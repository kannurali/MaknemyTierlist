// Browser check for the house giveaway: does it actually reach every slot?
//
//   node tools/check-giveaway-ui.mjs [http://127.0.0.1:8850]
//
// The unit suite asserts the SELECTION (tests/promo_test.mjs: houseFor picks
// the giveaway while it runs, a paid campaign still wins). It cannot see
// whether each page then draws it: the strip lives in app.js, the rails in
// three different page modules, the dock and the popup in shared ones. This
// walks all four placements on all three pages.
//
// /api/promo.php is answered with an empty document on purpose: locally the
// database holds demo campaigns which would (correctly) cover every slot, and
// then the check would prove nothing. The tier list banner (state.ad) is
// rewritten to the giveaway post for the same reason — on the live site it
// already points there, and that is the case worth checking.
//
// Needs Playwright (not a project dependency — see tools/make-mediakit-pdf.mjs
// for the install line) and a PHP server over public_html.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8850';
const GIVEAWAY_POST = 'https://t.me/theMaknemy/5302';
const has = (v, part) => typeof v === 'string' && v.includes(part);

const browser = await chromium.launch();
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

async function open(viewport, url, wait = 2500) {
    const context = await browser.newContext({ viewport });
    const page = await context.newPage();
    await page.route('**/api/promo.php*', r => r.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ v: 1, rev: 0, campaigns: [] })
    }));
    await page.route('**/api/tierlist.php*', async r => {
        const res = await r.fetch();
        const doc = await res.json();
        if (doc && doc.tierlist && doc.tierlist.ad) { doc.tierlist.ad.link = GIVEAWAY_POST; }
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
for (const [name, url, railSel] of [
    ['тирлист', '/index.php', '.ptn-rail'],
    ['лента', '/news.php', '.nw-rail'],
    ['калькулятор', '/calculator.php', '.tc-rail']
]) {
    const { context, page, errors } = await open({ width: 1600, height: 950 }, url);
    const rails = await page.evaluate(src, railSel);
    check(`${name}: оба борта заняты макетом розыгрыша`,
        rails.length >= 2 && rails.every(s => has(s, 'giveaway-rail.webp')), rails);
    if (url === '/index.php') {
        const strip = await page.evaluate(src, '.ptn-card, .ptn-slide');
        check('тирлист: лента показывает макет розыгрыша, а не картинку в 256 px',
            strip.some(s => has(s, 'giveaway-strip.webp')), strip);
    }
    check(`${name}: без ошибок в консоли`, errors.length === 0, errors);
    await context.close();
}

// --- нижняя полоса (телефон) ----------------------------------------------
{
    const { context, page, errors } = await open({ width: 390, height: 844 }, '/index.php');
    const dock = await page.evaluate(src, '.ptn-dock');
    check('телефон: нижняя полоса показывает розыгрыш',
        dock.some(s => has(s, 'giveaway-dock.webp')), dock);
    check('телефон: без ошибок в консоли', errors.length === 0, errors);
    await context.close();
}

// --- окно (всплывает через POPUP delayMs = 12 с) ---------------------------
{
    const { context, page } = await open({ width: 1280, height: 900 }, '/index.php', 14000);
    const popup = await page.evaluate(() => ({
        img: (document.querySelector('.ptn-pop-img') || {}).getAttribute?.('src') || null,
        title: (document.querySelector('.ptn-pop-title') || {}).textContent || '',
        cta: (document.querySelector('.ptn-pop-cta') || {}).getAttribute?.('href') || null
    }));
    check('окно: макет розыгрыша', has(popup.img, 'giveaway-popup.webp'), popup);
    // Текст окна идёт из словаря, а не из картинки: он обязан быть переведён.
    check('окно: заголовок из словаря', popup.title.trim().length > 0, popup);
    check('окно: кнопка ведёт на пост розыгрыша', popup.cta === GIVEAWAY_POST, popup);
    await context.close();
}

await browser.close();
console.log(failed ? `\n${failed} проверок не прошло` : '\nВСЕ ПРОВЕРКИ ПРОШЛИ');
process.exit(failed ? 1 : 0);
