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
      b.addEventListener("click", () => { editorImageSize = s.key; renderImageSizeSeg(); updatePreview(); });
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
  function setImage(url) {
    currentImage = url || "";
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
      image_size: getImageSize(),
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
    setImage(post ? post.image_url : "");
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
      image_size: getImageSize(),
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
    $("#neCancel").addEventListener("click", () => { editor.hidden = true; });
    $("#neClose").addEventListener("click", () => { editor.hidden = true; });
    $("#neImagePick").addEventListener("click", () => $("#neImageFile").click());
    $("#neImageClear").addEventListener("click", () => { setImage(""); updatePreview(); });
    editor.addEventListener("click", e => { if (e.target === editor) { editor.hidden = true; } });
    document.addEventListener("keydown", e => {
      if (e.key === "Escape" && !editor.hidden) { editor.hidden = true; }
    });

    $("#neImageFile").addEventListener("change", async ev => {
      const file = ev.target.files && ev.target.files[0];
      if (!file) { return; }
      const dataUrl = await new Promise(res => {
        const fr = new FileReader();
        fr.onload = () => res(fr.result);
        fr.readAsDataURL(file);
      });
      // kind: "news" поднимает потолок стороны до 1280 — см. upload.php.
      const r = await fetch("/api/upload.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ data: dataUrl, kind: "news" }),
      });
      const d = await r.json();
      if (r.ok && d.url) { setImage(d.url); }
      else { $("#neError").textContent = tx("news.saveFailed") + " " + (d.error || ""); }
      updatePreview();
      ev.target.value = "";
    });

    // Живое превью: заголовки, тексты и дата не проходят через отдельные
    // сеттеры вроде setCat/setImageSize, поэтому слушаем input/change прямо на
    // полях. Дебаунс не нужен — updatePreview() это несколько DOM-узлов на
    // короткий пост, лишней нагрузки на каждую нажатую клавишу не создаёт.
    for (const id of ["neTitleRu", "neTitleEn", "neBodyRu", "neBodyEn"]) {
      $("#" + id).addEventListener("input", updatePreview);
    }
    $("#neDate").addEventListener("change", updatePreview);
    $("#neDate").addEventListener("input", updatePreview);
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
