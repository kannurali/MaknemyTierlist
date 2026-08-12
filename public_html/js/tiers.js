// Логотипы полос тиров: где лежат картинки по умолчанию и что делать со
// старыми сохранениями.
//
// У трёх стандартных тиров марка совпадает с брендовыми марками шапки
// (poster/marks.png — молния + GLH + MK). Соответствие держится по ярлыку
// тира: ярлык хранится в состоянии и переживает правки, а порядок тиров — нет.
//
// Ярлык «💧» остался с тех времён, когда третьей маркой было пламя. Менять его
// нельзя: он лежит в сохранениях и в базе, и именно по нему тир узнаёт свою
// картинку. Сама марка стала молнией — редизайн 2026-08-07 заменил пламя на
// молнию в шапке, но logo-flame.png в полосе тира тогда не тронули, и одна
// марка выглядела в двух местах по-разному.
//
// Поэтому LEGACY переводит старый путь на новый на входе состояния — из
// localStorage, из базы и из импортированного JSON. Без этого правка увидела бы
// только чистую установку: у всех, кто уже открывал сайт, в сохранении лежит
// строка с пламенем.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах.
(function (root) {
  "use strict";

  var TIER_LOGOS = {
    MK: "assets/logo-mk.png",
    GLH: "assets/logo-glh.png",
    "💧": "assets/logo-bolt.png",
  };

  var LEGACY_LOGOS = {
    "assets/logo-flame.png": TIER_LOGOS["💧"],
  };

  // restoreEmpty различает два входа состояния:
  //   false — localStorage: пустая строка значит «админ снял логотип», её
  //           надо сохранить, а вернуть картинку только отсутствующему полю;
  //   true  — база и импорт: там пустое поле приходит и от старых снимков,
  //           где логотипов не было вовсе, поэтому марка возвращается.
  function normalizeTierLogos(tiers, restoreEmpty) {
    if (!Array.isArray(tiers)) { return tiers; }
    tiers.forEach(function (tier) {
      if (!tier) { return; }
      if (LEGACY_LOGOS[tier.logo]) { tier.logo = LEGACY_LOGOS[tier.logo]; }
      var missing = restoreEmpty ? !tier.logo : tier.logo === undefined;
      if (missing && TIER_LOGOS[tier.label]) { tier.logo = TIER_LOGOS[tier.label]; }
    });
    return tiers;
  }

  var api = {
    TIER_LOGOS: TIER_LOGOS,
    LEGACY_LOGOS: LEGACY_LOGOS,
    normalizeTierLogos: normalizeTierLogos,
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.TIERS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
