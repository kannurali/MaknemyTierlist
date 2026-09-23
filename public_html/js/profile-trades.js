(() => {
  "use strict";

  const list = document.getElementById("pfTrades");
  if (!list) return;

  const stateEl = document.getElementById("pfTradesState");
  const quotaEl = document.getElementById("pfTradesQuota");

  const LANG_KEY = "nexus-lang-v1";
  const API_MINE = "/api/trades.php?view=mine";

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  const state = {
    catalog: {},
    catalogReady: false,
    authed: true,
    canLogin: false,
    admin: false,
    offers: null,
    quota: null,
    ready: true,
    error: false
  };

  const cards = TRADE_CARDS.make({ tx: tx, state: state, loginUrl: () => "/profile" });

  function renderQuota() {
    const q = state.quota;
    if (!q) { quotaEl.hidden = true; return; }
    quotaEl.hidden = false;
    if (q.activeLeft === 0) {
      quotaEl.textContent = tx("trade.activeOut", { max: q.activeMax });
      quotaEl.classList.add("is-bad");
    } else if (q.left > 0) {
      quotaEl.textContent = tx("trade.quotaLeft", { active: q.active, activeMax: q.activeMax, n: q.left });
      quotaEl.classList.remove("is-bad");
    } else {
      const wait = q.retryAt - Math.floor(Date.now() / 1000);
      quotaEl.textContent = tx("trade.quotaOut", { max: q.max, t: cards.duration(wait) });
      quotaEl.classList.add("is-bad");
    }
  }

  function render() {
    list.textContent = "";
    let msg = "";
    if (state.error) { msg = tx("trade.loadError"); }
    else if (!state.ready) { msg = tx("trade.notReady"); }
    else if (state.offers === null) { msg = tx("trade.loading"); }
    else if (!state.offers.length) { msg = tx("profile.tradesEmpty"); }
    stateEl.textContent = msg;
    stateEl.hidden = !msg;
    (state.offers || []).forEach(o => list.appendChild(cards.buildCard(o)));
    renderQuota();
  }

  async function load() {
    try {
      const r = await fetch(API_MINE, { cache: "no-store", credentials: "same-origin" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const d = await r.json();
      state.ready = !!d.ready;
      state.offers = Array.isArray(d.offers) ? d.offers : [];
      state.quota = d.quota || null;
      state.error = false;
    } catch (_) {
      state.error = true;
    }
    render();
  }

  async function loadCatalog() {
    try {
      const cat = await TRADE_CARDS.loadCatalog();
      state.catalog = cat.index;
    } catch (e) {
      console.warn("profile: не удалось загрузить тирлист", e);
    }
    state.catalogReady = true;
    render();
  }

  list.addEventListener("click", async e => {
    const btn = e.target.closest("button[data-act]");
    if (!btn) return;
    const card = btn.closest(".tr-card");
    if (card) { card.classList.add("is-busy"); }
    const ok = await TRADE_CARDS.close(Number(btn.dataset.id), btn.dataset.act, tx);
    if (ok) {
      location.reload();
      return;
    }
    if (card) { card.classList.remove("is-busy"); }
    if (ok === false) { window.alert(tx("trade.closeFailed")); }
  });

  const langBox = document.getElementById("langSwitch");
  if (langBox) {
    langBox.addEventListener("click", e => {
      const b = e.target.closest("[data-lang]");
      if (!b) return;
      lang = b.dataset.lang;
      render();
    });
  }

  render();
  loadCatalog();
  load();
})();
