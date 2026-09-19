
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

  var state = { me: '', threads: [], thread: 0, ready: false, authed: false, messages: [] };

  var drafts = {};
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
        pre.textContent = (t.last.mine ? tx('chat.you', 'вы') + ': ' : '') + t.last.body;
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
    var all = emojiButtons();
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
    if (next) { buildEmoji(); }
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
        : '';
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

    var body = document.createElement('p');
    body.className = 'ct-body';
    body.textContent = m.body;
    li.appendChild(body);

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

      var sig = JSON.stringify([d.thread, d.threads, d.messages, d.ready, d.authed]);
      if (quiet && sig === lastSig) { return; }
      lastSig = sig;

      state.me      = d.me;
      state.ready   = !!d.ready;
      state.authed  = !!d.authed;
      state.threads  = d.threads || [];
      state.thread   = d.thread || 0;
      state.messages = d.messages || [];

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

  compose.addEventListener('submit', async function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || !state.thread) { return; }
    input.value = '';
    drafts[state.thread] = '';
    input.disabled = true;

    var r = await post('/api/chat_send.php', { thread: state.thread, body: text });
    input.disabled = false;
    input.focus();

    if (r.ok && r.data && r.data.ok) {
      log.appendChild(bubble(r.data.message));
      roomEmpty.hidden = true;
      log.scrollTop = log.scrollHeight;
      keepDrafts();
      load(state.thread, true);
    } else {
      input.value = text;
      drafts[state.thread] = text;
      roomEmpty.hidden = false;
      roomEmpty.textContent = (r.data && r.data.error === 'rate_limited')
        ? tx('chat.tooFast', 'Слишком часто. Подождите немного.')
        : tx('chat.sendFailed', 'Сообщение не отправилось. Попробуйте ещё раз.');
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

  if (wanted) {
    if (history.replaceState) {
      try { history.replaceState(null, '', location.pathname); } catch (e) {}
    }
    openWith(wanted[1]).then(function (id) {
      load(id).then(pollLater, pollLater);
    }, function () {
      load(0).then(pollLater, pollLater);
    });
  } else {
    load(0).then(pollLater, pollLater);
  }
})();
