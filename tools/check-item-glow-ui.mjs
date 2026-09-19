// Browser check for the item glow ("Выделение" in the item editor).
//
//   node tools/check-item-glow-ui.mjs [http://127.0.0.1:8880]
//
// tests/tags_test.php pins the markup, the dictionary and the CSS as text.
// This drives the real page: every glow colour on the card, the legacy
// `glow: true` left by the gold-only release (PR #71), the editor field and
// its live preview, the published payload, the colour under hover, the phone
// size, and that the PNG export still produces a file.
//
// The tier list comes from a fixture: api/tierlist.php, api/state.php,
// api/promo.php and api/save.php are answered here, so the check needs no
// database content and writes nothing. The admin role is granted the way
// admin.php grants it — window.NX_ADMIN_PAGE before app.js — plus a positive
// api/session.php, so no password is involved.
//
// Needs Playwright (install line in tools/make-mediakit-pdf.mjs) and a PHP
// server over public_html with a config.php next to it; any DSN will do,
// index.php survives an empty database:
//
//   php -S 127.0.0.1:8880 -t public_html
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';

const BASE = process.argv[2] || 'http://127.0.0.1:8880';
// Ядро свечения каждого цвета так, как его отдаёт getComputedStyle.
const CORE = { gold: '255, 226, 127', green: '93, 255, 106', red: '255, 74, 74' };
const COLORS = Object.keys(CORE);
const CYAN = '79, 214, 255';

const item = (id, value, extra = {}) => ({
    id, name: id, value, icon: 'assets/icon-sample.png', type: 'f', demand: 'green', trend: '',
    desc: '', descEn: '', terms: '', termsEn: '', tag: '', tagEn: '', flag: false, wip: false,
    ...extra,
});

// legacy — так сохранял PR #71, nope — его «выключено», weird — цвета нет в
// списке, old — сохранение, сделанное до появления поля.
const EXPECT = { gold: 'gold', green: 'green', red: 'red', legacy: 'gold', off: '', nope: '', weird: '', old: '' };
const FIXTURE = {
    title: 'MAKNEMY\nTIER LIST',
    date: '17.09.2026',
    autoSort: false,
    filters: { configurators: true, fruits: true, perms: false, passes: true },
    ad: { text: '', image: '', link: '' },
    tiers: [{
        id: 't1', label: 'MK', logo: 'assets/logo-mk.png',
        items: [
            item('gold', '8000', { glow: 'gold' }),
            item('green', '7000', { glow: 'green' }),
            item('red', '6000', { glow: 'red' }),
            item('legacy', '5000', { glow: true }),
            item('off', '4000', { glow: '' }),
            item('nope', '3000', { glow: false }),
            item('weird', '2000', { glow: 'blue' }),
            item('old', '1000'),
        ],
    }],
    _rev: 1,
};

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function session(name, { admin = false, mobile = false }, body) {
    const context = await browser.newContext({
        viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 1000 },
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'ru-RU',
        acceptDownloads: true,
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const saved = [];
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    const json = data => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    await page.route('**/api/state.php*', r => r.fulfill(json({ rev: 1, likes: 0 })));
    await page.route('**/api/tierlist.php*', r => r.fulfill(json({ tierlist: FIXTURE, likes: 0 })));
    await page.route('**/api/promo.php*', r => r.fulfill(json({ v: 1, rev: 0, campaigns: [] })));
    await page.route('**/api/session.php*', r => r.fulfill(json({ admin })));
    await page.route('**/api/save.php*', r => {
        saved.push(JSON.parse(r.request().postData() || 'null'));
        return r.fulfill(json({ ok: true, rev: 2 }));
    });
    if (admin) await page.addInitScript(() => { window.NX_ADMIN_PAGE = true; });
    try {
        await page.goto(BASE + '/index.php', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('.cell[data-id="old"]');
        await body(page, saved);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

const iconFilter = (page, id) =>
    page.$eval(`.cell[data-id="${id}"] .cell-icon img`, el => getComputedStyle(el).filter);
const cellWidth = (page, id) =>
    page.$eval(`.cell[data-id="${id}"]`, el => el.getBoundingClientRect().width);
// Только классы свечения, отсортированные: «glow glow-red» или пусто.
const glowClasses = (page, sel) =>
    page.$eval(sel, el => [...el.classList].filter(c => c === 'glow' || c.startsWith('glow-')).sort().join(' '));
const expectClasses = color => (color ? ['glow', 'glow-' + color].sort().join(' ') : '');
const coreOf = filter => COLORS.find(c => filter.includes(CORE[c])) || '';
// Размытие первого слоя (ядра) в px. Делённое на ширину ячейки, оно
// сравнимо между компьютером и телефоном.
const coreBlur = filter => {
    const m = /^drop-shadow\(rgba?\([^)]*\) 0px 0px ([\d.]+)px\)/.exec(filter);
    return m ? Number(m[1]) : NaN;
};

let deskRatio = NaN;

await session('компьютер', {}, async page => {
    for (const [id, color] of Object.entries(EXPECT)) {
        const cls = await glowClasses(page, `.cell[data-id="${id}"]`);
        const f = await iconFilter(page, id);
        check(`${id}: свечение «${color || 'нет'}»`, cls === expectClasses(color) && coreOf(f) === color, { cls, f });
    }
    deskRatio = coreBlur(await iconFilter(page, 'gold')) / (await cellWidth(page, 'gold'));

    for (const id of COLORS) {
        await page.hover(`.cell[data-id="${id}"]`);
        await page.waitForTimeout(300);
        const f = await iconFilter(page, id);
        check(`${id}: при наведении цвет не меняется`, coreOf(f) === id && !f.includes(CYAN), f);
    }
    await page.hover('.cell[data-id="off"]');
    await page.waitForTimeout(300);
    const plain = await iconFilter(page, 'off');
    check('обычный предмет при наведении голубой, как раньше', plain.includes(CYAN), plain);
});

await session('телефон', { mobile: true }, async page => {
    for (const [id, color] of Object.entries(EXPECT)) {
        const f = await iconFilter(page, id);
        check(`телефон: ${id} — свечение «${color || 'нет'}»`, color ? coreOf(f) === color : f === 'none', f);
    }
    const ratio = coreBlur(await iconFilter(page, 'gold')) / (await cellWidth(page, 'gold'));
    check('телефон: свечение заметнее, чем на компьютере', ratio > deskRatio * 1.3, { ratio, deskRatio });
});

await session('админка', { admin: true }, async (page, saved) => {
    await page.waitForSelector('#tbToggles:not([hidden])');
    await page.evaluate(() => {
        const t = document.querySelector('#editToggle');
        t.checked = true;
        t.dispatchEvent(new Event('change'));
    });
    await page.waitForSelector('#stage.editing');

    const selected = () => page.$eval('#mGlow button.active', b => b.dataset.v);
    const preview = () => glowClasses(page, '.icon-preview');
    const previewCore = async () => coreOf(await page.$eval('#mIconPreview', el => getComputedStyle(el).filter));
    const cardClasses = id => glowClasses(page, `.cell[data-id="${id}"]`);
    const openItem = async id => {
        await page.click(`.cell[data-id="${id}"]`);
        await page.waitForSelector('#modal:not([hidden])');
    };
    const pick = v => page.click(`#mGlow button[data-v="${v}"]`);

    await openItem('legacy');
    const buttons = await page.$$eval('#mGlow button', bs => bs.map(b => b.dataset.v));
    check('окно: кнопки «—» и три цвета', JSON.stringify(buttons) === JSON.stringify(['', ...COLORS]), buttons);
    for (const c of COLORS) {
        const color = await page.$eval(`#mGlow button[data-v="${c}"]`, b => getComputedStyle(b).color);
        check(`окно: кнопка ${c} подписана своим цветом`, color.includes(CORE[c]), color);
    }
    check('окно: старое true открывается «Золотым»', (await selected()) === 'gold', await selected());
    check('окно: превью золотое',
        (await preview()) === expectClasses('gold') && (await previewCore()) === 'gold', await preview());
    await pick('red');
    check('окно: «Красное» сразу перекрашивает превью',
        (await preview()) === expectClasses('red') && (await previewCore()) === 'red', await preview());
    await page.click('#modalClose');
    check('крестик без «Готово» ничего не меняет', (await cardClasses('legacy')) === expectClasses('gold'), await cardClasses('legacy'));

    await openItem('legacy');
    check('окно: превью сбрасывается при новом открытии', (await preview()) === expectClasses('gold'), await preview());
    await page.click('#mSave');
    check('«Готово» без правок оставляет старое true золотым', (await cardClasses('legacy')) === expectClasses('gold'), await cardClasses('legacy'));

    await openItem('weird');
    check('окно: неизвестный цвет открывается как «—»', (await selected()) === '' && (await preview()) === '', await selected());
    await page.click('#modalClose');

    await openItem('off');
    await pick('green');
    await page.click('#mSave');
    check('«Готово» зажигает зелёным', (await cardClasses('off')) === expectClasses('green'), await cardClasses('off'));

    await openItem('gold');
    await pick('');
    check('окно: «—» гасит превью', (await preview()) === '', await preview());
    await page.click('#mSave');
    check('«Готово» снимает свечение', (await cardClasses('gold')) === '', await cardClasses('gold'));

    await page.click('#btnSave');
    await page.waitForFunction(() => document.querySelector('#btnSave').classList.contains('clean'));
    const last = saved[saved.length - 1];
    const items = last ? last.tiers[0].items : [];
    const glowById = Object.fromEntries(items.map(i => [i.id, 'glow' in i ? i.glow : '(нет поля)']));
    const want = { gold: '', green: 'green', red: 'red', legacy: 'gold', off: 'green', nope: false, weird: 'blue', old: '(нет поля)' };
    check('публикация уносит строки цветов, нетронутые предметы как были',
        JSON.stringify(glowById) === JSON.stringify(want), glowById);

    const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 60000 }),
        page.click('#btnPng'),
    ]);
    const sig = readFileSync(await download.path()).subarray(0, 8).toString('hex');
    check('PNG по-прежнему скачивается', sig === '89504e470d0a1a0a', sig);
});

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
