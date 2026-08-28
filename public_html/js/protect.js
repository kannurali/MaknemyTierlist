// Защита контента от копирования — общая для тирлиста и ленты новостей.
//
// Файл не знает ни про тирлист, ни про ленту: ему передают функцию «сейчас
// админ?», и он вешает на документ четыре обработчика. Вынесено из app.js
// по той же причине, по какой тулбар уехал в base.css: вторая копия этой
// логики в news-page.js однажды разошлась бы с первой, и дыру, закрытую на
// одной странице, забыли бы закрыть на другой.
//
// Класс .protected на <body> (правила в base.css) отключает выделение и
// перетаскивание средствами CSS; здесь добиваются правая кнопка, копирование
// с клавиатуры и движки, которые не понимают user-select: none.
//
// Это заслон от обычного посетителя, а не от исходников: разметка всё равно
// видна через «Просмотр кода», картинки лежат по прямым адресам, а экран
// можно сфотографировать.
//
// DOM трогает только install(); inEditable — чистая функция, поэтому файл
// требуется из node в тестах, как i18n.js и news.js.
(function (root) {
  "use strict";

  // Поля ввода и редактируемые области должны работать как обычно — иначе
  // на странице входа админа нельзя было бы вставить пароль.
  function inEditable(target) {
    return !!(target && target.closest &&
      target.closest('input, textarea, [contenteditable="true"]'));
  }

  // isAdminFn — функция, а не значение: на тирлисте роль выясняется запросом
  // уже после установки обработчиков (см. initBackend в app.js), и снятое
  // значение навсегда осталось бы «гость».
  function install(isAdminFn, doc) {
    var d = doc || (typeof document !== "undefined" ? document : null);
    if (!d) { return; }

    // Правая кнопка — убирает «Сохранить картинку как…» и «Копировать».
    d.addEventListener("contextmenu", function (e) {
      if (isAdminFn() || inEditable(e.target)) { return; }
      e.preventDefault();
    });

    // Копирование с клавиатуры и через меню браузера.
    ["copy", "cut"].forEach(function (type) {
      d.addEventListener(type, function (e) {
        if (isAdminFn() || inEditable(e.target)) { return; }
        e.preventDefault();
      });
    });

    // Перетаскивание картинки в другую вкладку или на рабочий стол.
    // Админу нельзя мешать: на тирлисте перенос ячеек между тирами работает
    // на этом же событии.
    d.addEventListener("dragstart", function (e) {
      if (isAdminFn()) { return; }
      e.preventDefault();
    });

    // Движки, которые не понимают user-select: none.
    d.addEventListener("selectstart", function (e) {
      if (isAdminFn() || inEditable(e.target)) { return; }
      e.preventDefault();
    });
  }

  // Класс на <body> — источник правды для CSS-половины защиты.
  function applyClass(isAdmin, doc) {
    var d = doc || (typeof document !== "undefined" ? document : null);
    if (!d || !d.body) { return; }
    d.body.classList.toggle("protected", !isAdmin);
  }

  var api = { inEditable: inEditable, install: install, applyClass: applyClass };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NX_PROTECT = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
