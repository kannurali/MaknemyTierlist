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
  let lang = I18N.pickLang(localStorage.getItem(LANG_KEY), navigator.language);

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
  function cardFor(post) {
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
      img.className = "nw-image";
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

    if (isAdmin) {
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

    if (!visible.length) { showState("news.empty", false); return; }

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
    localStorage.setItem(LANG_KEY, next);
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
    render();
  }

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
      b.addEventListener("click", () => { editorCat = c.key; renderCatSeg(); });
      box.append(b);
    }
  }
  function setCat(key) { editorCat = NEWS.isCategory(key) ? key : "tierlist"; renderCatSeg(); }
  function getCat() { return editorCat; }

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

  function setImage(url) {
    currentImage = url || "";
    const prev = $("#neImagePreview");
    prev.hidden = !currentImage;
    if (currentImage) { prev.src = currentImage; }
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
    setImage(post ? post.image_url : "");
    $("#neError").textContent = "";
    editor.hidden = false;
    setTimeout(() => $("#neTitleRu").focus(), 30);
  }

  async function publish() {
    const body = {
      category: getCat(),
      title_ru: $("#neTitleRu").value.trim(),
      title_en: $("#neTitleEn").value.trim(),
      body_ru:  $("#neBodyRu").value.trim(),
      body_en:  $("#neBodyEn").value.trim(),
      image_url: currentImage,
      published_at: dayToMs($("#neDate").value),
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
    const r = await fetch("api/news_delete.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: post.id }),
    });
    if (r.ok) { await load(); }
  }

  $("#nePublish").addEventListener("click", publish);
  $("#neCancel").addEventListener("click", () => { editor.hidden = true; });
  $("#neClose").addEventListener("click", () => { editor.hidden = true; });
  $("#neImagePick").addEventListener("click", () => $("#neImageFile").click());
  $("#neImageClear").addEventListener("click", () => setImage(""));
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
    const r = await fetch("api/upload.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data: dataUrl, kind: "news" }),
    });
    const d = await r.json();
    if (r.ok && d.url) { setImage(d.url); }
    else { $("#neError").textContent = tx("news.saveFailed") + " " + (d.error || ""); }
    ev.target.value = "";
  });

  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  applyLang(lang);
  load();
  checkSession();
})();
