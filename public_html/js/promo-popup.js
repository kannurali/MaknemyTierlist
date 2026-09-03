// Рекламное окно (слот "popup") для ленты и калькулятора.
//
// На тирлисте такое окно уже есть, но его код вплетён в app.js: он знает про
// режим редактирования, экспорт PNG и три собственные модалки тирлиста.
// Ничего из этого на ленте и калькуляторе нет, а копировать полторы сотни
// строк в две страницы значит завести три независимых механизма показа
// рекламы — ровно то, чего вся система промо избегает. Поэтому общий кусок
// вынесен сюда, а app.js оставлен как есть.
//
// Что показать, решает не этот файл: PROMO.popupPick() в js/promo.js берёт
// купленную кампанию, а если такой нет — собственное объявление о
// телеграм-канале (PROMO.HOUSE_TG). Частота там же: «раз в сутки» — это
// capHours: 24 в настройках кампании, а не таймер здесь.
//
// Счётчик показов лежит в localStorage под тем же ключом, что у тирлиста:
// объявление одно на сайт, и человек, который закрыл его на тирлисте, не
// должен увидеть его же через минуту в ленте.
//
// Имя файла с префиксом promo-, а не ad-: ##[class^="ad-"] и ad.js —
// стандартные косметические правила EasyList (см. шапку js/promo.js).
(function (root) {
  "use strict";

  var SEEN_KEY = "nx-ptn-seen-v1";
  var LANG_KEY = "nexus-lang-v1";

  // Что глушим для скринридера, пока окно открыто. Список общий на две
  // страницы: лишний селектор просто не находится.
  var BEHIND = [".mk-top", "main", ".mk-foot", "#promoDock"];

  var seenCache = null;
  var timer = null;
  var opened = false;      // одно окно за загрузку страницы
  var camp = null;
  var restoreFocus = null;
  var cfg = null;

  function $(sel) { return document.querySelector(sel); }

  function t(key, fallback) {
    var i18n = root.I18N;
    if (!i18n || !key) { return fallback || ""; }
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) { /* приватный режим */ }
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  // Приватный режим Safari бросает на localStorage. Тогда счётчик живёт
  // только в памяти: окно покажется раз за сессию вместо раза в сутки — это
  // хуже, чем задумано, но лучше, чем на каждой перезагрузке.
  function readSeen() {
    if (seenCache) { return seenCache; }
    try { seenCache = JSON.parse(localStorage.getItem(SEEN_KEY)) || {}; }
    catch (_) { seenCache = {}; }
    return seenCache;
  }
  function writeSeen(v) {
    seenCache = v;
    try { localStorage.setItem(SEEN_KEY, JSON.stringify(v)); } catch (_) {}
  }

  function blocked() {
    if (cfg && cfg.isAdmin) { return true; }
    if (cfg && typeof cfg.busy === "function" && cfg.busy()) { return true; }
    return false;
  }

  // Заголовок и подпись кнопки. У платной кампании это строки от
  // рекламодателя — их не переводят. У собственного объявления вместо строк
  // приходят ключи словаря, и тогда узел помечается data-i18n: applyLang()
  // страницы переведёт его при переключении языка, не трогая окно.
  function setCopy(el, key, text, fallbackKey) {
    if (key) {
      el.setAttribute("data-i18n", key);
      el.textContent = t(key, "");
      return;
    }
    el.removeAttribute("data-i18n");
    if (text) { el.textContent = text; return; }
    el.textContent = fallbackKey ? t(fallbackKey, "") : "";
  }

  function open(src) {
    if (opened || blocked()) { return; }
    var pop = $("#promoPop");
    if (!pop) { return; }
    opened = true;
    writeSeen(root.PROMO.recordPopupShown(readSeen(), camp.id, Date.now()));

    $("#promoPopImg").src = src;
    setCopy($("#promoPopTitle"), camp.textKey, camp.text, null);

    var cta = $("#promoPopCta");
    var url = root.PROMO.safeHref(camp.href);
    setCopy(cta, camp.ctaKey, camp.cta, "promo.cta");
    cta.hidden = !url;
    if (url) { cta.href = url; }

    // Узел маркировки статический — прячем, когда токена нет, вместо того
    // чтобы создавать и удалять.
    var erid = $("#promoPopErid");
    if (erid) {
      erid.textContent = camp.erid ? "erid: " + camp.erid : "";
      erid.hidden = !camp.erid;
    }

    pop.hidden = false;
    // Блокировка прокрутки фона. На iOS одного overflow: hidden у body мало —
    // тач-скролл протекает, поэтому у подложки ещё touch-action (CSS).
    document.body.classList.add("ptn-locked");
    restoreFocus = document.activeElement;
    BEHIND.forEach(function (sel) {
      var el = $(sel);
      if (el) { el.setAttribute("aria-hidden", "true"); }
    });
    document.addEventListener("keydown", onKey, true);
    setTimeout(function () { var b = $("#promoPopClose"); if (b) { b.focus(); } }, 20);
  }

  function close() {
    var pop = $("#promoPop");
    if (!pop || pop.hidden) { return; }
    pop.hidden = true;
    document.body.classList.remove("ptn-locked");
    BEHIND.forEach(function (sel) {
      var el = $(sel);
      if (el) { el.removeAttribute("aria-hidden"); }
    });
    document.removeEventListener("keydown", onKey, true);
    // Картинку отцепляем: анимированный креатив иначе продолжает крутить
    // кадры в скрытом окне.
    var img = $("#promoPopImg");
    if (img) { img.removeAttribute("src"); }
    if (restoreFocus && restoreFocus.focus) { try { restoreFocus.focus(); } catch (_) {} }
    restoreFocus = null;
  }

  // Ловушка фокуса. На сайте её нет больше нигде, но именно это окно человек
  // не просил открывать, поэтому уйти из него с клавиатуры обязано
  // получаться.
  function onKey(e) {
    var pop = $("#promoPop");
    if (!pop || pop.hidden) { return; }
    if (e.key === "Escape") { e.preventDefault(); close(); return; }
    if (e.key !== "Tab") { return; }
    var focusables = Array.prototype.slice
      .call(pop.querySelectorAll("button, a[href], [tabindex]:not([tabindex='-1'])"))
      .filter(function (el) { return !el.hidden && el.offsetParent !== null; });
    if (!focusables.length) { return; }
    var first = focusables[0], last = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function tryOpen(pick) {
    if (opened || blocked()) { return; }
    if (document.visibilityState !== "visible") { return; }
    // Проверяем ещё раз: между планированием и срабатыванием таймера могла
    // пройти полночь лимита или окно могли показать в соседней вкладке.
    if (!root.PROMO.shouldShowPopup(pick, readSeen(), Date.now())) { return; }

    var cre = root.PROMO.creativeFor(pick, "popup");
    if (!cre) { return; }
    camp = pick;

    var reduced = root.matchMedia && root.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var src = (reduced && cre.anim && cre.poster) ? cre.poster : cre.src;

    // Ждём декодирования: окно, открытое поверх серого прямоугольника,
    // выглядит как поломка сайта, а не как реклама. Таймаут — на случай,
    // если картинка не приедет вовсе.
    var img = $("#promoPopImg");
    var done = false;
    var go = function () { if (!done) { done = true; open(src); } };
    img.src = src;
    if (img.decode) { img.decode().then(go).catch(go); }
    else { img.onload = go; img.onerror = go; }
    setTimeout(go, 4000);
  }

  function schedule() {
    if (!root.PROMO || opened || blocked()) { return; }
    clearTimeout(timer);
    // Отсчёт идёт только на видимой вкладке. Иначе лимит «раз в сутки»
    // сгорит на человеке, который открыл сайт в фоне и ничего не увидел.
    if (document.visibilityState !== "visible") { return; }
    var now = Date.now();
    var pick = root.PROMO.popupPick(cfg.doc, readSeen(), now, Math.random());
    if (!pick) { return; }
    timer = setTimeout(function () { tryOpen(pick); }, pick.popup.delayMs);
  }

  // opts.doc      — документ /api/promo.php как есть (может быть null:
  //                 тогда покажется собственное объявление);
  // opts.isAdmin  — админу рекламу не показываем вовсе;
  // opts.busy     — функция «сейчас открыто что-то своё» (каталог, модалка).
  function mount(opts) {
    cfg = opts || {};
    var pop = $("#promoPop");
    if (!pop || !root.PROMO) { return; }

    if (!pop.dataset.bound) {
      pop.dataset.bound = "1";
      $("#promoPopClose").addEventListener("click", close);
      pop.addEventListener("click", function (e) { if (e.target === pop) { close(); } });
      $("#promoPopCta").addEventListener("click", function () {
        if (!camp) { return; }
        writeSeen(root.PROMO.recordPopupClicked(readSeen(), camp.id, Date.now()));
        try {
          if (typeof root.ym === "function") {
            root.ym(111127188, "reachGoal", "promo_click", { id: camp.id, slot: "popup" });
          }
        } catch (_) {}
        close();
      });
      document.addEventListener("visibilitychange", function () {
        if (document.visibilityState === "visible") { schedule(); }
      });
    }
    schedule();
  }

  root.NX_PROMO_POPUP = { mount: mount };
})(window);
