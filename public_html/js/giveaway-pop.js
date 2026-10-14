(function (root) {
  "use strict";

  var SEEN_KEY = "nx-gw-seen-v1";
  var DELAY_MS = 2000;
  var EVERY_MS = 10 * 60 * 1000;
  var BUSY_RETRY_MS = 4000;
  var BEHIND = [".mk-top", "main", ".mk-foot", "#promoDock"];

  var GW = root.NX_GW;
  var pick = null;
  var timer = 0;
  var pop = null;
  var restoreFocus = null;

  function readSeen() {
    try { return JSON.parse(localStorage.getItem(SEEN_KEY)) || {}; } catch (_) { return {}; }
  }

  function writeSeen(id) {
    var v = readSeen();
    v[id] = Date.now();
    try { localStorage.setItem(SEEN_KEY, JSON.stringify(v)); } catch (_) {}
  }

  function shown(n) {
    if (n.hidden || !n.getClientRects().length) { return false; }
    var cs = root.getComputedStyle(n);
    return cs.visibility !== "hidden" && cs.display !== "none" && +cs.opacity > 0;
  }

  function otherModal() {
    var list = document.querySelectorAll("[aria-modal='true'], #modal, #viewModal, #donateModal");
    for (var i = 0; i < list.length; i++) {
      if (!list[i].classList.contains("gw-pop") && shown(list[i])) { return true; }
    }
    return false;
  }

  function isOpen() { return !!(pop && !pop.hidden); }

  function onKey(e) {
    if (!isOpen()) { return; }
    if (e.key === "Escape") { e.preventDefault(); close(); return; }
    if (e.key !== "Tab") { return; }
    var list = Array.prototype.slice
      .call(pop.querySelectorAll("button, a[href], [tabindex]:not([tabindex='-1'])"))
      .filter(function (n) { return !n.disabled && n.offsetParent !== null; });
    if (!list.length) { return; }
    var first = list[0], last = list[list.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function build() {
    pop = document.createElement("div");
    pop.className = "gw-pop";
    pop.hidden = true;
    pop.setAttribute("role", "dialog");
    pop.setAttribute("aria-modal", "true");
    var box = document.createElement("div");
    box.className = "gw-pop-box";
    box.tabIndex = -1;
    var x = document.createElement("button");
    x.type = "button";
    x.className = "gw-pop-close";
    x.textContent = "✕";
    x.addEventListener("click", close);
    box.appendChild(x);
    pop.appendChild(box);
    pop.addEventListener("click", function (e) { if (e.target === pop) { close(); } });
    document.body.appendChild(pop);
  }

  function fill(join) {
    var box = pop.querySelector(".gw-pop-box");
    var old = box.querySelector(".gw-card");
    if (old) { box.removeChild(old); }
    var x = box.querySelector(".gw-pop-close");
    x.setAttribute("aria-label", GW.t("gw.close"));
    x.title = GW.t("gw.close");
    var cardEl = GW.card(pick, {
      mode: "pop",
      onJoined: function () { schedule(); }
    });
    box.appendChild(cardEl);
    pop.setAttribute("aria-labelledby", "gwTitle" + pick.id);
    if (join && cardEl.gwJoin) { cardEl.gwJoin(); }
  }

  function open(join) {
    clearTimeout(timer);
    if (!pick || isOpen()) { return; }
    if (document.visibilityState !== "visible" || otherModal()) {
      timer = setTimeout(function () { open(join); }, BUSY_RETRY_MS);
      return;
    }
    if (!pop) { build(); }
    fill(join);
    writeSeen(pick.id);
    pop.hidden = false;
    document.body.classList.add("gw-locked");
    restoreFocus = document.activeElement;
    BEHIND.forEach(function (sel) {
      var n = document.querySelector(sel);
      if (n) { n.setAttribute("aria-hidden", "true"); }
    });
    document.addEventListener("keydown", onKey, true);
    setTimeout(function () {
      var box = pop.querySelector(".gw-pop-box");
      if (box) { box.focus({ preventScroll: true }); }
    }, 30);
  }

  function close() {
    if (!isOpen()) { return; }
    pop.hidden = true;
    document.body.classList.remove("gw-locked");
    BEHIND.forEach(function (sel) {
      var n = document.querySelector(sel);
      if (n) { n.removeAttribute("aria-hidden"); }
    });
    document.removeEventListener("keydown", onKey, true);
    if (pick) { writeSeen(pick.id); }
    if (restoreFocus && restoreFocus.focus) { try { restoreFocus.focus(); } catch (_) {} }
    restoreFocus = null;
    schedule();
  }

  function choose(data, s) {
    var now = Date.now() / 1000;
    var live = (data && data.live) || [];
    for (var i = 0; i < live.length; i++) {
      var g = live[i];
      if (g.ends_at > now && !GW.isJoined(g.id, s)) { return g; }
    }
    return null;
  }

  function schedule() {
    clearTimeout(timer);
    if (isOpen()) { return; }
    Promise.all([GW.load(), GW.session()]).then(function (r) {
      pick = choose(r[0], r[1]);
      if (!pick || isOpen()) { return; }
      if (document.visibilityState !== "visible") { return; }
      var last = readSeen()[pick.id] || 0;
      var wait = Math.max(DELAY_MS, last + EVERY_MS - Date.now());
      timer = setTimeout(function () { open(false); }, wait);
    });
  }

  function start() {
    if (!GW || !root.fetch || !root.Promise) { return; }
    if (root.NX_ADMIN_PAGE || /^\/admin(\/|$)/.test(location.pathname)) { return; }
    var resume = GW.takeResume();
    if (resume) {
      Promise.all([GW.load(), GW.session()]).then(function (r) {
        var live = (r[0] && r[0].live) || [];
        pick = null;
        for (var i = 0; i < live.length; i++) { if (live[i].id === resume) { pick = live[i]; } }
        if (pick && !GW.isJoined(pick.id, r[1])) { open(true); } else { schedule(); }
      });
    } else {
      schedule();
    }
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "visible") { schedule(); }
      else { clearTimeout(timer); }
    });
    var relang = function () { if (isOpen()) { fill(false); } };
    document.addEventListener("mk:langchange", relang);
    if (root.MutationObserver) {
      new MutationObserver(relang).observe(document.documentElement, { attributes: true, attributeFilter: ["lang"] });
    }
  }

  root.NX_GW_POP = { isOpen: isOpen };

  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", start); }
  else { start(); }
})(window);
