/* ============================================================
   Страница новостей. Загружает ленту, рисует карточки, фильтрует.
   Редактор админа живёт в этом же файле ниже (задача 8).
   ============================================================ */
(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const feedEl = $("#feed");
  const stateEl = $("#newsState");
  const filtersEl = $("#newsFilters");

  // Тот же ключ, что и на тирлисте (app.js, LANG_KEY) — иначе выбор языка
  // не переносился бы между страницами.
  const LANG_KEY = "nexus-lang-v1";

  // Адреса эндпоинтов — от корня. Эта же разметка отдаётся на /admin/news,
  // а он лежит на глубине 1: документ-относительный "api/news.php" оттуда
  // уехал бы в /admin/api/news.php. app.js ходит по абсолютным по той же
  // причине.

  let posts = [];
  let activeCat = "all";
  // Как в applyLang в app.js: localStorage бросает в приватном режиме Safari
  // (и в некоторых встроенных webview с отключённым хранилищем). Без try/catch
  // это исключение случилось бы прямо в теле IIFE до объявления load() ниже —
  // он бы не вызвался вообще, и посетитель получил бы пустую сцену без ленты,
  // без ошибки и без кнопки «Повторить».
  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = key => I18N.t(key, lang);

  function showState(key, withRetry) {
    feedEl.innerHTML = "";
    stateEl.hidden = false;
    stateEl.textContent = tx(key);
    if (withRetry) {
      const b = document.createElement("button");
      b.className = "btn small";
      b.textContent = tx("news.retry");
      b.addEventListener("click", load);
      stateEl.append(document.createElement("br"), b);
    }
  }

  // Карточка собирается узлами, а не строкой HTML. Текст поста приходит от
  // админа и кладётся через textContent — санитайзера здесь нет намеренно,
  // потому что вставлять нечего: innerHTML в этом файле не используется.
  //
  // withTools=false используется превью в редакторе (см. ниже): та же самая
  // функция строит и настоящую карточку в ленте, и то, что видит админ до
  // публикации, — чтобы превью не могло разойтись с реальным рендером, но при
  // этом не рисовало кнопки ✎/✕ поверх ещё не сохранённого поста.
  function cardFor(post, withTools = true) {
    const card = document.createElement("article");
    card.className = "nw-card";
    card.dataset.id = String(post.id);

    const meta = document.createElement("div");
    meta.className = "nw-meta";

    const date = document.createElement("span");
    date.className = "nw-date";
    date.textContent = NEWS.formatDate(post.published_at);
    meta.append(date);

    const cat = NEWS.CATEGORIES.find(c => c.key === post.category);
    if (cat) {
      const badge = document.createElement("span");
      badge.className = "nw-cat " + cat.cls;
      badge.textContent = tx(cat.i18n);
      meta.append(badge);
    }
    card.append(meta);

    const picked = NEWS.pickLang(post, lang);

    const h = document.createElement("h2");
    h.textContent = picked.title;
    card.append(h);

    if (post.image_url) {
      const img = document.createElement("img");

      // Ширина — число, которое реально прошло валидацию (10..100), поэтому
      // её можно класть в inline style напрямую: это не пользовательский
      // текст, а посчитанное значение. Мусор/выход за границы (пост,
      // сохранённый до появления этого поля, или что-то незнакомое) — те же
      // 100, что и на сервере при отсутствующем image_pct.
      const pctNum = Number(post.image_pct);
      const pct = Number.isFinite(pctNum) && pctNum >= 10 && pctNum <= 100 ? pctNum : 100;
      img.style.width = pct + "%";

      // Неизвестное/отсутствующее выравнивание — center, как и на сервере
      // (validate_news_post трактует пустое так же).
      const align = NEWS.ALIGNS.find(a => a.key === post.image_align) || NEWS.ALIGNS[1];

      // center + обтекание не имеет смысла: у float нет «по центру» — он
      // умеет только «к левому» или «к правому» краю. Раз админ явно выбрал
      // центр, обтекание в этом случае молча выключается и картинка остаётся
      // блочной (как при wrap=false) — это безопаснее, чем самовольно
      // прижать её к стороне, которую никто не выбирал.
      const wrap = !!post.image_wrap && align.key !== "center";

      img.className = "nw-image " + (wrap
        ? (align.key === "left" ? "nw-img-float-left" : "nw-img-float-right")
        : "nw-img-" + align.key);

      img.src = post.image_url;
      img.alt = picked.title;
      img.loading = "lazy";
      img.decoding = "async";
      // width/height — не литеральный размер отображения (тем управляет CSS:
      // .nw-image{height:auto} и inline style выше), а подсказка браузеру для
      // резервирования места под картинку, пока байты ещё не пришли — иначе
      // текст под картинкой скачет при догрузке. Пост без картинки и пост,
      // сохранённый до появления этих колонок (image_width/image_height —
      // null), не дают атрибутов вовсе, а не 0×0 — 0 обнулил бы
      // зарезервированную высоту, а не оставил бы её неизвестной.
      if (post.image_width && post.image_height) {
        img.width = post.image_width;
        img.height = post.image_height;
      }
      card.append(img);
    }

    const body = document.createElement("div");
    body.className = "nw-body";
    for (const para of NEWS.toParagraphs(picked.body)) {
      const p = document.createElement("p");
      p.textContent = para;
      body.append(p);
    }
    card.append(body);

    if (withTools && isAdmin) {
      const tools = document.createElement("div");
      tools.className = "nw-tools";
      const edit = document.createElement("button");
      edit.type = "button";
      edit.textContent = "✎";
      edit.title = tx("news.edit");
      edit.addEventListener("click", () => openEditor(post));
      const del = document.createElement("button");
      del.type = "button";
      del.className = "danger";
      del.textContent = "✕";
      del.title = tx("news.delete");
      del.addEventListener("click", () => removePost(post));
      tools.append(edit, del);
      card.append(tools);
    }

    return card;
  }

  function render() {
    const visible = activeCat === "all"
      ? posts
      : posts.filter(p => p.category === activeCat);

    // "В этой категории пока ничего нет" не подходит, когда категория вообще
    // не выбрана (activeCat === "all") — это день первый ленты, когда постов
    // нет в принципе, а не "в этом фильтре пусто". Разные строки на разные
    // причины пустоты.
    if (!visible.length) { showState(activeCat === "all" ? "news.emptyAll" : "news.empty", false); return; }

    stateEl.hidden = true;
    feedEl.innerHTML = "";
    for (const post of visible) { feedEl.append(cardFor(post)); }
  }

  function renderFilters() {
    filtersEl.innerHTML = "";
    const label = document.createElement("span");
    label.className = "tb-label";
    label.textContent = tx("news.filterLabel");
    filtersEl.append(label);

    const mk = (key, text, isAll) => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "chip" + (isAll ? " all" : "") + (activeCat === key ? " active" : "");
      b.textContent = text;
      b.addEventListener("click", () => { activeCat = key; renderFilters(); render(); });
      return b;
    };
    for (const c of NEWS.CATEGORIES) { filtersEl.append(mk(c.key, tx(c.i18n), false)); }
    filtersEl.append(mk("all", tx("news.all"), true));
  }

  async function load() {
    showState("news.loading", false);
    try {
      const r = await fetch("/api/news.php", { cache: "no-store" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const data = await r.json();
      posts = Array.isArray(data.posts) ? data.posts : [];
      renderFilters();
      render();
    } catch (e) {
      console.warn("не удалось загрузить ленту", e);
      showState("news.error", true);
    }
  }

  // Переключатель языка: тот же ключ в localStorage, что и на тирлисте,
  // поэтому выбор переносится между страницами.
  function applyLang(next) {
    lang = next;
    try { localStorage.setItem(LANG_KEY, next); } catch (_) { /* приватный режим */ }
    document.documentElement.lang = next;
    for (const node of document.querySelectorAll("[data-i18n]")) {
      node.textContent = I18N.t(node.dataset.i18n, lang);
    }
    // aria-label тоже переводимый текст (см. applyLang в app.js) — без этого
    // переключатель языка и панель фильтров остаются подписаны на языке
    // загрузки страницы.
    for (const node of document.querySelectorAll("[data-i18n-label]")) {
      node.setAttribute("aria-label", I18N.t(node.dataset.i18nLabel, lang));
    }
    // title — как data-i18n-title в app.js: рамка кроп-редактора (#neCropFrame)
    // документирует свои клавиши (стрелки/Shift+стрелки) и в title, и в
    // aria-label одной и той же строкой news.cropFrameLabel.
    for (const node of document.querySelectorAll("[data-i18n-title]")) {
      node.title = I18N.t(node.dataset.i18nTitle, lang);
    }
    for (const b of document.querySelectorAll("#langSwitch .chip")) {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    }
    renderFilters();
    render();
    // Превью зовёт cardFor() тем же путём, что и лента, поэтому переключение
    // языка обязано поменять и его — иначе оставленный на RU текст в превью
    // разошёлся бы с тем, что реально покажется на странице.
    updatePreview();
  }

  // ---------- Админ ----------
  // Разметку редактора вставляет только admin-news.php (/admin/news), он же
  // ставит этот флаг. На публичной ленте ни того, ни другого нет, и роль у
  // сервера здесь не спрашивают вовсе — раньше это был лишний запрос с
  // каждого захода ради ответа «нет» для всех, кроме одного человека.
  // Роль известна синхронно, поэтому ✎/✕ рисуются с первого же render(),
  // без второго прохода по уже показанным карточкам.
  const isAdmin = window.NX_ADMIN_PAGE === true;

  const editor = $("#newsEditor");
  let editingPost = null;
  let currentImage = "";
  let currentImageWidth = null;
  let currentImageHeight = null;
  // Data URL картинки, выбранной в этой сессии редактирования (см.
  // #neImageFile ниже) — источник, из которого можно перезалить файл под
  // новый потолок при смене ширины картинки. "" значит "источника нет":
  // либо ничего не выбирали, либо открыли существующий пост и не трогали
  // картинку — тогда смена ширины не должна ничего перезаливать (см.
  // обработчик "change" на #nePct ниже).
  let pickedImageDataUrl = "";

  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

  // ---------- Кроп-редактор картинки (между выбором файла и заливкой) ----------
  // Геометрия (перевод рамки+зума+панорамы в прямоугольник исходника) живёт в
  // NEWS.cropToSourceRect (news.js, покрыта тестами в node) — здесь только
  // canvas и pointer-обработчики, которые эту геометрию используют и рисуют.
  //
  // cropSrc — декодированный исходник, с которого рисует canvas:
  // { source: ImageBitmap|HTMLImageElement, width, height, isBitmap, objectUrl? }.
  // Держится ровно один битмап за раз (см. closeCropUI) — требование
  // "не хранить несколько полноразмерных копий" из
  // docs/superpowers/specs/2026-08-03-safari-memory-and-i18n-design.md,
  // тот же файл объясняет, почему в этом проекте к декодированной памяти
  // относятся всерьёз, а не формально.
  let cropSrc = null;
  let cropOriginalDataUrl = "";  // байты файла как есть — источник для «Без кадрирования»
  let cropOutputMime = "image/png";
  let cropViewport = { w: 0, h: 0 }; // css-размер холста редактора
  let cropZoomFit = 1;               // масштаб, вписывающий весь исходник в холст (zoom=1)
  let cropZoom = 1;                  // множитель поверх cropZoomFit, из ползунка/колеса (1..4)
  let cropPan = { x: 0, y: 0 };      // левый верхний угол картинки на холсте, css-px
  let cropFrame = { x: 0, y: 0, w: 0, h: 0 }; // рамка выделения, те же координаты
  let cropCtx = null;
  let cropDrag = null; // активный pointer-жест: {kind, pointerId, ...}

  const CROP_MIN_FRAME = 32; // минимальный размер рамки, px — чтобы её не сжали в точку
  const CROP_KEY_STEP = 5;   // шаг стрелок клавиатуры, px

  function cropEffectiveZoom() { return cropZoomFit * cropZoom; }

  // Сегмент категорий строится из того же списка, что рисует чипы фильтра —
  // добавленная в news.js категория появляется в редакторе сама.
  let editorCat = "tierlist";

  function renderCatSeg() {
    const box = $("#neCat");
    box.innerHTML = "";
    for (const c of NEWS.CATEGORIES) {
      const b = document.createElement("button");
      b.type = "button";
      b.dataset.v = c.key;
      b.className = c.key === editorCat ? "active" : "";
      b.textContent = tx(c.i18n);
      b.addEventListener("click", () => { editorCat = c.key; renderCatSeg(); updatePreview(); });
      box.append(b);
    }
  }
  function setCat(key) { editorCat = NEWS.isCategory(key) ? key : "tierlist"; renderCatSeg(); }
  function getCat() { return editorCat; }

  // Тот же сегмент, что и категории (.ne-cat-seg переиспользуется — это
  // просто "ряд кнопок-переключателей", вид не завязан на то, что именно
  // выбирают), но свой список и свой ключ по умолчанию.
  let editorAlign = "center";

  function renderAlignSeg() {
    const box = $("#neAlign");
    box.innerHTML = "";
    for (const a of NEWS.ALIGNS) {
      const b = document.createElement("button");
      b.type = "button";
      b.dataset.v = a.key;
      b.className = a.key === editorAlign ? "active" : "";
      b.textContent = tx(a.i18n);
      b.addEventListener("click", () => { editorAlign = a.key; renderAlignSeg(); updatePreview(); });
      box.append(b);
    }
  }
  function setAlign(key) { editorAlign = NEWS.isAlign(key) ? key : "center"; renderAlignSeg(); }
  function getAlign() { return editorAlign; }

  // Свободная ширина картинки, 10..100% ширины карточки — тот же range-input,
  // что рисует превью карточки в редакторе (#nePct в admin-news.php).
  let editorPct = 100;

  function updatePctOutput() {
    $("#nePctValue").textContent = editorPct + "%";
  }
  function setPct(v) {
    const n = Number(v);
    editorPct = Number.isFinite(n) && n >= 10 && n <= 100 ? Math.round(n) : 100;
    $("#nePct").value = editorPct;
    updatePctOutput();
    // Единственная точка записи editorPct (см. обработчик "input" на #nePct
    // ниже) — значит и единственная точка, откуда честно пересчитывать
    // подсказку о мыле: она зависит от того же editorPct.
    updatePctHint();
  }
  function getPct() { return editorPct; }

  // Подсказка "картинка сохранена под меньшую ширину" (#nePctHint,
  // задача 3) — не ошибка (та живёт в #neError), а совет: показывается,
  // когда админ поднимает ширину ВЫШЕ потолка, под который реально хранится
  // файл, и перезалить его нечем. currentImageWidth/currentImageHeight —
  // то, что вернул сервер при последней заливке (или что пришло с
  // сохранённого поста), а не то, что выбрано в кроп-редакторе сейчас.
  // pickedImageDataUrl пуст ровно тогда, когда "change" на #nePct ниже НЕ
  // перезаливает файл (см. его комментарий выше и openEditor()) — именно
  // в этом случае поднятая ширина растягивает старые пиксели мылом молча,
  // без него смена ширины сама подтянет файл под новый потолок.
  function updatePctHint() {
    const hint = $("#nePctHint");
    if (!hint) { return; }
    const storedLongest = currentImageWidth && currentImageHeight
      ? Math.max(currentImageWidth, currentImageHeight)
      : 0;
    const show = !!currentImage && !pickedImageDataUrl && storedLongest > 0
      && NEWS.newsImageCap(getPct()) > storedLongest;
    hint.hidden = !show;
    hint.textContent = show ? tx("news.imageStale") : "";
  }

  function setWrap(v) { $("#neWrap").checked = !!v; }
  function getWrap() { return $("#neWrap").checked; }

  // <input type="date"> работает в YYYY-MM-DD и в локальном времени.
  // Полдень, а не полночь: сдвиг часового пояса на пару часов не должен
  // перекидывать дату поста на соседний день.
  const isoDay = ms => {
    const d = new Date(ms);
    const p = n => String(n).padStart(2, "0");
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
  };
  const dayToMs = value => {
    const [y, m, d] = String(value).split("-").map(Number);
    return new Date(y, (m || 1) - 1, d || 1, 12, 0, 0).getTime();
  };

  // Больше не рисует <img> сама — превью всей карточки (см. ниже) уже
  // показывает картинку в контексте поста, отдельный образец рядом с кнопками
  // стал бы вторым, расходящимся источником правды о том же самом файле.
  function setImage(url, width, height) {
    currentImage = url || "";
    currentImageWidth = width || null;
    currentImageHeight = height || null;
    // Подсказка о мыле (#nePctHint) зависит от currentImage*/pickedImageDataUrl
    // не меньше, чем от editorPct — пересчитываем и здесь, а не только в
    // setPct(), иначе она осталась бы висеть после смены/уборки картинки.
    updatePctHint();
  }

  // Читает File как data URL — то же, что раньше делал обработчик
  // #neImageFile целиком; вынесено отдельно, потому что теперь нужно и для
  // «Без кадрирования» (байты как есть), и как запасной путь, если картинку
  // не удалось декодировать для кропа.
  function readFileAsDataURL(file) {
    return new Promise(res => {
      const fr = new FileReader();
      fr.onload = () => res(fr.result);
      fr.readAsDataURL(file);
    });
  }

  // Декодирует выбранный файл в рисуемый на canvas источник для кроп-редактора.
  //
  // createImageBitmap(file, {imageOrientation: "from-image"}) — единственный
  // способ отменить EXIF-поворот САМОСТОЯТЕЛЬНО, до того как картинка ляжет
  // на canvas: телефон пишет портретное фото как альбомное с флагом
  // Orientation в EXIF, а не физически поворачивает пиксели. Без этой опции
  // (или без самого createImageBitmap) кроп-редактор нарисует фото с айфона
  // лежащим на боку — ровно тот баг, из-за которого этот метод обязателен.
  //
  // Фолбэк на <img>, если createImageBitmap недоступен или не смог
  // декодировать конкретный файл: <img> EXIF Orientation не читает вообще
  // (это не его контракт), так что портретное фото ляжет повернутым — этот
  // путь существует только чтобы кроп-редактор продолжал работать в старых
  // браузерах (Safari < 15.4 и т.п.), а не чтобы решить проблему ориентации
  // так же хорошо, как createImageBitmap.
  async function decodeImageForCrop(file) {
    if (typeof createImageBitmap === "function") {
      try {
        const bmp = await createImageBitmap(file, { imageOrientation: "from-image" });
        return { source: bmp, width: bmp.width, height: bmp.height, isBitmap: true };
      } catch (e) {
        console.warn("createImageBitmap не смог декодировать файл, пробуем <img>", e);
      }
    }
    const objectUrl = URL.createObjectURL(file);
    try {
      const img = await new Promise((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = () => reject(new Error("image decode failed"));
        el.src = objectUrl;
      });
      return { source: img, width: img.naturalWidth, height: img.naturalHeight, isBitmap: false, objectUrl };
    } catch (e) {
      URL.revokeObjectURL(objectUrl);
      return null;
    }
  }

  // Размер холста редактора берётся из реальной раскладки (getBoundingClientRect),
  // а не из фиксированной константы — модалка адаптивная (@media в base.css),
  // и ширина холста меняется вместе с ней.
  //
  // DPR зажат в 2 (а не взят как есть — на телефоне 3): холст временный,
  // существует только на время редактирования, но не должен раздувать
  // decoded-буфер втрое ради резкости, которую всё равно перекроет финальный
  // рендер в полном разрешении при подтверждении (см. confirmCrop).
  function setupCropCanvasSize() {
    const stage = $("#neCropStage");
    const canvas = $("#neCropCanvas");
    const rect = stage.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    cropViewport = { w: rect.width, h: rect.height };
    canvas.width = Math.max(1, Math.round(rect.width * dpr));
    canvas.height = Math.max(1, Math.round(rect.height * dpr));
    cropCtx = canvas.getContext("2d");
    cropCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  // Зажимает панораму так, чтобы картинка всегда полностью закрывала холст —
  // иначе при перетаскивании по краю появлялась бы пустая область без
  // изображения. При zoom>=cropZoomFit картинка на обеих осях не меньше
  // холста, поэтому диапазон [vw-iw, 0] (и его аналог по Y) всегда корректен.
  function clampPan() {
    if (!cropSrc) { return; }
    const eff = cropEffectiveZoom();
    const iw = cropSrc.width * eff, ih = cropSrc.height * eff;
    const vw = cropViewport.w, vh = cropViewport.h;
    cropPan.x = clamp(cropPan.x, Math.min(0, vw - iw), Math.max(0, vw - iw));
    cropPan.y = clamp(cropPan.y, Math.min(0, vh - ih), Math.max(0, vh - ih));
  }

  // Рамка выделения — независимый прямоугольник холста (не привязан к
  // картинке под ним впрямую, только к границам холста и минимальному
  // размеру); cropToSourceRect() сам зажимает то, что рамка в итоге читает
  // из исходника, так что здесь достаточно не выпускать рамку за холст.
  function clampFrameToViewport() {
    cropFrame.w = clamp(cropFrame.w, CROP_MIN_FRAME, cropViewport.w);
    cropFrame.h = clamp(cropFrame.h, CROP_MIN_FRAME, cropViewport.h);
    cropFrame.x = clamp(cropFrame.x, 0, cropViewport.w - cropFrame.w);
    cropFrame.y = clamp(cropFrame.y, 0, cropViewport.h - cropFrame.h);
  }

  function drawCrop() {
    if (!cropSrc || !cropCtx) { return; }
    const eff = cropEffectiveZoom();
    cropCtx.clearRect(0, 0, cropViewport.w, cropViewport.h);
    cropCtx.drawImage(
      cropSrc.source, 0, 0, cropSrc.width, cropSrc.height,
      cropPan.x, cropPan.y, cropSrc.width * eff, cropSrc.height * eff
    );
  }

  function positionCropFrameEl() {
    const el = $("#neCropFrame");
    el.style.left = cropFrame.x + "px";
    el.style.top = cropFrame.y + "px";
    el.style.width = cropFrame.w + "px";
    el.style.height = cropFrame.h + "px";
    // Хэндлы позиционируются в координатах самой рамки (0..w, 0..h) — она
    // сама position:absolute, так что дети-хэндлы анкерятся к её боксу.
    const half = 8; // половина стороны хэндла, см. .ne-crop-handle в news.css
    const corners = { nw: [0, 0], ne: [cropFrame.w, 0], sw: [0, cropFrame.h], se: [cropFrame.w, cropFrame.h] };
    for (const corner of Object.keys(corners)) {
      const [dx, dy] = corners[corner];
      const h = el.querySelector('[data-corner="' + corner + '"]');
      h.style.left = (dx - half) + "px";
      h.style.top = (dy - half) + "px";
    }
  }

  function updateCropDimsOutput() {
    if (!cropSrc) { return; }
    const rect = NEWS.cropToSourceRect(cropFrame, cropEffectiveZoom(), cropPan, { width: cropSrc.width, height: cropSrc.height });
    const w = Math.max(1, Math.round(rect.sw));
    const h = Math.max(1, Math.round(rect.sh));
    $("#neCropDims").textContent = I18N.t("news.cropDims", lang, { w, h });
  }

  // Общая точка входа после любого изменения состояния (панорама, зум,
  // перемещение/ресайз рамки) — перерисовывает холст, рамку и индикатор
  // итогового размера разом, чтобы вызывающему коду не нужно было помнить,
  // какие три вещи держать в синхроне.
  function updateCropUI() {
    drawCrop();
    positionCropFrameEl();
    updateCropDimsOutput();
  }

  // Масштабирует вокруг точки холста (anchorX, anchorY) — точка исходника,
  // которая была под этой точкой холста, остаётся под ней и после смены
  // зума. Без этого движение ползунка/колеса «уводило» бы картинку в
  // сторону от того места, где админ крутил колесо.
  function applyCropZoom(zoomPct, anchorX, anchorY) {
    if (!cropSrc) { return; }
    const oldEff = cropEffectiveZoom();
    const imgX = (anchorX - cropPan.x) / oldEff;
    const imgY = (anchorY - cropPan.y) / oldEff;
    cropZoom = clamp(zoomPct / 100, 1, 4);
    const newEff = cropEffectiveZoom();
    cropPan.x = anchorX - imgX * newEff;
    cropPan.y = anchorY - imgY * newEff;
    clampPan();
    updateCropUI();
  }

  // Начальное состояние после декодирования картинки: зум «вписать целиком»,
  // картинка отцентрована, рамка выделения — почти весь холст, но НЕ вплотную
  // к краям (6% отступ с каждой стороны). Рамка вплотную к краю оставляла бы
  // panning (перетаскивание холста ЗА пределами рамки) недоступным сразу
  // после открытия — схватить нечего, вся площадь холста уже занята рамкой.
  // Отступ даёт видимую полосу картинки вокруг рамки, за которую можно
  // потянуть, не требуя сначала сузить рамку вручную.
  function initCropState() {
    setupCropCanvasSize();
    const vw = cropViewport.w, vh = cropViewport.h;
    cropZoomFit = Math.min(vw / cropSrc.width, vh / cropSrc.height) || 1;
    cropZoom = 1;
    const eff = cropEffectiveZoom();
    cropPan = { x: (vw - cropSrc.width * eff) / 2, y: (vh - cropSrc.height * eff) / 2 };
    const marginX = vw * 0.06, marginY = vh * 0.06;
    cropFrame = { x: marginX, y: marginY, w: vw - marginX * 2, h: vh - marginY * 2 };
    $("#neCropZoom").value = 100;
    $("#neCropZoomValue").textContent = "100%";
    updateCropUI();
  }

  // Закрывает вкладку кроп-редактора и освобождает декодированный источник —
  // ImageBitmap.close() отпускает декодированный битмап немедленно, не
  // дожидаясь сборщика мусора (та же дисциплина памяти, что и у остального
  // проекта, см. Safari-спеку в шапке файла). У фолбэка на <img> вместо
  // close() отзывается blob-URL, который держал его src.
  function closeCropUI() {
    if (cropSrc) {
      if (cropSrc.isBitmap) { cropSrc.source.close(); }
      if (cropSrc.objectUrl) { URL.revokeObjectURL(cropSrc.objectUrl); }
    }
    cropSrc = null;
    cropOriginalDataUrl = "";
    cropDrag = null;
    const box = $("#neCrop");
    if (box) { box.hidden = true; }
  }

  // Кадрирует в ПОЛНОМ разрешении исходника — единственный раз, здесь, а не
  // на каждый кадр превью. Рисует прямо из cropSrc.source (ImageBitmap/<img>)
  // в новый холст размера кропа, а не масштабирует уже уменьшенный
  // превью-канвас (тот и так CSS-размера редактора, из него не вытянуть
  // пиксели исходника обратно).
  async function confirmCrop() {
    if (!cropSrc) { return; }
    const rect = NEWS.cropToSourceRect(cropFrame, cropEffectiveZoom(), cropPan, { width: cropSrc.width, height: cropSrc.height });
    const sx = Math.round(rect.sx), sy = Math.round(rect.sy);
    const sw = Math.max(1, Math.round(rect.sw)), sh = Math.max(1, Math.round(rect.sh));

    // Потолок стороны — тот же, что сервер применит к текущей выбранной
    // ширине (news_image_cap() в api/lib/images.php, см. NEWS.newsImageCap()
    // в news.js). Без этого шага исходник шёл на выходной canvas в полный
    // размер кропа В ПИКСЕЛЯХ ИСТОЧНИКА: скриншот телефона 3000×4000,
    // обрезанный не в упор, легко даёт 15-25 МБ PNG (~30 МБ в base64) —
    // сервер такое всё равно обрежет под потолок, но не раньше, чем
    // NEWS_IMAGE_MAX_BYTES (6 МБ) в save_image_bytes() бросит "image too
    // large", а то и post_max_size на хостинге оборвёт запрос ещё раньше.
    // Даунскейл здесь — не дублирование серверного, а экономия byte'ов,
    // которые сервер всё равно бы выбросил.
    const cap = NEWS.newsImageCap(getPct());
    const longestSide = Math.max(sw, sh);
    const scale = longestSide > cap ? cap / longestSide : 1;
    const dw = Math.max(1, Math.round(sw * scale));
    const dh = Math.max(1, Math.round(sh * scale));

    const out = document.createElement("canvas");
    out.width = dw;
    out.height = dh;
    const octx = out.getContext("2d");
    // sx/sy/sw/sh (окно в исходнике) → 0/0/dw/dh (выходной canvas) одним
    // вызовом: так ресемплинг делает сам drawImage за один проход, а не
    // канвас-в-канвас в два прохода с двойной потерей резкости.
    octx.drawImage(cropSrc.source, sx, sy, sw, sh, 0, 0, dw, dh);
    // JPEG-исходник остаётся JPEG (0.9 — тот же компромисс качество/вес, что
    // и у imagejpeg(..., 88) на сервере в images.php), всё остальное — PNG,
    // чтобы не потерять альфа-канал.
    const quality = cropOutputMime === "image/jpeg" ? 0.9 : undefined;
    const dataUrl = out.toDataURL(cropOutputMime, quality);

    closeCropUI();
    pickedImageDataUrl = dataUrl;
    await uploadPickedImage();
    updatePreview();
  }

  // «Без кадрирования»: заливает файл ровно так, как он был выбран — байты
  // из readFileAsDataURL, а не что-либо, прошедшее через canvas.
  async function skipCrop() {
    const original = cropOriginalDataUrl;
    closeCropUI();
    pickedImageDataUrl = original;
    await uploadPickedImage();
    updatePreview();
  }

  // ---- pointer-жесты кроп-редактора: панорама картинки, перемещение и
  // ресайз рамки. Оба обработчика рано выходят, если cropDrag нет (жест не
  // из кроп-редактора) — их можно вешать на document безусловно.
  function cropPointerMove(ev) {
    if (!cropDrag || ev.pointerId !== cropDrag.pointerId) { return; }
    const dx = ev.clientX - cropDrag.startX;
    const dy = ev.clientY - cropDrag.startY;

    if (cropDrag.kind === "pan") {
      cropPan = { x: cropDrag.startPan.x + dx, y: cropDrag.startPan.y + dy };
      clampPan();
    } else if (cropDrag.kind === "move") {
      cropFrame = {
        ...cropDrag.startFrame,
        x: clamp(cropDrag.startFrame.x + dx, 0, cropViewport.w - cropDrag.startFrame.w),
        y: clamp(cropDrag.startFrame.y + dy, 0, cropViewport.h - cropDrag.startFrame.h),
      };
    } else if (cropDrag.kind === "resize") {
      resizeFrameFromAnchor(cropDrag.anchor, cropDrag.startCorner.x + dx, cropDrag.startCorner.y + dy);
    }
    updateCropUI();
  }

  function cropPointerUp(ev) {
    if (!cropDrag || ev.pointerId !== cropDrag.pointerId) { return; }
    cropDrag = null;
  }

  // Перетаскивает противоположный от anchor угол рамки в точку (px, py) холста,
  // зажатую в границы холста и не ближе CROP_MIN_FRAME к anchor — та же
  // формула, что описана и протестирована для cropToSourceRect: рамка это
  // просто прямоугольник между anchor и текущей точкой курсора.
  function resizeFrameFromAnchor(anchor, px, py) {
    px = clamp(px, 0, cropViewport.w);
    py = clamp(py, 0, cropViewport.h);
    if (Math.abs(px - anchor.x) < CROP_MIN_FRAME) {
      px = anchor.x + CROP_MIN_FRAME * (px < anchor.x ? -1 : 1);
    }
    if (Math.abs(py - anchor.y) < CROP_MIN_FRAME) {
      py = anchor.y + CROP_MIN_FRAME * (py < anchor.y ? -1 : 1);
    }
    px = clamp(px, 0, cropViewport.w);
    py = clamp(py, 0, cropViewport.h);
    cropFrame = {
      x: Math.min(anchor.x, px), y: Math.min(anchor.y, py),
      w: Math.abs(px - anchor.x), h: Math.abs(py - anchor.y),
    };
  }

  // anchor — противоположный (неподвижный) угол рамки, se остаётся точкой на
  // месте, пока тащат nw, и т.д. corner — сам перетаскиваемый угол, нужен
  // отдельно, чтобы прибавлять dx/dy к его СОБСТВЕННОЙ стартовой позиции, а
  // не к левому верхнему углу рамки (это не одно и то же для трёх из
  // четырёх хэндлов).
  const CROP_ANCHORS = {
    nw: f => ({ x: f.x + f.w, y: f.y + f.h }),
    ne: f => ({ x: f.x,       y: f.y + f.h }),
    sw: f => ({ x: f.x + f.w, y: f.y }),
    se: f => ({ x: f.x,       y: f.y }),
  };
  const CROP_CORNER = {
    nw: f => ({ x: f.x,       y: f.y }),
    ne: f => ({ x: f.x + f.w, y: f.y }),
    sw: f => ({ x: f.x,       y: f.y + f.h }),
    se: f => ({ x: f.x + f.w, y: f.y + f.h }),
  };

  // Собирает пост из текущего состояния формы — ровно то, что publish()
  // отправит на сервер, только ещё не отправленное. cardFor() строит из него
  // настоящую карточку, поэтому превью не может нарисовать что-то, чего не
  // нарисует и лента.
  function buildPreviewPost() {
    const dateVal = $("#neDate").value;
    return {
      id: editingPost ? editingPost.id : 0,
      category: getCat(),
      title_ru: $("#neTitleRu").value,
      title_en: $("#neTitleEn").value,
      body_ru:  $("#neBodyRu").value,
      body_en:  $("#neBodyEn").value,
      image_url: currentImage,
      image_width: currentImageWidth,
      image_height: currentImageHeight,
      image_pct: getPct(),
      image_align: getAlign(),
      image_wrap: getWrap(),
      // Пустая/битая дата не должна ронять превью на "Invalid Date" —
      // берём "сейчас" вместо неё; publish() всё равно проверяет дату
      // отдельно перед отправкой.
      published_at: dateVal ? dayToMs(dateVal) : Date.now(),
    };
  }

  function updatePreview() {
    const box = $("#nePreviewCard");
    // applyLang() зовёт превью при каждой смене языка, в том числе на
    // публичной ленте, где редактора нет.
    if (!box) { return; }
    box.innerHTML = "";
    box.append(cardFor(buildPreviewPost(), false));
  }

  // Единая точка закрытия модалки редактора — обязана освобождать открытый
  // кроп-битмап (closeCropUI), а не только прятать модалку: без этого уход из
  // редактора Escape-ом/крестиком/кликом по фону мимо кнопок «Обрезать»/«Без
  // кадрирования» оставлял бы декодированный ImageBitmap висеть в памяти до
  // следующего выбора файла (или вовсе до перезагрузки страницы).
  function closeEditor() {
    closeCropUI();
    editor.hidden = true;
  }

  function openEditor(post) {
    // На случай, если предыдущая сессия редактирования была прервана не
    // через closeEditor() (не должно происходить, но дешевле подстраховаться,
    // чем держать это инвариантом, который нечем проверить).
    closeCropUI();
    editingPost = post;
    $("#neHeading").textContent = tx(post ? "news.modalEdit" : "news.modalNew");
    $("#neTitleRu").value = post ? post.title_ru : "";
    $("#neTitleEn").value = post ? post.title_en : "";
    $("#neBodyRu").value  = post ? post.body_ru  : "";
    $("#neBodyEn").value  = post ? post.body_en  : "";
    $("#neDate").value = isoDay(post ? post.published_at : Date.now());
    setCat(post ? post.category : "tierlist");
    setAlign(post ? post.image_align : "center");
    setPct(post ? post.image_pct : 100);
    setWrap(post ? post.image_wrap : false);
    // Новая сессия редактирования — новый (изначально пустой) источник для
    // перезаливки при смене ширины. Открытие уже существующего поста не
    // держит его исходный файл в памяти браузера, поэтому источника нет,
    // пока админ сам не выберет файл заново. Обнуляется ДО setImage() ниже:
    // тот пересчитывает #nePctHint внутри себя и обязан видеть уже пустой
    // pickedImageDataUrl этой новой сессии, а не хвост от предыдущей.
    pickedImageDataUrl = "";
    setImage(post ? post.image_url : "", post ? post.image_width : null, post ? post.image_height : null);
    $("#neError").textContent = "";
    editor.hidden = false;
    updatePreview();
    setTimeout(() => $("#neTitleRu").focus(), 30);
  }

  async function publish() {
    const dateVal = $("#neDate").value;
    // Пустая дата уходит в dayToMs("") как NaN → отрицательный timestamp,
    // news_save.php видит published_at <= 0 и молча подставляет "сейчас".
    // На новом посте это безобидно, а вот на правке существующего — тихая
    // порча реальной даты публикации без единого предупреждения. #neDate
    // отмечен required, но модалка — не <form>, кнопка «Опубликовать» не
    // сабмит, так что required без явной проверки браузер не остановил бы
    // ничего — проверяем здесь же, перед запросом.
    if (!dateVal) {
      $("#neError").textContent = tx("news.dateRequired");
      return;
    }
    const body = {
      category: getCat(),
      title_ru: $("#neTitleRu").value.trim(),
      title_en: $("#neTitleEn").value.trim(),
      body_ru:  $("#neBodyRu").value.trim(),
      body_en:  $("#neBodyEn").value.trim(),
      image_url: currentImage,
      image_width: currentImageWidth,
      image_height: currentImageHeight,
      image_pct: getPct(),
      image_align: getAlign(),
      image_wrap: getWrap(),
      published_at: dayToMs(dateVal),
    };
    if (editingPost) { body.id = editingPost.id; }

    try {
      const r = await fetch("/api/news_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const d = await r.json();
      if (!r.ok || !d.ok) { throw new Error(d.error || "http " + r.status); }
      closeEditor();
      await load();
    } catch (e) {
      // Модалка остаётся открытой намеренно: потерять набранный текст из-за
      // отвалившейся сети — худшее, что может здесь произойти.
      $("#neError").textContent = tx("news.saveFailed") + " " + e.message;
    }
  }

  async function removePost(post) {
    const picked = NEWS.pickLang(post, lang);
    if (!confirm(I18N.t("news.confirmDelete", lang, { title: picked.title }))) { return; }
    try {
      const r = await fetch("/api/news_delete.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: post.id }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) { throw new Error(d.error || "http " + r.status); }
      await load();
    } catch (e) {
      // #neError показывать некуда: удаление запускается с ✕ на карточке
      // в ленте, редактор при этом закрыт, и текст внутри модалки никто
      // не увидит. alert() — тот же приём, что и в app.js для ошибок вне
      // форм (msg.readFailed и т. п.): системное окно видно независимо от
      // того, что сейчас на экране.
      alert(tx("news.deleteFailed") + " " + e.message);
    }
  }

  // Заливает pickedImageDataUrl под текущую выбранную ширину редактора.
  // Общая для первого выбора файла и для перезаливки при смене ширины (см.
  // обработчик "change" на #nePct в wireAdmin ниже) — оба случая шлют один
  // и тот же запрос, отличается только источник в pickedImageDataUrl и
  // повод вызова.
  async function uploadPickedImage() {
    // kind: "news" + pct — потолок стороны по выбранной в редакторе ширине,
    // см. resolve_upload_max_side() в upload.php.
    const r = await fetch("/api/upload.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data: pickedImageDataUrl, kind: "news", pct: getPct() }),
    });
    const d = await r.json();
    if (r.ok && d.url) {
      setImage(d.url, d.width, d.height);
      $("#neError").textContent = "";
    } else {
      $("#neError").textContent = tx("news.saveFailed") + " " + (d.error || "");
    }
  }

  // Кнопка «Добавить» и вся обвязка модалки. Вызывается только на /admin/news:
  // на публичной ленте этих узлов нет, и addEventListener на null бросил бы
  // прямо в теле IIFE — то есть убил бы и загрузку ленты для посетителя.
  function wireAdmin() {
    const bar = $("#newsAdminBar");
    if (bar) {
      bar.hidden = false;
      const add = document.createElement("button");
      add.className = "btn primary";
      add.dataset.i18n = "news.add";
      add.textContent = tx("news.add");
      add.addEventListener("click", () => openEditor(null));
      bar.append(add);
    }
    if (!editor) { return; }

    $("#nePublish").addEventListener("click", publish);
    $("#neCancel").addEventListener("click", closeEditor);
    $("#neClose").addEventListener("click", closeEditor);
    $("#neImagePick").addEventListener("click", () => $("#neImageFile").click());
    $("#neImageClear").addEventListener("click", () => {
      setImage("");
      // Очищаем и источник: иначе следующая смена размера перезалила бы уже
      // убранную картинку обратно.
      pickedImageDataUrl = "";
      updatePreview();
    });
    editor.addEventListener("click", e => { if (e.target === editor) { closeEditor(); } });
    document.addEventListener("keydown", e => {
      if (e.key === "Escape" && !editor.hidden) { closeEditor(); }
    });

    $("#neImageFile").addEventListener("change", async ev => {
      const file = ev.target.files && ev.target.files[0];
      if (!file) { return; }
      // Сбрасываем сразу: файл уже будет прочитан ниже (и в data URL, и в
      // decodeImageForCrop), так что значение поля больше не нужно — а без
      // сброса повторный выбор ТОГО ЖЕ файла не дал бы второго события change.
      ev.target.value = "";

      if (cropSrc) { closeCropUI(); } // повторный выбор файла до подтверждения предыдущего кропа

      const originalDataUrl = await readFileAsDataURL(file);
      const decoded = await decodeImageForCrop(file).catch(() => null);

      if (!decoded) {
        // Файл не декодировался ни через createImageBitmap, ни через <img>
        // (неизвестный браузеру формат вроде HEIC) — показывать кроп-редактор
        // нечем. Откатываемся на прежнее поведение: заливаем файл как есть,
        // без кадрирования, а не блокируем загрузку картинки вовсе.
        pickedImageDataUrl = originalDataUrl;
        await uploadPickedImage();
        updatePreview();
        return;
      }

      cropSrc = decoded;
      cropOriginalDataUrl = originalDataUrl;
      // JPEG-исходник остаётся JPEG при экспорте кропа (см. confirmCrop) —
      // всё остальное (PNG, WebP, GIF-как-картинка) становится PNG, чтобы не
      // потерять альфа-канал, который downscale_image_bytes() на сервере тоже
      // бережёт только для не-JPEG форматов.
      cropOutputMime = file.type === "image/jpeg" ? "image/jpeg" : "image/png";
      $("#neCrop").hidden = false;
      initCropState();
    });

    // ---- pointer/клавиатурная обвязка кроп-редактора. Живёт здесь, а не на
    // верхнем уровне IIFE, ровно по той же причине, что и остальная обвязка
    // редактора: #neCropStage/#neCropFrame существуют только внутри разметки
    // #newsEditor, которую вставляет admin-news.php — на публичной ленте их
    // нет, и addEventListener на null бросил бы прямо здесь.
    document.addEventListener("pointermove", cropPointerMove);
    document.addEventListener("pointerup", cropPointerUp);
    document.addEventListener("pointercancel", cropPointerUp);

    $("#neCropStage").addEventListener("pointerdown", ev => {
      if (!cropSrc || ev.target.closest("#neCropFrame")) { return; }
      ev.preventDefault();
      ev.target.setPointerCapture(ev.pointerId);
      cropDrag = { kind: "pan", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY, startPan: { ...cropPan } };
    });

    $("#neCropFrame").addEventListener("pointerdown", ev => {
      if (!cropSrc || ev.target.closest(".ne-crop-handle")) { return; }
      ev.preventDefault();
      ev.target.setPointerCapture(ev.pointerId);
      cropDrag = { kind: "move", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY, startFrame: { ...cropFrame } };
    });

    for (const handle of document.querySelectorAll(".ne-crop-handle")) {
      handle.addEventListener("pointerdown", ev => {
        if (!cropSrc) { return; }
        ev.preventDefault();
        ev.stopPropagation();
        ev.target.setPointerCapture(ev.pointerId);
        const corner = handle.dataset.corner;
        const startFrame = { ...cropFrame };
        cropDrag = {
          kind: "resize", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY,
          startFrame, anchor: CROP_ANCHORS[corner](startFrame), startCorner: CROP_CORNER[corner](startFrame),
        };
      });
    }

    $("#neCropStage").addEventListener("wheel", ev => {
      if (!cropSrc) { return; }
      ev.preventDefault();
      const rect = $("#neCropStage").getBoundingClientRect();
      const ax = ev.clientX - rect.left, ay = ev.clientY - rect.top;
      const nextPct = clamp(cropZoom * 100 + (ev.deltaY > 0 ? -10 : 10), 100, 400);
      applyCropZoom(nextPct, ax, ay);
      $("#neCropZoom").value = Math.round(nextPct);
      $("#neCropZoomValue").textContent = Math.round(nextPct) + "%";
    }, { passive: false });

    $("#neCropZoom").addEventListener("input", () => {
      const pct = Number($("#neCropZoom").value) || 100;
      $("#neCropZoomValue").textContent = pct + "%";
      // Якорь — центр холста: ползунком и клавиатурой (в отличие от колеса над
      // конкретной точкой) не целятся в конкретное место картинки.
      applyCropZoom(pct, cropViewport.w / 2, cropViewport.h / 2);
    });

    // Клавиатурный путь для рамки: стрелки двигают, Shift+стрелки меняют
    // размер (растут вправо/вниз, сужаются к тому же углу — простое,
    // предсказуемое правило вместо «какой угол ресайзили последним»).
    // Описание — в news.cropFrameLabel (title/aria-label на самом элементе,
    // задаётся в HTML и обновляется applyLang()).
    $("#neCropFrame").addEventListener("keydown", ev => {
      if (!cropSrc) { return; }
      const step = CROP_KEY_STEP;
      let handled = true;
      if (ev.shiftKey) {
        if (ev.key === "ArrowRight") { cropFrame.w = clamp(cropFrame.w + step, CROP_MIN_FRAME, cropViewport.w - cropFrame.x); }
        else if (ev.key === "ArrowLeft") { cropFrame.w = clamp(cropFrame.w - step, CROP_MIN_FRAME, cropViewport.w - cropFrame.x); }
        else if (ev.key === "ArrowDown") { cropFrame.h = clamp(cropFrame.h + step, CROP_MIN_FRAME, cropViewport.h - cropFrame.y); }
        else if (ev.key === "ArrowUp") { cropFrame.h = clamp(cropFrame.h - step, CROP_MIN_FRAME, cropViewport.h - cropFrame.y); }
        else { handled = false; }
      } else {
        if (ev.key === "ArrowRight") { cropFrame.x = clamp(cropFrame.x + step, 0, cropViewport.w - cropFrame.w); }
        else if (ev.key === "ArrowLeft") { cropFrame.x = clamp(cropFrame.x - step, 0, cropViewport.w - cropFrame.w); }
        else if (ev.key === "ArrowDown") { cropFrame.y = clamp(cropFrame.y + step, 0, cropViewport.h - cropFrame.h); }
        else if (ev.key === "ArrowUp") { cropFrame.y = clamp(cropFrame.y - step, 0, cropViewport.h - cropFrame.h); }
        else { handled = false; }
      }
      if (handled) { ev.preventDefault(); updateCropUI(); }
    });

    $("#neCropConfirm").addEventListener("click", confirmCrop);
    $("#neCropSkip").addEventListener("click", skipCrop);

    // Поворот телефона (или ресайз окна на десктопе) меняет реальный css-размер
    // #neCropStage, пока редактор открыт — пересчитываем буфер холста и зажимаем
    // панораму/рамку под новый viewport, а не оставляем их посчитанными под уже
    // не существующий размер (иначе рамка могла бы оказаться шире холста).
    window.addEventListener("resize", () => {
      if (!cropSrc) { return; }
      setupCropCanvasSize();
      clampPan();
      clampFrameToViewport();
      updateCropUI();
    });

    // Живое превью: заголовки, тексты и дата не проходят через отдельные
    // сеттеры вроде setCat/setAlign, поэтому слушаем input/change прямо на
    // полях. Тело поста ограничено 20 000 символами (NEWS_BODY_MAX в
    // news_save.php), а updatePreview() на каждый input пересобирает всю
    // карточку заново — режет body на абзацы и пересоздаёт каждый <p> и
    // <img>. На заголовке это незаметно, но на боди у предела длины —
    // заметная работа на каждое нажатие клавиши, поэтому обновление
    // дебаунсится на 150 мс, а не гонится за каждым input.
    let previewDebounceTimer = null;
    function schedulePreviewUpdate() {
      clearTimeout(previewDebounceTimer);
      previewDebounceTimer = setTimeout(updatePreview, 150);
    }
    for (const id of ["neTitleRu", "neTitleEn", "neBodyRu", "neBodyEn"]) {
      $("#" + id).addEventListener("input", schedulePreviewUpdate);
    }
    $("#neDate").addEventListener("change", schedulePreviewUpdate);
    $("#neDate").addEventListener("input", schedulePreviewUpdate);

    // "input" тянется на каждый тик перетаскивания ползунка — обновляет
    // числовой индикатор и превью (дебаунс тот же, что и у текстовых полей).
    // "change" срабатывает один раз, когда админ отпустил ползунок или
    // закончил менять его с клавиатуры — только в этот момент имеет смысл
    // перезаливать уже выбранный файл под новый потолок стороны (см.
    // uploadPickedImage выше): гнать запрос на каждый промежуточный тик
    // было бы избыточно.
    // setPct() — единственное место, которое округляет и зажимает editorPct
    // в 10..100 (и пересчитывает #nePctHint); присвоение editorPct напрямую
    // здесь означало бы вторую точку записи той же переменной без той же
    // проверки.
    $("#nePct").addEventListener("input", () => {
      setPct($("#nePct").value);
      schedulePreviewUpdate();
    });
    $("#nePct").addEventListener("change", () => {
      if (pickedImageDataUrl) { uploadPickedImage().then(updatePreview); }
    });
    $("#neWrap").addEventListener("change", updatePreview);
  }

  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  applyLang(lang);
  if (isAdmin) {
    document.body.classList.add("nw-editing");
    wireAdmin();
  }
  load();
})();
