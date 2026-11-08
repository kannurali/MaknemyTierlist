// Browser check for the giveaway Mini App (/tg-app): every server state drawn
// in a dark and a light Telegram theme, at 390 and 320 px.
//
//   node tools/check-tg-app-ui.mjs [http://127.0.0.1:9241] [out-dir]
//
// Needs a PHP server over public_html (the page itself does not touch the
// database) and Playwright next to this script (tools/node_modules, not a
// project dependency). Chrome is used through channel: 'chrome' because the
// bundled Chromium is not downloaded on this machine.
//
// Nothing here talks to Telegram or to the real API:
// - telegram-web-app.js is answered with a stub that mimics window.Telegram.WebApp
//   (theme variables on <html>, initData, version checks, MainButton drawn as a
//   bar at the bottom so screenshots show what the user would press);
// - api/gw_tg_app.php is answered from the fixtures below, one per state;
// - the banner URL (https://maknemy.com/images/giveaways/6.webp) is answered
//   with a local picture: BANNER=<file> or the Hellfire popup creative.
//
// Every case also asserts the behaviour around the screenshot: which MainButton
// text is shown, that joined/last ask for write access once, that Subscribe
// opens the channel through openTelegramLink and that coming back re-checks.
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const BASE = process.argv[2] || 'http://127.0.0.1:9241';
const OUT = path.resolve(process.argv[3] || path.join(HERE, 'scratch_tg_app_shots'));
const BANNER = process.env.BANNER || path.join(HERE, '..', 'public_html', 'assets', 'promo', 'hellfire-prizes-popup.webp');
const PAGE = '/tg-app.php';
fs.mkdirSync(OUT, { recursive: true });

// Telegram's own default palettes (Android "Night" and iOS "Day").
const THEMES = {
    dark: {
        bg_color: '#212d3b', secondary_bg_color: '#151e27', section_bg_color: '#1d2733',
        text_color: '#ffffff', hint_color: '#7d8b99', link_color: '#5eabe1',
        button_color: '#50a8eb', button_text_color: '#ffffff', header_bg_color: '#242d39',
        accent_text_color: '#64b5ef', section_header_text_color: '#79c4fc',
        subtitle_text_color: '#7b8790', destructive_text_color: '#ee686f'
    },
    light: {
        bg_color: '#ffffff', secondary_bg_color: '#efeff4', section_bg_color: '#ffffff',
        text_color: '#000000', hint_color: '#8e8e93', link_color: '#007aff',
        button_color: '#007aff', button_text_color: '#ffffff', header_bg_color: '#f8f8f8',
        accent_text_color: '#007aff', section_header_text_color: '#6d6d72',
        subtitle_text_color: '#8e8e93', destructive_text_color: '#ff3b30'
    },
    black: {
        bg_color: '#000000', secondary_bg_color: '#000000', section_bg_color: '#1c1c1d',
        text_color: '#ffffff', hint_color: '#98989e', link_color: '#3e88f7',
        button_color: '#3e88f7', button_text_color: '#ffffff', header_bg_color: '#1a1a1a',
        accent_text_color: '#3e88f7', section_header_text_color: '#8d8e93',
        subtitle_text_color: '#98989e', destructive_text_color: '#eb5545'
    }
};

// Runs in the page instead of telegram-web-app.js. Reads its settings from
// window.__TG (set by addInitScript before any page script).
function stub() {
    const cfg = window.__TG || {};
    if (cfg.absent) { return; }
    const calls = window.__calls = [];
    const log = (name, arg) => calls.push(arg === undefined ? [name] : [name, arg]);
    // A plain browser gets no themeParams, so no --tg-theme-* variables either.
    const theme = cfg.initData === '' ? {} : (cfg.theme || {});
    const root = document.documentElement;
    for (const k of Object.keys(theme)) {
        root.style.setProperty('--tg-theme-' + k.replace(/_/g, '-'), theme[k]);
    }
    root.style.setProperty('--tg-viewport-height', innerHeight + 'px');
    root.style.setProperty('--tg-viewport-stable-height', innerHeight + 'px');
    const handlers = {};
    const cmp = (a, b) => {
        const x = String(a).split('.').map(Number), y = String(b).split('.').map(Number);
        for (let i = 0; i < Math.max(x.length, y.length); i++) {
            const d = (x[i] || 0) - (y[i] || 0);
            if (d) { return d; }
        }
        return 0;
    };
    const version = cfg.version || '8.0';
    let bar = null, btn = null, label = '', clicks = [], visible = false;
    const BAR_H = 66;
    function drawBar() {
        if (!bar) {
            bar = document.createElement('div');
            bar.setAttribute('data-stub', 'main-button');
            bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:99;padding:8px 12px;box-sizing:border-box;height:' + BAR_H + 'px;background:' + (theme.bottom_bar_bg_color || theme.secondary_bg_color || '#000') + ';box-shadow:0 -0.5px 0 rgba(128,128,128,.3)';
            btn = document.createElement('button');
            btn.style.cssText = 'width:100%;height:50px;border:0;border-radius:12px;font:600 16px -apple-system,BlinkMacSystemFont,Roboto,"Segoe UI",sans-serif;background:' + (theme.button_color || '#2481cc') + ';color:' + (theme.button_text_color || '#fff');
            btn.addEventListener('click', () => clicks.slice().forEach(f => f()));
            bar.appendChild(btn);
            const st = document.createElement('style');
            st.textContent = 'body.stub-mb{padding-bottom:' + BAR_H + 'px}';
            document.head.appendChild(st);
        }
        if (!bar.isConnected && document.body) { document.body.appendChild(bar); }
        btn.textContent = MainButton.isProgressVisible ? '···' : label;
        bar.style.display = visible ? 'block' : 'none';
        document.body && document.body.classList.toggle('stub-mb', visible);
        root.style.setProperty('--tg-viewport-stable-height', (innerHeight - (visible ? BAR_H : 0)) + 'px');
    }
    const MainButton = {
        text: '', isVisible: false, isActive: true, isProgressVisible: false,
        setText(t) { label = this.text = String(t); log('main.setText', label); drawBar(); return this; },
        show() { visible = this.isVisible = true; log('main.show'); drawBar(); return this; },
        hide() { visible = this.isVisible = false; log('main.hide'); drawBar(); return this; },
        onClick(f) { clicks.push(f); return this; },
        offClick(f) { clicks = clicks.filter(x => x !== f); return this; },
        showProgress() { this.isProgressVisible = true; log('main.progress', true); drawBar(); return this; },
        hideProgress() { this.isProgressVisible = false; drawBar(); return this; },
        enable() { this.isActive = true; return this; },
        disable() { this.isActive = false; return this; },
        setParams(p) { if (p.text) { this.setText(p.text); } if (p.is_visible === true) { this.show(); } if (p.is_visible === false) { this.hide(); } return this; }
    };
    const user = { id: 777000111, first_name: 'Nurali', language_code: cfg.lang || 'ru', allows_write_to_pm: !!cfg.canWrite };
    const WebApp = {
        initData: cfg.initData === undefined ? 'query_id=AAH&user=%7B%22id%22%3A777000111%7D&auth_date=1762600000&hash=abc' : cfg.initData,
        initDataUnsafe: { user, start_param: 'gw6' },
        version, platform: 'android', colorScheme: cfg.scheme || 'dark', themeParams: theme,
        isVersionAtLeast(v) { return cmp(version, v) >= 0; },
        ready() { log('ready'); }, expand() { log('expand'); }, close() { log('close'); },
        setHeaderColor(c) { log('setHeaderColor', c); }, setBackgroundColor(c) { log('setBackgroundColor', c); },
        setBottomBarColor(c) { log('setBottomBarColor', c); },
        onEvent(e, f) { (handlers[e] = handlers[e] || []).push(f); }, offEvent(e, f) { handlers[e] = (handlers[e] || []).filter(x => x !== f); },
        openLink(u) { log('openLink', u); }, openTelegramLink(u) { log('openTelegramLink', u); },
        requestWriteAccess(cb) { log('requestWriteAccess'); if (cfg.write !== 'never') { setTimeout(() => cb && cb(cfg.write === 'grant'), 250); } },
        HapticFeedback: { notificationOccurred(t) { log('haptic', t); }, impactOccurred() {}, selectionChanged() {} },
        __emit(e) { (handlers[e] || []).forEach(f => f()); }
    };
    if (!cfg.noMainButton) { WebApp.MainButton = MainButton; }
    window.Telegram = { WebApp };
}

const GW = {
    id: 6,
    title: 'Permanent Venom, Gas и 5 Hellfire',
    image: 'https://maknemy.com/images/giveaways/6.webp',
    prizes: [
        { name: 'Permanent Venom', count: 3 },
        { name: 'Permanent Gas', count: 2 },
        { name: 'Скин Hellfire', count: 5 }
    ],
    when: 'когда участников станет 15000',
    entries: 10512, max: 15000, phase: 'live',
    site: 'https://maknemy.com/giveaways#g6-ch'
};
const GW_EN = { ...GW, title: 'Permanent Venom, Gas and 5 Hellfire', when: 'when there are 15,000 participants', prizes: [GW.prizes[0], GW.prizes[1], { name: 'Hellfire skin', count: 5 }] };
const GW_LONG = {
    ...GW,
    title: 'Большой новогодний розыгрыш: Permanent Dragon, Kitsune, Leopard и ещё пять фруктов',
    prizes: [
        { name: 'Permanent Dragon', count: 1 },
        { name: 'Permanent Kitsune', count: 2 },
        { name: 'Permanent Leopard', count: 2 },
        { name: 'Permanent Dough', count: 3 },
        { name: 'Gamepass «2x Mastery» для одного счастливчика', count: 1 },
        { name: '10 000 Robux', count: 12 }
    ],
    when: '31 декабря в 23:00 по Москве', entries: 487, max: 0
};
const MISSING = [
    { title: 'Stock Blox Fruits', url: 'https://t.me/Stock_BloxFruits' },
    { title: 'MAKNEMY', url: 'https://t.me/theMaknemy' },
    { title: 'Башня Шута — чат трейдеров Blox Fruits и обсуждение стока', url: 'https://t.me/+VQVMxAbCdEf12345' }
];

const R = {
    joined: { ok: true, state: 'joined', lang: 'ru', text: 'Вы участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', gw: GW, missing: [] },
    last: { ok: true, state: 'last', lang: 'ru', text: 'Вы участвуете и заняли последнее место — итоги совсем скоро.', note: '', gw: { ...GW, entries: 15000 }, missing: [] },
    already: { ok: true, state: 'already', lang: 'ru', text: 'Вы уже участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', gw: { ...GW, entries: 10519 }, missing: [] },
    missing: { ok: false, state: 'missing', lang: 'ru', text: 'Чтобы участвовать, подпишитесь на каналы из условий.', note: '', gw: GW, missing: MISSING },
    missingOne: { ok: false, state: 'missing', lang: 'ru', text: 'Чтобы участвовать, подпишитесь на каналы из условий.', note: '', gw: GW, missing: [MISSING[1]] },
    closed: { ok: false, state: 'closed', lang: 'ru', text: 'Приём заявок закрыт.', note: '', gw: { ...GW, entries: 15000, phase: 'drawn' }, missing: [] },
    full: { ok: false, state: 'full', lang: 'ru', text: 'Все места заняты — приём закрыт.', note: '', gw: { ...GW, entries: 15000 }, missing: [] },
    none: { ok: false, state: 'none', lang: 'ru', text: 'Сейчас нет розыгрыша, в котором можно участвовать.', note: '', gw: null, missing: [] },
    auth: { ok: false, state: 'auth', lang: 'ru', text: 'Откройте розыгрыш заново из Telegram.', note: '', gw: null, missing: [] },
    rate: { ok: false, state: 'rate', lang: 'ru', text: 'Слишком много попыток. Попробуйте через несколько минут.', note: '', gw: null, missing: [] },
    banned: { ok: false, state: 'banned', lang: 'ru', text: 'Участие в розыгрышах для вас закрыто.', gw: GW, missing: [] },
    tg_used: { ok: false, state: 'tg_used', lang: 'ru', text: 'Этот Telegram уже участвует с другого аккаунта сайта.', note: '', gw: GW, missing: [] },
    site: { ok: false, state: 'site', lang: 'ru', text: 'Участвуйте на сайте maknemy.com, в разделе «Розыгрыши».', note: '', gw: GW, missing: [] },
    long: { ok: true, state: 'joined', lang: 'ru', text: 'Вы участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', gw: GW_LONG, missing: [] },
    noimg: { ok: true, state: 'already', lang: 'ru', text: 'Вы уже участвуете!', note: '', gw: { ...GW, image: null }, missing: [] },
    en: { ok: true, state: 'joined', lang: 'en', text: 'You\'re in!', note: 'Don\'t unsubscribe from the channels in the conditions until the results.', gw: GW_EN, missing: [] },
    enMissing: { ok: false, state: 'missing', lang: 'en', text: 'To enter, subscribe to the channels from the conditions.', note: '', gw: GW_EN, missing: MISSING.slice(0, 2) }
};
const STATUS = { auth: 403, rate: 429 };

const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || 'chrome' });
const banner = fs.readFileSync(BANNER);
const bannerType = BANNER.endsWith('.jpg') ? 'image/jpeg' : BANNER.endsWith('.png') ? 'image/png' : 'image/webp';
let failed = 0;
const shots = [];

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) { failed++; }
}

// answers: list of fixture keys (or 'net' / 'hang'), one per API call; the last repeats.
async function open({ answers = ['joined'], theme = 'dark', scheme, size = 390, lang = 'ru', tg = {}, motion = 'reduce' } = {}) {
    const viewport = size === 320 ? { width: 320, height: 640 } : { width: 390, height: 780 };
    const context = await browser.newContext({ viewport, deviceScaleFactor: 2, reducedMotion: motion, hasTouch: true, isMobile: true });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') { errors.push(m.text()); } });
    const cfg = { theme: THEMES[theme], scheme: scheme || (theme === 'light' ? 'light' : 'dark'), lang, write: 'deny', ...tg };
    await page.addInitScript(c => { window.__TG = c; }, cfg);
    await page.route('https://telegram.org/js/telegram-web-app.js*', r => r.fulfill({
        status: 200, contentType: 'application/javascript', body: `(${stub.toString()})();`
    }));
    await page.route('https://maknemy.com/images/**', r => r.fulfill({ status: 200, contentType: bannerType, body: banner }));
    const sent = [];
    let n = 0;
    await page.route('**/api/gw_tg_app.php', async r => {
        sent.push(r.request().postDataJSON());
        const key = answers[Math.min(n++, answers.length - 1)];
        if (key === 'net') { return r.abort('internetdisconnected'); }
        if (key === 'hang') { return; }
        await new Promise(res => setTimeout(res, 120));
        return r.fulfill({ status: STATUS[R[key].state] || 200, contentType: 'application/json', body: JSON.stringify(R[key]) });
    });
    await page.goto(BASE + PAGE, { waitUntil: 'domcontentloaded' });
    return { context, page, errors, sent };
}

// Full-page shots hide the stub's MainButton: a fixed bar would land in the
// middle of a stitched long page and only confuse the picture.
async function shot(page, name, full = false) {
    await page.evaluate(() => document.fonts && document.fonts.ready);
    const file = path.join(OUT, name + '.png');
    if (full) { await page.evaluate(() => document.querySelectorAll('[data-stub]').forEach(n => { n.style.visibility = 'hidden'; })); }
    await page.screenshot({ path: file, fullPage: full });
    if (full) { await page.evaluate(() => document.querySelectorAll('[data-stub]').forEach(n => { n.style.visibility = ''; })); }
    shots.push(file);
}

const calls = page => page.evaluate(() => window.__calls || []);
const mainText = page => page.evaluate(() => {
    const b = document.querySelector('[data-stub="main-button"]');
    return b && b.style.display !== 'none' ? b.textContent : '';
});
const settle = page => page.waitForFunction(() => document.getElementById('ta').getAttribute('aria-busy') === 'false', null, { timeout: 15000 });
const noOverflow = page => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth);

// --- загрузка ---------------------------------------------------------------
{
    const { context, page, errors } = await open({ answers: ['hang'], motion: 'no-preference' });
    await page.waitForTimeout(900);
    await shot(page, '01-loading-390-dark');
    check('загрузка: скелет на месте', await page.locator('.ta-skel-dot').count() === 1);
    check('загрузка: ready() и expand() вызваны', (await calls(page)).some(c => c[0] === 'ready'));
    check('загрузка: без ошибок', errors.length === 0, errors);
    await context.close();
}

// --- участвует (тёмная, светлая, 320) --------------------------------------
for (const [theme, size] of [['dark', 390], ['light', 390], ['dark', 320], ['black', 390]]) {
    const { context, page, errors, sent } = await open({ answers: ['joined'], theme, size, motion: theme === 'dark' && size === 390 ? 'no-preference' : 'reduce' });
    await settle(page);
    await page.waitForTimeout(1400);
    await shot(page, `02-joined-${size}-${theme}`);
    if (size === 320) { await shot(page, `02-joined-${size}-${theme}-full`, true); }
    const c = await calls(page);
    check(`joined ${theme} ${size}: initData ушла в POST`, sent[0] && typeof sent[0].init === 'string' && sent[0].init.length > 0, sent);
    check(`joined ${theme} ${size}: кнопка «Готово»`, await mainText(page) === 'Готово', await mainText(page));
    check(`joined ${theme} ${size}: haptic success`, c.some(x => x[0] === 'haptic' && x[1] === 'success'), c);
    check(`joined ${theme} ${size}: requestWriteAccess один раз`, c.filter(x => x[0] === 'requestWriteAccess').length === 1, c);
    check(`joined ${theme} ${size}: после отказа есть «Разрешить»`, await page.locator('.ta-ask-btn:visible').count() === 1);
    check(`joined ${theme} ${size}: нет горизонтальной прокрутки`, await noOverflow(page));
    check(`joined ${theme} ${size}: без ошибок`, errors.length === 0, errors);
    if (theme === 'dark' && size === 390) {
        await page.getByRole('button', { name: 'Открыть на сайте' }).click();
        check('joined: «Открыть на сайте» → openLink(gw.site)', (await calls(page)).some(x => x[0] === 'openLink' && x[1] === GW.site));
        await page.locator('[data-stub="main-button"] button').click();
        check('joined: «Готово» закрывает приложение', (await calls(page)).some(x => x[0] === 'close'));
    }
    await context.close();
}

// --- последнее место, уже участвует, без картинки, длинный --------------------
for (const [key, theme, size, full] of [['last', 'dark', 390], ['already', 'light', 390], ['noimg', 'dark', 390], ['long', 'dark', 320, true], ['long', 'light', 390, true]]) {
    const { context, page, errors } = await open({ answers: [key], theme, size, tg: key === 'already' ? { canWrite: true } : {} });
    await settle(page);
    await page.waitForTimeout(500);
    await shot(page, `03-${key}-${size}-${theme}`, !!full);
    const c = await calls(page);
    if (key === 'already') {
        check('already: без haptic success', !c.some(x => x[0] === 'haptic'), c);
        check('already: уже может писать — не спрашиваем', !c.some(x => x[0] === 'requestWriteAccess'), c);
    }
    if (key === 'long') {
        check('long: 6 призов', await page.locator('.ta-prize').count() === 6);
        check('long: без max нет полосы', await page.locator('.ta-bar').count() === 0);
    }
    check(`${key} ${theme} ${size}: нет горизонтальной прокрутки`, await noOverflow(page));
    check(`${key} ${theme} ${size}: без ошибок`, errors.length === 0, errors);
    await context.close();
}

// --- не подписан: 3 канала, возврат из канала, ручная проверка ----------------
for (const [theme, size] of [['dark', 390], ['light', 320]]) {
    const { context, page, errors, sent } = await open({ answers: ['missing', 'missing', 'joined'], theme, size, motion: 'no-preference' });
    await settle(page);
    await page.waitForTimeout(900);
    await shot(page, `04-missing-${size}-${theme}`);
    await shot(page, `04-missing-${size}-${theme}-full`, true);
    check(`missing ${theme}: 3 строки каналов`, await page.locator('.ta-chan').count() === 3);
    check(`missing ${theme}: кнопка «Проверить подписку»`, await mainText(page) === 'Проверить подписку', await mainText(page));
    check(`missing ${theme}: нет горизонтальной прокрутки`, await noOverflow(page));
    if (theme === 'dark') {
        await page.locator('[data-stub="main-button"] button').click();
        await page.waitForTimeout(150);
        check('missing: ручная проверка показывает прогресс на кнопке', (await calls(page)).some(x => x[0] === 'main.progress'));
        await settle(page);
        await page.waitForTimeout(300);
        check('missing: повторная проверка ушла на сервер', sent.length === 2, sent.length);
        check('missing: подписки всё нет — подсказка и haptic warning',
            await page.locator('.ta-note').count() === 1 && (await calls(page)).some(x => x[0] === 'haptic' && x[1] === 'warning'));
        await shot(page, '04-missing-still-390-dark');
        await page.waitForTimeout(1600);
        await page.locator('.ta-join').first().click();
        check('missing: «Подписаться» → openTelegramLink', (await calls(page)).some(x => x[0] === 'openTelegramLink' && x[1] === MISSING[0].url));
        await page.evaluate(() => window.Telegram.WebApp.__emit('activated'));
        await page.waitForTimeout(700);
        await settle(page);
        check('missing: после возврата из канала — сама перепроверка', sent.length === 3, sent.length);
        await page.evaluate(() => window.Telegram.WebApp.__emit('activated'));
        await page.waitForTimeout(800);
        check('missing: второй возврат без клика не шлёт запрос', sent.length === 3, sent.length);
        await page.waitForTimeout(1200);
        check('missing → joined: «Готово»', await mainText(page) === 'Готово', await mainText(page));
        await shot(page, '04-missing-then-joined-390-dark');
    }
    check(`missing ${theme}: без ошибок`, errors.length === 0, errors);
    await context.close();
}

// --- закрыт, мест нет ---------------------------------------------------------
for (const [key, theme] of [['closed', 'dark'], ['full', 'light']]) {
    const { context, page, errors } = await open({ answers: [key], theme });
    await settle(page);
    await page.waitForTimeout(400);
    await shot(page, `05-${key}-390-${theme}`);
    check(`${key}: кнопка «Итоги на сайте»`, await mainText(page) === 'Итоги на сайте', await mainText(page));
    await page.locator('[data-stub="main-button"] button').click();
    check(`${key}: «Итоги на сайте» → openLink(gw.site)`, (await calls(page)).some(x => x[0] === 'openLink' && x[1] === GW.site));
    check(`${key}: без ошибок`, errors.length === 0, errors);
    await context.close();
}

// --- сообщения без розыгрыша и ошибки -------------------------------------------
for (const [key, theme, size, main] of [
    ['none', 'dark', 390, ''], ['auth', 'dark', 390, 'Закрыть'], ['net', 'dark', 320, 'Попробовать ещё раз'],
    ['rate', 'light', 390, 'Попробовать ещё раз'], ['banned', 'dark', 390, ''], ['tg_used', 'light', 390, ''], ['site', 'dark', 390, 'Открыть на сайте']
]) {
    const { context, page, errors } = await open({ answers: [key], theme, size });
    await settle(page);
    await page.waitForTimeout(400);
    await shot(page, `06-${key}-${size}-${theme}`);
    check(`${key}: MainButton «${main}»`, await mainText(page) === main, await mainText(page));
    if (key === 'net') {
        check('net: свой текст про сервер', (await page.locator('.ta-title').textContent()) === 'Не получилось связаться с сервером.');
    }
    check(`${key}: нет горизонтальной прокрутки`, await noOverflow(page));
    check(`${key}: без ошибок`, errors.filter(e => !/ERR_INTERNET_DISCONNECTED|Failed to load resource/.test(e)).length === 0, errors);
    await context.close();
}

// --- English ------------------------------------------------------------------
for (const key of ['en', 'enMissing']) {
    const { context, page, errors } = await open({ answers: [key], lang: 'en', tg: { write: 'grant' } });
    await settle(page);
    await page.waitForTimeout(800);
    await shot(page, `07-${key}-390-dark`);
    check(`${key}: интерфейс по-английски`, (await mainText(page)) === (key === 'en' ? 'Done' : 'Check subscription'), await mainText(page));
    check(`${key}: без ошибок`, errors.length === 0, errors);
    await context.close();
}

// --- старый клиент без MainButton --------------------------------------------
{
    const { context, page, errors } = await open({ answers: ['missingOne', 'joined'], theme: 'light', tg: { version: '6.0', noMainButton: true } });
    await settle(page);
    await page.waitForTimeout(400);
    await shot(page, '08-oldclient-missing-390-light');
    const cta = page.locator('.ta-cta');
    check('old client: кнопка на странице вместо MainButton', await cta.count() === 1 && (await cta.textContent()) === 'Проверить подписку');
    await cta.click();
    await settle(page);
    await page.waitForTimeout(300);
    check('old client: после проверки — «Готово» на странице', (await page.locator('.ta-cta').textContent()) === 'Готово');
    check('old client: 6.0 не просит доступ на запись', !(await calls(page)).some(x => x[0] === 'requestWriteAccess'));
    await shot(page, '08-oldclient-joined-390-light');
    check('old client: без ошибок', errors.length === 0, errors);
    await context.close();
}

// --- обычный браузер, без Telegram --------------------------------------------
for (const [size, tg] of [[390, { initData: '' }], [320, { absent: true }]]) {
    const { context, page, errors, sent } = await open({ answers: ['joined'], size, tg, theme: 'dark' });
    await page.waitForTimeout(800);
    await shot(page, `09-browser-${size}`);
    check(`browser ${size}: API не вызывается`, sent.length === 0, sent.length);
    check(`browser ${size}: ссылка на все розыгрыши`, (await page.locator('a.ta-cta').getAttribute('href')) === 'https://maknemy.com/giveaways');
    check(`browser ${size}: без ошибок`, errors.length === 0, errors);
    await context.close();
}

await browser.close();
console.log('\n' + shots.join('\n'));
console.log(failed ? `\n${failed} FAILED` : '\nall ok');
process.exit(failed ? 1 : 0);
