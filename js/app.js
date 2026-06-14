/* ============================================================
   Nexus Tier List — интерактивный редактор
   Чистый JS, состояние в localStorage.
   ============================================================ */
(() => {
  "use strict";

  const STORAGE_KEY = "nexus-tierlist-v1";
  const DEFAULT_ICON = "assets/icon-sample.png";
  const TIER_LOGOS = { MK: "assets/logo-mk.png", GLH: "assets/logo-glh.png", "💧": "assets/logo-flame.png" };

  const uid = () => "id" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

  // ---------- Default template ----------
  function defaultState() {
    const mk = (name, value, type, demand, trend) => ({
      id: uid(), name, value: String(value), icon: DEFAULT_ICON, type, demand, trend,
    });
    return {
      title: "NEXUS\nTIER LIST",
      date: "17.02.2026",
      autoSort: true,
      filters: { fruits: true, perms: true, passes: true, skins: true },
      ad: { text: "МЕСТО ДЛЯ ВАШЕЙ РЕКЛАМЫ — t.me/mksvtnc", image: "" },
      credits: [
        { role: "Автор", name: "Maknemy" },
        { role: "Дизайнер", name: "Maknemy" },
        { role: "Аналитик", name: "—" },
        { role: "Помощник аналитика", name: "—" },
        { role: "Кодер сайта", name: "—" },
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

  // ---------- State ----------
  let state = load() || defaultState();
  let isAdmin = false;
  let fbRef = null;
  let dirty = false;   // есть несохранённые правки админа
  let saving = false;  // идёт публикация в Firebase

  function load() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return null;
      const data = JSON.parse(raw);
      if (!data || !data.tiers) return null;
      // merge with defaults so old saves get the new fields
      const d = defaultState();
      const merged = Object.assign({}, d, data);
      merged.ad = Object.assign({}, d.ad, data.ad || {});
      merged.filters = Object.assign({}, d.filters, data.filters || {});
      if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
      if (typeof merged.autoSort !== "boolean") merged.autoSort = true;
      // old saves: give default tiers their logos back
      merged.tiers.forEach(t => {
        if (t.logo === undefined && TIER_LOGOS[t.label]) t.logo = TIER_LOGOS[t.label];
      });
      return merged;
    } catch (e) { return null; }
  }
  let saveTimer = null;
  // Локальное сохранение (резервная копия). В общую базу изменения НЕ уходят
  // автоматически — только по кнопке «💾 Сохранить» (publish()).
  function save() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(() => {
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e) {}
    }, 400);
    if (isAdmin) { dirty = true; renderSaveBtn(); }
  }

  // Публикация текущего состояния в Firebase — вызывается кнопкой «Сохранить»
  function publish() {
    if (!isAdmin || !dirty || saving) return;
    if (!fbRef) { dirty = false; renderSaveBtn(); flashSaved(); return; } // локальный режим
    saving = true; renderSaveBtn();
    fbRef.set(state)
      .then(() => { saving = false; dirty = false; renderSaveBtn(); flashSaved(); })
      .catch(() => { saving = false; renderSaveBtn(); savedHint.textContent = "⚠ Ошибка сохранения Firebase"; });
  }

  // Внешний вид кнопки «Сохранить» по текущему состоянию
  function renderSaveBtn() {
    if (!btnSave) return;
    btnSave.classList.remove("clean", "dirty", "saving");
    if (saving)     { btnSave.textContent = "⏳ Сохранение…"; btnSave.classList.add("saving"); }
    else if (dirty) { btnSave.textContent = "💾 Сохранить";  btnSave.classList.add("dirty"); }
    else            { btnSave.textContent = "✓ Сохранено";   btnSave.classList.add("clean"); }
  }
  function flashSaved() {
    savedHint.textContent = "✓ Сохранено";
    clearTimeout(flashSaved._t);
    flashSaved._t = setTimeout(() => (savedHint.textContent = ""), 1200);
  }

  // ---------- DOM refs ----------
  const $ = (s, r = document) => r.querySelector(s);
  const stage = $("#stage");
  const tiersEl = $("#tiers");
  const savedHint = $("#savedHint");
  const btnSave = $("#btnSave");
  const editToggle = $("#editToggle");
  const autoSortToggle = $("#autoSortToggle");
  const creditsEl = $("#credits");

  // ---------- Helpers ----------
  function findTier(tid) { return state.tiers.find(t => t.id === tid); }
  function findItem(iid) {
    for (const t of state.tiers) {
      const it = t.items.find(i => i.id === iid);
      if (it) return { tier: t, item: it };
    }
    return null;
  }
  // "60 000", "60к", "60,5" → число; нечисловое → NaN
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
  // Фрукты (f/m/cr/пусто) · Пермы (p) · Пассы (gp) · Скины (s)
  function groupOf(type) {
    if (type === "p") return "perms";
    if (type === "gp") return "passes";
    if (type === "s") return "skins";
    return "fruits";
  }

  // ============================================================
  //  AUTO SORT (по убыванию цены)
  // ============================================================
  // Ставит предмет на место согласно его цене: сканируем тиры сверху
  // вниз и вставляем перед первым предметом с меньшей ценой.
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

  // Полная сортировка: внутри каждого тира по убыванию (без цены — в конец)
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

  // ============================================================
  //  RENDER
  // ============================================================
  function render() {
    tiersEl.innerHTML = "";
    const adAfter = Math.ceil(state.tiers.length / 2) - 1; // середина тирлиста
    state.tiers.forEach((tier, ti) => {
      tiersEl.appendChild(renderTier(tier, ti));
      if (ti === adAfter) tiersEl.appendChild(renderAd());
    });
    if (!state.tiers.length) tiersEl.appendChild(renderAd());
    renderCredits();
    applyFilters();
    applyEditMode();
    fitValues();
  }

  // Ужимаем значение по ШРИФТУ (а не transform: scale), чтобы строка
  // перекомпоновывалась и цифры с бейджем оставались по центру — без
  // съезжания/наложения. Размер берём из CSS (cqw) и при необходимости
  // уменьшаем в px; на ресайзе/смене шрифта пересчитывается заново.
  function fitValues() {
    requestAnimationFrame(() => {
      tiersEl.querySelectorAll(".cell-strip").forEach(strip => {
        const val = strip.querySelector(".cell-value");
        if (!val) return;
        val.style.fontSize = "";                 // сброс к базовому размеру из CSS
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

  // Сколько ячеек помещается в один ряд при текущей ширине сцены.
  // cqw = 1% inline-размера .stage (container-type: inline-size).
  function itemsPerRow() {
    const cs = getComputedStyle(stage);
    const contentW = stage.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    if (!contentW || contentW < 60) return 0; // сцена ещё не разложена
    const cqw = contentW / 100;
    const cellW   = 8.2 * cqw;
    const colGap  = 0.55 * cqw;
    const panelPad = 0.8 * cqw;            // .tier-items padding слева+справа
    const innerW  = contentW - 2 * panelPad - 2; // -2 на рамку панели
    const per = Math.floor((innerW + colGap) / (cellW + colGap) + 1e-6);
    return Math.max(1, per);
  }

  // Плашка-заголовок тира. Инструменты/редактирование — только у первой
  // плашки (isFirst); у плашек-продолжений логотип/название повторяются,
  // но без кнопок и без contenteditable.
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
        label.addEventListener("blur", () => { tier.label = label.textContent.trim(); save(); });
        label.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); label.blur(); } });
      }
      band.appendChild(label);
    }

    if (isFirst) {
      const tools = document.createElement("div");
      tools.className = "tier-tools edit-only";
      tools.appendChild(toolBtn("🖼", "Загрузить логотип тира", () => pickTierLogo(tier.id)));
      if (tier.logo) tools.appendChild(toolBtn("Т", "Убрать логотип (показывать текст)", () => { tier.logo = ""; save(); render(); }));
      tools.appendChild(toolBtn("▲", "Выше", () => moveTier(ti, -1)));
      tools.appendChild(toolBtn("▼", "Ниже", () => moveTier(ti, +1)));
      tools.appendChild(toolBtn("✕", "Удалить тир", () => deleteTier(tier.id)));
      band.appendChild(tools);
    }
    return band;
  }

  function renderTier(tier, ti) {
    const sec = document.createElement("section");
    sec.className = "tier";
    sec.dataset.id = tier.id;

    // Разбиваем предметы тира на ряды: каждый ряд — отдельная плашка-тир со
    // своей шапкой и небольшим отступом, чтобы тир «не удваивался» в одной
    // полосе при переполнении. Данные тира не меняем — это только верстка.
    const per = itemsPerRow();
    const chunks = [];
    if (per > 0 && tier.items.length > per) {
      for (let i = 0; i < tier.items.length; i += per) chunks.push(tier.items.slice(i, i + per));
    } else {
      chunks.push(tier.items.slice());
    }
    if (!chunks.length) chunks.push([]); // пустой тир — одна плашка

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
        add.title = "Добавить предмет";
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

    // demand dot — справа от иконки
    if (item.demand) {
      const d = document.createElement("img");
      d.className = "dot";
      d.src = "assets/dot-" + item.demand + ".png";
      d.alt = "";
      cell.appendChild(d);
    }
    // trend — слева от иконки
    if (item.trend) {
      const tr = document.createElement("img");
      tr.className = "trend" + (item.trend === "swap" ? " tr-swap" : "");
      tr.src = "assets/trend-" + item.trend + ".png";
      tr.alt = "";
      cell.appendChild(tr);
    }

    const iconWrap = document.createElement("div");
    iconWrap.className = "cell-icon";
    const img = document.createElement("img");
    img.src = item.icon || DEFAULT_ICON;
    img.alt = item.name || "";
    img.onerror = () => { img.src = DEFAULT_ICON; };
    iconWrap.appendChild(img);
    cell.appendChild(iconWrap);

    // тёмная полоса с ценой и бейджем типа (как в макете)
    const strip = document.createElement("div");
    strip.className = "cell-strip";
    const val = document.createElement("span");
    val.className = "cell-value";
    val.textContent = item.value || "";
    strip.appendChild(val);
    if (item.type) {
      const b = document.createElement("img");
      b.className = "tbadge";
      b.src = "assets/badge-" + item.type + ".png";
      b.alt = item.type.toUpperCase();
      strip.appendChild(b);
    }
    cell.appendChild(strip);

    // edit controls
    const edit = document.createElement("div");
    edit.className = "cell-edit";
    edit.appendChild(miniBtn("✎", "Изменить", e => { e.stopPropagation(); openModal(item.id); }));
    edit.appendChild(miniBtn("✕", "Удалить", e => { e.stopPropagation(); deleteItem(item.id); }));
    cell.appendChild(edit);

    // double click / click in edit mode → modal
    cell.addEventListener("dblclick", () => openModal(item.id));
    cell.addEventListener("click", () => { if (stage.classList.contains("editing")) openModal(item.id); });

    setupDraggable(cell, item, tier);
    return cell;
  }

  function miniBtn(txt, title, fn) {
    const b = document.createElement("button");
    b.textContent = txt; b.title = title;
    b.addEventListener("click", fn);
    return b;
  }

  // ============================================================
  //  AD BLOCK (реклама в середине тирлиста)
  // ============================================================
  function renderAd() {
    const ad = document.createElement("section");
    ad.className = "ad-block";

    const chip = document.createElement("span");
    chip.className = "ad-chip";
    chip.textContent = "РЕКЛАМА";
    ad.appendChild(chip);

    if (state.ad.image) {
      const img = document.createElement("img");
      img.className = "ad-img";
      img.src = state.ad.image;
      img.alt = "Реклама";
      ad.appendChild(img);
    }

    const txt = document.createElement("div");
    txt.className = "ad-text";
    txt.textContent = state.ad.text || "";
    txt.spellcheck = false;
    txt.addEventListener("blur", () => { state.ad.text = txt.textContent.trim(); save(); });
    txt.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); txt.blur(); } });
    ad.appendChild(txt);

    const tools = document.createElement("div");
    tools.className = "ad-tools edit-only";
    tools.appendChild(toolBtn("🖼 Баннер", "Загрузить картинку рекламы", () => $("#adImgFile").click()));
    if (state.ad.image) tools.appendChild(toolBtn("Т Текст", "Убрать картинку", () => { state.ad.image = ""; save(); render(); }));
    ad.appendChild(tools);
    return ad;
  }

  // ============================================================
  //  CREDITS (команда тирлиста)
  // ============================================================
  function renderCredits() {
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
      del.title = "Убрать из списка";
      del.addEventListener("click", () => { state.credits.splice(idx, 1); save(); render(); });

      el.appendChild(role); el.appendChild(name); el.appendChild(del);
      creditsEl.appendChild(el);
    });

    const add = document.createElement("button");
    add.className = "credit-add edit-only";
    add.textContent = "＋";
    add.title = "Добавить участника";
    add.addEventListener("click", () => { state.credits.push({ role: "Роль", name: "Имя" }); save(); render(); });
    creditsEl.appendChild(add);
  }

  // ============================================================
  //  DRAG & DROP
  // ============================================================
  let dragData = null; // { itemId, fromTierId }

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
    // remove from source
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

  // ============================================================
  //  MUTATIONS
  // ============================================================
  function addTier() {
    state.tiers.push({ id: uid(), label: "Новый тир", logo: "", items: [] });
    save(); render();
  }
  function deleteTier(tid) {
    const t = findTier(tid);
    if (!t) return;
    if (t.items.length && !confirm(`Удалить тир «${t.label}» вместе с ${t.items.length} предметами?`)) return;
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
    const item = { id: uid(), name: "Item", value: "0", icon: DEFAULT_ICON, type: "f", demand: "", trend: "" };
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

  // ---------- tier logo upload ----------
  let tierLogoTarget = null;
  function pickTierLogo(tid) {
    tierLogoTarget = tid;
    $("#tierLogoFile").click();
  }
  $("#tierLogoFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file || !tierLogoTarget) return;
    const reader = new FileReader();
    reader.onload = () => {
      const t = findTier(tierLogoTarget);
      if (t) { t.logo = reader.result; save(); render(); }
      tierLogoTarget = null;
    };
    reader.readAsDataURL(file);
    e.target.value = "";
  });

  // ---------- ad image upload ----------
  $("#adImgFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => { state.ad.image = reader.result; save(); render(); };
    reader.readAsDataURL(file);
    e.target.value = "";
  });

  // ============================================================
  //  FILTERS (Фрукты / Пермы / Пассы / Скины)
  // ============================================================
  const filtersEl = $("#filters");
  function applyFilters() {
    const f = state.filters;
    ["fruits", "perms", "passes", "skins"].forEach(key => {
      stage.classList.toggle("hide-" + key, !f[key]);
      const chip = filtersEl.querySelector(`.chip[data-f="${key}"]`);
      if (chip) chip.classList.toggle("active", !!f[key]);
    });
    const all = filtersEl.querySelector('.chip[data-f="all"]');
    all.classList.toggle("active", f.fruits && f.perms && f.passes && f.skins);
  }
  filtersEl.addEventListener("click", e => {
    const chip = e.target.closest(".chip");
    if (!chip) return;
    const key = chip.dataset.f;
    if (key === "all") {
      state.filters = { fruits: true, perms: true, passes: true, skins: true };
    } else {
      state.filters[key] = !state.filters[key];
      // нельзя выключить всё разом — хотя бы одна категория остаётся
      if (!state.filters.fruits && !state.filters.perms && !state.filters.passes && !state.filters.skins) {
        state.filters[key] = true;
        return;
      }
    }
    save();
    applyFilters();
    fitValues();
  });

  // ============================================================
  //  ITEM MODAL
  // ============================================================
  const modal = $("#modal");
  let editingId = null;

  function openModal(iid) {
    if (!isAdmin) return; // редактировать может только администратор
    const found = findItem(iid);
    if (!found) return;
    editingId = iid;
    const it = found.item;
    $("#mName").value = it.name || "";
    $("#mValue").value = it.value || "";
    $("#mIconPreview").src = it.icon || DEFAULT_ICON;
    setType(it.type || "f");
    setSeg("#mDemand", it.demand || "");
    setSeg("#mTrend", it.trend || "");
    modal.hidden = false;
    setTimeout(() => $("#mName").focus(), 30);
  }
  function closeModal() { modal.hidden = true; editingId = null; }

  function setSeg(sel, value) {
    $(sel).querySelectorAll("button").forEach(b => {
      b.classList.toggle("active", (b.dataset.v || "") === value);
    });
  }
  function getSeg(sel) {
    const a = $(sel).querySelector("button.active");
    return a ? (a.dataset.v || "") : "";
  }

  // ----- Type: Regular/Permanent toggle (#mFruit) + optional category (#mType2) -----
  const CATEGORIES = ["s", "m", "gp", "cr"];
  function setType(type) {
    const isCat = CATEGORIES.includes(type);
    $("#mFruit").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    if (!isCat) {
      const v = type === "p" ? "p" : "f"; // пусто/обычный → Обычный (F) по умолчанию
      $("#mFruit").querySelector(`button[data-v="${v}"]`).classList.add("active");
    }
    setSeg("#mType2", isCat ? type : "");
  }
  function getType() {
    const cat = getSeg("#mType2");
    if (cat) return cat;
    const fr = $("#mFruit").querySelector("button.active");
    return fr ? fr.dataset.v : "";
  }
  // wire fruit toggle: choosing a fruit clears the category
  $("#mFruit").addEventListener("click", e => {
    const btn = e.target.closest("button");
    if (!btn) return;
    $("#mFruit").querySelectorAll("button").forEach(b => b.classList.remove("active"));
    btn.classList.add("active");
    setSeg("#mType2", "");
  });
  // wire category: a real category overrides the fruit toggle; "—" restores Обычный
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
  // wire simple segmented controls
  ["#mDemand", "#mTrend"].forEach(sel => {
    $(sel).addEventListener("click", e => {
      const btn = e.target.closest("button");
      if (!btn) return;
      $(sel).querySelectorAll("button").forEach(b => b.classList.remove("active"));
      btn.classList.add("active");
    });
  });

  // icon upload
  $("#mIconFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => { $("#mIconPreview").src = reader.result; };
    reader.readAsDataURL(file);
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
    it.icon = $("#mIconPreview").src;
    it.type = getType();
    it.demand = getSeg("#mDemand");
    it.trend = getSeg("#mTrend");
    // автоперемещение по цене
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
    if (e.key === "Escape" && !modal.hidden) closeModal();
  });

  // ============================================================
  //  HEADER / DATE editable
  // ============================================================
  const dateEl = $("#tlDate");
  dateEl.textContent = state.date;
  dateEl.addEventListener("blur", () => { state.date = dateEl.textContent.trim(); save(); });
  dateEl.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); dateEl.blur(); } });

  // ============================================================
  //  EDIT MODE
  // ============================================================
  function applyEditMode() {
    const on = editToggle.checked;
    stage.classList.toggle("editing", on);
    document.querySelectorAll(".edit-only").forEach(el => { el.style.display = on ? "" : "none"; });
    // contenteditable only in edit mode (плашки-продолжения .cont-label не редактируются)
    document.querySelectorAll(".tier-label:not(.cont-label), #tlDate, .ad-text, .cr-role, .cr-name").forEach(el => {
      el.contentEditable = on ? "true" : "false";
    });
  }
  editToggle.addEventListener("change", applyEditMode);

  autoSortToggle.checked = state.autoSort;
  autoSortToggle.addEventListener("change", () => {
    state.autoSort = autoSortToggle.checked;
    save();
  });

  // ============================================================
  //  TOOLBAR ACTIONS
  // ============================================================
  $("#btnAddTier").addEventListener("click", addTier);
  $("#btnAddItem").addEventListener("click", () => {
    if (!state.tiers.length) addTier();
    addItem(state.tiers[0].id);
  });
  $("#btnSort").addEventListener("click", sortAllTiers);
  if (btnSave) btnSave.addEventListener("click", publish);
  // Предупреждать о несохранённых изменениях при закрытии / перезагрузке
  window.addEventListener("beforeunload", e => {
    if (isAdmin && dirty) { e.preventDefault(); e.returnValue = ""; }
  });
  $("#btnReset").addEventListener("click", () => {
    if (confirm("Сбросить тирлист к стандартному шаблону? Текущие данные будут потеряны.")) {
      state = defaultState();
      dateEl.textContent = state.date;
      autoSortToggle.checked = state.autoSort;
      save(); render();
    }
  });

  // ----- Export / Import JSON -----
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
        if (!data.tiers) throw new Error("нет поля tiers");
        const d = defaultState();
        state = Object.assign({}, d, data);
        state.ad = Object.assign({}, d.ad, data.ad || {});
        state.filters = Object.assign({}, d.filters, data.filters || {});
        if (!Array.isArray(state.credits) || !state.credits.length) state.credits = d.credits;
        dateEl.textContent = state.date || "";
        autoSortToggle.checked = state.autoSort;
        save(); render();
      } catch (err) {
        alert("Не удалось прочитать файл: " + err.message);
      }
    };
    reader.readAsText(file);
    e.target.value = "";
  });

  // ----- Download PNG -----
  $("#btnPng").addEventListener("click", async () => {
    const wasEditing = editToggle.checked;
    editToggle.checked = false;
    applyEditMode();
    const btn = $("#btnPng");
    const prev = btn.textContent;
    btn.textContent = "Рендер…";
    btn.disabled = true;
    // wait a frame for layout/fonts
    await document.fonts.ready.catch(() => {});
    await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
    try {
      const canvas = await html2canvas(stage, {
        backgroundColor: null,
        scale: 2,
        useCORS: true,
        allowTaint: false,
        logging: false,
        // html2canvas не умеет CSS blend-mode, поэтому фон выходил «цветным».
        // В клоне для экспорта подменяем фон на заранее посчитанную синюю
        // версию (bg.png × #091640) и убираем blend-mode у лепестков.
        onclone: (doc) => {
          const url = new URL("assets/bg-export.png", location.href).href;
          const s = doc.getElementById("stage");
          if (s) {
            s.style.backgroundImage = 'url("' + url + '")';
            s.style.backgroundColor = "#091640";
            s.style.backgroundBlendMode = "normal";
          }
          const p = doc.querySelector(".petals");
          if (p) p.style.mixBlendMode = "normal";
        },
      });
      const url = canvas.toDataURL("image/png");
      const a = document.createElement("a");
      a.href = url;
      a.download = "nexus-tier-list.png";
      a.click();
    } catch (err) {
      alert(
        "Не удалось сохранить PNG.\n" +
        (location.protocol === "file:"
          ? "Откройте сайт через локальный сервер (например: python -m http.server), а не файлом — браузер блокирует экспорт картинок с file://."
          : err.message)
      );
      console.error(err);
    } finally {
      btn.textContent = prev;
      btn.disabled = false;
      editToggle.checked = wasEditing;
      applyEditMode();
    }
  });

  // ============================================================
  //  FIREBASE — авторизация и синхронизация
  // ============================================================
  function setAdminMode(admin) {
    isAdmin = admin;
    const loginBtn  = $("#btnLogin");
    const badge     = $("#adminBadge");
    const tbEdit    = $("#tbEdit");
    const tbToggles = $("#tbToggles");
    const tbActions = $("#tbAdminActions");
    const tbPublish = $("#tbPublish");

    if (admin) {
      if (loginBtn)  loginBtn.hidden  = true;
      if (badge)     badge.hidden     = false;
      if (tbEdit)    tbEdit.hidden    = false;
      if (tbToggles) tbToggles.hidden = false;
      if (tbActions) tbActions.hidden = false;
      if (tbPublish) tbPublish.hidden = false;
      renderSaveBtn();
      // Если в Firebase ещё нет данных — публикуем текущее состояние
      if (fbRef) fbRef.once("value", snap => { if (!snap.val()) fbRef.set(state); });
    } else {
      if (loginBtn)  loginBtn.hidden  = false;
      if (badge)     badge.hidden     = true;
      if (tbEdit)    tbEdit.hidden    = true;
      if (tbToggles) tbToggles.hidden = true;
      if (tbActions) tbActions.hidden = true;
      if (tbPublish) tbPublish.hidden = true;
      editToggle.checked = false;
      applyEditMode();
    }
  }

  function initFirebase() {
    const configured =
      typeof FIREBASE_CONFIG !== "undefined" &&
      FIREBASE_CONFIG.apiKey &&
      FIREBASE_CONFIG.apiKey !== "ВСТАВЬ_СЮДА";

    if (!configured) {
      // Firebase не настроен — работаем локально, редактирование открыто
      setAdminMode(true);
      return;
    }

    try {
      firebase.initializeApp(FIREBASE_CONFIG);
      const auth = firebase.auth();
      fbRef = firebase.database().ref("tierlist");

      // Слушаем обновления — все клиенты получают новые данные в реальном времени
      fbRef.on("value", snapshot => {
        const data = snapshot.val();
        if (!data) return;
        if (dirty) return; // у админа есть неопубликованные правки — не затираем их
        const d = defaultState();
        const merged = Object.assign({}, d, data);
        merged.ad      = Object.assign({}, d.ad,      data.ad      || {});
        merged.filters = Object.assign({}, d.filters, data.filters || {});
        if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
        merged.tiers.forEach(t => {
          if (!t.logo && TIER_LOGOS[t.label]) t.logo = TIER_LOGOS[t.label];
        });
        state = merged;
        dateEl.textContent        = state.date;
        autoSortToggle.checked    = state.autoSort;
        render();
      });

      // Показать UID прямо на странице (надёжнее alert/prompt, которые
      // браузер может молча блокировать) — с кнопкой «Копировать».
      function showUid(uid) {
        console.log("Ваш UID:", uid);
        const old = document.getElementById("uidBanner");
        if (old) old.remove();

        const box = document.createElement("div");
        box.id = "uidBanner";
        box.className = "uid-banner";
        box.innerHTML =
          '<button class="uid-banner-close" title="Закрыть">✕</button>' +
          '<div class="uid-banner-title">Вы пока не администратор</div>' +
          '<div class="uid-banner-sub">Скопируйте свой UID и отправьте владельцу сайта, чтобы вас добавили:</div>' +
          '<div class="uid-banner-row">' +
            '<input class="uid-banner-input" readonly />' +
            '<button class="btn primary uid-banner-copy" type="button">Копировать</button>' +
          '</div>';
        document.body.appendChild(box);

        const input = box.querySelector(".uid-banner-input");
        input.value = uid;            // значение через свойство, не через innerHTML
        input.focus();
        input.select();

        box.querySelector(".uid-banner-copy").addEventListener("click", () => {
          input.focus(); input.select();
          let ok = false;
          try { ok = document.execCommand("copy"); } catch (e) {}
          try { if (navigator.clipboard) navigator.clipboard.writeText(uid); } catch (e) {}
          box.querySelector(".uid-banner-copy").textContent = ok ? "Скопировано ✓" : "Выделено — Ctrl+C";
        });
        box.querySelector(".uid-banner-close").addEventListener("click", () => box.remove());
      }

      // Следим за состоянием авторизации
      auth.onAuthStateChanged(user => {
        if (!user) { setAdminMode(false); return; }

        const uids = typeof ADMIN_UIDS !== "undefined" ? ADMIN_UIDS : [];
        if (!uids.length || !uids.includes(user.uid)) {
          // Не в списке админов — показываем UID и выходим
          showUid(user.uid);
          auth.signOut();
          return;
        }

        setAdminMode(true);
      });

      $("#btnLogin").addEventListener("click", () => {
        auth.signInWithPopup(new firebase.auth.GoogleAuthProvider())
          .catch(err => {
            if (err.code !== "auth/popup-closed-by-user") {
              alert("Ошибка входа: " + err.message);
            }
          });
      });

      $("#btnLogout").addEventListener("click", () => auth.signOut());

    } catch(e) {
      console.error("Firebase init error:", e);
      setAdminMode(true); // fallback: открываем локальный режим
    }
  }

  // ============================================================
  //  INIT
  // ============================================================
  render();
  if (!localStorage.getItem(STORAGE_KEY)) save(); // persist seed on first run
  initFirebase();
  // при смене ширины пересчитываем разбивку на ряды (itemsPerRow) и подгон цифр
  let resizeT = null;
  window.addEventListener("resize", () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(render, 150);
  });
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(fitValues).catch(() => {});
  }
  // после загрузки картинок-бейджей ширина полосы могла измениться
  window.addEventListener("load", fitValues);
})();
