(function (w, d) {
  var s = d.currentScript;
  var id = s ? s.getAttribute("data-id") : "";
  if (!id || w.gtag || !/(^|\.)maknemy\.com$/.test(w.location.hostname)) { return; }
  w.dataLayer = w.dataLayer || [];
  w.gtag = function () { w.dataLayer.push(arguments); };
  w.gtag("js", new Date());
  w.gtag("config", id, { allow_google_signals: false, allow_ad_personalization_signals: false });
  var t = d.createElement("script");
  t.async = true;
  t.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(id);
  d.head.appendChild(t);
})(window, document);
