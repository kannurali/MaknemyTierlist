
(() => {
  "use strict";

  const STORAGE_KEY = "nexus-tierlist-v1";
  const DIRTY_KEY = "nexus-tierlist-dirty-v1";
  const DEFAULT_ICON = "assets/icon-sample.png";

  const tierMarks = (typeof TIERS !== "undefined") ? TIERS : null;
  if (!tierMarks) console.warn("tiers.js не загружен — марки полос тиров останутся как есть");
  const TIER_LOGOS = tierMarks ? tierMarks.TIER_LOGOS : {};
  const normalizeTierLogos = tierMarks ? tierMarks.normalizeTierLogos : (list => list);

  const uid = () => "id" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

  const DONATE_DA  = "https://www.donationalerts.com/r/maknemy";
  const DONATE_HUB = "https://dalink.to/maknemy";
  const DONATE_QR  = "assets/qr-donate.png?v=1";

  function defaultState() {
    const mk = (name, value, type, demand, trend) => ({
      id: uid(), name, value: String(value), icon: DEFAULT_ICON, type, demand, trend,
      desc: "", descEn: "", terms: "", termsEn: "", tag: "", tagEn: "",
      flag: false, wip: false,
    });
    return {
      title: "MAKNEMY\nTIER LIST",
      date: "17.02.2026",
      autoSort: true,
      filters: { configurators: true, fruits: true, perms: false, passes: true },
      ad: { text: "МЕСТО ДЛЯ ВАШЕЙ РЕКЛАМЫ — t.me/mksvtnc", image: "", link: "" },
      donate: { da: DONATE_DA, hub: DONATE_HUB, qr: DONATE_QR },
      credits: [
        { role: "Автор", name: "Maknemy" },
        { role: "Дизайнер", name: "Maknemy" },
        { role: "Аналитик", name: "—" },
        { role: "Помощник аналитика", name: "—" },
        { role: "Кодер сайта", name: "—" },
      ],
      footer: [
        { title: "МОЙ ДИСКОРД",       sub: "discord.gg/A4ZG8sxCM",   href: "https://discord.gg/A4ZG8sxCM" },
        { title: "МОЙ ТЕЛЕГРАММ",     sub: "t.me/mksvtnc",           href: "https://t.me/mksvtnc" },
        { title: "BLOX FRUITS NEWS",  sub: "t.me/bfsnews",           href: "https://t.me/bfsnews" },
        { title: "ВСЕ РОЗЫГРЫШИ ТУТ", sub: "t.me/mksvtnc",           href: "https://t.me/mksvtnc" },
        { title: "CHARLOTTE TM",      sub: "discord.gg/Q9PO6UG9Q4",  href: "https://discord.gg/Q9PO6UG9Q4" },
        { title: "ПОМОЩНИК",          sub: "t.me/typeopozitivegg",   href: "https://t.me/typeopozitivegg" },
      ],
      tiers: [
        {
          id: uid(), label: "MK", logo: TIER_LOGOS.MK,
          items: [
            mk("Item", 60000, "f", "green", "up"),
            mk("Item", 50000, "f", "green", ""),
            mk("Item", 40000, "f", "yellow", ""),
            mk("Item", 30000, "f", "yellow", "down"),
            mk("Item", 25000, "s", "orange", ""),
          ],
        },
        {
          id: uid(), label: "GLH", logo: TIER_LOGOS.GLH,
          items: [
            mk("Item", 12000, "f", "yellow", ""),
            mk("Item", 9000, "f", "orange", "down"),
            mk("Item", 7500, "m", "orange", ""),
            mk("Item", 5000, "p", "red", ""),
          ],
        },
        {
          id: uid(), label: "💧", logo: TIER_LOGOS["💧"],
          items: [
            mk("Item", 800, "f", "red", "down"),
            mk("Item", 500, "f", "red", ""),
            mk("Item", 250, "cr", "red", ""),
          ],
        },
      ],
    };
  }

  function bootState() {
    const d = defaultState();
    d.date = "";
    d.ad = { text: "", image: "", link: "" };
    d.tiers = d.tiers.map(t => Object.assign({}, t, { items: [] }));
    return d;
  }

  let state = load() || bootState();
  let isAdmin = false;
  let dirty = false;
  let saving = false;

  let bootedFromLocal = localStorage.getItem(STORAGE_KEY) != null;

  let bootedDirty = (() => { try { return localStorage.getItem(DIRTY_KEY) === "1"; } catch (e) { return false; } })();
  let deferredServer = null;
  let pendingServer = null;
  let roleResolved = false;
  let firstSnapshotHandled = false;

  function load() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !data.tiers) return null;

      const d = defaultState();
      const merged = Object.assign({}, d, data);
      merged.ad = Object.assign({}, d.ad, data.ad || {});
      merged.donate = Object.assign({}, d.donate, data.donate || {});
      merged.filters = normalizeFilters(data.filters, d.filters);
      merged.filters.perms = false;
      if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
      if (!Array.isArray(merged.footer) || !merged.footer.length) merged.footer = d.footer;
      if (typeof merged.autoSort !== "boolean") merged.autoSort = true;

      normalizeTierLogos(merged.tiers, false);
      return merged;
    } catch (e) { return null; }
  }
  let saveTimer = null;

  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e) {}
    }, 400);
    if (isAdmin) { dirty = true; try { localStorage.setItem(DIRTY_KEY, "1"); } catch (e) {} renderSaveBtn(); }
  }

  function clearDirty() {
    dirty = false;
    try { localStorage.removeItem(DIRTY_KEY); } catch (e) {}
    renderSaveBtn();
  }

  function shrinkDataURL(src, maxSize, quality) {
    return new Promise(resolve => {
      const img = new Image();
      img.onload = () => {
        let w = img.naturalWidth || img.width;
        let h = img.naturalHeight || img.height;
        const scale = Math.min(1, maxSize / Math.max(w, h || 1));
        w = Math.max(1, Math.round(w * scale));
        h = Math.max(1, Math.round(h * scale));
        const c = document.createElement("canvas");
        c.width = w; c.height = h;
        c.getContext("2d").drawImage(img, 0, 0, w, h);
        let out = "";
        try { out = c.toDataURL("image/webp", quality); } catch (e) {}

        if (out.indexOf("data:image/webp") !== 0) {
          try { out = c.toDataURL("image/png"); } catch (e) { out = ""; }
        }

        resolve(out && out.length < src.length ? out : src);
      };
      img.onerror = () => resolve(src);
      img.src = src;
    });
  }

  function fileToSmallDataURL(file, maxSize, quality) {
    return new Promise(resolve => {
      const reader = new FileReader();
      reader.onload = () => shrinkDataURL(reader.result, maxSize, quality).then(resolve);
      reader.onerror = () => resolve("");
      reader.readAsDataURL(file);
    });
  }

  function dataUrlBytes(du) {
    const comma = du.indexOf(",");
    if (comma < 0) return du.length;
    return Math.round((du.length - comma - 1) * 3 / 4);
  }
  async function fileToBudgetedDataURL(file, presets, budget) {
    let out = "";
    for (const p of presets) {
      out = await fileToSmallDataURL(file, p[0], p[1]);
      if (!out) return "";
      if (dataUrlBytes(out) <= budget) return out;
    }
    return out;
  }

  const REQUEST_TIMEOUT_MS = 30000;
  async function fetchWithTimeout(url, opts, ms) {
    const ctrl = new AbortController();
    const to = setTimeout(() => ctrl.abort(), ms || REQUEST_TIMEOUT_MS);
    try {
      return await fetch(url, Object.assign({}, opts, { signal: ctrl.signal }));
    } finally {
      clearTimeout(to);
    }
  }

  async function uploadDataUrl(dataUrl) {
    if (typeof dataUrl !== "string" || dataUrl.indexOf("data:") !== 0) return dataUrl;
    try {
      const r = await fetchWithTimeout(API_UPLOAD, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ data: dataUrl }),
      });
      const d = await r.json().catch(() => ({}));
      if (r.ok && d.url) return d.url;
    } catch (e) {}
    return dataUrl;
  }

  async function compactState() {
    for (const t of state.tiers) {
      if (typeof t.logo === "string" && t.logo.indexOf("data:") === 0) {
        t.logo = await uploadDataUrl(t.logo);
      }
      for (const it of t.items) {
        if (typeof it.icon === "string" && it.icon.indexOf("data:") === 0) {
          it.icon = await uploadDataUrl(it.icon);
        }
      }
    }
    if (state.ad && typeof state.ad.image === "string" && state.ad.image.indexOf("data:") === 0) {
      state.ad.image = await uploadDataUrl(state.ad.image);
    }
    if (state.donate && typeof state.donate.qr === "string" && state.donate.qr.indexOf("data:") === 0) {
      state.donate.qr = await uploadDataUrl(state.donate.qr);
    }
  }

  async function publish() {
    if (!isAdmin || !dirty || saving) return;
    saving = true; renderSaveBtn();
    try {
      await compactState();
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
      render();
      const r = await fetchWithTimeout(API_SAVE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(state),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) { throw new Error(d.error || ("save failed: " + r.status)); }

      state._rev = d.rev; lastRev = d.rev;
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
      saving = false; clearDirty(); flashSaved();
    } catch (err) {
      saving = false; renderSaveBtn();
      savedHint.textContent = (err && err.name === "AbortError")
        ? tx("msg.saveTimeout")
        : "⚠ " + ((err && err.message) || tx("msg.saveError"));
    }
  }

  function renderSaveBtn() {
    if (!btnSave) return;
    btnSave.classList.remove("clean", "dirty", "saving");
    if (saving)     { btnSave.textContent = tx("admin.saving"); btnSave.classList.add("saving"); }
    else if (dirty) { btnSave.textContent = tx("admin.save");  btnSave.classList.add("dirty"); }
    else            { btnSave.textContent = tx("admin.saved");   btnSave.classList.add("clean"); }
  }
  function flashSaved() {
    savedHint.textContent = tx("admin.saved");
    clearTimeout(flashSaved._t);
    flashSaved._t = setTimeout(() => (savedHint.textContent = ""), 1200);
  }

  const $ = (s, r = document) => r.querySelector(s);
  const stage = $("#stage");
  const tiersEl = $("#tiers");
  const savedHint = $("#savedHint");
  const btnSave = $("#btnSave");
  const editToggle = $("#editToggle");
  const autoSortToggle = $("#autoSortToggle");
  const creditsEl = $("#credits");
  const footerEl = $("#tlFooter");

  const LANG_KEY = "nexus-lang-v1";

  const i18n = (typeof I18N !== "undefined") ? I18N : null;
  if (!i18n) console.warn("i18n.js не загружен — интерфейс останется на русском");

  let lang = i18n
    ? i18n.pickLang(
        (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
        navigator.language)
    : "ru";

  const tx = (key, vars) => (i18n ? i18n.t(key, lang, vars) : key);

  const content = (typeof CONTENT !== "undefined") ? CONTENT : null;

  const textFor = (it, base) => content
    ? content.textFor(it, base, lang)
    : String((it && it[base]) || "").trim();
  const descFor = it => textFor(it, "desc");

  function applyLang(next) {
    if (!i18n) return;
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) {}
    }
    document.documentElement.lang = lang;

    document.querySelectorAll("[data-i18n]").forEach(el => { el.textContent = tx(el.dataset.i18n); });
    document.querySelectorAll("[data-i18n-title]").forEach(el => { el.title = tx(el.dataset.i18nTitle); });
    document.querySelectorAll("[data-i18n-alt]").forEach(el => { el.alt = tx(el.dataset.i18nAlt); });
    document.querySelectorAll("[data-i18n-label]").forEach(el => {
      el.setAttribute("aria-label", tx(el.dataset.i18nLabel));
    });
    document.querySelectorAll("[data-i18n-placeholder]").forEach(el => {
      el.placeholder = tx(el.dataset.i18nPlaceholder);
    });

    document.querySelectorAll("#langSwitch [data-lang]").forEach(b => {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    });
  }

  (function initLangSwitch() {
    const box = $("#langSwitch");
    if (!box) return;
    box.addEventListener("click", e => {
      const btn = e.target.closest("[data-lang]");
      if (!btn) return;
      const next = btn.dataset.lang;

      if (next === lang) return;

      applyLang(next);
      render();
    });
  })();

  function findTier(tid) { return state.tiers.find(t => t.id === tid); }
  function findItem(iid) {
    for (const t of state.tiers) {
      const it = t.items.find(i => i.id === iid);
      if (it) return { tier: t, item: it };
    }
    return null;
  }

  function parseVal(v) {
    if (v === null || v === undefined) return NaN;
    let s = String(v).toLowerCase().replace(/\s/g, "").replace(",", ".");
    let mult = 1;
    while (s.endsWith("kk") || s.endsWith("кк")) { mult *= 1e6; s = s.slice(0, -2); }
    while (s.endsWith("k") || s.endsWith("к")) { mult *= 1e3; s = s.slice(0, -1); }
    s = s.replace(/[^\d.\-]/g, "");
    if (!s) return NaN;
    const n = parseFloat(s);
    return isNaN(n) ? NaN : n * mult;
  }

  const SAFE_SCHEME = /^(https?:|mailto:|tel:)/i;

  function looksLikeUrl(s) { return /^[^\s]+\.[a-z]{2,}([\/?#].*)?$/i.test(s); }
  function normalizeHref(href, fallback) {
    let s = String(href == null ? "" : href).trim();
    if (s === "" || /^https?:\/*$/i.test(s)) {
      const fb = String(fallback == null ? "" : fallback).trim();
      s = looksLikeUrl(fb) ? fb : "";
    }
    if (!s) return "";

    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) return SAFE_SCHEME.test(s) ? s : "";

    if (s.indexOf("//") === 0) return "https:" + s;

    if (s.charAt(0) === "/" || s.charAt(0) === "#") return s;
    return "https://" + s.replace(/^\/+/, "");
  }

  const BADGE_FILE = { f: "fr", p: "pm", s: "cs", m: "cm", v: "vh" };

  function badgeSrc(type) {
    return "assets/design/legend/badge-" + (BADGE_FILE[type] || type) + ".svg";
  }

  function groupOf(type) {
    if (type === "p") return "perms";
    if (type === "gp" || type === "vh" || type === "v") return "passes";
    if (type === "s" || type === "m" || type === "cs" ||
        type === "cm" || type === "ms" || type === "cr") return "configurators";
    return "fruits";
  }

  function normalizeFilters(saved, defaults) {
    const out = Object.assign({}, defaults, saved || {});
    const s = saved || {};
    if (s.configurators === undefined && (s.skins !== undefined || s.mutations !== undefined)) {
      out.configurators = !!(s.skins || s.mutations);
    }
    delete out.skins;
    delete out.mutations;
    return out;
  }

  function autoPlace(itemId) {
    const found = findItem(itemId);
    if (!found) return;
    const v = parseVal(found.item.value);
    if (isNaN(v)) return;
    const item = found.item;
    found.tier.items = found.tier.items.filter(i => i.id !== itemId);
    for (const t of state.tiers) {
      for (let i = 0; i < t.items.length; i++) {
        const ov = parseVal(t.items[i].value);
        if (!isNaN(ov) && ov < v) {
          t.items.splice(i, 0, item);
          return;
        }
      }
    }
    state.tiers[state.tiers.length - 1].items.push(item);
  }

  function sortAllTiers() {
    state.tiers.forEach(t => {
      t.items.sort((a, b) => {
        const av = parseVal(a.value), bv = parseVal(b.value);
        if (isNaN(av) && isNaN(bv)) return 0;
        if (isNaN(av)) return 1;
        if (isNaN(bv)) return -1;
        return bv - av;
      });
    });
    save(); render();
  }

  function visibleItemsOf(tier) {
    return tier.items.filter(it => state.filters[groupOf(it.type)]);
  }

  const ITEMS_PER_BLOCK = 11;

  let eagerIconBudget = 0;

  function render() {
    teardownPromoStrip();
    tiersEl.innerHTML = "";
    eagerIconBudget = Math.max(6, (itemsPerRow() || ITEMS_PER_BLOCK) * 2);
    const editing = editToggle.checked;

    let blocks;
    if (editing) {
      blocks = [];
      state.tiers.forEach((tier, ti) => {
        const items = visibleItemsOf(tier);
        if (items.length || !tier.items.length) blocks.push({ tier, ti, items });
      });
    } else {
      const blockSize = Math.min(itemsPerRow() || ITEMS_PER_BLOCK, ITEMS_PER_BLOCK);
      const flat = [];
      state.tiers.forEach(tier => { for (const it of visibleItemsOf(tier)) flat.push(it); });
      blocks = [];

      if (!flat.length) {
        state.tiers.forEach((tier, ti) => blocks.push({ tier, ti, items: [] }));
      }
      for (let i = 0; i < flat.length; i += blockSize) {
        const bi = blocks.length;
        const tier = state.tiers[Math.min(bi, state.tiers.length - 1)];
        blocks.push({ tier, ti: bi, items: flat.slice(i, i + blockSize) });
      }
    }

    const adAfter = Math.ceil(blocks.length / 2) - 1;
    blocks.forEach((b, idx) => {
      tiersEl.appendChild(renderTier(b.tier, b.ti, b.items));
      if (idx === adAfter) tiersEl.appendChild(renderPromoBlock());
    });
    if (!blocks.length) tiersEl.appendChild(renderPromoBlock());
    renderFooter();
    renderCredits();
    renderDonate();
    applyFilters();
    applyEditMode();
    fitValues();
  }

  function fitValues() {
    requestAnimationFrame(() => {
      tiersEl.querySelectorAll(".cell-strip").forEach(strip => {
        const val = strip.querySelector(".cell-value");
        if (!val) return;
        val.style.fontSize = "";
        const base = parseFloat(getComputedStyle(val).fontSize);
        if (!base) return;
        const badge = strip.querySelector(".tbadge");
        const gap = parseFloat(getComputedStyle(strip).columnGap) || 0;
        const avail = strip.clientWidth - (badge ? badge.offsetWidth + gap : 0) - 1;
        const w = val.getBoundingClientRect().width;
        if (avail > 0 && w > avail) {
          val.style.fontSize = (base * avail / w).toFixed(2) + "px";
        }
      });
    });
  }

  function itemsPerRow() {
    const cs = getComputedStyle(stage);
    const contentW = stage.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    if (!contentW || contentW < 60) return 0;
    const cqw = contentW / 100;
    let cellW = 8.2 * cqw, colGap = 0.55 * cqw, panelPad = 0.8 * cqw;

    const pList = document.createElement("div");
    pList.className = "tier-items";
    pList.style.cssText = "position:absolute;visibility:hidden;width:auto;min-height:0;";
    const pCell = document.createElement("div");
    pCell.className = "cell";
    pList.appendChild(pCell);
    tiersEl.appendChild(pList);
    const lcs = getComputedStyle(pList);
    const cw = pCell.getBoundingClientRect().width;
    const g  = parseFloat(lcs.columnGap);
    const pad = parseFloat(lcs.paddingLeft) + parseFloat(lcs.paddingRight);
    tiersEl.removeChild(pList);
    if (cw > 0) cellW = cw;
    if (!isNaN(g)) colGap = g;
    if (!isNaN(pad)) panelPad = pad / 2;
    const innerW = contentW - 2 * panelPad - 2;
    const per = Math.floor((innerW + colGap) / (cellW + colGap) + 1e-6);
    return Math.max(1, per);
  }

  function renderBand(tier, ti, isFirst) {
    const band = document.createElement("div");
    band.className = "tier-band";

    if (tier.logo) {
      const img = document.createElement("img");
      img.className = "band-logo";
      img.src = tier.logo;
      img.alt = tier.label || "";
      img.onerror = () => { tier.logo = ""; save(); render(); };
      band.appendChild(img);
    } else {
      const label = document.createElement("div");
      label.className = "tier-label" + (isFirst ? "" : " cont-label");
      label.textContent = tier.label || "";
      label.spellcheck = false;
      if (isFirst) {
        label.title = tx("tier.rename");
        label.addEventListener("blur", () => { tier.label = label.textContent.trim(); save(); });
        label.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); label.blur(); } });
      }
      band.appendChild(label);
    }

    if (isFirst) {
      const tools = document.createElement("div");
      tools.className = "tier-tools edit-only";
      tools.appendChild(toolBtn("🖼", tx("tier.logo"), () => pickTierLogo(tier.id)));
      if (tier.logo) tools.appendChild(toolBtn("Т", tx("tier.logoOff"), () => { tier.logo = ""; save(); render(); }));
      tools.appendChild(toolBtn("▲", tx("tier.up"), () => moveTier(ti, -1)));
      tools.appendChild(toolBtn("▼", tx("tier.down"), () => moveTier(ti, +1)));
      tools.appendChild(toolBtn("✕", tx("tier.remove"), () => deleteTier(tier.id)));
      band.appendChild(tools);
    }
    return band;
  }

  function renderTier(tier, ti, items) {
    const sec = document.createElement("section");
    sec.className = "tier";
    sec.dataset.id = tier.id;

    const per = itemsPerRow();
    const chunks = [];
    if (per > 0 && items.length > per) {
      for (let i = 0; i < items.length; i += per) chunks.push(items.slice(i, i + per));
    } else {
      chunks.push(items.slice());
    }
    if (!chunks.length) chunks.push([]);

    chunks.forEach((chunk, ci) => {
      const isFirst = ci === 0;
      const isLast  = ci === chunks.length - 1;

      const group = document.createElement("div");
      group.className = "tier-rowgroup";
      group.appendChild(renderBand(tier, ti, isFirst));

      const list = document.createElement("div");
      list.className = "tier-items";
      list.dataset.tier = tier.id;
      chunk.forEach(item => list.appendChild(renderCell(item, tier)));

      if (isLast) {
        const add = document.createElement("div");
        add.className = "cell-add edit-only";
        add.title = tx("admin.addItemToTier");
        add.textContent = "＋";
        add.addEventListener("click", () => addItem(tier.id));
        list.appendChild(add);
      }

      setupDropzone(list, tier);
      group.appendChild(list);
      sec.appendChild(group);
    });

    return sec;
  }

  function toolBtn(txt, title, fn) {
    const b = document.createElement("button");
    b.className = "btn small";
    b.textContent = txt; b.title = title;
    b.addEventListener("click", fn);
    return b;
  }

  function renderCell(item, tier) {
    const cell = document.createElement("div");
    cell.className = "cell";
    cell.dataset.id = item.id;
    cell.dataset.group = groupOf(item.type);
    cell.draggable = true;

    if (item.demand) {
      const d = document.createElement("img");
      d.className = "dot";
      d.src = "assets/dot-" + item.demand + ".png";
      d.alt = "";
      cell.appendChild(d);
    }

    if (item.trend) {
      const tr = document.createElement("img");
      tr.className = "trend" + (item.trend === "swap" ? " tr-swap" : "");
      tr.src = "assets/design/legend/trend-" + item.trend + ".svg";
      tr.alt = "";
      cell.appendChild(tr);
    }

    const iconWrap = document.createElement("div");
    iconWrap.className = "cell-icon";
    const img = document.createElement("img");

    if (eagerIconBudget > 0) {
      eagerIconBudget--;
      img.loading = "eager";

      img.setAttribute("fetchpriority", "high");
    } else {
      img.loading = "lazy";
    }
    img.decoding = "async";
    img.src = item.icon || DEFAULT_ICON;
    img.alt = item.name || "";
    img.onerror = () => { img.src = DEFAULT_ICON; };
    iconWrap.appendChild(img);
    cell.appendChild(iconWrap);

    const strip = document.createElement("div");
    strip.className = "cell-strip";
    const val = document.createElement("span");
    val.className = "cell-value";
    val.textContent = item.value || "";
    strip.appendChild(val);
    if (item.type) {
      const b = document.createElement("img");
      b.className = "tbadge";
      b.src = badgeSrc(item.type);
      b.alt = item.type.toUpperCase();
      strip.appendChild(b);
    }
    cell.appendChild(strip);

    if (item.flag) {
      const nb = document.createElement("img");
      nb.className = "cell-new";
      nb.src = "assets/design/legend/trend-new.png";
      nb.alt = "NEW";
      cell.appendChild(nb);
    }

    if (item.wip) {
      const wb = document.createElement("img");
      wb.className = "cell-wip";
      wb.src = "assets/design/legend/trend-wip.svg";
      wb.alt = "?";
      wb.title = tx("cell.wipTitle");
      cell.appendChild(wb);
    }

    const nm = (item.name || "").trim();
    if (nm && nm !== "Item") {
      const tip = document.createElement("div");
      tip.className = "cell-tip";
      const tn = document.createElement("div");
      tn.className = "tip-name";
      tn.textContent = nm;
      tip.appendChild(tn);
      cell.appendChild(tip);
    }

    const edit = document.createElement("div");
    edit.className = "cell-edit";
    edit.appendChild(miniBtn("✎", tx("item.edit"), e => { e.stopPropagation(); openModal(item.id); }));
    edit.appendChild(miniBtn("✕", tx("modal.delete"), e => { e.stopPropagation(); deleteItem(item.id); }));
    cell.appendChild(edit);

    cell.addEventListener("dblclick", () => { if (stage.classList.contains("editing")) openModal(item.id); });
    cell.addEventListener("click", () => {
      if (stage.classList.contains("editing")) openModal(item.id);
      else openViewModal(item.id);
    });

    setupDraggable(cell, item, tier);
    return cell;
  }

  function miniBtn(txt, title, fn) {
    const b = document.createElement("button");
    b.textContent = txt; b.title = title;
    b.addEventListener("click", fn);
    return b;
  }

  const promo = (typeof PROMO !== "undefined") ? PROMO : null;

  const houseFor = slot => (promo ? promo.houseFor(slot, Date.now()) : null);

  function legacyAdEmpty() {
    const ad = (state && state.ad) || {};
    return !String(ad.image || "").trim() && !String(ad.link || "").trim();
  }

  function legacySameAs(camp) {
    const link = normalizeHref((state && state.ad && state.ad.link) || "", "");
    const href = promo ? promo.safeHref(camp && camp.href) : "";
    return !!link && !!href && link.replace(/\/+$/, "") === href.replace(/\/+$/, "");
  }

  function renderPromoBlock() {
    const list = stripOrder();
    if (list.length) return renderPromoStrip(list);

    if (promo && !stage.classList.contains("editing")) {
      const house = houseFor("strip");

      const sellable = house && house.id === promo.HOUSE_SLOT.id;
      if (house && (legacyAdEmpty() || (!sellable && legacySameAs(house)))) {
        return renderPromoStrip([house]);
      }
    }
    return renderLegacyAd();
  }

  function renderLegacyAd() {
    const ad = document.createElement("section");
    ad.className = "ptn-card";
    const adUrl = normalizeHref(state.ad.link, "");
    const hasLink = !!adUrl;
    if (hasLink) ad.classList.add("has-link");

    const openLink = (e) => {
      if (!hasLink || stage.classList.contains("editing")) return;
      if (e) e.preventDefault();
      window.open(adUrl, "_blank", "noopener");
    };

    ad.addEventListener("click", openLink);

    const makeBadge = () => {
      const b = document.createElement("span");
      b.className = "ptn-link-badge";

      b.setAttribute("aria-label", tx("ad.linkLabel"));
      b.title = tx("ad.isLink");
      return b;
    };

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    ad.appendChild(chip);

    if (state.ad.image) {
      const wrap = document.createElement("div");
      wrap.className = "ptn-img-wrap";
      const img = document.createElement("img");
      img.className = "ptn-img";
      img.src = state.ad.image;
      img.alt = tx("ad.imageAlt");
      img.draggable = false;
      wrap.appendChild(img);
      ad.appendChild(wrap);
    }

    const txt = document.createElement("div");
    txt.className = "ptn-text";
    txt.textContent = state.ad.text || "";
    txt.spellcheck = false;
    txt.addEventListener("blur", () => { state.ad.text = txt.textContent.trim(); save(); });
    txt.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); txt.blur(); } });
    ad.appendChild(txt);

    if (hasLink) ad.appendChild(makeBadge());

    const tools = document.createElement("div");
    tools.className = "ptn-tools edit-only";
    tools.appendChild(toolBtn(tx("ad.banner"), tx("ad.bannerTitle"), () => $("#ptnImgFile").click()));
    if (state.ad.image) tools.appendChild(toolBtn(tx("ad.textMode"), tx("ad.imageOff"), () => { state.ad.image = ""; save(); render(); }));
    tools.appendChild(toolBtn(hasLink ? tx("ad.linkSet") : tx("ad.link"),
      tx("ad.linkTitle"),
      () => {
        const v = prompt(tx("ad.linkPrompt"), state.ad.link || "");
        if (v !== null) { state.ad.link = normalizeHref(v, ""); save(); render(); }
      }));
    ad.appendChild(tools);
    return ad;
  }

  function promoEridNode(camp) {
    if (!camp || !camp.erid) return null;
    const el = document.createElement("span");
    el.className = "ptn-erid";
    el.textContent = "erid: " + camp.erid;
    return el;
  }

  const PROMO_SEED = Math.random();
  let promoRndState = 0;
  function promoResetRnd() { promoRndState = Math.floor(PROMO_SEED * 2147483646) + 1; }
  function promoRnd() {
    promoRndState = (promoRndState * 48271) % 2147483647;
    return promoRndState / 2147483647;
  }

  let promoOrderCache = null;
  let promoOrderRev = -1;
  let promoIndex = 0;

  function stripOrder() {
    if (!promo) return [];
    if (promoOrderCache && promoOrderRev === promoDoc.rev) return promoOrderCache;
    promoResetRnd();
    const eligible = promo.eligible(promoDoc, "strip", Date.now());
    promoOrderCache = promo.orderForCarousel(eligible, promoRnd, promo.MAX_STRIP_SLIDES);
    promoOrderRev = promoDoc.rev;
    promoIndex = 0;
    return promoOrderCache;
  }

  function openPromo(camp, slot) {
    const url = promo ? promo.safeHref(camp.href) : "";
    if (!url || stage.classList.contains("editing")) return;

    try { if (typeof ym === "function") ym(111127188, "reachGoal", "promo_click", { id: camp.id, slot: slot }); }
    catch (e) {}
    window.open(url, "_blank", "noopener");
  }

  let stripCtl = null;

  function teardownPromoStrip() {
    if (stripCtl) { stripCtl.destroy(); stripCtl = null; }
  }

  function renderPromoStrip(list) {
    const root = document.createElement("section");
    root.className = "ptn-card ptn-strip";
    root.setAttribute("aria-roledescription", "carousel");
    root.setAttribute("aria-label", tx("promo.region"));

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    root.appendChild(chip);

    const frame = document.createElement("div");
    frame.className = "ptn-frame";
    const viewport = document.createElement("div");
    viewport.className = "ptn-viewport";
    const track = document.createElement("div");
    track.className = "ptn-track";
    viewport.appendChild(track);
    frame.appendChild(viewport);
    root.appendChild(frame);

    const reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    list.forEach((camp, i) => {
      const cre = promo.creativeFor(camp, "strip");
      const slide = document.createElement("div");
      slide.className = "ptn-slide";
      slide.setAttribute("role", "group");
      slide.setAttribute("aria-roledescription", "slide");
      slide.setAttribute("aria-label", tx("promo.counter", { n: i + 1, m: list.length }));
      slide.dataset.cid = camp.id;
      if (promo.safeHref(camp.href)) slide.classList.add("has-link");

      if (cre) {
        const wrap = document.createElement("div");
        wrap.className = "ptn-img-wrap";
        const img = document.createElement("img");
        img.className = "ptn-img";

        img.dataset.src = cre.src;
        img.dataset.poster = cre.poster || cre.src;
        img.dataset.anim = cre.anim ? "1" : "";
        if (cre.w) img.width = cre.w;
        if (cre.h) img.height = cre.h;
        img.alt = tx("ad.imageAlt");
        img.draggable = false;
        img.decoding = "async";
        wrap.appendChild(img);
        slide.appendChild(wrap);
      }

      if (camp.text) {
        const txt = document.createElement("div");
        txt.className = "ptn-text";
        txt.textContent = camp.text;
        slide.appendChild(txt);
      }

      const erid = promoEridNode(camp);
      if (erid) slide.appendChild(erid);

      track.appendChild(slide);
    });

    const mkNav = (cls, label) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "ptn-nav ptn-export-hide " + cls;
      b.setAttribute("aria-label", label);
      b.title = label;
      return b;
    };
    const prev = mkNav("ptn-prev", tx("promo.prev"));
    const next = mkNav("ptn-next", tx("promo.next"));
    if (list.length > 1) {
      frame.appendChild(prev);
      frame.appendChild(next);
    }

    stripCtl = makeStripController({
      root: root, viewport: viewport, track: track,
      prev: prev, next: next, list: list, reduced: reduced
    });
    return root;
  }

  function makeStripController(ui) {
    const slides = Array.from(ui.track.children);
    const count = slides.length;

    let settleT = null;
    let scrollRaf = 0;
    let index = Math.min(promoIndex, count - 1);
    if (index < 0) index = 0;

    function syncWindow() {
      slides.forEach((slide, i) => {
        const img = slide.querySelector(".ptn-img");
        if (!img) return;
        const near = Math.abs(i - index) <= 1;
        const want = (ui.reduced && img.dataset.anim) ? img.dataset.poster : img.dataset.src;
        if (near) {
          if (img.getAttribute("src") !== want) img.src = want;
        } else if (img.hasAttribute("src")) {
          img.removeAttribute("src");
        }
      });
    }

    function goTo(i, smooth) {
      if (!count) return;
      index = ((i % count) + count) % count;
      promoIndex = index;
      const left = index * ui.viewport.clientWidth;

      ui.viewport.scrollTo({
        left: left,
        behavior: (smooth && !ui.reduced) ? "smooth" : "auto"
      });

      clearTimeout(settleT);
      const want = index;
      settleT = setTimeout(() => {
        if (index !== want) return;
        if (Math.abs(ui.viewport.scrollLeft - left) > 4) ui.viewport.scrollLeft = left;
      }, 450);
      syncWindow();
    }

    function onScroll() {
      if (scrollRaf) return;
      scrollRaf = requestAnimationFrame(() => {
        scrollRaf = 0;
        const w = ui.viewport.clientWidth || 1;
        const i = Math.round(ui.viewport.scrollLeft / w);
        if (i !== index && i >= 0 && i < count) {
          index = i;
          promoIndex = i;
          syncWindow();
        }
      });
    }

    let downAt = 0;
    function onDown() { downAt = ui.viewport.scrollLeft; }
    function onClick(e) {
      if (Math.abs(ui.viewport.scrollLeft - downAt) > 8) return;
      const slide = e.target.closest(".ptn-slide");
      if (!slide) return;
      const camp = ui.list.find(c => c.id === slide.dataset.cid);
      if (camp) openPromo(camp, "strip");
    }

    function onKey(e) {
      if (e.key === "ArrowLeft")  { e.preventDefault(); goTo(index - 1, true); }
      if (e.key === "ArrowRight") { e.preventDefault(); goTo(index + 1, true); }
    }

    const onVis = () => { if (document.visibilityState === "visible") syncWindow(); };

    ui.viewport.addEventListener("scroll", onScroll, { passive: true });
    ui.viewport.addEventListener("pointerdown", onDown, { passive: true });
    ui.track.addEventListener("click", onClick);
    ui.root.addEventListener("keydown", onKey);

    const nav = (e, i) => { goTo(i, true); if (e.detail > 0 && e.currentTarget.blur) e.currentTarget.blur(); };
    ui.prev.addEventListener("click", e => nav(e, index - 1));
    ui.next.addEventListener("click", e => nav(e, index + 1));
    document.addEventListener("visibilitychange", onVis);

    ui.root.tabIndex = -1;

    goTo(index, false);

    return {
      freezeForExport() {
        slides.forEach(slide => {
          const img = slide.querySelector(".ptn-img");
          if (img && img.dataset.anim && img.dataset.poster) img.src = img.dataset.poster;
        });
        ui.track.dataset.active = String(index);
      },
      unfreeze() { syncWindow(); },
      destroy() {
        clearTimeout(settleT);
        if (scrollRaf) cancelAnimationFrame(scrollRaf);
        document.removeEventListener("visibilitychange", onVis);
      }
    };
  }

  const RAIL_ROTATE_MS = 20000;
  const railMQ = window.matchMedia
    ? window.matchMedia("(min-width: 1460px) and (min-height: 760px)")
    : null;
  let railTimer = null;
  let railStep = 0;

  function renderPromoRails() {
    const left = $("#promoRailL"), right = $("#promoRailR");
    if (!left || !right) return;

    clearInterval(railTimer);
    railTimer = null;

    const wide = railMQ ? railMQ.matches : false;
    let list = (wide && promo) ? promo.eligible(promoDoc, "rail", Date.now()) : [];

    if (wide && promo && !list.length) {
      const house = houseFor("rail");
      if (house) list = [house];
    }
    if (!list.length) {
      [left, right].forEach(el => { el.hidden = true; el.innerHTML = ""; });
      return;
    }

    const paint = () => {
      const a = list[railStep % list.length];
      const b = list.length > 1 ? list[(railStep + 1) % list.length] : a;
      fillRail(left, a);
      fillRail(right, b);
      railStep++;
    };
    paint();
    if (list.length > 2) railTimer = setInterval(paint, RAIL_ROTATE_MS);
    syncRailTop();
  }

  const RAIL_MIN_TOP = 96;
  let railTopRaf = 0;

  function syncRailTop() {
    railTopRaf = 0;
    if (!railMQ || !railMQ.matches) return;
    const stage = $("#stage");
    if (!stage) return;
    let top = Math.max(RAIL_MIN_TOP, stage.getBoundingClientRect().top);

    const foot = document.querySelector(".mk-foot");
    const rail = document.querySelector(".ptn-rail:not([hidden])");
    if (foot && rail) {
      const h = rail.offsetHeight || 600;
      top = Math.min(top, foot.getBoundingClientRect().top - h);
    }
    document.documentElement.style.setProperty("--rail-top", Math.round(top) + "px");
  }

  function queueRailTop() {
    if (railTopRaf) return;
    railTopRaf = requestAnimationFrame(syncRailTop);
  }

  const dockMQ = window.matchMedia ? window.matchMedia("(max-width: 640px)") : null;
  let dockRO = null;

  function renderPromoDock() {
    const dock = $("#promoDock");
    if (!dock) return;
    const wide = dockMQ ? dockMQ.matches : false;
    let list = (wide && promo) ? promo.eligible(promoDoc, "dock", Date.now()) : [];

    if (wide && promo && !list.length) {
      const house = houseFor("dock");
      if (house) list = [house];
    }

    dock.innerHTML = "";
    if (!list.length) {
      dock.hidden = true;
      document.body.classList.remove("has-promo-dock");
      document.body.style.removeProperty("--ptn-dock-h");
      if (dockRO) { dockRO.disconnect(); dockRO = null; }
      return;
    }

    const camp = promo.pickWeighted(list, Math.random());
    const cre = promo.creativeFor(camp, "dock");
    if (!cre) { dock.hidden = true; document.body.classList.remove("has-promo-dock"); return; }

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    dock.appendChild(chip);

    const img = document.createElement("img");
    img.className = "ptn-dock-img";
    img.src = cre.src;
    img.alt = tx("ad.imageAlt");
    img.draggable = false;
    img.decoding = "async";
    if (cre.w) img.width = cre.w;
    if (cre.h) img.height = cre.h;
    dock.appendChild(img);

    const dockErid = promoEridNode(camp);
    if (dockErid) dock.appendChild(dockErid);

    const url = promo.safeHref(camp.href);
    dock.classList.toggle("has-link", !!url);
    dock.onclick = url ? (() => openPromo(camp, "dock")) : null;
    dock.tabIndex = url ? 0 : -1;
    dock.onkeydown = url ? (e => {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openPromo(camp, "dock"); }
    }) : null;

    dock.hidden = false;
    document.body.classList.add("has-promo-dock");

    const measure = () => {
      document.body.style.setProperty("--ptn-dock-h", Math.round(dock.offsetHeight) + "px");
    };
    measure();
    if (window.ResizeObserver) {
      if (dockRO) dockRO.disconnect();
      dockRO = new ResizeObserver(measure);
      dockRO.observe(dock);
    }
  }

  function fillRail(el, camp) {
    const cre = promo.creativeFor(camp, "rail");
    if (!cre) { el.hidden = true; el.innerHTML = ""; return; }
    el.innerHTML = "";
    el.hidden = false;

    const img = document.createElement("img");
    img.src = cre.src;
    img.alt = tx("ad.imageAlt");
    img.draggable = false;
    img.decoding = "async";
    img.loading = "lazy";
    el.appendChild(img);

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    el.appendChild(chip);

    const railErid = promoEridNode(camp);
    if (railErid) el.appendChild(railErid);

    const url = promo.safeHref(camp.href);
    el.classList.toggle("has-link", !!url);
    el.onclick = url ? (() => openPromo(camp, "rail")) : null;

    el.tabIndex = url ? 0 : -1;
    el.onkeydown = url ? (e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openPromo(camp, "rail"); } }) : null;
  }

  const PROMO_SEEN_KEY = "nx-ptn-seen-v1";
  let popupTimer = null;
  let popupOpened = false;
  let popupCamp = null;
  let popupRestoreFocus = null;

  let seenCache = null;
  function readSeen() {
    if (seenCache) return seenCache;
    try { seenCache = JSON.parse(localStorage.getItem(PROMO_SEEN_KEY)) || {}; }
    catch (e) { seenCache = {}; }
    return seenCache;
  }
  function writeSeen(v) {
    seenCache = v;
    try { localStorage.setItem(PROMO_SEEN_KEY, JSON.stringify(v)); } catch (e) {}
  }

  function otherModalOpen() {
    return ["#modal", "#viewModal", "#donateModal"].some(sel => {
      const el = $(sel);
      return el && !el.hidden;
    });
  }

  function schedulePromoPopup() {
    if (!promo || popupOpened) return;
    clearTimeout(popupTimer);

    if (document.visibilityState !== "visible") return;
    const now = Date.now();

    const camp = promo.popupPick(promoDoc, readSeen(), now, Math.random());
    if (!camp) return;
    popupTimer = setTimeout(() => tryOpenPromoPopup(camp), camp.popup.delayMs);
  }

  function tryOpenPromoPopup(camp) {
    if (popupOpened || !promo) return;
    if (isAdmin || editToggle.checked || exporting) return;
    if (document.visibilityState !== "visible" || otherModalOpen()) return;
    if (!promo.shouldShowPopup(camp, readSeen(), Date.now())) return;

    const cre = promo.creativeFor(camp, "popup");
    if (!cre) return;
    const reduced = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const src = (reduced && cre.anim && cre.poster) ? cre.poster : cre.src;

    const img = $("#promoPopImg");
    let done = false;
    const go = () => { if (!done) { done = true; openPromoPopup(camp, src); } };
    img.src = src;
    if (img.decode) { img.decode().then(go).catch(go); } else { img.onload = go; img.onerror = go; }
    setTimeout(go, 4000);
  }

  function openPromoPopup(camp, src) {
    if (popupOpened) return;
    if (isAdmin || editToggle.checked || exporting || otherModalOpen()) return;
    popupOpened = true;
    popupCamp = camp;
    writeSeen(promo.recordPopupShown(readSeen(), camp.id, Date.now()));

    const pop = $("#promoPop");
    $("#promoPopImg").src = src;

    setPromoCopy($("#promoPopTitle"), camp.textKey, camp.text, null);
    const cta = $("#promoPopCta");
    const url = promo.safeHref(camp.href);
    setPromoCopy(cta, camp.ctaKey, camp.cta, "promo.cta");
    cta.hidden = !url;
    if (url) cta.href = url;

    const popErid = $("#promoPopErid");
    if (popErid) {
      popErid.textContent = camp.erid ? "erid: " + camp.erid : "";
      popErid.hidden = !camp.erid;
    }

    pop.hidden = false;

    document.body.classList.add("ptn-locked");
    popupRestoreFocus = document.activeElement;
    ["#toolbar", ".stage-wrap", "#likeBtn", "#donateBtn"].forEach(sel => {
      const el = document.querySelector(sel);
      if (el) el.setAttribute("aria-hidden", "true");
    });
    document.addEventListener("keydown", onPopupKey, true);
    setTimeout(() => { const b = $("#promoPopClose"); if (b) b.focus(); }, 20);
  }

  function setPromoCopy(el, key, text, fallbackKey) {
    if (key) {
      el.setAttribute("data-i18n", key);
      el.textContent = tx(key);
      return;
    }
    el.removeAttribute("data-i18n");
    el.textContent = text || (fallbackKey ? tx(fallbackKey) : "");
  }

  function closePromoPopup() {
    const pop = $("#promoPop");
    if (!pop || pop.hidden) return;
    pop.hidden = true;
    document.body.classList.remove("ptn-locked");
    ["#toolbar", ".stage-wrap", "#likeBtn", "#donateBtn"].forEach(sel => {
      const el = document.querySelector(sel);
      if (el) el.removeAttribute("aria-hidden");
    });
    document.removeEventListener("keydown", onPopupKey, true);

    const img = $("#promoPopImg");
    if (img) img.removeAttribute("src");
    if (popupRestoreFocus && popupRestoreFocus.focus) { try { popupRestoreFocus.focus(); } catch (e) {} }
    popupRestoreFocus = null;
  }

  function onPopupKey(e) {
    const pop = $("#promoPop");
    if (!pop || pop.hidden) return;
    if (e.key === "Escape") { e.preventDefault(); closePromoPopup(); return; }
    if (e.key !== "Tab") return;
    const focusables = Array.from(pop.querySelectorAll("button, a[href], [tabindex]:not([tabindex='-1'])"))
      .filter(el => !el.hidden && el.offsetParent !== null);
    if (!focusables.length) return;
    const first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function initPromoPopup() {
    const pop = $("#promoPop");
    if (!pop) return;
    $("#promoPopClose").addEventListener("click", closePromoPopup);
    pop.addEventListener("click", e => { if (e.target === pop) closePromoPopup(); });
    $("#promoPopCta").addEventListener("click", () => {
      if (!popupCamp || !promo) return;
      writeSeen(promo.recordPopupClicked(readSeen(), popupCamp.id, Date.now()));
      try { if (typeof ym === "function") ym(111127188, "reachGoal", "promo_click", { id: popupCamp.id, slot: "popup" }); }
      catch (e) {}
      closePromoPopup();
    });
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") schedulePromoPopup();
    });
    schedulePromoPopup();
  }

  function renderCredits() {
    if (!creditsEl) return;
    creditsEl.innerHTML = "";
    state.credits.forEach((cr, idx) => {
      const el = document.createElement("div");
      el.className = "credit";

      const role = document.createElement("span");
      role.className = "cr-role";
      role.textContent = cr.role || "";
      role.spellcheck = false;
      role.addEventListener("blur", () => { cr.role = role.textContent.trim(); save(); });
      role.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); role.blur(); } });

      const name = document.createElement("span");
      name.className = "cr-name";
      name.textContent = cr.name || "";
      name.spellcheck = false;
      name.addEventListener("blur", () => { cr.name = name.textContent.trim(); save(); });
      name.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); name.blur(); } });

      const del = document.createElement("button");
      del.className = "credit-del edit-only";
      del.textContent = "✕";
      del.title = tx("credits.remove");
      del.addEventListener("click", () => { state.credits.splice(idx, 1); save(); render(); });

      el.appendChild(role); el.appendChild(name); el.appendChild(del);
      creditsEl.appendChild(el);
    });

    const add = document.createElement("button");
    add.className = "credit-add edit-only";
    add.textContent = "＋";
    add.title = tx("credits.add");
    add.addEventListener("click", () => { state.credits.push({ role: "Роль", name: "Имя" }); save(); render(); });
    creditsEl.appendChild(add);
  }

  function renderFooter() {
    footerEl.innerHTML = "";
    state.footer.forEach((lnk, idx) => {
      const a = document.createElement("a");
      a.className = "flink";

      const url = normalizeHref(lnk.href, lnk.sub);
      if (url) a.href = url; else a.classList.add("flink-nourl");
      a.target = "_blank";
      a.rel = "noopener";

      a.addEventListener("click", e => { if (stage.classList.contains("editing")) e.preventDefault(); });

      const title = document.createElement("span");
      title.className = "fl-title";
      title.textContent = lnk.title || "";
      title.spellcheck = false;
      title.addEventListener("blur", () => { lnk.title = title.textContent.trim(); save(); });
      title.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); title.blur(); } });

      const sub = document.createElement("span");
      sub.className = "fl-sub";
      sub.textContent = lnk.sub || "";
      sub.spellcheck = false;
      sub.addEventListener("blur", () => { lnk.sub = sub.textContent.trim(); save(); });
      sub.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); sub.blur(); } });

      const tools = document.createElement("div");
      tools.className = "flink-tools edit-only";
      const urlBtn = document.createElement("button");
      urlBtn.textContent = "🔗";
      urlBtn.title = tx("footer.editUrl");
      urlBtn.addEventListener("click", e => {
        e.preventDefault(); e.stopPropagation();
        const v = prompt(tx("footer.urlPrompt"), lnk.href || "");

        if (v !== null) { lnk.href = normalizeHref(v, ""); save(); render(); }
      });
      const del = document.createElement("button");
      del.className = "danger";
      del.textContent = "✕";
      del.title = tx("footer.removeLink");
      del.addEventListener("click", e => {
        e.preventDefault(); e.stopPropagation();
        state.footer.splice(idx, 1); save(); render();
      });
      tools.appendChild(urlBtn); tools.appendChild(del);

      a.appendChild(title);
      a.appendChild(sub);
      a.appendChild(tools);
      footerEl.appendChild(a);
    });

    const add = document.createElement("button");
    add.className = "flink-add edit-only";
    add.textContent = tx("footer.addLinkBtn");
    add.title = tx("footer.addLink");
    add.addEventListener("click", () => {
      state.footer.push({ title: "НАЗВАНИЕ", sub: "ссылка", href: "https://" });
      save(); render();
    });
    footerEl.appendChild(add);
  }

  let dragData = null;

  function setupDraggable(cell, item, tier) {
    cell.addEventListener("dragstart", e => {
      if (!stage.classList.contains("editing")) { e.preventDefault(); return; }
      dragData = { itemId: item.id, fromTierId: tier.id };
      cell.classList.add("dragging");
      e.dataTransfer.effectAllowed = "move";
      try { e.dataTransfer.setData("text/plain", item.id); } catch (_) {}
    });
    cell.addEventListener("dragend", () => {
      cell.classList.remove("dragging");
      dragData = null;
      document.querySelectorAll(".tier.drag-over").forEach(t => t.classList.remove("drag-over"));
    });
  }

  function setupDropzone(list, tier) {
    const sec = () => list.closest(".tier");
    list.addEventListener("dragover", e => {
      if (!dragData) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = "move";
      sec().classList.add("drag-over");
    });
    list.addEventListener("dragleave", e => {
      if (!list.contains(e.relatedTarget)) sec().classList.remove("drag-over");
    });
    list.addEventListener("drop", e => {
      e.preventDefault();
      sec().classList.remove("drag-over");
      if (!dragData) return;
      const targetCell = e.target.closest(".cell");
      moveItem(dragData.itemId, tier.id, targetCell ? targetCell.dataset.id : null);
      dragData = null;
    });
  }

  function moveItem(itemId, toTierId, beforeItemId) {
    const found = findItem(itemId);
    if (!found) return;
    const fromTier = found.tier;
    const item = found.item;

    fromTier.items = fromTier.items.filter(i => i.id !== itemId);
    const toTier = findTier(toTierId);
    if (!toTier) return;
    if (beforeItemId && beforeItemId !== itemId) {
      const idx = toTier.items.findIndex(i => i.id === beforeItemId);
      toTier.items.splice(idx < 0 ? toTier.items.length : idx, 0, item);
    } else {
      toTier.items.push(item);
    }
    save();
    render();
  }

  function addTier() {
    state.tiers.push({ id: uid(), label: "Новый тир", logo: "", items: [] });
    save(); render();
  }
  function deleteTier(tid) {
    const t = findTier(tid);
    if (!t) return;
    if (t.items.length && !confirm(tx("msg.confirmDeleteTier", { tier: t.label, count: t.items.length }))) return;
    state.tiers = state.tiers.filter(x => x.id !== tid);
    save(); render();
  }
  function moveTier(index, dir) {
    const ni = index + dir;
    if (ni < 0 || ni >= state.tiers.length) return;
    const arr = state.tiers;
    [arr[index], arr[ni]] = [arr[ni], arr[index]];
    save(); render();
  }
  function addItem(tid) {
    const t = findTier(tid);
    if (!t) return;
    const item = {
      id: uid(), name: "Item", value: "0", icon: DEFAULT_ICON, type: "f", demand: "", trend: "",
      desc: "", descEn: "", terms: "", termsEn: "", tag: "", tagEn: "", flag: true, wip: false,
    };
    t.items.push(item);
    save(); render();
    openModal(item.id);
  }
  function deleteItem(iid) {
    const found = findItem(iid);
    if (!found) return;
    found.tier.items = found.tier.items.filter(i => i.id !== iid);
    save(); render();
  }

  let tierLogoTarget = null;
  function pickTierLogo(tid) {
    tierLogoTarget = tid;
    $("#tierLogoFile").click();
  }
  $("#tierLogoFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file || !tierLogoTarget) return;
    const tid = tierLogoTarget;
    tierLogoTarget = null;
    fileToSmallDataURL(file, 160, 0.85).then(du => uploadDataUrl(du)).then(url => {
      const t = findTier(tid);
      if (t && url) { t.logo = url; save(); render(); }
    });
    e.target.value = "";
  });

  $("#ptnImgFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;

    fileToBudgetedDataURL(file, [[1280, 0.85], [1280, 0.7], [1024, 0.7], [800, 0.65]], 460000)
      .then(du => uploadDataUrl(du))
      .then(url => {
        if (url) { state.ad.image = url; save(); render(); }
      });
    e.target.value = "";
  });

  const FILTER_KEYS = ["configurators", "fruits", "perms", "passes"];
  const allFiltersOn = () => FILTER_KEYS.every(k => state.filters[k]);
  const filtersEl = $("#filters");

  function applyFilters() {
    FILTER_KEYS.forEach(key => {
      const chip = filtersEl.querySelector(`.chip[data-f="${key}"]`);
      if (chip) chip.classList.toggle("active", !!state.filters[key]);
    });
    const all = filtersEl.querySelector('.chip[data-f="all"]');
    if (all) all.classList.toggle("active", allFiltersOn());
  }
  filtersEl.addEventListener("click", e => {
    const chip = e.target.closest(".chip");
    if (!chip) return;
    const key = chip.dataset.f;
    if (key === "all") {
      FILTER_KEYS.forEach(k => state.filters[k] = true);
    } else {
      state.filters[key] = !state.filters[key];

      if (!FILTER_KEYS.some(k => state.filters[k])) {
        state.filters[key] = true;
        return;
      }
    }
    save();
    render();
  });

  const modal = $("#modal");
  let editingId = null;

  function openModal(iid) {
    if (!isAdmin) return;
    const found = findItem(iid);
    if (!found) return;
    editingId = iid;
    const it = found.item;
    $("#mName").value = it.name || "";
    $("#mValue").value = it.value || "";
    $("#mDesc").value = it.desc || "";
    $("#mDescEn").value = it.descEn || "";
    $("#mTerms").value = it.terms || "";
    $("#mTermsEn").value = it.termsEn || "";
    $("#mTag").value = it.tag || "";
    $("#mTagEn").value = it.tagEn || "";
    setFlag("flag", it.flag);
    setFlag("wip", it.wip);
    $("#mIconPreview").src = it.icon || DEFAULT_ICON;
    setType(it.type || "f");
    setSeg("#mDemand", it.demand || "");
    setSeg("#mTrend", it.trend || "");
    modal.hidden = false;
    setTimeout(() => $("#mName").focus(), 30);
  }
  function closeModal() { modal.hidden = true; editingId = null; }

  const KIND_LABEL = {
    f:  ["view.kindFruit",     ""],
    p:  ["view.kindFruit",     "view.kindSubPerm"],
    cs: ["view.kindConfig",    "view.kindSubSkin"],
    cm: ["view.kindConfig",    "view.kindSubMutation"],
    ms: ["view.kindMutation",  "view.kindSubSkin"],
    cr: ["view.kindChromatic", ""],
    gp: ["view.kindPass",      ""],
    vh: ["view.kindVoucher",   ""],
    s:  ["view.kindConfig",    "view.kindSubSkin"],
    m:  ["view.kindConfig",    "view.kindSubMutation"],
    v:  ["view.kindVoucher",   ""],
  };

  const viewModal = $("#viewModal");
  function openViewModal(iid) {
    const found = findItem(iid);
    if (!found) return;
    const it = found.item;
    $("#vIcon").src = it.icon || DEFAULT_ICON;
    $("#vName").textContent = (it.name || "").trim() || tx("view.noName");
    $("#vValue").textContent = it.value || "—";

    const [mainKey, subKey] = KIND_LABEL[it.type] || KIND_LABEL.f;
    $("#vKindMain").textContent = tx(mainKey);
    const subEl = $("#vKindSub");
    subEl.textContent = subKey ? tx(subKey) : "";
    subEl.hidden = !subKey;

    const tag = textFor(it, "tag");
    $("#vTag").textContent = tag;
    $("#vTagPill").hidden = !tag;

    const ds = descFor(it);
    const descEl = $("#vDesc");
    descEl.textContent = ds || tx("view.noDesc");
    descEl.classList.toggle("empty", !ds);

    const terms = textFor(it, "terms");
    $("#vTerms").textContent = terms;
    $("#vTermsPanel").hidden = !terms;

    viewModal.hidden = false;
  }
  function closeViewModal() { viewModal.hidden = true; }
  $("#viewClose").addEventListener("click", closeViewModal);
  viewModal.addEventListener("click", e => { if (e.target === viewModal) closeViewModal(); });

  const SEG_VALUE = "button:not([data-flag])";
  function setSeg(sel, value) {
    $(sel).querySelectorAll(SEG_VALUE).forEach(b => {
      b.classList.toggle("active", (b.dataset.v || "") === value);
    });
  }
  function getSeg(sel) {
    const a = $(sel).querySelector(SEG_VALUE + ".active");
    return a ? (a.dataset.v || "") : "";
  }

  const flagBtn = name => $(`#mTrend button[data-flag="${name}"]`);
  function setFlag(name, on) { flagBtn(name).classList.toggle("active", !!on); }
  function getFlag(name) { return flagBtn(name).classList.contains("active"); }

  const CATEGORIES = ["cs", "cm", "ms", "cr", "gp", "vh"];

  const LEGACY_CATEGORY = { s: "cs", m: "cm", v: "vh" };
  function setType(type) {
    const cat = LEGACY_CATEGORY[type] || type;
    const isCat = CATEGORIES.includes(cat);
    $("#mFruit").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    if (!isCat) {
      const v = type === "p" ? "p" : "f";
      $("#mFruit").querySelector(`button[data-v="${v}"]`).classList.add("active");
    }
    setSeg("#mType2", isCat ? cat : "");
  }
  function getType() {
    const cat = getSeg("#mType2");
    if (cat) return cat;
    const fr = $("#mFruit").querySelector("button.active");
    return fr ? fr.dataset.v : "";
  }

  $("#mFruit").addEventListener("click", e => {
    const btn = e.target.closest("button");
    if (!btn) return;
    $("#mFruit").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    setSeg("#mType2", "");
  });

  $("#mType2").addEventListener("click", e => {
    const btn = e.target.closest("button");
    if (!btn) return;
    $("#mType2").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    if (btn.dataset.v) {
      $("#mFruit").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    } else if (!$("#mFruit").querySelector("button.active")) {
      $("#mFruit").querySelector('button[data-v="f"]').classList.add("active");
    }
  });

  ["#mDemand", "#mTrend"].forEach(sel => {
    $(sel).addEventListener("click", e => {
      const btn = e.target.closest("button");
      if (!btn) return;

      if (btn.dataset.flag) { btn.classList.toggle("active"); return; }
      $(sel).querySelectorAll(SEG_VALUE).forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  $("#mIconFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    fileToSmallDataURL(file, 160, 0.85).then(du => uploadDataUrl(du)).then(url => {
      if (url) $("#mIconPreview").src = url;
    });
    e.target.value = "";
  });
  $("#mIconReset").addEventListener("click", () => { $("#mIconPreview").src = DEFAULT_ICON; });

  $("#mSave").addEventListener("click", () => {
    const found = findItem(editingId);
    if (!found) return closeModal();
    const it = found.item;
    const oldVal = it.value;
    it.name = $("#mName").value.trim();
    it.value = $("#mValue").value.trim();
    it.desc = $("#mDesc").value.trim();
    it.descEn = $("#mDescEn").value.trim();
    it.terms = $("#mTerms").value.trim();
    it.termsEn = $("#mTermsEn").value.trim();
    it.tag = $("#mTag").value.trim();
    it.tagEn = $("#mTagEn").value.trim();
    it.flag = getFlag("flag");
    it.wip = getFlag("wip");
    it.icon = $("#mIconPreview").src;
    it.type = getType();
    it.demand = getSeg("#mDemand");
    it.trend = getSeg("#mTrend");

    if (state.autoSort && it.value !== oldVal) autoPlace(it.id);
    save(); render(); closeModal();
  });
  $("#mDelete").addEventListener("click", () => {
    if (editingId) deleteItem(editingId);
    closeModal();
  });
  $("#modalClose").addEventListener("click", closeModal);
  modal.addEventListener("click", e => { if (e.target === modal) closeModal(); });
  document.addEventListener("keydown", e => {
    if (e.key !== "Escape") return;
    if (!modal.hidden) closeModal();
    if (!viewModal.hidden) closeViewModal();
  });

  const dateEl = $("#tlDate");
  dateEl.textContent = state.date;
  dateEl.addEventListener("blur", () => { state.date = dateEl.textContent.trim(); save(); });
  dateEl.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); dateEl.blur(); } });

  function applyEditMode() {
    const on = editToggle.checked;
    stage.classList.toggle("editing", on);
    document.querySelectorAll(".edit-only").forEach(el => { el.style.display = on ? "" : "none"; });

    document.querySelectorAll(".tier-label:not(.cont-label), #tlDate, .ptn-text, .cr-role, .cr-name, .fl-title, .fl-sub").forEach(el => {
      el.contentEditable = on ? "true" : "false";
    });
  }

  editToggle.addEventListener("change", render);

  autoSortToggle.checked = state.autoSort;
  autoSortToggle.addEventListener("change", () => {
    state.autoSort = autoSortToggle.checked;
    save();
  });

  $("#btnAddTier").addEventListener("click", addTier);
  $("#btnAddItem").addEventListener("click", () => {
    if (!state.tiers.length) addTier();
    addItem(state.tiers[0].id);
  });
  $("#btnSort").addEventListener("click", sortAllTiers);
  if (btnSave) btnSave.addEventListener("click", publish);

  window.addEventListener("beforeunload", e => {
    if (isAdmin && dirty) { e.preventDefault(); e.returnValue = ""; }
  });
  $("#btnReset").addEventListener("click", () => {
    if (confirm(tx("msg.confirmReset"))) {
      state = defaultState();
      dateEl.textContent = state.date;
      autoSortToggle.checked = state.autoSort;
      save(); render();
    }
  });

  $("#btnExport").addEventListener("click", () => {
    const blob = new Blob([JSON.stringify(state, null, 2)], { type: "application/json" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "nexus-tierlist.json";
    a.click();
    URL.revokeObjectURL(a.href);
  });
  const importFile = $("#importFile");
  $("#btnImport").addEventListener("click", () => importFile.click());
  importFile.addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      try {
        const data = JSON.parse(reader.result);
        if (!data.tiers) throw new Error(tx("msg.noTiersField"));
        const d = defaultState();
        state = Object.assign({}, d, data);
        state.ad = Object.assign({}, d.ad, data.ad || {});
        state.donate = Object.assign({}, d.donate, data.donate || {});
        state.filters = normalizeFilters(data.filters, d.filters);
        if (!Array.isArray(state.credits) || !state.credits.length) state.credits = d.credits;
        if (!Array.isArray(state.footer) || !state.footer.length) state.footer = d.footer;
        normalizeTierLogos(state.tiers, true);
        dateEl.textContent = state.date || "";
        autoSortToggle.checked = state.autoSort;
        save(); render();
      } catch (err) {
        alert(tx("msg.readFailed") + err.message);
      }
    };
    reader.readAsText(file);
    e.target.value = "";
  });

  const DEBUG = /(?:^|[?&])debug=1(?:&|$)/.test(location.search);
  const dbgLines = [];
  let dbgBody = null;

  function dbgInit() {
    if (dbgBody) return;
    const box = document.createElement("div");
    box.style.cssText =
      "position:fixed;left:6px;right:6px;top:6px;z-index:99999;max-height:48vh;overflow:auto;" +
      "background:rgba(4,8,20,.95);border:1px solid #2aa6e0;border-radius:8px;padding:8px;" +
      "color:#bfe6ff;font:11px/1.35 ui-monospace,Menlo,Consolas,monospace;white-space:pre-wrap;" +
      "word-break:break-word;-webkit-user-select:text;user-select:text";
    dbgBody = document.createElement("div");
    const bar = document.createElement("div");
    bar.style.cssText = "display:flex;gap:6px;justify-content:flex-end;margin-top:6px";
    const mkBtn = (label, fn) => {
      const b = document.createElement("button");
      b.textContent = label;
      b.style.cssText = "font:600 11px system-ui;padding:5px 10px;border-radius:6px;" +
        "border:1px solid #2aa6e0;background:#0d2138;color:#cfe9ff";
      b.addEventListener("click", fn);
      return b;
    };
    bar.appendChild(mkBtn(tx("msg.copy"), () => {
      const text = dbgLines.join("\n");
      if (navigator.clipboard) navigator.clipboard.writeText(text).catch(() => {});
      const sel = window.getSelection();
      const range = document.createRange();
      range.selectNodeContents(dbgBody);
      sel.removeAllRanges(); sel.addRange(range);
    }));
    bar.appendChild(mkBtn("✕", () => box.remove()));
    box.appendChild(dbgBody);
    box.appendChild(bar);
    document.body.appendChild(box);
  }

  function dbg(msg) {
    if (!DEBUG) return;
    dbgInit();
    dbgLines.push(msg);
    dbgBody.textContent = dbgLines.join("\n");
    dbgBody.parentElement.scrollTop = dbgBody.parentElement.scrollHeight;
  }

  function dbgProbeIcons(canvas, scale) {
    if (!DEBUG) return;
    const sr = stage.getBoundingClientRect();
    const ctx = canvas.getContext("2d");
    const imgs = Array.from(stage.querySelectorAll(".cell-icon img")).slice(0, 5);
    imgs.forEach((im, i) => {
      const r = im.getBoundingClientRect();
      const x = Math.round((r.left - sr.left + r.width * .25) * scale);
      const y = Math.round((r.top - sr.top + r.height * .25) * scale);
      const w = Math.max(1, Math.round(r.width * .5 * scale));
      const h = Math.max(1, Math.round(r.height * .5 * scale));
      try {
        const d = ctx.getImageData(x, y, w, h).data;
        let min = 255, max = 0, sum = 0, clear = 0, n = 0;
        for (let p = 0; p < d.length; p += 4) {
          const lum = (d[p] * 299 + d[p + 1] * 587 + d[p + 2] * 114) / 1000;
          if (d[p + 3] < 16) clear++;
          if (lum < min) min = lum;
          if (lum > max) max = lum;
          sum += lum; n++;
        }
        dbg("  икон" + i + ": разброс " + Math.round(max - min) +
            " сред " + Math.round(sum / n) + " прозр " + Math.round(clear / n * 100) + "%" +
            (max - min < 12 ? "  <- ПУСТО" : ""));
      } catch (e) {
        dbg("  икон" + i + ": getImageData → " + e.name + " (холст протух)");
      }
    });
  }

  if (DEBUG) dbg("диагностика включена — нажми «⬇ Скачать PNG»");

  let h2cPromise = null;
  function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (!h2cPromise) {
      h2cPromise = new Promise((resolve, reject) => {
        const s = document.createElement("script");
        s.src = "js/html2canvas.min.js";
        s.onload = () => resolve(window.html2canvas);
        s.onerror = () => { h2cPromise = null; reject(new Error(tx("msg.h2cFailed"))); };
        document.head.appendChild(s);
      });
    }
    return h2cPromise;
  }

  const isIOS = () => /iP(hone|ad|od)/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);

  function exportScale(el) {
    const r = el.getBoundingClientRect();
    const area = Math.max(1, r.width * r.height);
    const mobile = isIOS() || (window.matchMedia && matchMedia("(pointer: coarse)").matches);
    const budget = mobile ? 3.5e6 : 12e6;
    return Math.max(1, Math.min(2, Math.sqrt(budget / area)));
  }

  async function eagerLoadStageImages() {
    const decoded = img => (img.decode ? img.decode().catch(() => {}) : Promise.resolve());

    const imgs = Array.from(stage.querySelectorAll("img"));
    for (let i = 0; i < imgs.length; i += 12) {
      await Promise.all(imgs.slice(i, i + 12).map(img => {
        img.loading = "eager";

        if (img.complete) return decoded(img);
        return new Promise(res => {
          const done = () => { clearTimeout(t); decoded(img).then(res); };
          const t = setTimeout(done, 8000);
          img.addEventListener("load", done, { once: true });
          img.addEventListener("error", done, { once: true });
        });
      }));
    }
  }

  function inlineStageImages(scale) {
    const map = new Map();
    let canvas = document.createElement("canvas");
    let ctx = canvas.getContext("2d");
    let tried = 0, ok = 0, notLoaded = 0, badUrl = 0, tainted = 0;
    const samples = [];
    stage.querySelectorAll("img").forEach(img => {
      const src = img.currentSrc || img.src;
      if (!src || src.startsWith("data:") || map.has(src)) return;
      if (!img.naturalWidth) {
        notLoaded++;
        if (samples.length < 3) samples.push("не загружена: " + src.slice(-38));
        return;
      }
      const box = img.getBoundingClientRect();
      const w = Math.min(img.naturalWidth, Math.max(1, Math.round(box.width * scale))) || img.naturalWidth;
      const h = Math.min(img.naturalHeight, Math.max(1, Math.round(box.height * scale))) || img.naturalHeight;
      tried++;
      try {
        canvas.width = w; canvas.height = h;
        if (!canvas.width || !canvas.height) return;
        ctx.clearRect(0, 0, w, h);
        ctx.drawImage(img, 0, 0, w, h);

        const url = canvas.toDataURL("image/png");

        if (url.indexOf("data:image/") !== 0 || url.length < 64) {
          badUrl++;
          if (samples.length < 3) {
            samples.push("toDataURL=" + JSON.stringify(url.slice(0, 24)) + " len " + url.length +
                         " " + w + "x" + h + " " + src.slice(-30));
          }
          return;
        }
        map.set(src, url);
        if (img.src && img.src !== src) map.set(img.src, url);
        ok++;
      } catch (e) {
        tainted++;
        if (samples.length < 3) samples.push("исключение " + e.name + ": " + src.slice(-30));
        canvas = document.createElement("canvas");
        ctx = canvas.getContext("2d");
      }
    });
    canvas.width = canvas.height = 0;
    dbg("врезка: всего " + tried + ", удалось " + ok + ", не загружено " + notLoaded +
        ", плохой data-URL " + badUrl + ", исключений " + tainted +
        (tried && ok < tried * 0.9 ? "  <- КАРТА СБРОШЕНА (<90%)" : ""));
    samples.forEach(s => dbg("  " + s));

    if (tried && ok < tried * 0.9) map.clear();
    return map;
  }

  function nextFrames() {
    return new Promise(res => {
      const t = setTimeout(res, 1000);
      requestAnimationFrame(() => requestAnimationFrame(() => { clearTimeout(t); res(); }));
    });
  }

  function canvasToBlob(canvas) {
    return new Promise((resolve, reject) => {
      if (!canvas.toBlob) {
        try {
          const bin = atob(canvas.toDataURL("image/png").split(",")[1]);
          const buf = new Uint8Array(bin.length);
          for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
          resolve(new Blob([buf], { type: "image/png" }));
        } catch (e) { reject(e); }
        return;
      }
      canvas.toBlob(
        b => (b ? resolve(b) : reject(new Error(tx("msg.pngMemory")))),
        "image/png"
      );
    });
  }

  const PNG_NAME = "nexus-tier-list.png";
  let pendingBlobUrl = null;

  function anchorDownload(blob) {
    if (pendingBlobUrl) URL.revokeObjectURL(pendingBlobUrl);
    pendingBlobUrl = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = pendingBlobUrl;
    a.download = PNG_NAME;
    a.rel = "noopener";
    document.body.appendChild(a);
    a.click();
    a.remove();

    setTimeout(() => {
      if (pendingBlobUrl) { URL.revokeObjectURL(pendingBlobUrl); pendingBlobUrl = null; }
    }, 60000);
  }

  function saveBlob(blob) {
    if (isIOS() && navigator.canShare && typeof File === "function") {
      try {
        const file = new File([blob], PNG_NAME, { type: "image/png" });
        if (navigator.canShare({ files: [file] })) {
          dbg("сохранение: navigator.share");
          navigator.share({ files: [file], title: "Maknemy Tier List" })
            .catch(err => {
              dbg("share отклонён: " + (err && err.name));
              if (!err || err.name !== "AbortError") anchorDownload(blob);
            });
          return;
        }
        dbg("canShare(files) = false");
      } catch (e) { dbg("share недоступен: " + e.name); }
    }
    dbg("сохранение: <a download>");
    anchorDownload(blob);
  }

  const btnPng = $("#btnPng");
  const PNG_LABEL = btnPng.textContent;
  const PNG_TITLE = btnPng.title;
  let readyBlob = null;
  let readyTimer = null;
  let exporting = false;
  let pendingReflow = false;

  function resetPngButton() {
    clearTimeout(readyTimer);
    readyBlob = null;
    btnPng.textContent = PNG_LABEL;
    btnPng.title = PNG_TITLE;
    btnPng.disabled = false;
    btnPng.classList.remove("png-ready");

    if (savedHint.dataset.pngHint) { savedHint.textContent = ""; delete savedHint.dataset.pngHint; }
  }

  btnPng.addEventListener("click", async () => {
    if (readyBlob) {
      const blob = readyBlob;
      resetPngButton();
      saveBlob(blob);
      return;
    }

    const wasEditing = editToggle.checked;
    editToggle.checked = false;
    applyEditMode();
    btnPng.textContent = tx("png.rendering");
    btnPng.disabled = true;
    exporting = true;
    let canvas = null;
    const t0 = Date.now();
    try {
      if (DEBUG) {
        const r = stage.getBoundingClientRect();
        dbg("— экспорт —");
        dbg("iOS " + isIOS() + ", dpr " + (window.devicePixelRatio || 1) +
            ", окно " + window.innerWidth + "x" + window.innerHeight);
        dbg("UA " + navigator.userAgent.slice(0, 90));
        dbg("сцена " + Math.round(r.width) + "x" + Math.round(r.height) +
            ", масштаб " + exportScale(stage).toFixed(2) +
            ", картинок " + stage.querySelectorAll("img").length);
      }
      await document.fonts.ready.catch(() => {});

      if (stripCtl) stripCtl.freezeForExport();
      await eagerLoadStageImages();
      if (DEBUG) {
        const all = Array.from(stage.querySelectorAll("img"));
        dbg("после догрузки: " + all.filter(i => i.naturalWidth).length + "/" + all.length +
            " с пикселями, " + (Date.now() - t0) + " мс");
      }

      const broken = Array.from(stage.querySelectorAll(".cell-icon img")).filter(i => !i.naturalWidth).length;
      const scale = exportScale(stage);
      const inlined = inlineStageImages(scale);
      await nextFrames();
      const html2canvas = await loadHtml2Canvas();
      canvas = await html2canvas(stage, {
        backgroundColor: null,
        scale: scale,
        useCORS: true,
        allowTaint: false,
        logging: false,
        imageTimeout: 20000,

        onclone: (doc) => {
          const url = new URL("assets/poster/bg-tile-export.jpg", location.href).href;
          const s = doc.getElementById("stage");
          if (s) {
            s.style.backgroundImage = 'url("' + url + '")';
            s.style.backgroundColor = "#05091f";
            s.style.backgroundBlendMode = "normal";

            s.style.backgroundRepeat = "repeat-y";
            s.style.backgroundSize = "100% auto";
          }
          const p = doc.querySelector(".petals");
          if (p) {
            const pu = new URL("assets/poster/petals-tile-export.png", location.href).href;
            p.style.backgroundImage = 'url("' + pu + '")';
            p.style.backgroundRepeat = "repeat-y";
            p.style.backgroundSize = "100% auto";
            p.style.mixBlendMode = "normal";
          }

          doc.querySelectorAll(".ptn-export-hide").forEach(el => el.remove());
          const tr = doc.querySelector(".ptn-track");
          if (tr) {
            const keep = Number(tr.dataset.active) || 0;
            Array.from(tr.children).forEach((s, i) => { if (i !== keep) s.remove(); });
            if (tr.parentElement) tr.parentElement.style.overflow = "visible";
          }

          let swapped = 0, kept = 0;
          doc.querySelectorAll("img").forEach(im => {
            const d = inlined.get(im.currentSrc || im.src) || inlined.get(im.src);
            if (d) { im.src = d; swapped++; } else { kept++; }
            im.loading = "eager";
            im.decoding = "sync";
          });
          dbg("в клоне подменено " + swapped + ", осталось по ссылке " + kept);
        },
      });
      inlined.clear();
      if (DEBUG) {
        dbg("холст " + canvas.width + "x" + canvas.height +
            " (" + (canvas.width * canvas.height / 1e6).toFixed(1) + " Мп), " +
            (Date.now() - t0) + " мс");
        dbgProbeIcons(canvas, exportScale(stage));
      }
      const blob = await canvasToBlob(canvas);
      dbg("blob " + (blob.size / 1048576).toFixed(2) + " МБ, " + blob.type +
          ", итого " + (Date.now() - t0) + " мс");

      canvas.width = canvas.height = 0;
      canvas = null;
      if (broken) {
        alert(tx("msg.iconsMissing") + broken + tx("msg.iconsMissingTail"));
      }
      if (isIOS()) {
        readyBlob = blob;
        btnPng.textContent = tx("png.save");
        btnPng.title = tx("png.readyTitle");
        btnPng.disabled = false;
        btnPng.classList.add("png-ready");
        savedHint.textContent = tx("png.readyHint");
        savedHint.dataset.pngHint = "1";
        readyTimer = setTimeout(resetPngButton, 120000);
        return;
      }
      saveBlob(blob);
    } catch (err) {
      dbg("ОШИБКА " + (err && err.name) + ": " + (err && err.message));
      alert(
        tx("msg.pngSaveFailed") + "\n" +
        (location.protocol === "file:" ? tx("msg.pngFileHint") : err.message)
      );
      console.error(err);
    } finally {
      if (canvas) canvas.width = canvas.height = 0;
      exporting = false;
      if (stripCtl) stripCtl.unfreeze();
      editToggle.checked = wasEditing;
      applyEditMode();
      if (!readyBlob) { btnPng.textContent = PNG_LABEL; btnPng.disabled = false; }
      if (pendingReflow) { pendingReflow = false; reflowUntilStable(); }
    }
  });

  const LIKED_KEY = "nexus-liked";
  let hasLiked = false;
  let likeCount = 0;
  try { hasLiked = localStorage.getItem(LIKED_KEY) === "1"; } catch (e) {}

  const likeBtn = $("#likeBtn");
  const likeCountEl = $("#likeCount");

  function renderLike() {
    if (!likeBtn) return;
    likeBtn.classList.toggle("liked", hasLiked);
    likeBtn.setAttribute("aria-pressed", hasLiked ? "true" : "false");
    likeBtn.title = hasLiked ? tx("like.remove") : tx("like.title");
    const heart = likeBtn.querySelector(".like-heart");
    if (heart) heart.textContent = hasLiked ? "💙" : "🤍";
    if (likeCountEl) likeCountEl.textContent = likeCount.toLocaleString("ru-RU");
  }

  function setLiked(v) {
    hasLiked = v;
    try { localStorage.setItem(LIKED_KEY, v ? "1" : "0"); } catch (e) {}
    renderLike();
  }

  function popLike() {
    if (!likeBtn) return;
    likeBtn.classList.remove("pop");
    void likeBtn.offsetWidth;
    likeBtn.classList.add("pop");
  }

  async function sendLike(dir) {
    try {
      const r = await fetch(API_LIKE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ dir }),
      });
      return r.ok ? true : false;
    } catch (e) {}
    return null;
  }

  function toggleLike() {
    const willLike = !hasLiked;
    const dir = willLike ? 1 : -1;

    setLiked(willLike);
    popLike();
    likeCount = Math.max(0, likeCount + dir);
    renderLike();

    sendLike(dir).then(ok => {
      if (ok === false) {
        setLiked(!willLike);
        likeCount = Math.max(0, likeCount - dir);
        renderLike();
      }
    });
  }

  if (likeBtn) likeBtn.addEventListener("click", toggleLike);
  renderLike();

  const donateModal = $("#donateModal");

  function renderDonate() {
    const dn = state.donate || {};
    const linkDA  = $("#donateLinkDA");
    const linkHub = $("#donateLinkHub");
    const qr      = $("#donateQr");
    if (linkDA)  linkDA.href  = normalizeHref(dn.da,  DONATE_DA)  || DONATE_DA;
    if (linkHub) linkHub.href = normalizeHref(dn.hub, DONATE_HUB) || DONATE_HUB;
    if (qr)      qr.src       = dn.qr  || DONATE_QR;
  }

  (function initDonate() {
    const donateBtn = $("#donateBtn");
    if (!donateBtn || !donateModal) return;

    const open  = () => { donateModal.hidden = false; };
    const close = () => { donateModal.hidden = true; };

    donateBtn.hidden = false;
    donateBtn.addEventListener("click", open);
    $("#donateClose").addEventListener("click", close);
    donateModal.addEventListener("click", e => { if (e.target === donateModal) close(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape" && !donateModal.hidden) close(); });

    donateModal.addEventListener("click", e => {
      const a = e.target.closest(".dmodal-link");
      if (a && stage.classList.contains("editing")) e.preventDefault();
    });

    const editLink = (key, label) => {
      const dn = state.donate || (state.donate = {});
      const v = prompt(label, dn[key] || "");
      if (v === null) return;
      dn[key] = normalizeHref(v, "");
      save(); render();
    };
    const btnDA  = $("#donateEditDA");
    const btnHub = $("#donateEditHub");
    if (btnDA)  btnDA.addEventListener("click", () => editLink("da", tx("donate.promptDA")));
    if (btnHub) btnHub.addEventListener("click", () => editLink("hub", tx("donate.promptHub")));

    const qrFile = $("#donateQrFile");
    if (qrFile) qrFile.addEventListener("change", e => {
      const file = e.target.files[0];
      if (!file) return;
      fileToSmallDataURL(file, 640, 0.92).then(du => uploadDataUrl(du)).then(url => {
        if (!url) return;
        (state.donate || (state.donate = {})).qr = url;
        save(); render();
      });
      e.target.value = "";
    });
    const qrReset = $("#donateQrReset");
    if (qrReset) qrReset.addEventListener("click", () => {
      (state.donate || (state.donate = {})).qr = DONATE_QR;
      save(); render();
    });
  })();

  (function initFabAutoHide() {
    const THRESHOLD = 8;
    const TOP_ZONE = 90;
    const BOTTOM_ZONE = 60;
    let lastY = window.pageYOffset || 0;
    let hidden = false;
    let ticking = false;

    function setHidden(v) {
      if (v === hidden) return;
      hidden = v;
      document.body.classList.toggle("fabs-hidden", v);
    }
    function onScroll() {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(() => {
        ticking = false;
        const y = Math.max(0, window.pageYOffset || document.documentElement.scrollTop || 0);
        const dy = y - lastY;
        if (Math.abs(dy) < THRESHOLD) return;
        lastY = y;
        const doc = document.documentElement;
        const atBottom = y + window.innerHeight >= doc.scrollHeight - BOTTOM_ZONE;
        if (y < TOP_ZONE) { setHidden(false); return; }

        if (atBottom) { setHidden(true); return; }
        setHidden(dy > 0);
      });
    }
    window.addEventListener("scroll", onScroll, { passive: true });

    window.addEventListener("orientationchange", () => { lastY = window.pageYOffset || 0; setHidden(false); });
  })();

  function setAdminMode(admin) {
    isAdmin = admin;
    const tbEdit    = $("#tbEdit");
    const tbToggles = $("#tbToggles");
    const tbActions = $("#tbAdminActions");
    const tbPublish = $("#tbPublish");
    const tbPng     = $("#tbPng");

    if (admin) {
      if (tbEdit)    tbEdit.hidden    = false;
      if (tbToggles) tbToggles.hidden = false;
      if (tbActions) tbActions.hidden = false;
      if (tbPublish) tbPublish.hidden = false;
      if (tbPng)     tbPng.hidden     = false;
      renderSaveBtn();
    } else {
      if (tbEdit)    tbEdit.hidden    = true;
      if (tbToggles) tbToggles.hidden = true;
      if (tbActions) tbActions.hidden = true;
      if (tbPublish) tbPublish.hidden = true;
      if (tbPng)     tbPng.hidden     = true;
      editToggle.checked = false;
      applyEditMode();
    }
    applyProtection();
    roleResolved = true;
    resolvePending();
  }

  function mergeServer(data) {
    const d = defaultState();
    const merged = Object.assign({}, d, data);
    merged.ad      = Object.assign({}, d.ad,      data.ad      || {});
    merged.donate  = Object.assign({}, d.donate,  data.donate  || {});
    merged.filters = normalizeFilters(data.filters, d.filters);
    merged.filters.perms = false;
    if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
    if (!Array.isArray(merged.footer)  || !merged.footer.length)  merged.footer  = d.footer;
    normalizeTierLogos(merged.tiers, true);
    return merged;
  }
  function applyServer(s) {
    state = s;
    dateEl.textContent     = state.date;
    autoSortToggle.checked = state.autoSort;
    render();
  }
  function sameState(a, b) {
    try { return JSON.stringify(a) === JSON.stringify(b); } catch (e) { return false; }
  }

  function resolvePending() {
    if (deferredServer === null) return;
    const srv = deferredServer; deferredServer = null;
    if (isAdmin) {
      dirty = true; try { localStorage.setItem(DIRTY_KEY, "1"); } catch (e) {} renderSaveBtn();
      savedHint.textContent = tx("msg.restored");

      pendingServer = srv; showUpdateBanner();
    } else {
      applyServer(srv);
    }
  }

  const API_TIERLIST = "/api/tierlist.php";
  const API_STATE    = "/api/state.php";
  const API_LIKE     = "/api/like.php";
  const API_SAVE     = "/api/save.php";
  const API_SESSION  = "/api/session.php";
  const API_UPLOAD   = "/api/upload.php";
  const POLL_MS = 30000;
  let pollTimer = null;
  let lastRev = null;
  let haveFullData = false;

  const API_PROMO = "/api/promo.php";
  const PROMO_DOC_KEY = "nx-ptn-doc-v1";
  const PROMO_PREVIEW_KEY = "nx-ptn-preview";
  let promoDoc = promo ? promo.normalizeDoc(null) : { v: 1, rev: 0, campaigns: [] };
  let lastPromoRev = null;

  function readPromoLocal() {
    if (!promo) return null;

    try {
      if (/[?&]promo_preview=1(&|$)/.test(location.search)) {
        const draft = sessionStorage.getItem(PROMO_PREVIEW_KEY);
        if (draft) return JSON.parse(draft);
      }
    } catch (e) {}
    try {
      const raw = localStorage.getItem(PROMO_DOC_KEY);
      if (raw) return JSON.parse(raw);
    } catch (e) {}
    return null;
  }

  function applyPromoDoc(doc, cache) {
    if (!promo) return;
    promoDoc = promo.normalizeDoc(doc);
    promoOrderCache = null;
    if (cache) {
      try { localStorage.setItem(PROMO_DOC_KEY, JSON.stringify(promoDoc)); } catch (e) {}
    }
  }

  async function fetchPromo(rev) {
    const q = (rev !== null && rev !== undefined && rev !== "") ? ("?rev=" + encodeURIComponent(rev)) : "";
    try {
      const r = await fetch(API_PROMO + q, { cache: "default" });
      if (r.ok) { applyPromoDoc(await r.json(), true); return true; }
    } catch (e) {}
    return false;
  }

  function refreshPromoBlock() {
    const old = tiersEl.querySelector(".ptn-card");
    if (!old) return;
    teardownPromoStrip();
    old.replaceWith(renderPromoBlock());
    applyEditMode();
    renderPromoRails();
    renderPromoDock();
    schedulePromoPopup();
  }

  function handleSnapshot(data) {
    if (!data) return;
    const merged = mergeServer(data);

    if (!firstSnapshotHandled) {
      firstSnapshotHandled = true;
      if (bootedDirty && !sameState(merged, state)) {
        deferredServer = merged;
        if (roleResolved) resolvePending();
        return;
      }
    }
    if (deferredServer !== null) { deferredServer = merged; return; }

    if (dirty) { pendingServer = merged; showUpdateBanner(); return; }

    applyServer(merged);
  }

  async function fetchState() {
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      if (r.ok) return await r.json();
    } catch (e) {}
    return null;
  }

  async function fetchFull(rev) {
    const q = (rev !== null && rev !== undefined && rev !== "") ? ("?rev=" + encodeURIComponent(rev)) : "";
    try {
      const r = await fetch(API_TIERLIST + q, { cache: "default" });
      if (r.ok) {
        const d = await r.json();
        if (d && d.tierlist) { handleSnapshot(d.tierlist); return true; }
      }
    } catch (e) {}
    return false;
  }

  let bootRev = (typeof window.NX_REV === "number" && isFinite(window.NX_REV)) ? window.NX_REV : null;

  async function fetchSnapshot() {
    if (bootRev !== null && !haveFullData) {
      const rev = bootRev;
      bootRev = null;
      if (await fetchFull(rev)) { haveFullData = true; lastRev = rev; }
    }
    const st = await fetchState();
    if (st && typeof st.likes === "number") { likeCount = Math.max(0, st.likes); renderLike(); }

    const need = !haveFullData || (st && st.rev !== lastRev);
    if (need) {
      const ok = await fetchFull(st ? st.rev : null);
      if (ok) { haveFullData = true; if (st) lastRev = st.rev; }
    } else if (st) {
      lastRev = st.rev;
    }

    if (st && typeof st.promoRev === "number" && st.promoRev !== lastPromoRev) {
      if (await fetchPromo(st.promoRev)) {
        lastPromoRev = st.promoRev;
        refreshPromoBlock();
      }
    }
  }

  function startPolling() {
    fetchSnapshot();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(fetchSnapshot, POLL_MS);

    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") fetchSnapshot();
    });
  }

  function showUpdateBanner() {
    if (!pendingServer || document.getElementById("syncBanner")) return;
    const box = document.createElement("div");
    box.id = "syncBanner";
    box.className = "uid-banner sync-banner";
    box.innerHTML =
      '<button class="uid-banner-close" title="' + tx("modal.close") + '">✕</button>' +
      '<div class="uid-banner-title">' + tx("sync.title") + '</div>' +
      '<div class="uid-banner-sub">' + tx("sync.sub") + '</div>' +
      '<div class="uid-banner-row">' +
        '<button class="btn small primary" id="syncApply">' + tx("sync.apply") + '</button>' +
        '<button class="btn small ghost" id="syncDismiss">' + tx("sync.dismiss") + '</button>' +
      '</div>';
    document.body.appendChild(box);
    const close = () => box.remove();
    box.querySelector(".uid-banner-close").addEventListener("click", close);
    box.querySelector("#syncDismiss").addEventListener("click", close);
    box.querySelector("#syncApply").addEventListener("click", () => {
      if (pendingServer) { clearDirty(); applyServer(pendingServer); pendingServer = null; }
      close();
    });
  }

  function initBackend() {
    startPolling();
    checkSession();
  }

  async function checkSession() {
    if (!window.NX_ADMIN_PAGE) { setAdminMode(false); return; }
    try {
      const r = await fetch(API_SESSION, { cache: "no-store" });
      const d = await r.json();

      if (!d.admin) { location.reload(); return; }
      setAdminMode(true);
    } catch (e) { setAdminMode(false); }
  }

  function applyProtection() {
    NX_PROTECT.applyClass(isAdmin);
  }
  function setupProtection() {
    applyProtection();
    NX_PROTECT.install(() => isAdmin);
  }

  applyLang();
  setupProtection();

  applyPromoDoc(readPromoLocal(), false);
  render();
  if (!localStorage.getItem(STORAGE_KEY)) save();
  initBackend();
  renderPromoRails();
  renderPromoDock();

  if (railMQ) {
    const onRailMQ = () => renderPromoRails();
    if (railMQ.addEventListener) railMQ.addEventListener("change", onRailMQ);
    else if (railMQ.addListener) railMQ.addListener(onRailMQ);
  }

  window.addEventListener("scroll", queueRailTop, { passive: true });
  window.addEventListener("resize", queueRailTop);
  syncRailTop();
  if (dockMQ) {
    const onDockMQ = () => renderPromoDock();
    if (dockMQ.addEventListener) dockMQ.addEventListener("change", onDockMQ);
    else if (dockMQ.addListener) dockMQ.addListener(onDockMQ);
  }
  initPromoPopup();

  function safeRender() {
    try { render(); }
    catch (e) {
      console.error("render failed", e);
      requestAnimationFrame(() => { try { render(); } catch (_) {} });
    }
  }
  let resizeT = null;
  let lastW = window.innerWidth;
  let lastPer = itemsPerRow();

  function reflowPass() {
    if (exporting) { pendingReflow = true; return; }
    const w = window.innerWidth;
    const per = itemsPerRow();
    if (Math.abs(w - lastW) < 2 && per === lastPer) { fitValues(); return; }
    lastW = w; lastPer = per;
    safeRender();
  }

  let reflowTimers = [];
  function reflowUntilStable() {
    reflowTimers.forEach(clearTimeout);
    reflowTimers = [0, 120, 320, 700, 1200].map(ms => setTimeout(reflowPass, ms));
  }

  window.addEventListener("resize", () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(reflowPass, 150);
  });
  window.addEventListener("orientationchange", reflowUntilStable);

  if (window.screen && screen.orientation && screen.orientation.addEventListener) {
    screen.orientation.addEventListener("change", reflowUntilStable);
  }

  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", () => {
      clearTimeout(resizeT);
      resizeT = setTimeout(reflowPass, 150);
    });
  }
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(fitValues).catch(() => {});
  }

  window.addEventListener("load", fitValues);
})();
