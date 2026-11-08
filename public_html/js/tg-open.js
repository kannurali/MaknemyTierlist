(function () {
  "use strict";

  var tg = window.Telegram && window.Telegram.WebApp;
  var meta = document.querySelector('meta[name="nx-tg-pages"]');
  var pages = {};
  try { pages = JSON.parse(meta ? meta.getAttribute("content") : "{}") || {}; } catch (e) { pages = {}; }

  var start = "";
  try { start = new URLSearchParams(window.location.search).get("tgWebAppStartParam") || ""; } catch (e) { start = ""; }
  if (!start && tg && tg.initDataUnsafe) { start = String(tg.initDataUnsafe.start_param || ""); }
  if (!start || !Object.prototype.hasOwnProperty.call(pages, start)) { return; }

  window.NX_TG_PAGE = true;
  var url = new URL(pages[start], window.location.href).href;

  if (tg && /^web/.test(String(tg.platform || ""))) {
    tg.openLink(url);
    tg.close();
    return;
  }
  window.location.replace(url);
})();
