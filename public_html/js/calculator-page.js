/* ============================================================
   Страница калькулятора трейдов. Вся арифметика (парсинг значений,
   суммы, вердикт, кодирование ссылки) живёт в js/calc.js — этот файл
   только DOM: рендер сторон, поиск предметов, обработчики кликов.
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
  // ------------------------------------------------------------------------
  let catalog = [];       // плоский список предметов тирлиста
  let catalogIndex = {};  // id -> предмет
  const sides = { left: [], right: [] };

  // ------------------------------------------------------------------------
  //  Рендер одной стороны
  // ------------------------------------------------------------------------
  function buildItemRow(side, entry) {
    const li = document.createElement("li");
    li.className = "tc-item";
    li.dataset.id = entry.item.id;

    const icon = document.createElement("img");
    icon.className = "tc-item-icon";
    icon.src = entry.item.icon || "";
    icon.alt = "";
    li.appendChild(icon);

    const name = document.createElement("span");
    name.className = "tc-item-name";
    name.textContent = entry.item.name || "";
    li.appendChild(name);

    // Точка спроса — тот же приём, что и в самой карточке тирлиста (app.js,
    // renderCell): реальная картинка assets/dot-<цвет>.png, а не CSS-кружок
    // легенды. У предмета без выставленного спроса точки просто нет.
    if (entry.item.demand) {
      const dot = document.createElement("img");
      dot.className = "tc-item-dot";
      dot.src = "assets/dot-" + entry.item.demand + ".png";
      dot.alt = "";
      li.appendChild(dot);
    }

    const value = document.createElement("span");
    value.className = "tc-item-value";
    value.textContent = entry.item.value || "0";
    li.appendChild(value);

    // Счётчик показываем только при повторном добавлении — единственная
    // строка с count===1 и так читается однозначно, а вечное "×1" на каждой
    // строке было бы шумом.
    const count = document.createElement("span");
    count.className = "tc-item-count";
    count.textContent = tx("calc.itemCount", { count: entry.count });
    count.hidden = entry.count <= 1;
    li.appendChild(count);

    const removeBtn = document.createElement("button");
    removeBtn.type = "button";
    removeBtn.className = "tc-item-remove";
    removeBtn.setAttribute("aria-label", tx("calc.removeOne", { name: entry.item.name || "" }));
    removeBtn.textContent = "−"; // минус, не дефис — читается как кнопка убавления
    removeBtn.addEventListener("click", () => {
      sides[side] = window.CALC.removeOneFromSide(sides[side], entry.item.id);
      onSidesChanged();
    });
    li.appendChild(removeBtn);

    return li;
  }

  function renderSide(side) {
    const root = sideRoot(side);
    if (!root) return;
    const list = root.querySelector(".tc-list");
    const empty = root.querySelector(".tc-empty");
    const totalEl = root.querySelector(".tc-total-value");
    const entries = sides[side];

    list.textContent = "";
    entries.forEach(entry => list.appendChild(buildItemRow(side, entry)));
    empty.hidden = entries.length > 0;
    totalEl.textContent = fmtNum(window.CALC.sideTotal(entries));
  }

  // ------------------------------------------------------------------------
  //  Итог сделки: сумма, разница, вердикт, подсказка по спросу
  // ------------------------------------------------------------------------
  function renderResult() {
    const trade = window.CALC.computeTrade(sides.left, sides.right);

    const diffAbs = Math.round(trade.diffAbs);
    const diffPct = Math.round(trade.diffPct * 10) / 10;
    const sign = diffAbs > 0 ? "+" : (diffAbs < 0 ? "−" : "");
    const pctSign = diffPct > 0 ? "+" : "";
    $("#tcDiffValue").textContent = sign + fmtNum(Math.abs(diffAbs)) + " (" + pctSign + diffPct + "%)";

    const verdictKey = trade.verdict === "win" ? "calc.verdictWin"
      : trade.verdict === "lose" ? "calc.verdictLose"
      : "calc.verdictFair";
    $("#tcVerdict").dataset.verdict = trade.verdict;
    $("#tcVerdictText").textContent = tx(verdictKey);

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
    $("#tcThreshold").textContent = tx("calc.thresholdNote", { pct: window.CALC.THRESHOLD_PCT });
  }

  // Подпись кнопки очистки стороны зависит от названия стороны — подстановка,
  // общий проход applyLang() её не делает (см. renderThreshold() выше).
  // title и aria-label ставятся вместе — кнопка несёт только "✕", без
  // aria-label скринридер не озвучил бы вообще ничего осмысленного.
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
    const query = window.CALC.encodeShareQuery(sides.left, sides.right);
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
    renderSide("left");
    renderSide("right");
    renderResult();
  }

  function onSidesChanged() {
    renderAll();
    syncUrl();
  }

  // ------------------------------------------------------------------------
  //  Поиск предмета
  // ------------------------------------------------------------------------
  function norm(s) { return String(s || "").toLowerCase(); }

  function closeSuggest(side) {
    const box = sideRoot(side).querySelector(".tc-suggest");
    box.hidden = true;
    box.textContent = "";
  }

  function renderSuggestions(side, query) {
    const root = sideRoot(side);
    const box = root.querySelector(".tc-suggest");
    const q = norm(query).trim();
    box.textContent = "";
    if (!q) { box.hidden = true; return; }

    const matches = catalog.filter(it => norm(it.name).includes(q)).slice(0, 8);
    if (!matches.length) {
      const li = document.createElement("li");
      li.className = "tc-suggest-empty";
      li.textContent = tx("calc.searchNoResults");
      box.appendChild(li);
      box.hidden = false;
      return;
    }

    matches.forEach(it => {
      const li = document.createElement("li");
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "tc-suggest-btn";
      btn.setAttribute("aria-label", tx("calc.addItem", { name: it.name || "" }));

      const icon = document.createElement("img");
      icon.className = "tc-suggest-icon";
      icon.src = it.icon || "";
      icon.alt = "";
      btn.appendChild(icon);

      const name = document.createElement("span");
      name.className = "tc-suggest-name";
      name.textContent = it.name || "";
      btn.appendChild(name);

      const value = document.createElement("span");
      value.className = "tc-suggest-value";
      value.textContent = it.value || "0";
      btn.appendChild(value);

      btn.addEventListener("click", () => {
        sides[side] = window.CALC.addToSide(sides[side], it);
        onSidesChanged();
        const input = root.querySelector(".tc-search-input");
        input.value = "";
        closeSuggest(side);
        input.focus();
      });

      li.appendChild(btn);
      box.appendChild(li);
    });
    box.hidden = false;
  }

  function wireSide(side) {
    const root = sideRoot(side);
    const input = root.querySelector(".tc-search-input");
    const box = root.querySelector(".tc-suggest");

    input.addEventListener("input", () => renderSuggestions(side, input.value));

    input.addEventListener("keydown", e => {
      if (e.key === "Escape") {
        input.value = "";
        closeSuggest(side);
      } else if (e.key === "ArrowDown") {
        const first = box.querySelector(".tc-suggest-btn");
        if (first) { e.preventDefault(); first.focus(); }
      }
    });

    // Клавиатурная навигация внутри самого списка подсказок — стрелками
    // между кнопками, Escape возвращает фокус в поле поиска.
    box.addEventListener("keydown", e => {
      const buttons = Array.from(box.querySelectorAll(".tc-suggest-btn"));
      const idx = buttons.indexOf(document.activeElement);
      if (e.key === "ArrowDown") {
        e.preventDefault();
        (buttons[idx + 1] || buttons[0])?.focus();
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        if (idx <= 0) { input.focus(); } else { buttons[idx - 1].focus(); }
      } else if (e.key === "Escape") {
        e.preventDefault();
        closeSuggest(side);
        input.focus();
      }
    });

    root.querySelector(".tc-clear-side").addEventListener("click", () => {
      sides[side] = window.CALC.clearSide();
      onSidesChanged();
    });
  }

  // Клик вне поля поиска/списка подсказок закрывает список — иначе он
  // остаётся раскрытым и перекрывает соседние элементы стороны.
  document.addEventListener("click", e => {
    ["left", "right"].forEach(side => {
      const root = sideRoot(side);
      if (root && !root.contains(e.target)) { closeSuggest(side); }
    });
  });

  // ------------------------------------------------------------------------
  //  Общие действия
  // ------------------------------------------------------------------------
  function wireActions() {
    $("#tcClearAllBtn").addEventListener("click", () => {
      if (!window.confirm(tx("calc.confirmClearAll"))) return;
      sides.left = window.CALC.clearSide();
      sides.right = window.CALC.clearSide();
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
    // Строки-подстановки (счётчик, вердикт, подсказка по спросу) заново
    // рисуются вместе со всей сделкой — их не покрывает общий проход выше.
    // renderAll(), не onSidesChanged(): состав сторон не менялся, трогать
    // адресную строку незачем (и до первой загрузки каталога — см. load() —
    // это ещё и стёрло бы l=/r= из входящей ссылки раньше, чем она прочитана).
    renderAll();
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
      catalog = window.CALC.flattenTierlist(data && data.tierlist);
      catalogIndex = window.CALC.buildCatalogIndex(catalog);
      stateEl.hidden = true;
      stateEl.textContent = "";

      // Восстановление возможно только ПОСЛЕ загрузки каталога:
      // decodeShareQuery обязана проверить, что id реально существуют, и без
      // индекса у неё нет данных, чтобы отличить настоящий предмет от
      // подделки — см. русский комментарий в calc.js про враждебный ввод.
      const restored = window.CALC.decodeShareQuery(new URLSearchParams(location.search), catalogIndex);
      sides.left = restored.left;
      sides.right = restored.right;
      // renderAll(), не onSidesChanged(): адрес и так уже несёт ровно эту
      // сделку (мы её из него и прочитали) — переписывать нечего.
      renderAll();
    } catch (e) {
      console.warn("calculator: не удалось загрузить тирлист", e);
      stateEl.hidden = false;
      stateEl.textContent = tx("calc.loadError");
    }
  }

  wireSide("left");
  wireSide("right");
  wireActions();
  applyLang(); // без аргумента — язык уже выбран выше, localStorage лишний раз не трогаем
  load();
})();
