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

  // Слот доски == позиция в entries, и в нём ровно ОДИН предмет: два
  // одинаковых занимают два слота. Защитный потолок нужен и здесь, не
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
  const DEMAND_LEVEL_KEY = {
    neon: "legend.neon", green: "legend.good", yellow: "legend.mid",
    orange: "legend.low", red: "legend.bad"
  };

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

    // Указатель на полосе: доля стороны в сумме двух сторон. Пустая сделка —
    // ровно середина, как в макете: делить 0 на 0 нечем, а «ноль слева»
    // читался бы как «эта сторона проигрывает», хотя сравнивать ещё нечего.
    const sum = total + otherTotal;
    const pct = sum > 0 ? Math.max(0, Math.min(100, (total / sum) * 100)) : 50;
    root.querySelector('[data-role="mark"]').style.left = pct + "%";
  }

  // ------------------------------------------------------------------------
  //  Итог сделки: вердикт, разница, подсказка по спросу
  // ------------------------------------------------------------------------
  function renderResult(trade) {
    const resultEl = $("#tcResult");
    const bothEmpty = sides.left.length === 0 && sides.right.length === 0;

    resultEl.dataset.verdict = bothEmpty ? "none" : trade.verdict;
    $("#tcVerdictBadge").dataset.verdict = bothEmpty ? "none" : trade.verdict;

    // Боковые столбики: сторона в минусе красная, в плюсе синяя, при честном
    // обмене обе зелёные. Порог берётся из того же computeTrade, что и вердикт
    // под доской — иначе столбик показывал бы «в минусе» там, где надпись
    // говорит «честная сделка».
    //
    // trade.verdict считается ОТ ЛЕВОЙ стороны: "lose" значит, что отдающий
    // отдаёт больше, чем получает. Значит левый столбик повторяет вердикт как
    // есть, а правый всегда противоположен ему.
    const invert = v => v === "win" ? "lose" : v === "lose" ? "win" : v;
    const gaugeL = bothEmpty ? "none" : trade.verdict;
    const gaugeR = bothEmpty ? "none" : invert(trade.verdict);
    const gl = $("#tcGaugeL"), gr = $("#tcGaugeR");
    if (gl) { gl.dataset.state = gaugeL; }
    if (gr) { gr.dataset.state = gaugeR; }

    // Заголовок карточки называет исход: «ПРОВЕРИМ?» до сделки и
    // «ВЫГОДНО» / «НЕ ВЫГОДНО» / «РАВНО» после — четыре варианта компонента
    // «Group 10» в макете отличаются именно им, а не только цветом обводки.
    // Строка под чертой поясняет исход словами, число под ней — процентом,
    // а разницу в пойнтах показывает строка под доской.
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
      // Оба числа печатаются с одним и тем же минусом U+2212. Дефис, который
      // Number отдаёт сам, заметно короче и выше плюса, и в строке вида
      // «−116 400» / «−88.2%» разнобой видно глазом — а это самые крупные
      // числа на странице. Отсюда явный знак и Math.abs() у обоих.
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

  // Помечено data-i18n в разметке не может — строка с подстановкой {pct}, а
  // общий проход applyLang() подстановки не делает. Отдельная функция.
  function renderThreshold() {
    $("#tcThreshold").textContent = tx("calc.thresholdNote", { pct: CALC.THRESHOLD_PCT });
  }

  // Ссылка в адресной строке всегда отражает текущую сделку — это и есть
  // «поделиться»: скопировать location.href уже даёт рабочую ссылку без
  // отдельного шага «собрать ссылку». replaceState, а не pushState: смена
  // состава сторон не должна плодить историю переходов браузера.
  function syncUrl() {
    // Путь берётся из location, а не зашивается литералом "/calculator":
    // страница живёт и по /calculator.php (встроенный сервер .htaccess не
    // читает), и перезапись адреса на несуществующий там путь теряла бы
    // собранную сделку при перезагрузке.
    const query = CALC.encodeShareQuery(sides.left, sides.right);
    history.replaceState(null, "", location.pathname + (query ? "?" + query : ""));
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
  //  каталог закрывается с анимацией — предмет должен сразу быть виден на
  //  доске, иначе непонятно, засчитался ли выбор. Заполнить следующий слот
  //  — это ещё один клик по доске. Закрывается также крестиком, кликом по
  //  подложке или Escape.
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

    // Скруглением и обрезкой заведует внутренняя обёртка, а не сама кнопка:
    // значок типа в макете выходит за левый верхний угол карточки, и
    // overflow:hidden на кнопке срезал бы его.
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
    // Плашка названия залита градиентом своего типа (Rectangle 67 макета).
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

    // Значок типа последним: он лежит поверх карточки и за её углом.
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
    // Отложенный кадр от прошлого открытия нарисовал бы сетку по уже
    // стёртому запросу поверх свежей.
    cancelQueuedRender();
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

  // Короткая подсветка слота, в который только что лёг предмет. Ищем ПОСЛЕДНИЙ
  // слот с этим id, а не первый: добавленный экземпляр всегда встаёт в конец,
  // и при втором таком же предмете подсветился бы старый слот, а не новый.
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
    cancelQueuedRender();

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

  // Поиск перестраивает сетку целиком — 113 карточек по пять узлов каждая.
  // Без задержки слово из девяти букв давало девять полных перестроек
  // подряд, и на телефоне ввод отставал от клавиатуры. Рисуем один раз,
  // когда человек перестал печатать.
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

  // aria-modal="true" только обещает вспомогательным технологиям, что
  // остального на странице нет — сам он ничего не удерживает. Без ловушки
  // Tab из последней карточки уходил на пилюли шапки и слоты доски под
  // затемнением: невидимые элементы, по которым не понять, где находишься,
  // и вернуться в каталог можно было только наощупь.
  const FOCUSABLE_SEL = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function catalogFocusables() {
    const dialog = $("#tcCatalog");
    if (!dialog) return [];
    // Скрытое (display:none у пустой сетки, например) в обход не берём:
    // фокус на элементе без коробки выглядит как пропавший фокус.
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

  // ------------------------------------------------------------------------
  //  Слоты
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
  //  fillNewsRail()/renderNewsPromo()). Своей логики отбора кампании здесь
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

  // Один запрос на оба размещения: борта по бокам доски (слот "rail", видны
  // на компьютере) и нижняя полоса (слот "dock", видна на телефоне, где
  // борта скрыты вместе с макетным фреймом). Документ /api/promo.php один и
  // тот же, тянуть его дважды незачем.
  function renderPromo() {
    const promo = window.PROMO;
    const left = document.getElementById("tcRailL");
    const right = document.getElementById("tcRailR");
    const dock = document.getElementById("promoDock");
    if (!promo) return;

    // Реклама не должна ронять калькулятор: не пришёл документ — борта
    // остаются полосатыми. Отсюда .catch перед разбором, а не после: с null
    // дальше по цепочке умеют работать и полоса, и окно (у окна есть своё
    // объявление на случай, когда купленных кампаний нет вовсе).
    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .catch(() => null)
      .then(doc => {
        // Нижняя полоса — общий модуль с лентой (js/promo-dock.js): он сам
        // решает, строить ли её на этой ширине экрана, и сам меряет высоту
        // под нижнее поле страницы.
        if (dock && window.NX_PROMO_DOCK) window.NX_PROMO_DOCK.render(dock, doc);

        // Окно — тот же общий модуль (js/promo-popup.js). busy() держит его
        // закрытым, пока открыт каталог: человек ищет предмет, и накрывать
        // поиск рекламой нельзя.
        if (window.NX_PROMO_POPUP) {
          window.NX_PROMO_POPUP.mount({ doc, busy: () => catalogState.open });
        }

        if (!doc || !left || !right) return;
        const list = promo.eligible(promo.normalizeDoc(doc), "rail", Date.now());
        if (!list.length) return; // не куплено — борт остаётся полосатой заглушкой
        fillRail(left, list[0]);
        // Один рекламодатель занимает оба борта: пустой второй борт рядом с
        // заполненным читается как поломка, а не как свободное место.
        fillRail(right, list[1] || list[0]);
      });
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
  //  Загрузка каталога, опрос обновлений и восстановление сделки из ссылки
  // ------------------------------------------------------------------------
  //  Цены калькулятора — это цены тирлиста: отдельной копии нет, каталог
  //  собирается из того же /api/tierlist.php. Но одной загрузки при открытии
  //  страницы мало: вкладку держат открытой часами, и правка цены в админке
  //  доезжала бы до неё только после F5 — человек считал бы сделку по цифрам,
  //  которых на сайте уже нет.
  //
  //  Опрос устроен как на тирлисте (js/app.js): раз в POLL_MS читаем
  //  крошечный /api/state.php ({rev, likes, promoRev} — десятки байт), и
  //  только когда rev изменился, качаем полный документ по
  //  /api/tierlist.php?rev=<n>. Ответ на конкретный rev помечен immutable
  //  (см. api/tierlist.php), поэтому повторный запрос с тем же rev до сервера
  //  не доходит вовсе.
  const API_STATE = "/api/state.php";
  const API_TIERLIST = "/api/tierlist.php";
  const POLL_MS = 30000;
  let lastRev = null;
  let pollTimer = null;

  function applyTierlist(doc) {
    catalog = CALC.flattenTierlist(doc);
    catalogIndex = CALC.buildCatalogIndex(catalog);
  }

  // Сделка хранит сами предметы, а не только их id, поэтому после обновления
  // каталога строки надо пересобрать: иначе на доске остались бы объекты со
  // старой ценой, и итог разошёлся бы с тирлистом ровно на величину правки.
  // Предмет, которого в новом каталоге больше нет, из сделки выпадает — так
  // же, как при разборе входящей ссылки (decodeShareQuery в calc.js).
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
    } catch (e) { /* оффлайн — молча ждём следующего опроса */ }
    return null;
  }

  // Без rev — обычный «дай текущее» (no-store). С rev — запрос за конкретной
  // ревизией, и вот его уже можно кэшировать: содержимое ревизии неизменно.
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
      // renderAll(), не onSidesChanged(): состав сделки не менялся — поменялись
      // цены под ним, и переписывать адресную строку незачем.
      renderAll();
      // Открытый каталог показывает те же цены, что доска, — значит и он
      // должен обновиться. Перерисовка редкая (только по смене rev), поэтому
      // она не спорит с правилом «не перестраивать сетку на каждый символ».
      if (catalogState.open) { renderCatalogGrid($("#tcCatalogSearch").value); }
    } catch (e) {
      console.warn("calculator: не удалось обновить тирлист", e);
    }
  }

  function startPolling() {
    if (pollTimer) { clearInterval(pollTimer); }
    pollTimer = setInterval(poll, POLL_MS);
    // Свежие цены сразу при возврате на вкладку, без ожидания интервала.
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") { poll(); }
    });
  }

  async function load() {
    const stateEl = $("#tcState");
    stateEl.hidden = false;
    stateEl.textContent = tx("calc.loading");
    try {
      // Сначала rev, потом документ по нему: так первая же загрузка попадает
      // в тот же immutable-кэш, что и последующие опросы, и повторное открытие
      // страницы не тянет тирлист заново.
      const st = await fetchState();
      const rev = st && typeof st.rev === "number" ? st.rev : null;
      applyTierlist(await fetchTierlist(rev));
      if (rev !== null) { lastRev = rev; }
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
  applyLang(); // без аргумента — язык уже выбран выше, localStorage лишний раз не трогаем
  load();
  renderPromo();
})();
