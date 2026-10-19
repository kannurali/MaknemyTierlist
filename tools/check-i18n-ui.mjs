// Проверка языков в настоящем браузере через локальный Apache (Task 6 плана
// docs/superpowers/plans/2026-10-20-multilang-pr1.md): автоопределение,
// переключение чипами, кука, перенос прежнего выбора из localStorage.
//   node tools/check-i18n-ui.mjs <папка-для-скриншотов>
// maknemy.com направляется на 127.0.0.1:8471, чтобы сработали правила
// канонического хоста; X-Forwarded-Proto: https снимает редирект на https.
// Чипы языка живут в меню аватара, которое гостю не открывается, поэтому
// нажатие на них — dispatchEvent, а не click по видимому элементу.
import { chromium } from 'playwright';
import fs from 'node:fs';

const out = process.argv[2] || '.';
fs.mkdirSync(out, { recursive: true });
const ORIGIN = 'http://maknemy.com';
const browser = await chromium.launch({
  channel: 'chrome',
  args: ['--host-resolver-rules=MAP maknemy.com 127.0.0.1:8471', '--disable-features=HttpsUpgrades'],
});
const fails = [];
const ok = (cond, msg) => { console.log((cond ? 'ok   ' : 'FAIL ') + msg); if (!cond) fails.push(msg); };

async function ctx(locale) {
  const c = await browser.newContext({ locale, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' }, viewport: { width: 1280, height: 900 } });
  await c.route('**/api/**', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
  const p = await c.newPage();
  p.setDefaultNavigationTimeout(45000);
  p.setDefaultTimeout(20000);
  p.on('pageerror', e => {
    console.log('pageerror:', String(e));
    if (/i18n|I18N|siteUrl|url is not/i.test(String(e))) fails.push('pageerror: ' + e);
  });
  return [c, p];
}
const path = p => new URL(p.url()).pathname;
const lang = p => p.evaluate(() => document.documentElement.lang);
const cookie = async c => ((await c.cookies(ORIGIN)).find(x => x.name === 'nx_lang') || {}).value;

{
  const [c, p] = await ctx('de-DE');
  await p.goto(ORIGIN + '/tierlist');
  ok(path(p) === '/en/tierlist', 'de-браузер попал на /en/tierlist: ' + p.url());
  ok(await lang(p) === 'en', 'html lang=en');
  ok(await p.locator('a[href="/en/calculator"]').count() > 0, 'шапка ведёт на /en/calculator');
  ok(await p.locator('link[hreflang="ru"]').count() === 1, 'hreflang ru');
  ok((await p.title()).startsWith('Maknemy Tier List — Blox Fruits'), 'английский title: ' + await p.title());
  await p.screenshot({ path: `${out}/en-tierlist.png` });

  await p.locator('#langSwitch [data-lang="ru"]').dispatchEvent('click');
  await p.waitForURL(u => new URL(u).pathname === '/tierlist');
  ok(await lang(p) === 'ru', 'RU-чип: html lang=ru');
  ok(await cookie(c) === 'ru', 'RU-чип: кука nx_lang=ru');

  await p.goto(ORIGIN + '/calculator');
  ok(path(p) === '/calculator', 'кука ru сильнее de-браузера');

  await p.locator('#langSwitch [data-lang="en"]').dispatchEvent('click');
  await p.waitForURL(u => new URL(u).pathname === '/en/calculator');
  ok(await cookie(c) === 'en', 'EN-чип: кука nx_lang=en');

  await p.goto(ORIGIN + '/');
  ok(path(p) === '/en', 'кука en уводит корень на /en');
  await p.screenshot({ path: `${out}/en-home.png` });
  await c.close();
}

{
  // Посетитель, выбравший EN ещё до мультиязычности: старый ключ лежит в
  // localStorage до первой загрузки нового i18n.js.
  const [c, p] = await ctx('ru-RU');
  await p.addInitScript(() => {
    if (!localStorage.getItem('nx-lang-migrated')) { localStorage.setItem('nexus-lang-v1', 'en'); }
  });
  await p.goto(ORIGIN + '/news');
  await p.waitForURL(u => new URL(u).pathname === '/en/news');
  ok(true, 'прежний выбор en из localStorage перенёс на /en/news');
  ok(await cookie(c) === 'en', 'перенос поставил куку en');
  await c.close();
}

{
  // Страницы сами пишут язык в старый ключ localStorage. Перенос делается
  // один раз, иначе чужая ссылка на /en/… навсегда переключила бы русского
  // посетителя на английский.
  const [c, p] = await ctx('ru-RU');
  await p.goto(ORIGIN + '/tierlist');
  ok(path(p) === '/tierlist', 'ru-браузер остаётся на русском');
  await p.goto(ORIGIN + '/en/news');
  ok(path(p) === '/en/news', 'ссылка /en/news открылась как есть');
  await p.goto(ORIGIN + '/calculator');
  ok(path(p) === '/calculator', 'после чужой /en/ ссылки русский остался русским');
  await p.goto(ORIGIN + '/tierlist');
  ok(path(p) === '/tierlist', 'и на следующей странице тоже');
  ok(await cookie(c) === undefined, 'кука выбора не появилась сама');
  await c.close();
}

await browser.close();
if (fails.length) { console.log(`\n${fails.length} FAIL`); process.exit(1); }
console.log('\nALL OK');
