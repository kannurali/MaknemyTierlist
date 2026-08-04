// Выбор языкового варианта КОНТЕНТА тирлиста (не интерфейса).
//
// Строки интерфейса живут в i18n.js. А текст, который вводит админ —
// описания предметов — это контент: он хранится прямо на предмете, а не в
// словаре. Описание держит необязательный английский вариант (descEn) рядом
// с основным текстом (desc, русский).
//
// descFor берёт нужный язык, а если его нет — откатывается на второй, чтобы
// наполовину заполненный предмет показывал хоть что-то, а не пустоту. Это
// зеркалит правило фолбэка в i18n.js: пустой экран хуже текста не на том языке.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах.
(function (root) {
  "use strict";

  function descFor(item, lang) {
    if (!item) { return ""; }
    var ru = String(item.desc || "").trim();
    var en = String(item.descEn || "").trim();
    return lang === "en" ? (en || ru) : (ru || en);
  }

  var api = { descFor: descFor };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CONTENT = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
