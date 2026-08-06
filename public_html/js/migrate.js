// Достройка состояния до нового макета.
//
// Из макета берётся только композиция: у логотипного тира появляется карточка
// автора, у ссылки подвала — место под аватарку. Тексты остаются сайтовскими.
// Роль и имя переезжают из state.credits, комментарий создаётся пустым: такого
// поля на сайте раньше не было, и придумывать за админа его содержимое незачем.
//
// Функция заполняет ТОЛЬКО пустые поля, поэтому повторный запуск ничего не
// меняет, а правки админа не затираются. Это важно: слияние вызывается и при
// чтении localStorage, и при каждом обновлении с сервера.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах — как i18n.js.
(function (root) {
  "use strict";

  // Логотип → роль. Это структура макета, а не контент: карточки привязаны к
  // тирам с логотипами, и надо знать, чей тир какой. Сами роли берутся теми
  // словами, какими они записаны в титрах сайта.
  var ROLE_BY_LOGO = {
    "logo-mk":   "Автор",
    "logo-glh":  "Аналитик",
    "logo-bolt": "Дизайнер",
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

  function norm(value) {
    return String(value || "").trim().toLowerCase();
  }

  function hasCard(tier) {
    var c = tier && tier.card;
    return !!(tier && tier.logo && c && (c.role || c.handle || c.comment));
  }

  function migrate(state) {
    if (!state || typeof state !== "object") { return state; }

    var credits = Array.isArray(state.credits) ? state.credits : [];

    (state.tiers || []).forEach(function (tier) {
      if (typeof tier.logo === "string" && tier.logo.indexOf(FLAME) !== -1) { tier.logo = BOLT; }
      if (!tier.logo || hasCard(tier)) { return; }

      var key = Object.keys(ROLE_BY_LOGO).filter(function (k) {
        return tier.logo.indexOf(k) !== -1;
      })[0];
      // Логотип, которого нет в таблице, — это загруженная админом картинка.
      // Кто за ней стоит, неизвестно, поэтому тир остаётся с обычной плашкой.
      if (!key) { return; }

      var role = ROLE_BY_LOGO[key];
      var credit = credits.filter(function (c) { return norm(c.role) === norm(role); })[0];
      // Роли нет в титрах — значит, человека на сайте не заводили. Карточку не
      // выдумываем: иначе на странице появится роль, которой нигде больше нет.
      if (!credit) { return; }

      tier.card = { role: credit.role, handle: credit.name || "", comment: "" };
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

  // Ключ участника — ПАРА «роль + имя», а не роль или имя по отдельности.
  // Имя в титрах сплошь и рядом заглушка: «—», «ищем...». Сравнение по одному
  // имени выносило из полоски всех, у кого стоит та же заглушка: карточка
  // аналитика с именем «—» уводила за собой помощника аналитика и кодера
  // сайта, то есть ровно тех, ради кого полоска и оставлена.
  // Собачку у ника снимаем: в титрах человек записан «mksvtn», в карточке
  // админ мог дописать «@mksvtn».
  //
  // Ключ собирается через JSON, а не склейкой через разделитель: любой символ,
  // который можно взять разделителем, набирается и в поле титров: тогда
  // «Автор» + «Мак» совпало бы с «АвторМак» + пустым именем.
  function creditKey(role, name) {
    return JSON.stringify([norm(role).replace(/^@/, ""), norm(name).replace(/^@/, "")]);
  }

  // Титры без тех, кто уже показан карточкой автора. Пара не совпала — строка
  // остаётся: показать человека дважды не страшно, потерять со страницы —
  // страшно.
  function visibleCredits(state) {
    var s = state || {};
    var shown = (s.tiers || []).filter(hasCard).map(function (t) {
      return creditKey(t.card.role, t.card.handle);
    });
    return (Array.isArray(s.credits) ? s.credits : []).filter(function (cr) {
      return shown.indexOf(creditKey(cr.role, cr.name)) === -1;
    });
  }

  var api = {
    migrate: migrate, hasCard: hasCard, visibleCredits: visibleCredits,
    ROLE_BY_LOGO: ROLE_BY_LOGO, FOOTER_ICONS: FOOTER_ICONS,
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.MIGRATE = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
