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
    "topbar.hideNav": "Скрыть разделы"
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

  function showSoon() {
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "mk-soon";
      // status + polite: скринридер проговорит текст, не перебивая себя.
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.appendChild(toast);
    }
    toast.textContent = tx("topbar.soon");
    // Повторный клик перезапускает показ: перевзводим таймер, а не копим их.
    clearTimeout(hideTimer);
    // Один кадр между вставкой и классом — иначе перехода не будет вовсе:
    // браузер не анимирует свойства узла, который только что появился.
    window.requestAnimationFrame(function () { toast.classList.add("is-on"); });
    hideTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }

  // Делегирование на документе, а не по кнопке на каждую: разметка шапки
  // одинакова на четырёх страницах, и новую пилюлю «в разработке» достаточно
  // пометить data-soon, не трогая этот файл.
  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-soon]") : null;
    if (!el) return;
    e.preventDefault();   // на случай, если раздел когда-то был <a>
    showSoon();
  });
})();
