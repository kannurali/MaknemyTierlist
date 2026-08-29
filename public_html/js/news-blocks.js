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

  // ------------------------------ Рендер ------------------------------
  // doc — это document (или его заглушка в тестах): модуль обязан работать и
  // в браузере, и в node, поэтому глобального document здесь нет.
  //
  // Ни одна строка ниже не собирает разметку из текста: только createElement,
  // createTextNode и textContent. Это то же правило, по которому написан
  // cardFor() в news-page.js, и единственная причина, по которой тело поста
  // не может стать скриптом, что бы ни лежало в базе.

  function langKeys(lang) {
    return lang === "en" ? { self: "en", other: "ru" } : { self: "ru", other: "en" };
  }

  function pickSpans(holder, keySelf, keyOther) {
    var s = holder[keySelf];
    return (Array.isArray(s) && s.length) ? s : (Array.isArray(holder[keyOther]) ? holder[keyOther] : []);
  }

  // Обёртки флагов, от внутренней к внешней. Порядок фиксирован, чтобы
  // одинаковый спан всегда давал одинаковый DOM — иначе сравнивать превью
  // редактора с лентой было бы нечем.
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
        // Спойлер открывается кликом, значит он управляющий элемент и обязан
        // открываться с клавиатуры тоже.
        spoiler.setAttribute("tabindex", "0");
        spoiler.setAttribute("role", "button");
        spoiler.append(node);
        node = spoiler;
      }
      // Второй рубеж после validateDoc: рендер получает блоки из базы, а не
      // только из редактора, и не имеет права построить javascript:-ссылку,
      // даже если она каким-то путём туда попала. Небезопасный href просто не
      // даёт ссылки — текст при этом остаётся на месте.
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
    var spans = k.self === "ru"
      ? pickSpans(b, "cap_ru", "cap_en")
      : pickSpans(b, "cap_en", "cap_ru");
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
    // width/height — не размер показа (им управляет CSS и style.width выше), а
    // подсказка браузеру, чтобы он зарезервировал место до загрузки байтов и
    // текст под картинкой не прыгал.
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
      // Класс несёт количество, а раскладку выбирает CSS: сетка на 2, 3 и 4+
      // картинок отличается только колонками, и городить это в JS незачем.
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

  var api = {
    DOC_VERSION: DOC_VERSION,
    LIMITS: LIMITS,
    BLOCK_TYPES: BLOCK_TYPES,
    SPAN_FLAGS: SPAN_FLAGS,
    isSafeHref: isSafeHref,
    validateDoc: validateDoc,
    toPlainText: toPlainText,
    firstImage: firstImage,
    spansToFragment: spansToFragment,
    renderBlocks: renderBlocks
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NEWSBLOCKS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
