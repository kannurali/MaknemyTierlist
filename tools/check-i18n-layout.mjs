// Вёрстка на всех языках: каждая публичная страница × каждый язык × телефон
// и компьютер через локальный Apache (как в tools/check-i18n-ui.mjs).
// Снимает скриншоты и ищет переполнения — элементы с текстом, у которых
// содержимое шире коробки или которые вылезают за край окна, — и печатает
// только те, которых нет в русской версии той же страницы.
//   node tools/check-i18n-layout.mjs <папка> [язык,язык…]
import { chromium } from 'playwright';
import fs from 'node:fs';

const out = process.argv[2] || 'layout';
const only = process.argv[3] ? process.argv[3].split(',') : null;
const LANGS = ['ru', 'en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk'].filter(l => !only || l === 'ru' || only.includes(l));
const PAGES = ['/', '/tierlist', '/calculator', '/news', '/trading', '/trading/new', '/stock', '/giveaways',
               '/ads', '/chat', '/support', '/profile?id=2841062255'];
const VIEWS = { phone: { width: 390, height: 844 }, desktop: { width: 1280, height: 900 } };
const ORIGIN = 'http://maknemy.com';
const IGNORE = '[class*="ticker"], [class*="marquee"], [class*="carousel"], .hm-track, .mk-lang-list, script, style, svg';

fs.mkdirSync(out, { recursive: true });
const browser = await chromium.launch({
  channel: 'chrome',
  args: ['--host-resolver-rules=MAP maknemy.com 127.0.0.1:8471', '--disable-features=HttpsUpgrades'],
});

function scan(ignoreSel) {
  const res = [];
  const vw = window.innerWidth;
  const path = el => {
    const parts = [];
    for (let n = el; n && n !== document.body && parts.length < 4; n = n.parentElement) {
      const cls = [...n.classList].slice(0, 2).join('.');
      parts.unshift(n.tagName.toLowerCase() + (n.id ? '#' + n.id : cls ? '.' + cls : ''));
    }
    return parts.join('>');
  };
  if (document.documentElement.scrollWidth > vw + 1) res.push({ key: 'DOCUMENT', text: 'горизонтальная прокрутка ' + document.documentElement.scrollWidth + 'px' });
  for (const el of document.querySelectorAll('a,button,h1,h2,h3,h4,p,span,li,label,th,td,strong,b,div')) {
    if (el.closest(ignoreSel)) continue;
    const own = [...el.childNodes].some(c => c.nodeType === 3 && c.textContent.trim());
    if (!own) continue;
    const r = el.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    const st = getComputedStyle(el);
    if (st.visibility === 'hidden' || st.display === 'none' || +st.opacity === 0) continue;
    const clipped = el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0;
    const offscreen = r.right > vw + 1 && r.left < vw;
    if (clipped || offscreen) res.push({ key: path(el), text: el.textContent.trim().slice(0, 60), w: el.clientWidth, sw: el.scrollWidth });
  }
  return res;
}

const report = {};
for (const [view, size] of Object.entries(VIEWS)) {
  const ctx = await browser.newContext({ viewport: size, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' }, reducedMotion: 'reduce' });
  await ctx.route('**/api/**', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
  const page = await ctx.newPage();
  page.setDefaultNavigationTimeout(45000);
  for (const path of PAGES) {
    const base = {};
    for (const lang of LANGS) {
      const url = ORIGIN + (lang === 'ru' ? path : '/' + lang + (path === '/' ? '' : path));
      await ctx.clearCookies();
      await ctx.addCookies([{ name: 'nx_lang', value: lang, url: ORIGIN }]);
      await page.goto(url, { waitUntil: 'load' });
      await page.addStyleTag({ content: '#promoPop{display:none!important} body.ptn-locked{overflow:auto!important}' });
      await page.waitForTimeout(400);
      const name = `${view}-${lang}-${(path.replace(/[/?=]/g, '_') || '_').replace(/^_/, '') || 'home'}`;
      await page.screenshot({ path: `${out}/${name}.png`, fullPage: false });
      const found = await page.evaluate(scan, IGNORE);
      if (lang === 'ru') { for (const f of found) base[f.key] = true; continue; }
      const fresh = found.filter(f => !base[f.key]);
      if (fresh.length) (report[`${view} ${lang} ${path}`] = fresh.slice(0, 12));
    }
  }
  await ctx.close();
}
await browser.close();
fs.writeFileSync(`${out}/report.json`, JSON.stringify(report, null, 2));
const n = Object.keys(report).length;
console.log(n ? `${n} страниц с новыми переполнениями — ${out}/report.json` : 'новых переполнений нет');
for (const [k, v] of Object.entries(report)) {
  console.log(`== ${k}`);
  for (const f of v) console.log(`   ${f.key} | ${f.text}${f.sw ? ` | ${f.w}→${f.sw}` : ''}`);
}
