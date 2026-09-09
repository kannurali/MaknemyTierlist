
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
  var railToggle= $('ctRailToggle');
  var shell     = $('ctShell');

  if (!list || !log) { return; }

  var state = { me: '', threads: [], thread: 0, ready: false, authed: false };

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
      b.dataset.thread = String(t.id);
      if (t.id === state.thread) { b.classList.add('is-open'); }

      var ava = document.createElement('span');
      ava.className = 'ct-ava';
      ava.dataset.status = t.peer.status;
      if (t.peer.avatar) {
        var img = document.createElement('img');
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

  function renderRoom(messages, peer) {
    log.textContent = '';

    roomTitle.textContent = peer
      ? tx('chat.with', 'Чат с') + ' ' + peer.nick
      : tx('chat.title', 'Чаты');
    if (peer) { roomTitle.removeAttribute('data-i18n'); }
    else { roomTitle.setAttribute('data-i18n', 'chat.title'); }

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
    log.scrollTop = log.scrollHeight;
  }

  function bubble(m) {
    var li = document.createElement('li');
    li.className = 'ct-msg' + (m.mine ? ' is-mine' : '');

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

  async function load(threadId) {
    var mine = ++seq;
    shell.classList.add('is-loading');
    try {
      var url = '/api/chat.php' + (threadId ? '?thread=' + encodeURIComponent(threadId) : '');
      var res = await fetch(url, { cache: 'no-store' });
      if (mine !== seq) { return; }
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      var d = await res.json();
      if (mine !== seq) { return; }

      state.me      = d.me;
      state.ready   = !!d.ready;
      state.authed  = !!d.authed;
      state.threads = d.threads || [];
      state.thread  = d.thread || 0;

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
      renderRoom(d.messages || [], open ? open.peer : null);

      setStars(d.review ? d.review.stars : 0);
      reviewTxt.value = d.review ? d.review.body : '';
      reviewSt.textContent = '';

      pageEmpty.hidden = state.ready;
      if (!state.ready) {
        pageEmpty.textContent = tx('chat.notReady', 'Чаты появятся вместе с аккаунтами');
      }
    } catch (e) {
      if (mine !== seq) { return; }
      shell.hidden = false;
      gate.hidden = true;
      list.textContent = '';
      log.textContent = '';
      compose.hidden = true;
      review.hidden = true;
      pageEmpty.hidden = false;
      pageEmpty.textContent = tx('chat.error', 'Не удалось загрузить чаты. Попробуйте обновить страницу.');
    } finally {
      if (mine === seq) { shell.classList.remove('is-loading'); }
    }
  }

  async function post(url, payload) {
    var res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    var data = null;
    try { data = await res.json(); } catch (e) {  }
    return { ok: res.ok, status: res.status, data: data };
  }
  list.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-thread]') : null;
    if (!b) { return; }
    load(Number(b.dataset.thread));
  });

  compose.addEventListener('submit', async function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || !state.thread) { return; }
    input.value = '';
    input.disabled = true;

    var r = await post('/api/chat_send.php', { thread: state.thread, body: text });
    input.disabled = false;
    input.focus();

    if (r.ok && r.data && r.data.ok) {
      log.appendChild(bubble(r.data.message));
      roomEmpty.hidden = true;
      log.scrollTop = log.scrollHeight;
      load(state.thread);
    } else {
      input.value = text;
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
    reviewSt.textContent = (r.ok && r.data && r.data.ok)
      ? tx('chat.reviewSaved', 'Отзыв сохранён')
      : tx('chat.reviewFailed', 'Отзыв не сохранился');
  });
  if (railToggle) {
    railToggle.addEventListener('click', function () {
      var open = shell.classList.toggle('rail-open');
      railToggle.setAttribute('aria-expanded', String(open));
      railToggle.setAttribute('aria-label', open
        ? tx('chat.hideList', 'Скрыть список диалогов')
        : tx('chat.showList', 'Показать список диалогов'));
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
      if (open) { roomTitle.textContent = tx('chat.with', 'Чат с') + ' ' + open.peer.nick; }
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

  load(0);
})();
