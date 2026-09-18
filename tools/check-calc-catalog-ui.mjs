// Browser check for the calculator catalog: badge order and the tier-list
// filter (docs/superpowers/specs/2026-09-18-calc-catalog-sort-filter-design.md).
//
//   node tools/check-calc-catalog-ui.mjs [http://127.0.0.1:8880]
//
// tests/calc_test.mjs covers the pure functions and
// tests/calculator_page_test.php pins the markup and CSS as text. This drives
// the real page: card order, every chip, the last-group rule, filter plus
// search, the choice surviving a reopen, English labels, and the chip row on
// a computer and on two phone widths. Screenshots go to tools/out/.
//
// The tier list comes from a fixture: api/tierlist.php, api/state.php and
// api/promo.php are answered here, so the check needs no database content.
//
// Needs Playwright next to the script (tools/node_modules, ignored by git)
// and a PHP server over public_html with a config.php next to it:
//
//   php -S 127.0.0.1:8880 -t public_html
//
// PW_CHANNEL=chrome runs the installed Chrome when Playwright's own browser
// is not downloaded.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = process.argv[2] || 'http://127.0.0.1:8880';
const OUT = fileURLToPath(new URL('./out/', import.meta.url));
mkdirSync(OUT, { recursive: true });

const item = (id, name, value, type) => ({
    id, name, value, type, icon: 'assets/icon-sample.png', demand: 'green', trend: '',
});

// Tiers mix the badges on purpose: the catalog must not keep this order.
const FIXTURE = {
    title: 'MAKNEMY\nTIER LIST',
    date: '18.09.2026',
    tiers: [
        { id: 't1', label: 'S', items: [
            item('eclipse', 'Eclipse Dragon', '50000', 'cr'),
            item('token', 'Dragon Token', '25000', 'p'),
            item('slayer', 'Slayer', '20500', 'cs'),
            item('vibes', 'Vibes', '20000', 'vh'),
            item('galaxy', 'Galaxy', '19250', 'ms'),
        ] },
        { id: 't2', label: 'A', items: [
            item('pdragon', 'Permanent Dragon', '14250', 'p'),
            item('meme', 'Meme Fruit', '9000', 'f'),
            item('dragon', 'Dragon Fruit', '6140', 'f'),
            item('notifier', 'Fruit Notifier', '5400', 'gp'),
            item('werewolf', 'Werewolf', '1600', 'cm'),
        ] },
        { id: 't3', label: 'B', items: [
            item('ptiger', 'Permanent Tiger', '7800', 'p'),
            item('pyeti', 'Permanent Yeti', '7800', 'p'),
            item('eagle', 'Eagle Fruit', '0,4', 'f'),
            item('dark', 'Dark Fruit', '0.3', 'f'),
        ] },
    ],
    _rev: 1,
};

// "0,4" is unreadable for the calculator (0), so Eagle Fruit ends the fruits.
const GROUP = {
    fruits: ['Meme Fruit', 'Dragon Fruit', 'Dark Fruit', 'Eagle Fruit'],
    configurators: ['Slayer', 'Werewolf', 'Galaxy', 'Eclipse Dragon'],
    perms: ['Dragon Token', 'Permanent Dragon', 'Permanent Tiger', 'Permanent Yeti'],
    passes: ['Fruit Notifier', 'Vibes'],
};
const ALL = [...GROUP.fruits, ...GROUP.configurators, ...GROUP.perms, ...GROUP.passes];
const ALL_ON = { fruits: true, configurators: true, perms: true, passes: true, all: true };

const browser = await chromium.launch({ channel: process.env.PW_CHANNEL || undefined });
let failed = 0;

function check(name, ok, detail) {
    console.log(`${ok ? 'ok  ' : 'FAIL'} - ${name}${ok ? '' : '   ' + JSON.stringify(detail)}`);
    if (!ok) failed++;
}

const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const names = page => page.$$eval('#tcCatalogGrid .tc-cat-name', els => els.map(e => e.textContent));
const pressed = page => page.$$eval('#tcCatalogFilters .chip', els =>
    Object.fromEntries(els.map(e => [e.dataset.f, e.getAttribute('aria-pressed') === 'true'])));
const chip = (page, key) => page.click(`#tcCatalogFilters .chip[data-f="${key}"]`);

async function openCatalog(page) {
    await page.click('.tc-slots[data-side="left"] .tc-slot.is-empty');
    await page.waitForSelector('#tcCatalogGrid .tc-cat-name');
}

async function closeCatalog(page) {
    await page.keyboard.press('Escape');
    await page.waitForSelector('#tcCatalogBackdrop', { state: 'hidden' });
}

async function layout(page) {
    return page.evaluate(() => {
        const box = el => el.getBoundingClientRect();
        const filters = document.getElementById('tcCatalogFilters');
        const chips = [...filters.querySelectorAll('.chip')].map(box);
        const pill = box(document.getElementById('tcCatalogTitle'));
        const close = box(document.getElementById('tcCatalogClose'));
        const mid = r => Math.round(r.top + r.height / 2);
        return {
            pillMid: mid(pill), closeMid: mid(close), chipsMid: mid(chips[0]),
            pillBottom: Math.round(pill.bottom), pillLeft: Math.round(pill.left),
            chipsTop: Math.round(Math.min(...chips.map(c => c.top))), chipsLeft: Math.round(chips[0].left),
            fits: filters.scrollWidth <= filters.clientWidth,
            pageOverflow: document.documentElement.scrollWidth - window.innerWidth,
        };
    });
}

async function session(name, { width, height, mobile = false }, body) {
    const context = await browser.newContext({
        viewport: { width, height },
        isMobile: mobile,
        hasTouch: mobile,
        locale: 'ru-RU',
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    // php -S serves one connection at a time, and a speculative connection
    // Chromium opens without sending a request can hold it for ~10 s.
    page.setDefaultNavigationTimeout(45000);
    const errors = [];
    page.on('pageerror', e => errors.push(String(e)));
    const json = data => ({ status: 200, contentType: 'application/json', body: JSON.stringify(data) });
    await page.route('**/api/state.php*', r => r.fulfill(json({ rev: 1, likes: 0 })));
    await page.route('**/api/tierlist.php*', r => r.fulfill(json({ tierlist: FIXTURE, likes: 0 })));
    await page.route('**/api/promo.php*', r => r.fulfill(json({ v: 1, rev: 0, campaigns: [] })));
    // The house popup opens 12 s after load whenever the catalog happens to be
    // closed and would swallow the next click. The page checks for
    // window.NX_PROMO_POPUP before using it, so an empty script is safe.
    await page.route('**/js/promo-popup.js*', r => r.fulfill({ status: 200, contentType: 'application/javascript', body: '' }));
    try {
        await page.goto(BASE + '/calculator.php', { waitUntil: 'domcontentloaded' });
        // The grid is built when the catalog opens: opening it before the
        // tier list arrives shows an empty catalog.
        await page.waitForFunction(() => document.getElementById('tcState').hidden);
        await openCatalog(page);
        await body(page);
        check(`${name}: без ошибок на странице`, errors.length === 0, errors);
    } catch (e) {
        check(`${name}: прогон`, false, String(e).split('\n')[0]);
    } finally {
        await context.close();
    }
}

await session('компьютер', { width: 1440, height: 1000 }, async page => {
    check('карточки идут по значку, внутри значка по цене', same(await names(page), ALL), await names(page));
    check('при открытии включено всё', same(await pressed(page), ALL_ON), await pressed(page));

    const row = await layout(page);
    check('кнопки в одной строке с «Каталог» и крестиком',
        Math.abs(row.pillMid - row.chipsMid) <= 2 && Math.abs(row.closeMid - row.chipsMid) <= 2, row);
    check('все пять кнопок видны без прокрутки', row.fits, row);
    await page.screenshot({ path: join(OUT, 'calc-catalog-desktop.png') });

    await chip(page, 'perms');
    check('«Пермы» прячет пермы', same(await names(page), ALL.filter(n => !GROUP.perms.includes(n))), await names(page));
    check('«Все» гаснет, когда группа выключена', same(await pressed(page),
        { fruits: true, configurators: true, perms: false, passes: true, all: false }), await pressed(page));
    await page.screenshot({ path: join(OUT, 'calc-catalog-desktop-noperms.png') });

    await chip(page, 'fruits');
    await chip(page, 'configurators');
    check('включены только пассы', same(await names(page), GROUP.passes), await names(page));
    await chip(page, 'passes');
    check('последнюю группу выключить нельзя',
        same(await names(page), GROUP.passes) && (await pressed(page)).passes === true, await pressed(page));

    await chip(page, 'all');
    check('«Все» возвращает всё', same(await names(page), ALL), await names(page));
    check('«Все» подсвечена', same(await pressed(page), ALL_ON), await pressed(page));

    await page.$eval('#tcCatalogGrid', g => { g.scrollTop = g.scrollHeight; });
    await chip(page, 'passes');
    const scrolled = await page.$eval('#tcCatalogGrid', g => g.scrollTop);
    check('смена фильтра прокручивает сетку в начало', scrolled === 0, scrolled);

    await chip(page, 'passes');
    await chip(page, 'perms');
    await page.fill('#tcCatalogSearch', 'dragon');
    await page.waitForTimeout(400);
    check('поиск идёт внутри включённых групп',
        same(await names(page), ['Dragon Fruit', 'Eclipse Dragon']), await names(page));

    await closeCatalog(page);
    await openCatalog(page);
    const again = { pressed: await pressed(page), names: await names(page) };
    check('выбор фильтра переживает повторное открытие',
        again.pressed.perms === false && same(again.names, ALL.filter(n => !GROUP.perms.includes(n))), again);
    check('поиск при повторном открытии сброшен', (await page.inputValue('#tcCatalogSearch')) === '',
        await page.inputValue('#tcCatalogSearch'));

    await closeCatalog(page);
    await page.click('#langSwitch [data-lang="en"]');
    await openCatalog(page);
    const labels = await page.$$eval('#tcCatalogFilters .chip', els => els.map(e => e.textContent));
    check('подписи кнопок переводятся', same(labels, ['Fruits', 'Configurators', 'Perms', 'Passes', 'All']), labels);
    const group = await page.getAttribute('#tcCatalogFilters', 'aria-label');
    check('название группы переводится', group === 'Filter by category', group);
    const en = await layout(page);
    check('английские подписи тоже влезают', en.fits, en);
});

for (const width of [390, 360]) {
    await session(`телефон ${width}`, { width, height: 844, mobile: true }, async page => {
        const lay = await layout(page);
        check(`${width}: кнопки отдельной строкой под «Каталог»`, lay.chipsTop >= lay.pillBottom, lay);
        check(`${width}: все пять кнопок влезают без прокрутки`, lay.fits, lay);
        check(`${width}: кнопки начинаются от левого края, как «Каталог»`, Math.abs(lay.chipsLeft - lay.pillLeft) <= 2, lay);
        check(`${width}: у страницы нет горизонтальной прокрутки`, lay.pageOverflow <= 0, lay);
        await page.screenshot({ path: join(OUT, `calc-catalog-phone-${width}.png`) });

        await chip(page, 'fruits');
        check(`${width}: касание кнопки прячет группу`,
            same(await names(page), ALL.filter(n => !GROUP.fruits.includes(n))), await names(page));
    });
}

await browser.close();
console.log(failed ? `\n${failed} FAILED` : '\nALL OK');
process.exit(failed ? 1 : 0);
