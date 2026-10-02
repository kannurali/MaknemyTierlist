(function () {
  "use strict";

  var API = "/api/stock.php";
  var WATCH = "/api/stock_watch.php";
  var LINK = "/api/tg_link.php";
  var SESSION = "/api/session.php";
  var TG_URL = "https://t.me/";
  var LANG_KEY = "nexus-lang-v1";
  var INVITE_KEY = "nexus-signin-v1";
  var KINDS = ["normal", "mirage"];
  var RARITIES = ["mythical", "legendary", "rare", "uncommon", "common", ""];
  var PERIODS = { normal: 14400, mirage: 7200 };

  var POLL_MS = 60000;
  var SOON_MS = 20000;
  var STALE_AFTER = 900;
  var LINK_POLL_MS = 4000;
  var LINK_POLL_FOR = 15 * 60 * 1000;

  var i18n = window.I18N;
  var lang = "ru";

  var data = null;
  var shown = false;
  var loadFailed = false;
  var skew = 0;
  var pollTimer = 0;
  var loadedAt = 0;

  var watch = {
    shown: false,
    user: false,
    keys: {},
    tg: null,
    url: "",
    waiting: false,
    linkFailed: false,
    saveFailed: false,
    busy: false,
    pollTimer: 0,
    pollUntil: 0
  };

  function $(id) { return document.getElementById(id); }

  function t(key) { return i18n ? i18n.t(key, lang) : key; }

  function fmt(key) {
    var s = t(key);
    for (var i = 1; i < arguments.length; i++) s = s.replace(/%[sd]/, String(arguments[i]));
    return s;
  }

  function el(tag, cls) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    return n;
  }

  function now() { return Math.floor((Date.now() + skew) / 1000); }

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function clock(sec) {
    sec = Math.max(0, Math.floor(sec));
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    return (h ? h + ":" + pad(m) : String(m)) + ":" + pad(s);
  }

  function ago(sec) {
    if (sec < 60) return t("stock.justNow");
    var m = Math.floor(sec / 60);
    if (m < 60) return fmt("stock.ago", fmt("stock.min", m));
    return fmt("stock.ago", fmt("stock.h", Math.floor(m / 60)));
  }

  function price(n) {
    return "$" + Number(n).toLocaleString(lang === "en" ? "en-US" : "ru-RU");
  }

  function catalog() {
    var map = {};
    var list = data && Array.isArray(data.catalog) ? data.catalog : [];
    list.forEach(function (c) { map[c.key] = c; });
    return map;
  }

  function picture(fruit, cat) {
    var pic = el("span", "sk-pic");
    var c = cat[fruit.key];
    if (c && c.icon) {
      var img = el("img");
      img.src = c.icon;
      img.alt = "";
      img.loading = "lazy";
      img.decoding = "async";
      pic.appendChild(img);
    } else {
      pic.classList.add("is-empty");
      pic.textContent = String(fruit.name || "?").charAt(0);
    }
    return pic;
  }

  function byPrice(a, b) {
    return (Number(b.price) || 0) - (Number(a.price) || 0);
  }

  function renderStock() {
    var cat = catalog();
    var fresh = !!data && !shown;
    KINDS.forEach(function (kind) {
      var card = document.querySelector('.sk-dealer[data-kind="' + kind + '"]');
      if (!card) return;
      var list = card.querySelector("[data-list]");
      var s = data && data[kind];
      list.textContent = "";
      list.classList.toggle("is-fresh", fresh);
      if (!data) return;
      if (!s || !Array.isArray(s.fruits) || !s.fruits.length) {
        var none = el("li", "sk-none");
        none.textContent = t("stock.noFruits");
        list.appendChild(none);
        return;
      }
      s.fruits.slice().sort(byPrice).forEach(function (f, i) {
        var li = el("li", "sk-item");
        li.dataset.rarity = f.rarity || "";
        li.style.setProperty("--i", String(i));
        if (watch.keys[f.key]) li.classList.add("is-watched");
        li.appendChild(picture(f, cat));
        var name = el("span", "sk-item-name");
        name.textContent = f.name;
        li.appendChild(name);
        if (f.rarity) {
          var rar = el("span", "sk-item-rarity");
          rar.textContent = t("stock.r." + f.rarity);
          li.appendChild(rar);
        }
        var cost = el("span", "sk-item-price");
        cost.textContent = price(f.price);
        li.appendChild(cost);
        if (watch.keys[f.key]) {
          var star = el("span", "sk-star");
          star.textContent = "★";
          star.setAttribute("role", "img");
          star.setAttribute("aria-label", t("stock.watched"));
          star.title = t("stock.watched");
          li.appendChild(star);
        }
        list.appendChild(li);
      });
    });
    if (data) shown = true;
    tick();
  }

  function setClock(node, text, digits) {
    if (node.dataset.text === text) return;
    node.dataset.text = text;
    node.textContent = "";
    node.classList.toggle("is-text", !digits);
    if (!digits) { node.textContent = text; return; }
    for (var i = 0; i < text.length; i++) {
      var ch = el("span", text.charAt(i) === ":" ? "c" : "d");
      ch.textContent = text.charAt(i);
      node.appendChild(ch);
    }
  }

  function stale() {
    if (!data) return false;
    var n = now();
    return KINDS.some(function (k) {
      return data[k] && data[k].ends && n - data[k].ends > STALE_AFTER;
    });
  }

  function renderState() {
    var box = $("skState");
    if (!box) return;
    var msg = "";
    if (!data) msg = loadFailed ? t("stock.error") : t("stock.loading");
    else if (!data.normal && !data.mirage) msg = t("stock.empty");
    else if (stale()) msg = t("stock.stale");
    box.textContent = msg;
    box.hidden = !msg;
  }

  function tick() {
    var n = now();
    KINDS.forEach(function (kind) {
      var card = document.querySelector('.sk-dealer[data-kind="' + kind + '"]');
      if (!card) return;
      var left = card.querySelector("[data-left]");
      var fill = card.querySelector("[data-fill]");
      var s = data && data[kind];
      var period = (s && s.period) || PERIODS[kind];
      var changing = !!(s && s.ends && s.ends <= n);
      if (!s || !s.ends) setClock(left, "—", false);
      else if (!changing) setClock(left, clock(s.ends - n), true);
      else setClock(left, t("stock.changing"), false);
      var done = 0;
      if (s && s.ends) done = changing ? 1 : Math.min(1, Math.max(0, 1 - (s.ends - n) / period));
      fill.style.width = (done * 100).toFixed(2) + "%";
      card.classList.toggle("is-changing", changing);
      left.setAttribute("aria-label", t("stock.changeIn") + " " + left.textContent);
    });

    var seen = $("skSeen");
    if (seen) {
      var last = 0;
      KINDS.forEach(function (k) { if (data && data[k] && data[k].seen > last) last = data[k].seen; });
      seen.textContent = last ? fmt("stock.seen", ago(Math.max(0, n - last))) : "";
    }
    renderState();
  }

  function nextDelay() {
    var n = now();
    var soon = data && KINDS.some(function (k) {
      return data[k] && data[k].ends && data[k].ends <= n && n - data[k].ends < STALE_AFTER;
    });
    return soon ? SOON_MS : POLL_MS;
  }

  function schedule() {
    clearTimeout(pollTimer);
    if (document.hidden) return;
    pollTimer = setTimeout(load, nextDelay());
  }

  function load() {
    clearTimeout(pollTimer);
    loadedAt = Date.now();
    fetch(API, { cache: "no-store" })
      .then(function (r) {
        var date = Date.parse(r.headers.get("Date") || "");
        if (!isNaN(date) && Math.abs(date - Date.now()) > 5000) skew = date - Date.now();
        return r.ok ? r.json() : null;
      })
      .then(function (d) {
        if (!d || !d.ok) { loadFailed = true; renderState(); return; }
        data = d;
        loadFailed = false;
        renderStock();
        renderWatch();
      })
      .catch(function () { loadFailed = true; renderState(); })
      .then(schedule);
  }

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (d) {
        return { ok: res.ok, data: d };
      });
    }).catch(function () {
      return { ok: false, data: null };
    });
  }

  function invited() {
    try { return localStorage.getItem(INVITE_KEY) === "1"; } catch (_) { return false; }
  }

  function watchCount() {
    return Object.keys(watch.keys).length;
  }

  function renderWatch() {
    var box = $("watch");
    if (!box || !watch.shown) return;
    box.hidden = false;

    var msg = $("skWatchMsg");
    var go = $("skWatchGo");
    var text = "";
    var showGo = false;

    if (!watch.user) {
      text = t("stock.watchLogin");
      go.textContent = t("user.login");
      go.href = window.MKAuth ? window.MKAuth.startUrl() : "/api/roblox_start.php";
      go.removeAttribute("target");
      go.removeAttribute("rel");
      showGo = true;
    } else if (!watch.tg || !watch.tg.linked) {
      if (watch.waiting) text = t("stock.watchWait");
      else if (watch.linkFailed) text = t("stock.watchFailed");
      else text = t("stock.watchConnect");
      go.textContent = t("stock.connect");
      go.href = watch.url || "#";
      go.target = "_blank";
      go.rel = "noopener";
      go.classList.toggle("is-busy", !watch.url);
      showGo = !watch.waiting;
    } else {
      text = fmt("stock.watchOn", watch.tg.name ? " (" + watch.tg.name + ")" : "", watchCount());
    }
    if (watch.saveFailed) text += " " + t("stock.watchError");
    msg.textContent = text;
    go.hidden = !showGo;

    renderCatalog();
  }

  function renderCatalog() {
    var box = $("skCatalog");
    if (!box) return;
    var items = data && Array.isArray(data.catalog) ? data.catalog : [];
    var cat = catalog();
    box.textContent = "";
    RARITIES.forEach(function (r) {
      var group = items.filter(function (c) { return (c.rarity || "") === r; });
      if (!group.length) return;
      var wrap = el("div", "sk-group");
      wrap.dataset.rarity = r;
      var title = el("h3", "sk-group-title");
      title.textContent = t("stock.g." + (r || "other"));
      wrap.appendChild(title);
      var list = el("ul", "sk-picks");
      group.forEach(function (c) {
        var li = el("li");
        var b = el("button", "sk-pick");
        b.type = "button";
        b.dataset.key = c.key;
        var on = !!watch.keys[c.key];
        b.setAttribute("aria-pressed", on ? "true" : "false");
        if (!watch.user) b.setAttribute("aria-disabled", "true");
        b.appendChild(picture({ key: c.key, name: c.name }, cat));
        var name = el("span", "sk-pick-name");
        name.textContent = c.name;
        b.appendChild(name);
        li.appendChild(b);
        list.appendChild(li);
      });
      wrap.appendChild(list);
      box.appendChild(wrap);
    });
  }

  function toggle(key) {
    var want = !watch.keys[key];
    if (want) watch.keys[key] = true; else delete watch.keys[key];
    watch.saveFailed = false;
    renderWatch();
    renderStock();
    post(WATCH, { action: "set", fruit: key, on: want }).then(function (r) {
      var list = r.ok && r.data && r.data.ok && Array.isArray(r.data.watch) ? r.data.watch : null;
      if (list) {
        watch.keys = {};
        list.forEach(function (k) { watch.keys[k] = true; });
      } else {
        if (want) delete watch.keys[key]; else watch.keys[key] = true;
        watch.saveFailed = true;
      }
      renderWatch();
      renderStock();
    });
  }

  function prepareLink() {
    if (watch.busy || (watch.tg && watch.tg.linked)) return;
    watch.busy = true;
    watch.url = "";
    post(LINK, { action: "link", lang: lang }).then(function (r) {
      watch.busy = false;
      var next = r.ok && r.data && r.data.ok && typeof r.data.url === "string" ? r.data.url : "";
      if (next.indexOf(TG_URL) === 0) {
        watch.url = next;
        watch.linkFailed = false;
      } else {
        watch.linkFailed = true;
      }
      renderWatch();
    });
  }

  function stopLinkPoll() {
    if (watch.pollTimer) { clearTimeout(watch.pollTimer); watch.pollTimer = 0; }
  }

  function checkLink() {
    stopLinkPoll();
    post(LINK, { action: "status" }).then(function (r) {
      var s = r.ok && r.data && r.data.ok ? r.data.tg : null;
      if (s && s.linked) {
        watch.tg = s;
        watch.waiting = false;
        watch.url = "";
        renderWatch();
        return;
      }
      scheduleLink();
    });
  }

  function scheduleLink() {
    stopLinkPoll();
    if ((watch.tg && watch.tg.linked) || !watch.waiting || Date.now() > watch.pollUntil) return;
    watch.pollTimer = setTimeout(checkLink, LINK_POLL_MS);
  }

  function showWatch() {
    watch.shown = true;
    renderWatch();
    if (location.hash === "#watch") {
      var box = $("watch");
      if (box && box.scrollIntoView) box.scrollIntoView({ block: "start" });
    }
  }

  function initWatch() {
    fetch(SESSION, { cache: "no-store" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s || !s.roblox) return;
        if (!s.user) {
          if (!s.roblox_public && !invited()) return;
          watch.user = false;
          showWatch();
          return;
        }
        watch.user = true;
        post(WATCH, { action: "get" }).then(function (r) {
          if (!r.ok || !r.data || !r.data.ok) return;
          watch.tg = r.data.tg || null;
          if (!watch.tg || !watch.tg.on) return;
          watch.keys = {};
          (r.data.watch || []).forEach(function (k) { watch.keys[k] = true; });
          showWatch();
          renderStock();
          if (!watch.tg.linked) prepareLink();
        });
      })
      .catch(function () {});
  }

  var catalogList = $("skCatalog");
  if (catalogList) {
    catalogList.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest(".sk-pick") : null;
      if (!b) return;
      if (!watch.user) {
        var go = $("skWatchGo");
        if (go && !go.hidden) go.focus();
        return;
      }
      toggle(b.dataset.key);
    });
  }

  var goBtn = $("skWatchGo");
  if (goBtn) {
    goBtn.addEventListener("click", function (e) {
      if (!watch.user) return;
      if (!watch.url) { e.preventDefault(); prepareLink(); return; }
      watch.waiting = true;
      watch.linkFailed = false;
      watch.pollUntil = Date.now() + LINK_POLL_FOR;
      renderWatch();
      scheduleLink();
    });
  }

  function apply(next) {
    if (!i18n) return;
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) {}
    }
    document.documentElement.lang = lang;
    document.querySelectorAll("[data-i18n]").forEach(function (n) {
      n.textContent = i18n.t(n.dataset.i18n, lang);
    });
    document.querySelectorAll("[data-i18n-title]").forEach(function (n) {
      n.title = i18n.t(n.dataset.i18nTitle, lang);
    });
    document.querySelectorAll("[data-i18n-label]").forEach(function (n) {
      n.setAttribute("aria-label", i18n.t(n.dataset.i18nLabel, lang));
    });
    document.querySelectorAll("#langSwitch [data-lang]").forEach(function (b) {
      var on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    });
    renderStock();
    renderWatch();
    document.dispatchEvent(new CustomEvent("mk:langchange", { detail: { lang: lang } }));
  }

  if (i18n) {
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    lang = i18n.pickLang(stored, navigator.language);
    var langBox = $("langSwitch");
    if (langBox) {
      langBox.addEventListener("click", function (e) {
        var b = e.target.closest("[data-lang]");
        if (b) apply(b.dataset.lang);
      });
    }
    apply();
  }

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) { clearTimeout(pollTimer); return; }
    if (Date.now() - loadedAt > 30000) load(); else schedule();
    if (watch.waiting && !(watch.tg && watch.tg.linked)) checkLink();
  });

  setInterval(tick, 1000);
  load();
  initWatch();
})();
