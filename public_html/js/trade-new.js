(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);

  const LANG_KEY = "nexus-lang-v1";
  const INVITE_KEY = "nexus-signin-v1";
  const API_CREATE = "/api/trade_create.php";
  const API_SESSION = "/api/session.php";

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  const btn = $("#tnPublish");
  const label = $("#tnPublishText");
  const status = $("#tnStatus");

  const st = {
    session: undefined,
    canLogin: false,
    busy: false,
    error: "",
    errorVars: null
  };

  function sides() {
    return window.NX_CALC ? window.NX_CALC.sides() : { left: [], right: [] };
  }

  function render() {
    const s = sides();
    const user = st.session && st.session.user;
    let key = "trade.publish";
    let disabled = false;
    let hint = "";
    let bad = false;

    if (st.session === undefined) {
      disabled = true;
    } else if (!user) {
      if (st.canLogin) {
        key = "trade.publishLogin";
        hint = tx("trade.hintLogin");
      } else {
        key = "trade.publishSoon";
        disabled = true;
      }
    } else if (st.busy) {
      key = "trade.publishing";
      disabled = true;
    } else if (!s.left.length) {
      disabled = true;
      hint = tx("trade.hintEmpty");
    } else if (!s.right.length) {
      hint = tx("trade.hintAny");
    }

    if (st.error) {
      hint = tx(st.error, st.errorVars || undefined);
      bad = true;
    }

    label.dataset.i18n = key;
    label.textContent = tx(key);
    btn.disabled = disabled;
    btn.setAttribute("aria-busy", st.busy ? "true" : "false");
    status.textContent = hint;
    status.classList.toggle("is-bad", bad);
  }

  function errorKey(status, err) {
    if (err === "too_many") return "trade.errTooMany";
    if (err === "bad_items") return "trade.errBadItems";
    if (err === "empty_give") return "trade.hintEmpty";
    if (err === "not_ready") return "trade.errNotReady";
    if (status === 429) return "trade.errRate";
    return "trade.errFailed";
  }

  async function publish() {
    const user = st.session && st.session.user;
    if (!user) {
      if (st.canLogin && window.MKAuth) { location.assign(window.MKAuth.startUrl()); }
      return;
    }
    const s = sides();
    if (!s.left.length || st.busy) return;

    st.busy = true;
    st.error = "";
    render();
    try {
      const r = await fetch(API_CREATE, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ give: s.left, want: s.right })
      });
      const d = await r.json().catch(() => null);
      if (r.ok && d && d.ok) {
        location.assign("/trading?posted=" + encodeURIComponent(d.id));
        return;
      }
      if (r.status === 401) {
        st.session = Object.assign({}, st.session, { user: null });
      } else {
        st.error = errorKey(r.status, d && d.error);
        st.errorVars = d && d.error === "too_many" ? { n: d.max } : null;
      }
    } catch (_) {
      st.error = "trade.errFailed";
      st.errorVars = null;
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

  btn.addEventListener("click", publish);

  document.addEventListener("tc:change", () => {
    st.error = "";
    render();
  });

  const langBox = $("#langSwitch");
  if (langBox) {
    langBox.addEventListener("click", e => {
      const b = e.target.closest("[data-lang]");
      if (!b) return;
      lang = b.dataset.lang;
      render();
    });
  }

  render();
  loadSession();
})();
