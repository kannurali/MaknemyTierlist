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

  var api = {
    CATEGORIES: CATEGORIES,
    isCategory: isCategory,
    pickLang: pickLang,
    formatDate: formatDate,
    toParagraphs: toParagraphs
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NEWS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
