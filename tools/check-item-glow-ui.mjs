// Browser check for the item glow ("Выделение" in the item editor).
//
//   node tools/check-item-glow-ui.mjs [http://127.0.0.1:8880]
//
// tests/tags_test.php pins the markup, the dictionary and the CSS as text.
// This drives the real page: the editor field and its live preview, the
// published payload, the colour under hover, the phone size, and that the
// PNG export still produces a file.
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
const GOLD = '255, 226, 127';
const CYAN = '79, 214, 255';

const item = (id, name, value, extra = {}) => ({
    id, name, value, icon: 'assets/icon-sample.png', type: 'f', demand: 'green', trend: '',
    desc: '', descEn: '', terms: '', termsEn: '', tag: '', tagEn: '', flag: false, wip: false,
    ...extra,
});

// «old» — предмет из сохранения, сделанного до появления поля glow.
const FIXTURE = {
    title: 'MAKNEMY\nTIER LIST',
    date: '16.09.2026',
    autoSort: false,
    filters: { configurators: true, fruits: true, perms: false, passes: true },
    ad: { text: '', image: '', link: '' },
    tiers: [{
        id: 't1', label: 'MK', logo: 'assets/logo-mk.png',
        items: [
            item('lit', 'Glowing', '3000', { glow: true }),
            item('off', 'Plain', '2000', { glow: false }),
            item('old', 'Legacy', '1000'),
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
const glowing = (page, id) =>
    page.$eval(`.cell[data-id="${id}"]`, el => el.classList.contains('glow'));
const cellWidth = (page, id) =>
    page.$eval(`.cell[data-id="${id}"]`, el => el.getBoundingClientRect().width);

// Размытие золотого ядра в px. Делённое на ширину ячейки, оно сравнимо
// между компьютером и телефоном.
const coreBlur = filter => {
    const m = new RegExp(`drop-shadow\\(rgba?\\(${GOLD}[^)]*\\) 0px 0px ([\\d.]+)px\\)`).exec(filter);
    return m ? Number(m[1]) : NaN;
};

let deskRatio = NaN;

await session('компьютер', {}, async page => {
    check('класс glow только у предмета со свечением',
        (await glowing(page, 'lit')) && !(await glowing(page, 'off')) && !(await glowing(page, 'old')), null);
    const lit = await iconFilter(page, 'lit');
    check('свечение золотое', lit.includes(GOLD), lit);
    deskRatio = coreBlur(lit) / (await cellWidth(page, 'lit'));
    const off = await iconFilter(page, 'off');
    check('у остальных золота нет', !off.includes(GOLD), off);

    await page.hover('.cell[data-id="lit"]');
    await page.waitForTimeout(300);
    const hovered = await iconFilter(page, 'lit');
    check('при наведении свечение остаётся золотым', hovered.includes(GOLD) && !hovered.includes(CYAN), hovered);

    await page.hover('.cell[data-id="off"]');
    await page.waitForTimeout(300);
    const plain = await iconFilter(page, 'off');
    check('обычный предмет при наведении голубой, как раньше', plain.includes(CYAN), plain);
});

await session('телефон', { mobile: true }, async page => {
    const lit = await iconFilter(page, 'lit');
    check('телефон: свечение золотое', lit.includes(GOLD), lit);
    const ratio = coreBlur(lit) / (await cellWidth(page, 'lit'));
    check('телефон: свечение заметнее, чем на компьютере', ratio > deskRatio * 1.3, { ratio, deskRatio });
    const off = await iconFilter(page, 'off');
    check('телефон: у остальных фильтра нет', off === 'none', off);
});

await session('админка', { admin: true }, async (page, saved) => {
    await page.waitForSelector('#tbToggles:not([hidden])');
    await page.evaluate(() => {
        const t = document.querySelector('#editToggle');
        t.checked = true;
        t.dispatchEvent(new Event('change'));
    });
    await page.waitForSelector('#stage.editing');

    const active = v => page.$eval(`#mGlow button[data-v="${v}"]`, b => b.classList.contains('active'));
    const preview = () => page.$eval('.icon-preview', el => el.classList.contains('glow'));
    const openItem = async id => {
        await page.click(`.cell[data-id="${id}"]`);
        await page.waitForSelector('#modal:not([hidden])');
    };

    await openItem('lit');
    check('окно: у светящегося выбрано «Свечение»', (await active('on')) && !(await active('')), null);
    check('окно: превью светится', await preview(), null);
    const pf = await page.$eval('#mIconPreview', el => getComputedStyle(el).filter);
    check('окно: превью золотое', pf.includes(GOLD), pf);
    await page.click('#modalClose');

    await openItem('off');
    check('окно: у обычного выбрано «—»', (await active('')) && !(await active('on')), null);
    check('окно: превью обычного не светится', !(await preview()), null);
    await page.click('#mGlow button[data-v="on"]');
    check('окно: щелчок сразу зажигает превью', await preview(), null);
    await page.click('#modalClose');
    check('крестик без «Готово» свечение не ставит', !(await glowing(page, 'off')), null);

    await openItem('off');
    check('окно: превью сбрасывается при новом открытии', !(await preview()), null);
    await page.click('#mGlow button[data-v="on"]');
    await page.click('#mSave');
    check('«Готово» зажигает предмет', await glowing(page, 'off'), null);

    await openItem('lit');
    await page.click('#mGlow button[data-v=""]');
    check('окно: «—» гасит превью', !(await preview()), null);
    await page.click('#mSave');
    check('«Готово» гасит предмет', !(await glowing(page, 'lit')), null);

    await page.click('#btnSave');
    await page.waitForFunction(() => document.querySelector('#btnSave').classList.contains('clean'));
    const last = saved[saved.length - 1];
    const glowById = last ? Object.fromEntries(last.tiers[0].items.map(i => [i.id, i.glow])) : null;
    check('публикация уносит поле glow',
        !!glowById && glowById.off === true && glowById.lit === false, glowById);

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
