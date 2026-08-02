// Интерфейсные строки сайта на русском и английском.
//
// Переводится ТОЛЬКО интерфейс. Содержимое тирлиста (названия тиров и
// предметов, описания, реклама, титры, ссылки в подвале) приходит из БД в том
// виде, в каком его ввёл админ, и языком не управляется.
//
// Ключи — «область.элемент». Разметка помечена data-i18n / data-i18n-title /
// data-i18n-placeholder, поэтому строки не ищутся по тексту в рантайме.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах.
(function (root) {
  "use strict";

  var STRINGS = {
    ru: {
      "lang.switch":            "Язык интерфейса",
      "lang.ru":                "RU",
      "lang.en":                "EN",

      "auth.login":             "🔑 Войти",
      "auth.loginTitle":        "Войти как администратор",
      "auth.admin":             "👑 Админ",
      "auth.logout":            "Выйти",
      "auth.prompt":            "Пароль администратора:",
      "auth.wrong":             "Неверный пароль",
      "auth.locked":            "Слишком много попыток. Попробуйте через",
      "auth.seconds":           "сек.",

      "like.title":             "Поставить лайк",
      "donate.button":          "Поддержать",
      "donate.title":           "Поддержать проект",
      "donate.modalTitle":      "💜 Поддержать проект",
      "donate.modalSub":        "Спасибо, что помогаешь держать тирлист живым!",
      "donate.linkHub":         "Все способы (dalink)",
      "donate.editDA":          "🔗 DonationAlerts",
      "donate.editDATitle":     "Изменить ссылку DonationAlerts",
      "donate.editHub":         "🔗 Все способы",
      "donate.editHubTitle":    "Изменить ссылку на хаб (dalink)",
      "donate.qrUpload":        "🖼 QR",
      "donate.qrUploadTitle":   "Загрузить новый QR-код",
      "donate.qrReset":         "Стандартный QR",
      "donate.qrResetTitle":    "Вернуть стандартный QR",
      "donate.qrAlt":           "QR для доната",
      "donate.qrCaption":       "Наведи камеру телефона",

      "filters.label":          "Показать:",
      "filters.fruits":         "Фрукты",
      "filters.fruitsTitle":    "Обычные фрукты",
      "filters.mutations":      "Мутации",
      "filters.mutationsTitle": "Мутации",
      "filters.perms":          "Пермы",
      "filters.permsTitle":     "Перманентные фрукты",
      "filters.passes":         "Пассы",
      "filters.passesTitle":    "Геймпассы",
      "filters.skins":          "Скины",
      "filters.skinsTitle":     "Скины и хроматики",
      "filters.all":            "Все",
      "filters.allTitle":       "Показать всё",

      "admin.addTier":          "＋ Тир",
      "admin.addTierTitle":     "Добавить новый тир",
      "admin.addItem":          "＋ Предмет",
      "admin.addItemTitle":     "Добавить предмет в первый тир",
      "admin.sort":             "⇅ Сортировать",
      "admin.sortTitle":        "Отсортировать все предметы по цене (по убыванию)",
      "admin.autoSort":         "Автосорт",
      "admin.autoSortTitle":    "Автоматически переставлять предмет по цене при её изменении",
      "admin.editing":          "Редактирование",
      "admin.editingTitle":     "Режим редактирования",
      "admin.saved":            "✓ Сохранено",
      "admin.save":             "Сохранить",
      "admin.saving":           "Сохранение…",
      "admin.saveTitle":        "Опубликовать изменения для всех",
      "admin.png":              "⬇ Скачать PNG",
      "admin.pngTitle":         "Скачать тирлист как PNG",
      "admin.export":           "Экспорт",
      "admin.exportTitle":      "Сохранить как JSON",
      "admin.import":           "Импорт",
      "admin.importTitle":      "Загрузить JSON",
      "admin.reset":            "Сброс",
      "admin.resetTitle":       "Сбросить к шаблону",
      "admin.addItemToTier":    "Добавить предмет",

      "stage.dateTitle":        "Кликните, чтобы изменить дату",
      "stage.brandAlt":         "Логотип",

      "legend.title":           "ПОМОЩЬ ДЛЯ НОВЕНЬКИХ",
      "legend.f":               "Обычный фрукт",
      "legend.s":               "Скин",
      "legend.m":               "Мутация",
      "legend.p":               "Перманент",
      "legend.gp":              "Пасс",
      "legend.cr":              "Хроматик",
      "legend.good":            "Хорошо",
      "legend.mid":             "Средне",
      "legend.low":             "Ниже среднего",
      "legend.bad":             "Плохо",
      "legend.up":              "Рост",
      "legend.swap":            "Пересмотр",
      "legend.down":            "Упадок",
      "legend.wip":             "Под вопросом",

      "modal.itemTitle":        "Предмет",
      "modal.close":            "Закрыть",
      "modal.iconUpload":       "Загрузить иконку",
      "modal.iconReset":        "Стандартная",
      "modal.name":             "Название",
      "modal.namePlaceholder":  "Напр. Dragon",
      "modal.value":            "Значение",
      "modal.valuePlaceholder": "Напр. 60000",
      "modal.desc":             "Описание (необязательно)",
      "modal.descPlaceholder":  "Кратко о фрукте — показывается при наведении",
      "modal.new":              "Значок «NEW» (новый / изменён)",
      "modal.newTitle":         "Показать значок NEW на предмете (новый или изменённый)",
      "modal.wip":              "Значок «?» (под вопросом)",
      "modal.wipTitle":         "Показать значок «?» на предмете (цена под вопросом). Работает вместе с NEW",
      "modal.fruitType":        "Тип фрукта",
      "modal.fruitPlain":       "Обычный",
      "modal.fruitPerm":        "Перманент",
      "modal.category":         "Категория (необязательно)",
      "modal.catSkin":          "S · Скин",
      "modal.catMutation":      "M · Мутация",
      "modal.catPass":          "GP · Пасс",
      "modal.catChromatic":     "CR · Хроматик",
      "modal.demand":           "Спрос",
      "modal.trend":            "Тренд",
      "modal.delete":           "Удалить",
      "modal.done":             "Готово",

      "tier.rename":            "Кликните, чтобы переименовать тир",
      "tier.logo":              "Свой логотип тира",
      "tier.up":                "Поднять тир",
      "tier.down":              "Опустить тир",
      "tier.remove":            "Удалить тир",

      "ad.chip":                "РЕКЛАМА",
      "ad.banner":              "🖼 Баннер",
      "ad.bannerTitle":         "Поставить картинку-баннер",

      "msg.confirmDeleteItem":  "Удалить предмет?",
      "msg.confirmDeleteTier":  "Удалить тир вместе с предметами?",
      "msg.confirmReset":       "Сбросить тирлист к стандартному шаблону?",
      "msg.saveFailed":         "Не удалось сохранить. Проверьте интернет и попробуйте ещё раз.",
      "msg.updated":            "Тирлист обновили. Показать новую версию?",
      "msg.show":               "Показать",
      "msg.uploadFailed":       "Не удалось загрузить картинку",
      "msg.imageTooLarge":      "Картинка слишком большая (максимум 500 КБ)"
    },

    en: {
      "lang.switch":            "Interface language",
      "lang.ru":                "RU",
      "lang.en":                "EN",

      "auth.login":             "🔑 Log in",
      "auth.loginTitle":        "Log in as administrator",
      "auth.admin":             "👑 Admin",
      "auth.logout":            "Log out",
      "auth.prompt":            "Administrator password:",
      "auth.wrong":             "Wrong password",
      "auth.locked":            "Too many attempts. Try again in",
      "auth.seconds":           "s.",

      "like.title":             "Like this tier list",
      "donate.button":          "Support",
      "donate.title":           "Support the project",
      "donate.modalTitle":      "💜 Support the project",
      "donate.modalSub":        "Thanks for helping keep the tier list alive!",
      "donate.linkHub":         "All methods (dalink)",
      "donate.editDA":          "🔗 DonationAlerts",
      "donate.editDATitle":     "Change the DonationAlerts link",
      "donate.editHub":         "🔗 All methods",
      "donate.editHubTitle":    "Change the hub link (dalink)",
      "donate.qrUpload":        "🖼 QR",
      "donate.qrUploadTitle":   "Upload a new QR code",
      "donate.qrReset":         "Default QR",
      "donate.qrResetTitle":    "Restore the default QR",
      "donate.qrAlt":           "Donation QR code",
      "donate.qrCaption":       "Point your phone camera here",

      "filters.label":          "Show:",
      "filters.fruits":         "Fruits",
      "filters.fruitsTitle":    "Regular fruits",
      "filters.mutations":      "Mutations",
      "filters.mutationsTitle": "Mutations",
      "filters.perms":          "Perms",
      "filters.permsTitle":     "Permanent fruits",
      "filters.passes":         "Passes",
      "filters.passesTitle":    "Game passes",
      "filters.skins":          "Skins",
      "filters.skinsTitle":     "Skins and chromatics",
      "filters.all":            "All",
      "filters.allTitle":       "Show everything",

      "admin.addTier":          "＋ Tier",
      "admin.addTierTitle":     "Add a new tier",
      "admin.addItem":          "＋ Item",
      "admin.addItemTitle":     "Add an item to the first tier",
      "admin.sort":             "⇅ Sort",
      "admin.sortTitle":        "Sort every item by value (descending)",
      "admin.autoSort":         "Auto-sort",
      "admin.autoSortTitle":    "Move an item automatically when its value changes",
      "admin.editing":          "Editing",
      "admin.editingTitle":     "Editing mode",
      "admin.saved":            "✓ Saved",
      "admin.save":             "Save",
      "admin.saving":           "Saving…",
      "admin.saveTitle":        "Publish the changes for everyone",
      "admin.png":              "⬇ Download PNG",
      "admin.pngTitle":         "Download the tier list as a PNG",
      "admin.export":           "Export",
      "admin.exportTitle":      "Save as JSON",
      "admin.import":           "Import",
      "admin.importTitle":      "Load JSON",
      "admin.reset":            "Reset",
      "admin.resetTitle":       "Reset to the template",
      "admin.addItemToTier":    "Add an item",

      "stage.dateTitle":        "Click to change the date",
      "stage.brandAlt":         "Logo",

      "legend.title":           "BEGINNER'S GUIDE",
      "legend.f":               "Regular fruit",
      "legend.s":               "Skin",
      "legend.m":               "Mutation",
      "legend.p":               "Permanent",
      "legend.gp":              "Game pass",
      "legend.cr":              "Chromatic",
      "legend.good":            "High",
      "legend.mid":             "Medium",
      "legend.low":             "Below average",
      "legend.bad":             "Low",
      "legend.up":              "Rising",
      "legend.swap":            "Under review",
      "legend.down":            "Falling",
      "legend.wip":             "Uncertain",

      "modal.itemTitle":        "Item",
      "modal.close":            "Close",
      "modal.iconUpload":       "Upload icon",
      "modal.iconReset":        "Default",
      "modal.name":             "Name",
      "modal.namePlaceholder":  "e.g. Dragon",
      "modal.value":            "Value",
      "modal.valuePlaceholder": "e.g. 60000",
      "modal.desc":             "Description (optional)",
      "modal.descPlaceholder":  "A short note — shown on hover",
      "modal.new":              "“NEW” badge (new / changed)",
      "modal.newTitle":         "Show the NEW badge on this item (new or changed)",
      "modal.wip":              "“?” badge (uncertain)",
      "modal.wipTitle":         "Show the “?” badge on this item (value uncertain). Works together with NEW",
      "modal.fruitType":        "Fruit type",
      "modal.fruitPlain":       "Regular",
      "modal.fruitPerm":        "Permanent",
      "modal.category":         "Category (optional)",
      "modal.catSkin":          "S · Skin",
      "modal.catMutation":      "M · Mutation",
      "modal.catPass":          "GP · Pass",
      "modal.catChromatic":     "CR · Chromatic",
      "modal.demand":           "Demand",
      "modal.trend":            "Trend",
      "modal.delete":           "Delete",
      "modal.done":             "Done",

      "tier.rename":            "Click to rename the tier",
      "tier.logo":              "Custom tier logo",
      "tier.up":                "Move tier up",
      "tier.down":              "Move tier down",
      "tier.remove":            "Delete tier",

      "ad.chip":                "AD",
      "ad.banner":              "🖼 Banner",
      "ad.bannerTitle":         "Set a banner image",

      "msg.confirmDeleteItem":  "Delete this item?",
      "msg.confirmDeleteTier":  "Delete the tier and everything in it?",
      "msg.confirmReset":       "Reset the tier list to the default template?",
      "msg.saveFailed":         "Could not save. Check your connection and try again.",
      "msg.updated":            "The tier list was updated. Show the new version?",
      "msg.show":               "Show",
      "msg.uploadFailed":       "Could not upload the image",
      "msg.imageTooLarge":      "Image is too large (500 KB maximum)"
    }
  };

  var DEFAULT_LANG = "ru";

  function supported() { return Object.keys(STRINGS); }

  // Неизвестный язык откатывается на русский, неизвестный ключ — на русскую
  // строку, а если и её нет — возвращается сам ключ. Пустой экран из-за
  // забытого перевода хуже, чем текст не на том языке.
  function t(key, lang) {
    var table = STRINGS[lang] || STRINGS[DEFAULT_LANG];
    if (Object.prototype.hasOwnProperty.call(table, key)) { return table[key]; }
    var fallback = STRINGS[DEFAULT_LANG];
    return Object.prototype.hasOwnProperty.call(fallback, key) ? fallback[key] : key;
  }

  // Язык из явного выбора, иначе из настроек браузера, иначе русский.
  function pickLang(stored, navigatorLang) {
    if (stored && STRINGS[stored]) { return stored; }
    var nav = String(navigatorLang || "").toLowerCase();
    if (nav.indexOf("ru") === 0) { return "ru"; }
    return nav ? "en" : DEFAULT_LANG;
  }

  var api = { STRINGS: STRINGS, DEFAULT_LANG: DEFAULT_LANG, supported: supported, t: t, pickLang: pickLang };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.I18N = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
