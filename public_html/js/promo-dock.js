
(function (root) {
  "use strict";

  var MQ = root.matchMedia ? root.matchMedia("(max-width: 640px)") : null;

  var ro = null;
  var lastEl = null;
  var lastDoc = null;
  var bound = false;

  function t(key, fallback) {
    var i18n = root.I18N;
    if (!i18n) { return fallback; }
    var stored = null;
    try { stored = localStorage.getItem("nexus-lang-v1"); } catch (_) {}
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  function teardown(el) {
    el.textContent = "";
    el.hidden = true;
    el.classList.remove("has-link", "is-mini", "is-mini-art", "is-flip");
    el.onclick = null;
    el.onkeydown = null;
    el.removeAttribute("tabindex");
    el.removeAttribute("role");
    document.body.classList.remove("has-promo-dock");
    document.body.style.removeProperty("--ptn-dock-h");
    if (ro) { ro.disconnect(); ro = null; }
    clearTimeout(backT);
    backT = 0;
  }

  var MINI_KEY = "nx-dock-mini-v1";
  var MINI_TTL_MS = 60 * 60 * 1000;
  var miniUntil = 0;
  var backT = 0;
  var backCheck = null;
  var backBound = false;

  function miniLeft() {
    var until = miniUntil;
    try {
      var at = Number(localStorage.getItem(MINI_KEY)) || 0;
      if (at > 0) { until = at + MINI_TTL_MS; }
    } catch (_) {}
    var left = until - Date.now();
    return left > 0 && left <= MINI_TTL_MS ? left : 0;
  }

  function bindBack() {
    if (backBound) { return; }
    backBound = true;
    var check = function () {
      if (backCheck && document.visibilityState !== "hidden") { backCheck(); }
    };
    document.addEventListener("visibilitychange", check);
    root.addEventListener("pageshow", check);
  }

  function miniSave(on) {
    miniUntil = on ? Date.now() + MINI_TTL_MS : 0;
    try {
      if (on) { localStorage.setItem(MINI_KEY, String(Date.now())); }
      else { localStorage.removeItem(MINI_KEY); }
    } catch (_) {}
  }

  function miniGoal(camp, on) {
    try {
      if (typeof root.ym === "function") {
        root.ym(111127188, "reachGoal", on ? "promo_dock_mini" : "promo_dock_full", { id: camp.id });
      }
    } catch (_) {}
  }

  function buildMini(el, camp, tr) {
    var promo = root.PROMO;
    var box = document.createElement("span");
    box.className = "ptn-dock-mini";

    var art = promo.creativeFor(camp, "dockMini");
    var cre = art || promo.creativeFor(camp, "dock");
    if (cre) {
      var img = document.createElement("img");
      img.className = art ? "ptn-dock-mini-art" : "ptn-dock-mini-thumb";
      img.src = promo.srcFor(cre);
      img.alt = "";
      img.decoding = "async";
      img.draggable = false;
      if (cre.w) { img.width = cre.w; }
      if (cre.h) { img.height = cre.h; }
      box.appendChild(img);
    }
    el.classList.toggle("is-mini-art", !!art);

    if (!art) {
      var body = document.createElement("span");
      body.className = "ptn-dock-mini-body";
      var key = promo.dockTextKey(camp) || camp.textKey;
      var text = key ? tr(key) : camp.text;
      if (text) {
        var line = document.createElement("span");
        line.className = "ptn-dock-mini-text";
        if (key) { line.setAttribute("data-i18n", key); }
        line.textContent = text;
        body.appendChild(line);
      }
      if (camp.erid) {
        var erid = document.createElement("span");
        erid.className = "ptn-dock-mini-erid";
        erid.textContent = "erid: " + camp.erid;
        body.appendChild(erid);
        box.classList.add("has-erid");
      }
      box.appendChild(body);
    }

    el.insertBefore(box, el.firstChild);
    return box;
  }

  function mini(el, camp, opts) {
    if (!el || !camp || !root.PROMO) { return; }
    var tr = (opts && opts.t) || function (k) { return k; };
    var open = (opts && opts.open) || null;
    var box = null;
    var flipT = 0;
    el.classList.remove("is-mini-art", "is-flip");

    var tab = document.createElement("button");
    tab.type = "button";
    tab.className = "ptn-dock-tab";
    el.appendChild(tab);

    var set, schedule;

    schedule = function () {
      clearTimeout(backT);
      backT = 0;
      if (!el.classList.contains("is-mini")) { return; }
      var left = miniLeft();
      if (!left) { set(false, "auto"); return; }
      backT = setTimeout(schedule, left + 50);
    };

    set = function (on, how) {
      if (on && !box) { box = buildMini(el, camp, tr); }
      el.classList.toggle("is-mini", on);
      var key = on ? "promo.dockExpand" : "promo.dockCollapse";
      tab.setAttribute("data-i18n-label", key);
      tab.setAttribute("data-i18n-title", key);
      tab.setAttribute("aria-label", tr(key));
      tab.title = tr(key);
      tab.setAttribute("aria-expanded", on ? "false" : "true");
      if (how) {
        miniSave(on);
        if (how === "user") { miniGoal(camp, on); }
        el.classList.remove("is-flip");
        void el.offsetWidth;
        el.classList.add("is-flip");
        clearTimeout(flipT);
        flipT = setTimeout(function () { el.classList.remove("is-flip"); }, 400);
      }
      schedule();
    };

    tab.onclick = function (e) {
      e.stopPropagation();
      set(!el.classList.contains("is-mini"), "user");
    };
    el.onclick = function () {
      if (el.classList.contains("is-mini")) { set(false, "user"); }
      else if (open) { open(); }
    };
    el.onkeydown = function (e) {
      if (e.target !== el || (e.key !== "Enter" && e.key !== " ")) { return; }
      e.preventDefault();
      el.onclick();
    };

    backCheck = schedule;
    bindBack();
    set(miniLeft() > 0);
  }

  function render(el, doc, page) {
    var promo = root.PROMO;
    if (!el || !promo) { return false; }

    lastEl = el;
    lastDoc = doc || null;

    if (MQ && !bound) {
      bound = true;
      var onChange = function () { if (lastEl) { render(lastEl, lastDoc); } };
      if (MQ.addEventListener) { MQ.addEventListener("change", onChange); }
      else if (MQ.addListener) { MQ.addListener(onChange); }
    }

    var narrow = MQ ? MQ.matches : false;
    var list = narrow ? promo.eligible(promo.normalizeDoc(doc), "dock", Date.now(), page) : [];

    if (narrow && !list.length) {
      var house = promo.houseFor("dock", Date.now(), page);
      if (house) { list = [house]; }
    }
    if (!list.length) { teardown(el); return false; }

    var camp = promo.pickWeighted(list, Math.random());
    var cre = camp ? promo.creativeFor(camp, "dock") : null;
    if (!cre || !cre.src) { teardown(el); return false; }

    el.textContent = "";

    var chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.setAttribute("data-i18n", "ad.chip");
    chip.textContent = t("ad.chip", "РЕКЛАМА");
    el.appendChild(chip);

    var img = document.createElement("img");
    img.className = "ptn-dock-img";
    img.src = promo.srcFor(cre);

    img.alt = "";
    img.decoding = "async";
    img.draggable = false;
    if (cre.w) { img.width = cre.w; }
    if (cre.h) { img.height = cre.h; }
    el.appendChild(img);
    promo.stampOn(img, camp, "dock", t);

    if (camp.erid) {
      var erid = document.createElement("span");
      erid.className = "ptn-erid";
      erid.textContent = "erid: " + camp.erid;
      el.appendChild(erid);
    }

    var url = promo.safeHref(camp.href);
    var open = url ? function () { root.open(url, "_blank", "noopener"); } : null;
    el.classList.toggle("has-link", !!url);
    if (url) {
      el.tabIndex = 0;
      el.setAttribute("role", "link");
    } else {
      el.removeAttribute("tabindex");
      el.removeAttribute("role");
    }
    mini(el, camp, { t: function (k) { return t(k, k); }, open: open });

    el.hidden = false;
    document.body.classList.add("has-promo-dock");

    var measure = function () {
      document.body.style.setProperty("--ptn-dock-h", Math.round(el.offsetHeight) + "px");
    };
    measure();

    img.addEventListener("load", measure, { once: true });
    if (root.ResizeObserver) {
      if (ro) { ro.disconnect(); }
      ro = new root.ResizeObserver(measure);
      ro.observe(el);
    }

    return true;
  }

  root.NX_PROMO_DOCK = { render: render, mini: mini };
})(window);
