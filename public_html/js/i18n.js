(function (root) {
  "use strict";

  var LANGS = ["ru", "en"];
  var DEFAULT_LANG = "ru";
  var COOKIE = "nx_lang";
  var LEGACY_KEY = "nexus-lang-v1";
  var MIGRATED_KEY = "nx-lang-migrated";

  function dicts() { return root.I18N_DICTS || {}; }

  function supported() { return LANGS.slice(); }

  function pageLang() {
    var doc = root.document;
    var lang = doc && doc.documentElement ? doc.documentElement.getAttribute("lang") : null;
    return LANGS.indexOf(lang) >= 0 ? lang : DEFAULT_LANG;
  }

  function t(key, lang, vars) {
    var all = dicts();
    var table = all[lang] || all[pageLang()] || all[DEFAULT_LANG] || {};
    if (!Object.prototype.hasOwnProperty.call(table, key)) { return key; }
    var s = table[key];
    if (!vars) { return s; }
    return s.replace(/\{(\w+)\}/g, function (whole, name) {
      return Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : whole;
    });
  }

  function pickLang() { return pageLang(); }

  function url(path, lang) {
    var l = lang || pageLang();
    if (l === DEFAULT_LANG || LANGS.indexOf(l) < 0) { return path; }
    if (path === "/") { return "/" + l; }
    var second = path.charAt(1);
    if (second === "?" || second === "#") { return "/" + l + path.slice(1); }
    return "/" + l + path;
  }

  function stripPrefix(path) {
    var m = /^\/([a-z]{2})(\/.*)?$/.exec(path || "/");
    if (m && m[1] !== DEFAULT_LANG && LANGS.indexOf(m[1]) >= 0) { return m[2] || "/"; }
    return path || "/";
  }

  function onAdmin() {
    var loc = root.location;
    return !!loc && /^\/admin(\/|$)/.test(loc.pathname);
  }

  function chosen() {
    var doc = root.document;
    var m = doc ? /(?:^|;\s*)nx_lang=([a-z]{2})(?:;|$)/.exec(doc.cookie || "") : null;
    return m && LANGS.indexOf(m[1]) >= 0 ? m[1] : null;
  }

  function remember(lang) {
    var secure = root.location && root.location.protocol === "https:" ? "; Secure" : "";
    try { root.document.cookie = COOKIE + "=" + lang + "; Path=/; Max-Age=31536000; SameSite=Lax" + secure; } catch (_) {}
  }

  function go(lang) {
    if (LANGS.indexOf(lang) < 0) { return; }
    remember(lang);
    if (lang === pageLang() || onAdmin()) { return; }
    var loc = root.location;
    loc.assign(url(stripPrefix(loc.pathname), lang) + loc.search + loc.hash);
  }

  function migrate() {
    var legacy = null;
    try {
      if (root.localStorage.getItem(MIGRATED_KEY)) { return; }
      root.localStorage.setItem(MIGRATED_KEY, "1");
      legacy = root.localStorage.getItem(LEGACY_KEY);
    } catch (_) { return; }
    if (legacy === null) { return; }
    try { root.localStorage.removeItem(LEGACY_KEY); } catch (_) {}
    if (chosen() || LANGS.indexOf(legacy) < 0) { return; }
    go(legacy);
  }

  var api = {
    DEFAULT_LANG: DEFAULT_LANG,
    supported: supported,
    pageLang: pageLang,
    pickLang: pickLang,
    t: t,
    url: url,
    stripPrefix: stripPrefix,
    go: go
  };

  if (root.document && root.document.addEventListener) {
    root.document.addEventListener("click", function (e) {
      var el = e.target && e.target.closest ? e.target.closest("#langSwitch [data-lang]") : null;
      if (!el) { return; }
      e.preventDefault();
      e.stopImmediatePropagation();
      go(el.getAttribute("data-lang"));
    }, true);
    migrate();
  }

  if (typeof module === "object" && module.exports) {
    LANGS.forEach(function (lang) { if (!dicts()[lang]) { require("./i18n/" + lang + ".js"); } });
    module.exports = api;
  }
  root.I18N = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
