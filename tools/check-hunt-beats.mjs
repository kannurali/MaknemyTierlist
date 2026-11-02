import { chromium } from 'playwright';

const BASE = 'http://127.0.0.1:9310';
const START = 1793394000;
const NOW = START + 2 * 86400 + 15 * 3600;
const SESSION = {
    admin: false, moderator: false,
    user: { id: '777', name: 'spooky', display: 'Spooky', avatar: '', profile: '', logo: null },
    roblox: true, roblox_public: true, unread: 0, liked: null, giveaways: [],
    hunt: { phase: 'open', day: 3, earned: 95, balance: 95, tickets: 0, cap: 420, price: 210, limit: 2, secs: 3540, full: false, gains: [], titles_new: [], next: null }
};

let failed = 0;
const check = (name, ok, detail) => { console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '  ' + JSON.stringify(detail)}`); if (!ok) failed++; };

const browser = await chromium.launch({ channel: 'chrome' });
const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 } });
const page = await ctx.newPage();
page.setDefaultNavigationTimeout(45000);
await page.clock.install({ time: new Date(NOW * 1000) });

let mode = 'ok';
let secs = 3540;
const beats = [];
const json = (b, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(b) });
await page.route('**/js/promo-popup.js*', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
await page.route('**/api/session.php*', r => r.fulfill(json(SESSION)));
await page.route('**/api/hunt_act.php*', r => {
    beats.push(mode);
    if (mode === 'slow') { return r.fulfill(json({ ok: false, status: 'rate' }, 429)); }
    secs += 60;
    const gains = secs === 3600 ? [{ kind: 'hour', n: 3, k: 1, delta: 15 }] : [];
    const full = mode === 'full';
    return r.fulfill(json({ ok: true, status: full ? 'full' : 'done', day: 3, secs: full ? 7200 : secs, full, gains, titles_new: [], wallet: { balance: 95 + (secs >= 3600 ? 15 : 0), earned: 95 + (secs >= 3600 ? 15 : 0), tickets: 0 } }));
});
await page.addInitScript(() => {
    window.__vis = 'visible';
    Object.defineProperty(document, 'visibilityState', { get: () => window.__vis, configurable: true });
    Object.defineProperty(document, 'hidden', { get: () => window.__vis !== 'visible', configurable: true });
    window.__events = [];
    document.addEventListener('hunt:update', e => window.__events.push(e.detail));
});

await page.goto(BASE + '/news', { waitUntil: 'domcontentloaded' });
await page.clock.runFor(3000);
await page.waitForTimeout(800);
check('first beat right after the session', beats.length === 1, beats);
const ev = await page.evaluate(() => window.__events.filter(e => e.gains && e.gains.length).slice(-1)[0]);
check('hunt:update carries the hour gain and new wallet', ev && ev.gains[0].delta === 15 && ev.earned === 110 && ev.secs === 3600, ev);
const toast = await page.evaluate(() => [...document.querySelectorAll('.hn-toast')].map(n => n.textContent));
check('toast for +15', toast.some(t => t.includes('+15')), toast);

await page.clock.runFor(60000);
await page.waitForTimeout(500);
check('second beat after 60 s', beats.length === 2, beats);
const num = await page.evaluate(() => document.querySelector('.hn-num b').textContent);
check('counter shows 110', num === '110', num);

await page.evaluate(() => { window.__vis = 'hidden'; document.dispatchEvent(new Event('visibilitychange')); });
await page.clock.runFor(180000);
await page.waitForTimeout(400);
check('no beats while hidden', beats.length === 2, beats);

await page.evaluate(() => { window.__vis = 'visible'; document.dispatchEvent(new Event('visibilitychange')); });
await page.waitForTimeout(500);
check('beat at once when visible again', beats.length === 3, beats);

mode = 'slow';
await page.clock.runFor(60000);
await page.waitForTimeout(400);
check('429 answered', beats.length === 4 && beats[3] === 'slow', beats);
await page.clock.runFor(240000);
await page.waitForTimeout(400);
check('quiet for 5 minutes after 429', beats.length === 4, beats);
mode = 'full';
await page.clock.runFor(70000);
await page.waitForTimeout(500);
check('resumes after the pause', beats.length >= 5, beats);
const n = beats.length;
await page.clock.runFor(300000);
await page.waitForTimeout(400);
check('stops after full', beats.length === n, beats);

await browser.close();
console.log(failed ? `${failed} FAILED` : 'ALL OK');
process.exit(failed ? 1 : 0);
