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

  // Тот же белый список формы, что NEWS_IMAGE_RE в api/news_save.php: чужой
  // хост, javascript: и обход каталога отсекаются по построению, а не
  // перечислением опасного.
  var IMAGE_RE = /^\/images\/[0-9a-f]{40}\.(png|jpg|webp)$/;

  var ALIGNS = ["left", "center", "right"];

  // Схема ссылки. Проверяется без учёта регистра, потому что "JavaScript:"
  // браузер выполнит так же охотно, как "javascript:".
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

  function validImageItem(im) {
    return isPlainObject(im)
      && keysAllowed(im, ["url", "w", "h"])
      && typeof im.url === "string" && IMAGE_RE.test(im.url)
      && isPosInt(im.w, 1, 65535) && isPosInt(im.h, 1, 65535);
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
    if (b.t === "image") {
      return validImageItem({ url: b.url, w: b.w, h: b.h })
        && isPosInt(b.pct, 10, 100)
        && ALIGNS.indexOf(b.align) !== -1
        && typeof b.wrap === "boolean"
        && validSpans(b.cap_ru) && validSpans(b.cap_en);
    }
    // album
    if (!Array.isArray(b.items) || b.items.length < 2 || b.items.length > LIMITS.albumItems) { return false; }
    for (var j = 0; j < b.items.length; j++) {
      if (!validImageItem(b.items[j])) { return false; }
    }
    return validSpans(b.cap_ru) && validSpans(b.cap_en);
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
    var self = lang === "en" ? "en" : "ru";
    var other = self === "ru" ? "en" : "ru";
    if (b.t === "p" || b.t === "quote") { return spansText(b[self], b[other]); }
    if (b.t === "code") { return String(b[self] || b[other] || ""); }
    if (b.t === "list") {
      var lines = [];
      for (var i = 0; i < b.items.length; i++) {
        lines.push(spansText(b.items[i][self], b.items[i][other]));
      }
      return lines.join("\n");
    }
    if (b.t === "image" || b.t === "album") {
      return self === "ru"
        ? spansText(b.cap_ru, b.cap_en)
        : spansText(b.cap_en, b.cap_ru);
    }
    return "";
  }

  // Плоский текст всего поста — он уезжает в колонки body_ru/body_en и оттуда
  // в превью ссылки (api/lib/og.php) и в noscript-тело news.php.
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
