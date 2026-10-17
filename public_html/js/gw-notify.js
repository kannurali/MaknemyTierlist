(function (root) {
  "use strict";

  var LINK = "/api/tg_link.php";
  var TG_URL = /^https:\/\/t\.me\//;
  var POLL_MS = 3000;
  var POLL_FOR = 10 * 60 * 1000;
  var TIME_MIN = 300;
  var TIME_MAX = 7776000;
  var TIME_DEFAULT = 3600;
  var PLACES_MAX = 100000;
  var PLACES_DEFAULT = 10;
  var UNITS = [86400, 3600, 60];
  var UNIT_KEY = { 60: "min", 3600: "hour", 86400: "day" };

  var GW = root.NX_GW;

  function $(id) { return document.getElementById(id); }

  var box = $("gwNotify");
  var lead = $("gwLead");
  if (!GW || !box) { return; }

  var ui = {
    title: $("gwNtTitle"),
    note: $("gwNtNote"),
    go: $("gwNtGo"),
    bot: $("gwNtBot"),
    set: $("gwNtSet"),
    msg: $("gwNtMsg"),
    panel: $("gwNtPanel"),
    on: $("gwNtOn"),
    rem: $("gwNtRem"),
    time: $("gwNtTime"),
    timeN: $("gwNtTimeN"),
    timeU: $("gwNtTimeU"),
    places: $("gwNtPlaces"),
    placesN: $("gwNtPlacesN"),
    placesU: $("gwNtPlacesU"),
    save: $("gwNtSave"),
    saved: $("gwNtSaved")
  };

  var tg = null;
  var st = {
    active: false,
    state: "ask",
    busy: false,
    saving: false,
    url: "",
    poll: 0,
    until: 0,
    self: false,
    msg: "",
    msgBad: false,
    saved: "",
    savedKind: ""
  };

  function lang() { return document.documentElement.lang === "en" ? "en" : "ru"; }

  function t(key, vars) { return root.I18N ? root.I18N.t(key, lang(), vars) : key; }

  function plural(n) {
    if (lang() === "en") { return n === 1 ? "one" : "many"; }
    var a = n % 10, b = n % 100;
    if (a === 1 && b !== 11) { return "one"; }
    if (a >= 2 && a <= 4 && (b < 12 || b > 14)) { return "few"; }
    return "many";
  }

  function unitWord(unit, n) { return t("gw.notify." + UNIT_KEY[unit] + "." + plural(n)); }

  function placeWord(n) { return t("gw.notify.place." + plural(n)); }

  function num(n) {
    try { return n.toLocaleString(lang() === "en" ? "en-US" : "ru-RU"); } catch (_) { return String(n); }
  }

  function post(body) {
    return fetch(LINK, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: r.status === 429 ? "rate_limited" : "net" }; });
    }).catch(function () { return { ok: false, error: "net" }; });
  }

  function goLogin() {
    var auth = root.MKAuth;
    if (auth) { location.assign(auth.startUrl(location.pathname + location.search)); }
  }

  function subscribed() {
    return !!(tg && tg.linked && (!tg.gw || tg.gw.subscribed !== false));
  }

  function bestUnit(sec) {
    for (var i = 0; i < UNITS.length; i++) {
      if (sec % UNITS[i] === 0) { return UNITS[i]; }
    }
    return 60;
  }

  function split(sec) {
    var u = bestUnit(sec);
    return { u: u, n: Math.max(1, Math.round(sec / u)) };
  }

  function summary() {
    var g = tg && tg.gw;
    if (!g) { return ""; }
    var time = "";
    var places = "";
    if (g.time_left > 0) {
      var p = split(g.time_left);
      time = p.n + " " + unitWord(p.u, p.n);
    }
    if (g.places_left > 0) { places = num(g.places_left) + " " + placeWord(g.places_left); }
    if (time && places) { return t("gw.notify.sumBoth", { time: time, places: places }); }
    if (time) { return t("gw.notify.sumTime", { time: time }); }
    if (places) { return t("gw.notify.sumPlaces", { places: places }); }
    return "";
  }

  function noteText() {
    if (st.state === "done") {
      var to = tg && tg.name ? t("gw.notify.doneTo", { name: tg.name }) : t("gw.notify.doneAny");
      var sum = summary();
      return sum ? to + " " + sum : to;
    }
    if (st.state === "off") { return t("gw.notify.offNote"); }
    return t("gw.notify.note");
  }

  function titleKey() {
    if (st.state === "done") { return "gw.notify.done"; }
    if (st.state === "off") { return "gw.notify.off"; }
    return "gw.notify.ask";
  }

  function say(key, bad) {
    st.msg = key || "";
    st.msgBad = !!bad;
    ui.msg.textContent = st.msg ? t(st.msg) : "";
    ui.msg.classList.toggle("is-bad", st.msgBad);
  }

  function setSaved(key, kind) {
    st.saved = key || "";
    st.savedKind = kind || "";
    ui.saved.textContent = st.saved ? t(st.saved) : "";
    ui.saved.classList.toggle("is-bad", st.savedKind === "bad");
    ui.saved.classList.toggle("is-ok", st.savedKind === "ok");
  }

  function visible(n) { return !!n && !n.hidden && n.getClientRects().length > 0; }

  function render() {
    var s = st.state;
    var had = box.contains(document.activeElement) ? document.activeElement : null;
    box.setAttribute("data-state", s);
    box.setAttribute("data-linked", tg && tg.linked ? "1" : "0");
    ui.title.textContent = t(titleKey());
    ui.note.textContent = noteText();
    ui.go.hidden = s !== "ask";
    ui.go.setAttribute("aria-disabled", st.busy ? "true" : "false");
    ui.go.classList.toggle("is-busy", st.busy);
    ui.bot.hidden = s !== "wait";
    ui.set.hidden = !(s === "done" || s === "off") || !(tg && tg.gw);
    if (ui.set.hidden && !ui.panel.hidden) { ui.panel.hidden = true; ui.set.setAttribute("aria-expanded", "false"); }
    if (had && !visible(had)) {
      var next = [ui.go, ui.bot, ui.set].filter(visible)[0];
      if (next) { next.focus(); }
    }
  }

  function setState(s) {
    var was = st.state;
    st.state = s;
    render();
    if (s === "done" && was !== "done" && st.active) {
      box.classList.remove("is-pop");
      void box.offsetWidth;
      box.classList.add("is-pop");
    }
  }

  function show() {
    if (st.active) { return; }
    st.active = true;
    if (lead) { lead.hidden = true; }
    box.hidden = false;
    box.classList.add("is-in");
  }

  function announce() {
    st.self = true;
    try { GW.tgLinked(tg); } finally { st.self = false; }
  }

  function stopPoll() { clearTimeout(st.poll); st.poll = 0; }

  function checkLink() {
    stopPoll();
    if (st.state !== "wait") { return; }
    if (Date.now() > st.until) {
      st.url = "";
      setState("ask");
      say("gw.notify.timeout", true);
      return;
    }
    post({ action: "status" }).then(function (r) {
      if (st.state !== "wait") { return; }
      if (r && r.ok && r.tg && r.tg.linked) {
        tg = r.tg;
        finishLink();
        return;
      }
      if (r && r.error === "not_logged_in") { goLogin(); return; }
      schedule();
    });
  }

  function schedule() {
    stopPoll();
    if (st.state !== "wait") { return; }
    st.poll = setTimeout(function () {
      if (document.hidden) { schedule(); return; }
      checkLink();
    }, POLL_MS);
  }

  function wait(blocked) {
    st.until = Date.now() + POLL_FOR;
    ui.bot.href = st.url;
    setState("wait");
    say(blocked ? "gw.notify.waitOpen" : "gw.notify.wait");
    schedule();
  }

  function finishLink() {
    stopPoll();
    st.url = "";
    if (!tg.gw) {
      say("");
      announce();
      setState("done");
      return;
    }
    st.busy = true;
    say("");
    post({ action: "gw_prefs", subscribed: true }).then(function (r) {
      st.busy = false;
      var ok = !!(r && r.ok && r.gw);
      if (ok) { tg.gw = r.gw; }
      announce();
      setState(subscribed() ? "done" : "ask");
      if (!ok) { say(r && r.error === "rate_limited" ? "gw.notify.errRate" : "gw.notify.err", true); }
    });
  }

  function subscribe() {
    if (st.busy || !tg) { return; }
    if (tg.linked) {
      st.busy = true;
      say("");
      render();
      post({ action: "gw_prefs", subscribed: true }).then(function (r) {
        st.busy = false;
        if (r && r.ok && r.gw) {
          tg.gw = r.gw;
          setState(subscribed() ? "done" : "ask");
          return;
        }
        if (r && r.error === "not_logged_in") { goLogin(); return; }
        render();
        say(r && r.error === "rate_limited" ? "gw.notify.errRate" : "gw.notify.err", true);
      });
      return;
    }
    var w = window.open("", "_blank");
    if (w) { w.opener = null; }
    st.busy = true;
    say("");
    render();
    post({ action: "link", lang: lang() }).then(function (r) {
      st.busy = false;
      if (r && r.ok && typeof r.url === "string" && TG_URL.test(r.url)) {
        st.url = r.url;
        if (w) { w.location.href = r.url; }
        wait(!w);
        return;
      }
      if (w) { w.close(); }
      if (r && r.error === "not_logged_in") { goLogin(); return; }
      render();
      say(r && r.error === "off" ? "gw.err.tg_off" : "gw.err.net", true);
    });
  }

  function timeBounds() {
    var u = +ui.timeU.value || 3600;
    ui.timeN.min = String(Math.ceil(TIME_MIN / u));
    ui.timeN.max = String(Math.floor(TIME_MAX / u));
  }

  function words() {
    var n = parseInt(ui.timeN.value, 10);
    n = n > 0 ? n : 1;
    Array.prototype.forEach.call(ui.timeU.options, function (o) {
      o.textContent = unitWord(+o.value, n);
    });
    var p = parseInt(ui.placesN.value, 10);
    ui.placesU.textContent = placeWord(p > 0 ? p : PLACES_DEFAULT);
  }

  function sync() {
    var on = ui.on.checked;
    ui.rem.disabled = !on;
    ui.timeN.disabled = !ui.time.checked;
    ui.timeU.disabled = !ui.time.checked;
    ui.placesN.disabled = !ui.places.checked;
    ui.rem.classList.toggle("is-off", !on);
    timeBounds();
    words();
  }

  function fill() {
    var g = tg.gw;
    ui.on.checked = g.subscribed !== false;
    var tl = g.time_left > 0 ? g.time_left : 0;
    var p = split(tl || TIME_DEFAULT);
    ui.time.checked = tl > 0;
    ui.timeU.value = String(p.u);
    ui.timeN.value = String(p.n);
    ui.places.checked = g.places_left > 0;
    ui.placesN.value = String(g.places_left > 0 ? g.places_left : PLACES_DEFAULT);
    sync();
  }

  function fixTime() {
    var u = +ui.timeU.value || 3600;
    var n = Math.round(parseFloat(ui.timeN.value));
    if (n !== n) {
      ui.timeU.value = String(TIME_DEFAULT);
      ui.timeN.value = "1";
      sync();
      return { sec: TIME_DEFAULT, fixed: false };
    }
    var sec = n * u;
    var clamped = Math.min(TIME_MAX, Math.max(TIME_MIN, sec));
    var p = clamped === sec ? { u: u, n: n } : split(clamped);
    ui.timeU.value = String(p.u);
    ui.timeN.value = String(p.n);
    sync();
    return { sec: clamped, fixed: clamped !== sec };
  }

  function fixPlaces() {
    var raw = ui.placesN.value;
    var n = Math.round(parseFloat(raw));
    if (n !== n) {
      ui.placesN.value = String(PLACES_DEFAULT);
      sync();
      return { n: PLACES_DEFAULT, fixed: false };
    }
    var c = Math.min(PLACES_MAX, Math.max(1, n));
    ui.placesN.value = String(c);
    sync();
    return { n: c, fixed: c !== n };
  }

  function readForm() {
    var out = { subscribed: ui.on.checked, time_left: 0, places_left: 0, note: "" };
    if (ui.time.checked) {
      var a = fixTime();
      out.time_left = a.sec;
      if (a.fixed) { out.note = "gw.notify.rangeTime"; }
    }
    if (ui.places.checked) {
      var b = fixPlaces();
      out.places_left = b.n;
      if (b.fixed && !out.note) { out.note = "gw.notify.rangePlaces"; }
    }
    return out;
  }

  function save(e) {
    if (e) { e.preventDefault(); }
    if (st.saving || !tg || !tg.gw) { return; }
    var v = readForm();
    st.saving = true;
    ui.save.setAttribute("aria-disabled", "true");
    ui.save.classList.add("is-busy");
    ui.panel.setAttribute("aria-busy", "true");
    setSaved("gw.notify.saving", "");
    post({ action: "gw_prefs", subscribed: v.subscribed, time_left: v.time_left, places_left: v.places_left }).then(function (r) {
      st.saving = false;
      ui.save.removeAttribute("aria-disabled");
      ui.save.classList.remove("is-busy");
      ui.panel.removeAttribute("aria-busy");
      if (r && r.ok && r.gw) {
        tg.gw = r.gw;
        setState(r.gw.subscribed === false ? "off" : "done");
        if (v.note) { setSaved(v.note, "note"); } else { setSaved("gw.notify.saved", "ok"); }
        return;
      }
      if (r && r.error === "not_logged_in") { goLogin(); return; }
      setSaved(r && r.error === "rate_limited" ? "gw.notify.errRate" : "gw.notify.err", "bad");
    });
  }

  function openPanel(on) {
    if (on === !ui.panel.hidden) { return; }
    if (on) {
      if (!tg || !tg.gw) { return; }
      setSaved("", "");
      fill();
    }
    ui.panel.hidden = !on;
    ui.set.setAttribute("aria-expanded", on ? "true" : "false");
    if (!on && tg && tg.gw && tg.gw.subscribed === false) {
      say("");
      setState("ask");
    }
  }

  ui.go.addEventListener("click", subscribe);

  ui.bot.addEventListener("click", function () {
    if (st.state !== "wait") { return; }
    st.until = Date.now() + POLL_FOR;
    say("gw.notify.wait");
    schedule();
  });

  ui.set.addEventListener("click", function () { openPanel(ui.panel.hidden); });

  ui.panel.addEventListener("submit", save);

  ui.on.addEventListener("change", function () { setSaved("", ""); sync(); });

  ui.time.addEventListener("change", function () {
    if (ui.time.checked && !(parseFloat(ui.timeN.value) > 0)) {
      ui.timeU.value = "3600";
      ui.timeN.value = "1";
    }
    setSaved("", "");
    sync();
  });

  ui.places.addEventListener("change", function () {
    if (ui.places.checked && !(parseFloat(ui.placesN.value) > 0)) { ui.placesN.value = String(PLACES_DEFAULT); }
    setSaved("", "");
    sync();
  });

  ui.timeN.addEventListener("input", words);
  ui.placesN.addEventListener("input", words);
  ui.timeU.addEventListener("change", function () { setSaved("", ""); sync(); });

  ui.timeN.addEventListener("change", function () {
    if (ui.timeN.value === "") { return; }
    if (fixTime().fixed) { setSaved("gw.notify.rangeTime", "note"); }
  });

  ui.placesN.addEventListener("change", function () {
    if (ui.placesN.value === "") { return; }
    if (fixPlaces().fixed) { setSaved("gw.notify.rangePlaces", "note"); }
  });

  box.addEventListener("keydown", function (e) {
    if (e.key !== "Escape" || ui.panel.hidden) { return; }
    e.preventDefault();
    var inside = ui.panel.contains(document.activeElement) || document.activeElement === ui.set;
    openPanel(false);
    if (inside) {
      var next = [ui.set, ui.go].filter(visible)[0];
      if (next) { next.focus(); }
    }
  });

  box.addEventListener("animationend", function (e) {
    if (e.target === box) { box.classList.remove("is-in"); }
  });

  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "visible" && st.state === "wait") { checkLink(); }
  });

  document.addEventListener("mk:tg-linked", function (e) {
    if (!st.active || st.self) { return; }
    var next = e.detail;
    if (!next || !next.linked) { return; }
    tg = next;
    if (st.state === "wait") { finishLink(); return; }
    if (!ui.panel.hidden) { return; }
    say("");
    setState(subscribed() ? "done" : "ask");
  });

  document.addEventListener("mk:langchange", function () {
    if (!st.active) { return; }
    render();
    say(st.msg, st.msgBad);
    setSaved(st.saved, st.savedKind);
    words();
  });

  GW.session().then(function (s) {
    if (!s || !s.user) { return; }
    return GW.tgStatus().then(function (x) {
      if (!x || x.on === false) { return; }
      tg = x;
      st.state = subscribed() ? "done" : "ask";
      render();
      show();
    });
  });
})(window);
