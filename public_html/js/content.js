
(function (root) {
  "use strict";

  function textFor(item, base, lang) {
    if (!item || !base) { return ""; }
    var ru = String(item[base] || "").trim();
    var en = String(item[base + "En"] || "").trim();
    return lang === "en" ? (en || ru) : (ru || en);
  }

  function descFor(item, lang) { return textFor(item, "desc", lang); }

  var api = { descFor: descFor, textFor: textFor };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CONTENT = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
