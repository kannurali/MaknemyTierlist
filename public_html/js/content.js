// Выбор языкового варианта КОНТЕНТА тирлиста (не интерфейса).
//
// Строки интерфейса живут в i18n.js. А текст, который вводит админ —
// описания предметов — это контент: он хранится прямо на предмете, а не в
// словаре. Описание держит необязательный английский вариант (descEn) рядом
// с основным текстом (desc, русский).
//
// textFor берёт нужный язык, а если его нет — откатывается на второй, чтобы
// наполовину заполненный предмет показывал хоть что-то, а не пустоту. Это
// зеркалит правило фолбэка в i18n.js: пустой экран хуже текста не на том языке.
//
// Двуязычных полей у предмета три, и все устроены одинаково: русское лежит
// под базовым именем, английское — под тем же именем с суффиксом En.
//
//   desc  / descEn   описание
//   terms / termsEn  условия передачи (блок «УСЛОВИЯ» в карточке)
//   tag   / tagEn     метка-плашка рядом с ценой (напр. LIMITED)
//
// Поэтому правило фолбэка вынесено в textFor(item, base, lang), а descFor
// остался как его частный случай — на него завязаны вызовы в app.js и тесты.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах.
(function (root) {
  "use strict";

  // base — имя русского поля; английское ищется под base + "En".
  function textFor(item, base, lang) {
    if (!item || !base) { return ""; }
    var ru = String(item[base] || "").trim();
    var en = String(item[base + "En"] || "").trim();
    return lang === "en" ? (en || ru) : (ru || en);
  }

  function descFor(item, lang) { return textFor(item, "desc", lang); }

  var api = { descFor: descFor, textFor: textFor };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CONTENT = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
