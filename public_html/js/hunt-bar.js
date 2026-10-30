(function (root) {
  "use strict";

  var ACT = "/api/hunt_act.php";
  var PUB = "/api/hunt.php";
  var SESSION = "/api/session.php";
  var COIN = "/assets/hunt/token-96.webp";
  var MEDAL = "/assets/design/giveaways/medal.webp";
  var BEAT_MS = 60000;
  var SLOW_MS = 5 * 60000;
  var WAIT_MS = 6000;
  var TOAST_MS = 5600;
  var TOAST_GAP = 420;
  var UNTUCK_MS = 500;
  var MSK = 3 * 3600;
  var DAY = 86400;
  var GUEST_PHASES = { soon: 1, open: 1, sales: 1 };
  var RAILS = ".gw-rail, .tc-rail, .tr-rail, .nw-rail, .ptn-rail";
  var PROTECT = "#promoDock, [class*='ptn-'], .has-ad, .ct-compose, .tc-slot, .like-fab, .donate-fab, button, input, textarea, select, [role='button'], .mk-top";
  var FIELD = "input, textarea, select, [contenteditable='true']";

  var doc = root.document;
  if (!doc || !root.fetch || /^\/admin(\/|$)/.test(root.location.pathname)) { return; }

  var st = null;
  var box = null;
  var bar = null;
  var fillEl = null;
  var gaugeEl = null;
  var numB = null;
  var numI = null;
  var tipLines = null;
  var tipGo = null;
  var toastBox = null;
  var beatT = 0;
  var slowT = 0;
  var dayT = 0;
  var checkT = 0;
  var untuckT = 0;
  var busy = false;
  var halted = false;
  var dayKey = 0;
  var toastQ = [];
  var toastAt = 0;
  var pubPromise = null;
  var sessionPromise = null;

  function lang() {
    var l = root.I18N ? root.I18N.pageLang() : (doc.documentElement.lang || "ru");
    return l || "ru";
  }

  function t(key, vars) {
    return root.I18N ? root.I18N.t(key, lang(), vars) : key;
  }

  function pick(obj) {
    if (!obj) { return ""; }
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

  function mskDay(ms) {
    return Math.floor((ms / 1000 + MSK) / DAY);
  }

  function visible() {
    return doc.visibilityState === "visible";
  }

  function fetchSession() {
    return fetch(SESSION, { cache: "no-store", credentials: "same-origin" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .catch(function () { return null; });
  }

  function session() {
    if (sessionPromise) { return sessionPromise; }
    sessionPromise = new Promise(function (resolve) {
      if (root.MK_SESSION !== undefined) { resolve(root.MK_SESSION); return; }
      var done = false;
      var finish = function (s) { if (!done) { done = true; resolve(s || null); } };
      doc.addEventListener("mk:session", function (e) { finish(e.detail); }, { once: true });
      setTimeout(function () {
        if (done) { return; }
        if (root.MK_SESSION !== undefined) { finish(root.MK_SESSION); return; }
        fetchSession().then(finish);
      }, WAIT_MS);
    });
    return sessionPromise;
  }

  function pub(fresh) {
    if (!pubPromise || fresh) {
      pubPromise = fetch(PUB, { cache: fresh ? "no-store" : "default", credentials: "same-origin" })
        .then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; });
    }
    return pubPromise;
  }

  function post(body) {
    return fetch(ACT, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (r.status === 429) { return { ok: false, status: "slow" }; }
      return r.json().catch(function () { return { ok: false, status: "net" }; });
    }).catch(function () { return { ok: false, status: "net" }; });
  }

  function canTicket() {
    return !!st && !st.guest && (st.phase === "open" || st.phase === "sales") &&
      st.price > 0 && st.balance >= st.price && st.tickets < st.limit;
  }

  function fromSession(h) {
    st = {
      guest: false,
      phase: h.phase,
      day: h.day,
      earned: +h.earned || 0,
      balance: +h.balance || 0,
      tickets: +h.tickets || 0,
      cap: +h.cap || 0,
      price: +h.price || 0,
      limit: +h.limit || 0,
      secs: +h.secs || 0,
      full: !!h.full,
      next: h.next || null
    };
    dayKey = mskDay(Date.now());
  }

  function emit(gains) {
    if (!st || st.guest) { return; }
    var detail = {
      earned: st.earned,
      balance: st.balance,
      tickets: st.tickets,
      secs: st.secs,
      day: st.day,
      full: st.full,
      gains: gains || []
    };
    try { doc.dispatchEvent(new CustomEvent("hunt:update", { detail: detail })); } catch (_) {}
  }

  function build() {
    box = el("div", "hn");
    box.setAttribute("data-hunt", "bar");

    bar = el("a", "hn-bar");
    bar.href = path("/halloween");
    if (/^\/(?:[a-z]{2}\/)?halloween\/?$/.test(root.location.pathname)) {
      bar.setAttribute("aria-current", "page");
      bar.addEventListener("click", function (e) {
        e.preventDefault();
        try { root.scrollTo({ top: 0, behavior: "smooth" }); } catch (_) { root.scrollTo(0, 0); }
      });
    }

    var coin = el("span", "hn-coin");
    var img = el("img");
    img.src = COIN;
    img.alt = "";
    img.width = 96;
    img.height = 96;
    coin.appendChild(img);
    bar.appendChild(coin);

    gaugeEl = el("span", "hn-gauge");
    var tube = el("span", "hn-tube");
    fillEl = el("span", "hn-fill");
    tube.appendChild(fillEl);
    gaugeEl.appendChild(tube);
    gaugeEl.appendChild(el("span", "hn-mark"));
    bar.appendChild(gaugeEl);

    var nums = el("span", "hn-num");
    nums.setAttribute("aria-hidden", "true");
    numB = el("b", null, "0");
    numI = el("i", null, "0");
    nums.appendChild(numB);
    nums.appendChild(numI);
    bar.appendChild(nums);

    box.appendChild(bar);

    var tip = el("div", "hn-tip");
    tip.setAttribute("aria-hidden", "true");
    tip.appendChild(el("p", "hn-tip-name", "Spooky Tokens"));
    tipLines = [el("p", "hn-tip-line"), el("p", "hn-tip-line"), el("p", "hn-tip-line is-hot")];
    tipLines.forEach(function (n) { tip.appendChild(n); });
    tipGo = el("p", "hn-tip-go");
    tip.appendChild(tipGo);
    box.appendChild(tip);

    toastBox = el("ul", "hn-toasts");
    toastBox.setAttribute("role", "status");
    toastBox.setAttribute("aria-live", "polite");

    doc.body.appendChild(box);
    doc.body.appendChild(toastBox);
  }

  function render() {
    if (!st) { return; }
    if (!box) { build(); }
    var cap = st.cap > 0 ? st.cap : 1;
    var earned = st.guest ? 0 : Math.min(st.earned, cap);
    gaugeEl.style.setProperty("--m", String(st.price > 0 ? Math.min(1, st.price / cap) : 0.5));
    numB.textContent = num(earned);
    numI.textContent = num(st.cap);
    fillEl.style.setProperty("--p", String(earned / cap));

    var hot = "";
    if (!st.guest && st.next !== "won") { st.next = canTicket() ? "ticket" : null; }
    if (st.next === "won") { hot = t("hunt.bar.won"); }
    else if (st.next === "ticket") { hot = t("hunt.bar.ticket"); }

    box.classList.toggle("is-guest", !!st.guest);
    box.classList.toggle("is-won", st.next === "won");
    box.classList.toggle("is-ticket", st.next === "ticket");

    tipLines[0].textContent = st.guest ? t("hunt.bar.guest") : t("hunt.earned", { n: num(st.earned), cap: num(st.cap) });
    tipLines[1].textContent = "";
    if (!st.guest && st.phase === "open") {
      tipLines[1].textContent = st.full ? t("hunt.today.full") : t("hunt.bar.today", { n: num(Math.floor(st.secs / 60)) });
    }
    tipLines[2].textContent = hot;
    tipGo.textContent = t("hunt.bar.open");

    var label = st.guest ? t("hunt.bar.label", { n: "0", cap: num(st.cap) }) + ". " + t("hunt.bar.guest")
      : t("hunt.bar.label", { n: num(st.earned), cap: num(st.cap) });
    if (hot) { label += ". " + hot; }
    bar.setAttribute("aria-label", label);
    measure();
    scheduleCheck(40);
  }

  function toastText(g) {
    if (g.kind === "login") { return t("hunt.toast.login", { n: g.n }); }
    if (g.kind === "hour") { return t(+g.k === 2 ? "hunt.toast.hour2" : "hunt.toast.hour1"); }
    return t("hunt.toast.admin");
  }

  function queueGains(gains, titles) {
    (gains || []).forEach(function (g) {
      if (!g || !(+g.delta)) { return; }
      toastQ.push({ amt: (+g.delta > 0 ? "+" : "") + num(g.delta), text: toastText(g) });
    });
    (titles || []).forEach(function (x) {
      if (!x) { return; }
      toastQ.push({ title: pick(x.title) });
    });
    pump();
  }

  function pump() {
    if (!toastQ.length || !box) { return; }
    var wait = Math.max(0, toastAt + TOAST_GAP - Date.now());
    setTimeout(function () {
      if (!toastQ.length) { return; }
      toastAt = Date.now();
      showToast(toastQ.shift());
      pump();
    }, wait);
  }

  function showToast(item) {
    var li = el("li", "hn-toast" + (item.title != null ? " is-title" : ""));
    var img = el("img");
    img.alt = "";
    img.width = 96;
    img.height = 96;
    img.src = item.title != null ? MEDAL : COIN;
    li.appendChild(img);
    if (item.title != null) {
      var tx = el("span", "hn-toast-text");
      tx.appendChild(el("b", null, item.title));
      tx.appendChild(doc.createTextNode(t("hunt.toast.title")));
      li.appendChild(tx);
    } else {
      li.appendChild(el("span", "hn-toast-amt", item.amt));
      li.appendChild(el("span", "hn-toast-text", item.text));
    }
    while (toastBox.children.length >= 3) { toastBox.removeChild(toastBox.firstChild); }
    toastBox.appendChild(li);
    root.requestAnimationFrame(function () {
      root.requestAnimationFrame(function () { li.classList.add("is-on"); });
    });
    setTimeout(function () {
      li.classList.remove("is-on");
      setTimeout(function () { if (li.parentNode) { li.parentNode.removeChild(li); } }, 700);
    }, TOAST_MS);
  }

  function canBeat() {
    return !!st && !st.guest && !halted && st.phase === "open" && !st.full;
  }

  function stopBeats() {
    clearInterval(beatT);
    beatT = 0;
  }

  function startBeats() {
    if (!canBeat() || !visible() || beatT || slowT) { return; }
    beat();
    beatT = setInterval(beat, BEAT_MS);
  }

  function slowDown() {
    stopBeats();
    clearTimeout(slowT);
    slowT = setTimeout(function () { slowT = 0; startBeats(); }, SLOW_MS);
  }

  function beat() {
    if (busy || !canBeat() || !visible()) { return; }
    busy = true;
    post({ act: "beat" }).then(function (r) {
      busy = false;
      if (!r) { return; }
      if (r.status === "slow") { slowDown(); return; }
      if (!r.ok) {
        if (r.status === "login" || r.status === "banned" || r.status === "closed") { halted = true; stopBeats(); }
        return;
      }
      if (r.wallet) {
        st.earned = +r.wallet.earned || 0;
        st.balance = +r.wallet.balance || 0;
        st.tickets = +r.wallet.tickets || 0;
      }
      if (r.secs != null) { st.secs = +r.secs || 0; }
      if (r.day != null) { st.day = r.day; }
      st.full = !!r.full || r.status === "full";
      if (r.status === "closed") { st.phase = "sales"; }
      queueGains(r.gains, r.titles_new);
      render();
      emit(r.gains);
      if (!canBeat()) { stopBeats(); }
    });
  }

  function scheduleDay() {
    clearTimeout(dayT);
    var next = (mskDay(Date.now()) + 1) * DAY - MSK;
    var wait = next * 1000 - Date.now() + 5000 + Math.floor(Math.random() * 30000);
    dayT = setTimeout(function () {
      if (visible()) { refresh(); }
    }, Math.max(5000, wait));
  }

  function refresh() {
    fetchSession().then(function (s) {
      if (!s || !s.user || !s.hunt) { return; }
      halted = false;
      fromSession(s.hunt);
      queueGains(s.hunt.gains, s.hunt.titles_new);
      render();
      emit(s.hunt.gains);
      scheduleDay();
      startBeats();
    });
  }

  function onVisibility() {
    if (!st || st.guest) { return; }
    if (!visible()) { stopBeats(); return; }
    if (mskDay(Date.now()) !== dayKey) { refresh(); return; }
    startBeats();
  }

  function measure() {
    if (!box) { return; }
    var vw = doc.documentElement.clientWidth || root.innerWidth;
    var room = 999;
    var list = doc.querySelectorAll(RAILS);
    for (var i = 0; i < list.length; i++) {
      var b = list[i].getBoundingClientRect();
      if (b.width < 2 || b.height < 2 || b.left < vw / 2) { continue; }
      room = Math.min(room, vw - b.right);
    }
    box.classList.toggle("is-slim", vw > 760 && room < 37);
    toastBox.classList.toggle("is-slim", box.classList.contains("is-slim"));
    toastBox.classList.toggle("is-top", room < 999);
  }

  function covered() {
    var r = box.getBoundingClientRect();
    if (!r.height || !doc.elementsFromPoint) { return false; }
    var xs = [r.left + 3, r.left + r.width / 2];
    var rows = 6;
    for (var i = 0; i < rows; i++) {
      var y = r.top + 4 + (r.height - 8) * i / (rows - 1);
      for (var j = 0; j < xs.length; j++) {
        var hit = doc.elementsFromPoint(xs[j], y);
        for (var k = 0; k < hit.length; k++) {
          var n = hit[k];
          if (n === doc.body || n === doc.documentElement) { break; }
          if (box.contains(n)) { continue; }
          if (n.closest && n.closest(PROTECT)) { return true; }
        }
      }
    }
    return false;
  }

  function check() {
    checkT = 0;
    if (!box) { return; }
    if (covered()) {
      clearTimeout(untuckT);
      untuckT = 0;
      box.classList.add("is-tuck");
    } else if (box.classList.contains("is-tuck") && !untuckT) {
      untuckT = setTimeout(function () {
        untuckT = 0;
        if (!covered()) { box.classList.remove("is-tuck"); }
      }, UNTUCK_MS);
    }
  }

  function scheduleCheck(ms) {
    if (checkT) { return; }
    checkT = setTimeout(check, ms == null ? 140 : ms);
  }

  function onFocus(e) {
    if (!box) { return; }
    var n = e.target;
    var typing = !!(n && n.matches && n.matches(FIELD)) && (root.innerWidth || 0) <= 760;
    box.classList.toggle("is-typing", typing && e.type === "focusin");
  }

  function bind() {
    root.addEventListener("scroll", function () { scheduleCheck(); }, { passive: true });
    root.addEventListener("resize", function () { measure(); scheduleCheck(); }, { passive: true });
    if (root.visualViewport) {
      root.visualViewport.addEventListener("resize", function () { scheduleCheck(); }, { passive: true });
    }
    doc.addEventListener("focusin", onFocus);
    doc.addEventListener("focusout", onFocus);
    [800, 2500, 6000].forEach(function (ms) {
      setTimeout(function () { measure(); scheduleCheck(0); }, ms);
    });
  }

  function onWallet(e) {
    var d = e.detail || {};
    if (!st || st.guest) { return; }
    if (d.balance != null) { st.balance = +d.balance || 0; }
    if (d.earned != null) { st.earned = +d.earned || 0; }
    if (d.tickets != null) { st.tickets = +d.tickets || 0; }
    if (d.won === false && st.next === "won") { st.next = null; }
    if (d.won === true) { st.next = "won"; }
    render();
  }

  function startGuest() {
    pub(false).then(function (d) {
      var h = d && d.hunt;
      if (!h || !GUEST_PHASES[h.phase]) { return; }
      st = {
        guest: true,
        phase: h.phase,
        day: h.day,
        earned: 0,
        balance: 0,
        tickets: 0,
        cap: +h.cap || 0,
        price: +h.price || 0,
        limit: +h.limit || 0,
        secs: 0,
        full: false,
        next: null
      };
      render();
      bind();
    });
  }

  function start(s) {
    if (s && s.user) {
      if (!s.hunt) { return; }
      fromSession(s.hunt);
      render();
      bind();
      queueGains(s.hunt.gains, s.hunt.titles_new);
      emit(s.hunt.gains);
      doc.addEventListener("visibilitychange", onVisibility);
      doc.addEventListener("hunt:wallet", onWallet);
      scheduleDay();
      startBeats();
      return;
    }
    startGuest();
  }

  root.NX_HUNT = {
    session: session,
    pub: pub,
    state: function () { return st; }
  };

  function init() {
    session().then(start);
  }

  if (doc.readyState === "loading") { doc.addEventListener("DOMContentLoaded", init); }
  else { init(); }
})(window);
