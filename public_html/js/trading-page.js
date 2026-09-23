(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);

  const PROMO_PAGE = "calc";
  const LANG_KEY = "nexus-lang-v1";
  const INVITE_KEY = "nexus-signin-v1";

  const API_FEED = "/api/trades.php";
  const API_SESSION = "/api/session.php";
  const PROMO_API = "/api/promo.php";

  const SEARCH_DEBOUNCE_MS = 350;
  const NOTICE_MS = 6000;

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  const state = {
    catalog: {},
    catalogReady: false,
    catalogRev: null,
    offers: [],
    mine: [],
    more: false,
    query: "",
    loading: false,
    error: false,
    ready: true,
    authed: false,
    admin: false,
    session: null,
    canLogin: false,
    notice: "",
    seq: 0
  };

  function loginUrl() {
    return window.MKAuth ? window.MKAuth.startUrl() : "/api/roblox_start.php";
  }

  const cards = TRADE_CARDS.make({ tx: tx, state: state, loginUrl: loginUrl });

  function render() {
    const mineList = $("#trMine");
    const feed = $("#trFeed");
    const stateEl = $("#trState");

    const showMine = state.mine.length > 0 && !state.query;
    $("#trMineTitle").hidden = !showMine;
    $("#trAllTitle").hidden = !showMine;
    mineList.hidden = !showMine;

    mineList.textContent = "";
    if (showMine) { state.mine.forEach(o => mineList.appendChild(cards.buildCard(o))); }

    feed.textContent = "";
    const list = showMine ? state.offers.filter(o => !o.mine) : state.offers;
    list.forEach(o => feed.appendChild(cards.buildCard(o)));

    let msg = "";
    let good = false;
    if (state.notice) { msg = state.notice; good = true; }
    else if (state.loading && !state.offers.length) { msg = tx("trade.loading"); }
    else if (state.error) { msg = tx("trade.loadError"); }
    else if (!state.ready) { msg = tx("trade.notReady"); }
    else if (!state.offers.length && !state.mine.length) {
      msg = state.query ? tx("trade.noResults", { q: state.query }) : tx("trade.empty");
    } else if (!state.offers.length && state.query) {
      msg = tx("trade.noResults", { q: state.query });
    }
    stateEl.textContent = msg;
    stateEl.hidden = !msg;
    stateEl.classList.toggle("is-good", good);

    $("#trMore").hidden = !state.more || state.loading;
  }

  async function loadFeed(reset) {
    const seq = ++state.seq;
    state.loading = true;
    if (reset) {
      state.offers = [];
      state.more = false;
      state.error = false;
    }
    render();

    const params = new URLSearchParams();
    if (state.query) { params.set("q", state.query); }
    if (!reset && state.offers.length) {
      params.set("before", String(state.offers[state.offers.length - 1].id));
    }
    const qs = params.toString();

    try {
      const r = await fetch(API_FEED + (qs ? "?" + qs : ""), { cache: "no-store", credentials: "same-origin" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const d = await r.json();
      if (seq !== state.seq) { return; }
      state.ready = !!d.ready;
      state.authed = !!d.authed;
      state.admin = !!d.admin;
      if (reset) { state.mine = Array.isArray(d.mine) ? d.mine : []; }
      const seen = new Set(state.offers.map(o => o.id));
      const fresh = (Array.isArray(d.offers) ? d.offers : []).filter(o => !seen.has(o.id));
      state.offers = state.offers.concat(fresh);
      state.more = !!d.more;
      state.error = false;
    } catch (e) {
      if (seq !== state.seq) { return; }
      state.error = true;
    }
    state.loading = false;
    render();
  }

  async function closeOffer(btn) {
    const id = Number(btn.dataset.id);
    const card = btn.closest(".tr-card");
    if (card) { card.classList.add("is-busy"); }
    const ok = await TRADE_CARDS.close(id, btn.dataset.act, tx);
    if (ok) {
      state.offers = state.offers.filter(o => o.id !== id);
      state.mine = state.mine.filter(o => o.id !== id);
      render();
      return;
    }
    if (card) { card.classList.remove("is-busy"); }
    if (ok === false) { window.alert(tx("trade.closeFailed")); }
  }

  async function loadCatalog() {
    try {
      const cat = await TRADE_CARDS.loadCatalog();
      if (cat.rev === null || cat.rev !== state.catalogRev) {
        state.catalog = cat.index;
        state.catalogRev = cat.rev;
      }
    } catch (e) {
      console.warn("trading: не удалось загрузить тирлист", e);
    }
    state.catalogReady = true;
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
    state.session = s;
    state.canLogin = !!(s && s.roblox && (s.roblox_public || invited));
    render();
  }

  function fillRail(el, camp) {
    const promo = window.PROMO;
    const cre = promo && camp ? promo.creativeFor(camp, "rail") : null;
    if (!cre || !cre.src) return false;

    el.textContent = "";
    el.classList.add("has-ad");

    const img = document.createElement("img");
    img.src = cre.src;
    img.alt = tx("ad.imageAlt");
    img.loading = "lazy";
    img.decoding = "async";
    img.draggable = false;
    el.appendChild(img);

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    el.appendChild(chip);

    if (camp.erid) {
      const erid = document.createElement("span");
      erid.className = "ptn-erid";
      erid.textContent = "erid: " + camp.erid;
      el.appendChild(erid);
    }

    const url = promo.safeHref(camp.href);
    el.classList.toggle("has-link", !!url);
    if (url) {
      const open = () => window.open(url, "_blank", "noopener");
      el.onclick = open;
      el.tabIndex = 0;
      el.setAttribute("role", "link");
      el.onkeydown = e => {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(); }
      };
    }
    return true;
  }

  function renderPromo() {
    const promo = window.PROMO;
    const rail = document.getElementById("trRail");
    const railR = document.getElementById("trRailR");
    const dock = document.getElementById("promoDock");
    if (!promo) return;

    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .catch(() => null)
      .then(doc => {
        if (dock && window.NX_PROMO_DOCK) window.NX_PROMO_DOCK.render(dock, doc, PROMO_PAGE);

        if (window.NX_PROMO_POPUP) {
          window.NX_PROMO_POPUP.mount({ doc, busy: () => false, page: PROMO_PAGE });
        }

        if (!rail || !railR) return;
        const paid = doc ? promo.eligible(promo.normalizeDoc(doc), "rail", Date.now(), PROMO_PAGE) : [];
        const house = promo.houseFor("rail", Date.now(), PROMO_PAGE);
        const list = paid.length ? paid : (house && house.id !== promo.HOUSE_SLOT.id ? [house] : []);
        if (!list.length) return;
        fillRail(rail, list[0]);
        fillRail(railR, list[1] || list[0]);
      });
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

  function takePosted() {
    if (!/[?&]posted=\d+/.test(location.search)) { return; }
    state.notice = tx("trade.posted");
    if (window.history && history.replaceState) {
      try { history.replaceState(null, "", location.pathname); } catch (_) {}
    }
    setTimeout(() => { state.notice = ""; render(); }, NOTICE_MS);
  }

  function wire() {
    const input = $("#trQuery");
    let timer = null;
    const run = () => {
      clearTimeout(timer);
      timer = null;
      const q = input.value.trim().replace(/\s+/g, " ").slice(0, 40);
      if (q === state.query) { return; }
      state.query = q;
      loadFeed(true);
    };
    input.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(run, SEARCH_DEBOUNCE_MS);
    });
    $("#trSearch").addEventListener("submit", e => { e.preventDefault(); run(); });

    $("#trMore").addEventListener("click", () => loadFeed(false));

    $("#trBoard").addEventListener("click", e => {
      const btn = e.target.closest("button[data-act]");
      if (btn) { closeOffer(btn); return; }
      if (e.target.closest("a, button")) { return; }
      const sel = window.getSelection ? String(window.getSelection()) : "";
      if (sel) { return; }
      const card = e.target.closest(".tr-card");
      const link = card ? card.querySelector("a.tr-stretch") : null;
      if (link) { location.assign(link.href); }
    });

    if ("IntersectionObserver" in window) {
      const io = new IntersectionObserver(entries => {
        if (entries.some(en => en.isIntersecting) && state.more && !state.loading) { loadFeed(false); }
      }, { root: $("#trBoard"), rootMargin: "240px" });
      io.observe($("#trMore"));
    }

    const langBox = $("#langSwitch");
    if (langBox) {
      langBox.addEventListener("click", e => {
        const b = e.target.closest("[data-lang]");
        if (b) applyLang(b.dataset.lang);
      });
    }

    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") { loadCatalog(); }
    });
  }

  wire();
  takePosted();
  applyLang();
  loadCatalog();
  loadSession();
  loadFeed(true);
  renderPromo();
})();
