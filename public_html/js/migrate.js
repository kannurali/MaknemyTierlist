// Достройка состояния до нового макета.
//
// Карточки авторов и аватарки подвала появились вместе с постерным макетом, а
// в базе лежат записи, заведённые до него. Здесь они дополняются недостающими
// полями — по логотипу тира и по заголовку ссылки.
//
// Функция заполняет ТОЛЬКО пустые поля, поэтому повторный запуск ничего не
// меняет, а правки админа не затираются. Это важно: слияние вызывается и при
// чтении localStorage, и при каждом обновлении с сервера.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах — как i18n.js.
(function (root) {
  "use strict";

  // Логотип → человек. Карточки в макете привязаны к тирам с логотипами, а не
  // к отдельному списку людей, поэтому старые записи узнаём по имени файла.
  var CARD_BY_LOGO = {
    "logo-mk":   { role: "Владелец", handle: "@mksvtn",   comment: "за деньги не продаюсь 🤭\nмаксимум за рокет 🚀" },
    "logo-glh":  { role: "Аналитик", handle: "@GLHorig",  comment: "Макса тцк не сдам, но за конфетку\nбджілка можно" },
    "logo-bolt": { role: "Дизайнер", handle: "@DAnikTda", comment: "гони шоколадку...\nи прямо сейчас я спалю обоих! 🦉" },
  };

  // Третий знак в макете — молния, а не пламя.
  var FLAME = "logo-flame";
  var BOLT = "assets/logo-bolt.png";

  // Аватарка подбирается по заголовку ссылки: ссылки заводит админ, и порядок
  // в базе не совпадает с порядком в макете.
  var FOOTER_ICONS = [
    [/дискорд|discord/i,       "assets/avatar-discord.png"],
    [/телеграм|telegram/i,     "assets/avatar-tg.png"],
    [/blox\s*fruits|новости/i, "assets/avatar-bfnews.png"],
    [/розыгрыш/i,              "assets/avatar-giveaways.png"],
    [/charlotte|шарлотт/i,     "assets/avatar-charlotte.png"],
  ];

  function hasCard(tier) {
    var c = tier && tier.card;
    return !!(tier && tier.logo && c && (c.role || c.handle || c.comment));
  }

  function migrate(state) {
    if (!state || typeof state !== "object") { return state; }

    (state.tiers || []).forEach(function (tier) {
      if (typeof tier.logo === "string" && tier.logo.indexOf(FLAME) !== -1) { tier.logo = BOLT; }
      if (!tier.logo || hasCard(tier)) { return; }
      var key = Object.keys(CARD_BY_LOGO).filter(function (k) {
        return tier.logo.indexOf(k) !== -1;
      })[0];
      // Логотип, которого нет в таблице, — это загруженная админом картинка.
      // Придумывать ей роль нечего, тир остаётся с обычной плашкой.
      if (key) {
        tier.card = {
          role: CARD_BY_LOGO[key].role,
          handle: CARD_BY_LOGO[key].handle,
          comment: CARD_BY_LOGO[key].comment,
        };
      }
    });

    (state.footer || []).forEach(function (link) {
      if (link.icon) { return; }
      var hit = FOOTER_ICONS.filter(function (pair) {
        return pair[0].test(link.title || "");
      })[0];
      // Ссылка, для которой аватарки в макете нет, так и остаётся без неё —
      // это рабочее состояние, а не ошибка.
      link.icon = hit ? hit[1] : "";
    });

    return state;
  }

  var api = { migrate: migrate, hasCard: hasCard, CARD_BY_LOGO: CARD_BY_LOGO, FOOTER_ICONS: FOOTER_ICONS };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.MIGRATE = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
