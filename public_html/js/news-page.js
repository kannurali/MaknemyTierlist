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

  let posts = [];
  let activeCat = "all";
  // true только после того, как load() реально получил ленту с сервера —
  // не во время "Загружаем…" и не после ошибки. checkSession() читает этот
  // флаг, чтобы не перерисовывать состояние поверх loading/error (см. ниже).
  let feedLoaded = false;
  // Как в applyLang в app.js: localStorage бросает в приватном режиме Safari
  // (и в некоторых встроенных webview с отключённым хранилищем). Без try/catch
  // это исключение случилось бы прямо в теле IIFE до объявления load() и
  // checkSession() ниже — они бы не вызвались вообще, и посетитель получил бы
  // пустую сцену без ленты, без ошибки и без кнопки «Повторить».
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
      // Неизвестный/отсутствующий размер — full, как и на сервере
      // (validate_news_post трактует пустое так же). Резерв на случай, если
      // сюда попадёт что-то не из NEWS.IMAGE_SIZES, а не признак того, что
      // это случается в норме.
      const size = NEWS.IMAGE_SIZES.find(s => s.key === post.image_size)
        || NEWS.IMAGE_SIZES[NEWS.IMAGE_SIZES.length - 1];
      img.className = "nw-image " + size.cls;
      img.src = post.image_url;
      img.alt = picked.title;
      img.loading = "lazy";
      img.decoding = "async";
      // width/height — не литеральный размер отображения (тем управляет CSS:
      // .nw-image{height:auto} и .sz-*{width:%}), а подсказка браузеру для
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
      const r = await fetch("api/news.php", { cache: "no-store" });
      if (!r.ok) { throw new Error("http " + r.status); }
      const data = await r.json();
      posts = Array.isArray(data.posts) ? data.posts : [];
      feedLoaded = true;
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
  // Сессия общая с тирлистом: cookie ставится с path '/', поэтому вход на
  // главной уже авторизует и эту страницу.
  let isAdmin = false;

  async function checkSession() {
    try {
      const r = await fetch("api/session.php", { cache: "no-store" });
      const d = await r.json();
      isAdmin = !!d.admin;
    } catch (e) {
      isAdmin = false;
    }
    document.body.classList.toggle("nw-editing", isAdmin);
    const bar = $("#newsAdminBar");
    bar.hidden = !isAdmin;
    if (isAdmin && !bar.childElementCount) {
      const add = document.createElement("button");
      add.className = "btn primary";
      add.dataset.i18n = "news.add";
      add.textContent = tx("news.add");
      add.addEventListener("click", () => openEditor(null));
      bar.append(add);
    }
    // checkSession() гонится с load(): api/session.php не трогает БД, поэтому
    // обычно отвечает первым. Безусловный render() здесь перерисовывал бы
    // #newsState с posts ещё в [] поверх "Загружаем…" (показывая "пусто" на
    // деле ещё не загруженной ленты), а при ошибке — поверх текста ошибки
    // вместе с её кнопкой «Повторить», без единого способа её вернуть.
    // render() из checkSession() имеет смысл только затем, чтобы дорисовать
    // ✎/✕ у уже показанных карточек после того, как выяснилась роль —
    // а это возможно только если feed уже реально загружен.
    if (feedLoaded) { render(); }
  }

  const editor = $("#newsEditor");
  let editingPost = null;
  let currentImage = "";
  let currentImageWidth = null;
  let currentImageHeight = null;
  // Data URL картинки, выбранной в этой сессии редактирования (см.
  // #neImageFile ниже) — источник, из которого можно перезалить файл под
  // новый потолок при смене размера картинки. "" значит "источника нет":
  // либо ничего не выбирали, либо открыли существующий пост и не трогали
  // картинку — тогда смена размера не должна ничего перезаливать (см.
  // renderImageSizeSeg ниже).
  let pickedImageDataUrl = "";

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
  let editorImageSize = "full";

  function renderImageSizeSeg() {
    const box = $("#neImageSize");
    box.innerHTML = "";
    for (const s of NEWS.IMAGE_SIZES) {
      const b = document.createElement("button");
      b.type = "button";
      b.dataset.v = s.key;
      b.className = s.key === editorImageSize ? "active" : "";
      b.textContent = tx(s.i18n);
      b.addEventListener("click", () => {
        editorImageSize = s.key;
        renderImageSizeSeg();
        updatePreview();
        // Разный размер — разный потолок стороны (см. NEWS_IMAGE_MAX_SIDE_BY_SIZE
        // в lib/images.php), поэтому уже загруженный файл нужно перезалить под
        // новый потолок, а не просто перекрасить CSS-класс поверх старого веса.
        // Источник для перезаливки есть только в рамках этой сессии
        // редактирования (см. pickedImageDataUrl выше) — если админ просто
        // открыл существующий пост и поменял размер, файл на диске остаётся
        // как есть: он всего лишь крупнее, чем нужно, и это безвредно.
        if (pickedImageDataUrl) { uploadPickedImage().then(updatePreview); }
      });
      box.append(b);
    }
  }
  function setImageSize(key) { editorImageSize = NEWS.isImageSize(key) ? key : "full"; renderImageSizeSeg(); }
  function getImageSize() { return editorImageSize; }

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
  }

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
      image_size: getImageSize(),
      // Пустая/битая дата не должна ронять превью на "Invalid Date" —
      // берём "сейчас" вместо неё; publish() всё равно проверяет дату
      // отдельно перед отправкой.
      published_at: dateVal ? dayToMs(dateVal) : Date.now(),
    };
  }

  function updatePreview() {
    const box = $("#nePreviewCard");
    box.innerHTML = "";
    box.append(cardFor(buildPreviewPost(), false));
  }

  function openEditor(post) {
    editingPost = post;
    $("#neHeading").textContent = tx(post ? "news.modalEdit" : "news.modalNew");
    $("#neTitleRu").value = post ? post.title_ru : "";
    $("#neTitleEn").value = post ? post.title_en : "";
    $("#neBodyRu").value  = post ? post.body_ru  : "";
    $("#neBodyEn").value  = post ? post.body_en  : "";
    $("#neDate").value = isoDay(post ? post.published_at : Date.now());
    setCat(post ? post.category : "tierlist");
    setImageSize(post ? post.image_size : "full");
    setImage(post ? post.image_url : "", post ? post.image_width : null, post ? post.image_height : null);
    // Новая сессия редактирования — новый (изначально пустой) источник для
    // перезаливки при смене размера. Открытие уже существующего поста не
    // держит его исходный файл в памяти браузера, поэтому источника нет,
    // пока админ сам не выберет файл заново.
    pickedImageDataUrl = "";
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
      image_size: getImageSize(),
      published_at: dayToMs(dateVal),
    };
    if (editingPost) { body.id = editingPost.id; }

    try {
      const r = await fetch("api/news_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const d = await r.json();
      if (!r.ok || !d.ok) { throw new Error(d.error || "http " + r.status); }
      editor.hidden = true;
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
      const r = await fetch("api/news_delete.php", {
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

  $("#nePublish").addEventListener("click", publish);
  $("#neCancel").addEventListener("click", () => { editor.hidden = true; });
  $("#neClose").addEventListener("click", () => { editor.hidden = true; });
  $("#neImagePick").addEventListener("click", () => $("#neImageFile").click());
  $("#neImageClear").addEventListener("click", () => {
    setImage("");
    // Очищаем и источник: иначе следующая смена размера перезалила бы уже
    // убранную картинку обратно.
    pickedImageDataUrl = "";
    updatePreview();
  });
  editor.addEventListener("click", e => { if (e.target === editor) { editor.hidden = true; } });
  document.addEventListener("keydown", e => {
    if (e.key === "Escape" && !editor.hidden) { editor.hidden = true; }
  });

  // Заливает pickedImageDataUrl под текущий выбранный размер редактора.
  // Общая для первого выбора файла и для перезаливки при смене размера
  // (см. renderImageSizeSeg выше) — оба случая шлют один и тот же запрос,
  // отличается только источник в pickedImageDataUrl и повод вызова.
  async function uploadPickedImage() {
    // kind: "news" + size — потолок стороны по выбранному в редакторе
    // размеру, см. resolve_upload_max_side() в upload.php.
    const r = await fetch("api/upload.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data: pickedImageDataUrl, kind: "news", size: getImageSize() }),
    });
    const d = await r.json();
    if (r.ok && d.url) {
      setImage(d.url, d.width, d.height);
      $("#neError").textContent = "";
    } else {
      $("#neError").textContent = tx("news.saveFailed") + " " + (d.error || "");
    }
  }

  $("#neImageFile").addEventListener("change", async ev => {
    const file = ev.target.files && ev.target.files[0];
    if (!file) { return; }
    pickedImageDataUrl = await new Promise(res => {
      const fr = new FileReader();
      fr.onload = () => res(fr.result);
      fr.readAsDataURL(file);
    });
    await uploadPickedImage();
    updatePreview();
    ev.target.value = "";
  });

  // Живое превью: заголовки, тексты и дата не проходят через отдельные
  // сеттеры вроде setCat/setImageSize, поэтому слушаем input/change прямо на
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

  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  applyLang(lang);
  load();
  checkSession();
})();
