(() => {
  "use strict";

  const API = "/api/giveaway_admin.php";
  const UPLOAD = "/api/upload.php";
  const TIERLIST = "/api/tierlist.php";
  const MSK = 3 * 3600;
  const NO_DEADLINE = 4102444800;
  const NBSP = String.fromCharCode(160);

  const $ = id => document.getElementById(id);
  const GW = window.NX_GW;
  const NB = window.NEWSBLOCKS;

  const STATUS = {
    draft: ["Черновик", ""],
    live: ["Идёт", "live"],
    ended: ["Закончился — итоги подводятся", "off"],
    drawn: ["Разыгран", ""]
  };

  const PROBLEMS = {
    no_title: "нет названия",
    no_prizes: "нет призов",
    ends_in_past: "время конца уже прошло",
    ends_too_far: "конец дальше чем через 90 дней",
    channel_not_admin: "у бота нет нужных прав в одном из отмеченных каналов или каналов своих условий (см. «Каналы бота» слева)",
    max_below_prizes: "лимит участников меньше числа призов",
    no_max: "не указано, сколько нужно участников",
    invite_link: "бот не смог создать ссылку-приглашение (дайте ему в канале право «Добавление подписчиков»)"
  };

  const ERRORS = {
    not_ready: "Таблиц нет — выполните docs/migrations/2026-10-14-giveaways.sql.",
    "not ended": "Розыгрыш ещё идёт — завершите его досрочно или дождитесь конца.",
    drawn: "Победители уже выбраны.",
    tg_down: "Telegram не ответил при проверке подписки. Нажмите ещё раз через минуту.",
    tg_off: "Бот не настроен: нет tg_bot_token в config.php.",
    "not draft": "Розыгрыш уже опубликован.",
    "not live": "Розыгрыш уже закончился.",
    "body too large": "Описание слишком длинное.",
    "bad prize name": "У приза нет названия (или оно длиннее 60 символов).",
    "bad prize count": "Число победителей приза — от 1 до 1000.",
    "bad conds": "Своих условий — не больше пяти.",
    "bad cond text": "У условия нет текста (или он длиннее 100 символов).",
    "bad cond url": "Ссылка условия должна начинаться с https:// и быть без пробелов (до 300 символов).",
    "bad cond button": "Надпись на кнопке условия — до 24 символов.",
    "bad cond chat": "Канал проверки у условия не найден — выберите его заново.",
    "bad channels": "Каналов, которые проверяет бот (отмеченных и у своих условий), — не больше пяти.",
    conds_migrate: "Для своих условий выполните docs/migrations/2026-10-15-giveaway-conds.sql.",
    test_migrate: "Для тестовых розыгрышей выполните docs/migrations/2026-10-15-giveaway-test.sql.",
    "bad max_entries": "Число участников — целое, от 1 до 1 000 000.",
    "bad end_by": "Выберите, когда итоги: по времени или по числу участников.",
    max_migrate: "Для лимита участников выполните docs/migrations/2026-10-15-giveaway-max-entries.sql.",
    drop_migrate: "Чтобы снять гарантию, выполните docs/migrations/2026-10-18-giveaway-unsub.sql.",
    posts_migrate: "Для постов в канале выполните docs/migrations/2026-10-27-giveaway-posts.sql.",
    "bad posts": "Каналов для поста — не больше пяти.",
    "bad emoji": "В строке эмодзи слева — сам эмодзи (не буквы, до 8 знаков), справа id — только цифры, до 24. Строк — до 100.",
    "dup emoji": "Один и тот же эмодзи в списке дважды — оставьте одну строку.",
    unauthorized: "Сессия админа закончилась — войдите на сайт заново."
  };

  const S = {
    list: [],
    channels: [],
    bot: "",
    rights: [],
    addUrl: "",
    tgOn: false,
    postsOn: false,
    postLast: [],
    emoji: null,
    cur: null,
    image: null,
    dirty: false,
    busy: false
  };

  function call(body) {
    return fetch(API, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(r => r.json().catch(() => ({ ok: false, error: "HTTP " + r.status })));
  }

  function errText(r) {
    if (!r) return "Сайт не ответил.";
    if (r.error === "incomplete" && r.problems) {
      const where = r.channels && r.channels.length ? " Каналы: " + r.channels.join(", ") + "." : "";
      return "Нельзя опубликовать: " + r.problems.map(p => PROBLEMS[p] || p).join(", ") + "." + where;
    }
    return ERRORS[r.error] || ("Ошибка: " + (r.error || "неизвестно"));
  }

  function msg(text, bad) {
    const m = $("gwMsg");
    m.textContent = text || "";
    m.classList.toggle("bad", !!bad);
  }

  function hint(text, kind) {
    const h = $("gwHint");
    h.textContent = text || "";
    h.className = "hint" + (kind ? " " + kind : "");
  }

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function toInput(ts) {
    if (!ts) return "";
    const d = new Date((ts + MSK) * 1000);
    return d.getUTCFullYear() + "-" + pad(d.getUTCMonth() + 1) + "-" + pad(d.getUTCDate())
      + "T" + pad(d.getUTCHours()) + ":" + pad(d.getUTCMinutes());
  }

  function fromInput(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(v || "");
    if (!m) return 0;
    return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) / 1000 - MSK;
  }

  function fmtDate(ts) {
    if (!ts) return "—";
    const d = new Date((ts + MSK) * 1000);
    return pad(d.getUTCDate()) + "." + pad(d.getUTCMonth() + 1) + " " + pad(d.getUTCHours()) + ":" + pad(d.getUTCMinutes());
  }

  function sameFmt(a, b) {
    const keys = ["b", "i", "u", "st", "c", "sp", "href"];
    return keys.every(k => (a[k] || false) === (b[k] || false));
  }

  function pushSpan(out, s, fmt) {
    if (!s) return;
    const last = out[out.length - 1];
    if (last && sameFmt(last, fmt)) { last.s += s; return; }
    const sp = { s: s };
    ["b", "i", "u", "st", "c", "sp"].forEach(k => { if (fmt[k]) sp[k] = true; });
    if (fmt.href) sp.href = fmt.href;
    out.push(sp);
  }

  function inlineSpans(node, fmt, out) {
    node.childNodes.forEach(ch => {
      if (ch.nodeType === 3) {
        pushSpan(out, ch.nodeValue.split(NBSP).join(" ").replace(/[\r\n]+/g, " "), fmt);
        return;
      }
      if (ch.nodeType !== 1) return;
      const tag = ch.tagName;
      if (tag === "BR") { pushSpan(out, "\n", fmt); return; }
      const f = Object.assign({}, fmt);
      if (tag === "B" || tag === "STRONG") f.b = true;
      if (tag === "I" || tag === "EM") f.i = true;
      if (tag === "U") f.u = true;
      if (tag === "S" || tag === "STRIKE" || tag === "DEL") f.st = true;
      if (tag === "CODE") f.c = true;
      if (tag === "A") {
        const h = ch.getAttribute("href") || "";
        if (/^https?:\/\//i.test(h)) f.href = h;
      }
      if (ch.classList.contains("nw-spoiler")) f.sp = true;
      const st = ch.style;
      if (st) {
        if (st.fontWeight === "bold" || +st.fontWeight >= 600) f.b = true;
        if (st.fontStyle === "italic") f.i = true;
        const deco = (st.textDecorationLine || st.textDecoration || "");
        if (deco.indexOf("underline") >= 0) f.u = true;
        if (deco.indexOf("line-through") >= 0) f.st = true;
      }
      const isBlock = /^(P|DIV|LI|BLOCKQUOTE|H[1-6]|UL|OL)$/.test(tag);
      if (isBlock && out.length && !/\n$/.test(out[out.length - 1].s)) pushSpan(out, "\n", fmt);
      inlineSpans(ch, f, out);
    });
    return out;
  }

  function clean(spans) {
    while (spans.length && !spans[0].s.replace(/^[\s\n]+/, "")) spans.shift();
    while (spans.length && !spans[spans.length - 1].s.replace(/[\s\n]+$/, "")) spans.pop();
    if (spans.length) {
      spans[0].s = spans[0].s.replace(/^[\s\n]+/, "");
      const l = spans[spans.length - 1];
      l.s = l.s.replace(/[\s\n]+$/, "");
    }
    return spans.filter(sp => sp.s !== "");
  }

  function editorBlocks() {
    const blocks = [];
    const addP = spans => { if (spans.length) blocks.push({ t: "p", ru: spans, en: [] }); };
    const hasBlocks = n => !!n.querySelector("ul, ol, blockquote, p, div");
    const walk = container => {
      let loose = null;
      const flush = () => {
        if (!loose) return;
        const box = document.createElement("div");
        loose.forEach(n => box.appendChild(n.cloneNode(true)));
        addP(clean(inlineSpans(box, {}, [])));
        loose = null;
      };
      container.childNodes.forEach(n => {
        const tag = n.nodeType === 1 ? n.tagName : "";
        if (tag === "UL" || tag === "OL") {
          flush();
          const items = Array.from(n.children)
            .filter(li => li.tagName === "LI")
            .map(li => ({ ru: clean(inlineSpans(li, {}, [])), en: [] }))
            .filter(it => it.ru.length);
          if (items.length) blocks.push({ t: "list", ordered: tag === "OL", items: items });
        } else if (tag === "BLOCKQUOTE") {
          flush();
          const sp = clean(inlineSpans(n, {}, []));
          if (sp.length) blocks.push({ t: "quote", ru: sp, en: [], collapsible: false });
        } else if (tag === "P" || tag === "DIV" || /^H[1-6]$/.test(tag)) {
          flush();
          if (hasBlocks(n)) walk(n);
          else addP(clean(inlineSpans(n, {}, [])));
        } else {
          (loose = loose || []).push(n);
        }
      });
      flush();
    };
    walk($("fBody"));
    return blocks;
  }

  function fillEditor(blocks) {
    const root = $("fBody");
    root.textContent = "";
    if (blocks && blocks.length && NB) {
      root.appendChild(NB.renderBlocks(document, blocks, "ru"));
      root.querySelectorAll(".nw-spoiler").forEach(sp => { sp.removeAttribute("role"); sp.removeAttribute("tabindex"); });
      root.querySelectorAll("a").forEach(a => { a.removeAttribute("target"); a.removeAttribute("rel"); });
    }
    if (!root.firstChild) root.innerHTML = "<p><br></p>";
  }

  function closestIn(node, sel) {
    const root = $("fBody");
    let el = node && node.nodeType === 3 ? node.parentNode : node;
    while (el && el !== root) {
      if (el.matches && el.matches(sel)) return el;
      el = el.parentNode;
    }
    return null;
  }

  function unwrap(el) {
    const p = el.parentNode;
    while (el.firstChild) p.insertBefore(el.firstChild, el);
    p.removeChild(el);
  }

  function toggleSpoiler() {
    const sel = window.getSelection();
    if (!sel.rangeCount) return;
    const range = sel.getRangeAt(0);
    const inside = closestIn(range.commonAncestorContainer, ".nw-spoiler");
    if (inside) { unwrap(inside); return; }
    if (range.collapsed) return;
    const span = document.createElement("span");
    span.className = "nw-spoiler is-open";
    span.appendChild(range.extractContents());
    range.insertNode(span);
    const after = document.createTextNode("");
    span.parentNode.insertBefore(after, span.nextSibling);
    sel.removeAllRanges();
    const r = document.createRange();
    r.setStart(after, 0);
    r.collapse(true);
    sel.addRange(r);
  }

  function toggleQuote() {
    const sel = window.getSelection();
    const inQuote = sel.rangeCount && closestIn(sel.getRangeAt(0).commonAncestorContainer, "blockquote");
    document.execCommand("formatBlock", false, inQuote ? "p" : "blockquote");
  }

  function makeLink() {
    const sel = window.getSelection();
    const a = sel.rangeCount ? closestIn(sel.getRangeAt(0).commonAncestorContainer, "a") : null;
    const url = window.prompt("Адрес ссылки (https://…). Пусто — убрать ссылку.", a ? a.getAttribute("href") : "https://");
    if (url === null) return;
    const v = url.trim();
    if (!v) { if (a) unwrap(a); else document.execCommand("unlink"); return; }
    if (!/^https?:\/\/\S+$/i.test(v)) { msg("Ссылка должна начинаться с http:// или https://", true); return; }
    if (a) { a.setAttribute("href", v); return; }
    document.execCommand("createLink", false, v);
  }

  function wireEditor() {
    const root = $("fBody");
    try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (_) {}
    $("fTools").addEventListener("mousedown", e => { if (e.target.closest("button")) e.preventDefault(); });
    $("fTools").addEventListener("click", e => {
      const b = e.target.closest("button[data-cmd]");
      if (!b || S.cur && S.cur.status === "drawn") return;
      root.focus();
      const cmd = b.dataset.cmd;
      if (cmd === "spoiler") toggleSpoiler();
      else if (cmd === "quote") toggleQuote();
      else if (cmd === "link") makeLink();
      else if (cmd === "removeFormat") {
        document.execCommand("removeFormat");
        document.execCommand("unlink");
        const sel = window.getSelection();
        const sp = sel.rangeCount ? closestIn(sel.getRangeAt(0).commonAncestorContainer, ".nw-spoiler") : null;
        if (sp) unwrap(sp);
      } else document.execCommand(cmd);
      changed();
    });
    root.addEventListener("paste", e => {
      e.preventDefault();
      const text = ((e.clipboardData || window.clipboardData).getData("text/plain") || "").replace(/\r\n?/g, "\n");
      document.execCommand("insertText", false, text);
    });
    root.addEventListener("input", e => {
      if (e.inputType === "insertParagraph" || e.inputType === "insertOrderedList" || e.inputType === "insertUnorderedList") {
        const sel = window.getSelection();
        const sp = sel.rangeCount ? closestIn(sel.getRangeAt(0).startContainer, ".nw-spoiler") : null;
        if (sp && !sp.textContent.trim()) {
          const p = sp.parentNode;
          const mark = document.createTextNode("");
          p.insertBefore(mark, sp);
          unwrap(sp);
          const r = document.createRange();
          r.setStart(mark, 0);
          r.collapse(true);
          sel.removeAllRanges();
          sel.addRange(r);
        }
      }
      changed();
    });
  }

  let catalog = null;
  let pickFor = null;

  function iconPath(url) {
    const m = /^(?:https:\/\/(?:www\.)?maknemy\.com)?(\/images\/[0-9a-f]{40}\.(?:png|jpg|webp))$/.exec(url || "");
    return m ? m[1] : "";
  }

  function loadCatalog() {
    if (catalog) return Promise.resolve(catalog);
    return fetch(TIERLIST, { credentials: "same-origin" })
      .then(r => r.json())
      .then(d => {
        const t = d && d.tierlist ? (typeof d.tierlist === "string" ? JSON.parse(d.tierlist) : d.tierlist) : null;
        const seen = {};
        catalog = [];
        ((t && t.tiers) || []).forEach(tier => (tier.items || []).forEach(it => {
          const icon = iconPath(it.icon);
          if (!icon || !it.name || seen[icon]) return;
          seen[icon] = true;
          catalog.push({ name: String(it.name), icon: icon });
        }));
        return catalog;
      })
      .catch(() => { catalog = null; return []; });
  }

  function renderCatalog() {
    const grid = $("gwPickGrid");
    const q = $("gwPickSearch").value.trim().toLowerCase();
    grid.textContent = "";
    const list = (catalog || []).filter(it => !q || it.name.toLowerCase().indexOf(q) >= 0);
    $("gwPickEmpty").hidden = list.length > 0;
    list.forEach(it => {
      const b = document.createElement("button");
      b.type = "button";
      b.className = "ga-pick-item";
      b.title = it.name;
      const img = document.createElement("img");
      img.src = it.icon;
      img.alt = "";
      img.loading = "lazy";
      const cap = document.createElement("span");
      cap.textContent = it.name;
      b.append(img, cap);
      b.addEventListener("click", () => { applyIcon(pickFor, it); closePicker(); });
      grid.appendChild(b);
    });
  }

  function openPicker(row) {
    pickFor = row;
    $("gwPicker").hidden = false;
    $("gwPickSearch").value = "";
    $("gwPickEmpty").hidden = true;
    $("gwPickGrid").textContent = "Загружаем предметы…";
    loadCatalog().then(() => {
      if (!catalog || !catalog.length) { $("gwPickGrid").textContent = "Не удалось загрузить тирлист."; return; }
      renderCatalog();
    });
    setTimeout(() => $("gwPickSearch").focus(), 30);
  }

  function closePicker() {
    $("gwPicker").hidden = true;
    pickFor = null;
  }

  function setRowIcon(row, icon) {
    row.dataset.icon = icon || "";
    const btn = row.querySelector(".ga-prize-icon");
    btn.textContent = "";
    if (icon) {
      const img = document.createElement("img");
      img.src = icon;
      img.alt = "";
      btn.appendChild(img);
      btn.title = "Сменить иконку";
    } else {
      btn.textContent = "＋";
      btn.title = "Выбрать иконку из тирлиста";
    }
  }

  function applyIcon(row, item) {
    if (!row) return;
    const name = row.querySelector(".ga-prize-name");
    if (item && (!name.value.trim() || name.value === row.dataset.itemName)) {
      name.value = item.name;
    }
    row.dataset.itemName = item ? item.name : "";
    setRowIcon(row, item ? item.icon : "");
    changed();
  }

  function wirePicker() {
    $("gwPickClose").addEventListener("click", closePicker);
    $("gwPickNone").addEventListener("click", () => { applyIcon(pickFor, null); closePicker(); });
    $("gwPickSearch").addEventListener("input", renderCatalog);
    $("gwPicker").addEventListener("click", e => { if (e.target === $("gwPicker")) closePicker(); });
    document.addEventListener("keydown", e => { if (e.key === "Escape" && !$("gwPicker").hidden) closePicker(); });
  }

  function prizeRow(p) {
    const row = document.createElement("div");
    row.className = "ga-prize";
    const iconBtn = document.createElement("button");
    iconBtn.type = "button";
    iconBtn.className = "ga-prize-icon";
    iconBtn.addEventListener("click", () => { if (!locked()) openPicker(row); });
    row.appendChild(iconBtn);
    setRowIcon(row, p && p.icon ? p.icon : "");
    row.dataset.itemName = p ? p.name : "";
    const name = document.createElement("input");
    name.type = "text";
    name.maxLength = 60;
    name.placeholder = "Magnet (permanent)";
    name.value = p ? p.name : "";
    name.className = "ga-prize-name";
    const count = document.createElement("input");
    count.type = "number";
    count.min = "1";
    count.max = "1000";
    count.step = "1";
    count.value = p ? p.count : 1;
    count.className = "ga-prize-count";
    count.title = "Сколько победителей получат этот приз";
    const lab = document.createElement("span");
    lab.className = "muted small";
    lab.textContent = "победителей";
    const del = document.createElement("button");
    del.type = "button";
    del.className = "btn small danger";
    del.textContent = "✕";
    del.title = "Убрать приз";
    del.addEventListener("click", () => { row.remove(); changed(); });
    [name, count].forEach(i => i.addEventListener("input", changed));
    row.append(name, count, lab, del);
    return row;
  }

  function readPrizes() {
    return Array.from($("fPrizes").querySelectorAll(".ga-prize")).map(r => ({
      name: r.querySelector(".ga-prize-name").value.trim(),
      count: Math.max(1, Math.min(1000, parseInt(r.querySelector(".ga-prize-count").value, 10) || 1)),
      icon: r.dataset.icon || ""
    })).filter(p => p.name);
  }

  function condRow(c) {
    const row = document.createElement("div");
    row.className = "ga-cond";
    const ru = document.createElement("input");
    ru.type = "text";
    ru.maxLength = 100;
    ru.placeholder = "Подписаться на YouTube Maknemy";
    ru.value = c ? c.ru : "";
    ru.className = "ga-cond-ru";
    const en = document.createElement("input");
    en.type = "text";
    en.maxLength = 100;
    en.placeholder = "По-английски (пусто — переведём)";
    en.value = c ? c.en : "";
    en.className = "ga-cond-en";
    const btn = document.createElement("input");
    btn.type = "text";
    btn.maxLength = 24;
    btn.placeholder = "Кнопка: Подписаться";
    btn.title = "Надпись на кнопке. Пусто — «Подписаться»; английский переведётся сам.";
    btn.value = c ? c.btn_ru || "" : "";
    btn.className = "ga-cond-btn";
    const url = document.createElement("input");
    url.type = "url";
    url.maxLength = 300;
    url.placeholder = "https://youtube.com/@maknemy";
    url.value = c ? c.url : "";
    url.className = "ga-cond-url";
    const chat = document.createElement("select");
    chat.className = "ga-cond-chat";
    chat.title = "Если ссылка ведёт в канал, где бот — админ, выберите его: бот будет проверять подписку, как у отмеченных каналов выше.";
    fillCondChat(chat, c ? c.chat || "" : "");
    const del = document.createElement("button");
    del.type = "button";
    del.className = "btn small danger";
    del.textContent = "✕";
    del.title = "Убрать условие";
    del.addEventListener("click", () => { row.remove(); changed(); });
    [ru, en, btn].forEach(i => i.addEventListener("input", changed));
    url.addEventListener("input", () => {
      if (!chat.value) chat.value = chatByUrl(url.value);
      markTgCond(row);
      changed();
    });
    chat.addEventListener("change", () => { markTgCond(row); changed(); });
    row.append(ru, en, btn, url, chat, del);
    markTgCond(row);
    return row;
  }

  function fillCondChat(sel, cur) {
    sel.textContent = "";
    sel.add(new Option("Бот не проверяет", ""));
    S.channels.forEach(c => {
      sel.add(new Option("Проверять: " + (c.title || c.id) + (c.admin ? "" : " (у бота нет прав)"), c.id));
    });
    if (cur && !S.channels.some(c => c.id === cur)) sel.add(new Option("Проверять: канал " + cur + " (нет в списке)", cur));
    sel.value = cur;
  }

  function chatByUrl(raw) {
    const u = raw.trim().replace(/^https?:\/\/(?:www\.)?(?:t|telegram)\.me\//i, "https://t.me/").replace(/\/+$/, "");
    const name = /^https:\/\/t\.me\/([A-Za-z0-9_]{4,})$/.exec(u);
    const c = S.channels.find(ch => (ch.url && ch.url.replace(/\/+$/, "") === u)
      || (name && ch.username && ch.username.toLowerCase() === name[1].toLowerCase()));
    return c ? c.id : "";
  }

  function markTgCond(row) {
    const url = row.querySelector(".ga-cond-url").value.trim();
    const tg = /^https?:\/\/(?:www\.)?(?:t|telegram)\.me\//i.test(url);
    row.classList.toggle("is-tg-free", tg && !row.querySelector(".ga-cond-chat").value);
  }

  function refreshCondChats() {
    $("fConds").querySelectorAll(".ga-cond").forEach(r => {
      const sel = r.querySelector(".ga-cond-chat");
      fillCondChat(sel, sel.value);
      markTgCond(r);
    });
  }

  function readConds() {
    return Array.from($("fConds").querySelectorAll(".ga-cond")).map(r => ({
      ru: r.querySelector(".ga-cond-ru").value.trim(),
      en: r.querySelector(".ga-cond-en").value.trim(),
      btn_ru: r.querySelector(".ga-cond-btn").value.trim(),
      url: r.querySelector(".ga-cond-url").value.trim(),
      chat: r.querySelector(".ga-cond-chat").value
    })).filter(c => c.ru || c.url);
  }

  function renderChannelChecks(selected) {
    const box = $("fChannels");
    box.textContent = "";
    if (!S.channels.length) {
      const p = document.createElement("span");
      p.className = "muted small";
      p.textContent = "Каналов пока нет — см. «Каналы бота» слева.";
      box.appendChild(p);
      return;
    }
    S.channels.forEach(c => {
      const lab = document.createElement("label");
      lab.className = "page-chk" + (c.admin ? "" : " ga-ch-off");
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.value = c.id;
      cb.checked = selected.indexOf(c.id) >= 0;
      cb.addEventListener("change", changed);
      lab.append(cb, document.createTextNode(" " + (c.title || c.id) + (c.admin ? "" : " (у бота нет прав)")));
      box.appendChild(lab);
    });
  }

  function readChannels() {
    return Array.from($("fChannels").querySelectorAll("input:checked")).map(i => i.value);
  }

  function renderPostChecks(selected) {
    const sec = $("fPostBox");
    sec.hidden = !S.postsOn;
    if (!S.postsOn) return;
    const box = $("fPosts");
    box.textContent = "";
    const posts = (S.cur && S.cur.posts) || [];
    const byChat = {};
    posts.forEach(p => { byChat[p.chat] = p; });
    if (!S.channels.length) {
      const p = document.createElement("span");
      p.className = "muted small";
      p.textContent = "Каналов пока нет — см. «Каналы бота» слева.";
      box.appendChild(p);
      return;
    }
    S.channels.forEach(c => {
      const post = byChat[c.id];
      const lab = document.createElement("label");
      lab.className = "page-chk" + (c.admin || post ? "" : " ga-ch-off");
      const cb = document.createElement("input");
      cb.type = "checkbox";
      cb.value = c.id;
      cb.checked = !!post || selected.indexOf(c.id) >= 0;
      cb.disabled = locked() || !!(post && post.posted) || (!c.admin && !post);
      cb.addEventListener("change", changed);
      let note = "";
      if (post && post.posted) note = post.results ? " — пост с итогами" : " — пост вышел";
      else if (!c.admin) note = " (у бота нет прав)";
      lab.append(cb, document.createTextNode(" " + (c.title || c.id) + note));
      if (post && post.url) {
        const a = document.createElement("a");
        a.href = post.url;
        a.target = "_blank";
        a.rel = "noopener";
        a.textContent = " открыть ↗";
        lab.appendChild(a);
      }
      box.appendChild(lab);
    });
  }

  function readPosts() {
    return Array.from($("fPosts").querySelectorAll("input:checked")).map(i => i.value);
  }

  function emojiRow(emoji, id, where) {
    const row = document.createElement("div");
    row.className = "slot-row ga-emoji";
    const ch = document.createElement("input");
    ch.type = "text";
    ch.maxLength = 16;
    ch.placeholder = "🔥";
    ch.value = emoji;
    ch.title = "Обычный эмодзи — его бот заменит премиум";
    ch.style.width = "3.2em";
    ch.style.textAlign = "center";
    const inp = document.createElement("input");
    inp.type = "text";
    inp.inputMode = "numeric";
    inp.maxLength = 24;
    inp.placeholder = "id премиум-эмодзи";
    inp.value = id;
    inp.style.flex = "1";
    inp.style.minWidth = "0";
    const del = document.createElement("button");
    del.type = "button";
    del.className = "btn small";
    del.textContent = "✕";
    del.title = "Убрать — снова обычный эмодзи";
    del.addEventListener("click", () => row.remove());
    row.append(ch, inp, del);
    if (where) {
      const sub = document.createElement("div");
      sub.className = "li-sub";
      sub.style.flexBasis = "100%";
      sub.textContent = "в шаблоне: " + where;
      row.appendChild(sub);
    }
    return row;
  }

  function renderEmoji() {
    const box = $("gwEmojiBox");
    box.hidden = !S.emoji;
    if (!S.emoji) return;
    const list = $("gwEmoji");
    list.textContent = "";
    const tpl = {};
    S.emoji.template.forEach(t => { tpl[t.emoji] = t.where; });
    const have = {};
    S.emoji.list.forEach(e => {
      have[e.emoji] = true;
      list.appendChild(emojiRow(e.emoji, e.id, tpl[e.emoji] || ""));
    });
    S.emoji.template.forEach(t => { if (!have[t.emoji]) list.appendChild(emojiRow(t.emoji, "", t.where)); });
    $("gwEmojiHelp").textContent = "Слева обычный эмодзи, справа id премиум-эмодзи — можно из своего набора, строк сколько угодно."
      + " Где этот эмодзи встретится в посте или сообщении бота — в шаблоне, названии, описании, призах, — Telegram покажет премиум; на сайте останется обычный."
      + " Строка без id не сохраняется. Как узнать id: пришлите премиум-эмодзи " + (S.bot ? "боту @" + S.bot : "боту")
      + " в личку — он ответит его id. Где Telegram их покажет: в личных сообщениях бота — если у владельца бота Telegram Premium;"
      + " в канале — только если у бота куплен дополнительный ник на Fragment (бусты канала тут не помогают)."
      + " Где нельзя — останется обычный эмодзи, пост всё равно выйдет.";
  }

  function saveEmoji() {
    const rows = [];
    $("gwEmoji").querySelectorAll(".ga-emoji").forEach(r => {
      const ins = r.querySelectorAll("input");
      const emoji = ins[0].value.trim();
      const id = ins[1].value.trim();
      if (id !== "") rows.push({ emoji: emoji, id: id });
    });
    $("gwEmojiSave").disabled = true;
    call({ action: "emoji", rows: rows }).then(r => {
      $("gwEmojiSave").disabled = false;
      if (!r || !r.ok) { hint(errText(r), "bad"); return; }
      S.emoji = r.emoji;
      renderEmoji();
      hint("Эмодзи сохранены — уже вышедший пост обновится при следующем «Сохранить» розыгрыша", "ok");
    });
  }

  function postNote(r) {
    const parts = [];
    const p = r && r.post;
    if (p && p.posted) parts.push("пост вышел в каналах: " + p.posted);
    if (p && p.edited) parts.push("пост в канале обновлён");
    if (p && p.failed && p.failed.length) {
      parts.push("пост не вышел или не обновился: " + p.failed.join(", ") + " (нет прав у бота, пост удалён в канале или Telegram не ответил — нажмите «Сохранить» ещё раз)");
    }
    if (r && r.post_failed) parts.push("итоги в пост канала не дописались — бот повторит сам через минуту");
    return parts.length ? " " + parts.join("; ") + "." : "";
  }

  function renderChannelsList() {
    refreshCondChats();
    const ul = $("gwChannels");
    ul.textContent = "";
    S.channels.forEach(c => {
      const li = document.createElement("li");
      li.className = "ga-ch";
      const top = document.createElement("div");
      top.className = "ga-ch-top";
      const name = document.createElement("span");
      name.className = "ga-ch-name";
      name.textContent = c.title || c.id;
      const st = document.createElement("span");
      st.className = "pill " + (c.admin ? "live" : "off");
      st.textContent = c.admin ? "права есть" : "нет прав";
      top.append(name, st);
      const sub = document.createElement("div");
      sub.className = "li-sub";
      sub.textContent = (c.username ? "@" + c.username : "приватный") + (c.url ? "" : " · нет ссылки");
      const miss = document.createElement("div");
      miss.className = "li-sub ga-ch-miss";
      miss.hidden = c.admin;
      miss.textContent = c.missing && c.missing.length
        ? "Не хватает: " + c.missing.join(", ")
        : "Бот не админ канала или права ещё не проверены — нажмите «Обновить».";
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn small";
      btn.textContent = "Обновить";
      btn.title = "Перечитать у Telegram название, права бота и ссылку-приглашение";
      btn.addEventListener("click", () => {
        btn.disabled = true;
        call({ action: "channel", id: c.id }).then(r => {
          btn.disabled = false;
          if (r && r.channels) {
            S.channels = r.channels;
            renderChannelsList();
            renderChannelChecks(readChannels());
            renderPostChecks(readPosts());
            preview();
          }
          hint(r && r.ok ? "Канал обновлён" : "Telegram не ответил", r && r.ok ? "ok" : "bad");
        });
      });
      li.append(top, sub, miss, btn);
      ul.appendChild(li);
    });
    const help = $("gwChHelp");
    const bot = S.bot ? "@" + S.bot : "бота";
    help.textContent = (S.channels.length ? "" : "Каналов пока нет. ")
      + "Чтобы канал появился здесь, добавьте " + bot + " в канал администратором с правами: "
      + (S.rights.length ? S.rights.join(", ") : "—")
      + ". Без них канал нельзя поставить в условия. Если канал не появился — нажмите «Подключить вебхук» на /admin/support и добавьте бота заново.";
    if (S.addUrl) {
      const add = document.createElement("a");
      add.className = "btn small primary ga-ch-add";
      add.href = S.addUrl;
      add.target = "_blank";
      add.rel = "noopener";
      add.textContent = "Добавить бота в канал";
      help.append(document.createElement("br"), add);
    }
  }

  function renderList() {
    const ul = $("gwList");
    ul.textContent = "";
    $("gwEmpty").hidden = S.list.length > 0;
    $("gwListCount").textContent = S.list.length ? "(" + S.list.length + ")" : "";
    S.list.forEach(g => {
      const li = document.createElement("li");
      li.className = S.cur && S.cur.id === g.id ? "on" : "";
      const top = document.createElement("div");
      top.className = "li-top";
      const name = document.createElement("span");
      name.className = "li-name";
      name.textContent = g.title || "Без названия";
      const st = STATUS[g.phase] || STATUS.draft;
      const pill = document.createElement("span");
      pill.className = "pill " + st[1];
      pill.textContent = st[0];
      top.append(name, pill);
      if (g.test) {
        const tp = document.createElement("span");
        tp.className = "pill";
        tp.textContent = "Тест";
        top.appendChild(tp);
      }
      const sub = document.createElement("div");
      sub.className = "li-sub";
      const when = g.end_by === "entries" ? " · итоги на " + g.max + " участниках"
        : g.ends_at ? " · конец " + fmtDate(g.ends_at) + " МСК" : "";
      sub.textContent = "Участников: " + g.entries + (g.max ? " из " + g.max : "") + when;
      li.append(top, sub);
      li.addEventListener("click", () => open(g.id));
      ul.appendChild(li);
    });
  }

  function setImage(img) {
    S.image = img;
    const prev = $("fImgPrev");
    prev.hidden = !img;
    if (img) prev.src = img.url; else prev.removeAttribute("src");
    $("fImgDel").hidden = !img;
  }

  function locked() { return !!S.cur && S.cur.status === "drawn"; }

  function renderStatus() {
    const g = S.cur;
    const box = $("gwStatus");
    const phase = g && g.id ? g.phase : "draft";
    const st = STATUS[phase] || STATUS.draft;
    let text = g && g.id ? st[0] : "Новый розыгрыш — ещё не сохранён";
    if (g && g.test) text += " · тестовый";
    if (g && g.id && g.drop_unsub === false) text += " · без гарантии: отписавшиеся занимают места";
    if (g && g.id && g.status !== "draft" && g.max_entries && g.entries >= g.max_entries) text += " · лимит участников набран";
    if (g && g.published_at) text += " · опубликован " + fmtDate(g.published_at) + " МСК";
    if (g && g.drawn_at) text += " · разыгран " + fmtDate(g.drawn_at) + " МСК";
    box.textContent = text;
    box.className = "ga-status is-" + phase;

    $("aPublish").hidden = !(g && (!g.id || g.status === "draft"));
    $("aEnd").hidden = !(g && g.phase === "live");
    $("aDraw").hidden = !(g && g.phase === "ended");
    $("aDelete").hidden = !(g && g.id);
    $("aSave").hidden = locked();
    $("fCondsLive").hidden = !(g && g.status === "live");

    const dis = locked();
    $("gwEditor").querySelectorAll("input, textarea, select").forEach(i => { i.disabled = dis; });
    $("fTest").disabled = !!(g && g.id && g.status !== "draft");
    $("fBody").contentEditable = dis ? "false" : "true";
    $("fTools").querySelectorAll("button").forEach(b => { b.disabled = dis; });
    $("fPrizeAdd").disabled = dis;
    $("fCondAdd").disabled = dis;
    $("gwEditor").querySelectorAll(".ga-prize .btn, .ga-prize-icon, .ga-cond .btn, [data-plus]").forEach(b => { b.disabled = dis; });
  }

  function renderPeople() {
    const g = S.cur;
    const sec = $("gwPeople");
    sec.hidden = !(g && g.id && g.status !== "draft");
    if (sec.hidden) return;
    const all = g.rows || g.entries;
    const left = g.left ? (g.drop_unsub === false ? " · из них отписались: " : " · отписались (не считаются): ") + g.left : "";
    $("gwCount").textContent = g.entries + (g.max_entries ? " из " + g.max_entries : "") + left + (g.list.length < all ? " (показаны " + g.list.length + ")" : "");
    const body = $("gwPeopleBody");
    body.textContent = "";
    if (!g.list.length) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.colSpan = 6;
      td.className = "muted";
      td.textContent = "Пока никого.";
      tr.appendChild(td);
      body.appendChild(tr);
      return;
    }
    g.list.forEach(e => {
      const tr = document.createElement("tr");
      if (e.prize !== null) tr.className = "is-win";
      const who = document.createElement("td");
      if (e.tg_only) {
        who.textContent = (e.display || "Участник из Telegram") + " — без аккаунта на сайте";
        if (e.prize !== null) {
          const nick = document.createElement("div");
          nick.className = "li-sub";
          nick.textContent = e.nick ? "Ник в Roblox для приза: " + e.nick : "Ник в Roblox ещё не прислал боту";
          who.appendChild(nick);
        }
      } else {
        const a = document.createElement("a");
        a.href = "/profile?id=" + encodeURIComponent(e.id);
        a.target = "_blank";
        a.rel = "noopener";
        a.className = "ga-who";
        if (e.avatar) {
          const img = document.createElement("img");
          img.src = e.avatar;
          img.alt = "";
          img.referrerPolicy = "no-referrer";
          a.appendChild(img);
        }
        a.appendChild(document.createTextNode((e.display || e.name || "#" + e.id) + (e.name ? " @" + e.name : "")));
        who.appendChild(a);
      }
      const tg = document.createElement("td");
      tg.textContent = e.tg || "—";
      const when = document.createElement("td");
      when.textContent = fmtDate(e.joined_at);
      const from = document.createElement("td");
      from.textContent = SOURCES[e.src] || "—";
      const prize = document.createElement("td");
      prize.textContent = e.prize !== null && g.prizes[e.prize] ? "🏆 " + g.prizes[e.prize].name : "";
      if (e.left_at) {
        tr.style.opacity = "0.55";
        prize.textContent = "Отписался " + fmtDate(e.left_at) + " — не участвует";
      }
      const act = document.createElement("td");
      if (!e.tg_only || e.tg_user) {
        const chat = document.createElement("a");
        chat.href = e.tg_only ? "https://t.me/" + encodeURIComponent(e.tg_user) : "/chat?to=" + encodeURIComponent(e.id);
        chat.target = "_blank";
        chat.rel = "noopener";
        chat.className = "btn small";
        chat.textContent = e.tg_only ? "Написать в Telegram" : "Написать";
        act.appendChild(chat);
      }
      tr.append(who, tg, when, from, prize, act);
      body.appendChild(tr);
    });
  }

  const PLACES = {
    tier: "Тирлист, над первой полосой",
    trade: "Трейды, после второго объявления",
    calc: "Калькулятор, под итогом",
    stock: "Сток, над дилерами",
    news: "Новости, над лентой"
  };
  const SOURCES = { tg: "Бот", ch: "Пост в канале → сайт", cb: "Кнопка в канале (без сайта)", pop: "Окно на сайте", page: "Страница /giveaways" };
  Object.keys(PLACES).forEach(k => { SOURCES["c-" + k] = "Карточка: " + PLACES[k]; });

  function pct(a, b) {
    if (!b) return "—";
    const v = a / b * 100;
    return (v >= 10 ? Math.round(v) : Math.round(v * 10) / 10).toString().replace(".", ",") + "%";
  }

  function reportCards(cards) {
    const box = $("gwCards");
    box.textContent = "";
    if (!Array.isArray(cards) || !cards.length) { box.hidden = true; return; }
    box.hidden = false;
    const head = document.createElement("p");
    head.className = "ga-sources";
    head.textContent = "Карточки на страницах сайта: показ — карточку увидели (половиной площади, от секунды; одно место — раз за просмотр страницы), переход — нажали, участие — перешли с карточки и вступили.";
    box.appendChild(head);
    const wrap = document.createElement("div");
    wrap.className = "ga-table-wrap";
    const table = document.createElement("table");
    table.className = "ga-table ga-cards";
    const thead = document.createElement("thead");
    const hr = document.createElement("tr");
    ["Место", "Показы", "Переходы", "CTR", "Участия", "Участия / переходы"].forEach(h => {
      const th = document.createElement("th");
      th.textContent = h;
      hr.appendChild(th);
    });
    thead.appendChild(hr);
    const tbody = document.createElement("tbody");
    const total = { views: 0, clicks: 0, entries: 0 };
    const row = (name, c, cls) => {
      const tr = document.createElement("tr");
      if (cls) tr.className = cls;
      [name, c.views, c.clicks, pct(c.clicks, c.views), c.entries, pct(c.entries, c.clicks)].forEach(v => {
        const td = document.createElement("td");
        td.textContent = String(v);
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    };
    cards.forEach(c => {
      total.views += c.views; total.clicks += c.clicks; total.entries += c.entries;
      row(PLACES[c.place] || c.place, c);
    });
    row("Всего", total, "is-total");
    table.append(thead, tbody);
    wrap.appendChild(table);
    box.appendChild(wrap);
  }

  function fmtFull(ts) {
    if (!ts) return "";
    const d = new Date((ts + MSK) * 1000);
    return d.getUTCFullYear() + "-" + pad(d.getUTCMonth() + 1) + "-" + pad(d.getUTCDate())
      + " " + pad(d.getUTCHours()) + ":" + pad(d.getUTCMinutes());
  }

  function csvCell(v) {
    const s = String(v == null ? "" : v);
    return /[";\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
  }

  function downloadCsv(ch) {
    const head = ["Канал", "Telegram ID", "Telegram", "Подписался (МСК)", "Отписался (МСК)", "Сейчас подписан",
      "Аккаунт сайта (Roblox ID)", "Ник на сайте", "Участвует в розыгрыше", "Откуда пришёл"];
    const rows = [head].concat(ch.list.map(s => [
      ch.title, s.tg_id, s.tg_name, fmtFull(s.joined_at), fmtFull(s.left_at), s.left_at ? "нет" : "да",
      s.site_id, s.site_name, s.entered ? "да" : "нет", SOURCES[s.src] || ""
    ]));
    const text = rows.map(r => r.map(csvCell).join(";")).join("\r\n");
    const blob = new Blob([String.fromCharCode(0xFEFF) + text], { type: "text/csv;charset=utf-8" });
    const a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "giveaway-" + S.cur.id + "-" + ch.id.replace(/^-/, "") + ".csv";
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  }

  function reportChannel(ch) {
    const box = document.createElement("div");
    box.className = "ga-rep-ch";
    const head = document.createElement("div");
    head.className = "ga-rep-head";
    const name = document.createElement("b");
    name.textContent = ch.title;
    const nums = document.createElement("span");
    nums.className = "ga-rep-nums";
    nums.textContent = "по ссылке розыгрыша: " + ch.joined + " · сейчас подписаны: " + ch.stayed + " · отписались: " + (ch.joined - ch.stayed);
    head.append(name, nums);
    box.appendChild(head);

    const linkRow = document.createElement("div");
    linkRow.className = "slot-row";
    if (ch.link) {
      const inp = document.createElement("input");
      inp.type = "text";
      inp.readOnly = true;
      inp.value = ch.link;
      inp.className = "ga-rep-link";
      const copy = document.createElement("button");
      copy.type = "button";
      copy.className = "btn small";
      copy.textContent = "Копировать";
      copy.addEventListener("click", () => {
        inp.select();
        try { navigator.clipboard.writeText(ch.link); } catch (_) { document.execCommand("copy"); }
        hint("Ссылка скопирована", "ok");
      });
      linkRow.append(inp, copy);
      if (ch.counts === false) {
        const pub = document.createElement("span");
        pub.className = "muted small";
        pub.textContent = "Это публичный адрес канала: Telegram не сообщает, кто пришёл по нему. Чтобы считать подписчиков, поставьте в условие ссылку-приглашение (t.me/+…).";
        linkRow.appendChild(pub);
      }
    } else {
      const no = document.createElement("span");
      no.className = "muted small";
      no.textContent = "Ссылки розыгрыша нет — подписчики не считаются. Дайте боту право «Добавление подписчиков» и сохраните розыгрыш.";
      linkRow.appendChild(no);
    }
    const csv = document.createElement("button");
    csv.type = "button";
    csv.className = "btn small primary";
    csv.textContent = "Скачать CSV";
    csv.disabled = !ch.list.length;
    csv.addEventListener("click", () => downloadCsv(ch));
    linkRow.appendChild(csv);
    box.appendChild(linkRow);

    const note = document.createElement("p");
    note.className = "muted small";
    note.textContent = ch.own
      ? "Ссылка из своего условия: считаются вступления по ней, пока розыгрыш идёт. В канале она в «Пригласительных ссылках» под тем именем, которое ей дали при создании."
      : "То же число рекламодатель видит у себя: канал → «Пригласительные ссылки» → «Maknemy #" + S.cur.id + "».";
    if (ch.counts !== false) box.appendChild(note);

    if (ch.list.length) {
      const wrap = document.createElement("div");
      wrap.className = "ga-table-wrap";
      const table = document.createElement("table");
      table.className = "ga-table";
      const thead = document.createElement("thead");
      const hr = document.createElement("tr");
      ["Telegram", "Аккаунт сайта", "Подписался", "Отписался", "Участвует", "Откуда"].forEach(h => {
        const th = document.createElement("th");
        th.textContent = h;
        hr.appendChild(th);
      });
      thead.appendChild(hr);
      const tbody = document.createElement("tbody");
      ch.list.forEach(s => {
        const tr = document.createElement("tr");
        if (s.left_at) tr.className = "is-left";
        const cells = [
          s.tg_name || "#" + s.tg_id,
          s.site_id ? (s.site_name || "#" + s.site_id) : "—",
          fmtDate(s.joined_at),
          s.left_at ? fmtDate(s.left_at) : "",
          s.entered ? "да" : "нет",
          SOURCES[s.src] || "—"
        ];
        cells.forEach((c, i) => {
          const td = document.createElement("td");
          if (i === 1 && s.site_id) {
            const a = document.createElement("a");
            a.href = "/profile?id=" + encodeURIComponent(s.site_id);
            a.target = "_blank";
            a.rel = "noopener";
            a.textContent = c;
            td.appendChild(a);
          } else {
            td.textContent = c;
          }
          tr.appendChild(td);
        });
        tbody.appendChild(tr);
      });
      table.append(thead, tbody);
      wrap.appendChild(table);
      box.appendChild(wrap);
    }
    return box;
  }

  function loadReport() {
    const sec = $("gwReport");
    const g = S.cur;
    sec.hidden = true;
    if (!g || !g.id || g.status === "draft") return;
    const id = g.id;
    call({ action: "report", id: id }).then(r => {
      if (!S.cur || S.cur.id !== id || !r || !r.ok) return;
      const rep = r.report;
      const s = rep.sources;
      const fromCards = Object.keys(PLACES).reduce((n, k) => n + (s["c-" + k] || 0), 0);
      $("gwSources").textContent = "Откуда пришли участники: из бота " + s.tg + " · пост в канале → сайт " + (s.ch || 0)
        + " · кнопка в канале без сайта " + (s.cb || 0) + " · окно на сайте " + s.pop
        + " · страница /giveaways " + s.page + " · карточки на сайте " + fromCards + (s[""] ? " · неизвестно " + s[""] : "");
      reportCards(rep.cards);
      const box = $("gwReportChannels");
      box.textContent = "";
      if (!rep.channels.length) {
        const p = document.createElement("p");
        p.className = "muted small";
        p.textContent = "В розыгрыше нет каналов в условиях — считать подписчиков не для кого.";
        box.appendChild(p);
      }
      rep.channels.forEach(ch => box.appendChild(reportChannel(ch)));
      sec.hidden = false;
    });
  }

  function collect() {
    const out = {
      title_ru: $("fTitleRu").value.trim(),
      title_en: $("fTitleEn").value.trim(),
      image: S.image,
      body: { v: 1, blocks: editorBlocks() },
      prizes: readPrizes(),
      channels: readChannels(),
      conds: readConds(),
      end_by: endBy(),
      ends_at: endBy() === "time" ? fromInput($("fEnds").value) : 0,
      max_entries: endBy() === "entries" ? readMax() : 0,
      test: $("fTest").checked,
      drop_unsub: $("fDropUnsub").checked
    };
    if (S.postsOn) out.posts = readPosts();
    return out;
  }

  function readMax() {
    const v = $("fMax").value.trim();
    if (v === "") return 0;
    const n = Number(v);
    return Number.isInteger(n) ? n : -1;
  }

  function endBy() { return $("fEndEntries").checked ? "entries" : "time"; }

  function syncEndBy() {
    const byMax = endBy() === "entries";
    $("fEndTimeBox").hidden = byMax;
    $("fEndEntriesBox").hidden = !byMax;
    if (!byMax && !$("fEnds").value) $("fEnds").value = toInput(Math.floor(Date.now() / 1000) + 3 * 86400);
  }

  let prevTimer = 0;
  function preview() {
    clearTimeout(prevTimer);
    prevTimer = setTimeout(() => {
      const box = $("gwPrevBox");
      box.textContent = "";
      if (!S.cur || !GW) return;
      const d = collect();
      const chans = d.channels.map(id => S.channels.find(c => c.id === id)).filter(Boolean)
        .map(c => ({ id: c.id, title: c.title, url: c.url }));
      const phase = S.cur.id ? S.cur.phase : "live";
      const g = {
        id: S.cur.id || 0,
        phase: phase === "draft" ? "live" : phase,
        title: { ru: d.title_ru || "Без названия", en: d.title_en },
        image: d.image,
        body: d.body.blocks,
        prizes: d.prizes,
        channels: chans,
        conds: d.conds.map(c => ({ text: { ru: c.ru || c.url, en: c.en }, url: c.url, btn: { ru: c.btn_ru, en: "" }, chat: c.chat })),
        end_by: d.end_by,
        ends_at: d.end_by === "entries" ? NO_DEADLINE : d.ends_at || Math.floor(Date.now() / 1000) + 86400,
        entries: S.cur.entries || 0,
        max_entries: Math.max(0, d.max_entries),
        winners: (S.cur.list || []).filter(e => e.prize !== null)
          .map(e => ({ prize: e.prize, id: e.id, name: e.name, display: e.display, avatar: e.avatar }))
      };
      box.appendChild(GW.card(g, { mode: "page", preview: true }));
    }, 150);
  }

  function changed() {
    S.dirty = true;
    hint("Есть несохранённые правки", "");
    preview();
  }

  function fill(g) {
    S.cur = g;
    S.dirty = false;
    $("gwEditor").hidden = false;
    $("gwPreview").hidden = false;
    $("fTitleRu").value = g.title_ru || "";
    $("fTitleEn").value = g.title_en || "";
    setImage(g.image || null);
    fillEditor(g.body ? g.body.blocks : []);
    const pr = $("fPrizes");
    pr.textContent = "";
    (g.prizes && g.prizes.length ? g.prizes : [null]).forEach(p => pr.appendChild(prizeRow(p)));
    renderChannelChecks(g.channels || []);
    const cs = $("fConds");
    cs.textContent = "";
    (g.conds || []).forEach(c => cs.appendChild(condRow(c)));
    const byMax = g.end_by === "entries" || g.max_entries > 0;
    $(byMax ? "fEndEntries" : "fEndTime").checked = true;
    $("fEndLegacy").hidden = !(g.end_by === "time" && g.max_entries > 0 && g.status !== "drawn");
    $("fEnds").value = g.end_by === "entries" ? "" : toInput(g.ends_at);
    $("fMax").value = g.max_entries ? String(g.max_entries) : "";
    syncEndBy();
    $("fTest").checked = !!g.test;
    $("fDropUnsub").checked = g.drop_unsub !== false;
    msg("");
    hint("");
    renderStatus();
    renderPostChecks(g.posts ? [] : S.postLast);
    renderPeople();
    loadReport();
    renderList();
    preview();
  }

  function blank() {
    return {
      id: 0, status: "draft", phase: "draft", title_ru: "", title_en: "", image: null,
      body: { v: 1, blocks: [] }, prizes: [], channels: [], conds: [], ends_at: Math.floor(Date.now() / 1000) + 3 * 86400,
      published_at: 0, drawn_at: 0, test: false, drop_unsub: true, entries: 0, max_entries: 0, list: []
    };
  }

  function confirmLeave() {
    return !S.dirty || window.confirm("Есть несохранённые правки. Уйти без сохранения?");
  }

  function open(id) {
    if (S.cur && S.cur.id === id) return;
    if (!confirmLeave()) return;
    call({ action: "get", id: id }).then(r => {
      if (r && r.ok) fill(r.giveaway);
      else hint(errText(r), "bad");
    });
  }

  function loadList(selectId) {
    return call({ action: "list" }).then(r => {
      if (!r || !r.ok) {
        const a = $("gwAlert");
        a.textContent = errText(r);
        a.hidden = false;
        return;
      }
      $("gwAlert").hidden = !!r.tg;
      if (!r.tg) $("gwAlert").textContent = "Бот не настроен (tg_bot_token и tg_bot_name в config.php): участвовать никто не сможет, рассылки не уйдут.";
      S.list = r.giveaways;
      S.channels = r.channels;
      S.bot = r.bot;
      S.rights = r.rights || [];
      S.addUrl = r.add_url || "";
      S.tgOn = r.tg;
      S.postsOn = !!r.posts;
      S.postLast = r.post_last || [];
      S.emoji = r.emoji || null;
      renderChannelsList();
      renderEmoji();
      renderList();
      if (selectId) {
        S.dirty = false;
        return call({ action: "get", id: selectId }).then(g => { if (g && g.ok) fill(g.giveaway); });
      }
      if (!S.cur && S.list.length) open(S.list[0].id);
    });
  }

  function busy(on) {
    S.busy = on;
    ["aSave", "aPublish", "aEnd", "aDraw", "aDelete"].forEach(id => { $(id).disabled = on; });
  }

  function save() {
    if (S.busy) return Promise.resolve(null);
    const body = Object.assign({ action: "save" }, collect());
    if (S.cur && S.cur.id) body.id = S.cur.id;
    busy(true);
    hint("Сохраняем…");
    return call(body).then(r => {
      busy(false);
      if (!r || !r.ok) { hint("", ""); msg(errText(r), true); return null; }
      return loadList(r.id).then(() => {
        if (r.link_failed && r.link_failed.length) {
          hint("Сохранено, но в каналах " + r.link_failed.join(", ") + " бот не смог создать ссылку для учёта подписчиков", "bad");
        } else {
          hint(r.translate_failed ? "Сохранено, но перевод на английский не удался" : "Сохранено", r.translate_failed ? "bad" : "ok");
        }
        if (r.post) msg("Сохранено:" + postNote(r), !!(r.post.failed && r.post.failed.length));
        return r.id;
      });
    });
  }

  function act(action, ask, done) {
    if (S.busy || !S.cur) return;
    if (ask && !window.confirm(ask)) return;
    const go = id => {
      busy(true);
      msg(action === "draw" ? "Выбираем победителей и проверяем подписки…" : "");
      call({ action: action, id: id }).then(r => {
        busy(false);
        if (!r || !r.ok) { msg(errText(r), true); return; }
        if (action === "delete") {
          S.cur = null;
          S.dirty = false;
          $("gwEditor").hidden = true;
          $("gwPreview").hidden = true;
          loadList().then(() => hint(done(r), "ok"));
          return;
        }
        loadList(id).then(() => msg(done(r)));
      });
    };
    if (S.dirty || !S.cur.id) {
      save().then(id => { if (id) go(id); });
    } else {
      go(S.cur.id);
    }
  }

  function upload(file) {
    if (!file) return;
    if (file.size > 6 * 1024 * 1024) { msg("Картинка больше 6 МБ.", true); return; }
    const rd = new FileReader();
    rd.onload = () => {
      $("fImgNote").textContent = "Загружаем…";
      fetch(UPLOAD, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ data: rd.result, kind: "news", pct: 100 })
      }).then(r => r.json()).then(r => {
        if (!r || !r.url) { $("fImgNote").textContent = "Не загрузилась: " + ((r && r.error) || "ошибка"); return; }
        setImage({ url: r.url, w: r.width || 1200, h: r.height || 675 });
        $("fImgNote").textContent = "PNG, JPG или WebP до 6 МБ. Без баннера шапку соберут иконки призов.";
        changed();
      }).catch(() => { $("fImgNote").textContent = "Не загрузилась"; });
    };
    rd.readAsDataURL(file);
  }

  function init() {
    wireEditor();
    wirePicker();
    $("gwNew").addEventListener("click", () => { if (confirmLeave()) fill(blank()); });
    $("fPrizeAdd").addEventListener("click", () => { $("fPrizes").appendChild(prizeRow(null)); changed(); });
    $("fCondAdd").addEventListener("click", () => {
      if ($("fConds").children.length >= 5) { msg(ERRORS["bad conds"], true); return; }
      const row = condRow(null);
      $("fConds").appendChild(row);
      row.querySelector("input").focus();
      changed();
    });
    $("fImgFile").addEventListener("change", e => { upload(e.target.files[0]); e.target.value = ""; });
    $("fImgDel").addEventListener("click", () => { setImage(null); changed(); });
    ["fTitleRu", "fTitleEn", "fEnds", "fMax"].forEach(id => $(id).addEventListener("input", changed));
    ["fTest", "fDropUnsub"].forEach(id => $(id).addEventListener("change", changed));
    ["fEndTime", "fEndEntries"].forEach(id => $(id).addEventListener("change", () => { syncEndBy(); changed(); }));
    document.querySelectorAll("[data-plus]").forEach(b => b.addEventListener("click", () => {
      const base = Math.max(Math.floor(Date.now() / 1000), fromInput($("fEnds").value) || 0);
      $("fEnds").value = toInput(base + (+b.dataset.plus) * 86400);
      changed();
    }));
    $("aSave").addEventListener("click", save);
    $("gwEmojiSave").addEventListener("click", saveEmoji);
    $("gwEmojiAdd").addEventListener("click", () => {
      const row = emojiRow("", "", "");
      $("gwEmoji").appendChild(row);
      row.querySelector("input").focus();
    });
    $("aPublish").addEventListener("click", () => {
      const test = $("fTest").checked;
      act("publish",
        test
          ? "Опубликовать тестовый? Посетители его не увидят, рассылки не будет — только по ссылке /giveaways?test."
          : "Опубликовать? Розыгрыш появится на сайте, и бот сразу напишет об этом всем, кто его подключил.",
        r => (test ? "Тестовый опубликован. Откройте /giveaways?test." : "Опубликован. Бот рассылает сообщение о старте.") + postNote(r));
    });
    $("aEnd").addEventListener("click", () => act("end",
      "Закончить приём заявок прямо сейчас?", () => "Приём заявок закрыт. Теперь можно разыграть."));
    $("aDraw").addEventListener("click", () => act("draw",
      "Итоги подводятся сами сразу после конца — эта кнопка на случай, если они не появились (например, Telegram не ответил). Подвести сейчас? Победители выбираются один раз, отменить нельзя. Бот сразу напишет победителям.",
      r => "Готово: победителей " + r.winners + " из " + r.slots + " мест, участников " + r.entries
        + (r.skipped ? ", пропущено " + r.skipped + " (отписались, отключили бота или в бане)" : "") + "." + postNote(r)));
    $("aDelete").addEventListener("click", () => act("delete",
      "Удалить розыгрыш вместе со списком участников? Это нельзя отменить.", () => "Удалён."));
    window.addEventListener("beforeunload", e => { if (S.dirty) { e.preventDefault(); e.returnValue = ""; } });
    loadList();
  }

  init();
})();
