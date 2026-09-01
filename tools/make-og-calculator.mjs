// Превью калькулятора для соцсетей: 1200×630 с карточкой вердикта на фоне
// страницы. Картинка снимается с ЖИВОЙ страницы, а не рисуется заново, — это
// единственный способ гарантировать, что превью и сайт показывают одну и ту же
// карточку: те же шрифты, та же обводка, тот же фон. Нарисованная отдельно
// копия разъехалась бы с сайтом на первой же правке calculator.css.
//
// Запуск (нужен поднятый сервер с /calculator):
//   node tools/make-og-calculator.mjs [baseUrl]
// По умолчанию baseUrl = http://localhost:8830.
//
// Результат: public_html/assets/og-calculator.jpg. Пересобирать при изменении
// вида карточки вердикта и поднимать ?v= в calculator.php.

import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const base = process.argv[2] || 'http://localhost:8830';
const here = path.dirname(fileURLToPath(import.meta.url));
const out = path.join(here, '..', 'public_html', 'assets', 'og-calculator.jpg');

// Размер превью — тот же, что у остальных картинок сайта (assets/og-card.jpg,
// assets/og-home.jpg): 1200×630 — формат, который соцсети показывают крупной
// карточкой, а не иконкой сбоку.
const W = 1200;
const H = 630;

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 1 });
await page.goto(base + '/calculator', { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);

await page.addStyleTag({ content: `
  /* Со страницы остаётся только карточка вердикта и фон под ней. Шапка,
     подвал, доска, борта и служебная полоса скрыты, а не обрезаны кадром:
     иначе их края лезли бы в превью с любого края. */
  .mk-top, .mk-foot, .tc-board, .tc-rail-slot, .tc-gauge, .tc-extras,
  .tc-cat-backdrop, .ptn-chip, .ptn-erid { display: none !important; }

  html, body { overflow: hidden !important; }
  .tc-page { --u: 1.62px !important; }
  .tc-frame { height: ${H}px !important; }

  /* Карточка встаёт по центру кадра. Значок висит над ней и выходит за верх,
     поэтому центр смещён вниз на его половину — иначе композиция «съезжала»
     вверх ровно на 44 макетные точки. */
  .tc-result {
    /* fixed, а не absolute: absolute отсчитывается от .tc-frame шириной
       1443 макетных точек (при --u 1.62 это 2338px), и «центр» уезжал за
       правый край кадра почти на треть. */
    position: fixed !important;
    left: 50% !important;
    top: 50% !important;
    transform: translate(-50%, calc(-50% + 22 * var(--u))) !important;
  }
` });
await page.waitForTimeout(400);

await page.screenshot({ path: out, type: 'jpeg', quality: 90, clip: { x: 0, y: 0, width: W, height: H } });
const box = await page.locator('.tc-result-card').boundingBox();
console.log('saved', out);
console.log('card box', box && { x: Math.round(box.x), y: Math.round(box.y), w: Math.round(box.width), h: Math.round(box.height) });
await browser.close();
