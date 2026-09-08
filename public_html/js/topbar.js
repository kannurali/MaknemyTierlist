// Поведение общей шапки сайта (.mk-top): свёрнутый режим при прокрутке и
// объяснение «В активной разработке» для разделов, которых ещё нет.
//
// Файл общий для всех страниц сайта (home / index / news) и
// ни от чего не зависит: словарь js/i18n.js необязателен, без него берутся
// русские строки. Скрипт подключается с defer — DOM к моменту запуска готов.
(function () {
  "use strict";

  var head = document.querySelector(".mk-top");

  // ------------------------------------------------------------------
  //  Язык
  // ------------------------------------------------------------------
  // Тот же ключ localStorage, что читает app.js: переключатель языка живёт
  // там, и шапка обязана показывать сообщение на выбранном языке, а не на
  // языке браузера. На главной i18n.js не подключён вовсе — тогда работают
  // запасные строки, а не пустые подписи.
  var LANG_KEY = "nexus-lang-v1";
  var FALLBACK = {
    "topbar.soon": "В активной разработке",
    "topbar.showNav": "Показать разделы",
    "topbar.hideNav": "Скрыть разделы",
    // Вход через Roblox. Запасные строки нужны на главной:
    // там js/i18n.js не подключён вовсе, а шапка там та же.
    "user.login": "Войти через Roblox",
    "user.menu": "Меню профиля",
    "user.profile": "Профиль в Roblox",
    "user.logout": "Выйти",
    "user.cancelled": "Вход отменён",
    "user.expired": "Вход занял слишком много времени — попробуйте ещё раз",
    "user.error": "Не удалось войти — попробуйте ещё раз"
  };

  function tx(key) {
    var i18n = window.I18N;
    if (!i18n) return FALLBACK[key];
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) { /* приватный режим */ }
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  // ------------------------------------------------------------------
  //  Свёрнутая шапка при прокрутке
  // ------------------------------------------------------------------
  // Шапка липкая, и в покое её фон совпадает с фоном страницы — шва не
  // видно. Стоит прокрутить, и эта же полоса застывает непрозрачным поясом
  // поверх содержимого. Класс is-stuck гасит фон, убавляет высоту, увозит
  // логотип за левый край, а плашку разделов — за правый (см. topbar.css);
  // вернуть плашку можно язычком, который в этом же состоянии появляется.
  //
  // Порог 4px, а не 0: на телефонах инерционная прокрутка отдаёт scrollY
  // вроде 0.5 в самом верху, и без запаса шапка мигала бы на каждом касании.
  var STICK_AT = 4;
  // Ниже этой ширины шапка при прокрутке не меняется вовсе — только едет
  // вместе с экраном. Там она и так вдвое ниже десктопной, а прятать её
  // значит отнимать у телефона единственную навигацию ради 60px экрана.
  // Порог тот же, на котором вёрстка переходит к иконкам (topbar.css).
  var WIDE = window.matchMedia("(min-width: 761px)");
  // Насколько нужно уехать вниз после ручного открытия, чтобы плашка
  // свернулась сама. Меньше — и меню закрывалось бы от дрожания пальца на
  // тачпаде, больше — и оно висело бы над содержимым весь экран.
  var RECLOSE_AFTER = 60;

  if (head) {
    var toggle = head.querySelector(".mk-top-toggle");
    var stuck = false;
    var open = false;          // плашку открыли язычком
    var openedAt = 0;          // scrollY в момент открытия
    var pending = false;

    function scrollY() {
      return window.scrollY || window.pageYOffset || 0;
    }

    // Подпись язычка обязана совпадать с тем, что он сделает при нажатии,
    // иначе скринридер объявит одно, а произойдёт другое.
    function syncToggle() {
      if (!toggle) return;
      var key = open ? "topbar.hideNav" : "topbar.showNav";
      var label = tx(key);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.setAttribute("aria-label", label);
      toggle.setAttribute("title", label);
      // Ключи для i18n.js: при переключении языка подписи перерисовываются
      // общим кодом applyLang(), и он должен взять актуальный ключ.
      toggle.setAttribute("data-i18n-label", key);
      toggle.setAttribute("data-i18n-title", key);
    }

    function setOpen(next) {
      open = next;
      openedAt = scrollY();
      head.classList.toggle("is-nav-open", open);
      syncToggle();
    }

    var sync = function () {
      pending = false;
      var y = scrollY();
      // Узкий экран — состояние всегда «как наверху»: шапка не сворачивается
      // и логотип никуда не уезжает. Проверка здесь, а не при подписке:
      // окно можно растянуть мышью, и поведение обязано переключиться без
      // перезагрузки.
      var next = WIDE.matches && y > STICK_AT;

      if (next !== stuck) {
        stuck = next;
        head.classList.toggle("is-stuck", stuck);
        // Возврат наверх — это и есть «шапка снова целиком на месте»:
        // сбрасываем ручное открытие, чтобы следующая прокрутка опять
        // свернула плашку, а не оставила её висеть.
        if (!stuck && open) setOpen(false);
        if (stuck) openedAt = y;
      }

      // Открыли язычком и поехали дальше вниз — сворачиваем обратно. Вверх
      // не сворачиваем: человек, скорее всего, возвращается к меню.
      if (stuck && open && y - openedAt > RECLOSE_AFTER) setOpen(false);
    };

    if (toggle) {
      syncToggle();
      toggle.addEventListener("click", function () { setOpen(!open); });
    }

    window.addEventListener("scroll", function () {
      // rAF, а не обработка на каждом событии: scroll стреляет чаще кадра, и
      // classList.toggle в нём заставлял бы браузер пересчитывать стили
      // впустую.
      if (pending) return;
      pending = true;
      window.requestAnimationFrame(sync);
    }, { passive: true });

    // Растягивание окна мышью меняет режим без единого события scroll.
    // addEventListener у MediaQueryList — не во всех старых движках, отсюда
    // откат на addListener.
    if (WIDE.addEventListener) WIDE.addEventListener("change", sync);
    else if (WIDE.addListener) WIDE.addListener(sync);

    // Перезагрузка посреди страницы и возврат «назад» восстанавливают
    // прокрутку без события scroll — состояние надо взять сразу.
    sync();
  }

  // ------------------------------------------------------------------
  //  «В активной разработке»
  // ------------------------------------------------------------------
  // Разделы «Трейдинг», «Калькулятор» и профиль пока не выкладываются.
  // Кнопки помечены data-soon: клик по такой не ведёт никуда, а показывает
  // плашку с объяснением. Мёртвая кнопка без ответа читается как поломка
  // сайта, поэтому ответ обязателен.
  var toast = null;
  var hideTimer = 0;

  // Тот же узел показывает и «в разработке», и результат входа через Roblox:
  // два плавающих сообщения разного вида в одной шапке ничему бы не помогли.
  function showToast(text) {
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "mk-soon";
      // status + polite: скринридер проговорит текст, не перебивая себя.
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.appendChild(toast);
    }
    toast.textContent = text;
    // Повторный клик перезапускает показ: перевзводим таймер, а не копим их.
    clearTimeout(hideTimer);
    // Один кадр между вставкой и классом — иначе перехода не будет вовсе:
    // браузер не анимирует свойства узла, который только что появился.
    window.requestAnimationFrame(function () { toast.classList.add("is-on"); });
    hideTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }

  function showSoon() { showToast(tx("topbar.soon")); }

  // Делегирование на документе, а не по кнопке на каждую: разметка шапки
  // одинакова на четырёх страницах, и новую пилюлю «в разработке» достаточно
  // пометить data-soon, не трогая этот файл.
  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-soon]") : null;
    if (!el) return;
    e.preventDefault();   // на случай, если раздел когда-то был <a>
    showSoon();
  });

  // ------------------------------------------------------------------
  //  Вход через Roblox
  // ------------------------------------------------------------------
  // Кнопка профиля в шапке (.mk-avatar) размечена как заглушка data-soon и
  // такой же приезжает со всех четырёх страниц. Оживляет её этот код, и
  // только если сервер сказал, что вход настроен (api/session.php → roblox).
  // Разметка одна на все состояния: пока приложение в Roblox не заведено,
  // кнопка ведёт себя ровно как раньше, и в PHP-страницах нет ни ветвлений,
  // ни четвёртой копии условия.
  var AUTH_START  = "/api/roblox_start.php";
  var AUTH_LOGOUT = "/api/logout.php";
  var AUTH_STATE  = "/api/session.php";

  // Адрес текущей страницы для return= — без метки ?login=, иначе она
  // копилась бы в адресе с каждым повторным входом.
  function here() {
    var q = location.search.replace(/([?&])login=[^&]*(&|$)/, "$1").replace(/[?&]$/, "");
    return location.pathname + q + location.hash;
  }

  // Метка результата, которую поставил api/roblox_callback.php. Читаем один
  // раз и сразу убираем из адреса: перезагрузка страницы не должна показывать
  // сообщение о входе второй раз, а «поделиться ссылкой» — тем более.
  function takeLoginFlag() {
    var m = /[?&]login=([a-z]+)/.exec(location.search);
    if (!m) return "";
    if (window.history && history.replaceState) {
      try { history.replaceState(null, "", here()); } catch (_) { /* file:// */ }
    }
    return m[1];
  }

  function reportLogin(flag) {
    if (flag === "cancelled") showToast(tx("user.cancelled"));
    else if (flag === "expired") showToast(tx("user.expired"));
    else if (flag === "error") showToast(tx("user.error"));
    // "ok" молчит: появившийся аватар и есть сообщение об успехе.
  }

  // Кнопка-заглушка превращается в ссылку, а не остаётся кнопкой с
  // location.href в обработчике: вход — это переход, и он обязан открываться
  // средней кнопкой мыши и показывать адрес в статусной строке.
  function toLoginLink(btn) {
    var a = document.createElement("a");
    a.className = btn.className;
    a.href = AUTH_START + "?return=" + encodeURIComponent(here());
    a.innerHTML = btn.innerHTML;
    var label = tx("user.login");
    a.setAttribute("aria-label", label);
    a.setAttribute("title", label);
    // Ключи для applyLang(): подписи перерисовываются при смене языка.
    a.setAttribute("data-i18n-label", "user.login");
    a.setAttribute("data-i18n-title", "user.login");
    btn.parentNode.replaceChild(a, btn);
    return a;
  }

  // Меню профиля живёт в <body> с position: fixed, а не внутри шапки.
  // У .mk-top стоит overflow-x: clip с откатом на hidden (см. topbar.css):
  // в движках без clip выпадающий список обрезало бы по краю шапки. Заодно
  // меню не попадает ни в один стековый контекст шапки.
  function buildMenu(user) {
    var menu = document.createElement("div");
    menu.className = "mk-user-menu";
    menu.setAttribute("role", "menu");
    menu.hidden = true;

    var name = document.createElement("div");
    name.className = "mk-user-name";
    // textContent, а не innerHTML: ник приходит из Roblox, то есть извне.
    name.textContent = user.display || user.name || "";
    var nick = document.createElement("span");
    nick.textContent = user.name ? "@" + user.name : "";
    name.appendChild(nick);

    var prof = document.createElement("a");
    prof.className = "mk-user-item";
    prof.setAttribute("role", "menuitem");
    prof.href = user.profile;
    prof.target = "_blank";
    // noopener: вкладка Roblox не получит доступ к window.opener этой страницы.
    prof.rel = "noopener noreferrer";
    prof.textContent = tx("user.profile");
    prof.setAttribute("data-i18n", "user.profile");

    var out = document.createElement("button");
    out.className = "mk-user-item";
    out.type = "button";
    out.setAttribute("role", "menuitem");
    out.textContent = tx("user.logout");
    out.setAttribute("data-i18n", "user.logout");
    out.addEventListener("click", function () {
      out.disabled = true;
      // POST, а не GET: выход меняет состояние, и api/logout.php другого не
      // принимает (require_post). Ответ не читаем — важен только сам факт.
      fetch(AUTH_LOGOUT, { method: "POST", cache: "no-store" })
        .catch(function () { /* сеть отвалилась — перезагрузка покажет правду */ })
        .then(function () { location.reload(); });
    });

    menu.appendChild(name);
    menu.appendChild(prof);
    menu.appendChild(out);
    document.body.appendChild(menu);
    return menu;
  }

  function initUserMenu(btn, user) {
    btn.removeAttribute("data-soon");
    var label = tx("user.menu");
    btn.setAttribute("aria-label", label);
    btn.setAttribute("title", label);
    btn.setAttribute("data-i18n-label", "user.menu");
    btn.setAttribute("data-i18n-title", "user.menu");
    btn.setAttribute("aria-haspopup", "menu");
    btn.setAttribute("aria-expanded", "false");

    // Аватар подменяет иконку-заглушку. Пустой avatar (профиль без картинки
    // или ссылка не с домена Roblox — её отсеивает сервер) оставляет прежний
    // значок: пустой кружок читался бы как поломка.
    if (user.avatar) {
      var img = document.createElement("img");
      img.className = "mk-avatar-img";
      img.src = user.avatar;
      img.alt = "";
      img.setAttribute("aria-hidden", "true");
      // Аватар отдаёт CDN Roblox: реферер ему не нужен, а адрес страницы —
      // не его дело.
      img.referrerPolicy = "no-referrer";
      btn.textContent = "";
      btn.appendChild(img);
    }

    var menu = buildMenu(user);
    var menuOpen = false;

    function place() {
      var r = btn.getBoundingClientRect();
      menu.style.top = (r.bottom + 8) + "px";
      menu.style.right = Math.max(8, window.innerWidth - r.right) + "px";
    }

    function setMenuOpen(next) {
      menuOpen = next;
      menu.hidden = !menuOpen;
      btn.setAttribute("aria-expanded", menuOpen ? "true" : "false");
      if (menuOpen) place();
    }

    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      setMenuOpen(!menuOpen);
    });

    document.addEventListener("click", function (e) {
      if (menuOpen && !menu.contains(e.target)) setMenuOpen(false);
    });
    document.addEventListener("keydown", function (e) {
      if (menuOpen && e.key === "Escape") { setMenuOpen(false); btn.focus(); }
    });
    // Шапка липкая, но кнопка при прокрутке уезжает вместе с плашкой
    // разделов — держать меню приклеенным к ней дороже, чем закрыть.
    window.addEventListener("scroll", function () { if (menuOpen) setMenuOpen(false); }, { passive: true });
    window.addEventListener("resize", function () { if (menuOpen) setMenuOpen(false); });
  }

  var avatarBtn = head ? head.querySelector(".mk-avatar") : null;
  if (avatarBtn && window.fetch) {
    reportLogin(takeLoginFlag());
    fetch(AUTH_STATE, { cache: "no-store" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        // Вход не настроен (нет client_id в config.php) — кнопка остаётся
        // заглушкой «В активной разработке», как была.
        if (!s || !s.roblox) return;
        if (s.user) initUserMenu(avatarBtn, s.user);
        else toLoginLink(avatarBtn);
      })
      .catch(function () { /* статика без PHP: заглушка остаётся заглушкой */ });
  }
})();
