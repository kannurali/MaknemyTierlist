# News block editor implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat news body with an ordered list of rich blocks, and replace the single-image form on `/admin/news` with a Telegram-style block editor.

**Architecture:** A post gains a `body_json` column holding `{"v":1,"blocks":[…]}`. Pure model + renderer live in a new `js/news-blocks.js` that takes `document` as an argument (node-testable, no globals). The server mirrors the validator in `api/lib/news_blocks.php` and derives the legacy `body_ru`/`body_en`/`image_url`/`image_width`/`image_height` columns from the blocks, so link previews and the no-JS fallback keep working untouched. The editor moves out of `js/news-page.js` into `js/news-editor.js`, loaded only by `/admin/news`.

**Tech Stack:** Plain ES5-compatible browser JS (no build step, no bundler, no framework), PHP 7.4-compatible procedural code with PDO, MySQL on production / SQLite in tests, `node --test` for JS units, a hand-rolled PHP test harness in `tests/lib.php`.

**Spec:** `docs/superpowers/specs/2026-08-29-news-block-editor-design.md`

## Global Constraints

- **Never use `innerHTML` for post content.** Post bodies are built with `createElement` / `createTextNode` / `textContent` only. `innerHTML = ""` to clear a container the code itself owns is fine and already used (`renderCatSeg`).
- **PHP must run on 7.4.** No `match`, no enums, no constructor promotion, no `str_contains`, no named arguments. `??` and `?:` are fine.
- **No new dependencies.** Not in PHP, not in node. `tools/node_modules` holds only Playwright.
- **Comments in Russian**, matching every existing file. Comments explain *why*, not *what* — follow the tone of `api/news.php` and `js/news.js`.
- **Modules are dependency-free and node-requirable:** `js/news-blocks.js` follows the `js/news.js` pattern — an IIFE that assigns to `module.exports` when present and to the global otherwise, and never touches `document` except through a passed-in argument.
- **One source of truth per rule.** Every limit that both sides enforce (block types, span flags, ceilings) gets a comment on each side pointing at the other, the way `NEWS_CATEGORIES` in `api/news.php` and `CATEGORIES` in `js/news.js` already do.
- **Run the suite with:** `PHP=/c/xampp/php/php.exe bash tests/run_all.sh`. On this machine XAMPP's `php.exe` has GD disabled, so `tests/images_test.php` needs `-d extension=gd`; see Task 12.
- **Ceilings, copied verbatim from the spec:** 200 blocks per post, 10 images per album, 65536 bytes of `body_json`, `NEWS_BODY_MAX` (20000) on the derived plain text, `href` limited to `http`/`https`, image URLs limited to `#^/images/[0-9a-f]{40}\.(png|jpg|webp)$#`.
- **Document version:** `{"v":1}`. Any other `v` is rejected, never coerced.

## File structure

| File | Status | Responsibility |
| --- | --- | --- |
| `public_html/js/news-blocks.js` | create | Block model: constants, normalisation, validation, plain-text derivation, DOM rendering. No globals, no DOM except via the passed `doc`. |
| `public_html/js/news-editor.js` | create | The whole admin editor: block list, inline formatting, image/album blocks, crop, save. Loaded only by `/admin/news`. |
| `public_html/api/lib/news_blocks.php` | create | Server-side mirror of the validator plus the derivation helpers. |
| `public_html/js/news-page.js` | modify | Loses the editor (moves to `news-editor.js`); `cardFor()` renders blocks when the post has them. |
| `public_html/js/news.js` | modify | Nothing removed. `toParagraphs` stays — legacy posts still use it. |
| `public_html/api/news_save.php` | modify | Accepts `body_json`, validates it, derives the legacy columns. |
| `public_html/api/news.php` | modify | Selects `body_json`, degrades to the legacy path on `1054 unknown column`. |
| `public_html/admin-news.php` | modify | Editor markup replaced by the block editor shell; loads `news-editor.js`. |
| `public_html/news.php` | modify | Loads `news-blocks.js`; cache-bust bumps. |
| `public_html/css/news-design.css` | modify | Styles for quote, list, code, spoiler, album, inline link. |
| `public_html/css/admin-shell.css` | modify | Styles for the block editor chrome. |
| `schema.sql` | modify | `body_json` column. |
| `docs/migrations/2026-08-29-news-blocks.sql` | create | The production `ALTER TABLE`. |
| `tests/lib.php` | modify | `body_json` in the SQLite mirror of the schema. |
| `tests/news_blocks_test.mjs` | create | Model + renderer units. |
| `tests/news_test.php` | modify | Server validation, ceilings, derivation, the `1054` window. |

---

### Task 1: Block model — constants, validation, plain text

**Files:**
- Create: `public_html/js/news-blocks.js`
- Test: `tests/news_blocks_test.mjs`

**Interfaces:**
- Consumes: nothing.
- Produces: global/CommonJS `NEWSBLOCKS` with `LIMITS`, `BLOCK_TYPES`, `SPAN_FLAGS`, `isSafeHref(str) -> bool`, `validateDoc(any) -> {ok: bool, error: string, blocks: Array}`, `toPlainText(blocks, lang) -> string`, `firstImage(blocks) -> {url, w, h}|null`.

- [ ] **Step 1: Write the failing test**

Create `tests/news_blocks_test.mjs`:

```js
// Unit tests for the block model. Run: node --test tests/news_blocks_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const B = require('../public_html/js/news-blocks.js');

const IMG = '/images/' + 'a'.repeat(40) + '.webp';
const p = (ru, en) => ({ t: 'p', ru: [{ s: ru }], en: en ? [{ s: en }] : [] });
const doc = blocks => ({ v: 1, blocks: blocks });

test('a minimal paragraph document validates', () => {
    const r = B.validateDoc(doc([p('Привет')]));
    assert.equal(r.ok, true, r.error);
    assert.equal(r.blocks.length, 1);
    assert.equal(r.blocks[0].t, 'p');
});

test('a document with the wrong version is rejected, not coerced', () => {
    assert.equal(B.validateDoc({ v: 2, blocks: [p('x')] }).ok, false);
    assert.equal(B.validateDoc({ blocks: [p('x')] }).ok, false);
});

test('an unknown block type is rejected', () => {
    assert.equal(B.validateDoc(doc([{ t: 'video', ru: [], en: [] }])).ok, false);
});

test('an unknown key inside a block is rejected, not silently dropped', () => {
    const bad = { t: 'p', ru: [{ s: 'x' }], en: [], onclick: 'alert(1)' };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('an unknown span flag is rejected', () => {
    const bad = { t: 'p', ru: [{ s: 'x', evil: true }], en: [] };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('every telegram flag is accepted and composes', () => {
    const span = { s: 'x', b: true, i: true, u: true, st: true, c: true, sp: true };
    assert.equal(B.validateDoc(doc([{ t: 'p', ru: [span], en: [] }])).ok, true);
});

test('only http and https hrefs survive', () => {
    assert.equal(B.isSafeHref('https://example.com/a'), true);
    assert.equal(B.isSafeHref('http://example.com/a'), true);
    assert.equal(B.isSafeHref('javascript:alert(1)'), false);
    assert.equal(B.isSafeHref('data:text/html,x'), false);
    assert.equal(B.isSafeHref('/news/1'), false);
    assert.equal(B.isSafeHref('JavaScript:alert(1)'), false);
});

test('a bad href fails the whole document', () => {
    const bad = { t: 'p', ru: [{ s: 'x', href: 'javascript:alert(1)' }], en: [] };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('an image url outside the upload directory is rejected', () => {
    const mk = url => doc([{ t: 'image', url: url, w: 10, h: 10, pct: 100, align: 'center', wrap: false, cap_ru: [], cap_en: [] }]);
    assert.equal(B.validateDoc(mk(IMG)).ok, true);
    assert.equal(B.validateDoc(mk('https://evil.example/x.png')).ok, false);
    assert.equal(B.validateDoc(mk('/images/../../etc/passwd')).ok, false);
});

test('image geometry follows the same rules as the legacy columns', () => {
    const mk = extra => doc([Object.assign({ t: 'image', url: IMG, w: 10, h: 10, pct: 100, align: 'center', wrap: false, cap_ru: [], cap_en: [] }, extra)]);
    assert.equal(B.validateDoc(mk({ pct: 9 })).ok, false, 'below 10');
    assert.equal(B.validateDoc(mk({ pct: 101 })).ok, false, 'above 100');
    assert.equal(B.validateDoc(mk({ align: 'top' })).ok, false, 'not an alignment');
});

test('ceilings are enforced', () => {
    const many = [];
    for (let i = 0; i < B.LIMITS.blocks + 1; i++) { many.push(p('x')); }
    assert.equal(B.validateDoc(doc(many)).ok, false, 'too many blocks');

    const items = [];
    for (let i = 0; i < B.LIMITS.albumItems + 1; i++) { items.push({ url: IMG, w: 10, h: 10 }); }
    assert.equal(B.validateDoc(doc([{ t: 'album', items: items, cap_ru: [], cap_en: [] }])).ok, false);
});

test('plain text concatenates text blocks and falls back across languages', () => {
    const blocks = [
        { t: 'p', ru: [{ s: 'Первый' }, { s: ' абзац', b: true }], en: [{ s: 'First para' }] },
        { t: 'quote', ru: [{ s: 'Цитата' }], en: [], collapsible: false },
        { t: 'image', url: IMG, w: 1, h: 1, pct: 100, align: 'center', wrap: false, cap_ru: [{ s: 'Подпись' }], cap_en: [] }
    ];
    assert.equal(B.toPlainText(blocks, 'ru'), 'Первый абзац\n\nЦитата\n\nПодпись');
    // en is empty on the quote and the caption, so those fall back to ru —
    // the same rule pickLang() already applies to the whole post.
    assert.equal(B.toPlainText(blocks, 'en'), 'First para\n\nЦитата\n\nПодпись');
});

test('firstImage finds the first picture, album or not', () => {
    const alb = { t: 'album', items: [{ url: IMG, w: 4, h: 5 }], cap_ru: [], cap_en: [] };
    assert.deepEqual(B.firstImage([p('x'), alb]), { url: IMG, w: 4, h: 5 });
    assert.equal(B.firstImage([p('x')]), null);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/news_blocks_test.mjs`
Expected: FAIL — `Cannot find module '../public_html/js/news-blocks.js'`.

- [ ] **Step 3: Write the model**

Create `public_html/js/news-blocks.js`:

```js
// Модель блочного тела поста: константы, валидация, плоский текст, рендер.
//
// Файл не трогает глобальный document: рендер (см. вторую половину файла)
// принимает его аргументом. Поэтому модуль требуется из node в тестах — как
// news.js, i18n.js, tiers.js и content.js.
(function (root) {
  "use strict";

  // Версия формата. Документ с другой версией отклоняется, а не разбирается
  // «как получится»: молчаливый разбор чужой версии — это тихая порча поста,
  // а явный отказ виден сразу.
  var DOC_VERSION = 1;

  // Потолки. Тот же список лежит в api/lib/news_blocks.php (NB_LIMIT_*) —
  // если правится один, обязан правиться и второй, иначе редактор предложит
  // то, чего сервер не примет. Та же дисциплина, что у CATEGORIES в news.js
  // и NEWS_CATEGORIES в api/news.php.
  var LIMITS = {
    blocks: 200,
    albumItems: 10,
    json: 65536,
    listItems: 100,
    spans: 200
  };

  var BLOCK_TYPES = ["p", "quote", "list", "code", "image", "album"];

  // Флаги спана — ровно набор телеграма. href живёт отдельно: он не флаг, а
  // значение, и проверяется своей функцией.
  var SPAN_FLAGS = ["b", "i", "u", "st", "c", "sp"];

  // Разрешённые ключи каждого типа блока. Ключ вне списка — ошибка, а не
  // молчаливое отбрасывание: то же решение, что у bad image_pct на сервере
  // (см. validate_news_post в api/news_save.php).
  var BLOCK_KEYS = {
    p:     ["t", "ru", "en"],
    quote: ["t", "ru", "en", "collapsible"],
    list:  ["t", "ordered", "items"],
    code:  ["t", "ru", "en"],
    image: ["t", "url", "w", "h", "pct", "align", "wrap", "cap_ru", "cap_en"],
    album: ["t", "items", "cap_ru", "cap_en"]
  };

  // Тот же белый список формы, что NEWS_IMAGE_RE в api/news_save.php: чужой хост,
  // javascript: и обход каталога отсекаются по построению, а не перечислением.
  var IMAGE_RE = /^\/images\/[0-9a-f]{40}\.(png|jpg|webp)$/;

  var ALIGNS = ["left", "center", "right"];

  // Схема ссылки. Проверяется по началу строки без учёта регистра, потому что
  // "JavaScript:" браузер выполнит так же охотно, как "javascript:".
  function isSafeHref(v) {
    if (typeof v !== "string") { return false; }
    if (v.length > 2048) { return false; }
    return /^https?:\/\//i.test(v);
  }

  function isPlainObject(v) {
    return v !== null && typeof v === "object" && !Array.isArray(v);
  }

  function keysAllowed(obj, allowed) {
    for (var k in obj) {
      if (!Object.prototype.hasOwnProperty.call(obj, k)) { continue; }
      if (allowed.indexOf(k) === -1) { return false; }
    }
    return true;
  }

  function isPosInt(v, lo, hi) {
    return typeof v === "number" && isFinite(v) && Math.floor(v) === v && v >= lo && v <= hi;
  }

  // Спан: {s: "текст"} плюс любые флаги из SPAN_FLAGS и необязательный href.
  function validSpans(v) {
    if (!Array.isArray(v) || v.length > LIMITS.spans) { return false; }
    for (var i = 0; i < v.length; i++) {
      var sp = v[i];
      if (!isPlainObject(sp) || typeof sp.s !== "string") { return false; }
      if (!keysAllowed(sp, ["s", "href"].concat(SPAN_FLAGS))) { return false; }
      for (var f = 0; f < SPAN_FLAGS.length; f++) {
        var flag = SPAN_FLAGS[f];
        if (sp[flag] !== undefined && typeof sp[flag] !== "boolean") { return false; }
      }
      if (sp.href !== undefined && !isSafeHref(sp.href)) { return false; }
    }
    return true;
  }

  function validImageFields(b) {
    return typeof b.url === "string" && IMAGE_RE.test(b.url)
      && isPosInt(b.w, 1, 65535) && isPosInt(b.h, 1, 65535)
      && isPosInt(b.pct, 10, 100)
      && ALIGNS.indexOf(b.align) !== -1
      && typeof b.wrap === "boolean"
      && validSpans(b.cap_ru) && validSpans(b.cap_en);
  }

  function validBlock(b) {
    if (!isPlainObject(b) || BLOCK_TYPES.indexOf(b.t) === -1) { return false; }
    if (!keysAllowed(b, BLOCK_KEYS[b.t])) { return false; }

    if (b.t === "p") { return validSpans(b.ru) && validSpans(b.en); }
    if (b.t === "quote") {
      return validSpans(b.ru) && validSpans(b.en) && typeof b.collapsible === "boolean";
    }
    if (b.t === "code") {
      return typeof b.ru === "string" && typeof b.en === "string"
        && b.ru.length <= 4096 && b.en.length <= 4096;
    }
    if (b.t === "list") {
      if (typeof b.ordered !== "boolean") { return false; }
      if (!Array.isArray(b.items) || b.items.length === 0 || b.items.length > LIMITS.listItems) { return false; }
      for (var i = 0; i < b.items.length; i++) {
        var it = b.items[i];
        if (!isPlainObject(it) || !keysAllowed(it, ["ru", "en"])) { return false; }
        if (!validSpans(it.ru) || !validSpans(it.en)) { return false; }
      }
      return true;
    }
    if (b.t === "image") { return validImageFields(b); }
    if (b.t === "album") {
      if (!Array.isArray(b.items) || b.items.length < 2 || b.items.length > LIMITS.albumItems) { return false; }
      for (var j = 0; j < b.items.length; j++) {
        var im = b.items[j];
        if (!isPlainObject(im) || !keysAllowed(im, ["url", "w", "h"])) { return false; }
        if (typeof im.url !== "string" || !IMAGE_RE.test(im.url)) { return false; }
        if (!isPosInt(im.w, 1, 65535) || !isPosInt(im.h, 1, 65535)) { return false; }
      }
      return validSpans(b.cap_ru) && validSpans(b.cap_en);
    }
    return false;
  }

  // Возвращает {ok, error, blocks}, а не бросает: вызывающему (редактору и
  // тестам) нужна причина отказа строкой, а не стек.
  function validateDoc(d) {
    if (!isPlainObject(d)) { return { ok: false, error: "not an object", blocks: [] }; }
    if (!keysAllowed(d, ["v", "blocks"])) { return { ok: false, error: "unknown key", blocks: [] }; }
    if (d.v !== DOC_VERSION) { return { ok: false, error: "bad version", blocks: [] }; }
    if (!Array.isArray(d.blocks)) { return { ok: false, error: "blocks is not an array", blocks: [] }; }
    if (d.blocks.length > LIMITS.blocks) { return { ok: false, error: "too many blocks", blocks: [] }; }
    for (var i = 0; i < d.blocks.length; i++) {
      if (!validBlock(d.blocks[i])) {
        return { ok: false, error: "bad block at " + i, blocks: [] };
      }
    }
    return { ok: true, error: "", blocks: d.blocks };
  }

  // Текст спанов одного языка с откатом на второй — то же правило, что
  // pickLang() в news.js: наполовину переведённый пост показывает хоть
  // что-то, а не пустоту.
  function spansText(primary, fallback) {
    var use = (Array.isArray(primary) && primary.length) ? primary : (fallback || []);
    var out = "";
    for (var i = 0; i < use.length; i++) { out += use[i].s; }
    return out;
  }

  function blockText(b, lang) {
    var ru = lang === "en" ? "en" : "ru";
    var other = ru === "ru" ? "en" : "ru";
    if (b.t === "p" || b.t === "quote") { return spansText(b[ru], b[other]); }
    if (b.t === "code") { return String(b[ru] || b[other] || ""); }
    if (b.t === "list") {
      var lines = [];
      for (var i = 0; i < b.items.length; i++) {
        lines.push(spansText(b.items[i][ru], b.items[i][other]));
      }
      return lines.join("\n");
    }
    if (b.t === "image" || b.t === "album") {
      return spansText(b[ru === "ru" ? "cap_ru" : "cap_en"], b[ru === "ru" ? "cap_en" : "cap_ru"]);
    }
    return "";
  }

  // Плоский текст всего поста — то, что уезжает в колонки body_ru/body_en и
  // оттуда в превью ссылки (api/lib/og.php) и в noscript-тело news.php.
  function toPlainText(blocks, lang) {
    var parts = [];
    for (var i = 0; i < blocks.length; i++) {
      var t = blockText(blocks[i], lang).trim();
      if (t !== "") { parts.push(t); }
    }
    return parts.join("\n\n");
  }

  // Первая картинка поста — она уезжает в колонку image_url и становится
  // картинкой превью ссылки.
  function firstImage(blocks) {
    for (var i = 0; i < blocks.length; i++) {
      var b = blocks[i];
      if (b.t === "image") { return { url: b.url, w: b.w, h: b.h }; }
      if (b.t === "album" && b.items.length) {
        return { url: b.items[0].url, w: b.items[0].w, h: b.items[0].h };
      }
    }
    return null;
  }

  var api = {
    DOC_VERSION: DOC_VERSION,
    LIMITS: LIMITS,
    BLOCK_TYPES: BLOCK_TYPES,
    SPAN_FLAGS: SPAN_FLAGS,
    isSafeHref: isSafeHref,
    validateDoc: validateDoc,
    toPlainText: toPlainText,
    firstImage: firstImage
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NEWSBLOCKS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/news_blocks_test.mjs`
Expected: PASS, 13 subtests.

- [ ] **Step 5: Commit**

```bash
git add public_html/js/news-blocks.js tests/news_blocks_test.mjs
git commit -m "feat(news): модель блочного тела поста и её валидация"
```

---

### Task 2: Block renderer

**Files:**
- Modify: `public_html/js/news-blocks.js` (append the renderer before the `api` object)
- Test: `tests/news_blocks_test.mjs` (append)

**Interfaces:**
- Consumes: `validateDoc`, `LIMITS` from Task 1.
- Produces: `spansToFragment(doc, spans, fallback) -> DocumentFragment`, `renderBlocks(doc, blocks, lang) -> DocumentFragment`. `doc` is anything with `createElement`, `createTextNode` and `createDocumentFragment`.

- [ ] **Step 1: Write the failing test**

Append to `tests/news_blocks_test.mjs`:

```js
// Крошечная замена document: ровно те методы, которые зовёт renderBlocks.
// Настоящего DOM в node нет, а тянуть jsdom ради шести методов — лишняя
// зависимость в проекте, где их сейчас ровно одна (playwright).
function fakeDoc() {
    const mkNode = tag => {
        const el = {
            tagName: tag.toUpperCase(), children: [], attrs: {}, style: {},
            className: '', _text: '',
            append(...kids) { for (const k of kids) { el.children.push(k); } },
            setAttribute(k, v) { el.attrs[k] = String(v); },
            get textContent() {
                return el._text + el.children.map(c => c.textContent).join('');
            },
            set textContent(v) { el._text = String(v); el.children.length = 0; }
        };
        return el;
    };
    return {
        createElement: mkNode,
        createTextNode: t => ({ tagName: '#text', children: [], textContent: String(t) }),
        createDocumentFragment: () => mkNode('#fragment')
    };
}

const flat = node => {
    const out = [];
    const walk = n => { out.push(n); (n.children || []).forEach(walk); };
    walk(node);
    return out;
};
const tags = node => flat(node).map(n => n.tagName);

test('a paragraph renders as P with its text', () => {
    const d = fakeDoc();
    const frag = B.renderBlocks(d, [p('Привет')], 'ru');
    assert.ok(tags(frag).includes('P'));
    assert.equal(frag.textContent, 'Привет');
});

test('bold and italic become STRONG and EM, not raw markup', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'жирно', b: true }, { s: 'косо', i: true }], en: [] }];
    const t = tags(B.renderBlocks(d, blocks, 'ru'));
    assert.ok(t.includes('STRONG'));
    assert.ok(t.includes('EM'));
});

test('a link gets target and a hardened rel', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'тут', href: 'https://example.com/a' }], en: [] }];
    const a = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.tagName === 'A');
    assert.ok(a, 'an A element exists');
    assert.equal(a.attrs.href, 'https://example.com/a');
    assert.equal(a.attrs.rel, 'noopener noreferrer nofollow');
    assert.equal(a.attrs.target, '_blank');
});

test('an unsafe href never reaches the DOM as a link', () => {
    // Второй рубеж после validateDoc: рендер может получить блоки из старой
    // записи, сохранённой до появления какой-то проверки, и всё равно не
    // имеет права построить javascript:-ссылку.
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'тут', href: 'javascript:alert(1)' }], en: [] }];
    const frag = B.renderBlocks(d, blocks, 'ru');
    assert.equal(tags(frag).includes('A'), false, 'no anchor');
    assert.equal(frag.textContent, 'тут', 'text survives');
});

test('a spoiler is a span with the spoiler class, text intact', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'секрет', sp: true }], en: [] }];
    const sp = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.className === 'nw-spoiler');
    assert.ok(sp, 'spoiler span exists');
    assert.equal(sp.textContent, 'секрет');
});

test('an ordered list renders OL, an unordered one UL', () => {
    const d = fakeDoc();
    const mk = ordered => ({ t: 'list', ordered: ordered, items: [{ ru: [{ s: 'раз' }], en: [] }] });
    assert.ok(tags(B.renderBlocks(d, [mk(true)], 'ru')).includes('OL'));
    assert.ok(tags(B.renderBlocks(d, [mk(false)], 'ru')).includes('UL'));
});

test('an image block carries its own width, alignment and caption', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'image', url: IMG, w: 800, h: 600, pct: 50, align: 'left', wrap: true, cap_ru: [{ s: 'Подпись' }], cap_en: [] }];
    const nodes = flat(B.renderBlocks(d, blocks, 'ru'));
    const img = nodes.find(n => n.tagName === 'IMG');
    assert.equal(img.style.width, '50%');
    assert.equal(img.className, 'nw-image nw-img-float-left');
    assert.equal(img.attrs.width, '800');
    assert.equal(img.attrs.height, '600');
    assert.ok(nodes.some(n => n.className === 'nw-caption' && n.textContent === 'Подпись'));
});

test('center plus wrap falls back to a block image, as it does today', () => {
    // float не умеет «по центру» — cardFor() уже принимает это решение для
    // легаси-поста, и блок обязан вести себя так же.
    const d = fakeDoc();
    const blocks = [{ t: 'image', url: IMG, w: 8, h: 6, pct: 100, align: 'center', wrap: true, cap_ru: [], cap_en: [] }];
    const img = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.tagName === 'IMG');
    assert.equal(img.className, 'nw-image nw-img-center');
});

test('an album renders one figure with a picture per item', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'album', items: [{ url: IMG, w: 4, h: 3 }, { url: IMG, w: 4, h: 3 }], cap_ru: [{ s: 'Три кадра' }], cap_en: [] }];
    const nodes = flat(B.renderBlocks(d, blocks, 'ru'));
    assert.equal(nodes.filter(n => n.tagName === 'IMG').length, 2);
    const grid = nodes.find(n => n.className && n.className.indexOf('nw-album') === 0);
    assert.equal(grid.className, 'nw-album nw-album-2');
});

test('english text falls back to russian per block', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'Русский' }], en: [] }, { t: 'p', ru: [{ s: 'Тоже' }], en: [{ s: 'English' }] }];
    assert.equal(B.renderBlocks(d, blocks, 'en').textContent, 'РусскийEnglish');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `node --test tests/news_blocks_test.mjs`
Expected: FAIL — `B.renderBlocks is not a function`.

- [ ] **Step 3: Write the renderer**

Insert into `public_html/js/news-blocks.js`, above the `var api = {` line:

```js
  // ------------------------- Рендер -------------------------
  // doc — это document (или его заглушка в тестах): модуль обязан работать
  // и в браузере, и в node, поэтому глобального document здесь нет.
  //
  // Ни одна строка ниже не собирает разметку из текста: только createElement
  // и textContent. Это то же правило, по которому написан cardFor() в
  // news-page.js, и единственная причина, по которой тело поста не может
  // стать скриптом, что бы ни лежало в базе.

  function langKeys(lang) {
    return lang === "en" ? { self: "en", other: "ru" } : { self: "ru", other: "en" };
  }

  function pickSpans(b, keySelf, keyOther) {
    var s = b[keySelf];
    return (Array.isArray(s) && s.length) ? s : (Array.isArray(b[keyOther]) ? b[keyOther] : []);
  }

  // Обёртки флагов, от внешней к внутренней. Порядок не имеет значения для
  // вида, но он фиксирован, чтобы одинаковый спан всегда давал одинаковый DOM
  // (иначе сравнение превью с лентой в тестах было бы недетерминированным).
  var FLAG_TAGS = [
    ["b", "strong"], ["i", "em"], ["u", "u"], ["st", "s"], ["c", "code"]
  ];

  function spansToFragment(doc, spans) {
    var frag = doc.createDocumentFragment();
    for (var i = 0; i < spans.length; i++) {
      var sp = spans[i];
      var node = doc.createTextNode(sp.s);
      for (var f = 0; f < FLAG_TAGS.length; f++) {
        if (sp[FLAG_TAGS[f][0]]) {
          var w = doc.createElement(FLAG_TAGS[f][1]);
          w.append(node);
          node = w;
        }
      }
      if (sp.sp) {
        var spoiler = doc.createElement("span");
        spoiler.className = "nw-spoiler";
        // tabindex/role — спойлер открывается кликом, значит он управляющий
        // элемент и обязан открываться с клавиатуры тоже.
        spoiler.setAttribute("tabindex", "0");
        spoiler.setAttribute("role", "button");
        spoiler.append(node);
        node = spoiler;
      }
      // Второй рубеж после validateDoc: рендер получает блоки из базы, а не
      // только из редактора, и не имеет права построить javascript:-ссылку
      // даже если она каким-то путём туда попала. Небезопасный href просто
      // не даёт ссылки — текст при этом остаётся на месте.
      if (sp.href && isSafeHref(sp.href)) {
        var a = doc.createElement("a");
        a.setAttribute("href", sp.href);
        a.setAttribute("target", "_blank");
        a.setAttribute("rel", "noopener noreferrer nofollow");
        a.append(node);
        node = a;
      }
      frag.append(node);
    }
    return frag;
  }

  function captionEl(doc, b, k) {
    var spans = pickSpans(b, k.self === "ru" ? "cap_ru" : "cap_en",
                             k.self === "ru" ? "cap_en" : "cap_ru");
    if (!spans.length) { return null; }
    var cap = doc.createElement("figcaption");
    cap.className = "nw-caption";
    cap.append(spansToFragment(doc, spans));
    return cap;
  }

  function imageEl(doc, item, pct, align, wrap) {
    var img = doc.createElement("img");
    img.style.width = pct + "%";
    // center + обтекание не имеет смысла: у float нет «по центру». Ровно то
    // же решение, что уже принимает cardFor() для легаси-поста.
    var floated = wrap && align !== "center";
    img.className = "nw-image " + (floated
      ? (align === "left" ? "nw-img-float-left" : "nw-img-float-right")
      : "nw-img-" + align);
    img.setAttribute("src", item.url);
    img.setAttribute("alt", "");
    img.setAttribute("loading", "lazy");
    img.setAttribute("decoding", "async");
    // width/height — подсказка браузеру, чтобы он зарезервировал место до
    // загрузки байтов и текст под картинкой не прыгал.
    img.setAttribute("width", item.w);
    img.setAttribute("height", item.h);
    return img;
  }

  function renderBlock(doc, b, lang) {
    var k = langKeys(lang);
    if (b.t === "p") {
      var pEl = doc.createElement("p");
      pEl.append(spansToFragment(doc, pickSpans(b, k.self, k.other)));
      return pEl;
    }
    if (b.t === "quote") {
      var q = doc.createElement("blockquote");
      q.className = b.collapsible ? "nw-quote nw-quote-collapsible" : "nw-quote";
      q.append(spansToFragment(doc, pickSpans(b, k.self, k.other)));
      return q;
    }
    if (b.t === "code") {
      var pre = doc.createElement("pre");
      pre.className = "nw-code";
      var codeEl = doc.createElement("code");
      codeEl.textContent = String(b[k.self] || b[k.other] || "");
      pre.append(codeEl);
      return pre;
    }
    if (b.t === "list") {
      var list = doc.createElement(b.ordered ? "ol" : "ul");
      list.className = "nw-list";
      for (var i = 0; i < b.items.length; i++) {
        var li = doc.createElement("li");
        li.append(spansToFragment(doc, pickSpans(b.items[i], k.self, k.other)));
        list.append(li);
      }
      return list;
    }
    if (b.t === "image") {
      var fig = doc.createElement("figure");
      fig.className = "nw-figure";
      fig.append(imageEl(doc, { url: b.url, w: b.w, h: b.h }, b.pct, b.align, b.wrap));
      var cap = captionEl(doc, b, k);
      if (cap) { fig.append(cap); }
      return fig;
    }
    if (b.t === "album") {
      var af = doc.createElement("figure");
      af.className = "nw-figure";
      var grid = doc.createElement("div");
      // Класс несёт количество, а раскладку выбирает CSS: сетка на 2, 3 и
      // 4+ картинок отличается только колонками, и городить это в JS незачем.
      grid.className = "nw-album nw-album-" + Math.min(b.items.length, 4);
      for (var j = 0; j < b.items.length; j++) {
        grid.append(imageEl(doc, b.items[j], 100, "center", false));
      }
      af.append(grid);
      var acap = captionEl(doc, b, k);
      if (acap) { af.append(acap); }
      return af;
    }
    return null;
  }

  function renderBlocks(doc, blocks, lang) {
    var frag = doc.createDocumentFragment();
    for (var i = 0; i < blocks.length; i++) {
      var el = renderBlock(doc, blocks[i], lang);
      if (el) { frag.append(el); }
    }
    return frag;
  }
```

and extend the exported object:

```js
    spansToFragment: spansToFragment,
    renderBlocks: renderBlocks
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `node --test tests/news_blocks_test.mjs`
Expected: PASS, 23 subtests.

- [ ] **Step 5: Commit**

```bash
git add public_html/js/news-blocks.js tests/news_blocks_test.mjs
git commit -m "feat(news): рендер блоков в DOM без innerHTML"
```

---

### Task 3: Server-side validator and derivation

**Files:**
- Create: `public_html/api/lib/news_blocks.php`
- Test: `tests/news_test.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: `news_blocks_validate($doc): array` returning `['ok' => bool, 'error' => string, 'blocks' => array]`; `news_blocks_plain(array $blocks, string $lang): string`; `news_blocks_first_image(array $blocks): ?array` returning `['url' => string, 'w' => int, 'h' => int]`.

- [ ] **Step 1: Write the failing test**

Append to `tests/news_test.php`, and add `require __DIR__ . '/../public_html/api/lib/news_blocks.php';` to the requires at the top of that file:

```php
// ------------------------- Блочное тело поста -------------------------

function nb_img(): string { return '/images/' . str_repeat('a', 40) . '.webp'; }
function nb_p(string $ru, string $en = ''): array {
    return ['t' => 'p', 'ru' => [['s' => $ru]], 'en' => $en === '' ? [] : [['s' => $en]]];
}
function nb_doc(array $blocks): array { return ['v' => 1, 'blocks' => $blocks]; }

test('a minimal block document validates on the server too', function () {
    $r = news_blocks_validate(nb_doc([nb_p('Привет')]));
    assert_true($r['ok'], $r['error']);
    assert_eq(1, count($r['blocks']));
});

test('the server rejects what the browser model rejects', function () {
    // Список зеркалит tests/news_blocks_test.mjs: если проверка есть только
    // на одной стороне, вторая — дыра.
    assert_true(!news_blocks_validate(['v' => 2, 'blocks' => []])['ok'], 'bad version');
    assert_true(!news_blocks_validate(nb_doc([['t' => 'video']]))['ok'], 'unknown type');
    assert_true(!news_blocks_validate(nb_doc([['t' => 'p', 'ru' => [], 'en' => [], 'x' => 1]]))['ok'], 'unknown key');
    assert_true(!news_blocks_validate(nb_doc([['t' => 'p', 'ru' => [['s' => 'x', 'evil' => true]], 'en' => []]]))['ok'], 'unknown flag');
    assert_true(!news_blocks_validate(nb_doc([['t' => 'p', 'ru' => [['s' => 'x', 'href' => 'javascript:alert(1)']], 'en' => []]]))['ok'], 'bad href');
});

test('an image block outside the upload directory is rejected', function () {
    $mk = function ($url) {
        return nb_doc([['t' => 'image', 'url' => $url, 'w' => 10, 'h' => 10,
                        'pct' => 100, 'align' => 'center', 'wrap' => false,
                        'cap_ru' => [], 'cap_en' => []]]);
    };
    assert_true(news_blocks_validate($mk(nb_img()))['ok'], 'own upload passes');
    assert_true(!news_blocks_validate($mk('https://evil.example/x.png'))['ok'], 'foreign host');
    assert_true(!news_blocks_validate($mk('/images/../../etc/passwd'))['ok'], 'traversal');
});

test('server ceilings match the ones in js/news-blocks.js', function () {
    $many = array_fill(0, 201, nb_p('x'));
    assert_true(!news_blocks_validate(nb_doc($many))['ok'], '201 blocks');

    $items = array_fill(0, 11, ['url' => nb_img(), 'w' => 4, 'h' => 3]);
    assert_true(!news_blocks_validate(nb_doc([['t' => 'album', 'items' => $items,
        'cap_ru' => [], 'cap_en' => []]]))['ok'], '11 album images');
});

test('plain text derivation matches what the JS model produces', function () {
    $blocks = [
        ['t' => 'p', 'ru' => [['s' => 'Первый'], ['s' => ' абзац', 'b' => true]], 'en' => [['s' => 'First para']]],
        ['t' => 'quote', 'ru' => [['s' => 'Цитата']], 'en' => [], 'collapsible' => false],
    ];
    assert_eq("Первый абзац\n\nЦитата", news_blocks_plain($blocks, 'ru'));
    assert_eq("First para\n\nЦитата", news_blocks_plain($blocks, 'en'));
});

test('the first image is what the link preview will use', function () {
    $alb = ['t' => 'album', 'items' => [['url' => nb_img(), 'w' => 4, 'h' => 3]],
            'cap_ru' => [], 'cap_en' => []];
    assert_eq(['url' => nb_img(), 'w' => 4, 'h' => 3], news_blocks_first_image([nb_p('x'), $alb]));
    assert_eq(null, news_blocks_first_image([nb_p('x')]));
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `PHP=/c/xampp/php/php.exe $PHP tests/news_test.php`
(or `/c/xampp/php/php.exe tests/news_test.php`)
Expected: FAIL — `Failed opening required .../api/lib/news_blocks.php`.

- [ ] **Step 3: Write the validator**

Create `public_html/api/lib/news_blocks.php`:

```php
<?php
// Серверная половина модели блочного тела поста. Обязана принимать и
// отклонять РОВНО то же, что js/news-blocks.js: клиентская проверка — это
// удобство редактора, а не защита, и любое расхождение здесь становится
// дырой. Каждая константа ниже имеет двойника в том файле; правится пара.
//
// PHP 7.4: без match, без enum, без промоушена конструктора — на бою стоит
// именно эта версия (см. коммит fix/php74-compat-and-og-hardening).

const NB_DOC_VERSION  = 1;
const NB_LIMIT_BLOCKS = 200;       // LIMITS.blocks
const NB_LIMIT_ALBUM  = 10;        // LIMITS.albumItems
const NB_LIMIT_JSON   = 65536;     // LIMITS.json
const NB_LIMIT_LIST   = 100;       // LIMITS.listItems
const NB_LIMIT_SPANS  = 200;       // LIMITS.spans
const NB_LIMIT_CODE   = 4096;
const NB_LIMIT_HREF   = 2048;

const NB_BLOCK_TYPES = ['p', 'quote', 'list', 'code', 'image', 'album'];
const NB_SPAN_FLAGS  = ['b', 'i', 'u', 'st', 'c', 'sp'];
const NB_ALIGNS      = ['left', 'center', 'right'];

// Тот же белый список формы, что NEWS_IMAGE_RE в api/news_save.php.
const NB_IMAGE_RE = '#^/images/[0-9a-f]{40}\.(png|jpg|webp)$#';

const NB_BLOCK_KEYS = [
    'p'     => ['t', 'ru', 'en'],
    'quote' => ['t', 'ru', 'en', 'collapsible'],
    'list'  => ['t', 'ordered', 'items'],
    'code'  => ['t', 'ru', 'en'],
    'image' => ['t', 'url', 'w', 'h', 'pct', 'align', 'wrap', 'cap_ru', 'cap_en'],
    'album' => ['t', 'items', 'cap_ru', 'cap_en'],
];

function nb_keys_allowed(array $a, array $allowed): bool {
    foreach (array_keys($a) as $k) {
        if (!in_array($k, $allowed, true)) { return false; }
    }
    return true;
}

// Целое в границах. json_decode отдаёт числа int или float — 10.0 приходит
// float-ом и обязано пройти, а 10.5 — нет.
function nb_is_int_between($v, int $lo, int $hi): bool {
    if (is_int($v)) { return $v >= $lo && $v <= $hi; }
    if (is_float($v) && $v == (int)$v) { $n = (int)$v; return $n >= $lo && $n <= $hi; }
    return false;
}

// Схема ссылки. Регистр не важен: "JavaScript:" браузер выполнит так же,
// как "javascript:", поэтому проверка — белый список http/https, а не
// чёрный список опасного.
function nb_is_safe_href($v): bool {
    if (!is_string($v) || strlen($v) > NB_LIMIT_HREF) { return false; }
    return (bool)preg_match('#^https?://#i', $v);
}

function nb_valid_spans($v): bool {
    if (!is_array($v) || $v !== array_values($v) || count($v) > NB_LIMIT_SPANS) { return false; }
    $allowed = array_merge(['s', 'href'], NB_SPAN_FLAGS);
    foreach ($v as $sp) {
        if (!is_array($sp) || !isset($sp['s']) || !is_string($sp['s'])) { return false; }
        if (!nb_keys_allowed($sp, $allowed)) { return false; }
        foreach (NB_SPAN_FLAGS as $flag) {
            if (array_key_exists($flag, $sp) && !is_bool($sp[$flag])) { return false; }
        }
        if (array_key_exists('href', $sp) && !nb_is_safe_href($sp['href'])) { return false; }
    }
    return true;
}

function nb_valid_image_item($im): bool {
    return is_array($im)
        && nb_keys_allowed($im, ['url', 'w', 'h'])
        && isset($im['url']) && is_string($im['url']) && preg_match(NB_IMAGE_RE, $im['url'])
        && nb_is_int_between($im['w'] ?? null, 1, 65535)
        && nb_is_int_between($im['h'] ?? null, 1, 65535);
}

function nb_valid_block($b): bool {
    if (!is_array($b) || !isset($b['t']) || !in_array($b['t'], NB_BLOCK_TYPES, true)) { return false; }
    if (!nb_keys_allowed($b, NB_BLOCK_KEYS[$b['t']])) { return false; }

    if ($b['t'] === 'p') {
        return nb_valid_spans($b['ru'] ?? null) && nb_valid_spans($b['en'] ?? null);
    }
    if ($b['t'] === 'quote') {
        return nb_valid_spans($b['ru'] ?? null) && nb_valid_spans($b['en'] ?? null)
            && array_key_exists('collapsible', $b) && is_bool($b['collapsible']);
    }
    if ($b['t'] === 'code') {
        return isset($b['ru'], $b['en']) && is_string($b['ru']) && is_string($b['en'])
            && strlen($b['ru']) <= NB_LIMIT_CODE && strlen($b['en']) <= NB_LIMIT_CODE;
    }
    if ($b['t'] === 'list') {
        if (!array_key_exists('ordered', $b) || !is_bool($b['ordered'])) { return false; }
        $items = $b['items'] ?? null;
        if (!is_array($items) || $items !== array_values($items)) { return false; }
        if (count($items) === 0 || count($items) > NB_LIMIT_LIST) { return false; }
        foreach ($items as $it) {
            if (!is_array($it) || !nb_keys_allowed($it, ['ru', 'en'])) { return false; }
            if (!nb_valid_spans($it['ru'] ?? null) || !nb_valid_spans($it['en'] ?? null)) { return false; }
        }
        return true;
    }
    if ($b['t'] === 'image') {
        return nb_valid_image_item(['url' => $b['url'] ?? null, 'w' => $b['w'] ?? null, 'h' => $b['h'] ?? null])
            && nb_is_int_between($b['pct'] ?? null, 10, 100)
            && isset($b['align']) && in_array($b['align'], NB_ALIGNS, true)
            && array_key_exists('wrap', $b) && is_bool($b['wrap'])
            && nb_valid_spans($b['cap_ru'] ?? null) && nb_valid_spans($b['cap_en'] ?? null);
    }
    // album
    $items = $b['items'] ?? null;
    if (!is_array($items) || $items !== array_values($items)) { return false; }
    if (count($items) < 2 || count($items) > NB_LIMIT_ALBUM) { return false; }
    foreach ($items as $im) {
        if (!nb_valid_image_item($im)) { return false; }
    }
    return nb_valid_spans($b['cap_ru'] ?? null) && nb_valid_spans($b['cap_en'] ?? null);
}

// Возвращает ['ok','error','blocks'] — той же формы, что validate_news_post()
// в api/news_save.php, чтобы вызывающий обрабатывал обе одинаково.
function news_blocks_validate($doc): array {
    if (!is_array($doc) || !nb_keys_allowed($doc, ['v', 'blocks'])) {
        return ['ok' => false, 'error' => 'bad body_json', 'blocks' => []];
    }
    if (($doc['v'] ?? null) !== NB_DOC_VERSION) {
        return ['ok' => false, 'error' => 'bad body_json version', 'blocks' => []];
    }
    $blocks = $doc['blocks'] ?? null;
    if (!is_array($blocks) || $blocks !== array_values($blocks)) {
        return ['ok' => false, 'error' => 'bad blocks', 'blocks' => []];
    }
    if (count($blocks) > NB_LIMIT_BLOCKS) {
        return ['ok' => false, 'error' => 'too many blocks', 'blocks' => []];
    }
    foreach ($blocks as $i => $b) {
        if (!nb_valid_block($b)) {
            return ['ok' => false, 'error' => 'bad block at ' . $i, 'blocks' => []];
        }
    }
    return ['ok' => true, 'error' => '', 'blocks' => $blocks];
}

// Текст спанов с откатом на второй язык — то же правило, что pickLang() в
// js/news.js и descFor() в js/content.js: наполовину переведённый пост
// показывает хоть что-то.
function nb_spans_text($primary, $fallback): string {
    $use = (is_array($primary) && count($primary)) ? $primary : (is_array($fallback) ? $fallback : []);
    $out = '';
    foreach ($use as $sp) { $out .= $sp['s']; }
    return $out;
}

function nb_block_text(array $b, string $lang): string {
    $self  = $lang === 'en' ? 'en' : 'ru';
    $other = $self === 'ru' ? 'en' : 'ru';
    if ($b['t'] === 'p' || $b['t'] === 'quote') {
        return nb_spans_text($b[$self], $b[$other]);
    }
    if ($b['t'] === 'code') {
        return $b[$self] !== '' ? $b[$self] : $b[$other];
    }
    if ($b['t'] === 'list') {
        $lines = [];
        foreach ($b['items'] as $it) { $lines[] = nb_spans_text($it[$self], $it[$other]); }
        return implode("\n", $lines);
    }
    $capSelf  = $self === 'ru' ? 'cap_ru' : 'cap_en';
    $capOther = $self === 'ru' ? 'cap_en' : 'cap_ru';
    return nb_spans_text($b[$capSelf], $b[$capOther]);
}

// Плоский текст всего поста — он уезжает в body_ru/body_en и оттуда в
// превью ссылки (api/lib/og.php) и в noscript-тело news.php.
function news_blocks_plain(array $blocks, string $lang): string {
    $parts = [];
    foreach ($blocks as $b) {
        $t = trim(nb_block_text($b, $lang));
        if ($t !== '') { $parts[] = $t; }
    }
    return implode("\n\n", $parts);
}

function news_blocks_first_image(array $blocks): ?array {
    foreach ($blocks as $b) {
        if ($b['t'] === 'image') {
            return ['url' => $b['url'], 'w' => (int)$b['w'], 'h' => (int)$b['h']];
        }
        if ($b['t'] === 'album' && count($b['items'])) {
            $im = $b['items'][0];
            return ['url' => $im['url'], 'w' => (int)$im['w'], 'h' => (int)$im['h']];
        }
    }
    return null;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe tests/news_test.php`
Expected: PASS, `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/news_blocks.php tests/news_test.php
git commit -m "feat(news): серверная валидация блоков и вывод плоского текста"
```

---

### Task 4: Schema, migration, and the save endpoint

**Files:**
- Modify: `schema.sql`, `tests/lib.php`, `public_html/api/news_save.php`
- Create: `docs/migrations/2026-08-29-news-blocks.sql`
- Test: `tests/news_test.php` (append)

**Interfaces:**
- Consumes: `news_blocks_validate`, `news_blocks_plain`, `news_blocks_first_image` from Task 3.
- Produces: `handle_news_save()` accepting an optional `body_json` key in the request body.

- [ ] **Step 1: Write the failing test**

Append to `tests/news_test.php`:

```php
test('saving blocks derives body_ru, body_en and the preview image', function () {
    $pdo = test_db();
    $blocks = [
        ['t' => 'p', 'ru' => [['s' => 'Тело поста']], 'en' => [['s' => 'Post body']]],
        ['t' => 'image', 'url' => nb_img(), 'w' => 800, 'h' => 600, 'pct' => 100,
         'align' => 'center', 'wrap' => false, 'cap_ru' => [['s' => 'Подпись']], 'cap_en' => []],
    ];
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game',
        'title_ru' => 'Заголовок',
        // Клиент СПЕЦИАЛЬНО присылает мусор в производных полях: сервер
        // обязан их проигнорировать и посчитать сам, иначе фронт мог бы
        // врать краулеру о содержимом поста.
        'body_ru'  => 'ВРАНЬЁ',
        'image_url' => '',
        'body_json' => ['v' => 1, 'blocks' => $blocks],
    ], 1000);
    assert_eq(200, $status);

    $row = $pdo->query("SELECT * FROM news WHERE id = " . (int)$p['id'])->fetch(PDO::FETCH_ASSOC);
    assert_eq("Тело поста\n\nПодпись", $row['body_ru'], 'derived russian body');
    assert_eq("Post body\n\nПодпись", $row['body_en'], 'derived english body');
    assert_eq(nb_img(), $row['image_url'], 'derived preview image');
    assert_eq(800, (int)$row['image_width']);
    assert_eq(600, (int)$row['image_height']);
    assert_true($row['body_json'] !== null && $row['body_json'] !== '', 'body_json stored');
});

test('a block post needs no body_ru of its own', function () {
    // Без блоков body_ru обязателен (и остаётся обязательным для легаси-формы).
    // С блоками он выводится, поэтому его отсутствие в запросе — норма.
    $pdo = test_db();
    [$status] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T',
        'body_json' => ['v' => 1, 'blocks' => [nb_p('Текст')]],
    ], 1000);
    assert_eq(200, $status);
});

test('a block document that says nothing is refused', function () {
    // Пустой список блоков и пост из одной картинки без единой буквы дают
    // пустой body_ru — а он NOT NULL и, главное, попадает в превью ссылки.
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T',
        'body_json' => ['v' => 1, 'blocks' => []],
    ], 1000);
    assert_eq(400, $status, 'empty blocks rejected');
    assert_eq('body_ru is required', $p['error']);
});

test('bad blocks are a 400, not a half-saved post', function () {
    $pdo = test_db();
    [$status] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T',
        'body_json' => ['v' => 1, 'blocks' => [['t' => 'p', 'ru' => [['s' => 'x', 'href' => 'javascript:1']], 'en' => []]]],
    ], 1000);
    assert_eq(400, $status);
    assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM news")->fetchColumn(), 'nothing written');
});

test('a body_json over the byte ceiling is refused', function () {
    $pdo = test_db();
    $big = str_repeat('я', 40000); // utf8: 80000 байт, выше NB_LIMIT_JSON
    [$status] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T',
        'body_json' => ['v' => 1, 'blocks' => [nb_p($big)]],
    ], 1000);
    assert_eq(400, $status);
});

test('a legacy post still saves with no body_json at all', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T', 'body_ru' => 'Текст',
    ], 1000);
    assert_eq(200, $status);
    $row = $pdo->query("SELECT body_json, body_ru FROM news WHERE id = " . (int)$p['id'])->fetch(PDO::FETCH_ASSOC);
    assert_eq(null, $row['body_json'], 'stays NULL, renders the old way');
    assert_eq('Текст', $row['body_ru']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/xampp/php/php.exe tests/news_test.php`
Expected: FAIL — `no such column: body_json` from the first new test.

- [ ] **Step 3: Add the column in all three schema copies**

In `schema.sql`, inside `CREATE TABLE news`, after the `image_height` column:

```sql
  -- Структурированное тело поста: {"v":1,"blocks":[...]} — см.
  -- docs/superpowers/specs/2026-08-29-news-block-editor-design.md.
  -- NULL, а не '': пост, сохранённый до появления колонки, и пост, у
  -- которого блоков нет, — это одно и то же состояние «рисуй по-старому»,
  -- и cardFor() в news-page.js обрабатывает их одинаково. Колонки
  -- body_ru/body_en/image_url при этом продолжают заполняться (сервер
  -- выводит их из блоков), поэтому og.php и SSR-мета работают без правок.
  body_json    LONGTEXT NULL,
```

Create `docs/migrations/2026-08-29-news-blocks.sql`:

```sql
-- Блочное тело поста. Запускать на боевой базе руками: schema.sql на сервер
-- не уезжает (.cpanel.yml копирует только public_html/).
--
-- Колонку можно добавлять на живой базе: она NULL, старые строки её не
-- заметят, и до первой правки поста в новом редакторе ни одна запись её не
-- заполнит. Пока миграция не выполнена, api/news.php отдаёт ленту без
-- body_json (см. обработку 1054 unknown column) — лента не падает.
ALTER TABLE news ADD COLUMN body_json LONGTEXT NULL;
```

In `tests/lib.php`, inside the `CREATE TABLE news` of `test_db()`, after `image_height`:

```sql
        -- Зеркалит schema.sql. LONGTEXT в SQLite — просто TEXT.
        body_json TEXT NULL,
```

- [ ] **Step 4: Wire the save endpoint**

In `public_html/api/news_save.php`, add after the existing `require_once`:

```php
require_once __DIR__ . '/lib/news_blocks.php';
```

Replace the body of `validate_news_post()`'s title/body section so that a block post does not require `body_ru` from the client. Concretely, change the two lines

```php
    if ($titleRu === '') { return ['ok' => false, 'error' => 'title_ru is required', 'post' => []]; }
    if ($bodyRu === '')  { return ['ok' => false, 'error' => 'body_ru is required',  'post' => []]; }
```

into

```php
    if ($titleRu === '') { return ['ok' => false, 'error' => 'title_ru is required', 'post' => []]; }
    // Для блочного поста body_ru приходит не из запроса, а выводится из
    // блоков в handle_news_save() ниже — там же он и проверяется на пустоту.
    // Здесь требование остаётся только для легаси-формы.
    if ($bodyRu === '' && !array_key_exists('body_json', $b)) {
        return ['ok' => false, 'error' => 'body_ru is required', 'post' => []];
    }
```

Then, in `handle_news_save()`, immediately after `$p = $v['post'];`, insert:

```php
    // Блочное тело. Отсутствие ключа — это легаси-пост (и все нынешние
    // записи), а не ошибка: тогда $bodyJson остаётся null и колонка
    // body_json не заполняется.
    $bodyJson = null;
    if (array_key_exists('body_json', $body) && $body['body_json'] !== null) {
        $vb = news_blocks_validate($body['body_json']);
        if (!$vb['ok']) { return [400, ['ok' => false, 'error' => $vb['error']]]; }

        // Кодируем СРАЗУ и меряем байты того, что реально ляжет в колонку:
        // мерить исходную строку запроса нельзя — она может отличаться
        // пробелами, а мерить символы вместо байтов значит промахнуться в
        // два раза на кириллице.
        $encoded = json_encode($vb['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > NB_LIMIT_JSON) {
            return [400, ['ok' => false, 'error' => 'body_json too large']];
        }
        $bodyJson = '{"v":' . NB_DOC_VERSION . ',"blocks":' . $encoded . '}';

        // Производные поля считает СЕРВЕР, а не клиент: это единственное,
        // что не даёт превью ссылки и тексту поста разойтись. Что бы фронт
        // ни прислал в body_ru/image_url — оно здесь перетирается.
        $p['body_ru'] = news_blocks_plain($vb['blocks'], 'ru');
        $p['body_en'] = news_blocks_plain($vb['blocks'], 'en');
        if ($p['body_ru'] === '') {
            return [400, ['ok' => false, 'error' => 'body_ru is required']];
        }
        if (mb_strlen($p['body_ru']) > NEWS_BODY_MAX || mb_strlen($p['body_en']) > NEWS_BODY_MAX) {
            return [400, ['ok' => false, 'error' => 'body too long']];
        }

        $img = news_blocks_first_image($vb['blocks']);
        $p['image_url']    = $img ? $img['url'] : '';
        $p['image_width']  = $img ? $img['w']   : null;
        $p['image_height'] = $img ? $img['h']   : null;
        // Геометрия живёт на самом блоке; строковые колонки остаются на
        // дефолтах и для блочного поста ничего не значат — единственный их
        // потребитель (api/lib/og.php) читает только image_url.
    }
```

Add `':bj' => $bodyJson,` to the `$params` array, and add the column to both statements:

```php
        $stmt = $pdo->prepare(
            "UPDATE news
                SET category = :c, title_ru = :tr, title_en = :te,
                    body_ru = :br, body_en = :be, image_url = :img,
                    image_pct = :pct, image_align = :al, image_wrap = :wr,
                    image_width = :iw, image_height = :ih,
                    body_json = :bj, published_at = :pa
              WHERE id = :id"
        );
```

```php
    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, image_pct,
                            image_align, image_wrap, image_width, image_height, body_json, published_at)
         VALUES (:c, :tr, :te, :br, :be, :img, :pct, :al, :wr, :iw, :ih, :bj, :pa)"
    );
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe tests/news_test.php`
Expected: PASS, `0 failures`.

- [ ] **Step 6: Commit**

```bash
git add schema.sql tests/lib.php tests/news_test.php public_html/api/news_save.php docs/migrations/2026-08-29-news-blocks.sql
git commit -m "feat(news): сохранение блоков и вывод старых колонок из них"
```

---

### Task 5: Feed read and the migration window

**Files:**
- Modify: `public_html/api/news.php`
- Test: `tests/news_test.php` (append)

**Interfaces:**
- Consumes: the `body_json` column from Task 4.
- Produces: each post in `GET /api/news.php` gains `body_json` — a decoded array `{v, blocks}` or `null`.

- [ ] **Step 1: Write the failing test**

Append to `tests/news_test.php`:

```php
test('the feed hands blocks to the client already decoded', function () {
    $pdo = test_db();
    handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'T',
        'body_json' => ['v' => 1, 'blocks' => [nb_p('Текст')]],
    ], 1000);
    [$status, $p] = handle_news($pdo);
    assert_eq(200, $status);
    // Массив, а не строка: разбирать JSON дважды (здесь и в браузере) незачем,
    // а строка заставила бы фронт делать свой JSON.parse и свой try/catch.
    assert_true(is_array($p['posts'][0]['body_json']), 'decoded');
    assert_eq('p', $p['posts'][0]['body_json']['blocks'][0]['t']);
});

test('a legacy post reports body_json as null, not as an empty array', function () {
    $pdo = test_db();
    seed_post($pdo, 'game', 'Старый', 1000);
    [, $p] = handle_news($pdo);
    assert_eq(null, $p['posts'][0]['body_json']);
});

// Таблица из ДО миграции docs/migrations/2026-08-29-news-blocks.sql: колонки
// body_json ещё нет. Это окно между выкладкой кода (пушем) и миграцией
// (руками) — и в отличие от рассинхрона в тесте выше, ЭТОТ конкретный
// рассинхрон обязан деградировать, а не валить ленту: /news объявлен в
// sitemap.xml, и краулер не должен получить 503 из-за неисполненного ALTER.
function news_db_without_body_json(): PDO {
    $pdo = test_db();
    $pdo->exec("ALTER TABLE news DROP COLUMN body_json");
    return $pdo;
}

test('a missing body_json column degrades to the legacy path instead of killing the feed', function () {
    $pdo = news_db_without_body_json();
    seed_post($pdo, 'game', 'Старый', 1000);
    [$status, $p] = handle_news($pdo);
    assert_eq(200, $status, 'feed still serves');
    assert_eq(1, count($p['posts']));
    assert_eq('Старый', $p['posts'][0]['title_ru']);
    assert_eq(null, $p['posts'][0]['body_json']);
});

test('any OTHER missing column is still a hard failure', function () {
    // Деградация касается ровно body_json. Пропавший image_pct — это другой,
    // невыясненный рассинхрон, и глотать его молча значит прятать поломку.
    $pdo = test_db();
    $pdo->exec("ALTER TABLE news DROP COLUMN image_pct");
    seed_post($pdo, 'game', 'Старый', 1000);
    assert_throws(function () use ($pdo) { handle_news($pdo); });
});
```

Note: SQLite supports `ALTER TABLE … DROP COLUMN` from 3.35 (2021). If the bundled SQLite is older, the test fails on the `ALTER`; in that case build the table by hand the way `old_schema_news_db()` already does in this file.

- [ ] **Step 2: Run the test to verify it fails**

Run: `/c/xampp/php/php.exe tests/news_test.php`
Expected: FAIL — the first new test reports `Undefined array key "body_json"`.

- [ ] **Step 3: Implement the read path**

In `public_html/api/news.php`, add above `handle_news()`:

```php
// Отличает «нет колонки body_json» от любого другого рассинхрона схемы.
// Именно этот случай — окно между выкладкой кода пушем и миграцией
// docs/migrations/2026-08-29-news-blocks.sql, которая запускается руками, —
// обязан деградировать до легаси-пути, а не ронять ленту: /news объявлен в
// sitemap.xml. Любая другая пропавшая колонка остаётся исключением, как и
// была (см. news_table_missing() выше — тот же приём, другая причина).
function news_body_json_missing(PDOException $e): bool {
    $msg = $e->getMessage();
    if (($e->errorInfo[1] ?? null) === 1054 && strpos($msg, 'body_json') !== false) {
        return true; // MySQL: ER_BAD_FIELD_ERROR
    }
    return strpos($msg, 'no such column: body_json') !== false; // SQLite
}
```

Rewrite `handle_news()`'s query section:

```php
function handle_news(PDO $pdo): array {
    // LIMIT подставляется из константы, а не из запроса пользователя.
    $cols = "id, category, title_ru, title_en, body_ru, body_en, image_url, image_pct,
             image_align, image_wrap, image_width, image_height, published_at, likes";
    $tail = " FROM news ORDER BY published_at DESC, id DESC LIMIT " . NEWS_FEED_LIMIT;

    $hasBlocks = true;
    try {
        $rows = $pdo->query("SELECT " . $cols . ", body_json" . $tail)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (news_table_missing($e)) {
            return [200, ['posts' => []]];
        }
        if (!news_body_json_missing($e)) {
            throw $e;
        }
        // Миграция ещё не запускалась: блоков нет ни у одного поста, и лента
        // целиком идёт легаси-путём — ровно то, что она и делала вчера.
        $hasBlocks = false;
        $rows = $pdo->query("SELECT " . $cols . $tail)->fetchAll(PDO::FETCH_ASSOC);
    }

    $posts = [];
    foreach ($rows as $r) {
        // Разбирается на сервере, а не в браузере: иначе каждый клиент
        // повторял бы JSON.parse со своим try/catch, а битая строка в базе
        // ломала бы страницу вместо одного поста. Битый JSON здесь — это
        // просто «блоков нет», то есть легаси-путь.
        $blocks = null;
        if ($hasBlocks && !empty($r['body_json'])) {
            $decoded = json_decode($r['body_json'], true);
            if (is_array($decoded)) { $blocks = $decoded; }
        }
        $posts[] = [
            // …существующие поля без изменений…
            'body_json'    => $blocks,
        ];
    }
    return [200, ['posts' => $posts]];
}
```

Keep every existing field in the `$posts[]` literal exactly as it is; only add the `body_json` line.

- [ ] **Step 4: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe tests/news_test.php`
Expected: PASS, `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/news.php tests/news_test.php
git commit -m "feat(news): лента отдаёт блоки и переживает окно до миграции"
```

---

### Task 6: Render blocks in the feed

**Files:**
- Modify: `public_html/js/news-page.js:236-241` (the `nw-body` loop in `cardFor`), `public_html/news.php`, `public_html/css/news-design.css`

**Interfaces:**
- Consumes: `NEWSBLOCKS.renderBlocks`, `NEWSBLOCKS.validateDoc` from Tasks 1-2; `post.body_json` from Task 5.
- Produces: nothing new for later tasks.

- [ ] **Step 1: Render blocks when the post has them**

In `public_html/js/news-page.js`, replace the body-building section of `cardFor()`:

```js
    const body = document.createElement("div");
    body.className = "nw-body";
    for (const para of NEWS.toParagraphs(picked.body)) {
      const p = document.createElement("p");
      p.textContent = para;
      body.append(p);
    }
    card.append(body);
```

with:

```js
    const body = document.createElement("div");
    body.className = "nw-body";
    // Блочный пост рисуется блоками, легаси-пост — абзацами. Проверка идёт
    // через validateDoc, а не по одному лишь наличию body_json: строка в
    // базе могла пережить формат, а рисовать полуразобранный документ —
    // худший из вариантов. Не прошло проверку — падаем на плоский текст,
    // который сервер всё равно вывел в body_ru (см. handle_news_save).
    const blockDoc = post.body_json ? NEWSBLOCKS.validateDoc(post.body_json) : null;
    if (blockDoc && blockDoc.ok) {
      body.append(NEWSBLOCKS.renderBlocks(document, blockDoc.blocks, lang));
    } else {
      for (const para of NEWS.toParagraphs(picked.body)) {
        const p = document.createElement("p");
        p.textContent = para;
        body.append(p);
      }
    }
    card.append(body);
```

Also, a block post already prints its pictures inside the body, so the single top image must not be printed twice. Guard the existing `if (post.image_url) {` in `cardFor()`:

```js
    // У блочного поста картинки живут в теле, а image_url — производная
    // колонка для превью ссылки (см. handle_news_save). Рисовать её сверху
    // значило бы показать первую картинку дважды.
    if (post.image_url && !(blockDoc && blockDoc.ok)) {
```

This requires moving the `blockDoc` declaration above the image block — put it right after `const picked = NEWS.pickLang(post, lang);`.

- [ ] **Step 2: Add the spoiler click handler**

At the end of `cardFor()`, before `return card;`:

```js
    // Спойлер открывается по клику и с клавиатуры. Обработчик вешается на
    // карточку, а не на каждый спан: спанов в длинном посте десятки, а
    // поведение у них одно.
    card.addEventListener("click", ev => {
      const sp = ev.target.closest(".nw-spoiler");
      if (sp) { sp.classList.add("is-open"); }
    });
    card.addEventListener("keydown", ev => {
      if (ev.key !== "Enter" && ev.key !== " ") { return; }
      const sp = ev.target.closest(".nw-spoiler");
      if (sp) { ev.preventDefault(); sp.classList.add("is-open"); }
    });
```

- [ ] **Step 3: Load the module**

In `public_html/news.php`, after the `js/news.js` tag:

```html
  <script src="js/news-blocks.js?v=1"></script>
```

In `public_html/admin-news.php`, add the same tag (path `/js/news-blocks.js?v=1`) next to the other module tags.

- [ ] **Step 4: Style the new block types**

Append to `public_html/css/news-design.css`:

```css
/* ===================== Блочное тело поста ===================== */
/* Типы блоков описаны в
   docs/superpowers/specs/2026-08-29-news-block-editor-design.md.
   Абзац (.nw-body p) уже стилизован выше — здесь только то, чего у
   плоского тела не было. */

.nw-card .nw-body a {
  color: var(--nw-accent-a);
  text-underline-offset: 2px;
}

.nw-card .nw-body code {
  font-family: ui-monospace, "SFMono-Regular", Consolas, monospace;
  font-size: 0.92em;
  padding: 1px calc(4 * var(--u));
  border-radius: calc(4 * var(--u));
  background: rgba(255, 255, 255, 0.1);
}

.nw-card .nw-code {
  margin: 0 0 calc(10 * var(--u));
  padding: calc(10 * var(--u)) calc(12 * var(--u));
  border-radius: calc(8 * var(--u));
  background: rgba(0, 0, 0, 0.35);
  overflow-x: auto;
}

.nw-card .nw-quote {
  margin: 0 0 calc(10 * var(--u));
  padding: calc(2 * var(--u)) 0 calc(2 * var(--u)) calc(12 * var(--u));
  border-left: calc(3 * var(--u)) solid var(--nw-accent-a);
  /* Односторонняя граница со скруглением выглядит сломанной. */
  border-radius: 0;
  color: rgba(255, 255, 255, 0.86);
}

/* Раскрывающаяся цитата: сложена до 3 строк, полностью открывается кликом
   (класс is-open вешает тот же обработчик карточки, что и у спойлера). */
.nw-card .nw-quote-collapsible {
  max-height: calc(66 * var(--u));
  overflow: hidden;
}
.nw-card .nw-quote-collapsible.is-open { max-height: none; }

.nw-card .nw-list {
  margin: 0 0 calc(10 * var(--u));
  padding-left: calc(20 * var(--u));
}
.nw-card .nw-list li { margin-bottom: calc(4 * var(--u)); }

.nw-card .nw-figure { margin: 0 0 calc(10 * var(--u)); }

.nw-card .nw-caption {
  margin-top: calc(4 * var(--u));
  font-size: calc(13 * var(--u));
  color: rgba(255, 255, 255, 0.62);
}

/* Спойлер: текст спрятан цветом, а не display — иначе он не занимал бы
   место и абзац прыгал бы при открытии. user-select запрещает вытащить
   его выделением, как это делает protect.js со всей страницей. */
.nw-card .nw-spoiler {
  border-radius: calc(4 * var(--u));
  background: rgba(255, 255, 255, 0.22);
  color: transparent;
  cursor: pointer;
  user-select: none;
}
.nw-card .nw-spoiler.is-open {
  background: none;
  color: inherit;
  cursor: auto;
  user-select: auto;
}

/* Альбом. Класс несёт количество картинок, раскладку выбирает CSS. */
.nw-card .nw-album {
  display: grid;
  gap: calc(4 * var(--u));
}
.nw-card .nw-album-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.nw-card .nw-album-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.nw-card .nw-album-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.nw-card .nw-album img {
  width: 100%;
  height: 100%;
  aspect-ratio: 4 / 3;
  object-fit: cover;
  border-radius: calc(8 * var(--u));
}

@media (max-width: 700px) {
  .nw-card .nw-album-3,
  .nw-card .nw-album-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
```

- [ ] **Step 5: Verify in the browser**

Insert one block post into the local database. Write the payload to the scratchpad first (it is throwaway, not a project file):

```json
{
  "category": "game",
  "title_ru": "Проверка блоков",
  "body_json": {
    "v": 1,
    "blocks": [
      {"t": "p", "ru": [{"s": "Обычный текст и "}, {"s": "жирный", "b": true}, {"s": ", и "}, {"s": "ссылка", "href": "https://example.com/"}, {"s": ", и "}, {"s": "секрет", "sp": true}, {"s": "."}], "en": []},
      {"t": "quote", "ru": [{"s": "Цитата из бюллетеня."}], "en": [], "collapsible": false},
      {"t": "list", "ordered": false, "items": [{"ru": [{"s": "Первый пункт"}], "en": []}, {"ru": [{"s": "Второй пункт"}], "en": []}]},
      {"t": "code", "ru": "console.log(1)", "en": ""}
    ]
  }
}
```

Then, from the repo root:

```bash
/c/xampp/php/php.exe -r "define('TESTING',1); require 'public_html/api/_bootstrap.php'; require 'public_html/api/lib/news_blocks.php'; require 'public_html/api/news_save.php'; var_dump(handle_news_save(db(), json_decode(file_get_contents(getenv('SCRATCH').'/post.json'), true), (int)(microtime(true)*1000)));"
```

Expected: `int(200)` and an `id`.

Use the preview tools: `preview_start` with the local server, navigate to `/news`, then `read_page` to confirm the card contains `STRONG`, `BLOCKQUOTE`, `UL`, `PRE` and the spoiler span, and no literal `[object Object]`. Then `read_console_messages` — no errors.

- [ ] **Step 6: Commit**

```bash
git add public_html/js/news-page.js public_html/news.php public_html/admin-news.php public_html/css/news-design.css
git commit -m "feat(news): лента рисует блочные посты"
```

---

### Task 7: Move the editor into its own file

**Files:**
- Create: `public_html/js/news-editor.js`
- Modify: `public_html/js/news-page.js`, `public_html/news.php`, `public_html/admin-news.php`

This task changes **no behaviour**. It exists so the block editor is written in a file that readers never download.

**Interfaces:**
- Consumes: `cardFor(post, withTools)` and the feed's `load()`/`render()`.
- Produces: `window.NEWSPAGE` — `{ cardFor, reload, getLang, isAdmin }` — the seam the editor uses to draw its preview and refresh the feed after a save.

- [ ] **Step 1: Expose the seam from news-page.js**

At the end of the IIFE in `public_html/js/news-page.js`:

```js
  // Шов для редактора (js/news-editor.js, грузится только на /admin/news).
  // Отдаётся ровно четыре вещи, а не весь модуль: редактору нужно нарисовать
  // превью карточки тем же кодом, что рисует ленту, и перезагрузить ленту
  // после сохранения — больше ничего.
  window.NEWSPAGE = {
    cardFor: cardFor,
    reload: load,
    getLang: () => lang,
    isAdmin: () => isAdmin
  };
```

- [ ] **Step 2: Cut the editor out**

Move these functions verbatim from `js/news-page.js` into a new `public_html/js/news-editor.js`, wrapped in the same `(() => { "use strict"; … })()` shape: everything from the `// ---------- Кроп-редактор картинки ----------` banner (`js/news-page.js:470`) through `showCopyFeedback` — that is, `cropEffectiveZoom`, `renderCatSeg`, `setCat`, `getCat`, `renderAlignSeg`, `setAlign`, `getAlign`, `updatePctOutput`, `setPct`, `getPct`, `updatePctHint`, `setWrap`, `getWrap`, `isoDay`, `dayToMs`, `setImage`, `readFileAsDataURL`, `decodeImageForCrop`, `setupCropCanvasSize`, `clampPan`, `clampFrameToViewport`, `drawCrop`, `positionCropFrameEl`, `updateCropDimsOutput`, `updateCropUI`, `applyCropZoom`, `initCropState`, `closeCropUI`, `confirmCrop`, `skipCrop`, `cropPointerMove`, `cropPointerUp`, `resizeFrameFromAnchor`, `buildPreviewPost`, `updatePreview`, `closeEditor`, `openEditor`, `publish`, `removePost`, plus their module-level `let` state and their DOM event wiring.

Leave `copyPostLink`, `copyViaFallback` and `showCopyFeedback` in `news-page.js` — the copy button is public, not admin-only.

In the moved code, replace every direct call to `cardFor(...)` with `window.NEWSPAGE.cardFor(...)`, every `load()` with `window.NEWSPAGE.reload()`, and every read of `lang` with `window.NEWSPAGE.getLang()`.

- [ ] **Step 3: Load the right file on each page**

In `public_html/news.php`, the `news-page.js` tag stays and no editor tag is added.

In `public_html/admin-news.php`, after the `news-page.js` tag:

```html
  <script src="/js/news-editor.js?v=1"></script>
```

- [ ] **Step 4: Verify both pages still work**

Run the suite (`bash tests/run_all.sh`), then with the preview tools open `/news` and `/admin/news`. On `/news`: `read_console_messages` must be clean and the card must have no ✎/✕ tools. On `/admin/news`: open the editor on an existing post, change the title, save, and confirm the feed updates.

- [ ] **Step 5: Commit**

```bash
git add public_html/js/news-editor.js public_html/js/news-page.js public_html/news.php public_html/admin-news.php
git commit -m "refactor(news): редактор в свой файл, читателям он больше не уезжает"
```

---

### Task 8: Block editor — the block list and text blocks

**Files:**
- Modify: `public_html/admin-news.php`, `public_html/js/news-editor.js`, `public_html/css/admin-shell.css`, `public_html/js/i18n.js`

**Interfaces:**
- Consumes: `NEWSBLOCKS.validateDoc`, `NEWSBLOCKS.renderBlocks`, `window.NEWSPAGE`.
- Produces, inside `news-editor.js`: `editorBlocks` (the array being edited), `renderBlockList()`, `blockToSpans(el) -> Array`, `spansToEditable(doc, spans) -> DocumentFragment`, `insertBlock(index, type)`, `removeBlock(index)`, `moveBlock(from, to)`, `currentDoc() -> {v, blocks}`.

- [ ] **Step 1: Replace the two body textareas with the block list**

In `public_html/admin-news.php`, delete the `#neBodyRu` and `#neBodyEn` fields and the whole legacy image group (`#neImagePick`, `#neImageClear`, `#nePct`, `#neAlign`, `#neWrap` and their labels) — image settings now live on each image block. Keep `#neTitleRu`, `#neTitleEn`, `#neCat`, `#neDate`, the crop dialog markup (`#neCrop` and everything inside it), `#nePreviewCard`, `#neError`, and the footer buttons. Insert in their place:

```html
        <div class="ne-field">
          <div class="ne-lang-seg" id="neLang" role="group" aria-label="Язык текста">
            <button type="button" data-v="ru" class="active">RU</button>
            <button type="button" data-v="en">EN</button>
          </div>
        </div>

        <div class="ne-blocks" id="neBlocks"></div>

        <div class="ne-add" id="neAddRow">
          <button class="btn small ghost" type="button" data-add="p" data-i18n="news.blockP">Абзац</button>
          <button class="btn small ghost" type="button" data-add="quote" data-i18n="news.blockQuote">Цитата</button>
          <button class="btn small ghost" type="button" data-add="list" data-i18n="news.blockList">Список</button>
          <button class="btn small ghost" type="button" data-add="code" data-i18n="news.blockCode">Код</button>
          <button class="btn small ghost" type="button" data-add="image" data-i18n="news.blockImage">Картинка</button>
          <button class="btn small ghost" type="button" data-add="album" data-i18n="news.blockAlbum">Альбом</button>
          <span class="ne-count" id="neBlockCount"></span>
        </div>

        <div class="ne-fmt" id="neFmt" hidden role="toolbar" aria-label="Форматирование">
          <button type="button" data-fmt="b" title="Ctrl+B"><b>Ж</b></button>
          <button type="button" data-fmt="i" title="Ctrl+I"><i>К</i></button>
          <button type="button" data-fmt="u" title="Ctrl+U"><u>П</u></button>
          <button type="button" data-fmt="st" title="Зачёркнутый"><s>З</s></button>
          <button type="button" data-fmt="c" title="Моноширинный">&lt;/&gt;</button>
          <button type="button" data-fmt="sp" title="Спойлер">▨</button>
          <button type="button" data-fmt="a" title="Ctrl+K">🔗</button>
        </div>
```

- [ ] **Step 2: Add the i18n keys**

In `public_html/js/i18n.js`, next to the existing `news.*` keys, add to the Russian dictionary:

```js
      "news.blockP":            "Абзац",
      "news.blockQuote":        "Цитата",
      "news.blockList":         "Список",
      "news.blockCode":         "Код",
      "news.blockImage":        "Картинка",
      "news.blockAlbum":        "Альбом",
      "news.blockCount":        "{n} из {max} блоков",
      "news.blockRemove":       "Удалить блок",
      "news.blockMove":         "Перетащить блок",
      "news.blockCollapsible":  "Раскрывающаяся",
      "news.blockOrdered":      "Нумерованный",
      "news.blockCaption":      "Подпись",
      "news.linkPrompt":        "Адрес ссылки (http:// или https://)",
      "news.linkBad":           "Ссылка должна начинаться с http:// или https://",
      "news.blocksEmpty":       "Пост пустой — добавьте хотя бы один блок с текстом.",
      "news.blocksTooBig":      "Пост слишком большой. Уберите часть картинок или текста.",
```

Add the same keys to the English dictionary with English wording.

- [ ] **Step 3: Implement the block list**

Add to `public_html/js/news-editor.js`:

```js
  // Блоки поста в порядке показа. Единственный источник правды о содержимом:
  // DOM редактора из него рисуется, а не наоборот — обратное чтение (DOM →
  // спаны) происходит только для текста внутри блока, где им управляет
  // contenteditable.
  let editorBlocks = [];
  // Какой язык сейчас правится. Раскладку не трогает — переключатель меняет
  // только текст, см. спеку (одна структура, два текста).
  let editorLang = "ru";

  const NB = window.NEWSBLOCKS;

  // Существующий редактор пишет ошибки в #neError напрямую, в семи местах.
  // Блочный добавляет ещё несколько — заводим одну функцию вместо восьмой
  // копии одной и той же строки.
  function showError(msg) { document.querySelector("#neError").textContent = msg || ""; }

  function emptySpans() { return []; }

  function newBlock(type) {
    if (type === "p")     { return { t: "p", ru: emptySpans(), en: emptySpans() }; }
    if (type === "quote") { return { t: "quote", ru: emptySpans(), en: emptySpans(), collapsible: false }; }
    if (type === "code")  { return { t: "code", ru: "", en: "" }; }
    if (type === "list")  { return { t: "list", ordered: false, items: [{ ru: emptySpans(), en: emptySpans() }] }; }
    if (type === "image") { return { t: "image", url: "", w: 0, h: 0, pct: 100, align: "center", wrap: false, cap_ru: emptySpans(), cap_en: emptySpans() }; }
    return { t: "album", items: [], cap_ru: emptySpans(), cap_en: emptySpans() };
  }

  // Спаны → редактируемый DOM. Теги те же, что рисует renderBlocks, чтобы
  // Ctrl+B в редакторе давал ровно то, что потом покажет лента.
  function spansToEditable(doc, spans) {
    const frag = doc.createDocumentFragment();
    for (const sp of spans) {
      let node = doc.createTextNode(sp.s);
      const wrap = (tag, cls, attrs) => {
        const w = doc.createElement(tag);
        if (cls) { w.className = cls; }
        if (attrs) { for (const k in attrs) { w.setAttribute(k, attrs[k]); } }
        w.append(node);
        node = w;
      };
      if (sp.b)  { wrap("strong"); }
      if (sp.i)  { wrap("em"); }
      if (sp.u)  { wrap("u"); }
      if (sp.st) { wrap("s"); }
      if (sp.c)  { wrap("code"); }
      if (sp.sp) { wrap("span", "nw-spoiler"); }
      if (sp.href) { wrap("a", "", { href: sp.href }); }
      frag.append(node);
    }
    return frag;
  }

  // Редактируемый DOM → спаны. Белый список тегов: всё, чего в нём нет,
  // схлопывается в текст. Это второй рубеж после «вставка только как
  // plain text» — браузер и сам умеет насовать <font> и <div> в
  // contenteditable, и ни один из них не имеет права доехать до базы.
  const TAG_FLAG = { STRONG: "b", B: "b", EM: "i", I: "i", U: "u", S: "st", STRIKE: "st", CODE: "c" };

  function blockToSpans(root) {
    const out = [];
    const walk = (node, state) => {
      if (node.nodeType === 3) {
        if (node.nodeValue === "") { return; }
        const sp = { s: node.nodeValue };
        for (const k in state) { if (state[k]) { sp[k] = state[k]; } }
        // Соседние одинаково оформленные куски склеиваются: contenteditable
        // любит дробить текст на узлы, а в базе нужен минимальный список.
        const prev = out[out.length - 1];
        if (prev && sameFormat(prev, sp)) { prev.s += sp.s; } else { out.push(sp); }
        return;
      }
      if (node.nodeType !== 1) { return; }
      const next = Object.assign({}, state);
      const flag = TAG_FLAG[node.tagName];
      if (flag) { next[flag] = true; }
      if (node.tagName === "SPAN" && node.classList.contains("nw-spoiler")) { next.sp = true; }
      if (node.tagName === "A") {
        const href = node.getAttribute("href") || "";
        if (NB.isSafeHref(href)) { next.href = href; }
      }
      if (node.tagName === "BR") { return; }
      for (const kid of Array.from(node.childNodes)) { walk(kid, next); }
    };
    for (const kid of Array.from(root.childNodes)) { walk(kid, {}); }
    return out;
  }

  function sameFormat(a, b) {
    const keys = ["b", "i", "u", "st", "c", "sp", "href"];
    return keys.every(k => (a[k] || false) === (b[k] || false));
  }
```

Then the list renderer, which builds one row per block, with a drag handle, a delete button and the type-specific editor:

```js
  function renderBlockList() {
    const box = document.querySelector("#neBlocks");
    box.innerHTML = "";
    editorBlocks.forEach((b, i) => box.append(blockRow(b, i)));
    const count = document.querySelector("#neBlockCount");
    // Третий аргумент I18N.t — подстановки, как в news.confirmDelete.
    count.textContent = I18N.t("news.blockCount", window.NEWSPAGE.getLang(),
      { n: editorBlocks.length, max: NB.LIMITS.blocks });
    updatePreview();
  }

  function blockRow(b, index) {
    const row = document.createElement("div");
    row.className = "ne-block ne-block-" + b.t;
    row.dataset.index = String(index);
    row.draggable = false; // тащим только за ручку, иначе не выделить текст

    const grip = document.createElement("button");
    grip.type = "button";
    grip.className = "ne-grip";
    grip.title = I18N.t("news.blockMove", window.NEWSPAGE.getLang());
    grip.textContent = "⠿";
    grip.addEventListener("pointerdown", () => { row.draggable = true; });
    row.addEventListener("dragend", () => { row.draggable = false; });

    const del = document.createElement("button");
    del.type = "button";
    del.className = "ne-block-del";
    del.title = I18N.t("news.blockRemove", window.NEWSPAGE.getLang());
    del.textContent = "✕";
    del.addEventListener("click", () => { editorBlocks.splice(index, 1); renderBlockList(); });

    row.append(grip, blockEditor(b, index), del);
    return row;
  }

  // Редактируемая часть блока. Текстовые типы — один contenteditable;
  // картинка и альбом получают свои элементы управления в задаче 9.
  function blockEditor(b, index) {
    if (b.t === "code") {
      const ta = document.createElement("textarea");
      ta.className = "ne-code";
      ta.rows = 3;
      ta.value = b[editorLang];
      ta.addEventListener("input", () => { b[editorLang] = ta.value; updatePreview(); });
      return ta;
    }
    // mediaBlockEditor приезжает в задаче 9. До неё — заглушка, чтобы
    // список блоков был проверяем сам по себе: кнопки «Картинка»/«Альбом»
    // уже создают блок, просто редактировать его пока нечем.
    if (b.t === "image" || b.t === "album") {
      if (typeof mediaBlockEditor === "function") { return mediaBlockEditor(b, index); }
      const stub = document.createElement("div");
      stub.className = "ne-media";
      stub.textContent = b.t;
      return stub;
    }

    const wrap = document.createElement("div");
    if (b.t === "quote") {
      const lbl = document.createElement("label");
      lbl.className = "ne-inline-check";
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = !!b.collapsible;
      cb.addEventListener("change", () => { b.collapsible = cb.checked; updatePreview(); });
      lbl.append(cb, document.createTextNode(" " + I18N.t("news.blockCollapsible", window.NEWSPAGE.getLang())));
      wrap.append(lbl);
    }
    if (b.t === "list") {
      const lbl = document.createElement("label");
      lbl.className = "ne-inline-check";
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.checked = !!b.ordered;
      cb.addEventListener("change", () => { b.ordered = cb.checked; updatePreview(); });
      lbl.append(cb, document.createTextNode(" " + I18N.t("news.blockOrdered", window.NEWSPAGE.getLang())));
      wrap.append(lbl);
      b.items.forEach((it, j) => wrap.append(editableFor(it, index, j)));
      return wrap;
    }
    wrap.append(editableFor(b, index, -1));
    return wrap;
  }

  function editableFor(holder, index, itemIndex) {
    const ed = document.createElement("div");
    ed.className = "ne-editable";
    ed.contentEditable = "true";
    ed.dataset.index = String(index);
    ed.dataset.item = String(itemIndex);
    // Куда писать прочитанные спаны — прямо на элементе. dataset хранит
    // только индекс блока (он нужен клавиатуре), а holder может быть и самим
    // блоком, и пунктом списка, и подписью картинки (задача 9) — вычислять
    // его заново из индексов значило бы завести третий способ сказать одно и
    // то же и потом гадать, какой из них устарел.
    ed._holder = holder;
    ed.append(spansToEditable(document, holder[editorLang] || []));

    // Вставка всегда простым текстом: скопированный из телеграма пост несёт
    // чужую разметку, и белый список в blockToSpans — не повод пускать её в
    // DOM редактора вообще.
    ed.addEventListener("paste", ev => {
      ev.preventDefault();
      const text = (ev.clipboardData || window.clipboardData).getData("text/plain");
      document.execCommand("insertText", false, text);
    });
    ed.addEventListener("input", () => {
      holder[editorLang] = blockToSpans(ed);
      updatePreview();
    });
    return ed;
  }
```

- [ ] **Step 4: Wire the add row, the language switch and the formatting toolbar**

```js
  document.querySelector("#neAddRow").addEventListener("click", ev => {
    const btn = ev.target.closest("[data-add]");
    if (!btn) { return; }
    if (editorBlocks.length >= NB.LIMITS.blocks) { return; }
    editorBlocks.push(newBlock(btn.dataset.add));
    renderBlockList();
  });

  document.querySelector("#neLang").addEventListener("click", ev => {
    const btn = ev.target.closest("[data-v]");
    if (!btn) { return; }
    editorLang = btn.dataset.v === "en" ? "en" : "ru";
    for (const b of document.querySelectorAll("#neLang [data-v]")) {
      b.classList.toggle("active", b.dataset.v === editorLang);
    }
    // Перерисовка целиком, а не точечная: текст блоков меняется весь сразу,
    // а порядок и картинки — нет, и это ровно то, что обещает переключатель.
    renderBlockList();
  });

  // Плавающая панель форматирования — показывается, пока внутри блока есть
  // непустое выделение.
  document.addEventListener("selectionchange", () => {
    const fmt = document.querySelector("#neFmt");
    const sel = document.getSelection();
    const inEditor = sel && sel.rangeCount && sel.anchorNode
      && sel.anchorNode.parentElement && sel.anchorNode.parentElement.closest(".ne-editable");
    if (!inEditor || sel.isCollapsed) { fmt.hidden = true; return; }
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    fmt.hidden = false;
    fmt.style.left = Math.round(rect.left + window.scrollX) + "px";
    fmt.style.top = Math.round(rect.top + window.scrollY - fmt.offsetHeight - 6) + "px";
  });

  const EXEC = { b: "bold", i: "italic", u: "underline", st: "strikeThrough" };

  document.querySelector("#neFmt").addEventListener("mousedown", ev => {
    // mousedown, а не click: click сначала снял бы выделение.
    ev.preventDefault();
    const btn = ev.target.closest("[data-fmt]");
    if (!btn) { return; }
    applyFormat(btn.dataset.fmt);
  });

  function applyFormat(kind) {
    if (EXEC[kind]) { document.execCommand(EXEC[kind], false, null); }
    else if (kind === "c") { wrapSelection("code", ""); }
    else if (kind === "sp") { wrapSelection("span", "nw-spoiler"); }
    else if (kind === "a") {
      const url = window.prompt(I18N.t("news.linkPrompt", window.NEWSPAGE.getLang()), "https://");
      if (url === null) { return; }
      if (!NB.isSafeHref(url)) { showError(I18N.t("news.linkBad", window.NEWSPAGE.getLang())); return; }
      document.execCommand("createLink", false, url);
    }
    syncActiveEditable();
  }

  // Обёртка выделения тегом, которого нет в execCommand.
  function wrapSelection(tag, cls) {
    const sel = document.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) { return; }
    const range = sel.getRangeAt(0);
    const el = document.createElement(tag);
    if (cls) { el.className = cls; }
    el.append(range.extractContents());
    range.insertNode(el);
    sel.removeAllRanges();
  }

  // После любой правки выделения читаем DOM активного блока обратно в модель:
  // execCommand меняет DOM, а не наш массив, и без этого превью отставало бы
  // на одно действие.
  function syncActiveEditable() {
    const ed = document.activeElement && document.activeElement.closest
      ? document.activeElement.closest(".ne-editable")
      : null;
    if (!ed || !ed._holder) { return; }
    ed._holder[editorLang] = blockToSpans(ed);
    updatePreview();
  }
```

- [ ] **Step 5: Style the editor chrome**

Append to `public_html/css/admin-shell.css`:

```css
/* ===================== Блочный редактор поста ===================== */
.ne-blocks { display: flex; flex-direction: column; gap: 6px; }

.ne-block {
  display: grid;
  grid-template-columns: 20px 1fr 24px;
  gap: 8px;
  align-items: start;
  padding: 8px 10px;
  border: 1px solid rgba(255, 255, 255, 0.16);
  border-radius: 8px;
}
.ne-block.is-drop-target { border-color: #61b5e9; }

.ne-grip, .ne-block-del {
  background: none;
  border: 0;
  color: rgba(255, 255, 255, 0.5);
  cursor: grab;
  font-size: 14px;
  line-height: 1;
  padding: 2px;
}
.ne-block-del { cursor: pointer; }

.ne-editable {
  min-height: 22px;
  padding: 2px 0;
  outline: none;
  line-height: 1.6;
}
.ne-editable:focus { box-shadow: inset 0 -1px 0 #61b5e9; }

.ne-block-quote .ne-editable { border-left: 2px solid #61b5e9; padding-left: 10px; }
.ne-code { width: 100%; font-family: ui-monospace, Consolas, monospace; }

.ne-inline-check { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; opacity: 0.75; }

.ne-add { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 8px; }
.ne-count { margin-left: auto; font-size: 12px; opacity: 0.6; }

.ne-lang-seg { display: inline-flex; border: 1px solid rgba(255, 255, 255, 0.2); border-radius: 6px; overflow: hidden; }
.ne-lang-seg button { background: none; border: 0; color: inherit; padding: 4px 12px; cursor: pointer; }
.ne-lang-seg button.active { background: rgba(97, 181, 233, 0.25); }

/* Панель форматирования висит над выделением — position: absolute от
   документа, поэтому координаты считаются со scrollX/scrollY. */
.ne-fmt {
  position: absolute;
  z-index: 30;
  display: flex;
  gap: 2px;
  padding: 4px;
  border-radius: 6px;
  background: #1b1b22;
  border: 1px solid rgba(255, 255, 255, 0.2);
}
.ne-fmt button { background: none; border: 0; color: #fff; padding: 3px 7px; cursor: pointer; border-radius: 4px; }
.ne-fmt button:hover { background: rgba(255, 255, 255, 0.12); }
```

- [ ] **Step 6: Verify**

Open `/admin/news` with the preview tools, click **Абзац**, type text, select it, press the Ж button, and confirm with `read_page` that the preview card gained a `STRONG`. Then `read_console_messages` — clean.

- [ ] **Step 7: Commit**

```bash
git add public_html/admin-news.php public_html/js/news-editor.js public_html/js/i18n.js public_html/css/admin-shell.css
git commit -m "feat(news): блочный редактор — список блоков и форматирование текста"
```

---

### Task 9: Block editor — image and album blocks

**Files:**
- Modify: `public_html/js/news-editor.js`, `public_html/css/admin-shell.css`

**Interfaces:**
- Consumes: `newBlock`, `renderBlockList`, `updatePreview`, `editableFor`, `showError` from Task 8; the existing `readFileAsDataURL`, `decodeImageForCrop`, `initCropState`, `confirmCrop`, `skipCrop`, `closeCropUI`, `uploadPickedImage` and the `#neCrop` markup.
- Produces: `pickImage(file, pct) -> Promise<{url, w, h}|null>`, `mediaBlockEditor(block, index) -> HTMLElement`.

- [ ] **Step 1: Turn the crop flow into a promise**

Today the crop flow ends by writing the module-level `currentImage` / `currentImageWidth` / `currentImageHeight` through `setImage()`, because a post had exactly one picture. With many pictures per post that is wrong: the dialog must hand its result back to the block that opened it.

Add to `public_html/js/news-editor.js`:

```js
  // Кто ждёт результата кропа. Ровно один за раз: диалог модальный, а
  // повторный выбор файла до подтверждения предыдущего уже закрывает
  // предыдущий (см. closeCropUI ниже).
  let cropResolve = null;
  // Ширина, под которую надо сохранить файл: потолок стороны считается от
  // неё (NEWS.newsImageCap). Раньше её давал общий на весь пост #nePct,
  // теперь — тот блок, который открыл диалог.
  let cropTargetPct = 100;

  // Открывает кроп для файла и обещает {url, w, h} — ровно то, что вернул
  // upload.php после пересжатия. null, если админ закрыл диалог, ничего не
  // выбрав.
  function pickImage(file, pct) {
    cropTargetPct = pct;
    return new Promise(resolve => {
      cropResolve = resolve;
      startCropFor(file);
    });
  }
```

Move the body of the existing `$("#neImageFile").addEventListener("change", …)` handler (`js/news-page.js:1198-1229` before the Task 7 move) into a named `async function startCropFor(file)`, dropping only the two lines that read and reset `ev.target` — the file now arrives as an argument. Its `uploadPickedImage()` fallback path (undecodable file, e.g. HEIC) must end with the same resolve as the crop path.

In `confirmCrop()` and `skipCrop()`, replace the final `setImage(url, w, h)` call with:

```js
    if (cropResolve) { cropResolve({ url: url, w: width, h: height }); cropResolve = null; }
    closeCropUI();
```

and add at the top of `closeCropUI()`, before it clears the crop state:

```js
  // Диалог закрыли, ничего не выбрав — обещание обязано разрешиться, иначе
  // блок навсегда останется в состоянии «ждём картинку».
  if (cropResolve) { cropResolve(null); cropResolve = null; }
```

Replace every `getPct()` inside the crop code with `cropTargetPct`, then delete the now-unused `setImage`, `currentImage`, `currentImageWidth`, `currentImageHeight`, `setPct`, `getPct`, `updatePctOutput`, `setAlign`, `getAlign`, `renderAlignSeg`, `setWrap`, `getWrap` and `updatePctHint` — all of them served the one global image the form no longer has. The "file is stored at a smaller width" hint comes back per block in Step 2.

- [ ] **Step 2: Implement the media block editor**

```js
  function mediaBlockEditor(b, index) {
    const wrap = document.createElement("div");
    wrap.className = "ne-media";

    const strip = document.createElement("div");
    strip.className = "ne-thumbs";
    wrap.append(strip);

    const drawThumbs = () => {
      strip.innerHTML = "";
      const urls = b.t === "image" ? (b.url ? [{ url: b.url, w: b.w, h: b.h }] : []) : b.items;
      urls.forEach((im, j) => {
        const th = document.createElement("div");
        th.className = "ne-thumb";
        const img = document.createElement("img");
        img.src = im.url;
        img.alt = "";
        const x = document.createElement("button");
        x.type = "button";
        x.textContent = "✕";
        x.addEventListener("click", () => {
          if (b.t === "image") { b.url = ""; b.w = 0; b.h = 0; }
          else { b.items.splice(j, 1); }
          drawThumbs();
          updatePreview();
        });
        th.append(img, x);
        strip.append(th);
      });

      const roomLeft = b.t === "image" ? !b.url : b.items.length < NB.LIMITS.albumItems;
      if (roomLeft) {
        const add = document.createElement("button");
        add.type = "button";
        add.className = "ne-thumb ne-thumb-add";
        add.textContent = "＋";
        add.addEventListener("click", () => chooseFile(b, drawThumbs));
        strip.append(add);
      }
    };

    drawThumbs();

    // Геометрия — только у одиночной картинки. У альбома её нет: плитка
    // всегда во всю ширину, и ползунок ширины там означал бы не то, что
    // означает у картинки.
    if (b.t === "image") { wrap.append(imageGeometryControls(b)); }

    // Подпись — тот же редактируемый div, что у абзаца, только пишет в
    // cap_ru/cap_en. Спаны в подписи разрешены, так что ссылка в подписи
    // работает как везде.
    const capHolder = {
      get ru() { return b.cap_ru; }, set ru(v) { b.cap_ru = v; },
      get en() { return b.cap_en; }, set en(v) { b.cap_en = v; }
    };
    const cap = document.createElement("div");
    cap.className = "ne-cap-field";
    const capLbl = document.createElement("span");
    capLbl.className = "ne-cap-label";
    capLbl.textContent = I18N.t("news.blockCaption", window.NEWSPAGE.getLang());
    cap.append(capLbl, editableFor(capHolder, index, -1));
    wrap.append(cap);

    return wrap;
  }

  function chooseFile(b, redraw) {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.addEventListener("change", async () => {
      const file = input.files && input.files[0];
      if (!file) { return; }
      const pct = b.t === "image" ? b.pct : 100;
      const got = await pickImage(file, pct);
      if (!got) { return; }
      if (b.t === "image") { b.url = got.url; b.w = got.w; b.h = got.h; }
      else { b.items.push({ url: got.url, w: got.w, h: got.h }); }
      redraw();
      updatePreview();
    });
    input.click();
  }

  function imageGeometryControls(b) {
    const row = document.createElement("div");
    row.className = "ne-geom";

    const pct = document.createElement("input");
    pct.type = "range";
    pct.min = "10"; pct.max = "100"; pct.step = "5";
    pct.value = String(b.pct);
    const out = document.createElement("output");
    out.textContent = b.pct + "%";

    // Подсказка о мыле, та же, что раньше висела на весь пост (#nePctHint),
    // только теперь у каждой картинки своя: файл сохранён под потолок той
    // ширины, что стояла при заливке, и поднять ширину выше — значит
    // растянуть уже сжатые пиксели. Перезалить нечем: исходника у открытого
    // поста в памяти браузера нет.
    const hint = document.createElement("p");
    hint.className = "ne-hint";
    const refreshHint = () => {
      const stored = Math.max(b.w || 0, b.h || 0);
      const stale = !!b.url && stored > 0 && NEWS.newsImageCap(b.pct) > stored;
      hint.hidden = !stale;
      hint.textContent = stale ? I18N.t("news.imageStale", window.NEWSPAGE.getLang()) : "";
    };
    refreshHint();

    pct.addEventListener("input", () => {
      b.pct = Number(pct.value);
      out.textContent = b.pct + "%";
      refreshHint();
      updatePreview();
    });

    const seg = document.createElement("div");
    seg.className = "ne-cat-seg";
    for (const a of NEWS.ALIGNS) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = I18N.t(a.i18n, window.NEWSPAGE.getLang());
      btn.className = a.key === b.align ? "active" : "";
      btn.addEventListener("click", () => {
        b.align = a.key;
        for (const s of seg.children) { s.classList.remove("active"); }
        btn.classList.add("active");
        updatePreview();
      });
      seg.append(btn);
    }

    const wrapLbl = document.createElement("label");
    wrapLbl.className = "ne-inline-check";
    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.checked = !!b.wrap;
    cb.addEventListener("change", () => { b.wrap = cb.checked; updatePreview(); });
    wrapLbl.append(cb, document.createTextNode(" " + I18N.t("news.fieldImageWrap", window.NEWSPAGE.getLang())));

    row.append(pct, out, seg, wrapLbl, hint);
    return row;
  }
```

- [ ] **Step 3: Style the media block**

Append to `public_html/css/admin-shell.css`:

```css
.ne-media { display: flex; flex-direction: column; gap: 8px; }
.ne-thumbs { display: flex; flex-wrap: wrap; gap: 6px; }
.ne-thumb {
  position: relative;
  width: 96px; height: 64px;
  border-radius: 6px;
  overflow: hidden;
  background: rgba(255, 255, 255, 0.08);
}
.ne-thumb img { width: 100%; height: 100%; object-fit: cover; }
.ne-thumb button {
  position: absolute; top: 2px; right: 2px;
  width: 18px; height: 18px;
  border: 0; border-radius: 50%;
  background: rgba(0, 0, 0, 0.6); color: #fff;
  cursor: pointer; font-size: 11px; line-height: 1;
}
.ne-thumb-add {
  display: flex; align-items: center; justify-content: center;
  border: 1px dashed rgba(255, 255, 255, 0.3);
  color: rgba(255, 255, 255, 0.6);
  background: none; cursor: pointer; font-size: 18px;
}
.ne-geom { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.ne-cap-field { display: flex; align-items: baseline; gap: 8px; }
.ne-cap-label { font-size: 12px; opacity: 0.6; white-space: nowrap; }
```

- [ ] **Step 4: Verify**

On `/admin/news`: add an image block, upload a picture, crop it, confirm the thumbnail and the preview both show it; add an album block, upload three pictures, confirm the preview shows a 3-column grid; delete one and confirm the grid becomes 2 columns.

- [ ] **Step 5: Commit**

```bash
git add public_html/js/news-editor.js public_html/css/admin-shell.css
git commit -m "feat(news): блоки картинки и альбома с кропом на каждую"
```

---

### Task 10: Block editor — keyboard, slash menu, markdown shortcuts, drag

**Files:**
- Modify: `public_html/js/news-editor.js`, `public_html/css/admin-shell.css`

**Interfaces:**
- Consumes: everything from Tasks 8-9.
- Produces: nothing new for later tasks.

- [ ] **Step 1: Enter, Backspace and the shortcuts**

```js
  document.querySelector("#neBlocks").addEventListener("keydown", ev => {
    const ed = ev.target.closest(".ne-editable");
    if (!ed) { return; }
    const bi = Number(ed.dataset.index);

    if ((ev.ctrlKey || ev.metaKey) && !ev.altKey) {
      const key = ev.key.toLowerCase();
      const map = { b: "b", i: "i", u: "u", k: "a" };
      if (map[key]) { ev.preventDefault(); applyFormat(map[key]); return; }
    }

    if (ev.key === "Enter" && !ev.shiftKey) {
      // Enter — новый блок, а не <br> внутри текущего: пост состоит из
      // блоков, и «пустая строка внутри абзаца» в этой модели не существует.
      // Shift+Enter оставлен браузеру — это перенос строки внутри абзаца.
      ev.preventDefault();
      editorBlocks.splice(bi + 1, 0, newBlock("p"));
      renderBlockList();
      focusBlock(bi + 1);
      return;
    }

    if (ev.key === "Backspace" && ed.textContent === "" && bi > 0) {
      // Пустой блок склеивается с предыдущим — то же, что делает телеграм и
      // Notion. Непустой не трогаем: удалять текст крестиком, а не бэкспейсом.
      ev.preventDefault();
      editorBlocks.splice(bi, 1);
      renderBlockList();
      focusBlock(bi - 1);
    }
  });

  function focusBlock(index) {
    const ed = document.querySelector('.ne-block[data-index="' + index + '"] .ne-editable');
    if (!ed) { return; }
    ed.focus();
    const range = document.createRange();
    range.selectNodeContents(ed);
    range.collapse(false);
    const sel = document.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }
```

- [ ] **Step 2: The slash menu**

```js
  // "/" в пустом блоке открывает список типов — тот же набор, что в кнопках
  // #neAddRow, просто под рукой, без переноса взгляда вниз формы.
  document.querySelector("#neBlocks").addEventListener("keyup", ev => {
    const ed = ev.target.closest(".ne-editable");
    if (!ed || ev.key !== "/") { return; }
    if (ed.textContent !== "/") { return; }
    openSlashMenu(ed, Number(ed.dataset.index));
  });

  function openSlashMenu(ed, index) {
    closeSlashMenu();
    const menu = document.createElement("div");
    menu.className = "ne-slash";
    menu.id = "neSlash";
    const types = [
      ["p", "news.blockP"], ["quote", "news.blockQuote"], ["list", "news.blockList"],
      ["code", "news.blockCode"], ["image", "news.blockImage"], ["album", "news.blockAlbum"]
    ];
    for (const [type, key] of types) {
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = I18N.t(key, window.NEWSPAGE.getLang());
      b.addEventListener("mousedown", mev => {
        mev.preventDefault();
        editorBlocks[index] = newBlock(type);
        closeSlashMenu();
        renderBlockList();
        focusBlock(index);
      });
      menu.append(b);
    }
    const rect = ed.getBoundingClientRect();
    menu.style.left = Math.round(rect.left + window.scrollX) + "px";
    menu.style.top = Math.round(rect.bottom + window.scrollY + 4) + "px";
    document.body.append(menu);
  }

  function closeSlashMenu() {
    const old = document.querySelector("#neSlash");
    if (old) { old.remove(); }
  }

  document.addEventListener("click", ev => {
    if (!ev.target.closest("#neSlash")) { closeSlashMenu(); }
  });
```

- [ ] **Step 3: Inline markdown as you type**

```js
  // Разметка на лету: закрывающий символ превращает пару в форматирование,
  // как в телеграме. Работает только на простом тексте прямо перед кареткой —
  // никакого разбора всего блока, иначе правка середины абзаца ломала бы
  // уже расставленное форматирование.
  const MD_RULES = [
    { re: /\*\*([^*]+)\*\*$/, fmt: "b" },
    { re: /__([^_]+)__$/,     fmt: "u" },
    { re: /~~([^~]+)~~$/,     fmt: "st" },
    { re: /\|\|([^|]+)\|\|$/, fmt: "sp" },
    { re: /`([^`]+)`$/,       fmt: "c" }
  ];

  document.querySelector("#neBlocks").addEventListener("input", ev => {
    const ed = ev.target.closest(".ne-editable");
    if (!ed) { return; }
    const sel = document.getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) { return; }
    const node = sel.anchorNode;
    if (!node || node.nodeType !== 3) { return; }
    const upto = node.nodeValue.slice(0, sel.anchorOffset);

    for (const rule of MD_RULES) {
      const m = rule.re.exec(upto);
      if (!m) { continue; }
      const start = sel.anchorOffset - m[0].length;
      const range = document.createRange();
      range.setStart(node, start);
      range.setEnd(node, sel.anchorOffset);
      range.deleteContents();

      const text = document.createTextNode(m[1]);
      range.insertNode(text);
      const r2 = document.createRange();
      r2.selectNodeContents(text);
      sel.removeAllRanges();
      sel.addRange(r2);
      applyFormat(rule.fmt);
      sel.collapseToEnd();
      break;
    }
  });
```

- [ ] **Step 4: Drag to reorder**

```js
  let dragFrom = -1;

  const blocksBox = document.querySelector("#neBlocks");
  blocksBox.addEventListener("dragstart", ev => {
    const row = ev.target.closest(".ne-block");
    if (!row) { return; }
    dragFrom = Number(row.dataset.index);
    ev.dataTransfer.effectAllowed = "move";
    // Firefox не начинает перетаскивание без установленных данных.
    ev.dataTransfer.setData("text/plain", String(dragFrom));
  });

  blocksBox.addEventListener("dragover", ev => {
    const row = ev.target.closest(".ne-block");
    if (!row || dragFrom < 0) { return; }
    ev.preventDefault();
    for (const r of blocksBox.children) { r.classList.remove("is-drop-target"); }
    row.classList.add("is-drop-target");
  });

  blocksBox.addEventListener("drop", ev => {
    const row = ev.target.closest(".ne-block");
    if (!row || dragFrom < 0) { return; }
    ev.preventDefault();
    const to = Number(row.dataset.index);
    const moved = editorBlocks.splice(dragFrom, 1)[0];
    editorBlocks.splice(to, 0, moved);
    dragFrom = -1;
    renderBlockList();
  });
```

- [ ] **Step 5: Style the slash menu**

```css
.ne-slash {
  position: absolute;
  z-index: 30;
  display: flex;
  flex-direction: column;
  min-width: 140px;
  padding: 4px;
  border-radius: 8px;
  background: #1b1b22;
  border: 1px solid rgba(255, 255, 255, 0.2);
}
.ne-slash button {
  background: none; border: 0; color: #fff;
  text-align: left; padding: 5px 8px; border-radius: 4px; cursor: pointer;
}
.ne-slash button:hover { background: rgba(255, 255, 255, 0.12); }
```

- [ ] **Step 6: Verify each interaction**

On `/admin/news`, in order: type `**жирно**` and confirm it turns bold and the asterisks disappear; type `||секрет||` and confirm a spoiler span; press Enter and confirm a new block appears focused; press Backspace in it and confirm it merges back; type `/` in an empty block and pick "Цитата"; drag the third block above the first and confirm the preview reorders.

- [ ] **Step 7: Commit**

```bash
git add public_html/js/news-editor.js public_html/css/admin-shell.css
git commit -m "feat(news): клавиатура редактора — Enter, /, markdown на лету, перетаскивание"
```

---

### Task 11: Load, preview and save

**Files:**
- Modify: `public_html/js/news-editor.js`

**Interfaces:**
- Consumes: everything above. The existing module-level `editingPost` (set by `openEditor`, read by `publish`) keeps its name and role.
- Produces: an `openEditor(post)` that fills `editorBlocks`, a `publish()` that posts `body_json`, and a preview that goes through `renderBlocks`.

- [ ] **Step 1: Fill the editor from a post, converting legacy ones**

```js
  // Легаси-пост превращается в блоки в памяти — миграции данных нет: пока
  // админ не сохранит, в базе всё лежит по-старому. Абзацы становятся
  // блоками p, картинка поста — первым блоком image со своей нынешней
  // геометрией.
  function blocksFromLegacy(post) {
    const blocks = [];
    if (post.image_url) {
      blocks.push({
        t: "image", url: post.image_url,
        w: post.image_width || 0, h: post.image_height || 0,
        pct: Number(post.image_pct) || 100,
        align: NEWS.isAlign(post.image_align) ? post.image_align : "center",
        wrap: !!post.image_wrap,
        cap_ru: [], cap_en: []
      });
    }
    const ru = NEWS.toParagraphs(post.body_ru || "");
    const en = NEWS.toParagraphs(post.body_en || "");
    const n = Math.max(ru.length, en.length);
    for (let i = 0; i < n; i++) {
      blocks.push({
        t: "p",
        ru: ru[i] ? [{ s: ru[i] }] : [],
        en: en[i] ? [{ s: en[i] }] : []
      });
    }
    return blocks;
  }

  function openEditor(post) {
    // На случай, если предыдущая сессия редактирования была прервана не
    // через closeEditor().
    closeCropUI();
    editingPost = post;
    editorLang = "ru";

    // Копия, а не сама структура поста: пока админ не нажал «Опубликовать»,
    // правки не должны быть видны в уже отрисованной ленте под модалкой.
    if (post && post.body_json) {
      const v = NB.validateDoc(post.body_json);
      editorBlocks = v.ok ? JSON.parse(JSON.stringify(v.blocks)) : blocksFromLegacy(post);
    } else if (post) {
      editorBlocks = blocksFromLegacy(post);
    } else {
      editorBlocks = [newBlock("p")];
    }

    const tx = key => I18N.t(key, window.NEWSPAGE.getLang());
    document.querySelector("#neHeading").textContent = tx(post ? "news.modalEdit" : "news.modalNew");
    document.querySelector("#neTitleRu").value = post ? post.title_ru : "";
    document.querySelector("#neTitleEn").value = post ? post.title_en : "";
    document.querySelector("#neDate").value = isoDay(post ? post.published_at : Date.now());
    setCat(post ? post.category : "tierlist");
    for (const b of document.querySelectorAll("#neLang [data-v]")) {
      b.classList.toggle("active", b.dataset.v === "ru");
    }
    showError("");

    renderBlockList();
    editor.hidden = false;
    setTimeout(() => document.querySelector("#neTitleRu").focus(), 30);
  }
```

- [ ] **Step 2: Preview through the real renderer**

```js
  // Превью строит НАСТОЯЩАЯ карточка ленты (window.NEWSPAGE.cardFor) из
  // черновика поста — второго рендера нет и разойтись нечему.
  function buildPreviewPost() {
    const blocks = currentDoc().blocks;
    const first = NB.firstImage(blocks);
    return {
      id: 0,
      category: getCat(),
      title_ru: document.querySelector("#neTitleRu").value.trim(),
      title_en: document.querySelector("#neTitleEn").value.trim(),
      // Плоский текст считается тем же кодом, что и на сервере, — если
      // превью и лента разойдутся, это будет видно сразу здесь.
      body_ru: NB.toPlainText(blocks, "ru"),
      body_en: NB.toPlainText(blocks, "en"),
      image_url: first ? first.url : "",
      image_width: first ? first.w : null,
      image_height: first ? first.h : null,
      image_pct: 100, image_align: "center", image_wrap: false,
      body_json: { v: NB.DOC_VERSION, blocks: blocks },
      published_at: dayToMs(document.querySelector("#neDate").value),
      likes: 0
    };
  }

  function updatePreview() {
    const box = document.querySelector("#nePreviewCard");
    box.innerHTML = "";
    box.append(window.NEWSPAGE.cardFor(buildPreviewPost(), false));
  }

  function currentDoc() {
    // Пустые блоки выбрасываются на выходе, а не запрещаются на входе:
    // пустой абзац — нормальное промежуточное состояние набора текста, но в
    // базе ему делать нечего.
    const blocks = editorBlocks.filter(b => {
      if (b.t === "p" || b.t === "quote") { return (b.ru.length + b.en.length) > 0; }
      if (b.t === "code") { return b.ru !== "" || b.en !== ""; }
      if (b.t === "list") { return b.items.some(i => i.ru.length + i.en.length > 0); }
      if (b.t === "image") { return !!b.url; }
      return b.items.length >= 2;
    });
    return { v: NB.DOC_VERSION, blocks: blocks };
  }
```

- [ ] **Step 3: Save**

In `publish()`, replace the body/image part of the payload:

```js
    const doc = currentDoc();
    const check = NB.validateDoc(doc);
    if (!check.ok) { showError(check.error); return; }
    if (NB.toPlainText(doc.blocks, "ru") === "") {
      showError(I18N.t("news.blocksEmpty", window.NEWSPAGE.getLang()));
      return;
    }

    const payload = {
      category: getCat(),
      title_ru: document.querySelector("#neTitleRu").value.trim(),
      title_en: document.querySelector("#neTitleEn").value.trim(),
      published_at: dayToMs(document.querySelector("#neDate").value),
      // body_ru/body_en/image_url НЕ отправляются: их выводит сервер из
      // блоков (см. handle_news_save). Прислать их значило бы завести второй
      // источник правды о том же тексте.
      body_json: doc
    };
    if (editingPost) { payload.id = editingPost.id; }
```

Keep the existing `fetch` call, error handling and `window.NEWSPAGE.reload()` on success. Map the server's `body_json too large` error to `news.blocksTooBig`.

- [ ] **Step 4: Verify the round trip**

Compose a post with a bold paragraph, a quote, a spoiler, one image with a caption and a 3-image album. Save. Confirm on `/news` that it renders identically to the preview. Reopen it in the editor and confirm every block came back. Then check the derived columns:

```bash
/c/xampp/php/php.exe -r "require 'config.php'; \$r=db()->query('SELECT body_ru, image_url, LENGTH(body_json) FROM news ORDER BY id DESC LIMIT 1')->fetch(); var_dump(\$r);"
```

Expected: `body_ru` holds the flat text of every block including captions, `image_url` holds the first picture.

- [ ] **Step 5: Commit**

```bash
git add public_html/js/news-editor.js
git commit -m "feat(news): загрузка, превью и сохранение блочного поста"
```

---

### Task 12: Full suite, cache-bust, and the pull request

**Files:**
- Modify: `public_html/news.php`, `public_html/admin-news.php`

- [ ] **Step 1: Run the whole suite**

```bash
PHP=/c/xampp/php/php.exe bash tests/run_all.sh
```

Expected: `ALL UNIT TESTS PASSED`. If `tests/images_test.php` reports 18 failures, GD is off in this PHP build — rerun that one file as:

```bash
/c/xampp/php/php.exe -d extension=gd tests/images_test.php
```

- [ ] **Step 2: Bump every cache-bust version**

In `public_html/news.php` and `public_html/admin-news.php`, increment the `?v=` on `js/news.js`, `js/news-page.js`, `js/news-blocks.js`, `js/news-editor.js`, `js/i18n.js`, `css/news-design.css` and `css/admin-shell.css` — every file this branch touched. A visitor with the old `js/news-page.js` cached and the new `js/news-blocks.js` would get a card with no body at all.

- [ ] **Step 3: Check the whole feed in the browser, both viewports**

With the preview tools: `/news` at desktop width and at 375px. Confirm the album grid collapses to 2 columns on mobile, the floated image still wraps text, and `read_console_messages` is clean on both.

- [ ] **Step 4: Verify the legacy path one more time**

Temporarily point the local database at a copy with no `body_json` column and load `/news`; the feed must still render every post. This is the production window between the push and the manual `ALTER TABLE`.

- [ ] **Step 5: Commit and open the PR**

```bash
git add public_html/news.php public_html/admin-news.php
git commit -m "chore(news): поднять версии кэша под блочный редактор"
git push -u maknemy feat/news-block-editor
```

Then open the pull request against `master` with `gh pr create`. The body must state, in its own paragraph, that `docs/migrations/2026-08-29-news-blocks.sql` has to be run by hand on the production database after the merge, and that until it is, the feed serves every post through the legacy path.
