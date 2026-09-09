
(() => {
  "use strict";

  const $ = sel => document.querySelector(sel);
  const feedEl = $("#feed");
  const stateEl = $("#newsState");
  const filtersEl = $("#newsFilters");
  const noticeEl = $("#newsNotice");

  const PROMO_PAGE = "news";

  const LANG_KEY = "nexus-lang-v1";

  const LINKED_POST_ID = (() => {
    const n = Number(window.NX_LINKED_POST_ID);
    return Number.isInteger(n) && n > 0 ? n : null;
  })();

  let linkedPostScrolled = false;

  let posts = [];
  let activeCat = "all";

  let langHook = null;
  let editHook = null;
  let deleteHook = null;

  let lang = I18N.pickLang(
    (() => { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language);

  const tx = key => I18N.t(key, lang);

  const NEWS_LIKED_KEY = "nexus-news-liked-v1";

  function readLikedMap() {
    try { return JSON.parse(localStorage.getItem(NEWS_LIKED_KEY) || "{}"); }
    catch (_) { return {}; }
  }
  function writeLikedMap(map) {
    try { localStorage.setItem(NEWS_LIKED_KEY, JSON.stringify(map)); } catch (_) {}
  }
  function isPostLiked(id) { return !!readLikedMap()[id]; }
  function setPostLiked(id, v) {
    const map = readLikedMap();
    if (v) { map[id] = true; } else { delete map[id]; }
    writeLikedMap(map);
  }

  async function sendNewsLike(id, dir) {
    try {
      const r = await fetch("/api/news_like.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id, dir }),
      });
      return r.ok ? true : false;
    } catch (e) {}
    return null;
  }

  function popLikeHeart(heartEl) {
    heartEl.classList.remove("pop");

    heartEl.getBoundingClientRect();
    heartEl.classList.add("pop");
  }

  function renderLikeButton(btn, heartEl, countEl, liked, likes) {
    btn.classList.toggle("liked", liked);
    btn.setAttribute("aria-pressed", liked ? "true" : "false");

    btn.title = tx(liked ? "news.likeRemove" : "news.like") + " (" + likes + ")";
    countEl.textContent = String(likes);
  }

  function toggleNewsLike(post, btn, heartEl, countEl) {
    const willLike = !isPostLiked(post.id);
    const dir = willLike ? 1 : -1;

    setPostLiked(post.id, willLike);
    post.likes = Math.max(0, (post.likes || 0) + dir);
    renderLikeButton(btn, heartEl, countEl, willLike, post.likes);
    popLikeHeart(heartEl);

    sendNewsLike(post.id, dir).then(ok => {
      if (ok === false) {
        setPostLiked(post.id, !willLike);
        post.likes = Math.max(0, post.likes - dir);
        renderLikeButton(btn, heartEl, countEl, !willLike, post.likes);
      }
    });
  }

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

  const HEART_PATH = "M 17.46 0 C 15.82 0 13.96 0.17 12.18 0.78 C 1.47 4.28 -1.98 15.72 1.05 25.15 L 1.07 25.19 L 1.08 25.23 C 2.75 29.91 5.43 34.17 8.93 37.67 L 8.95 37.69 L 8.97 37.71 C 13.96 42.49 19.38 46.65 25.26 50.27 L 26.64 51.12 L 28.03 50.29 C 33.92 46.75 39.45 42.47 44.37 37.73 L 44.39 37.71 L 44.4 37.7 C 47.93 34.19 50.62 29.9 52.26 25.21 L 52.27 25.17 L 52.29 25.13 C 55.26 15.73 51.83 4.27 41.19 0.82 C 39.45 0.25 37.65 0 35.89 0 C 31.94 -0 29.02 1.65 26.66 3.34 C 24.31 1.67 21.37 0 17.46 0 Z";

  const SHARE_PATH = "M13.4 4.3c0-1 1.2-1.5 1.9-.8l6.9 6.6c.5.5.5 1.3 0 1.8l-6.9 6.6c-.7.7-1.9.2-1.9-.8v-3.2c-4.6.1-8 1.6-10.3 4.6-.6.8-1.9.3-1.7-.7C2.7 11.2 6.9 7 13.4 6.2V4.3Z";

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

    const blockDoc = post.body_json ? NEWSBLOCKS.validateDoc(post.body_json) : null;
    const asBlocks = !!(blockDoc && blockDoc.ok);

    const h = document.createElement("h2");
    h.textContent = picked.title;
    card.append(h);

    if (post.image_url && !asBlocks) {
      const img = document.createElement("img");

      const pctNum = Number(post.image_pct);
      const pct = Number.isFinite(pctNum) && pctNum >= 10 && pctNum <= 100 ? pctNum : 100;
      img.style.width = pct + "%";

      const align = NEWS.ALIGNS.find(a => a.key === post.image_align) || NEWS.ALIGNS[1];

      const wrap = !!post.image_wrap && align.key !== "center";

      img.className = "nw-image " + (wrap
        ? (align.key === "left" ? "nw-img-float-left" : "nw-img-float-right")
        : "nw-img-" + align.key);

      img.src = post.image_url;
      img.alt = picked.title;
      img.loading = "lazy";
      img.decoding = "async";

      if (post.image_width && post.image_height) {
        img.width = post.image_width;
        img.height = post.image_height;
      }
      card.append(img);
    }

    const body = document.createElement("div");
    body.className = "nw-body";
    if (asBlocks) {
      body.append(NEWSBLOCKS.renderBlocks(document, blockDoc.blocks, lang));
    } else {
      for (const para of NEWS.toParagraphs(picked.body)) {
        const p = document.createElement("p");
        p.textContent = para;
        body.append(p);
      }
    }
    card.append(body);

    const footer = document.createElement("div");
    footer.className = "nw-footer";
    const likeBtn = document.createElement("button");
    likeBtn.type = "button";
    likeBtn.className = "nw-like";

    const likeHeart = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    likeHeart.setAttribute("class", "nw-like-heart");
    likeHeart.setAttribute("viewBox", "-2 0 57.3 51.2");
    likeHeart.setAttribute("aria-hidden", "true");
    const heartPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
    heartPath.setAttribute("d", HEART_PATH);
    heartPath.setAttribute("fill", "currentColor");
    heartPath.setAttribute("fill-rule", "evenodd");
    likeHeart.append(heartPath);
    const likeCount = document.createElement("span");
    likeCount.className = "nw-like-count";
    renderLikeButton(likeBtn, likeHeart, likeCount, isPostLiked(post.id), post.likes || 0);

    if (post.id > 0) {
      likeBtn.addEventListener("click", () => toggleNewsLike(post, likeBtn, likeHeart, likeCount));
    } else {
      likeBtn.disabled = true;
    }
    likeBtn.append(likeHeart, likeCount);
    footer.append(likeBtn);

    if (post.id > 0) {
      const copy = document.createElement("button");
      copy.type = "button";
      copy.className = "nw-copy";

      const copyIcon = document.createElementNS("http://www.w3.org/2000/svg", "svg");
      copyIcon.setAttribute("viewBox", "0 0 24 24");
      copyIcon.setAttribute("aria-hidden", "true");
      copyIcon.setAttribute("class", "nw-copy-icon");
      const copyPath = document.createElementNS("http://www.w3.org/2000/svg", "path");
      copyPath.setAttribute("d", SHARE_PATH);
      copyPath.setAttribute("fill", "currentColor");
      copyIcon.append(copyPath);
      copy.append(copyIcon);

      copy.title = tx("news.copyLink");
      copy.setAttribute("aria-label", tx("news.copyLink"));
      copy.addEventListener("click", () => copyPostLink(post, copy));
      footer.append(copy);
    }
    card.append(footer);

    if (withTools && isAdmin) {
      const tools = document.createElement("div");
      tools.className = "nw-tools";
      const edit = document.createElement("button");
      edit.type = "button";
      edit.textContent = "✎";
      edit.title = tx("news.edit");
      edit.addEventListener("click", () => { if (editHook) { editHook(post); } });
      const del = document.createElement("button");
      del.type = "button";
      del.className = "danger";
      del.textContent = "✕";
      del.title = tx("news.delete");
      del.addEventListener("click", () => { if (deleteHook) { deleteHook(post); } });
      tools.append(edit, del);
      card.append(tools);
    }

    if (asBlocks) {
      const reveal = target => {
        const el = target.closest && target.closest(".nw-spoiler, .nw-quote-collapsible");
        if (!el) { return false; }
        el.classList.add("is-open");
        return true;
      };
      card.addEventListener("click", ev => { reveal(ev.target); });
      card.addEventListener("keydown", ev => {
        if (ev.key !== "Enter" && ev.key !== " ") { return; }
        if (reveal(ev.target)) { ev.preventDefault(); }
      });
    }

    return card;
  }

  function render() {
    const visible = activeCat === "all"
      ? posts
      : posts.filter(p => p.category === activeCat);

    if (!visible.length) {
      showState(activeCat === "all" ? "news.emptyAll" : "news.empty", false);
      focusLinkedPost();
      return;
    }

    stateEl.hidden = true;
    feedEl.innerHTML = "";
    for (const post of visible) { feedEl.append(cardFor(post)); }
    focusLinkedPost();
  }

  function focusLinkedPost() {
    if (!LINKED_POST_ID || !noticeEl) { return; }

    const inFeed = posts.some(p => p.id === LINKED_POST_ID);
    const card = feedEl.querySelector('[data-id="' + LINKED_POST_ID + '"]');

    if (card) {
      noticeEl.hidden = true;
      card.classList.add("nw-linked");
      if (!linkedPostScrolled) {
        linkedPostScrolled = true;
        const reduceMotion = window.matchMedia
          && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        card.scrollIntoView({ behavior: reduceMotion ? "auto" : "smooth", block: "start" });

        card.setAttribute("tabindex", "-1");
        card.focus({ preventScroll: true });
      }
      return;
    }

    if (inFeed || posts.length === 0) {
      noticeEl.hidden = true;
    } else {
      noticeEl.hidden = false;
      noticeEl.textContent = tx("news.linkedPostMissing");
    }
  }

  function renderFilters() {
    if (!filtersEl) return;
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

  function applyLang(next) {
    lang = next;
    try { localStorage.setItem(LANG_KEY, next); } catch (_) {}
    document.documentElement.lang = next;
    for (const node of document.querySelectorAll("[data-i18n]")) {
      node.textContent = I18N.t(node.dataset.i18n, lang);
    }

    for (const node of document.querySelectorAll("[data-i18n-label]")) {
      node.setAttribute("aria-label", I18N.t(node.dataset.i18nLabel, lang));
    }

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

    if (langHook) { langHook(); }
  }

  const isAdmin = window.NX_ADMIN_PAGE === true;

  let copyStatusEl = null;

  async function copyPostLink(post, btn) {
    const url = "https://maknemy.com/news/" + post.id;
    let ok = false;
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        await navigator.clipboard.writeText(url);
        ok = true;
      }
    } catch (e) {
      ok = false;
    }
    if (!ok) { ok = copyViaFallback(url); }
    showCopyFeedback(btn, ok);
  }

  function copyViaFallback(text) {
    const ta = document.createElement("textarea");
    ta.value = text;

    ta.style.position = "fixed";
    ta.style.top = "-1000px";
    ta.style.left = "-1000px";
    ta.setAttribute("readonly", "");
    document.body.append(ta);
    ta.focus();
    ta.select();
    let ok = false;
    try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
    ta.remove();
    return ok;
  }

  let copyFeedbackTimer = null;
  function showCopyFeedback(btn, ok) {
    if (copyStatusEl) { copyStatusEl.textContent = tx(ok ? "news.copyLinkDone" : "news.copyLinkFailed"); }
    if (!btn) { return; }
    clearTimeout(copyFeedbackTimer);
    btn.classList.remove("nw-copy-ok", "nw-copy-fail");
    btn.classList.add(ok ? "nw-copy-ok" : "nw-copy-fail");
    btn.title = tx(ok ? "news.copyLinkDone" : "news.copyLinkFailed");
    copyFeedbackTimer = setTimeout(() => {
      btn.classList.remove("nw-copy-ok", "nw-copy-fail");
      btn.title = tx("news.copyLink");
    }, 2000);
  }

  for (const b of document.querySelectorAll("#langSwitch .chip")) {
    b.addEventListener("click", () => applyLang(b.dataset.lang));
  }

  copyStatusEl = document.createElement("div");
  copyStatusEl.className = "nw-sr-only";
  copyStatusEl.setAttribute("aria-live", "polite");
  document.body.append(copyStatusEl);

  const PROMO_API = "/api/promo.php";

  function fillNewsRail(el, camp) {
    const promo = window.PROMO;
    const cre = promo && camp ? promo.creativeFor(camp, "rail") : null;
    if (!cre || !cre.src) return false;

    el.innerHTML = "";
    el.classList.add("has-ad");

    if (el.parentElement) el.parentElement.hidden = false;

    const img = document.createElement("img");
    img.src = cre.src;
    img.alt = tx("ad.imageAlt");
    img.loading = "lazy";
    img.decoding = "async";
    img.draggable = false;
    el.append(img);

    const chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.textContent = tx("ad.chip");
    el.append(chip);

    if (camp.erid) {
      const erid = document.createElement("span");
      erid.className = "ptn-erid";
      erid.textContent = "erid: " + camp.erid;
      el.append(erid);
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

  function renderNewsPromo() {
    const promo = window.PROMO;
    const left = document.getElementById("newsRailL");
    const right = document.getElementById("newsRailR");
    const dock = document.getElementById("promoDock");
    if (!promo) return;

    fetch(PROMO_API, { cache: "no-store" })
      .then(r => (r.ok ? r.json() : null))
      .catch(() => null)
      .then(doc => {
        if (dock && window.NX_PROMO_DOCK) window.NX_PROMO_DOCK.render(dock, doc, PROMO_PAGE);

        if (window.NX_PROMO_POPUP) window.NX_PROMO_POPUP.mount({ doc, isAdmin, page: PROMO_PAGE });

        if (!left || !right) return;

        const paid = doc ? promo.eligible(promo.normalizeDoc(doc), "rail", Date.now(), PROMO_PAGE) : [];

        const house = promo.houseFor("rail", Date.now(), PROMO_PAGE);
        const list = paid.length ? paid : (house && house.id !== promo.HOUSE_SLOT.id ? [house] : []);
        if (!list.length) return;
        fillNewsRail(left, list[0]);

        fillNewsRail(right, list[1] || list[0]);
      });
  }

  NX_PROTECT.applyClass(isAdmin);
  NX_PROTECT.install(() => isAdmin);

  applyLang(lang);
  load();
  renderNewsPromo();

  window.NEWSPAGE = {
    cardFor: cardFor,
    reload: load,
    getLang: () => lang,
    isAdmin: () => isAdmin,
    onLang: fn => { langHook = typeof fn === "function" ? fn : null; },
    onEdit: fn => { editHook = typeof fn === "function" ? fn : null; },
    onDelete: fn => { deleteHook = typeof fn === "function" ? fn : null; }
  };
})();
