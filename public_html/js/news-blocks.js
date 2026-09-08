
(function (root) {
  "use strict";

  var DOC_VERSION = 1;

  var LIMITS = {
    blocks: 200,
    albumItems: 10,
    json: 65536,
    listItems: 100,
    spans: 200
  };

  var BLOCK_TYPES = ["p", "quote", "list", "code", "image", "album"];

  var SPAN_FLAGS = ["b", "i", "u", "st", "c", "sp"];

  var BLOCK_KEYS = {
    p:     ["t", "ru", "en"],
    quote: ["t", "ru", "en", "collapsible"],
    list:  ["t", "ordered", "items"],
    code:  ["t", "ru", "en"],
    image: ["t", "url", "w", "h", "pct", "align", "wrap", "cap_ru", "cap_en"],
    album: ["t", "items", "cap_ru", "cap_en"]
  };

  var IMAGE_RE = /^\/images\/[0-9a-f]{40}\.(png|jpg|webp)$/;

  var ALIGNS = ["left", "center", "right"];

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

    if (!Array.isArray(b.items) || b.items.length < 2 || b.items.length > LIMITS.albumItems) { return false; }
    for (var j = 0; j < b.items.length; j++) {
      if (!validImageItem(b.items[j])) { return false; }
    }
    return validSpans(b.cap_ru) && validSpans(b.cap_en);
  }

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

  function toPlainText(blocks, lang) {
    var parts = [];
    for (var i = 0; i < blocks.length; i++) {
      var t = blockText(blocks[i], lang).trim();
      if (t !== "") { parts.push(t); }
    }
    return parts.join("\n\n");
  }

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

  function langKeys(lang) {
    return lang === "en" ? { self: "en", other: "ru" } : { self: "ru", other: "en" };
  }

  function pickSpans(holder, keySelf, keyOther) {
    var s = holder[keySelf];
    return (Array.isArray(s) && s.length) ? s : (Array.isArray(holder[keyOther]) ? holder[keyOther] : []);
  }

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

        spoiler.setAttribute("tabindex", "0");
        spoiler.setAttribute("role", "button");
        spoiler.append(node);
        node = spoiler;
      }

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

    var floated = wrap && align !== "center";
    img.className = "nw-image " + (floated
      ? (align === "left" ? "nw-img-float-left" : "nw-img-float-right")
      : "nw-img-" + align);
    img.setAttribute("src", item.url);
    img.setAttribute("alt", "");
    img.setAttribute("loading", "lazy");
    img.setAttribute("decoding", "async");

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
