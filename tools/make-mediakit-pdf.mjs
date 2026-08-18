// Builds docs/mediakit/mediakit.pdf by PRINTING the media kit page itself, so
// the file and the page can never drift apart. Re-run it after any edit to
// mediakit.html, mediakit.css or the screenshots.
//
//   node tools/make-mediakit-pdf.mjs
//
// The kit lives in docs/, outside the web root: it is a commercial offer for
// one advertiser, not a page of the site, and the cPanel deploy is additive -
// a file published once has to be deleted from the server by hand. So the
// page is opened from disk over file://, no server involved.
//
// Needs Playwright, which is deliberately NOT a dependency of this project
// (no package.json, no npm — see the Global Constraints in the migration
// plan). Install it wherever it is convenient and run from there:
//
//   npm init -y && npm install playwright && npx playwright install chromium
//
// Chromium only: page.pdf() does not exist in the other engines.
//
// The dark background is kept on purpose. The file goes to an advertiser
// over a messenger and is read on a screen; inverted to white, the dark
// cards look broken. That is why printBackground is on.
import { chromium } from 'playwright';
import { statSync, mkdirSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath, pathToFileURL } from 'url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PAGE = pathToFileURL(resolve(ROOT, 'docs/mediakit/mediakit.html')).href;
const OUT = resolve(ROOT, 'docs/mediakit/mediakit.pdf');

mkdirSync(dirname(OUT), { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1100, height: 1400 } });
const problems = [];
page.on('pageerror', e => problems.push('JS: ' + e));
page.on('requestfailed', r => problems.push('FAILED ' + r.url()));

await page.goto(PAGE, { waitUntil: 'networkidle' });

// Every image has to be decoded before printing. The screenshots are lazy,
// and a lazy image that has not entered the viewport prints as a blank box.
await page.evaluate(async () => {
  const imgs = [...document.images];
  imgs.forEach(i => { i.loading = 'eager'; });
  await Promise.all(imgs.map(i => (i.complete && i.naturalWidth)
    ? Promise.resolve()
    : new Promise(r => {
        i.addEventListener('load', r, { once: true });
        i.addEventListener('error', r, { once: true });
      })));
});
await page.waitForTimeout(700);

const state = await page.evaluate(() => ({
  images: document.images.length,
  broken: [...document.images].filter(i => !i.naturalWidth).map(i => i.getAttribute('src')),
  draftRibbon: !document.getElementById('draftRibbon').hidden,
  unfilled: document.querySelectorAll('.blank').length
}));

await page.emulateMedia({ media: 'print' });
await page.pdf({
  path: OUT,
  format: 'A4',
  printBackground: true,
  margin: { top: '10mm', bottom: '12mm', left: '8mm', right: '8mm' },
  displayHeaderFooter: true,
  headerTemplate: '<div></div>',
  footerTemplate: '<div style="width:100%;font-size:8px;color:#7d9dc4;padding:0 10mm;'
    + 'display:flex;justify-content:space-between;">'
    + '<span>Maknemy Tier List — реклама</span>'
    + '<span class="pageNumber"></span>/<span class="totalPages"></span></div>'
});

console.log(JSON.stringify({ out: OUT, sizeKB: Math.round(statSync(OUT).size / 1024), ...state, problems }, null, 1));
if (state.broken.length || problems.length) {
  console.error('\n!! PDF собран, но с проблемами выше — проверьте файл перед отправкой.');
  process.exitCode = 1;
}
if (state.draftRibbon) {
  console.error(`\n!! В PDF стоит лента ЧЕРНОВИК: не заполнено полей — ${state.unfilled}.`);
  console.error('   Правится в блоке PRICES / AUDIENCE в конце docs/mediakit/mediakit.html.');
}
await browser.close();
