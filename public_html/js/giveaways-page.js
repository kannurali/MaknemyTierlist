(function () {
  "use strict";

  var LANG_KEY = "nexus-lang-v1";
  var POLL_MS = 15000;
  var POLL_TRIES = 12;

  var i18n = window.I18N;
  var GW = window.NX_GW;
  var lang = "ru";
  var data = null;
  var failed = false;
  var resume = 0;
  var focused = false;
  var pollLeft = 0;
  var pollTimer = 0;

  function $(id) { return document.getElementById(id); }

  function all(d) { return d ? d.live.concat(d.past) : []; }

  function sig(d) {
    return all(d).map(function (g) { return g.id + ":" + g.phase + ":" + (g.winners ? g.winners.length : 0); }).join("|");
  }

  function pending() {
    var now = Date.now() / 1000;
    return all(data).some(function (g) { return g.phase === "ended" || (g.phase === "live" && g.ends_at <= now); });
  }

  function watch() {
    clearTimeout(pollTimer);
    if (!GW || !pending() || pollLeft <= 0) return;
    pollLeft--;
    pollTimer = setTimeout(function () {
      GW.load(true).then(function (d) {
        if (d && Array.isArray(d.live)) {
          var next = { live: d.live, past: Array.isArray(d.past) ? d.past : [] };
          if (sig(next) !== sig(data)) { data = next; render(); }
        }
        watch();
      });
    }, POLL_MS);
  }

  function startWatch(e) {
    var id = e && e.detail ? e.detail.id : 0;
    all(data).forEach(function (g) { if (id && g.id === id && g.phase === "live") { g.phase = "ended"; } });
    pollLeft = POLL_TRIES;
    watch();
  }

  function t(key) { return i18n ? i18n.t(key, lang) : key; }

  function render() {
    var live = $("gwLive");
    var past = $("gwPast");
    var state = $("gwState");
    if (!live || !past || !state) return;
    live.textContent = "";
    past.textContent = "";

    if (!data) {
      state.textContent = t(failed ? "gw.error" : "gw.loading");
      state.hidden = false;
      $("gwPastWrap").hidden = true;
      return;
    }

    state.hidden = data.live.length > 0;
    state.textContent = data.live.length ? "" : t("gw.none");
    data.live.forEach(function (g) { live.appendChild(GW.card(g, { mode: "page" })); });

    $("gwPastWrap").hidden = !data.past.length;
    data.past.forEach(function (g) { past.appendChild(GW.card(g, { mode: "page" })); });

    if (!focused) {
      focused = true;
      var m = /^#g(\d+)(?:-tg)?$/.exec(location.hash);
      var target = m ? $("g" + m[1]) : null;
      if (target) {
        target.classList.add("is-target");
        target.scrollIntoView({ block: "start" });
      }
      if (resume) {
        var c = $("g" + resume);
        if (c && c.gwJoin) c.gwJoin();
      }
    }
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
    render();
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

  if (GW) {
    resume = GW.takeResume();
    GW.load().then(function (d) {
      if (!d || !Array.isArray(d.live)) { failed = true; render(); return; }
      data = { live: d.live, past: Array.isArray(d.past) ? d.past : [] };
      render();
      startWatch();
    });
    document.addEventListener("mk:gw-ended", startWatch);
  }
})();
