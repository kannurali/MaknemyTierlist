/* ============================================================
   Nexus Tier List — интерактивный редактор
   Чистый JS, состояние в localStorage.
   ============================================================ */
(() => {
  "use strict";

  const STORAGE_KEY = "nexus-tierlist-v1";
  const DIRTY_KEY = "nexus-tierlist-dirty-v1"; // помним факт НЕопубликованных правок между перезагрузками
  const DEFAULT_ICON = "assets/icon-sample.png";
  const TIER_LOGOS = { MK: "assets/logo-mk.png", GLH: "assets/logo-glh.png", "💧": "assets/logo-bolt.png" };

  const uid = () => "id" + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);

  // ============================================================
  //  ССЫЛКИ НА ДОНАТ — значения ПО УМОЛЧАНИЮ
  //  Кнопка «Поддержать» открывает окно с этими ссылками и QR-кодом.
  //  Менять их код больше не нужно: админ правит прямо на сайте
  //  (режим «Редактирование» → кнопка «Поддержать» → 🔗 / 🖼 QR).
  //  Значения хранятся в state.donate и публикуются вместе с тирлистом.
  // ============================================================
  const DONATE_DA  = "https://www.donationalerts.com/r/maknemy"; // прямой донат
  const DONATE_HUB = "https://dalink.to/maknemy";                // хаб со всеми способами
  const DONATE_QR  = "assets/qr-donate.png?v=1";                 // QR ведёт на DONATE_HUB

  // ---------- Default template ----------
  function defaultState() {
    const mk = (name, value, type, demand, trend) => ({
      id: uid(), name, value: String(value), icon: DEFAULT_ICON, type, demand, trend,
      desc: "", flag: false, wip: false,
    });
    return {
      title: "MAKNEMY\nTIER LIST",
      date: "17.02.2026",
      autoSort: true,
      filters: { fruits: true, mutations: true, perms: false, passes: true, skins: true },
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
        { title: "МОЙ ДИСКОРД",       sub: "discord.gg/A4ZG8sxCM",   href: "https://discord.gg/A4ZG8sxCM", icon: "assets/avatar-discord.png" },
        { title: "МОЙ ТЕЛЕГРАММ",     sub: "t.me/mksvtnc",           href: "https://t.me/mksvtnc",         icon: "assets/avatar-tg.png" },
        { title: "BLOX FRUITS NEWS",  sub: "t.me/bfsnews",           href: "https://t.me/bfsnews",         icon: "assets/avatar-bfnews.png" },
        { title: "ВСЕ РОЗЫГРЫШИ ТУТ", sub: "t.me/mksvtnc",           href: "https://t.me/mksvtnc",         icon: "assets/avatar-giveaways.png" },
        { title: "CHARLOTTE TM",      sub: "discord.gg/Q9PO6UG9Q4",  href: "https://discord.gg/Q9PO6UG9Q4", icon: "assets/avatar-charlotte.png" },
        { title: "ПОМОЩНИК",          sub: "t.me/typeopozitivegg",   href: "https://t.me/typeopozitivegg", icon: "" },
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
  // Достройка состояния до постерного макета вынесена в js/migrate.js: там она
  // тестируется из node, здесь бы её пришлось проверять только через браузер.
  // Без файла сайт продолжает работать — просто без карточек и аватарок.
  //
  // Объявление стоит ВЫШЕ первого вызова load(): const попадает во временную
  // мёртвую зону, и load() из строки ниже уронил бы страницу у всех, у кого в
  // localStorage лежит сохранённое состояние.
  const MIGRATE = (typeof window !== "undefined" && window.MIGRATE) || null;
  const migrateToPosterLayout = (MIGRATE && MIGRATE.migrate) || (s => s);

  // Чистый шаблон тоже проходит достройку: карточки авторов собираются из
  // титров, а не лежат в шаблоне отдельной копией тех же имён.
  let state = load() || migrateToPosterLayout(defaultState());
  let isAdmin = false;
  let dirty = false;   // есть несохранённые правки админа
  let saving = false;  // идёт публикация на сервер
  // Восстановление после перезагрузки: не затирать локальные правки данными из базы
  let bootedFromLocal = localStorage.getItem(STORAGE_KEY) != null;
  // Были ли при прошлой сессии РЕАЛЬНЫЕ неопубликованные правки (нажимали Save?).
  // Только в этом случае при старте защищаем локальные данные от базы — иначе
  // админ молча застревал «грязным» и переставал получать чужие обновления.
  let bootedDirty = (() => { try { return localStorage.getItem(DIRTY_KEY) === "1"; } catch (e) { return false; } })();
  let deferredServer = null;       // снимок из базы, отложенный до выяснения роли входа
  let pendingServer = null;        // свежая база, пришедшая пока есть свои неопубликованные правки
  let roleResolved = false;        // роль выяснена (checkSession/вход/выход отработали)
  let firstSnapshotHandled = false;

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
      merged.donate = Object.assign({}, d.donate, data.donate || {});
      merged.filters = Object.assign({}, d.filters, data.filters || {});
      merged.filters.perms = false; // пермы по умолчанию скрыты — показываются только по клику
      if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
      if (!Array.isArray(merged.footer) || !merged.footer.length) merged.footer = d.footer;
      if (typeof merged.autoSort !== "boolean") merged.autoSort = true;
      // old saves: give default tiers their logos back
      merged.tiers.forEach(t => {
        if (t.logo === undefined && TIER_LOGOS[t.label]) t.logo = TIER_LOGOS[t.label];
      });
      return migrateToPosterLayout(merged);
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
    if (isAdmin) { dirty = true; try { localStorage.setItem(DIRTY_KEY, "1"); } catch (e) {} renderSaveBtn(); }
  }
  // Снять отметку «есть неопубликованные правки» после успешной публикации
  function clearDirty() {
    dirty = false;
    try { localStorage.removeItem(DIRTY_KEY); } catch (e) {}
    renderSaveBtn();
  }

  // ---------- Сжатие картинок ----------
  // Картинки уходят на сервер отдельными файлами (upload.php), но сначала их
  // уменьшаем и пережимаем в WebP (обычно единицы КБ): так и загрузка быстрее,
  // и на диске хостинга не копятся мегабайтные исходники.
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
        // WebP не поддержан (старый Safari) → toDataURL вернёт PNG
        if (out.indexOf("data:image/webp") !== 0) {
          try { out = c.toDataURL("image/png"); } catch (e) { out = ""; }
        }
        // если меньше не стало (картинка и так крошечная) — оставляем исходник
        resolve(out && out.length < src.length ? out : src);
      };
      img.onerror = () => resolve(src);
      img.src = src;
    });
  }
  // File → сжатый data URL
  function fileToSmallDataURL(file, maxSize, quality) {
    return new Promise(resolve => {
      const reader = new FileReader();
      reader.onload = () => shrinkDataURL(reader.result, maxSize, quality).then(resolve);
      reader.onerror = () => resolve("");
      reader.readAsDataURL(file);
    });
  }
  // Сервер принимает картинку не больше 500 КБ (api/lib/images.php), а отказ
  // ломает сохранение целиком: неудавшаяся выгрузка оставляет data-URL прямо в
  // состоянии, и сохранение падает на извлечении картинок. Поэтому для крупных
  // баннеров перебираем пресеты, пока не влезем в бюджет.
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
  // fetch с ограничением по времени. Без него зависший сервер оставляет кнопку
  // «Сохранить» в состоянии «⏳ Сохранение…» навсегда, без выхода.
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

  // Загрузить (сжатый) data-URL на сервер, вернуть URL сохранённого файла.
  // При ошибке возвращаем исходный data-URL — правка не ломается оффлайн
  // (сервер при сохранении всё равно извлечёт встроенные картинки).
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
    } catch (e) { /* оффлайн */ }
    return dataUrl;
  }

  // Перед публикацией выгрузить все ещё встроенные (data:) картинки в файлы,
  // чтобы сохранённый JSON нёс только URL. Сервер извлекает как бэкстоп.
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

  // Публикация текущего состояния на сервер (PHP) — по кнопке «Сохранить».
  async function publish() {
    if (!isAdmin || !dirty || saving) return;
    saving = true; renderSaveBtn();
    try {
      await compactState(); // выгружает встроенные картинки в файлы (бэкстоп: сервер тоже извлечёт)
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
      render();
      const r = await fetchWithTimeout(API_SAVE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(state),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) { throw new Error(d.error || ("save failed: " + r.status)); }
      // rev генерит сервер; берём его из ответа.
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

  // Внешний вид кнопки «Сохранить» по текущему состоянию
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

  // ---------- DOM refs ----------
  const $ = (s, r = document) => r.querySelector(s);
  const stage = $("#stage");
  const tiersEl = $("#tiers");
  const savedHint = $("#savedHint");
  const btnSave = $("#btnSave");
  const editToggle = $("#editToggle");
  const autoSortToggle = $("#autoSortToggle");
  const creditsEl = $("#credits");
  const footerEl = $("#tlFooter");

  // ============================================================
  //  ЯЗЫК ИНТЕРФЕЙСА (RU / EN)
  // ------------------------------------------------------------
  //  Переводится ТОЛЬКО интерфейс. Названия тиров и предметов, реклама, титры
  //  и ссылки в подвале приходят из БД и показываются как есть.
  //  Легенда лежит внутри #stage, поэтому выбранный язык попадает и в PNG.
  //  Строки живут в js/i18n.js, узлы помечены data-i18n* в разметке.
  // ============================================================
  const LANG_KEY = "nexus-lang-v1";

  // Словарь подключается отдельным <script>. Если он почему-то не доехал
  // (частичная выкладка, блокировщик, битый кеш), обращение к I18N уронило бы
  // весь этот IIFE — то есть весь сайт, у всех.
  //
  // Без словаря: разметку не трогаем, поэтому посетитель видит русский текст из
  // index.html и сайт работает. Подписи, которые ставит JS, покажут сам ключ
  // («admin.saved» вместо «✓ Сохранено») — это заметно и чинится выкладкой
  // файла. Прятать такую поломку хуже, чем показать: молчаливый фолбэк на
  // вторую копию строк разошёлся бы со словарём при первой же правке.
  const i18n = (typeof I18N !== "undefined") ? I18N : null;
  if (!i18n) console.warn("i18n.js не загружен — интерфейс останется на русском");

  let lang = i18n
    ? i18n.pickLang(
        (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
        navigator.language)
    : "ru";

  // Не `t`: это имя в файле уже занято под тир/таймер в девяти местах, и внутри
  // таких блоков вызов t("ключ") молча ушёл бы не туда.
  const tx = (key, vars) => (i18n ? i18n.t(key, lang, vars) : key);

  function applyLang(next) {
    if (!i18n) return;      // оставляем разметку как есть
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) { /* приватный режим */ }
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
      // Перерисовываем: подписи, которые ставит JS (кнопка сохранения,
      // тултипы тиров, «＋ Предмет» внутри тира), живут не в разметке.
      if (btn && btn.dataset.lang !== lang) { applyLang(btn.dataset.lang); render(); }
    });
  })();

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
  // ---------- Ссылки ----------
  // Адрес, введённый руками, обычно идёт без схемы: «t.me/mksvtnc». Браузер
  // считает такую строку ОТНОСИТЕЛЬНЫМ путём и уводит на
  // maknemytierlist.site/t.me/mksvtnc — ссылка «перестаёт работать».
  // Поэтому схему подставляем сами.
  //
  // Заодно отсекаем всё, кроме http(s)/mailto/tel: «javascript:…» в поле
  // ссылки — это исполняемый код на странице у каждого посетителя.
  //
  // fallback — подпись ссылки (.sub): в ней уже написан адрес, поэтому если
  // href пустой или остался заглушкой «https://», берём адрес оттуда.
  const SAFE_SCHEME = /^(https?:|mailto:|tel:)/i;
  // Подпись — свободный текст: «t.me/bfsnews» подойдёт как адрес, а
  // «наш чат» нет. Берём её в дело, только если это похоже на домен:
  // без пробелов и с точкой в доменной части.
  function looksLikeUrl(s) { return /^[^\s]+\.[a-z]{2,}([\/?#].*)?$/i.test(s); }
  function normalizeHref(href, fallback) {
    let s = String(href == null ? "" : href).trim();
    if (s === "" || /^https?:\/*$/i.test(s)) {
      const fb = String(fallback == null ? "" : fallback).trim();
      s = looksLikeUrl(fb) ? fb : "";
    }
    if (!s) return "";
    // уже со схемой — пропускаем только безопасные
    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) return SAFE_SCHEME.test(s) ? s : "";
    // «//host» — протокол-относительный адрес, а не путь внутри сайта
    if (s.indexOf("//") === 0) return "https:" + s;
    // свой же путь или якорь — оставляем как есть
    if (s.charAt(0) === "/" || s.charAt(0) === "#") return s;
    return "https://" + s.replace(/^\/+/, "");
  }

  // Фрукты (f/пусто) · Мутации (m) · Пермы (p) · Пассы (gp) · Скины (s/cr — хроматики идут со скинами)
  function groupOf(type) {
    if (type === "p") return "perms";
    if (type === "gp") return "passes";
    if (type === "s" || type === "cr") return "skins";
    if (type === "m") return "mutations";
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
  // Предметы тира, проходящие активные фильтры (Фрукты/Мутации/Пермы/…)
  function visibleItemsOf(tier) {
    return tier.items.filter(it => state.filters[groupOf(it.type)]);
  }

  // Целевое число предметов в блоке (тире) при ПРОСМОТРЕ: видимые предметы
  // переливаются в блоки по столько штук, заполняя верхние блоки ближайшими
  // предметами снизу — чтобы не было пустых мест.
  const ITEMS_PER_BLOCK = 11;

  // Сколько иконок грузить обычным (не ленивым) способом. loading="lazy" на
  // ВСЕХ иконках означало, что даже верхний ряд браузер начинает качать только
  // после первой раскладки — предметы на первом экране появлялись с заметной
  // задержкой, «текстуры подгружались». Два верхних ряда всегда видны сразу,
  // поэтому их грузим приоритетно, а всё остальное остаётся ленивым.
  let eagerIconBudget = 0;

  function render() {
    tiersEl.innerHTML = "";
    eagerIconBudget = Math.max(6, (itemsPerRow() || ITEMS_PER_BLOCK) * 2);
    const editing = editToggle.checked;

    // blocks: [{ tier, ti, items }] — что и под какой плашкой рисуем
    let blocks;
    if (editing) {
      // Редактирование: реальная структура — каждый тир со своими предметами
      // (с учётом фильтров). Полностью пустой тир оставляем видимым, чтобы его
      // можно было наполнить. Предметы между тирами НЕ перемещаем.
      blocks = [];
      state.tiers.forEach((tier, ti) => {
        const items = visibleItemsOf(tier);
        if (items.length || !tier.items.length) blocks.push({ tier, ti, items });
      });
    } else {
      // Просмотр: переливаем ВСЕ видимые предметы в блоки, заполняя верхние
      // блоки ближайшими предметами снизу — без пустых мест. В один блок берём
      // столько, сколько ВЛЕЗАЕТ в ряд (на телефоне ~6), но не больше
      // ITEMS_PER_BLOCK (на десктопе 11) — чтобы каждый ряд был заполнен целиком
      // и не оставалось коротких «хвостов» по 5 штук.
      const blockSize = Math.min(itemsPerRow() || ITEMS_PER_BLOCK, ITEMS_PER_BLOCK);
      const flat = [];
      state.tiers.forEach(tier => { for (const it of visibleItemsOf(tier)) flat.push(it); });
      blocks = [];
      for (let i = 0; i < flat.length; i += blockSize) {
        const bi = blocks.length;
        const tier = state.tiers[Math.min(bi, state.tiers.length - 1)];
        blocks.push({ tier, ti: bi, items: flat.slice(i, i + blockSize) });
      }
    }

    const adAfter = Math.ceil(blocks.length / 2) - 1; // середина списка
    blocks.forEach((b, idx) => {
      tiersEl.appendChild(renderTier(b.tier, b.ti, b.items));
      if (idx === adAfter) tiersEl.appendChild(renderAd());
    });
    if (!blocks.length) tiersEl.appendChild(renderAd());
    renderFooter();
    renderCredits();
    renderDonate();
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
  // Ширину ячейки и gap МЕРЯЕМ зондом, а не берём 8.2cqw жёстко — чтобы
  // разбивка на ряды учитывала медиазапросы (на телефоне ячейки крупнее).
  function itemsPerRow() {
    const cs = getComputedStyle(stage);
    const contentW = stage.clientWidth - parseFloat(cs.paddingLeft || 0) - parseFloat(cs.paddingRight || 0);
    if (!contentW || contentW < 60) return 0; // сцена ещё не разложена
    const cqw = contentW / 100;
    let cellW = 8.2 * cqw, colGap = 0.55 * cqw, panelPad = 0.8 * cqw;
    // зонд: настоящая .tier-items с одной .cell внутри
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
    const innerW = contentW - 2 * panelPad - 2; // -2 на рамку панели
    const per = Math.floor((innerW + colGap) / (cellW + colGap) + 1e-6);
    return Math.max(1, per);
  }

  // Есть ли у тира карточка автора. Карточка рисуется только вместе с
  // логотипом: без него в макете нет центрального разрыва, вокруг которого
  // построена вся раскладка карточки. Определение живёт в js/migrate.js —
  // там же, где карточки проставляются, чтобы условие было одно на оба места.
  const hasCard = (MIGRATE && MIGRATE.hasCard) ||
    (tier => !!(tier && tier.logo && tier.card &&
      (tier.card.role || tier.card.handle || tier.card.comment)));

  // Одна половина карточки: подпись сверху, значение снизу. Обе половины
  // устроены одинаково и различаются только классом-модификатором и тем, что
  // в значение можно доложить иконку (телеграм у ника).
  function cardSide(mod, labelText, valueText, valueClass, onEdit, extra) {
    const side = document.createElement("div");
    side.className = "card-side card-side--" + mod;

    const labelRow = document.createElement("div");
    labelRow.className = "card-row card-row--label";
    const label = document.createElement("span");
    label.className = "card-label";
    label.textContent = labelText;
    labelRow.appendChild(label);

    const valueRow = document.createElement("div");
    valueRow.className = "card-row card-row--value";
    const value = document.createElement("span");
    value.className = valueClass;
    value.textContent = valueText;
    value.spellcheck = false;
    value.addEventListener("blur", () => { onEdit(value.textContent.trim()); save(); });
    valueRow.appendChild(value);
    if (extra) valueRow.appendChild(extra);

    side.appendChild(labelRow);
    side.appendChild(valueRow);
    return { side, label, value };
  }

  // Карточка автора — плашка логотипного тира, у которой бруски по бокам от
  // логотипа наполнены содержимым: слева роль и ник, справа комментарий.
  function renderCardBand(tier, band) {
    band.classList.add("tier-band--card");
    const card = tier.card;

    const left = cardSide(
      "role", card.role || "", card.handle || "", "card-handle",
      v => { card.handle = v; },
      (() => {
        const tg = document.createElement("img");
        tg.className = "card-tg";
        tg.src = "assets/icon-telegram.png";
        tg.width = 40; tg.height = 34;
        tg.alt = "";
        return tg;
      })(),
    );
    // Роль тоже правится, поэтому подпись слева — не статичный текст.
    left.label.classList.add("card-role");
    left.label.spellcheck = false;
    left.label.addEventListener("blur", () => { card.role = left.label.textContent.trim(); save(); });
    left.label.addEventListener("keydown", e => {
      if (e.key === "Enter") { e.preventDefault(); left.label.blur(); }
    });
    left.value.addEventListener("keydown", e => {
      if (e.key === "Enter") { e.preventDefault(); left.value.blur(); }
    });

    const logo = document.createElement("img");
    logo.className = "band-logo";
    logo.src = tier.logo;
    logo.alt = tier.label || "";
    logo.onerror = () => { tier.logo = ""; save(); render(); };

    const right = cardSide(
      "comment", tx("card.comment"), card.comment || "", "card-comment",
      v => { card.comment = v; },
    );
    // Комментария нет — прячем подпись, но не саму панель: без правой половины
    // карточка теряет симметрию и логотип уезжает с центра. В режиме
    // редактирования подпись возвращается, иначе неясно, куда писать.
    if (!card.comment) right.side.classList.add("card-side--empty");
    // Комментарий многострочный: Enter внутри него ставит перенос, а правка
    // завершается уходом фокуса. Поэтому обработчика keydown здесь нет.

    band.appendChild(left.side);
    band.appendChild(logo);
    band.appendChild(right.side);
  }

  // Плашка-заголовок тира. Инструменты/редактирование — только у первой
  // плашки (isFirst); у плашек-продолжений логотип/название повторяются,
  // но без кнопок и без contenteditable.
  // Плашка, в которой ничего нет, — просто светлая полоса поперёк тирлиста.
  // Такие тиры в базе есть: без логотипа и с пустой подписью.
  function bandIsEmpty(tier) {
    return !tier.logo && !hasCard(tier) && !String(tier.label || "").trim();
  }

  function renderBand(tier, ti, isFirst) {
    // Гостю пустая полоса не нужна. Админу она остаётся всегда: внутри неё
    // живут кнопки тира, и без плашки он не смог бы ни вернуть надпись, ни
    // подвинуть или удалить сам тир.
    if (bandIsEmpty(tier) && !stage.classList.contains("editing")) return null;

    const band = document.createElement("div");
    band.className = "tier-band";

    // Карточку показываем только на первой плашке тира: у плашек-продолжения
    // она повторяла бы роль и комментарий на каждом ряду переполнения.
    if (isFirst && hasCard(tier)) {
      renderCardBand(tier, band);
    } else if (tier.logo) {
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
        // В режиме редактирования плашка становится contenteditable, но без
        // подсказки это ниоткуда не следует.
        label.title = tx("tier.rename");
        // Пустая плашка видна только админу, и по ней не понять, что это поле
        // ввода. Подсказку рисует CSS через ::before, поэтому в тексте её нет
        // и она не уедет в сохранённое состояние.
        label.dataset.placeholder = tx("tier.labelEmpty");
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

    // Разбиваем ВИДИМЫЕ предметы тира на ряды: каждый ряд — отдельная плашка-тир
    // со своей шапкой и небольшим отступом, чтобы тир «не удваивался» в одной
    // полосе при переполнении. Данные тира не меняем — это только верстка.
    const per = itemsPerRow();
    const chunks = [];
    if (per > 0 && items.length > per) {
      for (let i = 0; i < items.length; i += per) chunks.push(items.slice(i, i + per));
    } else {
      chunks.push(items.slice());
    }
    if (!chunks.length) chunks.push([]); // пустой тир — одна плашка

    chunks.forEach((chunk, ci) => {
      const isFirst = ci === 0;
      const isLast  = ci === chunks.length - 1;

      const group = document.createElement("div");
      group.className = "tier-rowgroup";
      const band = renderBand(tier, ti, isFirst);
      if (band) group.appendChild(band);

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
    // Предметов больше сотни, а на экране телефона видно два-три ряда.
    // Ленивая загрузка и асинхронное декодирование не дают браузеру держать
    // в памяти сразу все распакованные иконки. Верхние ряды — исключение:
    // они видны сразу, и ленивая загрузка только оттягивала их появление.
    if (eagerIconBudget > 0) {
      eagerIconBudget--;
      img.loading = "eager";
      // Через атрибут, а не через свойство: Safari понимает fetchpriority
      // только с 17.2, и на более старых присваивание img.fetchPriority
      // создало бы бесполезное свойство объекта вместо атрибута.
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

    // значок NEW — новый/изменённый предмет (виден всем, попадает в PNG)
    if (item.flag) {
      const nb = document.createElement("span");
      nb.className = "cell-new";
      nb.textContent = "NEW";
      cell.appendChild(nb);
    }
    // значок «?» — цена под вопросом. Независим от NEW: у предмета могут
    // гореть оба сразу, NEW слева, «?» справа. Старые сохранения поля не
    // имеют — undefined тоже ложь, поэтому миграция не нужна.
    if (item.wip) {
      const wb = document.createElement("span");
      wb.className = "cell-new cell-wip";
      wb.textContent = "?";
      wb.title = tx("cell.wipTitle");
      cell.appendChild(wb);
    }

    // всплывающая подсказка при наведении: ТОЛЬКО название.
    // Описание показывается в отдельном окне по клику (openViewModal).
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

    // edit controls
    const edit = document.createElement("div");
    edit.className = "cell-edit";
    edit.appendChild(miniBtn("✎", tx("item.edit"), e => { e.stopPropagation(); openModal(item.id); }));
    edit.appendChild(miniBtn("✕", tx("modal.delete"), e => { e.stopPropagation(); deleteItem(item.id); }));
    cell.appendChild(edit);

    // Клик в режиме редактирования → окно редактирования (админ).
    // Обычный клик (просмотр) → окно с предметом: иконка, цена, название, описание.
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

  // ============================================================
  //  AD BLOCK (реклама в середине тирлиста)
  // ============================================================
  function renderAd() {
    const ad = document.createElement("section");
    ad.className = "ad-block";
    const adUrl = normalizeHref(state.ad.link, "");
    const hasLink = !!adUrl;
    if (hasLink) ad.classList.add("has-link");

    // Скрытая ссылка: клик по картинке/тексту/значку открывает URL (в обычном
    // режиме). Сам URL в тексте не показываем — его пишет админ как захочет.
    const openLink = (e) => {
      if (!hasLink || stage.classList.contains("editing")) return;
      if (e) e.preventDefault();
      window.open(adUrl, "_blank", "noopener");
    };
    // Вся карточка рекламы — одна кликабельная зона: клик в ЛЮБОЙ части
    // (любая точка картинки, поля вокруг неё, текст) открывает ссылку в
    // обычном режиме. Один обработчик на блок — без двойного срабатывания.
    ad.addEventListener("click", openLink);
    // значок-индикатор «это ссылка» — всегда в углу самой карточки рекламы,
    // а не поверх картинки: на краю баннера он не залезает на макет рекламы
    const makeBadge = () => {
      const b = document.createElement("span");
      b.className = "ad-link-badge";
      // сам глиф цепочки рисует CSS (фоновая SVG-картинка) — эмодзи здесь нет
      b.setAttribute("aria-label", tx("ad.linkLabel"));
      b.title = tx("ad.isLink");
      return b;
    };

    const chip = document.createElement("span");
    chip.className = "ad-chip";
    chip.textContent = tx("ad.chip");
    ad.appendChild(chip);

    if (state.ad.image) {
      const wrap = document.createElement("div");
      wrap.className = "ad-img-wrap";
      const img = document.createElement("img");
      img.className = "ad-img";
      img.src = state.ad.image;
      img.alt = tx("ad.imageAlt");
      img.draggable = false;
      wrap.appendChild(img);
      ad.appendChild(wrap);
    }

    const txt = document.createElement("div");
    txt.className = "ad-text";
    txt.textContent = state.ad.text || "";
    txt.spellcheck = false;
    txt.addEventListener("blur", () => { state.ad.text = txt.textContent.trim(); save(); });
    txt.addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); txt.blur(); } });
    ad.appendChild(txt);

    // значок-ссылка крепится к самой карточке — её угол одинаков и с картинкой,
    // и без неё (позиционируется от .ad-block, у которого position: relative)
    if (hasLink) ad.appendChild(makeBadge());

    const tools = document.createElement("div");
    tools.className = "ad-tools edit-only";
    tools.appendChild(toolBtn(tx("ad.banner"), tx("ad.bannerTitle"), () => $("#adImgFile").click()));
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

  // ============================================================
  //  CREDITS (команда тирлиста)
  // ============================================================
  // Роли, которые уже показаны карточками автора. Дублировать их внизу незачем:
  // в макете полоски титров нет вообще, и она остаётся только ради тех, кому
  // карточки не досталось — карточек три, а в команде людей больше.
  // Ник и роль нормализуем одинаково: в титрах человек записан именем без
  // собачки («mksvtn»), а в карточке — ником с ней («@mksvtn»). Без этого
  // владелец показывался бы дважды: карточкой и строкой титров.
  const normCredit = s => String(s || "").trim().toLowerCase().replace(/^@/, "");

  function shownInCards() {
    const shown = new Set();
    state.tiers.filter(hasCard).forEach(t => {
      [t.card.role, t.card.handle].map(normCredit).filter(Boolean).forEach(v => shown.add(v));
    });
    return shown;
  }

  function renderCredits() {
    creditsEl.innerHTML = "";
    const shown = shownInCards();
    const rest = state.credits.filter(cr =>
      !shown.has(normCredit(cr.role)) && !shown.has(normCredit(cr.name)));
    // Все роли разъехались по карточкам — страница совпадает с макетом.
    creditsEl.hidden = rest.length === 0 && !stage.classList.contains("editing");
    rest.forEach((cr) => {
      const idx = state.credits.indexOf(cr);
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

  // ============================================================
  //  FOOTER (ссылки соцсетей — редактируемые: текст + URL)
  // ============================================================
  function renderFooter() {
    footerEl.innerHTML = "";
    state.footer.forEach((lnk, idx) => {
      const a = document.createElement("a");
      a.className = "flink";
      // Без адреса ссылку НЕ делаем кликабельной: анкер без href ведёт себя
      // как текст, а не швыряет посетителя на несуществующую страницу.
      const url = normalizeHref(lnk.href, lnk.sub);
      if (url) a.href = url; else a.classList.add("flink-nourl");
      a.target = "_blank";
      a.rel = "noopener";
      // в режиме редактирования ссылка не открывается — можно править текст
      a.addEventListener("click", e => { if (stage.classList.contains("editing")) e.preventDefault(); });

      // Аватарка. Ссылки без неё — рабочее состояние, а не ошибка: сетка
      // держит колонку текста на месте независимо от наличия картинки.
      if (lnk.icon) {
        const icon = document.createElement("img");
        icon.className = "fl-icon";
        icon.src = lnk.icon;
        icon.width = 46; icon.height = 46;
        icon.alt = "";
        icon.onerror = () => { lnk.icon = ""; save(); render(); };
        a.appendChild(icon);
      } else {
        // Пустое место вместо аватарки: без него колонки текста у ссылок с
        // картинкой и без неё разъезжаются по горизонтали.
        const gap = document.createElement("span");
        gap.className = "fl-icon fl-icon-empty";
        a.appendChild(gap);
      }

      const text = document.createElement("span");
      text.className = "fl-text";

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
        // Сохраняем уже нормализованным, чтобы в базе копились рабочие адреса.
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
      const iconBtn = document.createElement("button");
      iconBtn.textContent = "🖼";
      iconBtn.title = tx("footer.editIcon");
      iconBtn.addEventListener("click", e => {
        e.preventDefault(); e.stopPropagation();
        pickFooterIcon(idx);
      });
      tools.appendChild(iconBtn); tools.appendChild(urlBtn); tools.appendChild(del);

      text.appendChild(title);
      text.appendChild(sub);
      a.appendChild(text);
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
    const item = { id: uid(), name: "Item", value: "0", icon: DEFAULT_ICON, type: "f", demand: "", trend: "", desc: "", flag: true, wip: false };
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
    const tid = tierLogoTarget;
    tierLogoTarget = null;
    fileToSmallDataURL(file, 160, 0.85).then(du => uploadDataUrl(du)).then(url => {
      const t = findTier(tid);
      if (t && url) { t.logo = url; save(); render(); }
    });
    e.target.value = "";
  });

  // ---------- footer avatar upload ----------
  // 96px по большей стороне: аватарка рисуется 3.39cqw — это ~35px при ширине
  // сцены 1040px, поэтому даже на экране с удвоенной плотностью запаса хватает,
  // а лишние килобайты в состоянии не копятся.
  let footerIconTarget = null;
  function pickFooterIcon(idx) {
    footerIconTarget = idx;
    $("#footerIconFile").click();
  }
  $("#footerIconFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file || footerIconTarget === null) return;
    const idx = footerIconTarget;
    footerIconTarget = null;
    fileToSmallDataURL(file, 96, 0.85).then(du => uploadDataUrl(du)).then(url => {
      const lnk = state.footer[idx];
      if (lnk && url) { lnk.icon = url; save(); render(); }
    });
    e.target.value = "";
  });

  // ---------- ad image upload ----------
  $("#adImgFile").addEventListener("change", e => {
    const file = e.target.files[0];
    if (!file) return;
    // 1280px по большей стороне: ширина сцены ограничена 1040px, блок рекламы —
    // ~986px, поэтому баннер остаётся чётким на всю ширину блока. Прежние 720px
    // физически не давали баннеру заполнить блок, как бы его ни растягивал CSS.
    fileToBudgetedDataURL(file, [[1280, 0.85], [1280, 0.7], [1024, 0.7], [800, 0.65]], 460000)
      .then(du => uploadDataUrl(du))
      .then(url => {
        if (url) { state.ad.image = url; save(); render(); }
      });
    e.target.value = "";
  });

  // ============================================================
  //  FILTERS (Фрукты / Мутации / Пермы / Пассы / Скины)
  // ============================================================
  const FILTER_KEYS = ["fruits", "mutations", "perms", "passes", "skins"];
  const allFiltersOn = () => FILTER_KEYS.every(k => state.filters[k]);
  const filtersEl = $("#filters");
  // Подсветка чипов под текущие фильтры. Само скрытие предметов/тиров —
  // в render(): предметы переупаковываются, пустые тиры пропадают.
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
      // нельзя выключить всё разом — хотя бы одна категория остаётся
      if (!FILTER_KEYS.some(k => state.filters[k])) {
        state.filters[key] = true;
        return;
      }
    }
    save();
    render(); // перерисовываем: предметы переупаковываются, пустые тиры пропадают
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
    $("#mDesc").value = it.desc || "";
    $("#mNew").checked = !!it.flag;
    $("#mWip").checked = !!it.wip;
    $("#mIconPreview").src = it.icon || DEFAULT_ICON;
    setType(it.type || "f");
    setSeg("#mDemand", it.demand || "");
    setSeg("#mTrend", it.trend || "");
    modal.hidden = false;
    setTimeout(() => $("#mName").focus(), 30);
  }
  function closeModal() { modal.hidden = true; editingId = null; }

  // ----- Окно ПРОСМОТРА предмета (для всех посетителей) -----
  // Показывает иконку, название, цену и описание. Открывается кликом по
  // предмету в обычном режиме (без редактирования).
  const viewModal = $("#viewModal");
  function openViewModal(iid) {
    const found = findItem(iid);
    if (!found) return;
    const it = found.item;
    $("#vIcon").src = it.icon || DEFAULT_ICON;
    $("#vName").textContent = (it.name || "").trim() || tx("view.noName");
    $("#vValue").textContent = it.value || "—";
    const badge = $("#vBadge");
    if (it.type) {
      badge.src = "assets/badge-" + it.type + ".png";
      badge.alt = it.type.toUpperCase();
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
    const ds = (it.desc || "").trim();
    const descEl = $("#vDesc");
    descEl.textContent = ds || tx("view.noDesc");
    descEl.classList.toggle("empty", !ds);
    viewModal.hidden = false;
  }
  function closeViewModal() { viewModal.hidden = true; }
  $("#viewClose").addEventListener("click", closeViewModal);
  viewModal.addEventListener("click", e => { if (e.target === viewModal) closeViewModal(); });

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
    it.flag = $("#mNew").checked;
    it.wip = $("#mWip").checked;
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
    if (e.key !== "Escape") return;
    if (!modal.hidden) closeModal();
    if (!viewModal.hidden) closeViewModal();
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
    document.querySelectorAll(".tier-label:not(.cont-label), #tlDate, .ad-text, .cr-role, .cr-name, .fl-title, .fl-sub, .card-role, .card-handle, .card-comment").forEach(el => {
      el.contentEditable = on ? "true" : "false";
    });
  }
  // Перерисовываем при смене режима: пустые тиры появляются (для наполнения)
  // или прячутся, плюс обновляются элементы редактирования.
  editToggle.addEventListener("change", render);

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
    if (confirm(tx("msg.confirmReset"))) {
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
        if (!data.tiers) throw new Error(tx("msg.noTiersField"));
        const d = defaultState();
        state = Object.assign({}, d, data);
        state.ad = Object.assign({}, d.ad, data.ad || {});
        state.donate = Object.assign({}, d.donate, data.donate || {});
        state.filters = Object.assign({}, d.filters, data.filters || {});
        if (!Array.isArray(state.credits) || !state.credits.length) state.credits = d.credits;
        if (!Array.isArray(state.footer) || !state.footer.length) state.footer = d.footer;
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

  // ============================================================
  //  ДИАГНОСТИКА ЭКСПОРТА (?debug=1)
  // ------------------------------------------------------------
  //  Экспорт ломается только на iPhone, а устройства под рукой нет и консоль
  //  Safari оттуда не достать. По ?debug=1 сайт показывает панель, в которую
  //  экспорт пишет каждый шаг: тестеру достаточно прислать скрин или нажать
  //  «Копировать». Без параметра ничего не создаётся и не считается.
  // ============================================================
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

  // Проба готового холста: берём центральную четверть каждой иконки и смотрим
  // разброс яркости. У настоящей иконки он большой; если иконка не нарисовалась,
  // там ровный фон ячейки — разброс около нуля. Это и отличает «иконок нет»
  // от «иконки есть, но тусклые».
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

  // ----- Download PNG -----
  // html2canvas весит ~200 КБ и нужен только админу при экспорте картинки.
  // Раньше он подключался тегом <script> на каждой загрузке — телефон тратил
  // на его разбор время и память впустую. Теперь грузим по требованию.
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

  // iOS (Safari и любой браузер на iPhone/iPad — все на WebKit) ведёт себя
  // иначе, и именно из-за этого экспорт «не скачивался»:
  //   1) «жест пользователя» живёт ~5 секунд. Рендер на телефоне занимает
  //      7–12 с (шрифты + догрузка 70 иконок + 200 КБ html2canvas + сам
  //      рендер), поэтому клик по <a download> происходил уже вне жеста и
  //      WebKit молча его отбрасывал: ничего не скачивалось, ошибки не было.
  //   2) data:-URL с атрибутом download на iOS не скачивается вообще —
  //      менеджер загрузок Safari понимает только blob:.
  //   3) <a>, не вставленный в документ, кликается ненадёжно.
  // Поэтому на iOS сохранение вынесено во ВТОРОЕ (короткое) нажатие: к нему
  // картинка уже готова, жест свежий, и работает либо системное «Поделиться»
  // (сохранить в Фото/Файлы), либо обычная загрузка blob:.
  const isIOS = () => /iP(hone|ad|od)/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1); // iPadOS

  // Пиксельный бюджет холста. Пик памяти при экспорте — это холст
  // (4 байта/пиксель) + буфер PNG + клон DOM, который делает html2canvas.
  // В альбомной ориентации сцена крупнее (797×1456 CSS против 390×1347):
  // при жёстком scale:2 холст выходил 1594×2912 = 4.6 Мп ≈ 17.7 МБ, и вкладка
  // на iPhone падала по памяти. Теперь масштаб подбирается под бюджет.
  function exportScale(el) {
    const r = el.getBoundingClientRect();
    const area = Math.max(1, r.width * r.height);
    const mobile = isIOS() || (window.matchMedia && matchMedia("(pointer: coarse)").matches);
    const budget = mobile ? 3.5e6 : 12e6;
    return Math.max(1, Math.min(2, Math.sqrt(budget / area)));
  }

  // Иконки ниже сгиба помечены loading="lazy" и ещё не загружены — для
  // экспорта нужны все сразу, иначе часть предметов выйдет пустой. Но
  // форсировать все 70 разом = пик декодирования на телефоне, поэтому
  // догружаем партиями.
  async function eagerLoadStageImages() {
    // decode() дожидается не только загрузки, но и раскодирования: у иконок
    // стоит decoding="async", и событие load приходит раньше, чем картинку
    // реально можно рисовать на холсте.
    const decoded = img => (img.decode ? img.decode().catch(() => {}) : Promise.resolve());
    // Берём ВСЕ картинки сцены, а не только помеченные lazy: верхние ряды
    // теперь грузятся eager, и по фильтру [loading="lazy"] они бы выпали из
    // ожидания — экспорт мог начаться до того, как они раскодированы.
    // Для уже загруженных это быстрый путь через img.complete.
    const imgs = Array.from(stage.querySelectorAll("img"));
    for (let i = 0; i < imgs.length; i += 12) {
      await Promise.all(imgs.slice(i, i + 12).map(img => {
        img.loading = "eager";
        // complete=true при naturalWidth=0 — картинка уже отвалилась (404).
        // Ждать её событий бессмысленно: они уже прошли, и экспорт зависал
        // навсегда на «Рендер…». Плюс таймаут на случай гонки, когда
        // загрузка успела закончиться между сменой loading и подпиской.
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

  // html2canvas не берёт пиксели из уже отрисованных <img>. На каждый URL он
  // заводит НОВЫЙ Image(), вешает crossOrigin="anonymous" (мы просим useCORS)
  // и ждёт его load не дольше imageTimeout — не успел, промис отклоняется, и
  // предмет попадает в PNG пустым. Именно так на айфоне терялась часть иконок;
  // на десктопе повторить не удалось, там всё берётся из кэша мгновенно.
  //
  // Поэтому перед снимком перерисовываем уже показанные картинки в data:-URL и
  // подменяем ссылки в клоне: html2canvas получает их мгновенно, без сети,
  // кэша, CORS и таймаутов. Заодно ужимаем до того размера, в котором картинка
  // реально попадёт в PNG — html2canvas тогда декодирует маленькие копии, а не
  // исходники (среди иконок попадаются 720×405 на ячейку в 79 px).
  // Что не удалось (чужой домен без CORS — холст «протухает»), оставляем как
  // есть: хуже, чем было, не станет.
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
        // Только PNG. toDataURL("image/webp") экономит память, но на части
        // версий Safari он не поддержан и возвращает мусор вместо картинки —
        // лишняя развилка на устройстве, которое здесь не проверить.
        const url = canvas.toDataURL("image/png");
        // Когда кодирование не удалось (нулевой холст, нехватка памяти,
        // неподдержанный формат), Safari отдаёт строку "data:,". Она не
        // подходит под /^data:image\//, поэтому html2canvas принимает её за
        // чужой домен, вешает crossOrigin="anonymous" — и картинка не
        // грузится совсем. Без этой проверки в экспорте пропали ВСЕ картинки
        // разом, а не только те, что не успели загрузиться.
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
        // Холст протух от чужой картинки и уже не отдаст toDataURL — берём новый.
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
    // Подмена оправдана, только если удалась почти для всех. Если холст на
    // этом устройстве не отдаёт картинки, честнее не подменять ничего и дать
    // html2canvas грузить по обычным ссылкам, как он делал раньше.
    if (tried && ok < tried * 0.9) map.clear();
    return map;
  }

  // Даём браузеру доложить раскладку перед снимком. requestAnimationFrame на
  // скрытой вкладке не вызывается вообще: если во время экспорта свернуть
  // браузер или заблокировать экран, кнопка навсегда залипала на «Рендер…».
  // Поэтому ждём кадр, но не дольше секунды.
  function nextFrames() {
    return new Promise(res => {
      const t = setTimeout(res, 1000);
      requestAnimationFrame(() => requestAnimationFrame(() => { clearTimeout(t); res(); }));
    });
  }

  // toBlob вместо toDataURL: base64-строка портретного экспорта весит 3 млн
  // символов = 6 МБ в памяти (JS-строки UTF-16), альбомного — вдвое больше,
  // и присваивание в a.href копирует её ещё раз. Blob этого не требует.
  function canvasToBlob(canvas) {
    return new Promise((resolve, reject) => {
      if (!canvas.toBlob) { // очень старый Safari
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
    document.body.appendChild(a); // WebKit не кликает по узлу вне документа
    a.click();
    a.remove();
    // Отзывать URL сразу нельзя: iOS начинает скачивание асинхронно.
    setTimeout(() => {
      if (pendingBlobUrl) { URL.revokeObjectURL(pendingBlobUrl); pendingBlobUrl = null; }
    }, 60000);
  }

  // Вызывать ТОЛЬКО синхронно из обработчика клика — Web Share требует
  // непросроченный жест пользователя.
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
  let readyBlob = null;    // готовая картинка, ждущая второго нажатия (iOS)
  let readyTimer = null;
  let exporting = false;   // на время экспорта запрещаем пересборку сцены
  let pendingReflow = false;

  function resetPngButton() {
    clearTimeout(readyTimer);
    readyBlob = null;
    btnPng.textContent = PNG_LABEL;
    btnPng.title = PNG_TITLE;
    btnPng.disabled = false;
    btnPng.classList.remove("png-ready");
    // Раньше подсказку искали по подстроке текста — при смене языка проверка
    // молча переставала срабатывать. Помечаем её флагом.
    if (savedHint.dataset.pngHint) { savedHint.textContent = ""; delete savedHint.dataset.pngHint; }
  }

  btnPng.addEventListener("click", async () => {
    // Второе нажатие на iOS: картинка уже готова — сохраняем в свежем жесте.
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
      await eagerLoadStageImages();
      if (DEBUG) {
        const all = Array.from(stage.querySelectorAll("img"));
        dbg("после догрузки: " + all.filter(i => i.naturalWidth).length + "/" + all.length +
            " с пикселями, " + (Date.now() - t0) + " мс");
      }
      // Иконки, которые так и не приехали, выйдут пустыми — лучше сказать об
      // этом сразу, чем отдать человеку PNG с дырками и промолчать.
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
          // Подменяем ссылки на data:-URL, чтобы html2canvas не качал иконки
          // заново по сети (см. комментарий у inlineStageImages).
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
      // Освобождаем холст сразу — на телефоне это десятки мегабайт.
      canvas.width = canvas.height = 0;
      canvas = null;
      if (broken) {
        alert(tx("msg.iconsMissing") + broken + tx("msg.iconsMissingTail"));
      }
      if (isIOS()) {
        // Скачивание должно уйти в НОВОМ жесте, иначе WebKit его проглотит.
        // Шаг нужно объяснить: молчаливая смена подписи читается как «ничего
        // не произошло», и человек просто уходит с готовой картинкой в руках.
        readyBlob = blob;
        btnPng.textContent = tx("png.save");
        btnPng.title = tx("png.readyTitle");
        btnPng.disabled = false;
        btnPng.classList.add("png-ready");
        savedHint.textContent = tx("png.readyHint");
        savedHint.dataset.pngHint = "1";
        readyTimer = setTimeout(resetPngButton, 120000);
        return; // finally ниже всё равно отработает
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
      editToggle.checked = wasEditing;
      applyEditMode();
      if (!readyBlob) { btnPng.textContent = PNG_LABEL; btnPng.disabled = false; }
      if (pendingReflow) { pendingReflow = false; reflowUntilStable(); }
    }
  });

  // ============================================================
  //  LIKE BUTTON (глобальный счётчик лайков для всех посетителей)
  // ============================================================
  // Счётчик общий — лежит в БД (таблица likes), меняется через /api/like.php.
  // Любой посетитель может
  // поставить лайк (без входа). Чтобы один браузер не накручивал, запоминаем
  // факт лайка в localStorage и разрешаем переключение лайк/не-лайк (±1).
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

  // короткий «всплеск» сердечка при клике
  function popLike() {
    if (!likeBtn) return;
    likeBtn.classList.remove("pop");
    void likeBtn.offsetWidth; // перезапустить CSS-анимацию
    likeBtn.classList.add("pop");
  }

  // Отправка лайка на свой PHP-эндпоинт (атомарный инкремент в БД).
  //   true  — записано;
  //   false — сервер отклонил (нужен откат UI);
  //   null  — сеть недоступна (оффлайн, оставляем оптимистичный счётчик).
  async function sendLike(dir) {
    try {
      const r = await fetch(API_LIKE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ dir }),
      });
      return r.ok ? true : false;
    } catch (e) { /* оффлайн — считаем локальным успехом */ }
    return null;
  }

  function toggleLike() {
    const willLike = !hasLiked;
    const dir = willLike ? 1 : -1;
    // Оптимистично обновляем UI; следующий опрос сверит счётчик с сервером.
    setLiked(willLike);
    popLike();
    likeCount = Math.max(0, likeCount + dir);
    renderLike();

    sendLike(dir).then(ok => {
      if (ok === false) { // запись не прошла — откат
        setLiked(!willLike);
        likeCount = Math.max(0, likeCount - dir);
        renderLike();
      }
    });
  }

  if (likeBtn) likeBtn.addEventListener("click", toggleLike);
  renderLike();

  // ============================================================
  //  ДОНАТ (ссылки + QR) — редактируются прямо на сайте
  // ------------------------------------------------------------
  //  Ссылки лежат в state.donate и публикуются вместе с тирлистом,
  //  поэтому правка видна всем без правки кода. Кнопки редактирования
  //  внутри окна помечены .edit-only — видны админу в режиме
  //  «Редактирование» (как 🔗 у ссылок в подвале).
  // ============================================================
  const donateModal = $("#donateModal");

  // Подставить актуальные ссылки/QR в окно доната. Вызывается из render().
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

    // В режиме редактирования ссылки не открываются — иначе клик по кнопке
    // «DonationAlerts» уводил бы админа со страницы вместо правки.
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

    // Новый QR — картинка уходит в файл через upload.php, как иконки предметов.
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

  // ============================================================
  //  АВТОСКРЫТИЕ ПЛАВАЮЩИХ КНОПОК (лайк + донат)
  // ------------------------------------------------------------
  //  На телефоне кнопки перекрывают нижние ряды тирлиста. Прячем их за
  //  правый край при прокрутке ВНИЗ и возвращаем при прокрутке ВВЕРХ.
  //  Класс вешаем на <body>, само смещение — в CSS внутри медиазапроса
  //  (max-width: 640px), поэтому на десктопе кнопки не двигаются.
  // ============================================================
  (function initFabAutoHide() {
    const THRESHOLD = 8;     // мелкое дрожание пальца не переключает состояние
    const TOP_ZONE = 90;     // у самого верха кнопки всегда видны
    const BOTTOM_ZONE = 60;  // у самого низа — всегда спрятаны
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
        // В самом низу держим кнопки спрятанными. Раньше здесь они наоборот
        // показывались, и на телефоне это выглядело так: прокрутил вниз —
        // спрятались, докрутил до конца — вернулись. Плюс «резинка» iOS в конце
        // страницы даёт короткий рывок вверх, который читался как прокрутка
        // вверх и тоже выдёргивал кнопки обратно.
        if (atBottom) { setHidden(true); return; }
        setHidden(dy > 0);
      });
    }
    window.addEventListener("scroll", onScroll, { passive: true });
    // Открытое окно (донат / предмет) — кнопки не должны «выезжать» из-под него
    // при прокрутке содержимого модалки.
    window.addEventListener("orientationchange", () => { lastY = window.pageYOffset || 0; setHidden(false); });
  })();

  // ============================================================
  //  АВТОРИЗАЦИЯ (пароль + cookie-сессия) и синхронизация
  // ============================================================
  function setAdminMode(admin) {
    isAdmin = admin;
    const loginBtn  = $("#btnLogin");
    const badge     = $("#adminBadge");
    const tbEdit    = $("#tbEdit");
    const tbToggles = $("#tbToggles");
    const tbActions = $("#tbAdminActions");
    const tbPublish = $("#tbPublish");
    const tbPng     = $("#tbPng");

    if (admin) {
      if (loginBtn)  loginBtn.hidden  = true;
      if (badge)     badge.hidden     = false;
      if (tbEdit)    tbEdit.hidden    = false;
      if (tbToggles) tbToggles.hidden = false;
      if (tbActions) tbActions.hidden = false;
      if (tbPublish) tbPublish.hidden = false;
      if (tbPng)     tbPng.hidden     = false;
      renderSaveBtn();
    } else {
      if (loginBtn)  loginBtn.hidden  = false;
      if (badge)     badge.hidden     = true;
      if (tbEdit)    tbEdit.hidden    = true;
      if (tbToggles) tbToggles.hidden = true;
      if (tbActions) tbActions.hidden = true;
      if (tbPublish) tbPublish.hidden = true;
      if (tbPng)     tbPng.hidden     = true;
      editToggle.checked = false;
      applyEditMode();
    }
    applyProtection(); // админу выделение и перетаскивание нужны, гостю — нет
    roleResolved = true;
    resolvePending();
  }

  // ---------- Слияние и применение данных с сервера ----------
  function mergeServer(data) {
    const d = defaultState();
    const merged = Object.assign({}, d, data);
    merged.ad      = Object.assign({}, d.ad,      data.ad      || {});
    merged.donate  = Object.assign({}, d.donate,  data.donate  || {});
    merged.filters = Object.assign({}, d.filters, data.filters || {});
    merged.filters.perms = false; // пермы по умолчанию скрыты — показываются только по клику
    if (!Array.isArray(merged.credits) || !merged.credits.length) merged.credits = d.credits;
    if (!Array.isArray(merged.footer)  || !merged.footer.length)  merged.footer  = d.footer;
    merged.tiers.forEach(t => { if (!t.logo && TIER_LOGOS[t.label]) t.logo = TIER_LOGOS[t.label]; });
    return migrateToPosterLayout(merged);
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
  // Когда роль входа выяснена, решаем судьбу отложенного снимка из базы:
  // админ сохраняет свои несохранённые правки, обычный зритель видит базу.
  function resolvePending() {
    if (deferredServer === null) return;
    const srv = deferredServer; deferredServer = null;
    if (isAdmin) {
      dirty = true; try { localStorage.setItem(DIRTY_KEY, "1"); } catch (e) {} renderSaveBtn();
      savedHint.textContent = tx("msg.restored");
      // Дать возможность одним кликом взять версию из базы вместо своих правок
      pendingServer = srv; showUpdateBanner();
    } else {
      applyServer(srv);
    }
  }

  // ============================================================
  //  ЧТЕНИЕ ДАННЫХ — опрос (polling) своих PHP-эндпоинтов
  // ------------------------------------------------------------
  //  Каждый посетитель периодически читает короткими запросами:
  //    1) /api/state.php  — крошечный {rev, likes} (десятки байт);
  //    2) /api/tierlist.php?rev=<n> — полный тирлист, но ТОЛЬКО когда rev
  //       изменился; ответ помечен immutable-кэшем, поэтому один и тот же rev
  //       берётся из кэша браузера без обращения к серверу.
  //  Картинки вынесены в файлы /images/<hash>, их раздаёт веб-сервер напрямую.
  //  Записи админа идут отдельно через publish() → POST /api/save.php.
  //  rev генерит сервер (save.php) и возвращает в ответе; клиент кладёт его в
  //  state._rev / lastRev. По изменению rev зрители понимают, что пора качать
  //  полные данные. Экономит трафик.
  // ============================================================
  const API_TIERLIST = "/api/tierlist.php";
  const API_STATE    = "/api/state.php";
  const API_LIKE     = "/api/like.php";
  const API_SAVE     = "/api/save.php";
  const API_SESSION  = "/api/session.php";
  const API_LOGIN    = "/api/login.php";
  const API_LOGOUT   = "/api/logout.php";
  const API_UPLOAD   = "/api/upload.php";
  const POLL_MS = 30000;
  let pollTimer = null;
  let lastRev = null;          // последний известный rev тирлиста
  let haveFullData = false;    // хотя бы раз загрузили полные данные

  // Обработка снимка данных тирлиста, пришедшего с сервера.
  function handleSnapshot(data) {
    if (!data) return;
    const merged = mergeServer(data);

    // Первый снимок после перезагрузки: защищаем локальные правки ТОЛЬКО если
    // при прошлой сессии остались реально неопубликованные изменения.
    if (!firstSnapshotHandled) {
      firstSnapshotHandled = true;
      if (bootedDirty && !sameState(merged, state)) {
        deferredServer = merged;
        if (roleResolved) resolvePending();
        return;
      }
    }
    if (deferredServer !== null) { deferredServer = merged; return; }

    // Есть свои НЕопубликованные правки → не затираем молча, показываем баннер.
    if (dirty) { pendingServer = merged; showUpdateBanner(); return; }

    applyServer(merged);
  }


  // Лёгкий опрос {rev, likes} со своего PHP-эндпоинта.
  async function fetchState() {
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      if (r.ok) return await r.json();
    } catch (e) { /* оффлайн */ }
    return null;
  }

  // Тяжёлая загрузка полного тирлиста — вызывается только при первой загрузке и
  // когда rev изменился.
  //
  // В URL добавляем ?rev=<n>: данные конкретной версии неизменны, поэтому сервер
  // отдаёт их с immutable-кэшем. Одинаковый rev → браузер отвечает из своего
  // кэша (cache:"default" даёт ему на это право), запрос до сервера не доходит.
  // Значит полные данные качаются раз на версию, а не каждые 30 сек.
  async function fetchFull(rev) {
    const q = (rev !== null && rev !== undefined && rev !== "") ? ("?rev=" + encodeURIComponent(rev)) : "";
    try {
      const r = await fetch(API_TIERLIST + q, { cache: "default" });
      if (r.ok) {
        const d = await r.json();
        if (d && d.tierlist) { handleSnapshot(d.tierlist); return true; }
      }
    } catch (e) { /* оффлайн */ }
    return false;
  }

  async function fetchSnapshot() {
    const st = await fetchState();
    if (st && typeof st.likes === "number") { likeCount = Math.max(0, st.likes); renderLike(); }

    // Полные данные тянем только если ещё ни разу не грузили ИЛИ rev поменялся.
    const need = !haveFullData || (st && st.rev !== lastRev);
    if (need) {
      const ok = await fetchFull(st ? st.rev : null);
      if (ok) { haveFullData = true; if (st) lastRev = st.rev; }
    } else if (st) {
      lastRev = st.rev;
    }
  }

  function startPolling() {
    fetchSnapshot();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(fetchSnapshot, POLL_MS);
    // Свежие цены сразу при возврате на вкладку, без ожидания интервала.
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") fetchSnapshot();
    });
  }

  // Баннер «есть свежие изменения» — когда другой админ опубликовал правки,
  // а у тебя есть свои неопубликованные. Один клик «Обновить» вместо Ctrl+F5.
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

  // Инициализация бэкенда: опрос данных + определение роли (админ по сессии) +
  // кнопки входа/выхода по паролю.
  function initBackend() {
    startPolling();
    checkSession();

    const btnLogin  = $("#btnLogin");
    const btnLogout = $("#btnLogout");

    if (btnLogin) btnLogin.addEventListener("click", async () => {
      const pw = window.prompt(tx("auth.prompt"));
      if (!pw) return;
      try {
        const r = await fetch(API_LOGIN, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ password: pw }),
        });
        const d = await r.json().catch(() => ({}));
        if (r.ok && d.ok) { setAdminMode(true); fetchSnapshot(); }
        // Бэкенд блокирует IP после пяти промахов и отвечает 429 с retry_after.
        // Показывать при этом «Неверный пароль» — обман: пароль может быть
        // верным, просто попытку сейчас не примут.
        else if (r.status === 429) { alert(tx("auth.locked") + " " + (d.retry_after || 0) + " " + tx("auth.seconds")); }
        else { alert(tx("auth.wrong")); }
      } catch (e) { alert(tx("msg.loginFailed")); }
    });

    if (btnLogout) btnLogout.addEventListener("click", async () => {
      try { await fetch(API_LOGOUT, { method: "POST" }); } catch (e) {}
      setAdminMode(false);
    });
  }

  // Кто я: спрашиваем сервер (по cookie-сессии), включаем админ-режим если да.
  async function checkSession() {
    try {
      const r = await fetch(API_SESSION, { cache: "no-store" });
      const d = await r.json();
      setAdminMode(!!d.admin);
    } catch (e) { setAdminMode(false); }
  }

  // ============================================================
  //  ЗАЩИТА КОНТЕНТА
  // ------------------------------------------------------------
  //  Гость не уносит с сайта ни текст, ни картинки: выделение, копирование,
  //  правая кнопка и перетаскивание отключены. Админу защита снимается сразу
  //  после входа — иначе он не сможет ни править подписи, ни таскать ячейки
  //  между тирами (перенос предметов работает на том же dragstart).
  //  Это заслон от обычного посетителя, а не от исходников: разметка всё равно
  //  видна через «Просмотр кода», а экран можно сфотографировать.
  // ============================================================
  function inEditable(t) {
    // Поля ввода и редактируемые области должны работать как обычно.
    return !!(t && t.closest && t.closest('input, textarea, [contenteditable="true"]'));
  }
  function applyProtection() {
    document.body.classList.toggle("protected", !isAdmin);
  }
  function setupProtection() {
    applyProtection();
    // Правая кнопка — убирает «Сохранить картинку как…» и «Копировать».
    document.addEventListener("contextmenu", e => {
      if (isAdmin || inEditable(e.target)) return;
      e.preventDefault();
    });
    // Копирование с клавиатуры и через меню браузера.
    ["copy", "cut"].forEach(type => {
      document.addEventListener(type, e => {
        if (isAdmin || inEditable(e.target)) return;
        e.preventDefault();
      });
    });
    // Перетаскивание картинки в другую вкладку или на рабочий стол.
    document.addEventListener("dragstart", e => {
      if (isAdmin) return;
      e.preventDefault();
    });
    // Движки, которые не понимают user-select: none.
    document.addEventListener("selectstart", e => {
      if (isAdmin || inEditable(e.target)) return;
      e.preventDefault();
    });
  }

  // ============================================================
  //  INIT
  // ============================================================
  // Язык применяем ДО первого render(): иначе интерфейс успевает мигнуть
  // русским, а подписи, которые ставит render (кнопка сохранения, тултипы
  // тиров), пришлось бы обновлять вторым проходом.
  applyLang();
  setupProtection();
  render();
  if (!localStorage.getItem(STORAGE_KEY)) save(); // persist seed on first run
  initBackend();
  // render() полностью перестраивает #tiers (innerHTML=""). На телефоне это
  // опасно: браузер шлёт 'resize' при сворачивании адресной строки во время
  // прокрутки (меняется только ВЫСОТА) — перерисовка сбрасывала прокрутку
  // вверх. Поэтому разметку пересобираем ТОЛЬКО при смене ШИРИНЫ; на смену
  // высоты просто подгоняем цифры без перестройки DOM.
  function safeRender() {
    try { render(); }
    catch (e) {
      // Транзитный сбой (например, поворот экрана на неустаканенном вьюпорте)
      // не должен оставлять пустую страницу — повторяем на следующем кадре.
      console.error("render failed", e);
      requestAnimationFrame(() => { try { render(); } catch (_) {} });
    }
  }
  let resizeT = null;
  let lastW = window.innerWidth;   // ширина, под которую собрана текущая разметка
  let lastPer = itemsPerRow();     // и сколько ячеек в ряду при этой ширине

  // Один проход пересчёта. Перерисовываем, только если реально изменилась
  // ширина ИЛИ вместимость ряда: на iOS размеры вьюпорта и container-query
  // единицы (cqw) обновляются НЕ одновременно, поэтому одной проверки
  // innerWidth мало — ширина уже новая, а .cell ещё старого размера.
  function reflowPass() {
    // Во время экспорта пересобирать DOM нельзя: html2canvas клонирует сцену,
    // и render() (innerHTML = "") на середине даёт битую картинку, а на
    // iPhone ещё и удваивает пик памяти. Поворот отработаем после экспорта.
    if (exporting) { pendingReflow = true; return; }
    const w = window.innerWidth;
    const per = itemsPerRow();
    if (Math.abs(w - lastW) < 2 && per === lastPer) { fitValues(); return; }
    lastW = w; lastPer = per;
    safeRender();
  }
  // Поворот экрана меняет ширину и высоту местами. Safari на iPhone шлёт
  // orientationchange/resize РАНЬШЕ, чем вьюпорт устаканится: раскладка
  // считалась по старой ширине — ряды выходили с неверным числом ячеек,
  // а иногда тирлист вовсе пропадал. Поэтому после поворота гоняем серию
  // проходов; каждый следующий перерисовывает только если размеры ещё
  // менялись, так что лишней работы нет.
  let reflowTimers = [];
  function reflowUntilStable() {
    reflowTimers.forEach(clearTimeout);
    reflowTimers = [0, 120, 320, 700, 1200].map(ms => setTimeout(reflowPass, ms));
  }

  window.addEventListener("resize", () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(reflowPass, 150); // только высота (адресная строка) → fitValues
  });
  window.addEventListener("orientationchange", reflowUntilStable);
  // Screen Orientation API — в новых iOS/Safari событие приходит надёжнее
  // устаревшего orientationchange (и после него), поэтому слушаем оба.
  if (window.screen && screen.orientation && screen.orientation.addEventListener) {
    screen.orientation.addEventListener("change", reflowUntilStable);
  }
  // visualViewport ловит случаи, когда window.resize на iOS не приходит вовсе
  // (смена размера при повороте с открытой панелью Safari).
  if (window.visualViewport) {
    window.visualViewport.addEventListener("resize", () => {
      clearTimeout(resizeT);
      resizeT = setTimeout(reflowPass, 150);
    });
  }
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(fitValues).catch(() => {});
  }
  // после загрузки картинок-бейджей ширина полосы могла измениться
  window.addEventListener("load", fitValues);
})();
