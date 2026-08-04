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
      "tier.logoOff":           "Убрать логотип (показывать текст)",
      "tier.up":                "Поднять тир",
      "tier.down":              "Опустить тир",
      "tier.remove":            "Удалить тир",

      "ad.chip":                "РЕКЛАМА",
      "ad.banner":              "🖼 Баннер",
      "ad.bannerTitle":         "Поставить картинку-баннер",
      "ad.imageOff":            "Убрать картинку",
      "ad.isLink":              "Это ссылка — нажмите по рекламе, чтобы открыть",

      "cell.wipTitle":          "Цена под вопросом",

      "credits.remove":         "Убрать из списка",
      "credits.add":            "Добавить участника",
      "footer.editUrl":         "Изменить ссылку (URL)",
      "footer.removeLink":      "Удалить ссылку",
      "footer.addLink":         "Добавить ссылку",

      "png.readyTitle":         "Картинка готова — нажми, чтобы сохранить",
      "png.rendering":          "Рендер…",
      "png.save":               "💾 Сохранить PNG",
      "png.readyHint":          "Картинка готова — нажми «💾 Сохранить PNG»",

      "item.edit":              "Изменить",
      "view.noName":            "Без названия",
      "view.noDesc":            "Описание не добавлено.",
      "like.remove":            "Убрать лайк",

      "ad.linkLabel":           "Ссылка",
      "ad.imageAlt":            "Реклама",
      "ad.textMode":            "Т Текст",
      "ad.link":                "🔗 Ссылка",
      "ad.linkSet":             "🔗 Ссылка ✓",
      "ad.linkTitle":           "Скрытая ссылка: клик по рекламе откроет её, URL в тексте не виден. Пусто — убрать.",
      "ad.linkPrompt":          "Скрытая ссылка (URL) для рекламы. Оставьте пустым, чтобы убрать:",

      "footer.addLinkBtn":      "＋ ссылка",
      "footer.urlPrompt":       "Ссылка (URL). Можно без https:// — подставится сам:",

      "donate.promptDA":        "Ссылка на прямой донат (DonationAlerts):",
      "donate.promptHub":       "Ссылка на хаб со всеми способами:",

      "sync.title":             "Есть свежие изменения",
      "sync.sub":               "Другой администратор обновил тирлист. Обновить сейчас? Ваши несохранённые правки будут заменены версией из базы.",
      "sync.apply":             "Обновить",
      "sync.dismiss":           "Оставить мои правки",

      "msg.copy":               "Копировать",
      "msg.saveTimeout":        "⚠ Долго нет ответа — проверьте интернет и размер картинок",
      "msg.saveError":          "Ошибка сохранения",
      "msg.noTiersField":       "нет поля tiers",
      "msg.h2cFailed":          "Не удалось загрузить html2canvas",
      "msg.pngMemory":          "не хватило памяти на картинку — попробуйте в вертикальной ориентации",
      "msg.pngSaveFailed":      "Не удалось сохранить PNG.",
      "msg.pngFileHint":        "Откройте сайт через локальный сервер (например: python -m http.server), а не файлом — браузер блокирует экспорт картинок с file://.",
      "msg.restored":           "♻ Восстановлены несохранённые правки — нажмите «Сохранить»",
      "msg.confirmDeleteTier":  "Удалить тир «{tier}» вместе с {count} предметами?",
      "msg.confirmReset":       "Сбросить тирлист к стандартному шаблону? Текущие данные будут потеряны.",
      "msg.readFailed":         "Не удалось прочитать файл: ",
      "msg.iconsMissing":       "Не загрузилось иконок: ",
      "msg.iconsMissingTail":   ". В картинке они будут пустыми.\nПроверьте интернет и попробуйте ещё раз.",
      "msg.loginFailed":        "Ошибка входа"
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
      "tier.logoOff":           "Remove the logo (show text instead)",
      "tier.up":                "Move tier up",
      "tier.down":              "Move tier down",
      "tier.remove":            "Delete tier",

      "ad.chip":                "AD",
      "ad.banner":              "🖼 Banner",
      "ad.bannerTitle":         "Set a banner image",
      "ad.imageOff":            "Remove the image",
      "ad.isLink":              "This is a link — tap the ad to open it",

      "cell.wipTitle":          "Value uncertain",

      "credits.remove":         "Remove from the list",
      "credits.add":            "Add a member",
      "footer.editUrl":         "Change the link (URL)",
      "footer.removeLink":      "Delete the link",
      "footer.addLink":         "Add a link",

      "png.readyTitle":         "The image is ready — tap to save it",
      "png.rendering":          "Rendering…",
      "png.save":               "💾 Save PNG",
      "png.readyHint":          "The image is ready — tap “💾 Save PNG”",

      "item.edit":              "Edit",
      "view.noName":            "Untitled",
      "view.noDesc":            "No description yet.",
      "like.remove":            "Remove the like",

      "ad.linkLabel":           "Link",
      "ad.imageAlt":            "Advertisement",
      "ad.textMode":            "T Text",
      "ad.link":                "🔗 Link",
      "ad.linkSet":             "🔗 Link ✓",
      "ad.linkTitle":           "Hidden link: tapping the ad opens it, the URL stays out of the text. Empty removes it.",
      "ad.linkPrompt":          "Hidden link (URL) for the ad. Leave empty to remove it:",

      "footer.addLinkBtn":      "＋ link",
      "footer.urlPrompt":       "Link (URL). You can omit https:// — it is added automatically:",

      "donate.promptDA":        "Direct donation link (DonationAlerts):",
      "donate.promptHub":       "Link to the hub with every method:",

      "sync.title":             "There are newer changes",
      "sync.sub":               "Another administrator updated the tier list. Update now? Your unsaved edits will be replaced with the version from the database.",
      "sync.apply":             "Update",
      "sync.dismiss":           "Keep my edits",

      "msg.copy":               "Copy",
      "msg.saveTimeout":        "⚠ No response for a while — check your connection and the image sizes",
      "msg.saveError":          "Save failed",
      "msg.noTiersField":       "no tiers field",
      "msg.h2cFailed":          "Could not load html2canvas",
      "msg.pngMemory":          "not enough memory for the image — try portrait orientation",
      "msg.pngSaveFailed":      "Could not save the PNG.",
      "msg.pngFileHint":        "Open the site through a local server (e.g. python -m http.server) instead of as a file — browsers block image export from file://.",
      "msg.restored":           "♻ Unsaved edits restored — press “Save”",
      "msg.confirmDeleteTier":  "Delete tier “{tier}” and its {count} items?",
      "msg.confirmReset":       "Reset the tier list to the default template? Current data will be lost.",
      "msg.readFailed":         "Could not read the file: ",
      "msg.iconsMissing":       "Icons that failed to load: ",
      "msg.iconsMissingTail":   ". They will be blank in the image.\nCheck your connection and try again.",
      "msg.loginFailed":        "Login error"
    }
  };

  var DEFAULT_LANG = "ru";

  function supported() { return Object.keys(STRINGS); }

  // Неизвестный язык откатывается на русский, неизвестный ключ — на русскую
  // строку, а если и её нет — возвращается сам ключ. Пустой экран из-за
  // забытого перевода хуже, чем текст не на том языке.
  //
  // vars подставляются по имени: t("msg.confirmDeleteTier", "ru", {tier: "S",
  // count: 3}). Порядок слов в языках разный, поэтому именно по имени, а не по
  // позиции. Неизвестное имя оставляется как есть — так пропущенную
  // подстановку видно в интерфейсе, а не молча теряется.
  function t(key, lang, vars) {
    var table = STRINGS[lang] || STRINGS[DEFAULT_LANG];
    var fallback = STRINGS[DEFAULT_LANG];
    var s;
    if (Object.prototype.hasOwnProperty.call(table, key)) { s = table[key]; }
    else if (Object.prototype.hasOwnProperty.call(fallback, key)) { s = fallback[key]; }
    else { return key; }

    if (!vars) { return s; }
    return s.replace(/\{(\w+)\}/g, function (whole, name) {
      return Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : whole;
    });
  }

  // Язык из явного выбора, иначе из настроек браузера, иначе русский.
  //
  // Английский — только по явному en*. Обратное правило («не ru — значит en»)
  // отправляло бы на английский всех, у кого в браузере kk-KZ, tr-TR или
  // en-US при русском интерфейсе в голове: аудитория сайта русскоязычная, и
  // казахская локаль здесь обычная, а не признак иностранца. Незнакомая
  // локаль остаётся на русском — это язык, на котором сайт написан.
  function pickLang(stored, navigatorLang) {
    if (stored && STRINGS[stored]) { return stored; }
    var nav = String(navigatorLang || "").toLowerCase();
    return nav.indexOf("en") === 0 ? "en" : DEFAULT_LANG;
  }

  var api = { STRINGS: STRINGS, DEFAULT_LANG: DEFAULT_LANG, supported: supported, t: t, pickLang: pickLang };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.I18N = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
