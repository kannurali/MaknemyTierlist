(() => {
  "use strict";

  const $ = id => document.getElementById(id);

  const LANG_KEY = "nexus-lang-v1";
  const INVITE_KEY = "nexus-signin-v1";
  const API_SEND = "/api/support.php";
  const API_SESSION = "/api/session.php";
  const MIN_CHARS = 10;

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  const form = $("spForm");
  const body = $("spBody");
  const send = $("spSend");
  const status = $("spStatus");
  const thanks = $("spThanks");
  const closeBtn = $("spThanksClose");

  const st = {
    session: undefined,
    canLogin: false,
    busy: false,
    msg: "",
    msgVars: null,
    bad: false
  };

  function user() {
    return st.session && st.session.user ? st.session.user : null;
  }

  function render() {
    const me = user();
    let key = "support.send";
    if (st.session !== undefined && !me) { key = st.canLogin ? "support.login" : ""; }
    if (st.busy) { key = "support.sending"; }

    send.hidden = key === "";
    if (key) {
      send.dataset.i18n = key;
      send.textContent = tx(key);
    }
    send.disabled = st.busy || st.session === undefined;
    body.disabled = st.session !== undefined && !me;

    let msg = st.msg ? tx(st.msg, st.msgVars || undefined) : "";
    if (!msg && st.session !== undefined && !me) {
      msg = tx(st.canLogin ? "support.loginHint" : "support.closed");
    }
    status.textContent = msg;
    status.classList.toggle("is-bad", st.bad && !!st.msg);
  }

  function applyLang(next) {
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) {}
    }
    document.documentElement.lang = lang;
    document.querySelectorAll("[data-i18n]").forEach(node => { node.textContent = tx(node.dataset.i18n); });
    document.querySelectorAll("[data-i18n-title]").forEach(node => { node.title = tx(node.dataset.i18nTitle); });
    document.querySelectorAll("[data-i18n-placeholder]").forEach(node => { node.placeholder = tx(node.dataset.i18nPlaceholder); });
    document.querySelectorAll("[data-i18n-label]").forEach(node => { node.setAttribute("aria-label", tx(node.dataset.i18nLabel)); });
    document.querySelectorAll("#langSwitch [data-lang]").forEach(b => {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    });
    render();
  }

  let lastFocus = null;

  function openThanks() {
    lastFocus = document.activeElement;
    thanks.hidden = false;
    document.body.style.overflow = "hidden";
    closeBtn.focus();
  }

  function closeThanks() {
    if (thanks.hidden) return;
    thanks.hidden = true;
    document.body.style.overflow = "";
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }

  async function submit(e) {
    e.preventDefault();
    const me = user();
    if (!me) {
      if (st.canLogin && window.MKAuth) { location.assign(window.MKAuth.startUrl()); }
      return;
    }
    if (st.busy) return;

    const text = body.value.trim();
    if (Array.from(text).length < MIN_CHARS) {
      st.msg = "support.tooShort";
      st.msgVars = { n: MIN_CHARS };
      st.bad = true;
      render();
      body.focus();
      return;
    }

    st.busy = true;
    st.msg = "";
    st.bad = false;
    render();
    try {
      const r = await fetch(API_SEND, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ body: text })
      });
      const d = await r.json().catch(() => null);
      if (r.ok && d && d.ok) {
        body.value = "";
        st.busy = false;
        render();
        openThanks();
        return;
      }
      const err = d && d.error;
      if (r.status === 401) {
        st.session = Object.assign({}, st.session, { user: null });
      } else {
        st.msg = err === "too_short" ? "support.tooShort"
          : err === "not_ready" ? "support.notReady"
          : r.status === 429 ? "support.rate"
          : "support.failed";
        st.msgVars = err === "too_short" ? { n: d.min || MIN_CHARS } : null;
        st.bad = true;
      }
    } catch (_) {
      st.msg = "support.failed";
      st.msgVars = null;
      st.bad = true;
    }
    st.busy = false;
    render();
  }

  async function loadSession() {
    let s = null;
    try {
      const r = await fetch(API_SESSION, { cache: "no-store", credentials: "same-origin" });
      s = r.ok ? await r.json() : null;
    } catch (_) {
      s = null;
    }
    let invited = false;
    try { invited = localStorage.getItem(INVITE_KEY) === "1" || /[?&]signin(=|&|$)/.test(location.search); } catch (_) {}
    st.session = s || {};
    st.canLogin = !!(s && s.roblox && (s.roblox_public || invited));
    render();
  }

  form.addEventListener("submit", submit);
  body.addEventListener("input", () => {
    if (st.msg) { st.msg = ""; st.bad = false; render(); }
  });

  closeBtn.addEventListener("click", closeThanks);
  thanks.addEventListener("click", e => { if (e.target === thanks) closeThanks(); });
  document.addEventListener("keydown", e => {
    if (thanks.hidden) return;
    if (e.key === "Escape") { closeThanks(); return; }
    if (e.key === "Tab") { e.preventDefault(); closeBtn.focus(); }
  });

  const langBox = $("langSwitch");
  if (langBox) {
    langBox.addEventListener("click", e => {
      const b = e.target.closest("[data-lang]");
      if (b) applyLang(b.dataset.lang);
    });
  }

  applyLang();
  loadSession();
})();
