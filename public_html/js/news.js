
(function (root) {
  "use strict";

  var CATEGORIES = [
    { key: "tierlist", i18n: "news.catTierlist", cls: "c-tierlist" },
    { key: "game",     i18n: "news.catGame",     cls: "c-game" },
    { key: "project",  i18n: "news.catProject",  cls: "c-project" }
  ];

  function isCategory(key) {
    for (var i = 0; i < CATEGORIES.length; i++) {
      if (CATEGORIES[i].key === key) { return true; }
    }
    return false;
  }

  var ALIGNS = [
    { key: "left",   i18n: "news.alignLeft" },
    { key: "center", i18n: "news.alignCenter" },
    { key: "right",  i18n: "news.alignRight" }
  ];

  function isAlign(key) {
    for (var i = 0; i < ALIGNS.length; i++) {
      if (ALIGNS[i].key === key) { return true; }
    }
    return false;
  }

  function newsImageCap(pct) {
    return Math.max(256, Math.min(1280, Math.ceil(16.8 * pct)));
  }

  function pickLang(post, lang) {
    if (!post) { return { title: "", body: "" }; }
    var tRu = String(post.title_ru || "").trim();
    var tEn = String(post.title_en || "").trim();
    var bRu = String(post.body_ru  || "").trim();
    var bEn = String(post.body_en  || "").trim();
    return lang === "en"
      ? { title: tEn || tRu, body: bEn || bRu }
      : { title: tRu || tEn, body: bRu || bEn };
  }

  function formatDate(ms) {
    var d = new Date(Number(ms) || 0);
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    return pad(d.getDate()) + "." + pad(d.getMonth() + 1) + "." + d.getFullYear();
  }

  function toParagraphs(text) {
    return String(text == null ? "" : text)
      .replace(/\r\n?/g, "\n")
      .split(/\n\s*\n/)
      .map(function (s) { return s.trim(); })
      .filter(function (s) { return s !== ""; });
  }

  function cropToSourceRect(frame, zoom, pan, image) {
    var z = zoom > 0 ? zoom : 1;

    var rawW = frame.w / z;
    var rawH = frame.h / z;
    var sw = Math.min(Math.max(rawW, 0), image.width);
    var sh = Math.min(Math.max(rawH, 0), image.height);

    var rawX = (frame.x - pan.x) / z;
    var rawY = (frame.y - pan.y) / z;
    var sx = Math.min(Math.max(rawX, 0), image.width - sw);
    var sy = Math.min(Math.max(rawY, 0), image.height - sh);

    return { sx: sx, sy: sy, sw: sw, sh: sh };
  }

  var api = {
    CATEGORIES: CATEGORIES,
    isCategory: isCategory,
    ALIGNS: ALIGNS,
    isAlign: isAlign,
    newsImageCap: newsImageCap,
    pickLang: pickLang,
    formatDate: formatDate,
    toParagraphs: toParagraphs,
    cropToSourceRect: cropToSourceRect
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NEWS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
