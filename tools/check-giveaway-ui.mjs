// Browser check for the house campaigns: does each placement draw the right one?
//
//   node tools/check-giveaway-ui.mjs [http://127.0.0.1:8850]
//
// The unit suite asserts the SELECTION (tests/promo_test.mjs: houseFor picks
// Playerok on the tier list, the giveaway everywhere else). It cannot see
// whether each page then draws it: the strip lives in app.js, the rails in
// three different page modules, the dock and the popup in shared ones. This
// walks all four placements on all three pages.
//
// Playerok is booked for pages: ["tierlist"], so the tier list must show it and
// NOT the giveaway — that split is the thing worth checking, because it is the
// paid placement. News and the calculator are where the giveaway lives.
//
// /api/promo.php is answered with an empty document on purpose: locally the
// database holds demo campaigns which would (correctly) cover every slot, and
// then the check would prove nothing. The tier list banner (state.ad) is
// rewritten to the link of the campaign that owns the page: app.js swaps the
// owner's 256 px picture for the full-size creative only when the two links
// match, and that swap is exactly what the strip check is about.
//
// Needs Playwright (not a project dependency — see tools/make-mediakit-pdf.mjs
// for the install line) and a PHP server over public_html.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8850';
const GIVEAWAY_POST = 'https://t.me/theMaknemy/5302';
const PLAYEROK_LINK = 'https://plrk.co/p/Maknemy0509';
const has = (v, part) => typeof v === 'string' && v.includes(part);

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function open(viewport, url, wait = 2500, adLink = GIVEAWAY_POST) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await page.route('**/api/promo.php*', r => r.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ v: 1, rev: 0, campaigns: [] })
    }));
    await page.route('**/api/tierlist.php*', async r => {
        const res = await r.fetch();
        const doc = await res.json();
        if (doc && doc.tierlist && doc.tierlist.ad) { doc.tierlist.ad.link = adLink; }
        await r.fulfill({ response: res, body: JSON.stringify(doc), contentType: 'application/json' });
    });
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    await page.goto(BASE + url, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(wait);
    return { context, page, errors };
}

const src = sel => [...document.querySelectorAll(sel)].map(n => (n.tagName === 'IMG' ? n : n.querySelector('img')))
    .filter(Boolean).map(i => i.getAttribute('src'));

// --- лента тирлиста + борта на трёх страницах (широкий экран) --------------
for (const [name, url, railSel, rail, adLink] of [
    ['тирлист', '/index.php', '.ptn-rail', 'playerok-rail.webp', PLAYEROK_LINK],
    ['лента', '/news.php', '.nw-rail', 'giveaway-rail.webp', GIVEAWAY_POST],
    ['калькулятор', '/calculator.php', '.tc-rail', 'giveaway-rail.webp', GIVEAWAY_POST]
]) {
    const { context, page, errors } = await open({ width: 1600, height: 950 }, url, 2500, adLink);
    const rails = await page.evaluate(src, railSel);
    check(`${name}: оба борта заняты макетом ${rail}`,
        rails.length >= 2 && rails.every(s => has(s, rail)), rails);
    if (url === '/index.php') {
        const strip = await page.evaluate(src, '.ptn-card, .ptn-slide');
        check('тирлист: лента показывает выкупленный макет, а не картинку в 256 px',
            strip.some(s => has(s, 'playerok-strip.webp')), strip);
        check('тирлист: розыгрыш не занимает выкупленное место',
            !strip.some(s => has(s, 'giveaway-')) && !rails.some(s => has(s, 'giveaway-')),
            { strip, rails });
    }
    check(`${name}: без ошибок в консоли`, errors.length === 0, errors);
    await context.close();
}

// --- нижняя полоса (телефон) ----------------------------------------------
for (const [name, url, dock] of [
    ['тирлист', '/index.php', 'playerok-dock.webp'],
    ['лента', '/news.php', 'giveaway-dock.webp']
]) {
    const { context, page, errors } = await open({ width: 390, height: 844 }, url);
    const strip = await page.evaluate(src, '.ptn-dock');
    check(`телефон, ${name}: нижняя полоса показывает ${dock}`,
        strip.some(s => has(s, dock)), strip);
    check(`телефон, ${name}: без ошибок в консоли`, errors.length === 0, errors);
    await context.close();
}

// --- окно (всплывает через POPUP delayMs = 12 с) ---------------------------
for (const [name, url, img, link] of [
    ['тирлист', '/index.php', 'playerok-popup.webp', PLAYEROK_LINK],
    ['лента', '/news.php', 'giveaway-popup.webp', GIVEAWAY_POST]
]) {
    const { context, page } = await open({ width: 1280, height: 900 }, url, 14000, link);
    const popup = await page.evaluate(() => ({
        img: (document.querySelector('.ptn-pop-img') || {}).getAttribute?.('src') || null,
        title: (document.querySelector('.ptn-pop-title') || {}).textContent || '',
        cta: (document.querySelector('.ptn-pop-cta') || {}).getAttribute?.('href') || null
    }));
    check(`окно, ${name}: макет ${img}`, has(popup.img, img), popup);
    // Текст окна идёт из словаря, а не из картинки: он обязан быть переведён.
    check(`окно, ${name}: заголовок из словаря`, popup.title.trim().length > 0, popup);
    check(`окно, ${name}: кнопка ведёт на нужную ссылку`, popup.cta === link, popup);
    await context.close();
}

await browser.close();
console.log(failed ? `\n${failed} проверок не прошло` : '\nВСЕ ПРОВЕРКИ ПРОШЛИ');
process.exit(failed ? 1 : 0);
