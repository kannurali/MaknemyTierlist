// Browser check for the Roblox sign-in invite (?signin).
//
//   node tools/check-signin-invite-ui.mjs [http://127.0.0.1:8885]
//
// Until Roblox approves the OAuth app only 10 accounts can sign in, so the
// header offers the login button to invited visitors only (a link with
// ?signin) unless config.php opens it to everyone (roblox_login_public).
// tests/topbar_test.php pins the rule as text; this drives the real header
// with every answer api/session.php can give, so it needs no Roblox app and
// no database. Every visitor is a fresh browser context: localStorage lives
// across pages inside one and never leaks into the next.
//
// Needs Playwright (install line in tools/make-mediakit-pdf.mjs) and a PHP
// server over public_html with a config.php next to it; home.php does not
// touch the database, so any DSN will do:
//
//   php -S 127.0.0.1:8885 -t public_html
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8885';
const START = '/api/roblox_start.php?return=';
const KEY = 'nexus-signin-v1';

const CLOSED = { admin: false, user: null, roblox: true, roblox_public: false };
const OPEN = { ...CLOSED, roblox_public: true };
const OFF = { admin: false, user: null, roblox: false, roblox_public: false };
const SIGNED = {
    ...CLOSED,
    user: { id: '42', name: 'mak', display: 'Mak', avatar: '', profile: 'https://www.roblox.com/users/42/profile' },
};

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function visitor(name, state, body, { noStorage = false } = {}) {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, locale: 'ru-RU' });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    await context.route(url => !url.href.startsWith(BASE), r => r.abort());
    await context.route('**/api/session.php*', r => r.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify(state),
    }));
    if (noStorage) {
        await context.addInitScript(() => {
            Object.defineProperty(window, 'localStorage', {
                configurable: true,
                get() { throw new DOMException('denied', 'SecurityError'); },
            });
        });
    }
    try {
        await body(page);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

// Открыть страницу и дождаться, пока шапка разберёт ответ о входе.
async function open(page, path) {
    const answered = page.waitForResponse(r => r.url().includes('/api/session.php'));
    await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
    await answered;
    await page.waitForTimeout(300);
    const avatar = await page.$eval('.mk-top .mk-avatar', el => ({
        tag: el.tagName.toLowerCase(),
        href: el.getAttribute('href'),
        soon: el.hasAttribute('data-soon'),
        menu: el.getAttribute('aria-haspopup'),
    }));
    return { ...avatar, url: page.url().slice(BASE.length) };
}

const stub = a => a.tag === 'button' && a.soon && a.href === null;
const loginTo = (a, ret) => a.tag === 'a' && a.href === START + encodeURIComponent(ret);
const stored = page => page.evaluate(k => {
    try { return localStorage.getItem(k); } catch (_) { return 'throws'; }
}, KEY);

await visitor('вход закрыт, приглашения нет', CLOSED, async page => {
    const a = await open(page, '/home.php');
    check('кнопка остаётся заглушкой', stub(a), a);
});

await visitor('приглашение по ссылке', CLOSED, async page => {
    let a = await open(page, '/home.php?signin');
    check('кнопка ведёт на вход с возвратом на страницу', loginTo(a, '/home.php'), a);
    check('параметр убран из адреса', a.url === '/home.php', a.url);
    const kept = await stored(page);
    check('приглашение запомнено', kept === '1', kept);
    a = await open(page, '/home.php');
    check('повторный заход без параметра — снова ссылка', loginTo(a, '/home.php'), a);
});

await visitor('вход открыт всем', OPEN, async page => {
    const a = await open(page, '/home.php');
    check('ссылка без приглашения', loginTo(a, '/home.php'), a);
});

await visitor('вход выключен', OFF, async page => {
    const a = await open(page, '/home.php?signin');
    check('заглушка даже по приглашению', stub(a), a);
    check('параметр всё равно убран', a.url === '/home.php', a.url);
});

await visitor('вошедший без приглашения', SIGNED, async page => {
    const a = await open(page, '/home.php');
    check('аватар с меню', a.tag === 'button' && !a.soon && a.menu === 'menu', a);
});

await visitor('приглашение среди других параметров', CLOSED, async page => {
    const a = await open(page, '/home.php?tab=1&signin=1#top');
    check('остальной адрес сохранён', a.url === '/home.php?tab=1#top', a.url);
    check('возврат туда же', loginTo(a, '/home.php?tab=1#top'), a);
});

await visitor('похожий параметр', CLOSED, async page => {
    const a = await open(page, '/home.php?signinx=1');
    check('не приглашение', stub(a), a);
    check('адрес не тронут', a.url === '/home.php?signinx=1', a.url);
    const kept = await stored(page);
    check('ничего не запомнено', kept === null, kept);
});

await visitor('хранилище недоступно', CLOSED, async page => {
    let a = await open(page, '/home.php?signin');
    check('ссылка по приглашению есть', loginTo(a, '/home.php'), a);
    a = await open(page, '/home.php');
    check('без параметра приглашение не пережило перезаход', stub(a), a);
}, { noStorage: true });

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
