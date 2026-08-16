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
    for (const b of document.querySelectorAll("#langSwitch .chip")) {
      const on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    }
    renderFilters();
    render();
  }

  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  applyLang(lang);
  load();
})();
