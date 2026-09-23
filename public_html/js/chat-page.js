
(function () {
  'use strict';

  var LANG_KEY = 'nexus-lang-v1';
  var i18n = window.I18N;

  var $ = function (id) { return document.getElementById(id); };
  var list      = $('ctList');
  var railEmpty = $('ctRailEmpty');
  var log       = $('ctLog');
  var roomTitle = $('ctRoomTitle');
  var roomEmpty = $('ctRoomEmpty');
  var compose   = $('ctCompose');
  var input     = $('ctInput');
  var review    = $('ctReview');
  var reviewTxt = $('ctReviewText');
  var stars     = $('ctStars');
  var reviewSt  = $('ctReviewStatus');
  var pageEmpty = $('ctEmpty');
  var gate      = $('ctGate');
  var rail      = $('ctRail');
  var railToggle= $('ctRailToggle');
  var shell     = $('ctShell');

  if (!list || !log) { return; }

  var CALC = window.CALC;

  var COPY_OK = 'input, textarea, .ct-nick, .ct-handle, .ct-peer-link';

  function copyAllowed(node) {
    var el = node && node.nodeType === 1 ? node : (node ? node.parentElement : null);
    return !!(el && el.closest && el.closest(COPY_OK));
  }

  ['copy', 'cut', 'contextmenu', 'selectstart'].forEach(function (type) {
    shell.addEventListener(type, function (e) {
      if (!copyAllowed(e.target)) { e.preventDefault(); }
    });
  });

  shell.addEventListener('dragstart', function (e) {
    if (!copyAllowed(e.target) || e.target.nodeName === 'IMG') { e.preventDefault(); }
  });

  var state = { me: '', threads: [], thread: 0, ready: false, authed: false, messages: [] };

  var drafts = {};
  var offerRef = {};
  var reviewDrafts = {};

  var PROFILE_PATH = '/profile';

  var BODY_MAX = 2000;

  var POLL_MS = 10000;

  var EMOJI = '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 🥲 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😶‍🌫️ 😏 😒 🙄 😬 😮‍💨 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🤧 🥵 🥶 🥴 😵 😵‍💫 🤯 🤠 🥳 🥸 😎 🤓 🧐 😕 😟 🙁 ☹️ 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 ☠️ 💩 🤡 👹 👺 👻 👽 👾 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾 🙈 🙉 🙊';


  function lang() { return document.documentElement.lang === 'en' ? 'en' : 'ru'; }
  function tx(key, fallback) { return i18n ? i18n.t(key, lang()) : fallback; }
  function when(sec) {
    var d = new Date(sec * 1000);
    var now = new Date();
    var sameDay = d.toDateString() === now.toDateString();
    var hh = String(d.getHours()).padStart(2, '0');
    var mm = String(d.getMinutes()).padStart(2, '0');
    if (sameDay) { return hh + ':' + mm; }
    return String(d.getDate()).padStart(2, '0') + '.' +
           String(d.getMonth() + 1).padStart(2, '0') + ' ' + hh + ':' + mm;
  }
  function renderList() {
    list.textContent = '';
    var has = state.threads.length > 0;
    railEmpty.hidden = has;
    shell.classList.toggle('is-empty', !has);
    if (!has) {
      railEmpty.textContent = state.ready
        ? tx('chat.noThreads', 'Диалогов пока нет')
        : tx('chat.notReady', 'Чаты появятся вместе с аккаунтами');
      return;
    }

    state.threads.forEach(function (t) {
      var li = document.createElement('li');

      var b = document.createElement('button');
      b.className = 'ct-contact';
      b.type = 'button';
      b.setAttribute('role', 'tab');
      b.setAttribute('aria-selected', String(t.id === state.thread));
      b.setAttribute('aria-controls', 'ctRoom');
      b.tabIndex = t.id === state.thread ? 0 : -1;
      b.dataset.thread = String(t.id);
      if (t.id === state.thread) { b.classList.add('is-open'); }

      var ava = document.createElement('span');
      ava.className = 'ct-ava';
      ava.dataset.status = t.peer.status;
      if (t.peer.avatar) {
        var img = document.createElement('img');
        img.referrerPolicy = 'no-referrer';
        img.src = t.peer.avatar;
        img.alt = '';
        ava.appendChild(img);
      }
      b.appendChild(ava);

      var meta = document.createElement('span');
      meta.className = 'ct-meta';

      var nick = document.createElement('span');
      nick.className = 'ct-nick';
      nick.textContent = t.peer.nick;
      meta.appendChild(nick);

      var handle = document.createElement('span');
      handle.className = 'ct-handle';
      handle.textContent = t.peer.handle;
      meta.appendChild(handle);

      var st = document.createElement('span');
      st.className = 'ct-status';
      st.textContent = tx('chat.status.' + t.peer.status, t.peer.status);
      meta.appendChild(st);

      if (t.last) {
        var pre = document.createElement('span');
        pre.className = 'ct-preview';
        pre.textContent = (t.last.mine ? tx('chat.you', 'вы') + ': ' : '') + previewText(t.last.body);
        meta.appendChild(pre);
      }

      b.appendChild(meta);
      li.appendChild(b);
      list.appendChild(li);
    });
  }

  function roomTitleFor(peer) {
    roomTitle.textContent = '';
    if (!peer) {
      roomTitle.textContent = tx('chat.title', 'Чаты');
      roomTitle.setAttribute('data-i18n', 'chat.title');
      return;
    }
    roomTitle.removeAttribute('data-i18n');
    roomTitle.appendChild(document.createTextNode(tx('chat.with', 'Чат с') + ' '));

    var a = document.createElement('a');
    a.className = 'ct-peer-link';
    a.href = PROFILE_PATH + '?id=' + encodeURIComponent(peer.id);
    a.textContent = peer.nick;
    a.setAttribute('aria-label', tx('chat.peerProfile', 'Профиль игрока') + ' ' + peer.nick);
    a.title = tx('chat.peerProfile', 'Профиль игрока');
    a.draggable = false;
    a.addEventListener('click', function (e) {
      var sel = window.getSelection ? window.getSelection() : null;
      if (sel && !sel.isCollapsed && sel.containsNode(a, true)) { e.preventDefault(); }
    });
    roomTitle.appendChild(a);
  }

  var emojiBtn   = $('ctEmojiBtn');
  var emojiPanel = $('ctEmoji');
  var emojiBuilt = false;
  var emojiAt    = 0;

  function emojiButtons() {
    return emojiPanel ? emojiPanel.querySelectorAll('.ct-emoji-item') : [];
  }

  function emojiFocus(i) {
    var all = emojiButtons();
    if (!all.length) { return; }
    emojiAt = Math.max(0, Math.min(all.length - 1, i));
    for (var k = 0; k < all.length; k++) { all[k].tabIndex = k === emojiAt ? 0 : -1; }
    all[emojiAt].focus();
  }

  function emojiColumns() {
    return columnsOf(emojiButtons());
  }

  function columnsOf(all) {
    if (all.length < 2) { return 1; }
    var top = all[0].offsetTop;
    var n = 1;
    while (n < all.length && all[n].offsetTop === top) { n++; }
    return n;
  }

  function insertEmoji(ch) {
    if (!input) { return; }
    var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    var end   = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
    var next  = input.value.slice(0, start) + ch + input.value.slice(end);
    if (Array.from(next).length > BODY_MAX) { return; }
    input.value = next;
    var caret = start + ch.length;
    input.focus();
    if (input.setSelectionRange) { input.setSelectionRange(caret, caret); }
  }

  function buildEmoji() {
    if (emojiBuilt || !emojiPanel) { return; }
    emojiBuilt = true;
    EMOJI.split(' ').forEach(function (ch, i) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ct-emoji-item';
      b.textContent = ch;
      b.tabIndex = i === 0 ? 0 : -1;
      b.addEventListener('click', function () { insertEmoji(ch); });
      emojiPanel.appendChild(b);
    });
  }

  function emojiOpen(next) {
    if (!emojiBtn || !emojiPanel) { return; }
    if (next) { buildEmoji(); stickersOpen(false); }
    emojiPanel.hidden = !next;
    emojiBtn.setAttribute('aria-expanded', next ? 'true' : 'false');
  }

  if (emojiBtn && emojiPanel) {
    emojiBtn.addEventListener('click', function () {
      var open = emojiPanel.hidden;
      emojiOpen(open);
      if (open) { emojiFocus(emojiAt); }
    });

    emojiPanel.addEventListener('keydown', function (e) {
      var all = emojiButtons();
      if (!all.length) { return; }
      var cols = emojiColumns();
      var next = null;
      if (e.key === 'ArrowRight')      { next = emojiAt + 1; }
      else if (e.key === 'ArrowLeft')  { next = emojiAt - 1; }
      else if (e.key === 'ArrowDown')  { next = emojiAt + cols; }
      else if (e.key === 'ArrowUp')    { next = emojiAt - cols; }
      else if (e.key === 'Home')       { next = 0; }
      else if (e.key === 'End')        { next = all.length - 1; }
      else { return; }
      e.preventDefault();
      emojiFocus(next);
    });

    emojiPanel.addEventListener('focusin', function (e) {
      var all = emojiButtons();
      for (var k = 0; k < all.length; k++) {
        if (all[k] === e.target) { emojiAt = k; break; }
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || emojiPanel.hidden) { return; }
      var inside = emojiPanel.contains(document.activeElement) || document.activeElement === emojiBtn;
      emojiOpen(false);
      if (inside) { emojiBtn.focus(); }
    });

    document.addEventListener('click', function (e) {
      if (emojiPanel.hidden) { return; }
      if (emojiPanel.contains(e.target) || emojiBtn.contains(e.target)) { return; }
      emojiOpen(false);
    });
  }

  var STICKER_RE = /^\[sticker:([A-Za-z0-9_-]{1,40})\]$/;

  var items = [];
  var itemsById = Object.create(null);
  var itemsState = '';

  function stickerId(body) {
    var m = STICKER_RE.exec(typeof body === 'string' ? body : '');
    return m ? m[1] : '';
  }

  function itemsSettled() { return itemsState === 'ready' || itemsState === 'error'; }

  async function loadItems() {
    if (itemsState) { return; }
    itemsState = 'loading';
    try {
      var rev = null;
      try {
        var sr = await fetch('/api/state.php', { cache: 'no-store' });
        var st = sr.ok ? await sr.json() : null;
        if (st && typeof st.rev === 'number') { rev = st.rev; }
      } catch (e) {  }
      var url = '/api/tierlist.php' + (rev !== null ? '?rev=' + encodeURIComponent(rev) : '');
      var res = await fetch(url, { cache: rev !== null ? 'default' : 'no-store' });
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      var d = await res.json();
      if (!d || !d.tierlist || !CALC) { throw new Error('no tierlist'); }
      items = CALC.sortCatalog(CALC.flattenTierlist(d.tierlist));
      itemsById = CALC.buildCatalogIndex(items);
      itemsState = 'ready';
    } catch (e) {
      itemsState = 'error';
    }
    refreshStickers();
  }

  function refreshStickers() {
    log.querySelectorAll('.ct-sticker').forEach(fillSticker);
    var inRail = state.threads.some(function (t) { return t.last && stickerId(t.last.body); });
    if (inRail) { renderList(); }
    if (stickerPanel && !stickerPanel.hidden) { renderStickerGrid(); }
  }

  function previewText(body) {
    var id = stickerId(body);
    if (!id) { return body; }
    if (!itemsSettled()) { loadItems(); }
    var it = itemsById[id];
    return tx('chat.sticker', 'Стикер') + (it && it.name ? ': ' + it.name : '');
  }

  function fillSticker(card) {
    var it = itemsById[card.dataset.item];
    card.textContent = '';
    card.classList.toggle('is-pending', !it && !itemsSettled());
    card.classList.toggle('is-lost', !it && itemsSettled());
    card.setAttribute('role', 'img');
    card.setAttribute('aria-label', tx('chat.sticker', 'Стикер') + (it && it.name ? ': ' + it.name : ''));
    if (!it) {
      if (itemsSettled()) {
        var lost = document.createElement('span');
        lost.className = 'ct-sticker-name';
        lost.textContent = tx('chat.sticker', 'Стикер');
        card.appendChild(lost);
      } else {
        loadItems();
      }
      return;
    }

    var code = CALC.badgeCodeFor(it.type);
    card.style.setProperty('--ct-plate', 'var(--ct-plate-' + code + ')');

    var art = document.createElement('span');
    art.className = 'ct-sticker-art';
    var icon = document.createElement('img');
    icon.className = 'ct-sticker-icon';
    icon.src = it.icon || '';
    icon.alt = '';
    icon.decoding = 'async';
    icon.draggable = false;
    art.appendChild(icon);
    card.appendChild(art);

    var name = document.createElement('span');
    name.className = 'ct-sticker-name';
    name.textContent = it.name || '';
    card.appendChild(name);

    var badgeBox = document.createElement('span');
    badgeBox.className = 'ct-sticker-badge-box';
    var badge = document.createElement('img');
    badge.className = 'ct-sticker-badge';
    badge.src = 'assets/design/legend/badge-' + code + '.svg';
    badge.alt = '';
    badge.draggable = false;
    badgeBox.appendChild(badge);
    card.appendChild(badgeBox);
  }

  var stickerBtn   = $('ctStickerBtn');
  var stickerPanel = $('ctStickers');
  var stickerFind  = $('ctStickerSearch');
  var stickerGrid  = $('ctStickerGrid');
  var stickerNote  = $('ctStickerNote');
  var stickerBusy  = false;

  function stickerButtons() {
    return stickerGrid ? stickerGrid.querySelectorAll('.ct-sticker-item') : [];
  }

  function renderStickerGrid() {
    if (!stickerGrid) { return; }
    stickerGrid.textContent = '';
    if (itemsState !== 'ready') {
      stickerNote.textContent = itemsState === 'error'
        ? tx('chat.stickerError', 'Не удалось загрузить предметы')
        : tx('chat.stickerLoading', 'Загружаем предметы…');
      return;
    }
    var shown = CALC.filterCatalog(items, null, stickerFind.value);
    stickerNote.textContent = shown.length ? '' : tx('chat.stickerNone', 'Ничего не нашлось');
    shown.forEach(function (it, i) {
      var li = document.createElement('li');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'ct-sticker-item';
      b.dataset.item = it.id;
      b.tabIndex = i === 0 ? 0 : -1;
      b.title = it.name || '';
      b.setAttribute('aria-label', tx('chat.stickerSend', 'Отправить стикер') + ': ' + (it.name || ''));

      var img = document.createElement('img');
      img.src = it.icon || '';
      img.alt = '';
      img.loading = 'lazy';
      img.decoding = 'async';
      img.draggable = false;
      b.appendChild(img);

      var cap = document.createElement('span');
      cap.className = 'ct-sticker-cap';
      cap.textContent = it.name || '';
      b.appendChild(cap);

      li.appendChild(b);
      stickerGrid.appendChild(li);
    });
  }

  function stickersOpen(next) {
    if (!stickerBtn || !stickerPanel) { return; }
    if (next) {
      emojiOpen(false);
      loadItems();
      renderStickerGrid();
    }
    stickerPanel.hidden = !next;
    stickerBtn.setAttribute('aria-expanded', next ? 'true' : 'false');
  }

  async function sendSticker(id) {
    if (!state.thread || stickerBusy) { return; }
    stickerBusy = true;
    var inside = stickerPanel.contains(document.activeElement);
    stickersOpen(false);
    if (inside) { stickerBtn.focus(); }
    var ok = await send('[sticker:' + id + ']');
    stickerBusy = false;
    if (ok) {
      keepDrafts();
      load(state.thread, true);
    }
  }

  if (stickerBtn && stickerPanel) {
    stickerBtn.addEventListener('click', function () {
      var open = stickerPanel.hidden;
      stickersOpen(open);
      if (open && window.matchMedia && matchMedia('(hover: hover) and (pointer: fine)').matches) {
        stickerFind.focus();
      }
    });

    stickerFind.addEventListener('input', renderStickerGrid);

    stickerFind.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' && e.key !== 'ArrowDown') { return; }
      e.preventDefault();
      var first = stickerButtons()[0];
      if (first) { first.focus(); }
    });

    stickerGrid.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.ct-sticker-item') : null;
      if (b) { sendSticker(b.dataset.item); }
    });

    stickerGrid.addEventListener('keydown', function (e) {
      var all = stickerButtons();
      var at = [].indexOf.call(all, document.activeElement);
      if (at < 0) { return; }
      var cols = columnsOf(all);
      var next = null;
      if (e.key === 'ArrowRight')      { next = at + 1; }
      else if (e.key === 'ArrowLeft')  { next = at - 1; }
      else if (e.key === 'ArrowDown')  { next = at + cols; }
      else if (e.key === 'ArrowUp')    { next = at - cols; }
      else if (e.key === 'Home')       { next = 0; }
      else if (e.key === 'End')        { next = all.length - 1; }
      else { return; }
      e.preventDefault();
      if (next < 0 && e.key === 'ArrowUp') { stickerFind.focus(); return; }
      next = Math.max(0, Math.min(all.length - 1, next));
      for (var k = 0; k < all.length; k++) { all[k].tabIndex = k === next ? 0 : -1; }
      all[next].focus();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || stickerPanel.hidden) { return; }
      var inside = stickerPanel.contains(document.activeElement) || document.activeElement === stickerBtn;
      stickersOpen(false);
      if (inside) { stickerBtn.focus(); }
    });

    document.addEventListener('click', function (e) {
      if (stickerPanel.hidden) { return; }
      if (stickerPanel.contains(e.target) || stickerBtn.contains(e.target)) { return; }
      stickersOpen(false);
    });
  }

  var notifyBox   = $('ctNotify');
  var bell        = $('ctBell');
  var notifyPanel = $('ctNotifyPanel');
  var notifyText  = $('ctNotifyText');
  var notifyGo    = $('ctNotifyGo');
  var notifyOff   = $('ctNotifyOff');
  var roomHead    = bell ? bell.closest('.ct-room-head') : null;

  var TG_URL = 'https://t.me/';

  var tg = { on: false, linked: false, name: '', mod: false };
  var tgUrl = '';
  var tgWaiting = false;
  var tgFailed = false;
  var tgBusy = false;

  function renderBell() {
    if (!notifyBox) { return; }
    var show = !!(state.authed && tg.on);
    notifyBox.hidden = !show;
    if (roomHead) { roomHead.classList.toggle('has-bell', show); }
    if (!show) { notifyOpen(false); return; }

    bell.dataset.on = tg.linked ? 'true' : 'false';

    var msg;
    if (tgFailed) {
      msg = tx('chat.notifyFailed', 'Не получилось. Попробуйте ещё раз.');
    } else if (tg.linked) {
      msg = tx('chat.notifyLinked', 'Уведомления приходят в Telegram') + (tg.name ? ': ' + tg.name : '') + '.';
      if (tg.mod) { msg += ' ' + tx('chat.notifyMod', 'Сюда же приходят новые обращения в поддержку.'); }
    } else if (tgWaiting) {
      msg = tx('chat.notifyWait', 'Нажмите «Start» в Telegram — и уведомления включатся.');
    } else {
      msg = tx('chat.notifyIntro', 'Бот в Telegram сообщит, кто вам написал. Сам текст останется на сайте.');
    }
    notifyText.textContent = msg;
    notifyGo.hidden  = tg.linked;
    notifyOff.hidden = !tg.linked;
  }

  function setTg(next) {
    var was = tg.linked;
    tg = {
      on: !!(next && next.on),
      linked: !!(next && next.linked),
      name: next && typeof next.name === 'string' ? next.name : '',
      mod: !!(next && next.mod),
    };
    if (tg.linked) {
      tgWaiting = false;
      tgUrl = '';
      if (!was) { tgFailed = false; }
    }
    renderBell();
  }

  async function prepareLink() {
    if (tgBusy || tg.linked) { return; }
    tgBusy = true;
    tgUrl = '';
    notifyGo.classList.add('is-busy');
    notifyGo.setAttribute('aria-disabled', 'true');
    var r = await post('/api/tg_link.php', { action: 'link', lang: lang() });
    tgBusy = false;
    notifyGo.classList.remove('is-busy');
    notifyGo.removeAttribute('aria-disabled');
    var url = r.ok && r.data && r.data.ok && typeof r.data.url === 'string' ? r.data.url : '';
    if (url.indexOf(TG_URL) === 0) {
      tgUrl = url;
      notifyGo.href = url;
      tgFailed = false;
    } else {
      tgFailed = true;
    }
    renderBell();
  }

  function notifyOpen(next) {
    if (!notifyPanel || !bell) { return; }
    var wasOpen = !notifyPanel.hidden;
    notifyPanel.hidden = !next;
    bell.setAttribute('aria-expanded', next ? 'true' : 'false');
    if (next && !wasOpen && !tg.linked) {
      tgWaiting = false;
      tgFailed = false;
      renderBell();
      prepareLink();
    }
  }

  if (bell && notifyPanel) {
    bell.addEventListener('click', function () {
      notifyOpen(notifyPanel.hidden);
    });

    notifyGo.addEventListener('click', function (e) {
      if (!tgUrl) { e.preventDefault(); return; }
      tgWaiting = true;
      renderBell();
    });

    notifyOff.addEventListener('click', async function () {
      notifyOff.disabled = true;
      var r = await post('/api/tg_link.php', { action: 'unlink' });
      notifyOff.disabled = false;
      if (r.ok && r.data && r.data.ok) {
        setTg({ on: tg.on, linked: false, name: '', mod: tg.mod });
        tgFailed = false;
        prepareLink();
      } else {
        tgFailed = true;
        renderBell();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape' || notifyPanel.hidden) { return; }
      var inside = notifyPanel.contains(document.activeElement) || document.activeElement === bell;
      notifyOpen(false);
      if (inside) { bell.focus(); }
    });

    document.addEventListener('click', function (e) {
      if (notifyPanel.hidden) { return; }
      if (notifyPanel.contains(e.target) || bell.contains(e.target)) { return; }
      notifyOpen(false);
    });
  }

  function sameHead(messages) {
    var shown = log.querySelectorAll('.ct-msg');
    if (!shown.length || shown.length > messages.length) { return -1; }
    for (var i = 0; i < shown.length; i++) {
      if (shown[i].dataset.id !== String(messages[i].id)) { return -1; }
    }
    return shown.length;
  }

  function renderRoom(messages, peer, keepScroll) {
    var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 24;
    var keepAt   = keepScroll ? log.scrollTop : 0;

    roomTitleFor(peer);

    var from = keepScroll && peer ? sameHead(messages) : -1;
    if (from >= 0) {
      for (var k = from; k < messages.length; k++) { log.appendChild(bubble(messages[k])); }
      if (messages.length > from) {
        roomEmpty.hidden = true;
        log.scrollTop = atBottom ? log.scrollHeight : keepAt;
      }
      return;
    }

    log.textContent = '';

    var open = !!peer;
    compose.hidden = !open;
    review.hidden  = !open;

    if (!open) {
      roomEmpty.hidden = false;
      roomEmpty.textContent = state.threads.length
        ? tx('chat.pick', 'Выберите диалог слева')
        : railEmpty.textContent;
      return;
    }

    roomEmpty.hidden = messages.length > 0;
    if (!messages.length) {
      roomEmpty.textContent = tx('chat.noMessages', 'Сообщений пока нет — напишите первым');
    }

    messages.forEach(function (m) { log.appendChild(bubble(m)); });
    log.scrollTop = (keepScroll && !atBottom) ? keepAt : log.scrollHeight;
  }

  function bubble(m) {
    var li = document.createElement('li');
    li.className = 'ct-msg' + (m.mine ? ' is-mine' : '');
    li.dataset.id = String(m.id);

    var sticker = stickerId(m.body);
    if (sticker) {
      li.classList.add('is-sticker');
      var card = document.createElement('div');
      card.className = 'ct-sticker';
      card.dataset.item = sticker;
      fillSticker(card);
      li.appendChild(card);
    } else {
      var body = document.createElement('p');
      body.className = 'ct-body';
      body.textContent = m.body;
      li.appendChild(body);
    }

    var t = document.createElement('time');
    t.className = 'ct-time';
    t.dateTime = new Date(m.at * 1000).toISOString();
    t.textContent = when(m.at);
    li.appendChild(t);
    return li;
  }

  function setStars(n) {
    var boxes = stars.querySelectorAll('input[type="radio"]');
    boxes.forEach(function (b) { b.checked = (Number(b.value) === n); });
    stars.dataset.value = String(n || 0);
  }

  function currentStars() {
    var on = stars.querySelector('input[type="radio"]:checked');
    return on ? Number(on.value) : 0;
  }
  var seq = 0;

  var lastSig = '';

  async function load(threadId, quiet) {
    var mine = quiet ? seq : ++seq;
    if (!quiet) { shell.classList.add('is-loading'); }
    try {
      var url = '/api/chat.php' + (threadId ? '?thread=' + encodeURIComponent(threadId) : '');
      var res = await fetch(url, { cache: 'no-store' });
      if (mine !== seq) { return; }
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      var d = await res.json();
      if (mine !== seq) { return; }

      var sig = JSON.stringify([d.thread, d.threads, d.messages, d.ready, d.authed, d.tg]);
      if (quiet && sig === lastSig) { return; }
      lastSig = sig;

      state.me      = d.me;
      state.ready   = !!d.ready;
      state.authed  = !!d.authed;
      state.threads  = d.threads || [];
      state.thread   = d.thread || 0;
      state.messages = d.messages || [];

      setTg(d.tg);

      if (!state.authed) {
        shell.hidden = true;
        gate.hidden = false;
        gate.textContent = tx('chat.login', 'Войдите через Roblox, чтобы переписываться');
        pageEmpty.hidden = true;
        return;
      }
      gate.hidden = true;
      shell.hidden = false;

      var open = state.threads.filter(function (t) { return t.id === state.thread; })[0];

      renderList();
      renderRoom(d.messages || [], open ? open.peer : null, quiet);

      if (!quiet) { input.value = drafts[state.thread] || ''; }

      if (!quiet) {
        var kept = reviewDrafts[state.thread];
        setStars(kept ? kept.stars : (d.review ? d.review.stars : 0));
        reviewTxt.value = kept ? kept.body : (d.review ? d.review.body : '');
        if (!kept) { reviewSt.textContent = ''; }
      }

      pageEmpty.hidden = state.ready;
      if (!state.ready) {
        pageEmpty.textContent = tx('chat.notReady', 'Чаты появятся вместе с аккаунтами');
      }
    } catch (e) {
      if (mine !== seq || quiet) { return; }
      shell.hidden = false;
      gate.hidden = true;
      list.textContent = '';
      log.textContent = '';
      compose.hidden = true;
      review.hidden = true;
      pageEmpty.hidden = false;
      pageEmpty.textContent = tx('chat.error', 'Не удалось загрузить чаты. Попробуйте обновить страницу.');
    } finally {
      if (mine === seq && !quiet) { shell.classList.remove('is-loading'); }
    }
  }

  async function post(url, payload) {
    try {
      var res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      var data = null;
      try { data = await res.json(); } catch (e) {  }
      return { ok: res.ok, status: res.status, data: data };
    } catch (e) {
      return { ok: false, status: 0, data: null };
    }
  }
  list.addEventListener('keydown', function (e) {
    var tabs = [].slice.call(list.querySelectorAll('[data-thread]'));
    if (!tabs.length) { return; }
    var at = tabs.indexOf(document.activeElement);
    if (at < 0) { return; }
    var next = null;
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight')   { next = at + 1; }
    else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { next = at - 1; }
    else if (e.key === 'Home')                             { next = 0; }
    else if (e.key === 'End')                              { next = tabs.length - 1; }
    else { return; }
    e.preventDefault();
    next = Math.max(0, Math.min(tabs.length - 1, next));
    tabs.forEach(function (t, i) { t.tabIndex = i === next ? 0 : -1; });
    tabs[next].focus();
  });

  list.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-thread]') : null;
    if (!b) { return; }
    keepDrafts();
    load(Number(b.dataset.thread));
  });

  function keepDrafts() {
    if (!state.thread) { return; }
    drafts[state.thread] = input.value;
    var n = currentStars();
    if (n || reviewTxt.value !== '') {
      reviewDrafts[state.thread] = { stars: n, body: reviewTxt.value };
    } else {
      delete reviewDrafts[state.thread];
    }
  }

  async function send(body) {
    var sentThread = state.thread;
    var payload = { thread: sentThread, body: body };
    if (offerRef[sentThread]) { payload.offer = offerRef[sentThread]; }
    var r = await post('/api/chat_send.php', payload);

    if (r.ok && r.data && r.data.ok) {
      delete offerRef[sentThread];
      log.appendChild(bubble(r.data.message));
      roomEmpty.hidden = true;
      log.scrollTop = log.scrollHeight;
      return true;
    }
    roomEmpty.hidden = false;
    roomEmpty.textContent = (r.data && r.data.error === 'rate_limited')
      ? tx('chat.tooFast', 'Слишком часто. Подождите немного.')
      : tx('chat.sendFailed', 'Сообщение не отправилось. Попробуйте ещё раз.');
    return false;
  }

  compose.addEventListener('submit', async function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || !state.thread) { return; }
    input.value = '';
    drafts[state.thread] = '';
    input.disabled = true;

    var ok = await send(text);
    input.disabled = false;
    input.focus();

    if (ok) {
      keepDrafts();
      load(state.thread, true);
    } else {
      input.value = text;
      drafts[state.thread] = text;
    }
  });

  review.addEventListener('submit', async function (e) {
    e.preventDefault();
    var n = currentStars();
    if (!state.thread) { return; }
    if (!n) {
      reviewSt.textContent = tx('chat.pickStars', 'Поставьте оценку');
      return;
    }
    var r = await post('/api/chat_review.php',
      { thread: state.thread, stars: n, body: reviewTxt.value.trim() });
    var saved = !!(r.ok && r.data && r.data.ok);
    if (saved) { delete reviewDrafts[state.thread]; }
    reviewSt.textContent = saved
      ? tx('chat.reviewSaved', 'Отзыв сохранён')
      : tx('chat.reviewFailed', 'Отзыв не сохранился');
  });
  function railOpen(next) {
    if (!railToggle) { return; }
    shell.classList.toggle('rail-open', next);
    railToggle.setAttribute('aria-expanded', String(next));
    railToggle.setAttribute('aria-label', next
      ? tx('chat.hideList', 'Скрыть список диалогов')
      : tx('chat.showList', 'Показать список диалогов'));
  }

  if (railToggle) {
    railToggle.addEventListener('click', function () {
      railOpen(!shell.classList.contains('rail-open'));
    });

    list.addEventListener('click', function () { railOpen(false); });

    document.addEventListener('click', function (e) {
      if (!shell.classList.contains('rail-open')) { return; }
      if (rail.contains(e.target) || railToggle.contains(e.target)) { return; }
      railOpen(false);
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && shell.classList.contains('rail-open')) {
        railOpen(false);
        railToggle.focus();
      }
    });
  }
  if (i18n) {
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (e) {  }
    var current = i18n.pickLang(stored, navigator.language);

    var apply = function (next) {
      if (next) {
        current = next;
        try { localStorage.setItem(LANG_KEY, current); } catch (e) {  }
      }
      document.documentElement.lang = current;

      document.querySelectorAll('[data-i18n]').forEach(function (el) {
        el.textContent = i18n.t(el.dataset.i18n, current);
      });
      document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
        el.title = i18n.t(el.dataset.i18nTitle, current);
      });
      document.querySelectorAll('[data-i18n-label]').forEach(function (el) {
        el.setAttribute('aria-label', i18n.t(el.dataset.i18nLabel, current));
      });
      document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
        el.placeholder = i18n.t(el.dataset.i18nPlaceholder, current);
      });
      document.querySelectorAll('#langSwitch [data-lang]').forEach(function (b) {
        var on = b.dataset.lang === current;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', String(on));
      });
      renderList();
      var open = state.threads.filter(function (t) { return t.id === state.thread; })[0];
      renderRoom(state.messages, open ? open.peer : null, true);
      log.querySelectorAll('.ct-sticker').forEach(fillSticker);
      if (stickerPanel && !stickerPanel.hidden) { renderStickerGrid(); }
      renderBell();

      if (!state.authed) { gate.textContent = tx('chat.login', 'Войдите через Roblox, чтобы переписываться'); }
      if (!state.ready)  { pageEmpty.textContent = tx('chat.notReady', 'Чаты появятся вместе с аккаунтами'); }
    };

    var box = document.getElementById('langSwitch');
    if (box) {
      box.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-lang]');
        if (btn) { apply(btn.dataset.lang); }
      });
    }
    apply();
  }

  var pollTimer = 0;

  function pollLater() {
    clearTimeout(pollTimer);
    if (document.hidden || !state.authed) { return; }
    pollTimer = setTimeout(pollNow, POLL_MS);
  }

  function pollNow() {
    if (document.hidden || !state.authed) { pollLater(); return; }
    load(state.thread, true).then(pollLater, pollLater);
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(pollTimer); return; }
    pollNow();
  });

  async function openWith(peer) {
    var r = await post('/api/chat_open.php', { to: peer });
    if (r.ok && r.data && r.data.ok) { return r.data.thread; }
    return 0;
  }

  var wanted = /[?&]to=(\d{1,20})(?:&|$)/.exec(location.search);

  var offerDraft = (function () {
    var m = /[?&]draft=([^&]*)/.exec(location.search);
    if (!m) { return ''; }
    try { return decodeURIComponent(m[1].replace(/\+/g, ' ')).slice(0, 500); } catch (e) { return ''; }
  })();

  var offerFromUrl = (function () {
    var m = /[?&]offer=(\d{1,10})(?:&|$)/.exec(location.search);
    return m ? Number(m[1]) : 0;
  })();

  if (wanted) {
    if (history.replaceState) {
      try { history.replaceState(null, '', location.pathname); } catch (e) {}
    }
    openWith(wanted[1]).then(function (id) {
      if (id && offerDraft && !drafts[id]) { drafts[id] = offerDraft; }
      if (id && offerFromUrl) { offerRef[id] = offerFromUrl; }
      load(id).then(pollLater, pollLater);
    }, function () {
      load(0).then(pollLater, pollLater);
    });
  } else {
    load(0).then(pollLater, pollLater);
  }
})();
