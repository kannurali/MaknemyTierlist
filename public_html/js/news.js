// Лента новостей: категории, выбор языка, дата, разбивка на абзацы.
//
// Файл не трогает DOM: его можно подключить <script>-ом в браузере и
// потребовать из node в тестах — как i18n.js, tiers.js и content.js.
(function (root) {
  "use strict";

  // Единый список категорий. По нему же проверяет api/news_save.php, поэтому
  // фронт и сервер не могут разойтись в том, что считают допустимым.
  var CATEGORIES = [
    { key: "tierlist", i18n: "news.catTierlist", cls: "c-tierlist" },
    { key: "game",     i18n: "news.catGame",     cls: "c-game" },
    { key: "project",  i18n: "news.catProject",  cls: "c-project" }
  ];

  function isCategory(key) {
    for (var i = 0; i < CATEGORIES.length; i++) {
      if (CATEGORIES[i].key === key) { return true; }
    }
    return false;
  }

  // Единый список выравниваний картинки в карточке. Та же логика единого
  // источника правды, что и у CATEGORIES: api/news_save.php проверяет ровно
  // этот же список из трёх ключей, поэтому редактор не может предложить
  // выравнивание, которое сервер не примет. Свободная ширина (image_pct)
  // такого списка не требует — это число 10..100, а не набор из фиксированных
  // вариантов, поэтому у неё нет ALIGNS-подобного массива.
  var ALIGNS = [
    { key: "left",   i18n: "news.alignLeft" },
    { key: "center", i18n: "news.alignCenter" },
    { key: "right",  i18n: "news.alignRight" }
  ];

  function isAlign(key) {
    for (var i = 0; i < ALIGNS.length; i++) {
      if (ALIGNS[i].key === key) { return true; }
    }
    return false;
  }

  // Потолок стороны картинки, в device-пикселях, для выбранной ширины
  // (image_pct). Обязана оставаться БУКВАЛЬНО той же формулой, что и
  // news_image_cap() в api/lib/images.php — вывод геометрии (16.8 × pct,
  // зажатое в [256, 1280]) описан в русском комментарии над той функцией и
  // здесь не повторяется, чтобы у формулы было одно место правды, а не два
  // текста, которые можно незаметно рассинхронить. Та же дисциплина единого
  // источника, что и у пары CATEGORIES (здесь) / NEWS_CATEGORIES (в
  // api/news_save.php) — только на этот раз источник живёт на сервере, а
  // клиент (confirmCrop() в news-page.js) обязан следовать за ним, а не
  // изобретать собственный потолок.
  function newsImageCap(pct) {
    return Math.max(256, Math.min(1280, Math.ceil(16.8 * pct)));
  }

  // Заголовок и текст на нужном языке, с откатом на второй. Зеркалит descFor
  // из content.js по той же причине: наполовину заполненный пост должен
  // показать хоть что-то, а не пустоту.
  function pickLang(post, lang) {
    if (!post) { return { title: "", body: "" }; }
    var tRu = String(post.title_ru || "").trim();
    var tEn = String(post.title_en || "").trim();
    var bRu = String(post.body_ru  || "").trim();
    var bEn = String(post.body_en  || "").trim();
    return lang === "en"
      ? { title: tEn || tRu, body: bEn || bRu }
      : { title: tRu || tEn, body: bRu || bEn };
  }

  // DD.MM.YYYY на обоих языках — ровно так дата напечатана на постере.
  // toLocaleDateString не используется намеренно: его вывод зависит от локали
  // читателя (у кого-то 8/16/2026) и разъезжается с макетом.
  function formatDate(ms) {
    var d = new Date(Number(ms) || 0);
    var pad = function (n) { return (n < 10 ? "0" : "") + n; };
    return pad(d.getDate()) + "." + pad(d.getMonth() + 1) + "." + d.getFullYear();
  }

  // Текст в массив абзацев: пустая строка — граница. Возвращается массив
  // строк, а не готовый HTML, именно чтобы рендер клал их через textContent.
  function toParagraphs(text) {
    return String(text == null ? "" : text)
      .replace(/\r\n?/g, "\n")
      .split(/\n\s*\n/)
      .map(function (s) { return s.trim(); })
      .filter(function (s) { return s !== ""; });
  }

  // Кроп-редактор (задача 8, вторая часть): переводит рамку выделения,
  // нарисованную на превью-канвасе редактора, в прямоугольник на исходном
  // изображении. Чистая геометрия, без DOM и без canvas — сам рендер и
  // pointer-обработчики живут в news-page.js и вызывают эту функцию.
  //
  // viewport ({width, height}) — размер холста редактора в css-px; сюда
  // рисуется картинка. image ({width, height}) — натуральный размер
  // декодированного исходника (ImageBitmap/<img>). zoom — во сколько раз
  // исходник увеличен при отрисовке на холст (на холсте картинка занимает
  // image.width*zoom × image.height*zoom px) — это ЭФФЕКТИВНЫЙ масштаб
  // (вписывание + позиция ползунка вместе), а не одно лишь положение
  // ползунка. pan ({x, y}) — где на холсте лежит левый верхний угол
  // картинки; может быть отрицательным, если картинку утащили влево/вверх
  // за край холста. frame ({x, y, w, h}) — рамка выделения в тех же
  // координатах холста, что и pan.
  //
  // Возвращает {sx, sy, sw, sh} — прямоугольник на исходном изображении.
  // Это единственное место, которое отвечает за инвариант «кроп не может
  // прочитать то, чего нет в исходнике»: результат всегда зажат в
  // [0, image.width] × [0, image.height], даже если рамка или панорама
  // переданы такими, что наивная формула читала бы за краем картинки —
  // news-page.js на это полагается, не дублируя зажатие у себя, и тесты
  // проверяют именно это поведение, а не только «счастливый путь».
  function cropToSourceRect(frame, zoom, pan, image) {
    var z = zoom > 0 ? zoom : 1;

    var rawW = frame.w / z;
    var rawH = frame.h / z;
    var sw = Math.min(Math.max(rawW, 0), image.width);
    var sh = Math.min(Math.max(rawH, 0), image.height);

    var rawX = (frame.x - pan.x) / z;
    var rawY = (frame.y - pan.y) / z;
    var sx = Math.min(Math.max(rawX, 0), image.width - sw);
    var sy = Math.min(Math.max(rawY, 0), image.height - sh);

    return { sx: sx, sy: sy, sw: sw, sh: sh };
  }

  var api = {
    CATEGORIES: CATEGORIES,
    isCategory: isCategory,
    ALIGNS: ALIGNS,
    isAlign: isAlign,
    newsImageCap: newsImageCap,
    pickLang: pickLang,
    formatDate: formatDate,
    toParagraphs: toParagraphs,
    cropToSourceRect: cropToSourceRect
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NEWS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
