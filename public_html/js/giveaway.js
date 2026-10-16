(function (root) {
  "use strict";

  var API = "/api/giveaways.php";
  var ENTER = "/api/giveaway_enter.php";
  var LINK = "/api/tg_link.php";
  var LANG_KEY = "nexus-lang-v1";
  var JOINED_KEY = "nx-gw-joined-v1";
  var RESUME_KEY = "nx-gw-resume-v1";
  var FROM_BOT_KEY = "nx-gw-from-bot-v1";
  var COND_KEY = "nx-gw-cond-v1";
  var TEST_KEY = "nx-gw-test-v1";
  var SESSION_WAIT = 5000;
  var LINK_POLL_MS = 3000;
  var LINK_POLL_FOR = 10 * 60 * 1000;

  var dataPromise = null;
  var sessionPromise = null;
  var ticker = 0;

  function lang() {
    var i18n = root.I18N;
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    var doc = document.documentElement.lang;
    if (doc === "ru" || doc === "en") { return doc; }
    return i18n ? i18n.pickLang(stored, navigator.language) : "ru";
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
    return (l === "en" ? obj.en || obj.ru : obj.ru || obj.en) || "";
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
      dataPromise = fetch(TEST ? API + "?test=1" : API, { cache: fresh ? "no-store" : "default" })
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

  function source(id, mode) {
    if (readFromBot()[String(id)]) { return "tg"; }
    return mode === "page" ? "page" : "pop";
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

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function clock(node, sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    node.textContent = "";
    if (d) {
      node.appendChild(el("span", "gw-clock-n", String(d)));
      node.appendChild(el("span", "gw-clock-u", t("gw.days", { n: "" }).trim()));
    }
    node.appendChild(el("span", null, pad(h) + ":" + pad(m) + ":" + pad(s)));
    return node;
  }

  function date(ts) {
    var opts = { timeZone: "Europe/Moscow", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" };
    var s;
    try { s = new Date(ts * 1000).toLocaleString(lang() === "en" ? "en-GB" : "ru-RU", opts); }
    catch (_) { s = new Date(ts * 1000).toLocaleString(); }
    return s + " " + t("gw.msk");
  }

  function tick() {
    var now = Date.now() / 1000;
    document.querySelectorAll("[data-gw-ends]").forEach(function (n) {
      var left = +n.getAttribute("data-gw-ends") - now;
      var time = n.querySelector(".gw-clock-time");
      if (left > 0) {
        if (time) { clock(time, left); }
        return;
      }
      n.removeAttribute("data-gw-ends");
      var card = n.closest(".gw-card");
      if (card && card.gwEnded) { card.gwEnded(); }
      document.dispatchEvent(new CustomEvent("mk:gw-ended"));
    });
  }

  function startTicker() {
    if (ticker) { return; }
    ticker = setInterval(tick, 1000);
  }

  function bindSpoilers(box) {
    var open = function (e) {
      var sp = e.target.closest ? e.target.closest(".nw-spoiler") : null;
      if (!sp || sp.classList.contains("is-open")) { return; }
      if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") { return; }
      e.preventDefault();
      sp.classList.add("is-open");
      sp.removeAttribute("role");
      sp.removeAttribute("tabindex");
    };
    box.addEventListener("click", open);
    box.addEventListener("keydown", open);
  }

  function renderBody(g) {
    var box = el("div", "gw-body");
    if (root.NEWSBLOCKS && g.body && g.body.length) {
      box.appendChild(root.NEWSBLOCKS.renderBlocks(document, g.body, lang()));
      bindSpoilers(box);
    }
    return box;
  }

  var SVG_NS = "http://www.w3.org/2000/svg";

  function svg(box, parts, cls) {
    var s = document.createElementNS(SVG_NS, "svg");
    s.setAttribute("viewBox", box);
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

  function clockIcon() {
    return svg("0 0 24 24", [
      ["circle", { cx: "12", cy: "13.5", r: "7.5" }],
      ["path", { d: "M12 9.5v4l2.6 1.6" }],
      ["path", { d: "M9.5 2.8h5" }],
      ["path", { d: "M18.4 6.2l1.5-1.5" }]
    ], "gw-clock-ico");
  }

  function peopleIcon(cls) {
    return svg("0 0 24 24", [
      ["circle", { cx: "9", cy: "8", r: "3.6" }],
      ["path", { d: "M2.6 19.5c.7-3.7 3.2-5.7 6.4-5.7s5.7 2 6.4 5.7" }],
      ["path", { d: "M15.2 4.6a3.6 3.6 0 0 1 0 6.8" }],
      ["path", { d: "M17.6 14.2c2 .8 3.4 2.6 3.8 5.3" }]
    ], cls || "gw-ico");
  }

  function plural(n) {
    if (lang() === "en") { return n === 1 ? "one" : "many"; }
    var a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) { return "one"; }
    if (a >= 2 && a <= 4 && (b < 12 || b > 14)) { return "few"; }
    return "many";
  }

  function winnersLabel(n) {
    return t("gw.winnersN." + plural(n), { n: n });
  }

  function renderTally(g) {
    var n = g.entries || 0;
    var max = g.max_entries || 0;
    var line = el("p", "gw-entries");
    line.appendChild(peopleIcon());
    line.appendChild(el("span", null, max ? t("gw.entriesOf", { n: n, max: max }) : t("gw.entries", { n: n })));
    if (!max) { return line; }

    var box = el("div", "gw-tally");
    var left = Math.max(0, max - n);
    if (g.phase === "live") {
      line.appendChild(el("span", "gw-left", left ? t("gw.spotsLeft." + plural(left), { n: left }) : t("gw.spotsNone")));
    }
    box.appendChild(line);

    var meter = el("div", "gw-meter");
    meter.setAttribute("role", "progressbar");
    meter.setAttribute("aria-valuemin", "0");
    meter.setAttribute("aria-valuemax", String(max));
    meter.setAttribute("aria-valuenow", String(Math.min(n, max)));
    meter.setAttribute("aria-label", t("gw.entriesOf", { n: n, max: max }));
    var fill = el("span", "gw-meter-fill");
    fill.style.width = Math.min(100, n / max * 100) + "%";
    meter.appendChild(fill);
    box.appendChild(meter);
    if (left === 0) { box.classList.add("is-full"); }
    return box;
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

  function renderPrizes(g) {
    var prizes = g.prizes || [];
    var sec = el("section", "gw-prize-sec");
    var head = el("div", "gw-h-row");
    head.appendChild(el("h3", "gw-h", t("gw.prizes")));
    if (prizes.length > 1) {
      var total = prizes.reduce(function (s, p) { return s + prizeCount(p); }, 0);
      head.appendChild(el("span", "gw-h-aside", winnersLabel(total)));
    }
    sec.appendChild(head);
    var kind = prizes.length === 1 ? " is-solo" : prizes.length > 6 ? " is-many" : "";
    var list = el("ul", "gw-prizes" + kind);
    prizes.forEach(function (p) {
      var li = el("li", "gw-prize");
      var n = prizeCount(p);
      li.appendChild(prizePic(p, "gw-prize-pic"));
      if (n > 1) {
        var badge = el("span", "gw-prize-count", String.fromCharCode(215) + n);
        badge.setAttribute("aria-hidden", "true");
        li.appendChild(badge);
      }
      var text = el("span", "gw-prize-text");
      text.appendChild(el("span", "gw-prize-name", p.name));
      text.appendChild(el("span", "gw-prize-win", winnersLabel(n)));
      li.appendChild(text);
      list.appendChild(li);
    });
    sec.appendChild(list);
    return sec;
  }

  function winnerItem(w) {
    var li = el("li", "gw-win");
    var a = el("a", "gw-win-user");
    a.href = "/profile?id=" + encodeURIComponent(w.id);
    var label = w.display || (w.name ? "@" + w.name : "#" + w.id);
    if (w.avatar) {
      var img = el("img", "gw-win-ava");
      img.src = w.avatar;
      img.alt = "";
      img.loading = "lazy";
      img.referrerPolicy = "no-referrer";
      a.appendChild(img);
    } else {
      a.appendChild(el("span", "gw-win-ava is-empty", label.replace(/^[@#]/, "").charAt(0).toUpperCase()));
    }
    var names = el("span", "gw-win-names");
    names.appendChild(el("span", "gw-win-name", label));
    if (w.display && w.name && w.display !== w.name) {
      names.appendChild(el("span", "gw-win-handle", "@" + w.name));
    }
    a.appendChild(names);
    li.appendChild(a);
    return li;
  }

  function renderWinners(g) {
    var sec = el("section", "gw-winners");
    sec.appendChild(el("h3", "gw-h", t("gw.winners")));
    var prizes = g.prizes || [];
    var groups = prizes.map(function (p) { return { p: p, list: [] }; });
    var loose = [];
    (g.winners || []).forEach(function (w) {
      var gr = groups[w.prize];
      (gr ? gr.list : loose).push(w);
    });
    if (loose.length) { groups.push({ p: null, list: loose }); }
    var box = el("ul", "gw-win-groups");
    groups.forEach(function (gr) {
      var li = el("li", "gw-win-group");
      if (gr.p) {
        var head = el("div", "gw-win-prize");
        head.appendChild(prizePic(gr.p, "gw-win-pic"));
        head.appendChild(el("span", "gw-win-prize-name", gr.p.name));
        if (prizeCount(gr.p) > 1) {
          head.appendChild(el("span", "gw-win-prize-n", String.fromCharCode(215) + prizeCount(gr.p)));
        }
        li.appendChild(head);
      }
      if (gr.list.length) {
        var ul = el("ul", "gw-win-list");
        gr.list.forEach(function (w) { ul.appendChild(winnerItem(w)); });
        li.appendChild(ul);
      } else {
        li.appendChild(el("p", "gw-win-none", t("gw.noWinner")));
      }
      box.appendChild(li);
    });
    sec.appendChild(box);
    return sec;
  }

  function safeUrl(u) {
    return typeof u === "string" && /^https?:\/\/\S+$/i.test(u) ? u : "";
  }

  function condItem(key, label, url, btn) {
    var li = el("li", "gw-cond-item");
    li.setAttribute("data-k", key);
    li.appendChild(el("span", "gw-cond-mark"));
    url = safeUrl(url);
    if (!url) {
      li.appendChild(el("span", "gw-cond-text", label));
      return li;
    }
    li.classList.add("has-link");
    var a = el("a", "gw-cond-text", label);
    a.href = url;
    a.target = "_blank";
    a.rel = "noopener noreferrer";
    li.appendChild(a);
    var b = el("span", "gw-sub", btn);
    b.setAttribute("aria-hidden", "true");
    li.appendChild(b);
    return li;
  }

  function renderConditions(g, ui) {
    var sec = el("section", "gw-cond");
    sec.appendChild(el("h3", "gw-h", t("gw.conditions")));
    var list = el("ul", "gw-cond-list");
    ui.cLogin = condItem("login", t("gw.condLogin"));
    ui.cTg = condItem("tg", t("gw.condTg"));
    list.appendChild(ui.cLogin);
    list.appendChild(ui.cTg);
    ui.cChannels = {};
    (g.channels || []).forEach(function (c) {
      var li = condItem("ch", t("gw.condChannel", { title: c.title }), c.url, t("gw.subscribe"));
      ui.cChannels[c.id] = li;
      list.appendChild(li);
    });
    ui.cOwn = [];
    var seen = readConds();
    (g.conds || []).forEach(function (c, i) {
      var li = condItem("own", pick(c.text), c.url, pick(c.btn) || t("gw.subscribe"));
      if (c.chat) { li.setAttribute("data-chat", c.chat); }
      var key = g.id + ":" + i;
      if (g.id && seen.indexOf(key) >= 0) { mark(li, "ok"); }
      var a = li.querySelector("a");
      if (a) {
        a.addEventListener("click", function () {
          mark(li, "ok");
          if (g.id) { markCond(key); }
        });
      }
      ui.cOwn.push(li);
      list.appendChild(li);
    });
    sec.appendChild(list);
    return sec;
  }

  function mark(li, state) {
    if (!li) { return; }
    li.classList.toggle("is-ok", state === "ok");
    li.classList.toggle("is-bad", state === "bad");
  }

  function card(g, opts) {
    opts = opts || {};
    var ui = {};
    var live = g.phase === "live";
    var root_ = el("article", "gw-card " + (opts.mode === "page" ? "is-page" : "is-pop") + (live ? " has-foot" : ""));
    root_.id = "g" + g.id;
    root_.setAttribute("data-phase", g.phase);

    var aside = el("div", "gw-aside");
    aside.appendChild(renderMedia(g));
    root_.appendChild(aside);

    var content = el("div", "gw-content");
    var main = el("div", "gw-main");
    var head = el("div", "gw-head-block");
    var title = el("h2", "gw-title", pick(g.title));
    title.id = "gwTitle" + g.id;
    head.appendChild(title);

    if (live && g.end_by === "entries") {
      var need = Math.max(0, (g.max_entries || 0) - (g.entries || 0));
      var cnt = el("div", "gw-clock is-count");
      cnt.appendChild(peopleIcon("gw-clock-ico"));
      cnt.appendChild(el("span", "gw-clock-label", t("gw.endsIn")));
      cnt.appendChild(el("span", "gw-clock-time", t("gw.peopleLeft." + plural(need), { n: need })));
      head.appendChild(cnt);
    } else if (live) {
      var clk = el("div", "gw-clock");
      clk.setAttribute("data-gw-ends", String(g.ends_at));
      clk.appendChild(clockIcon());
      clk.appendChild(el("span", "gw-clock-label", t("gw.endsIn")));
      clk.appendChild(clock(el("span", "gw-clock-time"), g.ends_at - Date.now() / 1000));
      head.appendChild(clk);
      ui.clock = clk;
    } else {
      head.appendChild(el("p", "gw-when", t("gw.endedAt", { date: date(g.ends_at) })));
    }
    aside.appendChild(head);

    var page = opts.mode === "page";
    var drawn = g.phase === "drawn" && g.winners && g.winners.length;
    if (drawn) {
      main.appendChild(renderWinners(g));
      main.appendChild(renderBody(g));
    } else {
      if (g.prizes && g.prizes.length) { main.appendChild(renderPrizes(g)); }
      main.appendChild(renderBody(g));
      if (live) { main.appendChild(renderConditions(g, ui)); }
      if (g.phase === "drawn") { main.appendChild(el("p", "gw-note", t("gw.noWinners"))); }
      if (g.phase === "ended") { main.appendChild(el("p", "gw-note", t("gw.waitDraw"))); }
    }

    (page && live ? aside : main).appendChild(renderTally(g));
    content.appendChild(main);
    root_.appendChild(content);

    if (live) {
      var foot = el("footer", "gw-foot");
      ui.msg = el("p", "gw-msg");
      ui.msg.setAttribute("role", "status");
      ui.msg.setAttribute("aria-live", "polite");
      ui.cta = el("button", "gw-cta", t("gw.join"));
      ui.cta.type = "button";
      foot.appendChild(ui.msg);
      var actions = el("div", "gw-actions");
      actions.appendChild(ui.cta);
      if (opts.mode !== "page") {
        var all = el("a", "gw-all", t("gw.all"));
        all.href = "/giveaways#g" + g.id;
        actions.appendChild(all);
      }
      foot.appendChild(actions);
      (page ? aside : content).appendChild(foot);
      if (opts.preview) { ui.cta.disabled = true; }
      else { wire(root_, g, ui, opts); }
    }
    startTicker();
    return root_;
  }

  function wire(cardEl, g, ui, opts) {
    var st = { joined: false, busy: false, tgUrl: "", poll: 0, pollUntil: 0, user: null };

    function say(key, vars) {
      ui.msg.textContent = key ? t(key, vars) : "";
      ui.msg.classList.toggle("is-bad", !!key && key.indexOf("gw.err.") === 0);
    }

    function setCta(key, disabled) {
      ui.cta.textContent = t(key);
      ui.cta.disabled = !!disabled;
      ui.cta.setAttribute("data-key", key);
    }

    function showJoined() {
      st.joined = true;
      cardEl.classList.add("is-joined");
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
            mark(ui.cTg, "ok");
            st.tgUrl = "";
            attempt();
            return;
          }
          pollLink();
        }).catch(pollLink);
      }, LINK_POLL_MS);
    }

    function askTg() {
      mark(ui.cLogin, "ok");
      mark(ui.cTg, "bad");
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
        if (err === "channels") {
          mark(ui.cTg, "ok");
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
        say("gw.tgWait");
        st.pollUntil = Date.now() + LINK_POLL_FOR;
        pollLink();
        return;
      }
      if (st.user === false) { goLogin(); return; }
      attempt();
    });

    cardEl.gwEnded = function () {
      stopPoll();
      if (st.joined) { return; }
      cardEl.setAttribute("data-phase", "ended");
      setCta("gw.ended", true);
      say("");
    };

    cardEl.gwJoin = attempt;

    session().then(function (s) {
      st.user = s ? !!s.user : null;
      if (s && s.user) { mark(ui.cLogin, "ok"); }
      if (isJoined(g.id, s)) { showJoined(); return; }
      if (st.user === false) { setCta("gw.joinLogin"); }
    });
  }

  noteFromBot();

  root.NX_GW = {
    load: load,
    session: session,
    card: card,
    isJoined: isJoined,
    takeResume: takeResume,
    lang: lang,
    t: t,
    pick: pick
  };
})(window);
