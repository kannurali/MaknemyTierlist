(function (m, e) {
  if (!/(^|\.)maknemy\.com$/.test(m.location.hostname)) { return; }
  var r = 'https://mc.yandex.ru/metrika/tag.js?id=111127188';
  m.ym = m.ym || function () { (m.ym.a = m.ym.a || []).push(arguments); };
  m.ym.l = 1 * new Date();
  var have = false;
  for (var j = 0; j < e.scripts.length; j++) { if (e.scripts[j].src === r) { have = true; } }
  if (!have) {
    var k = e.createElement('script'), a = e.getElementsByTagName('script')[0];
    k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
  }
  m.ym(111127188, 'init', {ssr: true, webvisor: true, trackHash: true, clickmap: true, referrer: e.referrer, url: m.location.href, accurateTrackBounce: true, trackLinks: true});
})(window, document);
