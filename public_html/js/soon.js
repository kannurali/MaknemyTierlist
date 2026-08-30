/* ===================================================================
   Страница «в разработке» (/soon). Никакого состояния, кроме языка
   интерфейса — тот же приём переключения, что в app.js/news-page.js/
   calculator-page.js, только без всего, что здесь не нужно (слотов,
   каталога, вердикта).
   =================================================================== */
(function () {
  "use strict";

  var $ = function (sel) { return document.querySelector(sel); };

  // Тот же ключ, что и на остальных страницах (app.js/news-page.js/
  // calculator-page.js, LANG_KEY) — иначе выбор языка не переносился бы
  // между разделами сайта.
  var LANG_KEY = "nexus-lang-v1";

  var lang = I18N.pickLang(
    (function () { try { return localStorage.getItem(LANG_KEY); } catch (_) { return null; } })(),
    navigator.language
  );

  function tx(key) { return I18N.t(key, lang); }

  function applyLang(next) {
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (_) { /* приватный режим */ }
    }
    document.documentElement.lang = lang;

    document.querySelectorAll("[data-i18n]").forEach(function (el) { el.textContent = tx(el.dataset.i18n); });
    document.querySelectorAll("[data-i18n-title]").forEach(function (el) { el.title = tx(el.dataset.i18nTitle); });
    document.querySelectorAll("[data-i18n-label]").forEach(function (el) {
      el.setAttribute("aria-label", tx(el.dataset.i18nLabel));
    });

    document.querySelectorAll("#langSwitch [data-lang]").forEach(function (b) {
      var on = b.dataset.lang === lang;
      b.classList.toggle("active", on);
      b.setAttribute("aria-pressed", String(on));
    });
  }

  (function initLangSwitch() {
    var box = $("#langSwitch");
    if (!box) return;
    box.addEventListener("click", function (e) {
      var btn = e.target.closest("[data-lang]");
      if (btn) applyLang(btn.dataset.lang);
    });
  })();

  applyLang(); // без аргумента — язык уже выбран выше, localStorage лишний раз не трогаем
})();
