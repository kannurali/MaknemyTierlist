// Browser checks for the advertising placements.
//
//   node tools/check-promo-ui.mjs [http://127.0.0.1:8080]
//
// The unit suite (bash tests/run_all.sh) covers the scheduling, the rotation
// and the storage. None of it can see layout, and layout is where this
// feature actually breaks: buttons drifting over a paid banner, a carousel
// that stops moving, a popup that will not close. This fills that gap.
//
// Needs Playwright (not a project dependency — see tools/make-mediakit-pdf.mjs
// for the install line) and campaigns seeded for every slot. With the demo
// data that means running tools/make-demo-creatives.py and saving those
// creatives into strip / rail / dock / popup.
//
// IMPORTANT: run against a browser that composites. The in-app preview pane
// dispatches no scroll events while it is not on screen, which reports swipe
// and autoplay as broken when they are fine.
import { chromium } from 'playwright';

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const PHONE = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true };

let pass = 0, fail = 0;
const ok = (cond, name, detail) => {
  if (cond) { pass++; console.log(`  ok   ${name}`); }
  else { fail++; console.log(`  FAIL ${name}${detail !== undefined ? '  → ' + JSON.stringify(detail) : ''}`); }
};
const head = t => console.log(`\n== ${t} ==`);

// The popup would swallow hovers and clicks in most of these checks.
const SUPPRESS_POPUP = () => {
  const now = Date.now(), seen = { _v: 1 };
  ['c_demo_aug', 'c_demo_anim', 'c_demo_brand'].forEach(id => {
    seen[id] = { last: now, clicked: 0, hits: [now] };
  });
  localStorage.setItem('nx-ptn-seen-v1', JSON.stringify(seen));
};

const browser = await chromium.launch();

async function open(ctx, { suppressPopup = true } = {}) {
  const page = await ctx.newPage();
  if (suppressPopup) await page.addInitScript(SUPPRESS_POPUP);
  await page.goto(BASE + '/');
  await page.waitForSelector('.ptn-strip', { timeout: 20000 });
  await page.waitForTimeout(1500);
  return page;
}

const rects = page => page.evaluate(() => {
  const r = s => {
    const e = document.querySelector(s);
    if (!e) return null;
    const b = e.getBoundingClientRect();
    return b.width ? { l: Math.round(b.left), r: Math.round(b.right), t: Math.round(b.top), b: Math.round(b.bottom) } : null;
  };
  return { railL: r('#promoRailL'), railR: r('#promoRailR'), dock: r('#promoDock'),
           like: r('#likeBtn'), donate: r('#donateBtn'), banner: r('.ptn-slide .ptn-img'),
           prev: r('.ptn-prev'), next: r('.ptn-next') };
});
const overlaps = (a, b) => !!(a && b) && a.l < b.r && b.l < a.r && a.t < b.b && b.t < a.b;

// ---------------------------------------------------------------- carousel
{
  head('Карусель (десктоп)');
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await open(ctx);
  const dot = () => page.$$eval('.ptn-nav', n => n.length);
  const barOpacity = () => page.$eval('.ptn-nav', e => getComputedStyle(e).opacity);

  ok((await page.$$('.ptn-toggle')).length === 0, 'кнопки паузы нет');
  ok((await page.$$('.ptn-dot')).length === 0, 'точек-индикаторов нет');
  ok(await dot() === 2, 'две стрелки');
  ok(await barOpacity() === '0', 'стрелки не видны в покое');

  const before = await page.$eval('.ptn-viewport', v => Math.round(v.scrollLeft));
  await page.waitForTimeout(16000);
  const after = await page.$eval('.ptn-viewport', v => Math.round(v.scrollLeft));
  ok(before === after, 'само не листается 16 секунд', { before, after });

  await page.hover('.ptn-strip');
  await page.waitForTimeout(400);
  ok(await barOpacity() === '1', 'стрелки появляются при наведении');

  const r = await rects(page);
  ok(r.prev.l >= r.banner.l - 40 && r.next.r <= r.banner.r + 40, 'стрелки на краях баннера', r);
  const mid = (r.banner.t + r.banner.b) / 2;
  ok(Math.abs((r.prev.t + r.prev.b) / 2 - mid) < 30, 'стрелки по вертикальной середине');

  await page.click('.ptn-next');
  await page.waitForTimeout(900);
  ok(await page.$eval('.ptn-viewport', v => v.scrollLeft > 10), 'стрелка листает');

  await page.mouse.move(4, 4);
  await page.waitForTimeout(400);
  ok(await barOpacity() === '0', 'стрелки гаснут после ухода курсора');

  ok(await page.evaluate(async () => {
    let created = 0;
    const si = window.setInterval;
    window.setInterval = function (...a) { created++; return si.apply(window, a); };
    const chips = [...document.querySelectorAll('#langSwitch .chip')];
    for (let i = 0; i < 8; i++) { chips[i % 2].click(); await new Promise(r => setTimeout(r, 120)); }
    return created === 0 && document.querySelectorAll('.ptn-strip').length === 1;
  }), 'перерисовки не плодят таймеры и карусели');

  await ctx.close();
}

// ------------------------------------------------------------------ popup
{
  head('Всплывающее окно');
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await ctx.newPage();
  await page.goto(BASE + '/');
  await page.waitForSelector('.ptn-strip', { timeout: 20000 });
  await page.evaluate(() => localStorage.removeItem('nx-ptn-seen-v1'));
  await page.reload();
  await page.waitForSelector('.ptn-strip', { timeout: 20000 });

  await page.waitForTimeout(6000);
  ok(await page.$eval('#promoPop', p => p.hidden), 'на 6-й секунде ещё не показан');
  await page.waitForSelector('#promoPop:not([hidden])', { timeout: 15000 });
  ok(true, 'показан к 12-й секунде');

  const st = await page.evaluate(() => ({
    locked: document.body.classList.contains('ptn-locked'),
    role: document.getElementById('promoPop').getAttribute('role'),
    modal: document.getElementById('promoPop').getAttribute('aria-modal'),
    z: getComputedStyle(document.getElementById('promoPop')).zIndex,
    focusOnClose: document.activeElement === document.getElementById('promoPopClose'),
    decoded: document.getElementById('promoPopImg').naturalWidth > 0,
    bgHidden: document.getElementById('toolbar').getAttribute('aria-hidden')
  }));
  ok(st.locked, 'фон заблокирован от прокрутки');
  ok(st.role === 'dialog' && st.modal === 'true', 'объявлен диалогом');
  ok(st.z === '90', 'z-index 90 — ниже модалок, выше кнопок', st.z);
  ok(st.focusOnClose, 'фокус на кнопке закрытия');
  ok(st.decoded, 'картинка раскодирована до показа');
  ok(st.bgHidden === 'true', 'фон скрыт от скринридера');

  const inside = [];
  for (let i = 0; i < 5; i++) {
    await page.keyboard.press('Tab');
    inside.push(await page.evaluate(() => document.getElementById('promoPop').contains(document.activeElement)));
  }
  ok(inside.every(Boolean), 'фокус не уходит из окна');

  await page.keyboard.press('Escape');
  await page.waitForTimeout(250);
  ok(await page.$eval('#promoPop', p => p.hidden), 'Esc закрывает');
  ok(!await page.evaluate(() => document.body.classList.contains('ptn-locked')), 'прокрутка возвращается');

  await page.reload();
  await page.waitForSelector('.ptn-strip', { timeout: 20000 });
  await page.waitForTimeout(15000);
  ok(await page.$eval('#promoPop', p => p.hidden), 'после перезагрузки не показывается сутки');
  await ctx.close();
}

// ------------------------------------------------------------------ rails
{
  head('Боковые борта');
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } });
  const page = await open(ctx);

  for (const [w, h, want] of [[1600, 900, true], [1461, 900, true], [1459, 900, false],
                              [1600, 761, true], [1600, 759, false]]) {
    await page.setViewportSize({ width: w, height: h });
    await page.waitForTimeout(450);
    const shown = await page.evaluate(() =>
      [...document.querySelectorAll('.ptn-rail')].filter(e => !e.hidden && getComputedStyle(e).display !== 'none').length);
    ok((shown === 2) === want, `${w}x${h}: борта ${want ? 'показаны' : 'скрыты'}`, { shown });
  }

  await page.setViewportSize({ width: 1600, height: 900 });
  await page.waitForTimeout(450);
  const r = await rects(page);
  ok(!overlaps(r.like, r.railL) && !overlaps(r.like, r.railR), 'лайк не наезжает на борта', r);
  ok(!overlaps(r.donate, r.railL) && !overlaps(r.donate, r.railR), 'донат не наезжает на борта', r);
  ok(!overlaps(r.like, r.banner) && !overlaps(r.donate, r.banner), 'кнопки не наезжают на баннер');
  ok(await page.evaluate(() => !document.querySelector('#stage #promoRailL')), 'борта вне сцены (значит не в PNG)');
  await ctx.close();
}

// ------------------------------------------------------- phone bottom dock
{
  head('Нижняя полоса (телефон)');
  const ctx = await browser.newContext(PHONE);
  const page = await open(ctx);
  const r = await rects(page);

  ok(await page.$eval('#promoDock', d => getComputedStyle(d).position) === 'fixed', 'полоса закреплена');
  ok(Math.abs(r.dock.b - 844) <= 1, 'прижата к низу экрана', r.dock);
  ok(r.like.l < 195 && r.donate.r > 195, 'лайк слева, донат справа', { like: r.like, donate: r.donate });
  ok(r.like.b <= r.dock.t && r.donate.b <= r.dock.t, 'кнопки над полосой');
  ok(!overlaps(r.like, r.dock) && !overlaps(r.donate, r.dock), 'кнопки не наезжают на полосу');
  ok(await page.evaluate(() => !!document.querySelector('#tiers .ptn-strip')),
     'баннер в середине тирлиста на месте');
  ok((await page.$$eval('.ptn-nav', n => n.filter(e => e.offsetParent !== null).length)) === 0,
     'стрелок на телефоне нет');

  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(700);
  ok(await page.evaluate(() => {
    const c = document.querySelector('#credits').getBoundingClientRect();
    const d = document.getElementById('promoDock').getBoundingClientRect();
    return c.bottom <= d.top + 2;
  }), 'низ страницы не спрятан за полосой');

  ok(await page.evaluate(() => {
    const v = document.querySelector('.ptn-viewport');
    return v.scrollWidth > v.clientWidth && getComputedStyle(v).scrollSnapType.startsWith('x');
  }), 'баннеры листаются свайпом со snap');
  await ctx.close();
}

// ------------------------------------------------------------- desktop dock
{
  head('Полосы нет на десктопе');
  const ctx = await browser.newContext({ viewport: { width: 1600, height: 900 } });
  const page = await open(ctx);
  ok(await page.$eval('#promoDock', d => d.hidden || getComputedStyle(d).display === 'none'), 'полоса скрыта');
  ok(!await page.evaluate(() => document.body.classList.contains('has-promo-dock')), 'отступ снизу не добавлен');
  await ctx.close();
}

console.log(`\n${pass} проверок пройдено, ${fail} провалено`);
await browser.close();
process.exit(fail ? 1 : 0);
