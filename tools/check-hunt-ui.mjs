import { chromium } from 'playwright';

const BASE = process.env.BASE || 'http://127.0.0.1:9310';
const OUT = process.env.OUT || 'C:/Users/nural/AppData/Local/Temp/claude/C--Users-nural-Desktop-Nexus-Tier-List/5bfda5e7-1bef-40b3-90ab-1e75ce2ed3bd/scratchpad/shots/';
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);

const START = 1793394000;
const DAY = 86400;
const LOGIN = [10, 15, 20, 25, 30, 35, 40];

function pubDoc(phase, extra = {}) {
    return {
        hunt: Object.assign({
            id: 1,
            title: { ru: 'Spooky Tokens', en: 'Spooky Tokens' },
            rules: {
                ru: 'Токены нельзя передать или продать. Один человек — один приз. Победители подтверждают выигрыш в течение 7 дней.',
                en: 'Tokens cannot be transferred or sold. One prize per person. Winners confirm within 7 days.'
            },
            phase, starts_at: START, ends_at: START + 7 * DAY, sales_end_at: START + 8 * DAY, draw_at: START + 8 * DAY + 7200,
            day: 3, days: 7, login: LOGIN, hours: [15, 20], cap: 420, price: 210, limit: 2,
            prizes: [{ name: 'Gas (permanent)', count: 3, icon: '' }],
            titles: { login: { ru: 'Ночной дозор', en: 'Night Watch' }, hours: { ru: 'Хранитель склепа', en: 'Crypt Keeper' } },
            hunters: 154
        }, extra),
        winners: []
    };
}

function days(states, hours, secs) {
    return LOGIN.map((reward, i) => ({
        n: i + 1,
        login: { reward, state: states[i] || 'future' },
        hours: [{ need: 3600, reward: 15, got: !!(hours[i] && hours[i][0]) }, { need: 7200, reward: 20, got: !!(hours[i] && hours[i][1]) }],
        secs: secs[i] || 0
    }));
}

function meDoc(o = {}) {
    return Object.assign({
        ok: true, hunt_id: 1,
        wallet: { balance: 95, earned: 95, tickets: 0 },
        day: 3,
        today: { secs: 2400, full: false },
        days: days(['got', 'missed', 'got'], [[1, 1]], [7200, 0, 2400]),
        tickets: [], wins: [], titles: [], tg: false,
        next_day_at: START + 3 * DAY
    }, o);
}

function sessionDoc(hunt) {
    return {
        admin: false, moderator: false,
        user: { id: '777', name: 'spooky', display: 'Spooky', avatar: '', profile: 'https://www.roblox.com/users/777/profile', logo: null },
        roblox: true, roblox_public: true, unread: 0, liked: null, giveaways: [],
        hunt: hunt === undefined ? {
            phase: 'open', day: 3, earned: 95, balance: 95, tickets: 0, cap: 420, price: 210, limit: 2,
            secs: 2400, full: false, gains: [{ kind: 'login', n: 3, delta: 20 }], titles_new: [], next: null
        } : hunt
    };
}

const GUEST = { admin: false, moderator: false, user: null, roblox: true, roblox_public: true, unread: 0, liked: null, giveaways: [], hunt: null };

const T_OPEN = START + 2 * DAY + 15 * 3600 + 20 * 60;
const T_SOON = START - 6 * 3600 - 17 * 60;
const T_DRAWN = START + 8 * DAY + 10 * 3600;

const SCEN = {
    guest: { now: T_OPEN, session: GUEST, pub: pubDoc('open'), me: null },
    open: { now: T_OPEN, session: sessionDoc(), pub: pubDoc('open'), me: meDoc() },
    ticket: {
        now: T_OPEN,
        session: sessionDoc({ phase: 'open', day: 3, earned: 230, balance: 230, tickets: 0, cap: 420, price: 210, limit: 2, secs: 4000, full: false, gains: [{ kind: 'hour', n: 3, k: 1, delta: 15 }], titles_new: [], next: 'ticket' }),
        pub: pubDoc('open'),
        me: meDoc({
            wallet: { balance: 230, earned: 230, tickets: 0 },
            today: { secs: 4000, full: false },
            days: days(['got', 'got', 'got'], [[1, 1], [1, 1], [1, 0]], [7200, 7200, 4000])
        })
    },
    two: {
        now: START + 6 * DAY + 20 * 3600,
        session: sessionDoc({ phase: 'open', day: 7, earned: 420, balance: 0, tickets: 2, cap: 420, price: 210, limit: 2, secs: 7200, full: true, gains: [], titles_new: [{ kind: 'login', title: { ru: 'Ночной дозор', en: 'Night Watch' } }], next: null }),
        pub: pubDoc('open', { day: 7 }),
        me: meDoc({
            wallet: { balance: 0, earned: 420, tickets: 2 },
            day: 7,
            today: { secs: 7200, full: true },
            days: days(['got', 'got', 'got', 'got', 'got', 'got', 'got'], [[1, 1], [1, 1], [1, 1], [1, 1], [1, 1], [1, 1], [1, 1]], [7200, 7200, 7200, 7200, 7200, 7200, 7200]),
            tickets: [{ id: 42, no: '#0042', seq: 1, created_at: START + 3 * DAY, void: false }, { id: 57, no: '#0057', seq: 2, created_at: START + 6 * DAY, void: false }],
            titles: [{ kind: 'login', title: { ru: 'Ночной дозор', en: 'Night Watch' } }, { kind: 'hours', title: { ru: 'Хранитель склепа', en: 'Crypt Keeper' } }],
            next_day_at: START + 7 * DAY
        })
    },
    drawn: {
        now: T_DRAWN,
        session: sessionDoc({ phase: 'drawn', day: 7, earned: 300, balance: 90, tickets: 1, cap: 420, price: 210, limit: 2, secs: 0, full: false, gains: [], titles_new: [], next: 'won' }),
        pub: Object.assign(pubDoc('drawn', { day: 7 }), {
            winners: [
                { prize: { idx: 0, name: 'Gas (permanent)', icon: '' }, user: { id: '777', username: 'spooky', display_name: 'Spooky' } },
                { prize: { idx: 0, name: 'Gas (permanent)', icon: '' }, user: { id: '12', username: 'nightowl_bf', display_name: 'NightOwl' } },
                { prize: { idx: 0, name: 'Gas (permanent)', icon: '' }, user: { id: '13', username: 'kitsune_main', display_name: 'KitsuneMain' } }
            ]
        }),
        me: meDoc({
            wallet: { balance: 90, earned: 300, tickets: 1 },
            day: 7,
            today: { secs: 0, full: false },
            days: days(['got', 'missed', 'got', 'got', 'missed', 'got', 'got'], [[1, 1], [0, 0], [1, 0], [1, 1], [0, 0], [1, 0], [1, 1]], []),
            tickets: [{ id: 42, no: '#0042', seq: 1, created_at: START + 3 * DAY, void: false }],
            wins: [{ id: 3, prize: { idx: 0, name: 'Gas (permanent)', icon: '' }, status: 'won', deadline: START + 15 * DAY, ticket_no: '#0042' }],
            titles: [{ kind: 'login', title: { ru: 'Ночной дозор', en: 'Night Watch' } }],
            next_day_at: 0
        })
    },
    soon: {
        now: T_SOON,
        session: sessionDoc({ phase: 'soon', day: 0, earned: 0, balance: 0, tickets: 0, cap: 420, price: 210, limit: 2, secs: 0, full: false, gains: [], titles_new: [], next: null }),
        pub: pubDoc('soon', { day: 0, hunters: 0 }),
        me: meDoc({ wallet: { balance: 0, earned: 0, tickets: 0 }, day: 0, today: { secs: 0, full: false }, days: days([], [], []), next_day_at: START })
    }
};

const browser = await chromium.launch({ channel: 'chrome' });

async function open(name, url, vp, opts = {}) {
    const sc = SCEN[name];
    const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: opts.dpr || 1, reducedMotion: opts.motion || 'no-preference', isMobile: false });
    const page = await ctx.newPage();
    page.setDefaultNavigationTimeout(45000);
    await page.clock.setFixedTime(new Date(sc.now * 1000));
    const json = (body, status = 200) => ({ status, contentType: 'application/json', body: JSON.stringify(body) });
    await page.route('**/js/promo-popup.js*', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    await page.route('**/api/session.php*', r => r.fulfill(json(sc.session)));
    await page.route('**/api/hunt.php*', r => r.fulfill(json(sc.pub)));
    await page.route('**/api/hunt_me.php*', r => r.fulfill(sc.me ? json(sc.me) : json({ ok: false, status: 'login' }, 401)));
    let secs = sc.me ? sc.me.today.secs : 0;
    const acts = [];
    await page.route('**/api/hunt_act.php*', r => {
        const body = JSON.parse(r.request().postData() || '{}');
        acts.push(body);
        const w = sc.me ? sc.me.wallet : { balance: 0, earned: 0, tickets: 0 };
        if (body.act === 'beat') {
            secs += 60;
            return r.fulfill(json({ ok: true, status: 'done', day: 3, secs, full: false, gains: [], titles_new: [], wallet: w }));
        }
        if (body.act === 'ticket') {
            const t = { id: 99, no: '#0099', seq: body.seq, created_at: sc.now, void: false };
            const wallet = { balance: w.balance - 210, earned: w.earned, tickets: w.tickets + 1 };
            return r.fulfill(json({ ok: true, status: 'done', ticket: t, tickets: (sc.me.tickets || []).concat([t]), wallet, price: 210, limit: 2 }));
        }
        if (body.act === 'confirm_win') {
            return r.fulfill(json({ ok: false, status: 'tg' }));
        }
        return r.fulfill(json({ ok: false, status: 'net' }));
    });
    await page.addInitScript(() => {
        document.addEventListener('DOMContentLoaded', () => {
            const s = document.createElement('style');
            s.textContent = '#promoPop{display:none!important} body.ptn-locked{overflow:auto!important}';
            document.head.appendChild(s);
        });
    });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(opts.wait || 2600);
    return { ctx, page, errors, acts };
}

const want = n => !ONLY.length || ONLY.includes(n);
const VPS = [{ width: 390, height: 844 }, { width: 1440, height: 900 }];

for (const name of ['guest', 'open', 'ticket', 'two', 'drawn', 'soon']) {
    if (!want(name)) continue;
    for (const vp of VPS) {
        const { ctx, page, errors, acts } = await open(name, '/halloween', vp);
        await page.screenshot({ path: `${OUT}halloween-${name}-${vp.width}.png`, fullPage: true });
        if (name === 'ticket' && vp.width === 1440) {
            await page.click('#hnBuy');
            await page.waitForTimeout(900);
            await page.locator('#hnTix').screenshot({ path: `${OUT}halloween-ticket-bought-1440.png` });
        }
        if (name === 'drawn' && vp.width === 390) {
            await page.click('.hn-wcard .hn-btn');
            await page.waitForTimeout(700);
            await page.locator('#hnWins').screenshot({ path: `${OUT}halloween-drawn-tg-390.png` });
        }
        console.log(name, vp.width, errors.length ? errors : 'ok', acts.map(a => a.act).join(','));
        await ctx.close();
    }
}

if (want('bar')) {
    for (const url of ['/', '/tierlist', '/calculator', '/chat', '/trading', '/news', '/giveaways']) {
        for (const vp of VPS) {
            const { ctx, page, errors, acts } = await open('open', url, vp, { wait: 3200 });
            const tag = url === '/' ? 'home' : url.slice(1);
            await page.screenshot({ path: `${OUT}bar-${tag}-${vp.width}.png` });
            const info = await page.evaluate(() => {
                const b = document.querySelector('.hn');
                if (!b) return null;
                const r = b.getBoundingClientRect();
                return { cls: b.className, l: Math.round(r.left), t: Math.round(r.top), w: Math.round(r.width), h: Math.round(r.height) };
            });
            console.log('bar', tag, vp.width, JSON.stringify(info), errors.length ? errors : 'ok', acts.map(a => a.act).join(','));
            if (vp.width === 1440 && url === '/') {
                await page.hover('.hn-bar');
                await page.waitForTimeout(500);
                await page.screenshot({ path: `${OUT}bar-home-hover-1440.png`, clip: { x: 1100, y: 250, width: 340, height: 400 } });
            }
            await ctx.close();
        }
    }
}

if (want('en')) {
    for (const vp of VPS) {
        const { ctx, page, errors } = await open('ticket', '/en/halloween', vp);
        await page.screenshot({ path: `${OUT}halloween-en-${vp.width}.png`, fullPage: true });
        const href = await page.evaluate(() => (document.querySelector('.hn-bar') || {}).getAttribute?.('href'));
        console.log('en', vp.width, href, errors.length ? errors : 'ok');
        await ctx.close();
    }
}

if (want('guestbar')) {
    for (const vp of VPS) {
        const { ctx, page, errors } = await open('guest', '/tierlist', vp);
        await page.screenshot({ path: `${OUT}bar-guest-tierlist-${vp.width}.png` });
        console.log('guestbar', vp.width, errors.length ? errors : 'ok');
        await ctx.close();
    }
}

await browser.close();
