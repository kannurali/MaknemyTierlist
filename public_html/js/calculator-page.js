
(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const sideRoot = side => document.querySelector('.tc-side[data-side="' + side + '"]');

  const LANG_KEY = "nexus-lang-v1";

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  function fmtNum(n) {
    return Math.round(n).toLocaleString(lang === "en" ? "en-US" : "ru-RU");
  }

  let catalog = [];
  let catalogIndex = {};
  const sides = { left: [], right: [] };

  function capSide(entries) {
    return (entries || []).slice(0, CALC.MAX_SLOTS);
  }

  function badgeImg(type, className) {
    const code = CALC.badgeCodeFor(type);
    const img = document.createElement("img");
    img.className = className;
    img.src = "assets/design/legend/badge-" + code + ".svg";
    img.alt = code.toUpperCase();
    return img;
  }

  function buildEmptySlot(side, index) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "tc-slot is-empty";
    btn.dataset.side = side;
    btn.dataset.index = String(index);
    const label = side === "left" ? tx("calc.giveLabel") : tx("calc.getLabel");
    btn.setAttribute("aria-label", tx("calc.emptySlot", { n: index + 1, side: label }));
    return btn;
  }

  function buildFilledSlot(side, index, entry) {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "tc-slot is-filled";
    btn.dataset.side = side;
    btn.dataset.index = String(index);
    btn.dataset.id = entry.item.id;
    btn.setAttribute("aria-label", tx("calc.removeOne", { name: entry.item.name || "" }));

    btn.appendChild(badgeImg(entry.item.type, "tc-slot-badge"));

    const removeMark = document.createElement("span");
    removeMark.className = "tc-slot-remove";
    removeMark.setAttribute("aria-hidden", "true");
    removeMark.textContent = "✕";
    btn.appendChild(removeMark);

    const icon = document.createElement("img");
    icon.className = "tc-slot-icon";
    icon.src = entry.item.icon || "";
    icon.alt = "";
    btn.appendChild(icon);

    const name = document.createElement("span");
    name.className = "tc-slot-name";
    name.textContent = entry.item.name || "";
    btn.appendChild(name);

    const bottom = document.createElement("span");
    bottom.className = "tc-slot-bottom";

    const value = document.createElement("span");
    value.className = "tc-slot-value";
    value.textContent = entry.item.value || "0";
    bottom.appendChild(value);

    if (entry.item.demand) {
      const dot = document.createElement("img");
      dot.className = "tc-slot-dot";
      dot.src = "assets/dot-" + entry.item.demand + ".png";
      dot.alt = "";
      bottom.appendChild(dot);
    }
    btn.appendChild(bottom);

    return btn;
  }

  function renderSlots(side) {
    const root = sideRoot(side);
    if (!root) return;
    const list = root.querySelector(".tc-slots");
    const entries = sides[side];

    list.textContent = "";
    for (let i = 0; i < CALC.MAX_SLOTS; i++) {
      const entry = entries[i];
      list.appendChild(entry ? buildFilledSlot(side, i, entry) : buildEmptySlot(side, i));
    }
  }

  const DEMAND_LEVEL_KEY = {
    neon: "legend.neon", green: "legend.good", yellow: "legend.mid",
    orange: "legend.low", red: "legend.bad"
  };

  function renderMeters(side, trade) {
    const root = sideRoot(side);
    if (!root) return;
    const total = side === "left" ? trade.leftTotal : trade.rightTotal;
    const otherTotal = side === "left" ? trade.rightTotal : trade.leftTotal;

    root.querySelector('[data-role="points"]').textContent = fmtNum(total);

    const bucket = CALC.demandBucket(CALC.demandBalance(sides[side]));
    const dot = root.querySelector('[data-role="demand"]');
    dot.dataset.demand = bucket || "none";
    dot.setAttribute("aria-label", bucket
      ? tx("calc.demandAggregate", { level: tx(DEMAND_LEVEL_KEY[bucket]) })
      : tx("calc.demandUnknown"));

    const sum = total + otherTotal;
    const pct = sum > 0 ? Math.max(0, Math.min(100, (total / sum) * 100)) : 50;
    root.querySelector('[data-role="mark"]').style.left = pct + "%";
  }

  function renderResult(trade) {
    const resultEl = $("#tcResult");
    const bothEmpty = sides.left.length === 0 && sides.right.length === 0;

    resultEl.dataset.verdict = bothEmpty ? "none" : trade.verdict;
    $("#tcVerdictBadge").dataset.verdict = bothEmpty ? "none" : trade.verdict;

    const invert = v => v === "win" ? "lose" : v === "lose" ? "win" : v;
    const gaugeL = bothEmpty ? "none" : trade.verdict;
    const gaugeR = bothEmpty ? "none" : invert(trade.verdict);
    const gl = $("#tcGaugeL"), gr = $("#tcGaugeR");
    if (gl) { gl.dataset.state = gaugeL; }
    if (gr) { gr.dataset.state = gaugeR; }

    const headingEl = $("#tcVerdictHeading");
    const stateEl = $("#tcVerdictState");
    const numberEl = $("#tcVerdictNumber");
    const totalEl = $("#tcTotalNum");

    if (bothEmpty) {
      headingEl.textContent = tx("calc.verdictPrompt");
      stateEl.textContent = "";
      numberEl.textContent = "0%";
      totalEl.textContent = "0";
    } else {
      const verdictKey = trade.verdict === "win" ? "calc.verdictWin"
        : trade.verdict === "lose" ? "calc.verdictLose"
        : "calc.verdictFair";
      const titleKey = trade.verdict === "win" ? "calc.verdictWinTitle"
        : trade.verdict === "lose" ? "calc.verdictLoseTitle"
        : "calc.verdictFairTitle";
      headingEl.textContent = tx(titleKey);
      stateEl.textContent = tx(verdictKey);

      const diffAbs = Math.round(trade.diffAbs);
      const diffPct = Math.round(trade.diffPct * 10) / 10;

      const sign = diffAbs > 0 ? "+" : (diffAbs < 0 ? "−" : "");
      const pctSign = diffPct > 0 ? "+" : (diffPct < 0 ? "−" : "");
      numberEl.textContent = pctSign + Math.abs(diffPct) + "%";
      totalEl.textContent = sign + fmtNum(Math.abs(diffAbs));
    }

    const noteEl = $("#tcDemandNote");
    if (trade.demandNote) {
      noteEl.hidden = false;
      noteEl.textContent = tx(trade.demandNote === "receiveLow" ? "calc.demandNoteReceiveLow" : "calc.demandNoteGiveLow");
    } else {
      noteEl.hidden = true;
      noteEl.textContent = "";
    }
  }

  function renderThreshold() {
    $("#tcThreshold").textContent = tx("calc.thresholdNote", { pct: CALC.THRESHOLD_PCT });
  }

  function syncUrl() {
    const query = CALC.encodeShareQuery(sides.left, sides.right);
    history.replaceState(null, "", location.pathname + (query ? "?" + query : ""));
  }

  function renderAll() {
    const trade = CALC.computeTrade(sides.left, sides.right);
    renderSlots("left");
    renderSlots("right");
    renderMeters("left", trade);
    renderMeters("right", trade);
    renderResult(trade);
  }

  function onSidesChanged() {
    renderAll();
    syncUrl();
  }

  const catalogState = { open: false, side: null, triggerEl: null, slotIndex: -1 };

  function norm(s) { return String(s || "").toLowerCase(); }

  function buildCatalogCard(it) {
    const li = document.createElement("li");
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "tc-cat-card";

    const full = !CALC.canAddToSide(sides[catalogState.side], it);
    btn.classList.toggle("is-full", full);
    btn.setAttribute("aria-label", tx("calc.addItem", { name: it.name || "" }));

    const code = CALC.badgeCodeFor(it.type);

    const inner = document.createElement("span");
    inner.className = "tc-cat-inner";

    const art = document.createElement("span");
    art.className = "tc-cat-art";
    const icon = document.createElement("img");
    icon.className = "tc-cat-icon";
    icon.src = it.icon || "";
    icon.alt = "";
    art.appendChild(icon);
    inner.appendChild(art);

    const name = document.createElement("span");
    name.className = "tc-cat-name";

    name.style.setProperty("--tc-plate", "var(--tc-plate-" + code + ")");
    name.textContent = it.name || "";
    inner.appendChild(name);

    const bottom = document.createElement("span");
    bottom.className = "tc-cat-bottom";
    const value = document.createElement("span");
    value.className = "tc-cat-value";
    value.textContent = it.value || "0";
    bottom.appendChild(value);
    if (it.demand) {
      const dot = document.createElement("img");
      dot.className = "tc-cat-dot";
      dot.src = "assets/dot-" + it.demand + ".png";
      dot.alt = "";
      bottom.appendChild(dot);
    }
    inner.appendChild(bottom);
    btn.appendChild(inner);

    const badgeBox = document.createElement("span");
    badgeBox.className = "tc-cat-badge-box";
    badgeBox.appendChild(badgeImg(it.type, "tc-cat-badge"));
    btn.appendChild(badgeBox);

    btn.addEventListener("click", () => {
      const side = catalogState.side;
      if (!side) return;
      if (!CALC.canAddToSide(sides[side], it)) {
        $("#tcCatalogStatus").textContent = tx("calc.slotsFull");
        return;
      }
      $("#tcCatalogStatus").textContent = "";
      sides[side] = capSide(CALC.addToSide(sides[side], it));
      onSidesChanged();

      closeCatalog({ side: side, addedId: it.id });
    });

    li.appendChild(btn);
    return li;
  }

  function renderCatalogGrid(query) {
    const grid = $("#tcCatalogGrid");
    const q = norm(query).trim();
    grid.textContent = "";
    const matches = q ? catalog.filter(it => norm(it.name).includes(q)) : catalog;
    if (!matches.length) {
      const li = document.createElement("li");
      li.className = "tc-cat-empty";
      li.textContent = tx("calc.searchNoResults");
      grid.appendChild(li);
      return;
    }
    matches.forEach(it => grid.appendChild(buildCatalogCard(it)));
  }

  function openCatalog(side, triggerEl) {
    catalogState.open = true;
    catalogState.side = side;
    catalogState.triggerEl = triggerEl || null;

    catalogState.slotIndex = triggerEl
      ? [...sideRoot(side).querySelectorAll(".tc-slot")].indexOf(triggerEl)
      : -1;
    $("#tcCatalogStatus").textContent = "";
    $("#tcCatalogSearch").value = "";

    cancelQueuedRender();
    renderCatalogGrid("");
    $("#tcCatalogBackdrop").hidden = false;
    document.body.style.overflow = "hidden";
    $("#tcCatalogSearch").focus();
  }

  const CATALOG_CLOSE_MS = 180;

  function reducedMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  function flashAddedSlot(side, addedId) {
    if (!side || !addedId) return;
    const list = sides[side] || [];
    let idx = -1;
    for (let i = list.length - 1; i >= 0; i--) {
      if (list[i] && list[i].item && list[i].item.id === addedId) { idx = i; break; }
    }
    if (idx < 0) return;
    const slot = sideRoot(side).querySelectorAll(".tc-slot")[idx];
    if (!slot) return;
    slot.classList.remove("is-just-added");

    void slot.offsetWidth;
    slot.classList.add("is-just-added");
    setTimeout(() => slot.classList.remove("is-just-added"), 900);
  }

  function closeCatalog(opts) {
    if (!catalogState.open) return;
    catalogState.open = false;
    const side = catalogState.side;
    const backdrop = $("#tcCatalogBackdrop");
    const slotIndex = catalogState.slotIndex;
    catalogState.side = null;
    catalogState.triggerEl = null;
    catalogState.slotIndex = -1;
    document.body.style.overflow = "";
    cancelQueuedRender();

    const finish = () => {
      backdrop.hidden = true;
      backdrop.classList.remove("is-closing");

      const slots = side ? sideRoot(side).querySelectorAll(".tc-slot") : [];

      const addedIndex = (opts && opts.addedId)
        ? (sides[side] || []).findIndex(e => e && e.item && e.item.id === opts.addedId)
        : -1;
      const target = (addedIndex >= 0 && slots[addedIndex])
        || (slotIndex >= 0 && slots[slotIndex])
        || slots[0];
      if (target) { target.focus(); }
      if (opts && opts.addedId) { flashAddedSlot(side, opts.addedId); }
    };

    if (reducedMotion()) { finish(); return; }
    backdrop.classList.add("is-closing");
    setTimeout(finish, CATALOG_CLOSE_MS);
  }

  const SEARCH_DEBOUNCE_MS = 120;
  let searchTimer = null;

  function cancelQueuedRender() {
    if (searchTimer !== null) { clearTimeout(searchTimer); searchTimer = null; }
  }

  function queueCatalogRender(query) {
    cancelQueuedRender();
    searchTimer = setTimeout(function () {
      searchTimer = null;
      renderCatalogGrid(query);
    }, SEARCH_DEBOUNCE_MS);
  }

  const FOCUSABLE_SEL = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function catalogFocusables() {
    const dialog = $("#tcCatalog");
    if (!dialog) return [];

    return [...dialog.querySelectorAll(FOCUSABLE_SEL)]
      .filter(el => el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement);
  }

  function trapTab(e) {
    const dialog = $("#tcCatalog");
    const items = catalogFocusables();
    if (!dialog || !items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    const outside = !dialog.contains(active);
    if (e.shiftKey) {
      if (outside || active === first) { e.preventDefault(); last.focus(); }
    } else if (outside || active === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function wireCatalog() {
    $("#tcCatalogSearch").addEventListener("input", e => queueCatalogRender(e.target.value));
    $("#tcCatalogClose").addEventListener("click", closeCatalog);
    $("#tcCatalogBackdrop").addEventListener("click", e => {
      if (e.target === $("#tcCatalogBackdrop")) closeCatalog();
    });
    document.addEventListener("keydown", e => {
      if (!catalogState.open) return;
      if (e.key === "Escape") { closeCatalog(); return; }
      if (e.key === "Tab") { trapTab(e); }
    });
  }

  function wireSlots(side) {
    const root = sideRoot(side);
    root.querySelector(".tc-slots").addEventListener("click", e => {
      const slot = e.target.closest(".tc-slot");
      if (!slot) return;
      if (slot.classList.contains("is-empty")) {
        openCatalog(side, slot);
      } else {
        sides[side] = CALC.removeOneFromSide(sides[side], slot.dataset.id);
        onSidesChanged();
      }
    });
  }

  function wireActions() {
    $("#tcClearAllBtn").addEventListener("click", () => {
      if (!window.confirm(tx("calc.confirmClearAll"))) return;
      sides.left = CALC.clearSide();
      sides.right = CALC.clearSide();
      onSidesChanged();
    });

    $("#tcShareBtn").addEventListener("click", async () => {
      const statusEl = $("#tcShareStatus");
      try {
        await navigator.clipboard.writeText(location.href);
        statusEl.textContent = tx("calc.shareCopied");
      } catch (_e) {
        statusEl.textContent = tx("calc.shareFailed") + ": " + location.href;
      }
    });
  }

  const PROMO_API = "/api/promo.php";

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
    const left = document.getElementById("tcRailL");
    const right = document.getElementById("tcRailR");
    const dock = document.getElementById("promoDock");
    if (!promo) return;

    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .catch(() => null)
      .then(doc => {
        if (dock && window.NX_PROMO_DOCK) window.NX_PROMO_DOCK.render(dock, doc);

        if (window.NX_PROMO_POPUP) {
          window.NX_PROMO_POPUP.mount({ doc, busy: () => catalogState.open });
        }

        if (!left || !right) return;

        const paid = doc ? promo.eligible(promo.normalizeDoc(doc), "rail", Date.now()) : [];

        const house = promo.houseFor("rail", Date.now());
        const list = paid.length ? paid : (house && house.id !== promo.HOUSE_SLOT.id ? [house] : []);
        if (!list.length) return;
        fillRail(left, list[0]);

        fillRail(right, list[1] || list[0]);
      });
  }

  function applyLang(next) {
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) {}
    }
    document.documentElement.lang = lang;

    document.querySelectorAll("[data-i18n]").forEach(el => { el.textContent = tx(el.dataset.i18n); });
    document.querySelectorAll("[data-i18n-title]").forEach(el => { el.title = tx(el.dataset.i18nTitle); });
    document.querySelectorAll("[data-i18n-placeholder]").forEach(el => { el.placeholder = tx(el.dataset.i18nPlaceholder); });
    document.querySelectorAll("[data-i18n-label]").forEach(el => { el.setAttribute("aria-label", tx(el.dataset.i18nLabel)); });
    document.querySelectorAll("#langSwitch [data-lang]").forEach(b => {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    });

    renderThreshold();

    renderAll();
    if (catalogState.open) { renderCatalogGrid($("#tcCatalogSearch").value); }
  }

  (function initLangSwitch() {
    const box = $("#langSwitch");
    if (!box) return;
    box.addEventListener("click", e => {
      const btn = e.target.closest("[data-lang]");
      if (btn) applyLang(btn.dataset.lang);
    });
  })();

  const API_STATE = "/api/state.php";
  const API_TIERLIST = "/api/tierlist.php";
  const POLL_MS = 30000;
  let lastRev = null;
  let pollTimer = null;

  function applyTierlist(doc) {
    catalog = CALC.flattenTierlist(doc);
    catalogIndex = CALC.buildCatalogIndex(catalog);
  }

  function remapSides() {
    ["left", "right"].forEach(side => {
      sides[side] = (sides[side] || [])
        .map(e => {
          const fresh = e && e.item && catalogIndex[e.item.id];
          return fresh ? { item: fresh, count: e.count } : null;
        })
        .filter(Boolean);
    });
  }

  async function fetchState() {
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      if (r.ok) { return await r.json(); }
    } catch (e) {}
    return null;
  }

  async function fetchTierlist(rev) {
    const hasRev = rev !== null && rev !== undefined && rev !== "";
    const url = API_TIERLIST + (hasRev ? "?rev=" + encodeURIComponent(rev) : "");
    const r = await fetch(url, { cache: hasRev ? "default" : "no-store" });
    if (!r.ok) throw new Error("http " + r.status);
    const d = await r.json();
    if (!d || !d.tierlist) throw new Error("empty tierlist");
    return d.tierlist;
  }

  async function poll() {
    const st = await fetchState();
    if (!st || typeof st.rev !== "number" || st.rev === lastRev) { return; }
    try {
      applyTierlist(await fetchTierlist(st.rev));
      lastRev = st.rev;
      remapSides();

      renderAll();

      if (catalogState.open) { renderCatalogGrid($("#tcCatalogSearch").value); }
    } catch (e) {
      console.warn("calculator: не удалось обновить тирлист", e);
    }
  }

  function startPolling() {
    if (pollTimer) { clearInterval(pollTimer); }
    pollTimer = setInterval(poll, POLL_MS);

    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") { poll(); }
    });
  }

  async function load() {
    const stateEl = $("#tcState");
    stateEl.hidden = false;
    stateEl.textContent = tx("calc.loading");
    try {
      const st = await fetchState();
      const rev = st && typeof st.rev === "number" ? st.rev : null;
      applyTierlist(await fetchTierlist(rev));
      if (rev !== null) { lastRev = rev; }
      stateEl.hidden = true;
      stateEl.textContent = "";

      const restored = CALC.decodeShareQuery(new URLSearchParams(location.search), catalogIndex);
      sides.left = capSide(restored.left);
      sides.right = capSide(restored.right);

      renderAll();
      startPolling();
    } catch (e) {
      console.warn("calculator: не удалось загрузить тирлист", e);
      stateEl.hidden = false;
      stateEl.textContent = tx("calc.loadError");
    }
  }

  wireSlots("left");
  wireSlots("right");
  wireCatalog();
  wireActions();
  applyLang();
  load();
  renderPromo();
})();
