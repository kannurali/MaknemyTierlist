(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const SVG_NS = "http://www.w3.org/2000/svg";

  const PROMO_PAGE = "calc";
  const LANG_KEY = "nexus-lang-v1";
  const INVITE_KEY = "nexus-signin-v1";

  const API_FEED = "/api/trades.php";
  const API_CLOSE = "/api/trade_close.php";
  const API_STATE = "/api/state.php";
  const API_TIERLIST = "/api/tierlist.php";
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

  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  function icon(id, cls) {
    const svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("class", cls);
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    const use = document.createElementNS(SVG_NS, "use");
    use.setAttribute("href", "#" + id);
    svg.appendChild(use);
    return svg;
  }

  function userSilhouette() {
    const svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("viewBox", "0 0 34 34");
    svg.setAttribute("aria-hidden", "true");
    const a = document.createElementNS(SVG_NS, "circle");
    a.setAttribute("cx", "17"); a.setAttribute("cy", "10"); a.setAttribute("r", "7");
    a.setAttribute("fill", "currentColor");
    const b = document.createElementNS(SVG_NS, "path");
    b.setAttribute("d", "M5.6 26.5c0-4.6 4.2-8.1 9.1-8.1h4.6c4.9 0 9.1 3.5 9.1 8.1 0 2.6-5 4.6-11.4 4.6S5.6 29.1 5.6 26.5Z");
    b.setAttribute("fill", "currentColor");
    svg.appendChild(a);
    svg.appendChild(b);
    return svg;
  }

  function loginUrl() {
    return window.MKAuth ? window.MKAuth.startUrl() : "/api/roblox_start.php";
  }

  function ago(ts) {
    const s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 60) { return tx("trade.agoNow"); }
    if (s < 3600) { return tx("trade.agoMin", { n: Math.floor(s / 60) }); }
    if (s < 86400) { return tx("trade.agoHour", { n: Math.floor(s / 3600) }); }
    return tx("trade.agoDay", { n: Math.floor(s / 86400) });
  }

  function itemName(id) {
    const it = state.catalog[id];
    return it && it.name ? it.name : "?";
  }

  function draftFor(offer) {
    const names = ids => ids.map(itemName).join(", ");
    return offer.want.length
      ? tx("trade.draft", { give: names(offer.give), want: names(offer.want) })
      : tx("trade.draftAny", { give: names(offer.give) });
  }

  function chatHref(offer) {
    return "/chat?to=" + encodeURIComponent(offer.author.id) + "&draft=" + encodeURIComponent(draftFor(offer));
  }

  function buildItem(id) {
    const li = el("li", "tr-item");
    if (!state.catalogReady) {
      li.classList.add("is-pending");
      return li;
    }
    const it = state.catalog[id];
    if (!it) {
      li.classList.add("is-missing");
      li.title = tx("trade.itemMissing");
      li.appendChild(el("span", "tr-sr-only", tx("trade.itemMissing")));
      return li;
    }
    li.title = (it.name || "") + " — " + (it.value || "0");

    const badge = document.createElement("img");
    const code = CALC.badgeCodeFor(it.type);
    badge.className = "tr-item-badge";
    badge.src = "assets/design/legend/badge-" + code + ".svg";
    badge.alt = "";
    li.appendChild(badge);

    const pic = document.createElement("img");
    pic.className = "tr-item-icon";
    pic.src = it.icon || "";
    pic.alt = "";
    pic.loading = "lazy";
    pic.decoding = "async";
    li.appendChild(pic);

    li.appendChild(el("span", "tr-sr-only", it.name || ""));
    li.appendChild(el("span", "tr-item-value", it.value || "0"));
    return li;
  }

  function buildSide(ids, cls, labelKey) {
    const ul = el("ul", "tr-items " + cls);
    ul.setAttribute("aria-label", tx(labelKey));
    if (!ids.length) {
      ul.appendChild(el("li", "tr-any", tx("trade.anyOffer")));
      return ul;
    }
    ids.forEach(id => ul.appendChild(buildItem(id)));
    return ul;
  }

  function buildAvatar(author) {
    if (author.avatar) {
      const img = document.createElement("img");
      img.className = "tr-ava";
      img.src = author.avatar;
      img.alt = "";
      img.loading = "lazy";
      img.referrerPolicy = "no-referrer";
      return img;
    }
    const box = el("span", "tr-ava is-empty");
    box.setAttribute("aria-hidden", "true");
    box.appendChild(userSilhouette());
    return box;
  }

  function actButton(key, act, cls, id) {
    const b = el("button", "tr-act " + cls, tx(key));
    b.type = "button";
    b.dataset.act = act;
    b.dataset.id = String(id);
    return b;
  }

  function buildCard(offer) {
    const li = el("li", "tr-card");
    li.dataset.id = String(offer.id);
    if (offer.mine) { li.classList.add("is-mine"); }

    const art = el("article", "tr-card-in");
    const nickId = "trNick" + offer.id + (offer.mine ? "m" : "");
    art.setAttribute("aria-labelledby", nickId);

    const head = el("header", "tr-card-head");
    head.appendChild(buildAvatar(offer.author));

    const who = el("div", "tr-who");
    let nick;
    if (state.authed && !offer.mine) {
      nick = el("a", "tr-nick", offer.author.nick);
      nick.href = "/profile?id=" + encodeURIComponent(offer.author.id);
      nick.title = tx("trade.profile");
    } else {
      nick = el("span", "tr-nick", offer.author.nick);
    }
    nick.id = nickId;
    who.appendChild(nick);
    if (offer.author.handle) { who.appendChild(el("span", "tr-handle", offer.author.handle)); }
    head.appendChild(who);

    const rep = el("span", "tr-rep");
    const up = el("span", "tr-rep-up");
    up.title = tx("trade.likes", { n: offer.author.likes });
    up.appendChild(icon("trIconUp", "tr-rep-icon"));
    up.appendChild(el("b", "", String(offer.author.likes)));
    const down = el("span", "tr-rep-down");
    down.title = tx("trade.dislikes", { n: offer.author.dislikes });
    down.appendChild(icon("trIconDown", "tr-rep-icon"));
    down.appendChild(el("b", "", String(offer.author.dislikes)));
    rep.appendChild(up);
    rep.appendChild(down);
    head.appendChild(rep);

    const online = el("span", "tr-online");
    const status = offer.author.status === "online" ? "online" : "offline";
    online.dataset.status = status;
    online.setAttribute("role", "img");
    online.setAttribute("aria-label", tx("chat.status." + status));
    online.title = tx("chat.status." + status);
    head.appendChild(online);
    art.appendChild(head);

    const body = el("div", "tr-card-body");
    const giveN = Math.max(1, offer.give.length);
    const wantN = Math.max(1, offer.want.length);
    body.style.setProperty("--n", String(giveN + wantN));
    body.style.setProperty("--m", String(Math.max(giveN, wantN)));
    body.classList.toggle("is-wide", giveN + wantN > 4);
    body.appendChild(buildSide(offer.give, "tr-give", "trade.give"));
    body.appendChild(icon("trIconSwap", "tr-swap"));
    body.appendChild(buildSide(offer.want, "tr-want", "trade.want"));
    art.appendChild(body);

    const foot = el("footer", "tr-card-foot");
    const time = el("time", "tr-time", ago(offer.at));
    time.dateTime = new Date(offer.at * 1000).toISOString();
    foot.appendChild(time);

    const actions = el("div", "tr-actions");
    if (offer.mine) {
      actions.appendChild(actButton("trade.done", "done", "tr-act-good", offer.id));
      actions.appendChild(actButton("trade.cancel", "cancel", "tr-act-ghost", offer.id));
    } else if (state.authed) {
      const a = el("a", "tr-act tr-stretch", tx("trade.write"));
      a.href = chatHref(offer);
      actions.appendChild(a);
    } else if (state.canLogin) {
      const a = el("a", "tr-act tr-stretch", tx("trade.loginToWrite"));
      a.href = loginUrl();
      actions.appendChild(a);
    }
    if (state.admin && !offer.mine) {
      actions.appendChild(actButton("trade.remove", "remove", "tr-act-bad", offer.id));
    }
    foot.appendChild(actions);
    art.appendChild(foot);

    li.appendChild(art);
    return li;
  }

  function render() {
    const mineList = $("#trMine");
    const feed = $("#trFeed");
    const stateEl = $("#trState");

    const showMine = state.mine.length > 0 && !state.query;
    $("#trMineTitle").hidden = !showMine;
    $("#trAllTitle").hidden = !showMine;
    mineList.hidden = !showMine;

    mineList.textContent = "";
    if (showMine) { state.mine.forEach(o => mineList.appendChild(buildCard(o))); }

    feed.textContent = "";
    const list = showMine ? state.offers.filter(o => !o.mine) : state.offers;
    list.forEach(o => feed.appendChild(buildCard(o)));

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
    const act = btn.dataset.act;
    const id = Number(btn.dataset.id);
    const ask = act === "done" ? "trade.confirmDone" : act === "cancel" ? "trade.confirmCancel" : "trade.confirmRemove";
    if (!window.confirm(tx(ask))) { return; }

    const card = btn.closest(".tr-card");
    if (card) { card.classList.add("is-busy"); }
    let ok = false;
    try {
      const r = await fetch(API_CLOSE, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: id, result: act })
      });
      const d = await r.json().catch(() => null);
      ok = r.ok && d && d.ok;
      if (!ok && d && d.error === "closed") { ok = true; }
    } catch (_) {
      ok = false;
    }

    if (ok) {
      state.offers = state.offers.filter(o => o.id !== id);
      state.mine = state.mine.filter(o => o.id !== id);
      render();
    } else {
      if (card) { card.classList.remove("is-busy"); }
      window.alert(tx("trade.closeFailed"));
    }
  }

  const tierlist = { rev: null };

  async function fetchState() {
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      if (r.ok) { return await r.json(); }
    } catch (e) {}
    return null;
  }

  async function loadCatalog() {
    try {
      const st = await fetchState();
      const rev = st && typeof st.rev === "number" ? st.rev : null;
      if (rev !== null && rev === tierlist.rev) { return; }
      const url = API_TIERLIST + (rev !== null ? "?rev=" + encodeURIComponent(rev) : "");
      const r = await fetch(url, { cache: rev !== null ? "default" : "no-store" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const d = await r.json();
      if (!d || !d.tierlist) { throw new Error("empty tierlist"); }
      state.catalog = CALC.buildCatalogIndex(CALC.flattenTierlist(d.tierlist));
      tierlist.rev = rev;
    } catch (e) {
      console.warn("trading: не удалось загрузить тирлист", e);
    }
    state.catalogReady = true;
    render();
  }

  function renderMe() {
    const box = $("#trMe");
    const s = state.session;
    const user = s && s.user;
    if (!user && !state.canLogin) { box.hidden = true; return; }
    box.hidden = false;
    box.classList.toggle("is-guest", !user);

    $("#trMeNick").textContent = user ? (user.display || user.name || "") : "—";
    $("#trMeHandle").textContent = user && user.name ? "@" + user.name : "—";

    const btn = $("#trMeBtn");
    const key = user ? "trade.meProfile" : "trade.meLogin";
    btn.href = user ? "/profile" : loginUrl();
    btn.dataset.i18n = key;
    btn.textContent = tx(key);

    const ava = $("#trMeAva");
    ava.textContent = "";
    if (user && user.avatar) {
      const img = document.createElement("img");
      img.src = user.avatar;
      img.alt = "";
      img.referrerPolicy = "no-referrer";
      ava.appendChild(img);
    } else {
      ava.appendChild(userSilhouette());
    }
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
    renderMe();
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

        if (!rail) return;
        const paid = doc ? promo.eligible(promo.normalizeDoc(doc), "rail", Date.now(), PROMO_PAGE) : [];
        const house = promo.houseFor("rail", Date.now(), PROMO_PAGE);
        const list = paid.length ? paid : (house && house.id !== promo.HOUSE_SLOT.id ? [house] : []);
        if (!list.length) return;
        fillRail(rail, list[0]);
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

    renderMe();
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
      }, { rootMargin: "240px" });
      io.observe($("#trMore"));
    }

    const me = $("#trMe");
    me.addEventListener("click", e => {
      if (e.target.closest("a")) { return; }
      me.classList.toggle("is-open");
    });
    document.addEventListener("click", e => {
      if (!me.contains(e.target)) { me.classList.remove("is-open"); }
    });

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
