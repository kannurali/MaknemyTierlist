
(function (root) {
  "use strict";

  var SEEN_KEY = "nx-ptn-seen-v1";
  var LANG_KEY = "nexus-lang-v1";

  var BEHIND = [".mk-top", "main", ".mk-foot", "#promoDock"];

  var seenCache = null;
  var timer = null;
  var opened = false;
  var camp = null;
  var restoreFocus = null;
  var cfg = null;

  function $(sel) { return document.querySelector(sel); }

  function t(key, fallback) {
    var i18n = root.I18N;
    if (!i18n || !key) { return fallback || ""; }
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  function readSeen() {
    if (seenCache) { return seenCache; }
    try { seenCache = JSON.parse(localStorage.getItem(SEEN_KEY)) || {}; }
    catch (_) { seenCache = {}; }
    return seenCache;
  }
  function writeSeen(v) {
    seenCache = v;
    try { localStorage.setItem(SEEN_KEY, JSON.stringify(v)); } catch (_) {}
  }

  function blocked() {
    if (cfg && cfg.isAdmin) { return true; }
    if (cfg && typeof cfg.busy === "function" && cfg.busy()) { return true; }
    return false;
  }

  function setCopy(el, key, text, fallbackKey) {
    if (key) {
      el.setAttribute("data-i18n", key);
      el.textContent = t(key, "");
      return;
    }
    el.removeAttribute("data-i18n");
    if (text) { el.textContent = text; return; }
    el.textContent = fallbackKey ? t(fallbackKey, "") : "";
  }

  function open(src) {
    if (opened || blocked()) { return; }
    var pop = $("#promoPop");
    if (!pop) { return; }
    opened = true;
    writeSeen(root.PROMO.recordPopupShown(readSeen(), camp.id, Date.now()));

    $("#promoPopImg").src = src;
    setCopy($("#promoPopTitle"), camp.textKey, camp.text, null);

    var cta = $("#promoPopCta");
    var url = root.PROMO.safeHref(camp.href);
    setCopy(cta, camp.ctaKey, camp.cta, "promo.cta");
    cta.hidden = !url;
    if (url) { cta.href = url; }

    var erid = $("#promoPopErid");
    if (erid) {
      erid.textContent = camp.erid ? "erid: " + camp.erid : "";
      erid.hidden = !camp.erid;
    }

    pop.hidden = false;

    document.body.classList.add("ptn-locked");
    restoreFocus = document.activeElement;
    BEHIND.forEach(function (sel) {
      var el = $(sel);
      if (el) { el.setAttribute("aria-hidden", "true"); }
    });
    document.addEventListener("keydown", onKey, true);
    setTimeout(function () { var b = $("#promoPopClose"); if (b) { b.focus(); } }, 20);
  }

  function close() {
    var pop = $("#promoPop");
    if (!pop || pop.hidden) { return; }
    pop.hidden = true;
    document.body.classList.remove("ptn-locked");
    BEHIND.forEach(function (sel) {
      var el = $(sel);
      if (el) { el.removeAttribute("aria-hidden"); }
    });
    document.removeEventListener("keydown", onKey, true);

    var img = $("#promoPopImg");
    if (img) { img.removeAttribute("src"); }
    if (restoreFocus && restoreFocus.focus) { try { restoreFocus.focus(); } catch (_) {} }
    restoreFocus = null;
  }

  function onKey(e) {
    var pop = $("#promoPop");
    if (!pop || pop.hidden) { return; }
    if (e.key === "Escape") { e.preventDefault(); close(); return; }
    if (e.key !== "Tab") { return; }
    var focusables = Array.prototype.slice
      .call(pop.querySelectorAll("button, a[href], [tabindex]:not([tabindex='-1'])"))
      .filter(function (el) { return !el.hidden && el.offsetParent !== null; });
    if (!focusables.length) { return; }
    var first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function tryOpen(pick) {
    if (opened || blocked()) { return; }
    if (document.visibilityState !== "visible") { return; }

    if (!root.PROMO.shouldShowPopup(pick, readSeen(), Date.now())) { return; }

    var cre = root.PROMO.creativeFor(pick, "popup");
    if (!cre) { return; }
    camp = pick;

    var reduced = root.matchMedia && root.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var src = (reduced && cre.anim && cre.poster) ? cre.poster : cre.src;

    var img = $("#promoPopImg");
    var done = false;
    var go = function () { if (!done) { done = true; open(src); } };
    img.src = src;
    if (img.decode) { img.decode().then(go).catch(go); }
    else { img.onload = go; img.onerror = go; }
    setTimeout(go, 4000);
  }

  function schedule() {
    if (!root.PROMO || opened || blocked()) { return; }
    clearTimeout(timer);

    if (document.visibilityState !== "visible") { return; }
    var now = Date.now();
    var pick = root.PROMO.popupPick(cfg.doc, readSeen(), now, Math.random(), cfg.page);
    if (!pick) { return; }
    timer = setTimeout(function () { tryOpen(pick); }, pick.popup.delayMs);
  }

  function mount(opts) {
    cfg = opts || {};
    var pop = $("#promoPop");
    if (!pop || !root.PROMO) { return; }

    if (!pop.dataset.bound) {
      pop.dataset.bound = "1";
      $("#promoPopClose").addEventListener("click", close);
      pop.addEventListener("click", function (e) { if (e.target === pop) { close(); } });
      $("#promoPopCta").addEventListener("click", function () {
        if (!camp) { return; }
        writeSeen(root.PROMO.recordPopupClicked(readSeen(), camp.id, Date.now()));
        try {
          if (typeof root.ym === "function") {
            root.ym(111127188, "reachGoal", "promo_click", { id: camp.id, slot: "popup" });
          }
        } catch (_) {}
        close();
      });
      document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "visible") { schedule(); }
      });
    }
    schedule();
  }

  root.NX_PROMO_POPUP = { mount: mount };
})(window);
