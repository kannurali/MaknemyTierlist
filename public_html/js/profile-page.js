(function () {
  'use strict';

  var LANG_KEY = 'nexus-lang-v1';
  var ABOUT_MAX = 280;
  var ABOUT_URL = '/api/profile-about.php';

  var i18n = window.I18N;
  var auth = window.MKAuth;

  function $(id) { return document.getElementById(id); }

  var gate   = $('pfGate');
  var card   = $('pfCard');
  var toggle = $('pfMenuToggle');
  var list   = $('pfMenuList');

  var aboutInput = $('pfAboutInput');
  var aboutSave  = $('pfAboutSave');
  var aboutCount = $('pfAboutCount');
  var aboutState = $('pfAboutStatus');

  var savedAbout = aboutInput ? aboutInput.value : '';

  function lang() { return document.documentElement.lang === 'en' ? 'en' : 'ru'; }
  function tx(key, fallback) { return i18n ? i18n.t(key, lang()) : fallback; }

  function syncMenuLabel() {
    if (!toggle) { return; }
    var open = toggle.getAttribute('aria-expanded') === 'true';
    toggle.setAttribute('aria-label', open
      ? tx('profile.menuCollapse', 'Свернуть меню профиля')
      : tx('profile.menuExpand', 'Развернуть меню профиля'));
    toggle.title = toggle.getAttribute('aria-label');
  }

  function renderAuth(authed) {
    if (gate) { gate.hidden = !!authed; }
    if (card) { card.hidden = !authed; }
  }

  document.addEventListener('mk:profiledata', function (e) {
    renderAuth(!!(e.detail && e.detail.authed));
  });

  function aboutLength(text) {
    return Array.from ? Array.from(text).length : text.length;
  }

  function setAboutStatus(key, fallback, isError) {
    if (!aboutState) { return; }
    if (!key) {
      aboutState.textContent = '';
      aboutState.removeAttribute('data-i18n');
      aboutState.classList.remove('is-error');
      return;
    }
    aboutState.textContent = tx(key, fallback);
    aboutState.setAttribute('data-i18n', key);
    aboutState.classList.toggle('is-error', !!isError);
  }

  function syncAbout() {
    if (!aboutInput) { return; }
    var n = aboutLength(aboutInput.value);
    if (aboutCount) {
      aboutCount.textContent = n + ' / ' + ABOUT_MAX;
      aboutCount.classList.toggle('is-over', n > ABOUT_MAX);
    }
    if (aboutSave) {
      aboutSave.disabled = n > ABOUT_MAX || aboutInput.value === savedAbout;
    }
  }

  function saveAbout() {
    if (!aboutInput || !aboutSave || aboutSave.disabled) { return; }
    var sent = aboutInput.value;
    aboutSave.disabled = true;
    setAboutStatus('profile.aboutSaving', 'Сохраняем…', false);

    fetch(ABOUT_URL, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ about: sent })
    }).then(function (res) {
      if (res.status === 401) {
        renderAuth(false);
        return null;
      }
      if (res.status === 429) {
        setAboutStatus('profile.aboutTooOften', 'Слишком часто — попробуйте позже', true);
        return null;
      }
      if (res.status === 503) {
        setAboutStatus('profile.aboutUnavailable', 'Пока не сохраняется — раздел ещё не подключён', true);
        return null;
      }
      if (!res.ok) {
        setAboutStatus('profile.aboutError', 'Не удалось сохранить', true);
        return null;
      }
      return res.json();
    }).then(function (data) {
      if (!data || !data.ok) { syncAbout(); return; }
      savedAbout = data.about || '';
      if (aboutInput.value === sent) { aboutInput.value = savedAbout; }
      setAboutStatus('profile.aboutSaved', 'Сохранено', false);
      syncAbout();
    }).catch(function () {
      setAboutStatus('profile.aboutError', 'Не удалось сохранить', true);
      syncAbout();
    });
  }

  if (aboutInput) {
    aboutInput.addEventListener('input', function () {
      setAboutStatus('', '', false);
      syncAbout();
    });
    syncAbout();
  }
  if (aboutSave) { aboutSave.addEventListener('click', saveAbout); }

  var out  = $('pfLogout');
  var swap = $('pfSwitch');

  function leaving() {
    if (out)  { out.disabled = true; }
    if (swap) { swap.disabled = true; }
  }

  if (auth) {
    if (out) {
      out.addEventListener('click', function () {
        leaving();
        auth.logout(function () { location.assign('/'); });
      });
    }
    if (swap) {
      swap.addEventListener('click', function () {
        leaving();
        auth.switchAccount('/profile');
      });
    }
  } else {
    leaving();
  }

  if (i18n) {
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (e) {}
    var current = i18n.pickLang(stored, navigator.language);

    var apply = function (next) {
      if (next) {
        current = next;
        try { localStorage.setItem(LANG_KEY, current); } catch (e) {}
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

      syncMenuLabel();

      document.dispatchEvent(new CustomEvent('mk:langchange', { detail: { lang: current } }));
    };

    var box = $('langSwitch');
    if (box) {
      box.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-lang]');
        if (btn) { apply(btn.dataset.lang); }
      });
    }

    apply();
  }

  if (toggle && list) {
    toggle.addEventListener('click', function () {
      var next = toggle.getAttribute('aria-expanded') !== 'true';
      toggle.setAttribute('aria-expanded', String(next));
      list.hidden = !next;
      syncMenuLabel();
    });
  }
})();
