(function (root) {
  "use strict";

  var PUB = "/api/hunt.php";
  var ME = "/api/hunt_me.php";
  var ACT = "/api/hunt_act.php";
  var SESSION = "/api/session.php";
  var COIN = "/assets/hunt/token-96.webp";
  var PRIZE_ART = "/assets/halloween/card-fruits.webp";
  var NS = "http://www.w3.org/2000/svg";
  var MSK_OFFSET = -180;
  var DRAW_POLL_MS = 8000;
  var DRAW_POLL_MAX = 23;
  var DRAW_SUSPENSE_MS = 2200;
  var SEEN_KEY = "hn-draw-seen-";

  var doc = root.document;
  var page = doc.getElementById("hnPage");
  if (!page) { return; }

  var H = null;
  var WINNERS = [];
  var me = null;
  var meStatus = "";
  var S = null;
  var buying = false;
  var buyNote = { text: "", err: false };
  var newSeq = 0;
  var confirming = {};
  var winMsg = {};
  var ticker = 0;
  var reloading = false;
  var reloadAt = 0;
  var modal = null;
  var L = {
    key: "", pot: null, slot: null, you: null, foot: null, cap: null, skip: null,
    playing: false, hold: false, live: false, polling: false, late: false,
    tries: 0, pullAt: 0, timer: 0, timers: [], anims: [], io: null, seen: ""
  };

  function $(id) { return doc.getElementById(id); }

  function lang() {
    return root.I18N ? root.I18N.pageLang() : (doc.documentElement.lang || "ru");
  }

  function t(key, vars) {
    return root.I18N ? root.I18N.t(key, lang(), vars) : key;
  }

  function plural(n) {
    return root.I18N ? root.I18N.plural(n) : "many";
  }

  function pick(obj) {
    if (!obj) { return ""; }
    if (typeof obj === "string") { return obj; }
    return (lang() === "ru" ? obj.ru || obj.en : obj.en || obj.ru) || "";
  }

  function num(n) {
    var v = Number(n) || 0;
    try { return v.toLocaleString(root.I18N ? root.I18N.locale() : "ru-RU"); } catch (_) { return String(v); }
  }

  function path(p) {
    return root.I18N && root.I18N.url ? root.I18N.url(p) : p;
  }

  function el(tag, cls, text) {
    var n = doc.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function svg(tag, attrs) {
    var n = doc.createElementNS(NS, tag);
    Object.keys(attrs || {}).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    return n;
  }

  function img(src, cls, size) {
    var n = el("img", cls);
    n.src = src;
    n.alt = "";
    n.width = size || 96;
    n.height = size || 96;
    n.setAttribute("aria-hidden", "true");
    return n;
  }

  function now() { return Date.now() / 1000; }

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function clock(sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var hms = pad(h) + ":" + pad(m) + ":" + pad(s);
    return d ? t("hunt.time.d", { n: d }) + " " + hms : hms;
  }

  function left(sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (d) { return t("hunt.time.d", { n: d }) + " " + t("hunt.time.h", { n: h }); }
    if (h) { return t("hunt.time.h", { n: h }) + " " + t("hunt.time.m", { n: m }); }
    return t("hunt.time.m", { n: Math.max(1, m) });
  }

  function date(ts) {
    var d = new Date(ts * 1000);
    var opts = { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" };
    if (d.getTimezoneOffset() !== MSK_OFFSET) { opts.timeZoneName = "short"; }
    try { return d.toLocaleString(root.I18N ? root.I18N.locale() : "ru-RU", opts); }
    catch (_) { return d.toLocaleString(); }
  }

  function safeArt(src) {
    return typeof src === "string" && /^(https:\/\/|\/(?!\/))/.test(src) ? src : PRIZE_ART;
  }

  function fill(text, key, node) {
    var raw = t(key);
    var at = raw.indexOf("{" + text + "}");
    var out = doc.createDocumentFragment();
    if (at < 0) { out.appendChild(doc.createTextNode(raw)); return out; }
    out.appendChild(doc.createTextNode(raw.slice(0, at)));
    out.appendChild(node);
    out.appendChild(doc.createTextNode(raw.slice(at + text.length + 2)));
    return out;
  }

  function getJson(url) {
    return fetch(url, { cache: "no-store", credentials: "same-origin" })
      .then(function (r) {
        return r.json().catch(function () { return null; }).then(function (j) { return { status: r.status, body: j }; });
      })
      .catch(function () { return { status: 0, body: null }; });
  }

  function post(body) {
    return fetch(ACT, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, status: r.status === 429 ? "slow" : "net" }; });
    }).catch(function () { return { ok: false, status: "net" }; });
  }

  function session() {
    if (root.NX_HUNT && root.NX_HUNT.session) { return root.NX_HUNT.session(); }
    if (root.MK_SESSION !== undefined) { return Promise.resolve(root.MK_SESSION); }
    return fetch(SESSION, { cache: "no-store", credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  function loadPub() {
    return getJson(PUB).then(function (r) { return r.body; });
  }

  function loadMe() {
    if (!S || !S.user) { me = null; meStatus = "login"; return Promise.resolve(); }
    return getJson(ME).then(function (r) {
      var b = r.body;
      if (b && b.ok && b.wallet) { me = b; meStatus = "ok"; return; }
      me = null;
      meStatus = r.status === 401 ? "login" : (b && b.status) || "error";
    });
  }

  function logged() { return !!me; }

  function guest() { return !S || !S.user || meStatus === "login"; }

  function tickets() {
    return me && Array.isArray(me.tickets) ? me.tickets : [];
  }

  function liveTickets() {
    return tickets().filter(function (x) { return !x.void; });
  }

  function dayInfo(n) {
    return me && Array.isArray(me.days) ? me.days[n - 1] || null : null;
  }

  function walletOut(extra) {
    if (!me) { return; }
    var d = { balance: me.wallet.balance, earned: me.wallet.earned, tickets: me.wallet.tickets };
    if (extra) { Object.keys(extra).forEach(function (k) { d[k] = extra[k]; }); }
    try { doc.dispatchEvent(new CustomEvent("hunt:wallet", { detail: d })); } catch (_) {}
  }

  function renderPhase() {
    var p = $("hnPhase");
    p.textContent = "";
    var ph = H.phase;
    if (ph === "soon") {
      var c = el("span", "hn-phase-clock", clock(H.starts_at - now()));
      c.id = "hnPhaseClock";
      p.appendChild(fill("time", "hunt.phase.soon", c));
    } else if (ph === "open") {
      p.textContent = t("hunt.phase.open", { day: H.day, days: H.days });
    } else if (ph === "sales") {
      p.textContent = t("hunt.phase.sales", { date: date(H.sales_end_at) });
    } else if (ph === "closed") {
      p.textContent = t("hunt.phase.closed", { date: date(H.draw_at) });
    } else {
      p.textContent = t("hunt.phase.drawn");
    }
    p.hidden = false;
  }

  function renderPurse() {
    var purse = $("hnPurse");
    var g = $("hnGuest");
    if (logged()) {
      var cap = H.cap > 0 ? H.cap : 1;
      $("hnPurseNum").textContent = num(me.wallet.balance);
      $("hnPurseEarned").textContent = t("hunt.earned", { n: num(me.wallet.earned), cap: num(H.cap) });
      purse.style.setProperty("--p", String(Math.min(1, me.wallet.earned / cap)));
      purse.style.setProperty("--m", String(Math.min(1, H.price / cap)));
      $("hnPurseMid").textContent = num(H.price);
      $("hnPurseCap").textContent = num(H.cap);
      purse.hidden = false;
      g.hidden = true;
      return;
    }
    purse.hidden = true;
    var showGuest = guest() && (H.phase === "soon" || H.phase === "open" || H.phase === "sales");
    g.hidden = !showGuest;
    if (!showGuest) { return; }
    var gift = H.phase === "open" ? +(H.login || [])[H.day - 1] || 0 : 0;
    var today = $("hnGuestToday");
    today.textContent = "";
    if (gift) {
      today.appendChild(img(COIN));
      today.appendChild(doc.createTextNode(t("hunt.guest.today", { n: num(gift) })));
    }
    today.hidden = !gift;
    var go = $("hnGuestGo");
    var can = !!(root.MKAuth && root.MKAuth.canLogin(S));
    go.hidden = !can;
    $("hnGuestWait").hidden = can;
    if (can) { go.href = root.MKAuth.startUrl(path("/halloween")); }
  }

  function windowArt() {
    var s = svg("svg", { viewBox: "0 0 100 140", "aria-hidden": "true", focusable: "false" });
    s.appendChild(svg("path", { "class": "hn-win-frame", d: "M4 136V60.7A58 58 0 0 1 50 4a58 58 0 0 1 46 56.7V136Z" }));
    s.appendChild(svg("path", { "class": "hn-win-glass", d: "M11 130V60.6A51 51 0 0 1 50 11a51 51 0 0 1 39 49.6V130Z" }));
    s.appendChild(svg("path", { "class": "hn-win-bars", d: "M50 11V54M11 54H89M30 54V130M70 54V130" }));
    s.appendChild(svg("path", { "class": "hn-win-crack", d: "M27 63l9 13-6 10 11 14-4 12M36 76l9-3" }));
    s.appendChild(svg("rect", { "class": "hn-win-sill", x: "0", y: "130", width: "100", height: "10", rx: "2.5" }));
    return s;
  }

  function guestState(n) {
    if (H.phase === "soon") { return "future"; }
    if (H.phase === "open") { return n < H.day ? "past" : n === H.day ? "today" : "future"; }
    return "past";
  }

  function renderDays() {
    var list = $("hnDays");
    list.textContent = "";
    var days = H.days || (H.login ? H.login.length : 7);
    for (var n = 1; n <= days; n++) {
      var info = dayInfo(n);
      var state = info && info.login ? info.login.state : guestState(n);
      var reward = info && info.login ? info.login.reward : (H.login || [])[n - 1];
      var isNow = H.phase === "open" && n === H.day;
      var li = el("li", "hn-day is-" + state + (isNow ? " is-now" : ""));

      var win = el("span", "hn-win");
      win.appendChild(windowArt());
      var inner = el("span", "hn-win-in");
      if (state === "got") { inner.appendChild(img(COIN, "hn-win-coin")); }
      inner.appendChild(el("span", "hn-win-num", "+" + num(reward)));
      win.appendChild(inner);
      li.appendChild(win);

      li.appendChild(el("span", "hn-day-name", t("hunt.cal.day", { n: n })));
      var stateEl = el("span", "hn-day-state", t(isNow ? "hunt.cal.today" : "hunt.cal." + state));
      if (isNow && state === "got") { stateEl.appendChild(el("span", "hn-sr", ", " + t("hunt.cal.got"))); }
      li.appendChild(stateEl);

      var pips = el("span", "hn-pips");
      var got = 0;
      for (var k = 0; k < 2; k++) {
        var on = !!(info && info.hours && info.hours[k] && info.hours[k].got);
        if (on) { got++; }
        pips.appendChild(el("i", "hn-pip" + (on ? " is-on" : "")));
      }
      if (info) {
        pips.setAttribute("role", "img");
        pips.setAttribute("aria-label", t("hunt.cal.hours", { n: got }));
      } else {
        pips.setAttribute("aria-hidden", "true");
      }
      li.appendChild(pips);
      list.appendChild(li);
    }
  }

  function setMeter(id, secs, need, reward, got) {
    var m = $(id);
    var p = Math.min(1, secs / need);
    var done = !!got || secs >= need;
    var mins = Math.min(need / 60, Math.floor(secs / 60));
    m.classList.toggle("is-done", done);
    m.querySelector(".hn-meter-fill").style.setProperty("--p", String(done ? 1 : p));
    m.querySelector(".hn-meter-time").textContent = t("hunt.today.min", { n: num(mins), total: num(need / 60) });
    m.querySelector(".hn-meter-prize b").textContent = done ? t("hunt.today.done") : "+" + num(reward);
    var bar = m.querySelector(".hn-meter-track");
    bar.setAttribute("aria-valuenow", String(mins));
    bar.setAttribute("aria-label", t("hunt.today.min", { n: num(mins), total: num(need / 60) }));
  }

  function renderToday() {
    var sec = $("hnToday");
    var note = $("hnTodayNote");
    var over = H.phase === "sales" || H.phase === "closed" || H.phase === "drawn";
    var hours = H.hours || [0, 0];
    $("hnMeters").hidden = over;
    note.textContent = "";
    if (over) {
      sec.classList.add("is-idle");
      note.textContent = t("hunt.today.over");
      return;
    }
    if (!logged() || H.phase !== "open") {
      sec.classList.add("is-idle");
      setMeter("hnMeter1", 0, 3600, hours[0], false);
      setMeter("hnMeter2", 0, 7200, hours[1], false);
      note.textContent = H.phase === "soon" ? t("hunt.today.soon") : guest() ? t("hunt.today.guest") : "";
      return;
    }
    sec.classList.remove("is-idle");
    var secs = me.today ? +me.today.secs || 0 : 0;
    var info = dayInfo(H.day);
    var hs = info && info.hours ? info.hours : [];
    setMeter("hnMeter1", secs, 3600, hours[0], hs[0] && hs[0].got);
    setMeter("hnMeter2", secs, 7200, hours[1], hs[1] && hs[1].got);
    if (me.today && me.today.full) {
      note.appendChild(doc.createTextNode(t("hunt.today.full") + ". "));
    }
    if (me.next_day_at && H.day < H.days) {
      var b = el("b", null, left(me.next_day_at - now()));
      b.id = "hnNextLeft";
      var wrap = el("span", "hn-next");
      wrap.appendChild(fill("time", "hunt.today.next", b));
      note.appendChild(wrap);
    }
  }

  function buyState() {
    if (guest()) { return { key: "login" }; }
    if (!logged()) { return { key: meStatus === "banned" ? "banned" : "error" }; }
    if (H.phase === "soon") { return { key: "soon" }; }
    if (H.phase !== "open" && H.phase !== "sales") { return { key: "closed" }; }
    if (liveTickets().length >= H.limit || me.wallet.tickets >= H.limit) { return { key: "limit" }; }
    if (buying) { return { key: "buying" }; }
    if (me.wallet.balance < H.price) { return { key: "need", n: H.price - me.wallet.balance }; }
    return { key: "buy" };
  }

  function stub(i, ticket) {
    var li = el("li", "hn-stub");
    var main = el("span", "hn-stub-main");
    var side = el("span", "hn-stub-side");
    main.appendChild(el("span", "hn-stub-label", t("hunt.tix.slot", { n: i })));
    if (ticket) {
      li.classList.add(ticket.void ? "is-void" : "is-bought");
      if (ticket.seq === newSeq) { li.classList.add("is-new"); }
      main.appendChild(el("span", "hn-stub-no", ticket.no || "#"));
      side.appendChild(img(COIN));
      side.appendChild(el("span", null, ticket.void ? t("hunt.tix.void") : t("hunt.tix.in")));
    } else {
      li.classList.add("is-empty");
      main.appendChild(el("span", "hn-stub-no", t("hunt.tix.empty")));
      side.appendChild(img(COIN));
      side.appendChild(el("span", null, num(H.price)));
    }
    li.appendChild(main);
    li.appendChild(side);
    return li;
  }

  function renderTix() {
    $("hnTixLead").textContent = t("hunt.tix.lead", { price: num(H.price), limit: H.limit, date: date(H.draw_at) });
    var list = $("hnStubs");
    list.textContent = "";
    var mine = tickets().slice().sort(function (a, b) { return (a.seq || 0) - (b.seq || 0); });
    var live = mine.filter(function (x) { return !x.void; });
    for (var i = 1; i <= (H.limit || 2); i++) {
      list.appendChild(stub(i, live[i - 1] || null));
    }
    mine.filter(function (x) { return x.void; }).forEach(function (x) {
      list.appendChild(stub(x.seq || 0, x));
    });

    var btn = $("hnBuy");
    var s = buyState();
    btn.textContent = "";
    btn.disabled = s.key !== "buy";
    if (s.key === "buy") {
      btn.appendChild(img(COIN));
      btn.appendChild(doc.createTextNode(t("hunt.tix.buy", { price: num(H.price) })));
    } else if (s.key === "need") {
      btn.textContent = t("hunt.tix.need." + plural(s.n), { n: num(s.n) });
    } else {
      btn.textContent = t({
        login: "hunt.tix.loginBtn",
        banned: "hunt.tix.banned",
        error: "hunt.tix.unavailable",
        soon: "hunt.tix.soon",
        closed: "hunt.tix.closed",
        limit: "hunt.tix.limit",
        buying: "hunt.tix.buying"
      }[s.key] || "hunt.tix.unavailable");
    }
    btn.hidden = s.key === "login";

    var note = $("hnBuyNote");
    note.textContent = "";
    note.classList.toggle("is-err", !!buyNote.err);
    if (buyNote.text) {
      note.textContent = buyNote.text;
    } else if (s.key === "login") {
      note.textContent = t("hunt.tix.login");
    } else if (logged() && (s.key === "buy" || s.key === "need")) {
      note.textContent = t("hunt.tix.have." + plural(me.wallet.balance), { n: num(me.wallet.balance) });
    }
  }

  function buy() {
    if (buying || buyState().key !== "buy") { return; }
    buying = true;
    buyNote = { text: "", err: false };
    renderTix();
    var seq = Math.max(liveTickets().length, +me.wallet.tickets || 0) + 1;
    post({ act: "ticket", seq: seq }).then(function (r) {
      buying = false;
      r = r || {};
      if (r.wallet) { me.wallet = r.wallet; }
      if (Array.isArray(r.tickets)) { me.tickets = r.tickets; }
      else if (r.ticket && r.ok) { me.tickets = tickets().concat([r.ticket]); }
      if (r.price) { H.price = r.price; }
      if (r.limit) { H.limit = r.limit; }
      var st = r.status;
      var fresh = null;
      if (r.ok && (st === "done" || st === "already")) {
        newSeq = r.ticket && r.ticket.seq ? r.ticket.seq : seq;
        buyNote = { text: t("hunt.tix.done", { no: r.ticket && r.ticket.no ? r.ticket.no : "" }), err: false };
        if (st === "done" && r.ticket && r.ticket.no) { fresh = r.ticket; }
      } else if (st === "balance") {
        var need = r.need || Math.max(0, H.price - (me.wallet.balance || 0));
        buyNote = { text: t("hunt.tix.need." + plural(need), { n: num(need) }), err: true };
      } else if (st === "limit") {
        buyNote = { text: t("hunt.tix.limit"), err: true };
      } else if (st === "stale") {
        buyNote = { text: "", err: false };
        loadMe().then(renderAll);
        return;
      } else if (st === "closed") {
        buyNote = { text: t("hunt.tix.closed"), err: true };
        reloadAll();
      } else if (st === "login") {
        meStatus = "login";
        me = null;
        buyNote = { text: t("hunt.tix.login"), err: true };
        renderAll();
        return;
      } else if (st === "banned") {
        buyNote = { text: t("hunt.tix.banned"), err: true };
      } else {
        buyNote = { text: t("hunt.tix.err"), err: true };
      }
      renderPurse();
      renderTix();
      walletOut();
      if (fresh) { reveal(fresh); }
    });
  }

  function motionOk() {
    return !(root.matchMedia && root.matchMedia("(prefers-reduced-motion: reduce)").matches);
  }

  function run(list, node, frames, opts, always) {
    if (!node || typeof node.animate !== "function" || (!always && !motionOk())) { return null; }
    var a = node.animate(frames, opts);
    if (list) { list.push(a); }
    return a;
  }

  function rise(list, node, delay, k) {
    return run(list, node, [
      { opacity: 0, transform: "translateY(10px)" },
      { opacity: 1, transform: "none" }
    ], { duration: 380, delay: (delay || 0) + (k || 0) * 80, easing: "cubic-bezier(.2,.8,.2,1)", fill: "backwards" });
  }

  function reelSet(no, still) {
    var box = el("span", "hn-reels");
    box.setAttribute("aria-hidden", "true");
    var reels = [];
    String(no || "").split("").forEach(function (ch) {
      if (still || !/[0-9]/.test(ch)) { box.appendChild(el("span", "hn-reel-ch", ch)); return; }
      var reel = el("span", "hn-reel");
      var strip = el("span", "hn-reel-strip");
      var count = 9 + reels.length * 3;
      for (var k = 0; k < count; k++) { strip.appendChild(el("span", null, String(Math.floor(Math.random() * 10)))); }
      strip.appendChild(el("span", null, ch));
      reel.appendChild(strip);
      box.appendChild(reel);
      reels.push({ reel: reel, strip: strip, n: count + 1 });
    });
    return { node: box, reels: reels };
  }

  function spin(set, list, delay, first, step) {
    var end = delay;
    set.reels.forEach(function (r, i) {
      var to = -(r.n - 1) / r.n * 100;
      var dur = first + i * step;
      end = Math.max(end, delay + dur);
      var a = run(list, r.strip, [
        { transform: "translateY(0)", easing: "cubic-bezier(.2,.55,.3,1)" },
        { transform: "translateY(" + (to - 32 / r.n) + "%)", offset: 0.86, easing: "cubic-bezier(.45,0,.4,1)" },
        { transform: "translateY(" + to + "%)" }
      ], { duration: dur, delay: delay, fill: "both" });
      if (a) {
        a.onfinish = function () { r.reel.classList.add("is-set"); };
      } else {
        r.strip.style.setProperty("transform", "translateY(" + to + "%)");
        r.reel.classList.add("is-set");
      }
    });
    return end;
  }

  function ticketEl(no, label, art, still) {
    var box = el("div", "hn-stub hn-ticket is-bought");
    box.setAttribute("aria-hidden", "true");
    var main = el("span", "hn-stub-main");
    main.appendChild(el("span", "hn-stub-label", label));
    var digits = el("span", "hn-stub-no hn-ticket-no");
    var set = reelSet(no, still);
    digits.appendChild(set.node);
    main.appendChild(digits);
    var side = el("span", "hn-stub-side");
    var seal = img(art.src, "hn-ticket-seal", art.size);
    side.appendChild(seal);
    if (art.text) { side.appendChild(el("span", "hn-ticket-in", art.text)); }
    box.appendChild(main);
    box.appendChild(side);
    return { node: box, set: set, seal: seal };
  }

  function focusTo(node) {
    if (!node) { return; }
    try { node.focus({ preventScroll: true }); } catch (_) { node.focus(); }
  }

  function reveal(ticket) {
    closeReveal(true);
    var still = !motionOk();
    var wrap = el("div", "hn-modal");
    var box = el("div", "hn-reveal");
    box.setAttribute("role", "dialog");
    box.setAttribute("aria-modal", "true");
    box.setAttribute("aria-labelledby", "hnRevealTitle");
    var slit = el("span", "hn-reveal-slit");
    slit.setAttribute("aria-hidden", "true");
    var mouth = el("div", "hn-reveal-mouth");
    var tk = ticketEl(ticket.no, t("hunt.tix.slot", { n: ticket.seq || 1 }), { src: COIN, size: 96, text: t("hunt.tix.in") }, still);
    mouth.appendChild(tk.node);
    box.appendChild(slit);
    box.appendChild(mouth);
    var title = el("h2", "hn-reveal-title", t("hunt.reveal.title", { no: ticket.no }));
    title.id = "hnRevealTitle";
    box.appendChild(title);
    var words = [title];
    if (H.draw_at > 0) {
      var sub = el("p", "hn-reveal-sub", t("hunt.reveal.draw", { date: date(H.draw_at) }));
      sub.id = "hnRevealSub";
      box.setAttribute("aria-describedby", "hnRevealSub");
      box.appendChild(sub);
      words.push(sub);
    }
    var btn = el("button", "hn-btn hn-reveal-close", t("hunt.reveal.close"));
    btn.type = "button";
    box.appendChild(btn);
    wrap.appendChild(box);
    btn.addEventListener("click", function () { closeReveal(false); });
    wrap.addEventListener("click", function (e) { if (e.target === wrap) { closeReveal(false); } });
    doc.addEventListener("keydown", onModalKey, true);
    doc.body.appendChild(wrap);
    modal = { wrap: wrap, btn: btn, anims: [] };
    focusTo(btn);

    var A = modal.anims;
    run(A, wrap, [{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: "ease-out", fill: "backwards" }, true);
    if (still) { return; }
    run(A, box, [{ transform: "translateY(18px) scale(.97)" }, { transform: "none" }], { duration: 440, easing: "cubic-bezier(.2,.8,.2,1)" });
    run(A, slit, [{ transform: "scaleX(.2)", opacity: 0 }, { transform: "none", opacity: 1 }], { duration: 320, delay: 60, easing: "cubic-bezier(.2,.8,.2,1)", fill: "backwards" });
    run(A, tk.node, [{ transform: "translateY(-104%)" }, { transform: "none" }], { duration: 680, delay: 220, easing: "cubic-bezier(.25,.8,.3,1)", fill: "backwards" });
    var end = spin(tk.set, A, 260, 900, 280);
    run(A, tk.seal, [
      { transform: "scale(1.9) rotate(-20deg)", opacity: 0 },
      { transform: "none", opacity: 1 }
    ], { duration: 420, delay: end - 60, easing: "cubic-bezier(.3,1.55,.5,1)", fill: "backwards" });
    run(A, tk.node, [{ transform: "none" }, { transform: "scale(1.04)" }, { transform: "none" }], { duration: 380, delay: end, easing: "ease-out" });
    words.forEach(function (n, k) { rise(A, n, end + 80, k); });
  }

  function onModalKey(e) {
    if (!modal) { return; }
    if (e.key === "Escape" || e.key === "Esc") {
      e.preventDefault();
      closeReveal(false);
    } else if (e.key === "Tab") {
      e.preventDefault();
      focusTo(modal.btn);
    }
  }

  function restamp() {
    var s = doc.querySelector("#hnStubs .hn-stub.is-new");
    if (!s) { return; }
    s.classList.remove("is-new");
    void s.offsetWidth;
    s.classList.add("is-new");
  }

  function closeReveal(instant) {
    if (!modal) { return; }
    var m = modal;
    modal = null;
    doc.removeEventListener("keydown", onModalKey, true);
    m.anims.forEach(function (a) { try { a.finish(); } catch (_) {} });
    m.wrap.style.setProperty("pointer-events", "none");
    var gone = function () { if (m.wrap.parentNode) { m.wrap.parentNode.removeChild(m.wrap); } };
    var out = instant ? null : run(null, m.wrap, [{ opacity: 1 }, { opacity: 0 }], { duration: 180, easing: "ease-in", fill: "forwards" }, true);
    if (out) { out.onfinish = gone; } else { gone(); }
    if (instant) { return; }
    var to = $("hnBuy");
    if (!to || to.disabled || to.hidden) {
      to = $("hnTixTitle");
      to.setAttribute("tabindex", "-1");
    }
    focusTo(to);
    restamp();
  }

  function winStatus(w) {
    if (w.status === "won") { return w.deadline ? t("hunt.win.deadline", { date: date(w.deadline) }) : ""; }
    return t("hunt.win." + (w.status || "confirmed"));
  }

  function renderWins() {
    var wins = me && Array.isArray(me.wins) ? me.wins : [];
    var sec = $("hnWins");
    var list = $("hnWinList");
    list.textContent = "";
    sec.hidden = !wins.length || L.hold;
    wins.forEach(function (w) {
      var card = el("article", "hn-wcard");
      var art = el("span", "hn-wcard-art");
      art.appendChild(img(safeArt(w.prize && w.prize.icon), null, 160));
      card.appendChild(art);
      var body = el("div", "hn-wcard-body");
      body.appendChild(el("p", "hn-wcard-text", t("hunt.win.text", { no: w.ticket_no || "", prize: w.prize ? w.prize.name : "" })));
      body.appendChild(el("p", "hn-wcard-status", winStatus(w)));
      card.appendChild(body);
      var act = el("div", "hn-wcard-act");
      if (w.status === "won") {
        var b = el("button", "hn-btn", t(confirming[w.id] ? "hunt.win.confirming" : "hunt.win.confirm"));
        b.type = "button";
        b.disabled = !!confirming[w.id];
        b.addEventListener("click", function () { confirmWin(w); });
        act.appendChild(b);
      }
      var msg = winMsg[w.id];
      if (msg) {
        var p = el("p", "hn-wcard-msg", msg.text);
        p.setAttribute("role", "status");
        if (msg.link) {
          p.appendChild(doc.createTextNode(" "));
          var a = el("a", null, t("hunt.win.tgLink"));
          a.href = path("/profile");
          p.appendChild(a);
        }
        act.appendChild(p);
      }
      card.appendChild(act);
      list.appendChild(card);
    });
  }

  function hasWon() {
    return !!(me && Array.isArray(me.wins) && me.wins.some(function (w) { return w.status === "won"; }));
  }

  function confirmWin(w) {
    if (confirming[w.id]) { return; }
    confirming[w.id] = true;
    delete winMsg[w.id];
    renderWins();
    post({ act: "confirm_win", id: w.id }).then(function (r) {
      delete confirming[w.id];
      r = r || {};
      if (r.ok && (r.status === "done" || r.status === "already")) {
        var fresh = r.win || {};
        Object.keys(fresh).forEach(function (k) { w[k] = fresh[k]; });
        if (w.status === "won") { w.status = "confirmed"; }
      } else if (r.status === "tg") {
        winMsg[w.id] = { text: t("hunt.win.tg"), link: true };
      } else if (r.status === "expired") {
        w.status = "expired";
      } else {
        winMsg[w.id] = { text: t("hunt.win.err") };
      }
      renderWins();
      walletOut({ won: hasWon() });
    });
  }

  function seenKey() { return SEEN_KEY + (H ? H.id : ""); }

  function wasSeen() {
    if (L.seen === seenKey()) { return true; }
    try { return root.localStorage.getItem(seenKey()) === "1"; } catch (_) { return false; }
  }

  function markSeen() {
    L.seen = seenKey();
    try { root.localStorage.setItem(seenKey(), "1"); } catch (_) {}
  }

  function myId() { return S && S.user ? String(S.user.id) : ""; }

  function isMine(w) {
    var id = myId();
    return !!id && !!w && !!w.user && String(w.user.id) === id;
  }

  function later(fn, ms) { L.timers.push(setTimeout(fn, ms)); }

  function potArt() {
    var pot = el("div", "hn-pot");
    var back = svg("svg", { "class": "hn-pot-back", viewBox: "0 0 240 190", "aria-hidden": "true", focusable: "false" });
    back.appendChild(svg("ellipse", { "class": "hn-pot-rim", cx: "120", cy: "58", rx: "104", ry: "22" }));
    back.appendChild(svg("ellipse", { "class": "hn-pot-well", cx: "120", cy: "60", rx: "94", ry: "17" }));
    var floats = svg("g", { "class": "hn-pot-floats" });
    [[74, 46, -18], [119, 42, 7], [163, 46, 20]].forEach(function (p) {
      var g = svg("g", { transform: "translate(" + p[0] + " " + p[1] + ") rotate(" + p[2] + ")" });
      var tk = svg("g", { "class": "hn-pot-tk" });
      tk.appendChild(svg("rect", { x: "-17", y: "-10", width: "34", height: "20", rx: "3" }));
      tk.appendChild(svg("path", { d: "M7 -10V10" }));
      g.appendChild(tk);
      floats.appendChild(g);
    });
    back.appendChild(floats);
    back.appendChild(svg("ellipse", { "class": "hn-pot-brew", cx: "120", cy: "60", rx: "92", ry: "16", fill: "url(#hnBrew)" }));
    [[94, 58, 3.4], [141, 63, 2.6], [160, 55, 3]].forEach(function (b) {
      back.appendChild(svg("circle", { "class": "hn-pot-bub", cx: String(b[0]), cy: String(b[1]), r: String(b[2]) }));
    });
    var halo = el("span", "hn-pot-halo");
    var slot = el("div", "hn-pot-slot");
    var front = svg("svg", { "class": "hn-pot-front", viewBox: "0 0 240 190", "aria-hidden": "true", focusable: "false" });
    front.appendChild(svg("path", { "class": "hn-pot-leg", d: "M62 146L50 181Q48 186 54 186H66L79 152Z" }));
    front.appendChild(svg("path", { "class": "hn-pot-leg", d: "M178 146L190 181Q192 186 186 186H174L161 152Z" }));
    front.appendChild(svg("path", { "class": "hn-pot-body", d: "M16 58C8 112 36 164 120 167C204 164 232 112 224 58A104 22 0 0 1 16 58Z", fill: "url(#hnIron)" }));
    front.appendChild(svg("path", { "class": "hn-pot-sheen", d: "M33 96C37 124 55 146 85 157" }));
    front.appendChild(svg("path", { "class": "hn-pot-lip", d: "M16 58A104 22 0 0 0 224 58" }));
    front.appendChild(svg("path", { "class": "hn-pot-lip-hi", d: "M18 54A102 21 0 0 0 222 54" }));
    front.appendChild(svg("path", { "class": "hn-pot-drip", d: "M147 82C147 90 149 97 153 97C157 97 159 90 159 81Z" }));
    front.appendChild(svg("path", { "class": "hn-pot-drip", d: "M81 81C81 86 83 90 86 90C89 90 91 86 91 80Z" }));
    var you = el("span", "hn-pot-you", t("hunt.draw.you"));
    you.hidden = true;
    pot.appendChild(back);
    pot.appendChild(halo);
    pot.appendChild(slot);
    pot.appendChild(front);
    pot.appendChild(you);
    return { node: pot, slot: slot, you: you };
  }

  function drawMode() {
    if (H.phase === "closed") { return L.late ? "late" : L.polling ? "pulling" : "wait"; }
    if (!WINNERS.length) { return "empty"; }
    if (L.playing) { return "show"; }
    if (motionOk() && (L.live || !wasSeen())) { return "pending"; }
    return "static";
  }

  function drawLead() {
    var lead = $("hnDrawLead");
    if (H.phase === "closed") {
      var n = +H.tickets || 0;
      lead.textContent = t("hunt.draw.pot." + plural(n), { n: num(n) });
      return;
    }
    var at = H.drawn_at || H.draw_at;
    lead.textContent = at ? t("hunt.draw.when", { date: date(at) }) : "";
  }

  function sideClosed(side, mode) {
    if (mode === "late") {
      side.appendChild(el("p", "hn-draw-note", t("hunt.draw.late")));
    } else if (mode === "pulling") {
      side.appendChild(el("p", "hn-draw-pull", t("hunt.draw.pulling")));
    } else if (H.draw_at > 0) {
      side.appendChild(el("p", "hn-draw-label", t("hunt.draw.in")));
      var c = el("p", "hn-draw-clock", clock(H.draw_at - now()));
      c.id = "hnDrawClock";
      side.appendChild(c);
    } else {
      side.appendChild(el("p", "hn-draw-note", t("hunt.draw.noDate")));
    }
    if (!logged()) { return; }
    var live = liveTickets();
    var box = el("div", "hn-draw-mine");
    box.appendChild(el("p", "hn-draw-label", t(live.length ? "hunt.draw.yours" : "hunt.draw.noTix")));
    if (live.length) {
      var ul = el("ul", "hn-chips");
      live.forEach(function (x) { ul.appendChild(el("li", "hn-chip-tk", x.no)); });
      box.appendChild(ul);
    }
    side.appendChild(box);
  }

  function waitRow(li) {
    li.className = "hn-drawn is-wait";
    li.textContent = "";
    var tk = el("span", "hn-stub");
    tk.setAttribute("aria-hidden", "true");
    var main = el("span", "hn-stub-main");
    main.appendChild(el("span", "hn-stub-no", "?"));
    tk.appendChild(main);
    tk.appendChild(el("span", "hn-stub-side"));
    li.appendChild(tk);
    li.appendChild(el("span", "hn-drawn-wait", t("hunt.draw.wait")));
  }

  function fillRow(li, w) {
    var mine = isMine(w);
    var u = w.user || {};
    li.className = "hn-drawn" + (mine ? " is-me" : "");
    li.textContent = "";
    var tk = el("span", "hn-stub is-bought");
    tk.setAttribute("aria-hidden", "true");
    var main = el("span", "hn-stub-main");
    main.appendChild(el("span", "hn-stub-no", w.ticket_no || "#"));
    var side = el("span", "hn-stub-side");
    side.appendChild(img(safeArt(w.prize && w.prize.icon), null, 120));
    tk.appendChild(main);
    tk.appendChild(side);
    li.appendChild(tk);
    var who = el("span", "hn-drawn-who");
    who.appendChild(el("span", "hn-sr", (w.ticket_no || "") + ": "));
    who.appendChild(el("span", "hn-drawn-name", u.display_name || u.username || "—"));
    var sub = el("span", "hn-drawn-sub");
    if (u.username) { sub.appendChild(el("span", null, "@" + u.username)); }
    sub.appendChild(el("span", "hn-drawn-prize", w.prize ? w.prize.name : ""));
    who.appendChild(sub);
    li.appendChild(who);
    if (mine) { li.appendChild(el("span", "hn-drawn-me", t("hunt.winners.you"))); }
  }

  function resultsList(filled) {
    var box = el("div", "hn-drawn-box");
    box.appendChild(el("h3", "hn-h3", t("hunt.winners.title")));
    var ol = el("ol", "hn-drawn-list");
    ol.id = "hnDrawList";
    WINNERS.forEach(function (w) {
      var li = el("li");
      if (filled) { fillRow(li, w); } else { waitRow(li); }
      ol.appendChild(li);
    });
    box.appendChild(ol);
    return box;
  }

  function sideLost(side) {
    var live = liveTickets();
    if (!logged() || !live.length || WINNERS.some(isMine)) { return; }
    var list = live.map(function (x) { return x.no; }).join(", ");
    side.appendChild(el("p", "hn-draw-note hn-draw-lost", t("hunt.draw.lost." + plural(live.length), { list: list })));
  }

  function footButton(key, fn) {
    var b = el("button", "hn-btn is-quiet", t(key));
    b.type = "button";
    b.addEventListener("click", fn);
    L.foot.appendChild(b);
    return b;
  }

  function footShow() {
    L.foot.textContent = "";
    L.cap = el("div", "hn-draw-cap");
    L.foot.appendChild(L.cap);
    L.skip = footButton("hunt.draw.skip", function () { showEnd(); });
  }

  function clearStage() {
    L.slot.textContent = "";
    L.pot.classList.remove("is-mine");
    L.you.hidden = true;
  }

  function renderDraw() {
    var sec = $("hnDraw");
    var on = H.phase === "closed" || H.phase === "drawn";
    sec.hidden = !on;
    if (!on) { L.hold = false; return; }
    drawLead();
    if (L.playing) { return; }
    if (!L.pot) {
      var art = potArt();
      L.pot = art.node;
      L.slot = art.slot;
      L.you = art.you;
      L.foot = el("div", "hn-draw-foot");
      $("hnDrawStage").appendChild(L.pot);
      $("hnDrawStage").appendChild(L.foot);
    }
    var mode = drawMode();
    var key = [mode, H.id, H.draw_at, H.tickets, myId(),
      WINNERS.map(function (w) { return w.ticket_no + ":" + (w.user ? w.user.id : ""); }).join(","),
      liveTickets().map(function (x) { return x.no; }).join(",")].join("|");
    if (key === L.key) { return; }
    L.key = key;
    L.hold = mode === "pending";
    sec.setAttribute("data-mode", mode);
    L.pot.classList.toggle("is-brewing", mode === "pulling");
    L.pot.classList.toggle("is-empty", mode === "empty" || !(+H.tickets > 0));
    clearStage();
    L.foot.textContent = "";
    L.cap = null;
    L.skip = null;
    var side = $("hnDrawSide");
    side.textContent = "";
    $("hnDrawLive").textContent = mode === "pulling" ? t("hunt.draw.pulling") : "";
    if (mode === "wait" || mode === "pulling" || mode === "late") {
      sideClosed(side, mode);
      return;
    }
    if (mode === "empty") {
      side.appendChild(el("p", "hn-draw-note", t("hunt.draw.empty")));
      return;
    }
    side.appendChild(resultsList(mode === "static"));
    if (mode === "static") {
      sideLost(side);
      if (motionOk()) { footButton("hunt.draw.replay", replay); }
      markSeen();
      return;
    }
    footShow();
    if (L.live) {
      L.live = false;
      showStart();
    } else {
      armShow();
    }
  }

  function armShow() {
    if (L.io || L.playing) { return; }
    if (typeof root.IntersectionObserver !== "function") { showStart(); return; }
    L.io = new root.IntersectionObserver(function (list) {
      list.forEach(function (e) {
        if (e.isIntersecting && e.intersectionRatio >= 0.6) { showStart(); }
      });
    }, { threshold: [0, 0.6, 1] });
    L.io.observe(L.pot);
  }

  function showStart() {
    if (L.playing || !WINNERS.length) { return; }
    if (L.io) { L.io.disconnect(); L.io = null; }
    L.playing = true;
    L.hold = true;
    renderWins();
    $("hnDraw").setAttribute("data-mode", "show");
    var side = $("hnDrawSide");
    side.textContent = "";
    side.appendChild(resultsList(false));
    if (!L.cap) { footShow(); }
    clearStage();
    showStep(0);
  }

  function caption(w) {
    var u = w.user || {};
    var cap = L.cap;
    cap.textContent = "";
    cap.classList.remove("is-out");
    var parts = [el("p", "hn-draw-name", u.display_name || u.username || "—")];
    if (u.username) { parts.push(el("p", "hn-draw-user", "@" + u.username)); }
    var prize = el("p", "hn-draw-prize");
    prize.appendChild(img(safeArt(w.prize && w.prize.icon), null, 120));
    prize.appendChild(doc.createTextNode(w.prize ? w.prize.name : ""));
    parts.push(prize);
    parts.forEach(function (p, k) {
      cap.appendChild(p);
      rise(L.anims, p, 0, k);
    });
  }

  function showStep(i) {
    if (!L.playing) { return; }
    if (i >= WINNERS.length) {
      later(function () { showEnd(); }, 500);
      return;
    }
    var w = WINNERS[i];
    var mine = isMine(w);
    var A = L.anims;
    var tk = ticketEl(w.ticket_no, WINNERS.length > 1 ? t("hunt.draw.step", { n: i + 1, total: WINNERS.length }) : t("hunt.draw.stepOne"),{ src: safeArt(w.prize && w.prize.icon), size: 120 }, false);
    clearStage();
    L.slot.appendChild(tk.node);
    if (L.cap) { L.cap.classList.add("is-out"); }
    run(A, tk.node, [{ transform: "translateY(110%)" }, { transform: "translateY(14%)" }], { duration: 760, easing: "cubic-bezier(.2,.8,.25,1)", fill: "backwards" });
    var end = spin(tk.set, A, 140, 820, 260);
    run(A, tk.seal, [
      { transform: "scale(1.8) rotate(-18deg)", opacity: 0 },
      { transform: "none", opacity: 1 }
    ], { duration: 420, delay: end - 40, easing: "cubic-bezier(.3,1.5,.5,1)", fill: "backwards" });
    later(function () {
      caption(w);
      var ol = $("hnDrawList");
      var row = ol ? ol.children[i] : null;
      if (row) {
        fillRow(row, w);
        row.classList.add("is-in");
      }
      if (mine) {
        tk.node.classList.add("is-mine");
        L.pot.classList.add("is-mine");
        L.you.hidden = false;
        run(A, L.you, [
          { transform: "translateY(10px) scale(.6)", opacity: 0 },
          { transform: "none", opacity: 1 }
        ], { duration: 460, easing: "cubic-bezier(.3,1.5,.5,1)", fill: "backwards" });
      }
      var u = w.user || {};
      $("hnDrawLive").textContent = t("hunt.draw.sr", {
        no: w.ticket_no || "",
        name: u.display_name || u.username || "—",
        prize: w.prize ? w.prize.name : ""
      }) + (mine ? ". " + t("hunt.draw.you") : "");
    }, end + 60);
    var hold = end + (mine ? 2600 : 1700);
    later(function () {
      run(A, tk.node, [
        { transform: "translateY(14%)", opacity: 1 },
        { transform: "translateY(-46%)", opacity: 0 }
      ], { duration: 440, easing: "cubic-bezier(.5,0,.75,.4)", fill: "forwards" });
      if (L.cap && i < WINNERS.length - 1) { L.cap.classList.add("is-out"); }
      L.pot.classList.remove("is-mine");
      L.you.hidden = true;
    }, hold);
    later(function () { showStep(i + 1); }, hold + 460);
  }

  function showEnd() {
    var hadFocus = !!(L.foot && L.foot.contains(doc.activeElement));
    L.timers.forEach(function (x) { clearTimeout(x); });
    L.timers = [];
    L.anims.forEach(function (a) { try { a.cancel(); } catch (_) {} });
    L.anims = [];
    if (L.io) { L.io.disconnect(); L.io = null; }
    L.playing = false;
    L.hold = false;
    L.live = false;
    markSeen();
    L.key = "";
    renderDraw();
    renderWins();
    walletOut({ won: hasWon() });
    if (hadFocus) { focusTo(L.foot.querySelector("button") || $("hnDrawTitle")); }
  }

  function replay() {
    showStart();
    var r = L.pot.getBoundingClientRect();
    if (r.top < 70 || r.bottom > (root.innerHeight || 0)) {
      try { L.pot.scrollIntoView({ block: "center", behavior: "smooth" }); } catch (_) { L.pot.scrollIntoView(); }
    }
    focusTo(L.skip);
  }

  function drawDue() {
    return !!H && H.phase === "closed" && H.draw_at > 0 && now() >= H.draw_at;
  }

  function startPoll() {
    if (L.polling || L.late) { return; }
    L.polling = true;
    L.tries = 0;
    L.pullAt = Date.now();
    renderDraw();
    pollDraw();
  }

  function pollDraw() {
    L.timer = 0;
    if (doc.visibilityState !== "visible") {
      L.timer = setTimeout(pollDraw, DRAW_POLL_MS);
      return;
    }
    L.tries++;
    loadMe().then(function () {
      return root.NX_HUNT && root.NX_HUNT.pub ? root.NX_HUNT.pub(true) : loadPub();
    }).then(function (d) {
      if (d && d.hunt) {
        H = d.hunt;
        WINNERS = Array.isArray(d.winners) ? d.winners : [];
      }
      if (H.phase !== "closed") {
        L.timer = setTimeout(function () {
          L.polling = false;
          L.live = H.phase === "drawn";
          renderAll();
        }, Math.max(0, DRAW_SUSPENSE_MS - (Date.now() - L.pullAt)));
        return;
      }
      if (L.tries >= DRAW_POLL_MAX) {
        L.polling = false;
        L.late = true;
        renderAll();
        return;
      }
      L.timer = setTimeout(pollDraw, DRAW_POLL_MS);
    });
  }

  function renderPrizes() {
    var list = $("hnPrizeList");
    list.textContent = "";
    (H.prizes || []).forEach(function (p) {
      var count = p.count > 0 ? p.count : 1;
      var li = el("li", "hn-prize");
      var art = el("span", "hn-prize-art");
      art.appendChild(img(safeArt(p.icon), null, 160));
      li.appendChild(art);
      var name = el("span", "hn-prize-name", p.name || "");
      name.appendChild(el("span", "hn-prize-sub", t("hunt.prizes.winners." + plural(count), { n: num(count) })));
      li.appendChild(name);
      li.appendChild(el("span", "hn-prize-n", "×" + num(count)));
      list.appendChild(li);
    });
    if (H.hunters > 0) {
      list.appendChild(el("li", "hn-note", t("hunt.hunters." + plural(H.hunters), { n: num(H.hunters) })));
    }
  }

  function renderDates() {
    var list = $("hnDateList");
    list.textContent = "";
    var at = now();
    var items = [
      ["hunt.dates.start", H.starts_at],
      ["hunt.dates.end", H.ends_at],
      ["hunt.dates.sales", H.sales_end_at],
      ["hunt.dates.draw", H.draw_at]
    ];
    var nextSet = false;
    items.forEach(function (it) {
      if (!it[1]) { return; }
      var li = el("li", "hn-date");
      if (it[1] <= at) { li.classList.add("is-past"); }
      else if (!nextSet) { li.classList.add("is-next"); nextSet = true; }
      li.appendChild(el("span", "hn-date-name", t(it[0])));
      li.appendChild(el("span", "hn-date-at", date(it[1])));
      list.appendChild(li);
    });
  }

  function renderTitles() {
    var list = $("hnTitleList");
    list.textContent = "";
    var days = H.days || 7;
    var have = {};
    (me && me.titles ? me.titles : []).forEach(function (x) { have[x.kind] = true; });
    ["login", "hours"].forEach(function (kind) {
      var total = kind === "login" ? days : days * 2;
      var n = 0;
      if (me && Array.isArray(me.days)) {
        me.days.forEach(function (d) {
          if (kind === "login") { if (d.login && d.login.state === "got") { n++; } }
          else { (d.hours || []).forEach(function (h) { if (h.got) { n++; } }); }
        });
      }
      var li = el("li", "hn-title-item" + (have[kind] ? " is-have" : ""));
      var chips = el("span", "nx-titles");
      var chip = el("span", "nx-title", pick(H.titles && H.titles[kind]));
      chip.setAttribute("data-kind", kind);
      chips.appendChild(chip);
      li.appendChild(chips);
      if (logged()) {
        li.appendChild(el("span", "hn-title-prog", have[kind] ? t("hunt.titles.have") : t("hunt.titles.progress", { n: n, total: total })));
      }
      li.appendChild(el("p", "hn-title-cond", t(kind === "login" ? "hunt.titles.login" : "hunt.titles.hours", { n: total })));
      if (logged()) {
        var bar = el("span", "hn-title-bar");
        bar.setAttribute("aria-hidden", "true");
        var f = el("span");
        f.style.setProperty("--p", String(have[kind] ? 1 : Math.min(1, n / total)));
        bar.appendChild(f);
        li.appendChild(bar);
      }
      list.appendChild(li);
    });
  }

  function renderRules() {
    var list = $("hnRuleList");
    list.textContent = "";
    var login = H.login || [];
    var hours = H.hours || [0, 0];
    [
      t("hunt.rules.login", { first: num(login[0] || 0), last: num(login[login.length - 1] || 0), days: H.days || 7 }),
      t("hunt.rules.hours", { h1: num(hours[0] || 0), h2: num(hours[1] || 0) }),
      t("hunt.rules.cap", { cap: num(H.cap), price: num(H.price), limit: H.limit }),
      t("hunt.rules.days"),
      t("hunt.rules.guest")
    ].forEach(function (s) { list.appendChild(el("li", "hn-rule", s)); });

    var box = $("hnRulesText");
    box.textContent = "";
    String(pick(H.rules) || "").split(/\n+/).forEach(function (line) {
      line = line.trim();
      if (line) { box.appendChild(el("p", null, line)); }
    });
  }

  function renderAll() {
    if (!H) { return; }
    page.setAttribute("data-phase", H.phase);
    renderPhase();
    renderPurse();
    renderDraw();
    renderWins();
    renderDays();
    renderToday();
    renderTix();
    renderPrizes();
    renderDates();
    renderTitles();
    renderRules();
    $("hnState").hidden = true;
    $("hnBody").hidden = false;
    startTick();
  }

  function renderNone(failed) {
    page.setAttribute("data-phase", "none");
    var s = $("hnState");
    s.textContent = t(failed ? "hunt.error" : "hunt.none");
    s.hidden = false;
    $("hnBody").hidden = true;
    $("hnPhase").hidden = true;
    $("hnPurse").hidden = true;
    $("hnGuest").hidden = true;
  }

  function reloadAll() {
    if (reloading || Date.now() - reloadAt < 20000) { return; }
    reloading = true;
    reloadAt = Date.now();
    var pubP = root.NX_HUNT && root.NX_HUNT.pub ? root.NX_HUNT.pub(true) : loadPub();
    Promise.all([pubP, loadMe()]).then(function (res) {
      reloading = false;
      var d = res[0];
      if (d && d.hunt) {
        H = d.hunt;
        WINNERS = Array.isArray(d.winners) ? d.winners : [];
      }
      renderAll();
    });
  }

  function tick() {
    if (!H || doc.visibilityState !== "visible") { return; }
    var at = now();
    if (H.phase === "soon") {
      var c = $("hnPhaseClock");
      if (c) { c.textContent = clock(H.starts_at - at); }
      if (at >= H.starts_at) { reloadAll(); }
      return;
    }
    if (H.phase === "closed") {
      var dc = $("hnDrawClock");
      if (dc) { dc.textContent = clock(H.draw_at - at); }
      if (drawDue()) { startPoll(); }
      return;
    }
    if (H.phase === "open" && me && me.next_day_at) {
      var b = $("hnNextLeft");
      if (b) { b.textContent = left(me.next_day_at - at); }
      if (at >= me.next_day_at + 3) { reloadAll(); }
      return;
    }
    if ((H.phase === "open" && at >= H.ends_at) || (H.phase === "sales" && at >= H.sales_end_at)) { reloadAll(); }
  }

  function startTick() {
    if (ticker) { return; }
    ticker = setInterval(tick, 1000);
  }

  function onUpdate(e) {
    var d = e.detail || {};
    if (!me || !H) { return; }
    if (d.day && me.day && d.day !== me.day) { me.day = d.day; reloadAll(); return; }
    if (d.balance != null) { me.wallet.balance = +d.balance || 0; }
    if (d.earned != null) { me.wallet.earned = +d.earned || 0; }
    if (d.tickets != null) { me.wallet.tickets = +d.tickets || 0; }
    if (!me.today) { me.today = { secs: 0, full: false }; }
    if (d.secs != null) { me.today.secs = +d.secs || 0; }
    if (d.full != null) { me.today.full = !!d.full; }
    (d.gains || []).forEach(function (g) {
      var info = dayInfo(g.n);
      if (!info) { return; }
      if (g.kind === "login" && info.login) { info.login.state = "got"; }
      if (g.kind === "hour" && info.hours && info.hours[(+g.k || 1) - 1]) { info.hours[(+g.k || 1) - 1].got = true; }
    });
    if (H.day === me.day || !me.day) { var today = dayInfo(H.day); if (today) { today.secs = me.today.secs; } }
    renderPurse();
    renderToday();
    if (d.gains && d.gains.length) { renderDays(); renderTitles(); }
    if (!buying) { renderTix(); }
  }

  function start() {
    $("hnBuy").addEventListener("click", buy);
    doc.addEventListener("hunt:update", onUpdate);
    var pubP = root.NX_HUNT && root.NX_HUNT.pub ? root.NX_HUNT.pub(false) : loadPub();
    Promise.all([pubP, session()]).then(function (res) {
      var d = res[0];
      S = res[1];
      if (!d) { renderNone(true); return; }
      if (!d.hunt) { renderNone(false); return; }
      H = d.hunt;
      WINNERS = Array.isArray(d.winners) ? d.winners : [];
      return loadMe().then(renderAll);
    });
  }

  start();
})(window);
