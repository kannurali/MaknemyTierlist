(function (root) {
  "use strict";

  var API = "/api/giveaways.php";
  var ENTER = "/api/giveaway_enter.php";
  var LINK = "/api/tg_link.php";
  var JOINED_KEY = "nx-gw-joined-v1";
  function siteUrl(path) { return root.I18N && root.I18N.url ? root.I18N.url(path) : path; }
  var RESUME_KEY = "nx-gw-resume-v1";
  var FROM_BOT_KEY = "nx-gw-from-bot-v1";
  var FROM_CARD_KEY = "nx-gw-from-card-v1";
  var COND_KEY = "nx-gw-cond-v1";
  var TEST_KEY = "nx-gw-test-v1";
  var SESSION_WAIT = 5000;
  var LINK_POLL_MS = 3000;
  var LINK_POLL_FOR = 10 * 60 * 1000;
  var CROWN = "/assets/design/giveaways/crown.webp";
  var MEDAL = "/assets/design/giveaways/medal.webp";

  var dataPromise = null;
  var sessionPromise = null;
  var tgPromise = null;
  var ticker = 0;
  var uid = 0;

  function lang() {
    return root.I18N ? root.I18N.pageLang() : "ru";
  }

  function t(key, vars) {
    return root.I18N ? root.I18N.t(key, lang(), vars) : key;
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function pick(obj) {
    if (!obj) { return ""; }
    var l = lang();
    return (l === "ru" ? obj.ru || obj.en : obj.en || obj.ru) || "";
  }

  function testMode() {
    var m = /[?&]test(?:=([^&#]*))?(?:&|$)/.exec(location.search);
    var on = !!m && m[1] !== "0";
    try {
      if (m) {
        if (on) { localStorage.setItem(TEST_KEY, "1"); } else { localStorage.removeItem(TEST_KEY); }
      }
      return localStorage.getItem(TEST_KEY) === "1";
    } catch (_) { return on; }
  }

  var TEST = testMode();

  function load(fresh) {
    if (!dataPromise || fresh) {
      dataPromise = fetch(API + "?" + (TEST ? "test=1&" : "") + "lang=" + lang(), { cache: fresh ? "no-store" : "default" })
        .then(function (r) { return r.ok ? r.json() : { live: [], past: [] }; })
        .catch(function () { return { live: [], past: [] }; });
    }
    return dataPromise;
  }

  function session() {
    if (sessionPromise) { return sessionPromise; }
    sessionPromise = new Promise(function (resolve) {
      if (root.MK_SESSION !== undefined) { resolve(root.MK_SESSION); return; }
      var done = false;
      var finish = function (s) { if (!done) { done = true; resolve(s || null); } };
      document.addEventListener("mk:session", function (e) { finish(e.detail); }, { once: true });
      setTimeout(function () { finish(root.MK_SESSION); }, SESSION_WAIT);
    });
    return sessionPromise;
  }

  function readJoined() {
    try {
      var v = JSON.parse(localStorage.getItem(JOINED_KEY));
      return Array.isArray(v) ? v : [];
    } catch (_) { return []; }
  }

  function markJoined(id) {
    var list = readJoined().filter(function (x) { return x !== id; });
    list.push(id);
    try { localStorage.setItem(JOINED_KEY, JSON.stringify(list.slice(-50))); } catch (_) {}
  }

  function isJoined(id, s) {
    if (s && s.user && Array.isArray(s.giveaways) && s.giveaways.indexOf(id) >= 0) { return true; }
    return readJoined().indexOf(id) >= 0;
  }

  function readConds() {
    try {
      var v = JSON.parse(localStorage.getItem(COND_KEY));
      return Array.isArray(v) ? v : [];
    } catch (_) { return []; }
  }

  function markCond(key) {
    var list = readConds().filter(function (x) { return x !== key; });
    list.push(key);
    try { localStorage.setItem(COND_KEY, JSON.stringify(list.slice(-100))); } catch (_) {}
  }

  function setResume(id) {
    try { sessionStorage.setItem(RESUME_KEY, String(id)); } catch (_) {}
  }

  function takeResume() {
    var v = null;
    try { v = sessionStorage.getItem(RESUME_KEY); sessionStorage.removeItem(RESUME_KEY); } catch (_) {}
    var n = v ? parseInt(v, 10) : 0;
    return n > 0 ? n : 0;
  }

  function readFromBot() {
    try { return JSON.parse(sessionStorage.getItem(FROM_BOT_KEY)) || {}; } catch (_) { return {}; }
  }

  function noteFromBot() {
    var m = /^#g(\d+)-tg$/.exec(location.hash);
    if (!m) { return; }
    var v = readFromBot();
    v[m[1]] = 1;
    try { sessionStorage.setItem(FROM_BOT_KEY, JSON.stringify(v)); } catch (_) {}
  }

  function readFromCard() {
    try { return JSON.parse(sessionStorage.getItem(FROM_CARD_KEY)) || {}; } catch (_) { return {}; }
  }

  function noteFromCard(id, src) {
    var v = readFromCard();
    v[String(id)] = src;
    try { sessionStorage.setItem(FROM_CARD_KEY, JSON.stringify(v)); } catch (_) {}
  }

  function source(id, mode) {
    if (readFromBot()[String(id)]) { return "tg"; }
    if (mode !== "page") { return "pop"; }
    var c = readFromCard()[String(id)];
    return typeof c === "string" && /^c-[a-z]{3,6}$/.test(c) ? c : "page";
  }

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: r.status === 429 ? "rate_limited" : "net" }; });
    });
  }

  function tgStatus(fresh) {
    if (!tgPromise || fresh) {
      tgPromise = post(LINK, { action: "status" })
        .then(function (r) { return r && r.ok && r.tg ? r.tg : null; })
        .catch(function () { return null; });
    }
    return tgPromise;
  }

  function tgLinked(tg) {
    tgPromise = Promise.resolve(tg);
    document.dispatchEvent(new CustomEvent("mk:tg-linked", { detail: tg }));
  }

  function openWin() {
    var w = null;
    try { w = root.open("", "_blank"); } catch (_) { w = null; }
    if (w) { try { w.opener = null; } catch (_) {} }
    return w;
  }

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function num(n) {
    try { return n.toLocaleString(root.I18N ? root.I18N.locale() : "ru-RU"); } catch (_) { return String(n); }
  }

  function clockText(sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var hms = pad(h) + ":" + pad(m) + ":" + pad(s);
    return d ? t("gw.days", { n: d }) + " " + hms : hms;
  }

  function date(ts) {
    var msk = lang() === "ru";
    var opts = { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" };
    if (msk) { opts.timeZone = "Europe/Moscow"; } else { opts.timeZoneName = "short"; }
    var s;
    try { s = new Date(ts * 1000).toLocaleString(root.I18N ? root.I18N.locale() : "ru-RU", opts); }
    catch (_) { s = new Date(ts * 1000).toLocaleString(); }
    return msk ? s + " " + t("gw.msk") : s;
  }

  function timeShare(from, to, now) {
    if (!(from > 0) || !(to > from)) { return 0; }
    return Math.min(1, Math.max(0, (now - from) / (to - from)));
  }

  function tick() {
    var now = Date.now() / 1000;
    document.querySelectorAll(".gw-bar-fill[data-gw-to]").forEach(function (n) {
      var share = timeShare(+n.getAttribute("data-gw-from"), +n.getAttribute("data-gw-to"), now);
      n.style.width = (share * 100).toFixed(2) + "%";
    });
    document.querySelectorAll("[data-gw-ends]").forEach(function (n) {
      var left = +n.getAttribute("data-gw-ends") - now;
      if (left > 0) {
        n.textContent = clockText(left);
        return;
      }
      n.removeAttribute("data-gw-ends");
      n.textContent = clockText(0);
      var card = n.closest(".gw-card");
      if (card && card.gwEnded) { card.gwEnded(); }
      document.dispatchEvent(new CustomEvent("mk:gw-ended"));
    });
  }

  function startTicker() {
    if (ticker) { return; }
    ticker = setInterval(tick, 1000);
  }

  var SVG_NS = "http://www.w3.org/2000/svg";

  function svg(box, parts, cls) {
    var s = document.createElementNS(SVG_NS, "svg");
    if (box) { s.setAttribute("viewBox", box); }
    s.setAttribute("aria-hidden", "true");
    s.setAttribute("focusable", "false");
    if (cls) { s.setAttribute("class", cls); }
    parts.forEach(function (p) {
      var n = document.createElementNS(SVG_NS, p[0]);
      Object.keys(p[1]).forEach(function (k) { n.setAttribute(k, p[1][k]); });
      s.appendChild(n);
    });
    return s;
  }

  function giftIcon(cls) {
    return svg("0 0 48 48", [
      ["path", { "class": "gw-gift-box", d: "M9 24h30v14a4 4 0 0 1-4 4H13a4 4 0 0 1-4-4z" }],
      ["rect", { x: "6", y: "15.5", width: "36", height: "8.5", rx: "2.5" }],
      ["path", { d: "M9 24h30v14a4 4 0 0 1-4 4H13a4 4 0 0 1-4-4z" }],
      ["path", { d: "M24 15.5V42" }],
      ["path", { d: "M24 15.5c-2.4-5.8-11-8.6-11-3.3 0 3 5.6 3.3 11 3.3z" }],
      ["path", { d: "M24 15.5c2.4-5.8 11-8.6 11-3.3 0 3-5.6 3.3-11 3.3z" }]
    ], "gw-gift" + (cls ? " " + cls : ""));
  }

  function userIcon() {
    return svg("0 0 38 38", [
      ["path", { d: "M19.0632 3.16699C14.6909 3.16699 11.1465 6.71141 11.1465 11.0837C11.1465 15.4559 14.6909 19.0003 19.0632 19.0003C23.4354 19.0003 26.9798 15.4559 26.9798 11.0837C26.9798 6.71141 23.4354 3.16699 19.0632 3.16699Z" }],
      ["path", { d: "M14.3134 20.583C11.6807 20.583 9.14896 21.784 7.66525 23.592C6.91222 24.5097 6.38285 25.6451 6.33675 26.9036C6.28968 28.1887 6.75357 29.4431 7.71026 30.5375C10.0418 33.2048 13.7644 34.833 19.0634 34.833C24.3624 34.833 28.085 33.2048 30.4166 30.5375C31.3733 29.4431 31.8372 28.1887 31.7901 26.9036C31.744 25.6451 31.2146 24.5097 30.4616 23.592C28.9779 21.784 26.4461 20.583 23.8134 20.583H14.3134Z" }]
    ], "gw-stat-svg");
  }

  function clockIcon() {
    return svg("0 0 38 38", [
      ["circle", { cx: "19", cy: "20", r: "13" }],
      ["path", { d: "M19 12.5V20.5L24 23.5" }],
      ["path", { d: "M15.5 4H22.5" }]
    ], "gw-stat-svg is-clock");
  }

  function overBadge() {
    var b = el("span", "gw-over");
    b.setAttribute("role", "img");
    b.setAttribute("aria-label", t("gw.over"));
    b.appendChild(svg("0 0 70 70", [
      ["path", { "fill-rule": "evenodd", d: "M49.0852 20.2112C41.112 12.238 28.1849 12.238 20.2117 20.2112C12.2385 28.1844 12.2385 41.1115 20.2117 49.0847C28.1849 57.0579 41.112 57.0579 49.0852 49.0847C57.0584 41.1115 57.0584 28.1844 49.0852 20.2112ZM41.8668 27.4296C42.6641 28.2269 42.6641 29.5196 41.8668 30.3169L37.5358 34.6479L41.8668 38.979C42.6641 39.7763 42.6641 41.069 41.8668 41.8663C41.0695 42.6636 39.7768 42.6636 38.9795 41.8663L34.6484 37.5353L30.3174 41.8663C29.5201 42.6636 28.2274 42.6636 27.4301 41.8663C26.6327 41.069 26.6327 39.7763 27.4301 38.979L31.7611 34.6479L27.4301 30.3169C26.6327 29.5196 26.6327 28.2269 27.4301 27.4296C28.2274 26.6322 29.5201 26.6322 30.3174 27.4296L34.6484 31.7606L38.9795 27.4296C39.7768 26.6322 41.0695 26.6322 41.8668 27.4296Z" }]
    ], "gw-over-svg"));
    return b;
  }

  function gradient(id) {
    var defs = document.createElementNS(SVG_NS, "defs");
    var g = document.createElementNS(SVG_NS, "linearGradient");
    g.setAttribute("id", id);
    g.setAttribute("x1", "0");
    g.setAttribute("y1", "0");
    g.setAttribute("x2", "0");
    g.setAttribute("y2", "1");
    [["0", "#8400ff"], ["1", "#bb00ff"]].forEach(function (s) {
      var stop = document.createElementNS(SVG_NS, "stop");
      stop.setAttribute("offset", s[0]);
      stop.setAttribute("stop-color", s[1]);
      g.appendChild(stop);
    });
    defs.appendChild(g);
    return defs;
  }

  function frame(cls, rx) {
    var id = "gwGrad" + (++uid);
    var s = svg("", [], cls);
    s.appendChild(gradient(id));
    var r = document.createElementNS(SVG_NS, "rect");
    r.setAttribute("width", "100%");
    r.setAttribute("height", "100%");
    r.setAttribute("rx", rx);
    r.setAttribute("stroke", "url(#" + id + ")");
    s.appendChild(r);
    return s;
  }

  function plural(n) {
    return root.I18N ? root.I18N.plural(n) : "many";
  }

  function prizeCount(p) {
    return p && p.count > 0 ? p.count : 1;
  }

  function themed() {
    return !!document.querySelector("link[rel='stylesheet'][href*='css/hw/']");
  }

  function giftArt() {
    var img = el("img", "gw-gift-art");
    img.src = themed() ? "/assets/halloween/card-giveaways.webp" : "/assets/design/home/card-giveaways.webp";
    img.alt = "";
    img.decoding = "async";
    img.addEventListener("error", function () {
      if (img.parentNode) { img.parentNode.replaceChild(giftIcon(), img); }
    });
    return img;
  }

  function prizePic(p, cls) {
    var box = el("span", cls);
    var gift = function () {
      box.textContent = "";
      box.classList.add("is-gift");
      box.appendChild(giftArt());
    };
    if (p && p.icon) {
      var img = el("img");
      img.src = p.icon;
      img.alt = "";
      img.decoding = "async";
      img.addEventListener("error", gift);
      box.appendChild(img);
    } else {
      gift();
    }
    return box;
  }

  function renderMedia(g) {
    var media = el("div", "gw-media");
    if (g.image && g.image.url) {
      var img = el("img", "gw-img");
      img.src = g.image.url;
      img.alt = "";
      if (g.image.w > 0 && g.image.h > 0) {
        img.width = g.image.w;
        img.height = g.image.h;
      }
      img.decoding = "async";
      media.appendChild(img);
      return media;
    }
    var icons = (g.prizes || []).filter(function (p) { return p && p.icon; }).slice(0, 3);
    media.className = "gw-media is-stage";
    var stage = el("div", "gw-stage is-n" + icons.length);
    if (icons.length === 3) { icons = [icons[1], icons[0], icons[2]]; }
    icons.forEach(function (p, i) {
      var main = icons.length === 3 ? i === 1 : icons.length === 1;
      stage.appendChild(prizePic(p, "gw-stage-pic" + (main ? " is-main" : "")));
    });
    if (!icons.length) {
      var lone = el("span", "gw-stage-pic is-main is-gift");
      lone.appendChild(giftArt());
      stage.appendChild(lone);
    }
    media.appendChild(stage);
    return media;
  }

  function renderStat(g) {
    var live = g.phase === "live";
    var row = el("div", "gw-stat");
    var byCount = g.end_by === "entries" || (!live && g.max_entries);
    var ico = el("span", "gw-stat-ico");
    ico.appendChild(byCount ? userIcon() : clockIcon());
    row.appendChild(ico);
    var pill = el("span", "gw-stat-pill");
    var text = el("span", "gw-stat-text", t("gw.stat") + " " + t("gw.statPill"));
    var val = el("span", "gw-stat-val");
    if (byCount) {
      var need = live ? Math.max(0, (g.max_entries || 0) - (g.entries || 0)) : 0;
      val.textContent = t("gw.peopleLeft." + plural(need), { n: num(need) });
    } else if (live) {
      val.setAttribute("data-gw-ends", String(g.ends_at));
      val.textContent = clockText(g.ends_at - Date.now() / 1000);
    } else {
      val.textContent = clockText(0);
    }
    pill.appendChild(text);
    row.appendChild(pill);
    var island = el("span", "gw-stat-pill is-val");
    island.appendChild(val);
    row.appendChild(island);
    return row;
  }

  function renderBar(g) {
    var n = g.entries || 0;
    var max = g.max_entries || 0;
    var bar = el("div", "gw-bar");
    var fill = el("span", "gw-bar-fill");
    var share = 1;
    if (g.phase === "live" && max) {
      share = Math.min(1, n / max);
      bar.setAttribute("role", "progressbar");
      bar.setAttribute("aria-valuemin", "0");
      bar.setAttribute("aria-valuemax", String(max));
      bar.setAttribute("aria-valuenow", String(Math.min(n, max)));
      bar.setAttribute("aria-label", t("gw.entriesOf", { n: n, max: max }));
    } else if (g.phase === "live") {
      share = timeShare(g.published_at, g.ends_at, Date.now() / 1000);
      fill.setAttribute("data-gw-from", String(g.published_at || 0));
      fill.setAttribute("data-gw-to", String(g.ends_at));
      bar.setAttribute("aria-hidden", "true");
    } else {
      bar.setAttribute("aria-hidden", "true");
    }
    fill.style.width = (share * 100).toFixed(2) + "%";
    bar.appendChild(fill);
    return bar;
  }

  function winnerLabel(w) {
    return w.display || (w.name ? "@" + w.name : "#" + w.id);
  }

  function avatar(w, cls) {
    if (w.avatar) {
      var img = el("img", cls);
      img.src = w.avatar;
      img.alt = "";
      img.loading = "lazy";
      img.referrerPolicy = "no-referrer";
      return img;
    }
    return el("span", cls + " is-empty", winnerLabel(w).replace(/^[@#]/, "").charAt(0).toUpperCase());
  }

  function renderWin(w, total) {
    var box = el("div", "gw-wins");
    if (!w) {
      if (total) { box.setAttribute("aria-hidden", "true"); }
      else { box.appendChild(el("span", "gw-win-none", t("gw.noWinner"))); }
      return box;
    }
    var a = el("a", "gw-win");
    a.href = siteUrl("/profile?id=" + encodeURIComponent(w.id));
    a.appendChild(avatar(w, "gw-win-ava"));
    a.appendChild(el("span", "gw-win-name", winnerLabel(w)));
    box.appendChild(a);
    box.appendChild(el("span", "gw-win-line"));
    return box;
  }

  function winGroups(g) {
    var groups = (g.prizes || []).map(function () { return []; });
    (g.winners || []).forEach(function (w) { if (groups[w.prize]) { groups[w.prize].push(w); } });
    return groups;
  }

  function placeIcon(i, cls) {
    var img = el("img", cls + (i ? "" : " is-first"));
    img.src = i ? MEDAL : CROWN;
    img.alt = "";
    img.width = i ? 33 : 40;
    img.height = 38;
    img.decoding = "async";
    return img;
  }

  function prizeName(p) {
    var n = prizeCount(p);
    return p.name + (n > 1 ? " " + String.fromCharCode(215) + n : "");
  }

  var winPop = null;
  var winBack = null;

  function onWinKey(e) {
    if (e.key === "Escape") { e.preventDefault(); closeWinners(); }
  }

  function closeWinners() {
    if (!winPop) { return; }
    winPop.parentNode.removeChild(winPop);
    winPop = null;
    document.body.classList.remove("gw-locked");
    document.removeEventListener("keydown", onWinKey, true);
    if (winBack && winBack.focus) { try { winBack.focus({ preventScroll: true }); } catch (_) {} }
    winBack = null;
  }

  function openWinners(g) {
    closeWinners();
    winBack = document.activeElement;
    var pop = el("div", "gw-pop gw-wl");
    pop.setAttribute("role", "dialog");
    pop.setAttribute("aria-modal", "true");
    pop.setAttribute("aria-labelledby", "gwWlTitle");
    var box = el("div", "gw-wl-box");
    var x = el("button", "gw-pop-close gw-wl-close");
    x.type = "button";
    x.setAttribute("aria-label", t("gw.close"));
    x.title = t("gw.close");
    x.addEventListener("click", closeWinners);
    box.appendChild(x);
    var title = el("h2", "gw-wl-title", pick(g.title));
    title.id = "gwWlTitle";
    box.appendChild(title);
    box.appendChild(el("p", "gw-wl-sub", t("gw.allWinners")));
    var groups = winGroups(g);
    var list = el("ul", "gw-wl-list");
    (g.prizes || []).forEach(function (p, i) {
      var li = el("li", "gw-wl-group");
      var head = el("div", "gw-wl-prize");
      head.appendChild(placeIcon(i, "gw-wl-place"));
      head.appendChild(prizePic(p, "gw-wl-pic"));
      head.appendChild(el("span", "gw-wl-name", prizeName(p)));
      li.appendChild(head);
      var ul = el("ul", "gw-wl-wins");
      if (!groups[i].length) { ul.appendChild(el("li", "gw-wl-none", t("gw.noWinner"))); }
      groups[i].forEach(function (w) {
        var item = el("li");
        var a = el("a", "gw-wl-win");
        a.href = siteUrl("/profile?id=" + encodeURIComponent(w.id));
        a.appendChild(avatar(w, "gw-wl-ava"));
        a.appendChild(el("span", "gw-wl-who", winnerLabel(w)));
        item.appendChild(a);
        ul.appendChild(item);
      });
      li.appendChild(ul);
      list.appendChild(li);
    });
    box.appendChild(list);
    pop.appendChild(box);
    pop.addEventListener("click", function (e) { if (e.target === pop) { closeWinners(); } });
    document.body.appendChild(pop);
    document.body.classList.add("gw-locked");
    document.addEventListener("keydown", onWinKey, true);
    winPop = pop;
    x.focus({ preventScroll: true });
  }

  function renderPrizes(g) {
    var prizes = g.prizes || [];
    var drawn = g.phase === "drawn" && g.winners && g.winners.length;
    var groups = winGroups(g);
    var seen = 0;
    var list = el("ul", "gw-prizes");
    list.setAttribute("aria-label", t(drawn ? "gw.winners" : "gw.prizes"));
    prizes.forEach(function (p, i) {
      var li = el("li", "gw-prize");
      li.appendChild(placeIcon(i, "gw-place"));
      if (drawn) {
        var first = seen < 3 ? groups[i][0] : null;
        if (first) { seen++; }
        li.appendChild(renderWin(first, groups[i].length));
      }
      var tile = el("div", "gw-prize-tile");
      tile.appendChild(prizePic(p, "gw-prize-pic"));
      tile.appendChild(el("span", "gw-prize-name", prizeName(p)));
      li.appendChild(tile);
      list.appendChild(li);
    });
    if (!drawn) { return list; }
    var box = el("div", "gw-wins-box");
    box.appendChild(frame("gw-wins-frame", "26"));
    box.appendChild(list);
    return box;
  }

  function safeUrl(u) {
    return typeof u === "string" && /^https?:\/\/\S+$/i.test(u) ? u : "";
  }

  var PATH_L = "M0 0C6.339 44.187 95.354 19.611 108 52";
  var PATH_R = "M108 0C101.661 44.187 12.646 19.611 0 52";

  function pathSvg(d) {
    var id = "gwGrad" + (++uid);
    var s = svg("0 0 108 52", [
      ["path", { "class": "gw-path-base", d: d }],
      ["path", { "class": "gw-path-fill", d: d, pathLength: "100", stroke: "url(#" + id + ")" }]
    ], "gw-path");
    s.insertBefore(gradient(id), s.firstChild);
    return s;
  }

  function paint(list) {
    if (!list) { return; }
    var items = list.children;
    for (var i = 0; i < items.length - 1; i++) {
      var s = items[i].querySelector(".gw-path");
      if (!s) { continue; }
      var hi = items[i].classList.contains("is-ok");
      var lo = items[i + 1].classList.contains("is-ok");
      var fill = s.querySelector(".gw-path-fill");
      s.setAttribute("data-fill", hi && lo ? "both" : lo ? "low" : hi ? "high" : "none");
      fill.setAttribute("stroke-dasharray", "50 100");
      fill.setAttribute("stroke-dashoffset", lo && !hi ? "-50" : "0");
    }
  }

  function mark(li, state) {
    if (!li) { return; }
    li.classList.toggle("is-ok", state === "ok");
    li.classList.toggle("is-bad", state === "bad");
    paint(li.parentNode);
  }

  function stepList(g) {
    var out = [{ k: "login", label: t("gw.condLogin") }, { k: "tg", label: t("gw.condTg") }];
    (g.channels || []).forEach(function (c) { out.push({ k: "ch", id: c.id, label: c.title, url: c.url }); });
    (g.conds || []).forEach(function (c, i) { out.push({ k: "own", i: i, label: pick(c.text), url: c.url, chat: c.chat }); });
    return out;
  }

  function renderSteps(g, ui) {
    var live = g.phase === "live";
    var steps = stepList(g);
    var n = steps.length;
    var list = el("ol", "gw-steps");
    list.setAttribute("aria-label", t("gw.conditions"));
    ui.cChannels = {};
    ui.cOwn = [];
    var seen = readConds();
    steps.forEach(function (s, idx) {
      var no = n - idx;
      var li = el("li", "gw-step " + (no % 2 ? "is-l" : "is-r"));
      li.setAttribute("data-k", s.k);
      var circle = el("span", "gw-step-n", String(no));
      circle.setAttribute("aria-hidden", "true");
      li.appendChild(circle);
      var url = live ? safeUrl(s.url) : "";
      var lab;
      if (url) {
        lab = el("a", "gw-step-label", s.label);
        lab.href = url;
        lab.target = "_blank";
        lab.rel = "noopener noreferrer";
      } else if (live && (s.k === "login" || s.k === "tg")) {
        lab = el("button", "gw-step-label", s.label);
        lab.type = "button";
        lab.disabled = true;
      } else {
        lab = el("span", "gw-step-label", s.label);
      }
      li.appendChild(lab);
      if (idx < n - 1) { li.appendChild(pathSvg(no % 2 ? PATH_L : PATH_R)); }
      if (s.k === "login") { ui.cLogin = li; }
      if (s.k === "tg") { ui.cTg = li; }
      if (s.k === "ch") { ui.cChannels[s.id] = li; }
      if (s.k === "own") {
        if (s.chat) { li.setAttribute("data-chat", s.chat); }
        var key = g.id + ":" + s.i;
        if (live && g.id && seen.indexOf(key) >= 0) { li.classList.add("is-ok"); }
        if (url) {
          lab.addEventListener("click", function () {
            mark(li, "ok");
            if (g.id) { markCond(key); }
          });
        }
        ui.cOwn.push(li);
      }
      list.appendChild(li);
    });
    paint(list);
    fitLabels(list);
    return list;
  }

  function fitLabels(list) {
    var run = function () {
      if (!list.isConnected) { return; }
      list.querySelectorAll(".gw-step-label").forEach(function (lab) {
        lab.style.width = "";
        var range = document.createRange();
        range.selectNodeContents(lab);
        var rects = range.getClientRects();
        if (rects.length < 2) { return; }
        var wide = 0;
        for (var i = 0; i < rects.length; i++) { wide = Math.max(wide, rects[i].width); }
        var fs = parseFloat(getComputedStyle(lab).fontSize) || 12;
        lab.style.width = (wide / fs + 10 / 12 + 0.05).toFixed(3) + "em";
      });
    };
    requestAnimationFrame(function () {
      (document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve()).then(run);
    });
  }

  function card(g, opts) {
    opts = opts || {};
    var ui = {};
    var live = g.phase === "live";
    var page = opts.mode === "page";
    var root_ = el("article", "gw-card " + (page ? "is-page" : "is-pop"));
    root_.id = "g" + g.id;
    root_.setAttribute("data-phase", g.phase);

    var box = el("div", "gw-in");
    var title = el("h2", "gw-title", pick(g.title));
    title.id = "gwTitle" + g.id;
    box.appendChild(title);

    var media = renderMedia(g);
    if (!live) { media.appendChild(overBadge()); }
    box.appendChild(media);
    box.appendChild(renderStat(g));
    box.appendChild(renderBar(g));

    var grid = el("div", "gw-grid");
    var left = el("div", "gw-left");
    if (g.prizes && g.prizes.length) { left.appendChild(renderPrizes(g)); }
    if (g.phase === "drawn" && g.winners && g.winners.length && g.prizes && g.prizes.length) {
      root_.classList.add("has-wins");
      root_.tabIndex = 0;
      root_.setAttribute("aria-haspopup", "dialog");
      root_.setAttribute("aria-describedby", title.id);
      root_.setAttribute("aria-label", t("gw.allWinners"));
      root_.addEventListener("click", function (e) {
        if (e.target.closest && e.target.closest("a, button")) { return; }
        openWinners(g);
      });
      root_.addEventListener("keydown", function (e) {
        if (e.target !== root_ || (e.key !== "Enter" && e.key !== " ")) { return; }
        e.preventDefault();
        openWinners(g);
      });
    }
    grid.appendChild(left);
    grid.appendChild(renderSteps(g, ui));

    var foot = el("div", "gw-foot");
    ui.msg = el("p", "gw-msg");
    ui.msg.setAttribute("role", "status");
    ui.msg.setAttribute("aria-live", "polite");
    if (live) {
      ui.cta = el("button", "gw-cta");
      ui.cta.type = "button";
      ui.cta.appendChild(frame("gw-cta-frame", "999"));
      ui.ctaText = el("span", "gw-cta-text", t("gw.join"));
      ui.cta.appendChild(ui.ctaText);
      ui.cta.setAttribute("data-key", "gw.join");
      foot.appendChild(ui.cta);
    }
    foot.appendChild(ui.msg);
    if (live && !page) {
      var all = el("a", "gw-all", t("gw.all"));
      all.href = siteUrl("/giveaways#g" + g.id);
      foot.appendChild(all);
    }
    if (g.phase === "drawn" && !(g.winners && g.winners.length)) { ui.msg.textContent = t("gw.noWinners"); }
    if (g.phase === "ended") { ui.msg.textContent = t("gw.waitDraw"); }
    if (live || ui.msg.textContent) { left.appendChild(foot); }
    box.appendChild(grid);
    root_.appendChild(box);

    if (live) {
      if (opts.preview) { ui.cta.disabled = true; }
      else { wire(root_, g, ui, opts); }
    }
    startTicker();
    return root_;
  }

  function wire(cardEl, g, ui, opts) {
    var st = { joined: false, busy: false, tgUrl: "", poll: 0, pollUntil: 0, user: null };
    ui.bLogin = ui.cLogin.querySelector("button");
    ui.bTg = ui.cTg.querySelector("button");
    ui.bLogin.addEventListener("click", goLogin);
    ui.bTg.addEventListener("click", openTg);

    function say(key, vars) {
      ui.msg.textContent = key ? t(key, vars) : "";
      ui.msg.classList.toggle("is-bad", !!key && key.indexOf("gw.err.") === 0);
    }

    function setCta(key, disabled) {
      ui.ctaText.textContent = t(key);
      ui.cta.disabled = !!disabled;
      ui.cta.setAttribute("data-key", key);
    }

    function showJoined() {
      st.joined = true;
      cardEl.classList.add("is-joined");
      ui.bLogin.disabled = true;
      ui.bTg.disabled = true;
      mark(ui.cLogin, "ok");
      mark(ui.cTg, "ok");
      Object.keys(ui.cChannels || {}).forEach(function (k) { mark(ui.cChannels[k], "ok"); });
      (ui.cOwn || []).forEach(function (li) { mark(li, "ok"); });
      setCta("gw.joined", true);
      say(g.end_by === "entries" ? "gw.joinedNoteCount" : g.max_entries ? "gw.joinedNoteMax" : "gw.joinedNote",
        { date: date(g.ends_at), max: g.max_entries });
      if (opts.onJoined) { opts.onJoined(g); }
    }

    function stopPoll() { clearTimeout(st.poll); st.poll = 0; }

    function pollLink() {
      stopPoll();
      if (st.joined || Date.now() > st.pollUntil) { return; }
      st.poll = setTimeout(function () {
        if (document.hidden) { pollLink(); return; }
        post(LINK, { action: "status" }).then(function (r) {
          if (r && r.ok && r.tg && r.tg.linked) {
            tgLinked(r.tg);
            onTgLinked();
            attempt();
            return;
          }
          pollLink();
        }).catch(pollLink);
      }, LINK_POLL_MS);
    }

    function onTgLinked() {
      mark(ui.cTg, "ok");
      ui.bTg.disabled = true;
      st.tgUrl = "";
      if (ui.cta.getAttribute("data-key") === "gw.connectTg") {
        setCta("gw.join");
        say("");
      }
    }

    function waitTg() {
      say("gw.tgWait");
      st.pollUntil = Date.now() + LINK_POLL_FOR;
      pollLink();
    }

    function askTg() {
      mark(ui.cLogin, "ok");
      ui.bLogin.disabled = true;
      mark(ui.cTg, "bad");
      ui.bTg.disabled = false;
      post(LINK, { action: "link", lang: lang() }).then(function (r) {
        if (r && r.ok && r.url) {
          st.tgUrl = r.url;
          setCta("gw.connectTg");
          say("gw.tgNote");
        } else {
          setCta("gw.join");
          say(r && r.error === "off" ? "gw.err.tg_off" : "gw.err.net");
        }
      }).catch(function () { setCta("gw.join"); say("gw.err.net"); });
    }

    function openTg() {
      if (st.tgUrl) {
        root.open(st.tgUrl, "_blank", "noopener");
        waitTg();
        return;
      }
      var w = openWin();
      ui.bTg.disabled = true;
      post(LINK, { action: "link", lang: lang() }).then(function (r) {
        ui.bTg.disabled = false;
        if (r && r.ok && r.url) {
          st.tgUrl = r.url;
          if (w) {
            w.location.href = r.url;
            waitTg();
          } else {
            setCta("gw.connectTg");
            say("gw.tgNote");
          }
          return;
        }
        if (w) { w.close(); }
        if (r && r.error === "not_logged_in") { goLogin(); return; }
        say(r && r.error === "off" ? "gw.err.tg_off" : "gw.err.net");
      }).catch(function () {
        ui.bTg.disabled = false;
        if (w) { w.close(); }
        say("gw.err.net");
      });
    }

    function goLogin() {
      setResume(g.id);
      var auth = root.MKAuth;
      if (!auth) { say("gw.err.net"); return; }
      var ret = location.pathname + location.search + (opts.mode === "page" ? "#g" + g.id : "");
      location.assign(auth.startUrl(ret));
    }

    function attempt() {
      if (st.busy || st.joined) { return; }
      st.busy = true;
      cardEl.classList.add("is-busy");
      ui.cta.disabled = true;
      say("");
      post(ENTER, { id: g.id, src: source(g.id, opts.mode) }).then(function (r) {
        st.busy = false;
        cardEl.classList.remove("is-busy");
        ui.cta.disabled = false;
        if (r && r.ok) {
          markJoined(g.id);
          showJoined();
          if (r.last) {
            say("gw.joinedLast");
            document.dispatchEvent(new CustomEvent("mk:gw-ended", { detail: { id: g.id } }));
          }
          return;
        }
        var err = r && r.error ? r.error : "net";
        if (err === "login") { goLogin(); return; }
        if (err === "tg") { askTg(); return; }
        mark(ui.cLogin, "ok");
        ui.bLogin.disabled = true;
        if (err === "channels") {
          mark(ui.cTg, "ok");
          ui.bTg.disabled = true;
          var missing = r.missing || [];
          Object.keys(ui.cChannels || {}).forEach(function (k) {
            mark(ui.cChannels[k], missing.indexOf(k) >= 0 ? "bad" : "ok");
          });
          (ui.cOwn || []).forEach(function (li) {
            var chat = li.getAttribute("data-chat");
            if (chat) { mark(li, missing.indexOf(chat) >= 0 ? "bad" : "ok"); }
          });
          setCta("gw.check");
          say("gw.err.channels");
          return;
        }
        if (err === "closed" || err === "full") { setCta("gw.ended", true); }
        var known = ["tg_used", "banned", "closed", "full", "tg_down", "rate_limited"];
        say("gw.err." + (known.indexOf(err) >= 0 ? err : "net"));
      }).catch(function () {
        st.busy = false;
        cardEl.classList.remove("is-busy");
        ui.cta.disabled = false;
        say("gw.err.net");
      });
    }

    ui.cta.addEventListener("click", function () {
      if (st.tgUrl) {
        root.open(st.tgUrl, "_blank", "noopener");
        waitTg();
        return;
      }
      if (st.user === false) { goLogin(); return; }
      attempt();
    });

    cardEl.gwEnded = function () {
      stopPoll();
      if (st.joined) { return; }
      cardEl.setAttribute("data-phase", "ended");
      ui.bLogin.disabled = true;
      ui.bTg.disabled = true;
      setCta("gw.ended", true);
      say("");
    };

    cardEl.gwJoin = attempt;

    document.addEventListener("mk:tg-linked", function () {
      if (!st.joined && cardEl.isConnected) { onTgLinked(); }
    });

    session().then(function (s) {
      st.user = s ? !!s.user : null;
      if (s && s.user) { mark(ui.cLogin, "ok"); }
      if (isJoined(g.id, s)) { showJoined(); return; }
      if (st.user === false) {
        ui.bLogin.disabled = false;
        return;
      }
      if (!st.user) { return; }
      tgStatus().then(function (tg) {
        if (st.joined || !tg) { return; }
        if (tg.linked) { onTgLinked(); } else { ui.bTg.disabled = false; }
      });
    });
  }

  noteFromBot();

  root.NX_GW = {
    load: load,
    session: session,
    tgStatus: tgStatus,
    tgLinked: tgLinked,
    card: card,
    isJoined: isJoined,
    takeResume: takeResume,
    noteFromCard: noteFromCard,
    date: date,
    plural: plural,
    prizePic: prizePic,
    giftIcon: giftIcon,
    lang: lang,
    t: t,
    pick: pick
  };
})(window);
