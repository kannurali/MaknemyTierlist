/* ============================================================
   Страница калькулятора трейдов. Вся арифметика (парсинг значений,
   суммы, вердикт, кодирование ссылки) живёт в js/calc.js — этот файл
   только DOM: слоты, каталог предметов, шкалы, вердикт, рекламные борта.
   ============================================================ */
(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const sideRoot = side => document.querySelector('.tc-side[data-side="' + side + '"]');

  // Тот же ключ, что и на тирлисте/ленте новостей (app.js/news-page.js,
  // LANG_KEY) — иначе выбор языка не переносился бы между страницами.
  const LANG_KEY = "nexus-lang-v1";

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = (key, vars) => I18N.t(key, lang, vars);

  // Числа показываем разделителем разрядов того языка, что выбран сейчас —
  // калькулятор двуязычный (в отличие от тирлиста, где toLocaleString всегда
  // "ru-RU", потому что сам тирлист на английский не переводится).
  function fmtNum(n) {
    return Math.round(n).toLocaleString(lang === "en" ? "en-US" : "ru-RU");
  }

  // ------------------------------------------------------------------------
  //  Состояние: две стороны сделки. Формат — ровно тот, что понимает calc.js:
  //  [{item, count}], без дублей по id (дубль показывается счётчиком).
  //  Слот доски — это строка entries, а не единица count: одна и та же
  //  сторона не может занять больше CALC.MAX_SLOTS строк (см. canAddToSide()
  //  в calc.js и wireSlots() ниже).
  // ------------------------------------------------------------------------
  let catalog = [];       // плоский список предметов тирлиста
  let catalogIndex = {};  // id -> предмет
  const sides = { left: [], right: [] };

  // Слот доски == позиция в entries. Защитный потолок нужен и здесь, не
  // только в интерактивном добавлении: старая/враждебная ссылка-«поделиться»
  // могла закодировать больше шести предметов, и без обрезки на восстановлении
  // доска нарисовала бы седьмой слот, которого в разметке физически нет.
  function capSide(entries) {
    return (entries || []).slice(0, CALC.MAX_SLOTS);
  }

  // ------------------------------------------------------------------------
  //  Значок типа предмета — общий для слота доски и карточки каталога.
  // ------------------------------------------------------------------------
  function badgeImg(type, className) {
    const code = CALC.badgeCodeFor(type);
    const img = document.createElement("img");
    img.className = className;
    img.src = "assets/design/legend/badge-" + code + ".svg";
    img.alt = code.toUpperCase();
    return img;
  }

  // ------------------------------------------------------------------------
  //  Слоты стороны: ровно CALC.MAX_SLOTS штук, часть занята предметом,
  //  остальные — пустые "плюсы", открывающие каталог.
  // ------------------------------------------------------------------------
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
    removeMark.textContent = "✕"; // ✕ — клик по слоту убирает предмет
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

    if (entry.count > 1) {
      const count = document.createElement("span");
      count.className = "tc-slot-count";
      count.textContent = tx("calc.itemCount", { count: entry.count });
      btn.appendChild(count);
    }

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

  // Цвет агрегированного спроса → уже переведённое название уровня. Те же
  // четыре слова, что и в легенде тирлиста (index.php: "Хорошо"/"Средне"/
  // "Ниже среднего"/"Плохо") — новых строк под них заводить незачем.
  const DEMAND_LEVEL_KEY = { green: "legend.good", yellow: "legend.mid", orange: "legend.low", red: "legend.bad" };

  // ------------------------------------------------------------------------
  //  Шкалы стороны: «Пойнты» (сумма value — и ничего больше, отдельной
  //  метрики "очков" в данных нет), «Спрос» (агрегированная точка,
  //  demandBucket() в calc.js) и тонкая полоса относительно другой стороны.
  // ------------------------------------------------------------------------
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

    const max = Math.max(total, otherTotal, 1);
    const pct = Math.max(0, Math.min(100, (total / max) * 100));
    root.querySelector('[data-role="bar"]').style.width = pct + "%";
  }

  // ------------------------------------------------------------------------
  //  Итог сделки: вердикт, разница, подсказка по спросу
  // ------------------------------------------------------------------------
  function renderResult(trade) {
    const resultEl = $("#tcResult");
    const bothEmpty = sides.left.length === 0 && sides.right.length === 0;

    resultEl.dataset.verdict = bothEmpty ? "none" : trade.verdict;
    $("#tcVerdictBadge").dataset.verdict = bothEmpty ? "none" : trade.verdict;

    if (bothEmpty) {
      $("#tcVerdictHeading").textContent = tx("calc.verdictPrompt");
      $("#tcVerdictNumber").textContent = "—"; // — placeholder, пока сторон нет
    } else {
      const verdictKey = trade.verdict === "win" ? "calc.verdictWin"
        : trade.verdict === "lose" ? "calc.verdictLose"
        : "calc.verdictFair";
      $("#tcVerdictHeading").textContent = tx(verdictKey);

      const diffAbs = Math.round(trade.diffAbs);
      const diffPct = Math.round(trade.diffPct * 10) / 10;
      const sign = diffAbs > 0 ? "+" : (diffAbs < 0 ? "−" : "");
      const pctSign = diffPct > 0 ? "+" : "";
      $("#tcVerdictNumber").textContent = sign + fmtNum(Math.abs(diffAbs)) + " (" + pctSign + diffPct + "%)";
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

  // Помечено data-i18n в разметке не может — строка с подстановкой {pct}, а
  // общий проход applyLang() подстановки не делает. Отдельная функция.
  function renderThreshold() {
    $("#tcThreshold").textContent = tx("calc.thresholdNote", { pct: CALC.THRESHOLD_PCT });
  }

  // Подпись кнопки очистки стороны зависит от названия стороны — подстановка,
  // общий проход applyLang() её не делает (см. renderThreshold() выше).
  function renderClearTitles() {
    const giveTitle = tx("calc.clearSide", { side: tx("calc.giveLabel") });
    const getTitle = tx("calc.clearSide", { side: tx("calc.getLabel") });
    const leftBtn = sideRoot("left").querySelector(".tc-clear-side");
    const rightBtn = sideRoot("right").querySelector(".tc-clear-side");
    leftBtn.title = giveTitle;
    leftBtn.setAttribute("aria-label", giveTitle);
    rightBtn.title = getTitle;
    rightBtn.setAttribute("aria-label", getTitle);
  }

  // Ссылка в адресной строке всегда отражает текущую сделку — это и есть
  // «поделиться»: скопировать location.href уже даёт рабочую ссылку без
  // отдельного шага «собрать ссылку». replaceState, а не pushState: смена
  // состава сторон не должна плодить историю переходов браузера.
  function syncUrl() {
    const query = CALC.encodeShareQuery(sides.left, sides.right);
    const url = query ? ("/calculator?" + query) : "/calculator";
    history.replaceState(null, "", url);
  }

  // renderAll() — только перерисовка, без побочных эффектов на адресную
  // строку. onSidesChanged() — то же самое плюс синхронизация ссылки.
  // Разделены намеренно: при восстановлении сделки ИЗ ссылки (load(), ниже)
  // вызывать syncUrl() нельзя — на этот момент location.search ещё не
  // прочитан decodeShareQuery(), и запись пустой сделки стёрла бы l=/r= из
  // адреса раньше, чем их успели разобрать. Смена языка (applyLang) тоже не
  // должна трогать ссылку — состав сторон не менялся.
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

  // ------------------------------------------------------------------------
  //  Каталог предметов — единый оверлей на обе стороны. Клик по пустому
  //  слоту открывает его для этой стороны; карточка добавляет предмет и
  //  диалог остаётся открытым — так удобнее заполнять сразу несколько
  //  слотов подряд. Закрывается крестиком, кликом по подложке или Escape.
  // ------------------------------------------------------------------------
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
    btn.appendChild(badgeImg(it.type, "tc-cat-badge"));

    const icon = document.createElement("img");
    icon.className = "tc-cat-icon";
    icon.src = it.icon || "";
    icon.alt = "";
    btn.appendChild(icon);

    const name = document.createElement("span");
    name.className = "tc-cat-name";
    name.style.setProperty("--tc-badge-color", "var(--tc-badge-" + code + ")");
    name.textContent = it.name || "";
    btn.appendChild(name);

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
    btn.appendChild(bottom);

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
      // Каталог закрывается сразу после удачного добавления: пока он оставался
      // открытым, было не видно, попал предмет на доску или нет. Слот, куда он
      // лёг, коротко подсвечивается — чтобы глаз сам нашёл результат.
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
    // Номер слота, а не ссылка на него: onSidesChanged() перерисовывает доску,
    // и прежний элемент к моменту закрытия уже выброшен из документа — фокус
    // на нём молча уходил на body, и человек с клавиатуры терял место.
    catalogState.slotIndex = triggerEl
      ? [...sideRoot(side).querySelectorAll(".tc-slot")].indexOf(triggerEl)
      : -1;
    $("#tcCatalogStatus").textContent = "";
    $("#tcCatalogSearch").value = "";
    renderCatalogGrid("");
    $("#tcCatalogBackdrop").hidden = false;
    document.body.style.overflow = "hidden";
    $("#tcCatalogSearch").focus();
  }

  // Длительность закрытия должна совпадать с переходом .tc-cat-backdrop в CSS.
  // Держим её здесь одним числом, а не в двух местах: разъехавшись, они дали бы
  // либо обрыв анимации на середине, либо застрявший поверх страницы оверлей.
  const CATALOG_CLOSE_MS = 180;

  function reducedMotion() {
    return window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }

  // Короткая подсветка слота, в который только что лёг предмет. Ищем по id, а
  // не по индексу: addToSide кладёт повтор в уже существующую строку, и индекс
  // в этом случае не менялся бы вовсе.
  function flashAddedSlot(side, addedId) {
    if (!side || !addedId) return;
    const idx = (sides[side] || []).findIndex(e => e && e.item && e.item.id === addedId);
    if (idx < 0) return;
    const slot = sideRoot(side).querySelectorAll(".tc-slot")[idx];
    if (!slot) return;
    slot.classList.remove("is-just-added");
    // Перезапуск анимации: без чтения offsetWidth браузер склеит снятие и
    // возврат класса в один кадр, и повторное добавление того же предмета
    // не мигнёт.
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

    const finish = () => {
      backdrop.hidden = true;
      backdrop.classList.remove("is-closing");
      // Слот ищем заново в живом документе: доска уже перерисована.
      const slots = side ? sideRoot(side).querySelectorAll(".tc-slot") : [];
      // Если предмет добавлен — ведём фокус туда же, куда ушла подсветка, а не
      // на слот, по которому кликнули. Предмет ложится первой свободной
      // строкой, и она почти никогда не совпадает с нажатой клеткой: фокус и
      // подсветка в разных местах заставляли бы искать результат дважды.
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

  function wireCatalog() {
    $("#tcCatalogSearch").addEventListener("input", e => renderCatalogGrid(e.target.value));
    $("#tcCatalogClose").addEventListener("click", closeCatalog);
    $("#tcCatalogBackdrop").addEventListener("click", e => {
      if (e.target === $("#tcCatalogBackdrop")) closeCatalog();
    });
    document.addEventListener("keydown", e => {
      if (e.key === "Escape" && catalogState.open) { closeCatalog(); }
    });
  }

  // ------------------------------------------------------------------------
  //  Слоты и очистка стороны
  // ------------------------------------------------------------------------
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

    root.querySelector(".tc-clear-side").addEventListener("click", () => {
      sides[side] = CALC.clearSide();
      onSidesChanged();
    });
  }

  // ------------------------------------------------------------------------
  //  Общие действия
  // ------------------------------------------------------------------------
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
        // Буфер обмена недоступен (нет разрешения, старый браузер, http без
        // secure context) — показываем саму ссылку текстом вместо тихой
        // ошибки: её всё ещё можно скопировать вручную.
        statusEl.textContent = tx("calc.shareFailed") + ": " + location.href;
      }
    });
  }

  // ------------------------------------------------------------------------
  //  Рекламные борта — слот "rail", тот же документ /api/promo.php и тот же
  //  модуль js/promo.js, что у тирлиста (app.js) и ленты (news-page.js,
  //  fillNewsRail()/renderNewsRails()). Своей логики отбора кампании здесь
  //  нет умышленно: третий independent-механизм показа рекламы — это ровно
  //  тот способ рассинхронизировать три страницы, которого ТЗ требует
  //  избежать.
  // ------------------------------------------------------------------------
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

    // Маркировка рекламы обязательна по закону, если рекламодатель её
    // прислал: без erid борт показывать можно, а вот выкидывать
    // присланный идентификатор — нельзя.
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

  function renderRails() {
    const promo = window.PROMO;
    const left = document.getElementById("tcRailL");
    const right = document.getElementById("tcRailR");
    if (!promo || !left || !right) return;

    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .then(doc => {
        if (!doc) return;
        const list = promo.eligible(promo.normalizeDoc(doc), "rail", Date.now());
        if (!list.length) return; // не куплено — борт остаётся полосатой заглушкой
        fillRail(left, list[0]);
        // Один рекламодатель занимает оба борта: пустой второй борт рядом с
        // заполненным читается как поломка, а не как свободное место.
        fillRail(right, list[1] || list[0]);
      })
      // Реклама не должна ронять калькулятор: не пришёл документ — борта
      // остаются полосатыми.
      .catch(() => {});
  }

  // ------------------------------------------------------------------------
  //  Язык интерфейса (RU / EN) — тот же приём, что в app.js/news-page.js.
  // ------------------------------------------------------------------------
  function applyLang(next) {
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) { /* приватный режим */ }
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
    renderClearTitles();
    // Строки-подстановки (счётчик, слоты, вердикт, подсказка по спросу)
    // заново рисуются вместе со всей сделкой — их не покрывает общий проход
    // выше. renderAll(), не onSidesChanged(): состав сторон не менялся,
    // трогать адресную строку незачем (и до первой загрузки каталога —
    // см. load() — это ещё и стёрло бы l=/r= из входящей ссылки раньше, чем
    // она прочитана).
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

  // ------------------------------------------------------------------------
  //  Загрузка каталога и восстановление сделки из ссылки
  // ------------------------------------------------------------------------
  async function load() {
    const stateEl = $("#tcState");
    stateEl.hidden = false;
    stateEl.textContent = tx("calc.loading");
    try {
      const res = await fetch("/api/tierlist.php", { cache: "no-store" });
      if (!res.ok) throw new Error("http " + res.status);
      const data = await res.json();
      catalog = CALC.flattenTierlist(data && data.tierlist);
      catalogIndex = CALC.buildCatalogIndex(catalog);
      stateEl.hidden = true;
      stateEl.textContent = "";

      // Восстановление возможно только ПОСЛЕ загрузки каталога:
      // decodeShareQuery обязана проверить, что id реально существуют, и без
      // индекса у неё нет данных, чтобы отличить настоящий предмет от
      // подделки — см. русский комментарий в calc.js про враждебный ввод.
      const restored = CALC.decodeShareQuery(new URLSearchParams(location.search), catalogIndex);
      sides.left = capSide(restored.left);
      sides.right = capSide(restored.right);
      // renderAll(), не onSidesChanged(): адрес и так уже несёт ровно эту
      // сделку (мы её из него и прочитали) — переписывать нечего.
      renderAll();
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
  applyLang(); // без аргумента — язык уже выбран выше, localStorage лишний раз не трогаем
  load();
  renderRails();
})();
