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
// - api/gw_tg_app.php is answered from the fixtures below, one per state; its
//   avatar request ({photo: true}) separately, from open({ photo }) — null by
//   default, so the letter stays unless a case asks for a picture;
// - pictures on https://maknemy.com/images/ (banner, prize art, channel avatars)
//   and the user's https://t.me/i/userpic/ are answered locally: the banner from
//   BANNER=<file> (default: the Hellfire popup creative), anything else from
//   IMG_DIR=<dir> by file name (userpic.jpg for the user) when it is there, else
//   a drawn placeholder; a name with "broken" in it answers 404 so the fallbacks
//   (letter avatar, gift outline) get drawn too. Real prize art is not in the
//   repo: download it from https://maknemy.com/api/giveaways.php into IMG_DIR.
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
const IMG_DIR = process.env.IMG_DIR || '';
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
    let bar = null, btn = null, label = '', clicks = [], visible = false, bottomBar = '';
    const BAR_H = 66;
    function drawBar() {
        if (!bar) {
            bar = document.createElement('div');
            bar.setAttribute('data-stub', 'main-button');
            bar.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:99;padding:8px 12px;box-sizing:border-box;height:' + BAR_H + 'px;background:' + (theme.bottom_bar_bg_color || theme.secondary_bg_color || '#000') + ';box-shadow:0 -0.5px 0 rgba(128,128,128,.3)';
            btn = document.createElement('button');
            btn.style.cssText = 'width:100%;height:50px;border:0;border-radius:12px;font:600 16px -apple-system,BlinkMacSystemFont,Roboto,"Segoe UI",sans-serif';
            btn.addEventListener('click', () => clicks.slice().forEach(f => f()));
            bar.appendChild(btn);
            const st = document.createElement('style');
            st.textContent = 'body.stub-mb{padding-bottom:' + BAR_H + 'px}';
            document.head.appendChild(st);
        }
        if (!bar.isConnected && document.body) { document.body.appendChild(bar); }
        btn.textContent = MainButton.isProgressVisible ? '···' : label;
        btn.style.background = MainButton.color || theme.button_color || '#2481cc';
        btn.style.color = MainButton.textColor || theme.button_text_color || '#fff';
        bar.style.background = bottomBar || theme.bottom_bar_bg_color || theme.secondary_bg_color || '#000';
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
        setParams(p) {
            log('main.params', p);
            if (p.color) { this.color = p.color; }
            if (p.text_color) { this.textColor = p.text_color; }
            if (p.text) { this.setText(p.text); }
            if (p.is_visible === true) { this.show(); }
            if (p.is_visible === false) { this.hide(); }
            drawBar();
            return this;
        }
    };
    const user = { id: 777000111, first_name: cfg.firstName === undefined ? 'Nurali' : cfg.firstName, language_code: cfg.lang || 'ru', allows_write_to_pm: !!cfg.canWrite };
    if (cfg.photo !== null) { user.photo_url = cfg.photo || 'https://t.me/i/userpic/320/nurali.jpg'; }
    const WebApp = {
        initData: cfg.initData === undefined ? 'query_id=AAH&user=%7B%22id%22%3A777000111%7D&auth_date=1762600000&hash=abc' : cfg.initData,
        initDataUnsafe: { user, start_param: 'gw6' },
        version, platform: 'android', colorScheme: cfg.scheme || 'dark', themeParams: theme,
        isVersionAtLeast(v) { return cmp(version, v) >= 0; },
        ready() { log('ready'); }, expand() { log('expand'); }, close() { log('close'); },
        setHeaderColor(c) { log('setHeaderColor', c); }, setBackgroundColor(c) { log('setBackgroundColor', c); },
        setBottomBarColor(c) { log('setBottomBarColor', c); if (/^#/.test(c)) { bottomBar = c; if (bar) { drawBar(); } } },
        onEvent(e, f) { (handlers[e] = handlers[e] || []).push(f); }, offEvent(e, f) { handlers[e] = (handlers[e] || []).filter(x => x !== f); },
        openLink(u) { log('openLink', u); }, openTelegramLink(u) { log('openTelegramLink', u); },
        requestWriteAccess(cb) { log('requestWriteAccess'); if (cfg.write !== 'never') { setTimeout(() => cb && cb(cfg.write === 'grant'), 250); } },
        HapticFeedback: { notificationOccurred(t) { log('haptic', t); }, impactOccurred() {}, selectionChanged() {} },
        __emit(e) { (handlers[e] || []).forEach(f => f()); }
    };
    if (!cfg.noMainButton) { WebApp.MainButton = MainButton; }
    window.Telegram = { WebApp };
}

// Real file names from https://maknemy.com/api/giveaways.php (giveaways 6 and 2).
const IMG = 'https://maknemy.com/images/';
const BANNER_URL = IMG + 'a2d23d8abfef6bae07930dea75ff2bae159be68d.jpg';
const ART = {
    venom: IMG + '5708035a9826850deeb987b0a94abbf2648b4fe3.webp',
    gas: IMG + '1689cda446ce4d6ea79a3116b663c8c8d4bd54f8.webp',
    hellfire: IMG + 'aca91791385fbe7baeeae61a5699e27778971b9a.png',
    werewolf: IMG + 'd52459cdabeb464ec24e44044a069c8df582d48a.webp',
    tiger: IMG + '2aa53bc265ba4fe5dbd61ccbbd2fa4703afb5a37.webp',
    ghost: IMG + '6837e1f304957229a18232cdf69fc4cf6293be11.png',
    kitsune: IMG + 'd59f8b98a0db7f7bc3b1704d94d26b899349e3ae.webp'
};
const GW = {
    id: 6,
    title: '🔥 РОЗЫГРЫШ PERMANENT VENOM, GAS И 5 HELLFIRE GAS MUTATIONS!',
    image: BANNER_URL,
    prizes: [
        { name: 'Permanent Venom', count: 3, icon: ART.venom },
        { name: 'Permanent Gas', count: 2, icon: ART.gas },
        { name: 'Hellfire (Gas)', count: 5, icon: ART.hellfire }
    ],
    when: 'когда участников станет 15000',
    entries: 10513, max: 15000, phase: 'live',
    site: 'https://maknemy.com/giveaways#g6-ch'
};
const GW_EN = { ...GW, title: '🔥 GIVEAWAY: PERMANENT VENOM, GAS AND 5 HELLFIRE GAS MUTATIONS!', when: 'when there are 15,000 participants' };
const GW_8 = {
    ...GW,
    id: 2,
    title: '🎃 МЕГА-РОЗЫГРЫШ К HALLOWEEN UPDATE!',
    image: IMG + 'de33774d64638ebd97c1460293ea2cd78fa85713.jpg',
    prizes: [
        { name: 'Permanent Venom', count: 3, icon: ART.venom },
        { name: 'Permanent Gas', count: 2, icon: ART.gas },
        { name: 'Hellfire (Gas)', count: 5, icon: ART.hellfire },
        { name: 'Werewolf (Tiger)', count: 1, icon: ART.werewolf },
        { name: 'Tiger Fruit', count: 2, icon: ART.tiger },
        { name: 'Scarlet Ghost', count: 7, icon: ART.ghost },
        { name: 'Kitsune Fruit', count: 1, icon: ART.kitsune },
        { name: 'Gamepass «2x Mastery» для одного счастливчика', count: 1, icon: null }
    ],
    when: 'когда участников станет 3001', entries: 2086, max: 3001, site: 'https://maknemy.com/giveaways#g2-ch'
};
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
// Avatars: a real one, none at all (null) and one that fails to load.
const MISSING = [
    { title: 'Stock Blox Fruits', url: 'https://t.me/Stock_BloxFruits', photo: IMG + 'c0a1f00000000000000000000000000000000001.jpg' },
    { title: 'MAKNEMY', url: 'https://t.me/theMaknemy', photo: null },
    { title: 'Башня Шута — чат трейдеров Blox Fruits и обсуждение стока', url: 'https://t.me/+VQVMxAbCdEf12345', photo: IMG + 'broken-c0a1f0000000000000000000000000000002.jpg' }
];

const R = {
    joined: { ok: true, state: 'joined', lang: 'ru', text: 'Вы участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', ticket: 10513, gw: GW, missing: [] },
    last: { ok: true, state: 'last', lang: 'ru', text: 'Вы участвуете и заняли последнее место — итоги совсем скоро.', note: '', ticket: 15000, gw: { ...GW, entries: 15000 }, missing: [] },
    already: { ok: true, state: 'already', lang: 'ru', text: 'Вы уже участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', ticket: 9876, gw: { ...GW, entries: 10519 }, missing: [] },
    prizes8: { ok: true, state: 'joined', lang: 'ru', text: 'Вы участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', ticket: 2086, gw: GW_8, missing: [] },
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
    long: { ok: true, state: 'joined', lang: 'ru', text: 'Вы участвуете!', note: 'Не отписывайтесь от каналов из условий до итогов.', ticket: 487, gw: GW_LONG, missing: [] },
    noimg: { ok: true, state: 'already', lang: 'ru', text: 'Вы уже участвуете!', note: '', ticket: 312, gw: { ...GW, image: null }, missing: [] },
    en: { ok: true, state: 'joined', lang: 'en', text: 'You\'re in!', note: 'Don\'t unsubscribe from the channels in the conditions until the results.', ticket: 10513, gw: GW_EN, missing: [] },
    enMissing: { ok: false, state: 'missing', lang: 'en', text: 'To enter, subscribe to the channels from the conditions.', note: '', gw: GW_EN, missing: MISSING.slice(0, 2) }
};
const STATUS = { auth: 403, rate: 429 };

const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || 'chrome' });
const typeOf = f => /\.jpe?g$/i.test(f) ? 'image/jpeg' : /\.png$/i.test(f) ? 'image/png' : /\.svg$/i.test(f) ? 'image/svg+xml' : 'image/webp';
const banner = fs.readFileSync(BANNER);

// Placeholder for a picture that is not in IMG_DIR: a glowing blob, its hue taken
// from the file name so neighbouring tiles differ.
function placeholder(name) {
    let h = 0;
    for (const ch of name) { h = (h * 31 + ch.charCodeAt(0)) >>> 0; }
    const hue = h % 360;
    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 160 160"><defs><radialGradient id="g" cx=".4" cy=".35"><stop offset="0" stop-color="hsl(${hue} 95% 78%)"/><stop offset="1" stop-color="hsl(${(hue + 40) % 360} 90% 42%)"/></radialGradient></defs><circle cx="80" cy="86" r="54" fill="url(#g)"/><path d="M80 32c6-10 18-14 26-10-8 2-14 8-18 16z" fill="hsl(120 60% 40%)"/></svg>`;
}

async function image(r) {
    const url = r.request().url();
    const name = decodeURIComponent(url.split('?')[0].split('/').pop() || '');
    if (/broken/.test(name)) { return r.fulfill({ status: 404, contentType: 'text/plain', body: 'not found' }); }
    if (url.startsWith(BANNER_URL)) { return r.fulfill({ status: 200, contentType: typeOf(BANNER), body: banner }); }
    const local = IMG_DIR && path.join(IMG_DIR, /\/userpic\//.test(url) ? 'userpic.jpg' : name);
    if (local && fs.existsSync(local)) { return r.fulfill({ status: 200, contentType: typeOf(local), body: fs.readFileSync(local) }); }
    return r.fulfill({ status: 200, contentType: 'image/svg+xml', body: placeholder(name) });
}
let failed = 0;
const shots = [];

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) { failed++; }
}

// answers: list of fixture keys (or 'net' / 'hang'), one per API call; the last repeats.
async function open({ answers = ['joined'], theme = 'dark', scheme, size = 390, lang = 'ru', tg = {}, motion = 'reduce', photo = null } = {}) {
    const viewport = size === 320 ? { width: 320, height: 640 } : { width: 390, height: 780 };
    const context = await browser.newContext({ viewport, deviceScaleFactor: 2, reducedMotion: motion, hasTouch: true, isMobile: true });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) { errors.push(m.text()); } });
    const cfg = { theme: THEMES[theme], scheme: scheme || (theme === 'light' ? 'light' : 'dark'), lang, write: 'deny', ...tg };
    await page.addInitScript(c => { window.__TG = c; }, cfg);
    await page.route('https://telegram.org/js/telegram-web-app.js*', r => r.fulfill({
        status: 200, contentType: 'application/javascript', body: `(${stub.toString()})();`
    }));
    await page.route('https://maknemy.com/images/**', image);
    await page.route('https://t.me/i/userpic/**', image);
    const sent = [];
    const photoAsks = [];
    let n = 0;
    await page.route('**/api/gw_tg_app.php', async r => {
        const body = r.request().postDataJSON();
        if (body && body.photo === true) {
            photoAsks.push(body);
            await new Promise(res => setTimeout(res, 200));
            return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ photo }) });
        }
        sent.push(body);
        const key = answers[Math.min(n++, answers.length - 1)];
        if (key === 'net') { return r.abort('internetdisconnected'); }
        if (key === 'hang') { return; }
        await new Promise(res => setTimeout(res, 120));
        return r.fulfill({ status: STATUS[R[key].state] || 200, contentType: 'application/json', body: JSON.stringify(R[key]) });
    });
    await page.goto(BASE + PAGE, { waitUntil: 'domcontentloaded' });
    return { context, page, errors, sent, photoAsks };
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
const loaded = (page, sel) => page.evaluate(s => [...document.querySelectorAll(s)].filter(i => i.complete && i.naturalWidth > 0).length, sel);
const avatars = page => page.evaluate(() => [...document.querySelectorAll('.ta-ava')].map(a => {
    const i = a.querySelector('img');
    return i ? (i.complete && i.naturalWidth > 0 ? 'photo' : 'pending') : 'letter';
}));
const NNBSP = String.fromCharCode(0x202f);

// --- загрузка ---------------------------------------------------------------
{
    const { context, page, errors } = await open({ answers: ['hang'], motion: 'no-preference' });
    await page.waitForTimeout(900);
    await shot(page, '01-loading-390-dark');
    check('загрузка: скелет на месте', await page.locator('.ta-skel-stage').count() === 1);
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
    check(`joined ${theme} ${size}: номер билета 10 513`, (await page.locator('.ta-pass-num').textContent()) === '10' + NNBSP + '513');
    check(`joined ${theme} ${size}: 3 приза с картинками и ×N`,
        JSON.stringify(await page.locator('.ta-qty').allTextContents()) === '["×3","×2","×5"]' && await loaded(page, '.ta-slot img') === 3,
        await page.locator('.ta-qty').allTextContents());
    check(`joined ${theme} ${size}: аватар пользователя на корешке`, await loaded(page, '.ta-me img') === 1);
    check(`joined ${theme} ${size}: MainButton в цвет сайта`, c.some(x => x[0] === 'main.params' && x[1].color === '#8b00ff'), c.filter(x => x[0] === 'main.params'));
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
    if (key === 'noimg') {
        check('noimg: без баннера сцена из картинок призов', await page.locator('.ta-stage.is-art .ta-stage-pic').count() === 3);
    }
    if (key === 'long') {
        check('long: 6 призов', await page.locator('.ta-item').count() === 6);
        check('long: без картинок призов — контур подарка', await page.locator('.ta-slot:not(.has-art) svg').count() === 6);
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
    check(`missing ${theme}: аватарки — фото, буква (photo: null), буква после 404`,
        JSON.stringify(await avatars(page)) === '["photo","letter","letter"]', await avatars(page));
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

// --- набор для просмотра дизайна: главные состояния на 390 и 320 ----------------
// Each shot twice: the screen as opened and the whole page. The "moment" shot is
// the join animation half a second in: ticket landing, confetti in the air.
for (const size of [390, 320]) {
    for (const [key, theme, tg] of [
        ['joined', 'dark'], ['joined', 'light'], ['already', 'dark', { canWrite: true }], ['missing', 'dark'],
        ['missing', 'light'], ['closed', 'dark'], ['prizes8', 'dark', { photo: null }]
    ]) {
        const { context, page, errors } = await open({ answers: [key], theme, size, tg: tg || {} });
        await settle(page);
        await page.waitForTimeout(700);
        await shot(page, `10-${key}-${size}-${theme}`);
        await shot(page, `10-${key}-${size}-${theme}-full`, true);
        if (key === 'prizes8') {
            check(`prizes8 ${size}: 8 призов, 7 картинок и один подарок-заглушка`,
                await page.locator('.ta-item').count() === 8 && await loaded(page, '.ta-slot img') === 7 && await page.locator('.ta-slot:not(.has-art)').count() === 1);
            check(`prizes8 ${size}: без photo_url — буква на корешке`, (await page.locator('.ta-me-letter').textContent()) === 'N' && await page.locator('.ta-me img').count() === 0);
        }
        check(`design ${key} ${theme} ${size}: нет горизонтальной прокрутки`, await noOverflow(page));
        check(`design ${key} ${theme} ${size}: без ошибок`, errors.length === 0, errors);
        await context.close();
    }
    {
        const { context, page, errors } = await open({ answers: ['joined'], theme: 'dark', size, motion: 'no-preference' });
        await settle(page);
        await page.waitForTimeout(520);
        await shot(page, `10-joined-${size}-dark-moment`);
        check(`moment ${size}: конфетти летит`, await page.locator('canvas.ta-burst').count() === 1);
        await page.waitForTimeout(1700);
        check(`moment ${size}: холст конфетти убран`, await page.locator('canvas.ta-burst').count() === 0);
        await shot(page, `10-joined-${size}-dark-after`);
        check(`moment ${size}: без ошибок`, errors.length === 0, errors);
        await context.close();
    }
}

// --- аватарка с сервера: photo_url Telegram даёт только мини-приложениям из
// меню вложений, остальным фото профиля приносит сам сайт ----------------------
{
    const pic = 'https://maknemy.com/images/' + 'a'.repeat(40) + '.jpg';
    for (const theme of ['dark', 'light']) {
        const { context, page, errors, photoAsks } = await open({ answers: ['joined'], theme, tg: { photo: null }, photo: pic });
        await settle(page);
        await page.waitForFunction(() => { const i = document.querySelector('.ta-me img'); return i && i.complete && i.naturalWidth > 0; }, null, { timeout: 10000 });
        await page.waitForTimeout(400);
        await shot(page, `20-avatar-server-${theme}`);
        check(`avatar ${theme}: без photo_url сайт отдал фото — оно на корешке`,
            await page.locator('.ta-me img.is-late').count() === 1 && (await page.locator('.ta-me img').getAttribute('src')) === pic);
        check(`avatar ${theme}: спросили один раз`, photoAsks.length === 1);
        check(`avatar ${theme}: без ошибок`, errors.length === 0, errors);
        await context.close();
    }
    {
        const { context, page, errors, photoAsks } = await open({ answers: ['joined'], tg: { photo: null }, photo: null });
        await settle(page);
        await page.waitForTimeout(700);
        check('avatar: у сервера фото нет — буква', await page.locator('.ta-me img').count() === 0 && (await page.locator('.ta-me-letter').textContent()) === 'N');
        check('avatar: спросили один раз', photoAsks.length === 1);
        check('avatar: без ошибок', errors.length === 0, errors);
        await context.close();
    }
    {
        const { context, page, errors, photoAsks } = await open({ answers: ['joined'], photo: pic });
        await settle(page);
        await page.waitForTimeout(700);
        check('avatar: есть photo_url — сайт не спрашиваем', photoAsks.length === 0 && /\/userpic\//.test(await page.locator('.ta-me img').getAttribute('src')));
        check('avatar photo_url: без ошибок', errors.length === 0, errors);
        await context.close();
    }
    {
        const { context, page, errors, photoAsks } = await open({ answers: ['joined'], tg: { photo: null }, photo: 'https://evil.example/x.jpg' });
        await settle(page);
        await page.waitForTimeout(700);
        check('avatar: чужой адрес не ставится', await page.locator('.ta-me img').count() === 0 && photoAsks.length === 1);
        check('avatar evil: без ошибок', errors.length === 0, errors);
        await context.close();
    }
}

await browser.close();
console.log('\n' + shots.join('\n'));
console.log(failed ? `\n${failed} FAILED` : '\nall ok');
process.exit(failed ? 1 : 0);
