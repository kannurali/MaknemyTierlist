(function () {
  'use strict';

  var API = '/api/tg_link.php';
  var TG_URL = 'https://t.me/';
  var POLL_MS = 4000;
  var POLL_FOR = 15 * 60 * 1000;

  var i18n = window.I18N;

  function $(id) { return document.getElementById(id); }

  var bell  = $('pfBell');
  var panel = $('pfNotify');
  if (!bell || !panel) { return; }

  var text  = $('pfNotifyText');
  var go    = $('pfNotifyGo');
  var off   = $('pfNotifyOff');
  var err   = $('pfNotifyErr');
  var boxes = Array.prototype.slice.call(panel.querySelectorAll('input[data-topic]'));

  function lang() { return document.documentElement.lang === 'en' ? 'en' : 'ru'; }
  function tx(key, fallback) { return i18n ? i18n.t(key, lang()) : fallback; }

  var tg = {
    linked: panel.dataset.linked === '1',
    name: panel.dataset.name || '',
    mod: panel.dataset.mod === '1'
  };
  var url = '';
  var waiting = false;
  var failed = false;
  var busy = false;
  var pollTimer = null;
  var pollUntil = 0;

  function post(body) {
    return fetch(API, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (data) {
        return { ok: res.ok, data: data };
      });
    }).catch(function () {
      return { ok: false, data: null };
    });
  }

  function render() {
    bell.dataset.on = tg.linked ? 'true' : 'false';

    var msg;
    if (failed) {
      msg = tx('chat.notifyFailed', 'Не получилось. Попробуйте ещё раз.');
    } else if (tg.linked) {
      msg = tx('chat.notifyLinked', 'Уведомления приходят в Telegram') + (tg.name ? ': ' + tg.name : '') + '.';
      if (tg.mod) { msg += ' ' + tx('chat.notifyMod', 'Сюда же приходят новые обращения в поддержку.'); }
    } else if (waiting) {
      msg = tx('chat.notifyWait', 'Нажмите «Start» в Telegram — и уведомления включатся.');
    } else {
      msg = tx('profile.notifyIntro', 'Подключите Telegram — бот напишет, когда вам ответят в чате, а ещё об изменениях цен в тирлисте и новостях.');
    }
    text.textContent = msg;
    go.hidden  = tg.linked;
    off.hidden = !tg.linked;
  }

  function showError(on) {
    if (!err) { return; }
    err.hidden = !on;
    err.textContent = on ? tx('profile.notifySaveError', 'Не сохранилось — попробуйте ещё раз') : '';
  }

  function prepareLink() {
    if (busy || tg.linked) { return; }
    busy = true;
    url = '';
    go.classList.add('is-busy');
    go.setAttribute('aria-disabled', 'true');
    post({ action: 'link', lang: lang() }).then(function (r) {
      busy = false;
      go.classList.remove('is-busy');
      go.removeAttribute('aria-disabled');
      var next = r.ok && r.data && r.data.ok && typeof r.data.url === 'string' ? r.data.url : '';
      if (next.indexOf(TG_URL) === 0) {
        url = next;
        go.href = next;
        failed = false;
      } else {
        failed = true;
      }
      render();
    });
  }

  function stopPoll() {
    if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
  }

  function checkNow() {
    stopPoll();
    post({ action: 'status' }).then(function (r) {
      var s = r.ok && r.data && r.data.ok ? r.data.tg : null;
      if (s && s.linked) {
        tg = { linked: true, name: typeof s.name === 'string' ? s.name : '', mod: !!s.mod };
        waiting = false;
        failed = false;
        url = '';
        render();
        return;
      }
      schedule();
    });
  }

  function schedule() {
    stopPoll();
    if (tg.linked || !waiting || Date.now() > pollUntil) { return; }
    pollTimer = setTimeout(checkNow, POLL_MS);
  }

  function setOpen(next) {
    var was = !panel.hidden;
    panel.hidden = !next;
    bell.setAttribute('aria-expanded', next ? 'true' : 'false');
    if (waiting && Date.now() > pollUntil) { waiting = false; }
    if (next && !was && !tg.linked && !waiting) {
      failed = false;
      render();
      prepareLink();
    }
  }

  bell.addEventListener('click', function () {
    setOpen(panel.hidden);
  });

  go.addEventListener('click', function (e) {
    if (!url) { e.preventDefault(); return; }
    waiting = true;
    failed = false;
    pollUntil = Date.now() + POLL_FOR;
    render();
    schedule();
  });

  off.addEventListener('click', function () {
    off.disabled = true;
    post({ action: 'unlink' }).then(function (r) {
      off.disabled = false;
      if (r.ok && r.data && r.data.ok) {
        tg = { linked: false, name: '', mod: tg.mod };
        failed = false;
        waiting = false;
        render();
        prepareLink();
      } else {
        failed = true;
        render();
      }
    });
  });

  boxes.forEach(function (box) {
    box.addEventListener('change', function () {
      var topic = box.dataset.topic;
      var want = box.checked;
      var body = { action: 'prefs' };
      body[topic] = want;
      box.disabled = true;
      showError(false);
      post(body).then(function (r) {
        box.disabled = false;
        var prefs = r.ok && r.data && r.data.ok ? r.data.prefs : null;
        if (prefs && typeof prefs[topic] === 'boolean') {
          box.checked = prefs[topic];
          return;
        }
        box.checked = !want;
        showError(true);
      });
    });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && waiting && !tg.linked) { checkNow(); }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' || panel.hidden) { return; }
    var inside = panel.contains(document.activeElement) || document.activeElement === bell;
    setOpen(false);
    if (inside) { bell.focus(); }
  });

  document.addEventListener('click', function (e) {
    if (panel.hidden) { return; }
    if (panel.contains(e.target) || bell.contains(e.target)) { return; }
    setOpen(false);
  });

  document.addEventListener('mk:langchange', function () {
    render();
    if (err && !err.hidden) { showError(true); }
  });

  render();
})();
