// Рекламные кампании: нормализация, расписание, ротация, частотный лимит.
//
// Здесь только чистые функции. Ни DOM, ни таймеров, ни localStorage — всё
// состояние приходит аргументами и возвращается новым значением. Причина
// простая: app.js — двухтысячестрочный IIFE, завязанный на DOM, и он не
// покрыт тестами вообще. Логика показа рекламы решает, что именно увидит
// платящий клиент, и ошибаться в ней дороже всего, поэтому она вынесена
// туда, где её можно прогнать через node --test.
//
// Файл подключается <script>-ом ДО app.js и требуется из tests/promo_test.mjs.
//
// ВАЖНО про имя файла: назвать его js/ads.js нельзя — это самое надёжно
// блокируемое имя файла в интернете, его режут сетевые фильтры EasyList.
// По той же причине классы в разметке идут с префиксом ptn- (partner), а не
// ad-: селекторы ##.ad-block и ##[class^="ad-"] — стандартные косметические
// правила. Реклама, которую не видно у трети посетителей, не продаётся.
(function (root) {
  "use strict";

  // Места размещения. Порядок важен только для вывода в админке.
  //
  // rail и dock — одно и то же размещение «сбоку от контента», но в двух
  // ориентациях: на десктопе это вертикальная свеча по краям постера, на
  // телефоне — горизонтальная полоса, приклеенная к низу экрана. Вертикальный
  // макет в горизонтальную полосу не влезает, поэтому это отдельный слот со
  // своим размером, а не тот же файл в другом месте.
  var SLOTS = ["strip", "rail", "dock", "popup"];

  // Потолок числа слайдов в карусели. Держим низким намеренно: у каждого
  // живого слайда есть декодированный битмап, а сцена на телефоне уже
  // расходует десятки мегабайт (см. комментарии в styles.css про падения
  // вкладок в iOS). Восемь — это запас, реально кампаний будет две-три.
  var MAX_STRIP_SLIDES = 8;

  // Даты кампаний считаем в фиксированном московском времени (UTC+3, без
  // перехода на летнее — в России его нет с 2014 года). Не в локальном
  // времени посетителя: тогда «размещение до 20.09» кончалось бы в разные
  // моменты для Калининграда, Москвы и Казахстана, а это спор об оплате.
  var MSK_OFFSET_MS = 3 * 60 * 60 * 1000;

  var DAY_MS = 24 * 60 * 60 * 1000;
  var WEEK_MS = 7 * DAY_MS;

  // Значения по умолчанию для попапа. delayMs = 12 с — середина запрошенного
  // клиентом окна 10-15 с.
  var POPUP_DEFAULTS = { delayMs: 12000, capHours: 24, maxPerWeek: 3 };
  var POPUP_LIMITS = {
    delayMs:    { min: 5000, max: 60000 },
    capHours:   { min: 1,    max: 720 },
    maxPerWeek: { min: 1,    max: 50 }
  };

  var ID_RE = /^[A-Za-z0-9_-]{1,64}$/;
  // Токен маркировки рекламы, который выдаёт ОРД («2VtzquZgWvo»). Поле
  // необязательное: пустое — под баннером ничего не рисуется, сайт ведёт себя
  // ровно как до его появления. Формат токена — те же символы, что у id;
  // всё остальное отбрасываем, чтобы под видом erid не приехала строка,
  // которую придётся выводить на страницу.
  var ERID_RE = /^[A-Za-z0-9_-]{1,64}$/;
  var DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
  var SAFE_SCHEME = /^(https?:|mailto:|tel:)/i;

  // ==========================================================================
  //  Ссылки
  // ==========================================================================

  // Те же правила, что normalizeHref() в app.js, но чистой экспортируемой
  // функцией: её вызывает и сайт, и админка, и она проверяема тестом.
  // Серверный promo_safe_href() в api/promo.php повторяет их ещё раз —
  // админ-клиент не граница доверия, ссылку в базе валидирует сервер.
  function safeHref(href) {
    var s = String(href == null ? "" : href).trim();
    // «https://» без адреса остаётся от недозаполненной формы.
    if (s === "" || /^https?:\/*$/i.test(s)) { return ""; }
    // Пробел или перевод строки внутри адреса — это не адрес.
    if (/[\s<>"]/.test(s)) { return ""; }
    // Со схемой пропускаем только безопасные: javascript:, data:, vbscript:
    // отсекаются здесь.
    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) { return SAFE_SCHEME.test(s) ? s : ""; }
    // «//host» — протокол-относительный адрес, а не путь внутри сайта.
    if (s.indexOf("//") === 0) { return "https:" + s; }
    if (s.charAt(0) === "/" || s.charAt(0) === "#") { return s; }
    return "https://" + s.replace(/^\/+/, "");
  }

  // ==========================================================================
  //  Расписание
  // ==========================================================================

  // "2026-08-20" -> границы этих суток по МСК в миллисекундах UTC.
  // Конец включительный: кампания «до 20.09» работает весь день 20-го.
  // Несуществующая дата ("2026-02-31") возвращает null, а не молча уезжает
  // на 3 марта, как сделал бы конструктор Date.
  function dayBoundsMsk(dateStr) {
    var m = DATE_RE.exec(String(dateStr == null ? "" : dateStr).trim());
    if (!m) { return null; }
    var y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }
    var utcMidnight = Date.UTC(y, mo - 1, d);
    var probe = new Date(utcMidnight);
    // Проверка на переполнение: 31 февраля превратится в март.
    if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d) {
      return null;
    }
    var startMs = utcMidnight - MSK_OFFSET_MS;
    return { startMs: startMs, endMs: startMs + DAY_MS - 1 };
  }

  // Пустая дата = граница не задана (бессрочно с этой стороны).
  // Кривая дата = кампанию не показываем. Показать рекламу с непонятным
  // сроком хуже, чем не показать: за неё либо не заплатили, либо срок вышел.
  function inWindow(campaign, nowMs) {
    if (!campaign) { return false; }
    var now = Number(nowMs);
    if (!isFinite(now)) { return false; }

    var start = String(campaign.start == null ? "" : campaign.start).trim();
    if (start !== "") {
      var sb = dayBoundsMsk(start);
      if (!sb || now < sb.startMs) { return false; }
    }
    var end = String(campaign.end == null ? "" : campaign.end).trim();
    if (end !== "") {
      var eb = dayBoundsMsk(end);
      if (!eb || now > eb.endMs) { return false; }
    }
    return true;
  }

  function creativeFor(campaign, slot) {
    if (!campaign || !campaign.creatives) { return null; }
    var c = campaign.creatives[slot];
    if (!c || typeof c !== "object") { return null; }
    return String(c.src || "").trim() ? c : null;
  }

  // Кампании, которые прямо сейчас имеют право показаться в этом слоте.
  // Слот должен быть и в списке slots (это выключатель в админке), и иметь
  // непустой креатив — чтобы выключить размещение, не удаляя макет.
  function eligible(doc, slot, nowMs) {
    var d = normalizeDoc(doc);
    if (SLOTS.indexOf(slot) < 0) { return []; }
    var out = [];
    for (var i = 0; i < d.campaigns.length; i++) {
      var c = d.campaigns[i];
      if (!c.enabled) { continue; }
      if (c.slots.indexOf(slot) < 0) { continue; }
      if (!creativeFor(c, slot)) { continue; }
      if (!inWindow(c, nowMs)) { continue; }
      out.push(c);
    }
    return out;
  }

  // ==========================================================================
  //  Ротация
  // ==========================================================================

  // rnd принимается и числом, и функцией: тесты удобнее писать с числом,
  // а карусель дёргает генератор много раз подряд.
  function toRndFn(rnd) {
    if (typeof rnd === "function") { return rnd; }
    var n = Number(rnd);
    if (!isFinite(n)) { n = 0; }
    return function () { return n; };
  }

  function clamp01(x) {
    var n = Number(x);
    if (!isFinite(n) || n < 0) { return 0; }
    return n < 1 ? n : 0.999999999;
  }

  // Доля показов = weight / сумма весов. Это то, что продаётся напрямую:
  // «Премиум = втрое большая доля ротации, чем Старт».
  function pickWeighted(campaigns, rnd) {
    var list = Array.isArray(campaigns) ? campaigns.filter(Boolean) : [];
    if (!list.length) { return null; }
    var next = toRndFn(rnd);

    var total = 0, i;
    for (i = 0; i < list.length; i++) { total += Math.max(0, Number(list[i].weight) || 0); }
    // Все веса нулевые — равномерно, а не undefined.
    if (total <= 0) {
      return list[Math.min(list.length - 1, Math.floor(clamp01(next()) * list.length))];
    }

    var x = clamp01(next()) * total, acc = 0;
    for (i = 0; i < list.length; i++) {
      acc += Math.max(0, Number(list[i].weight) || 0);
      if (x < acc) { return list[i]; }
    }
    return list[list.length - 1];
  }

  function advKey(c) { return String(c.advertiser || c.id); }

  // Порядок слайдов карусели: по одному слайду на кампанию, порядок —
  // взвешенная выборка без возврата, и два подряд слайда одного
  // рекламодателя запрещены (иначе купивший два пакета Playerok выглядит
  // как заевшая пластинка). Детерминирован при фиксированном rnd, поэтому
  // тест может утверждать точный порядок, а демо воспроизводимо.
  //
  // Из кандидатов берём не любого, а того рекламодателя, у кого осталось
  // больше всего слайдов. Без этого правила жадный выбор загоняет себя в
  // угол: при двух кампаниях Playerok и одной чужой порядок «чужая,
  // Playerok, Playerok» оставляет два одинаковых подряд, хотя расстановка
  // без повтора существует. Приоритет самого многочисленного — стандартное
  // решение задачи о перестановке без соседних одинаковых, и оно находит
  // корректный порядок всегда, когда тот вообще возможен.
  function orderForCarousel(campaigns, rnd, max) {
    var pool = Array.isArray(campaigns) ? campaigns.filter(Boolean).slice() : [];
    var cap = Number(max);
    if (!isFinite(cap) || cap < 1) { cap = MAX_STRIP_SLIDES; }
    cap = Math.min(cap, MAX_STRIP_SLIDES);

    var next = toRndFn(rnd);
    var out = [], lastAdv = null;

    while (pool.length && out.length < cap) {
      // Сколько слайдов осталось у каждого рекламодателя.
      var left = {};
      for (var i = 0; i < pool.length; i++) {
        var k = advKey(pool[i]);
        left[k] = (left[k] || 0) + 1;
      }

      // Кандидаты — все, кроме прошлого рекламодателя. Если других нет,
      // повтор неизбежен (например, три кампании из четырёх — от одного
      // рекламодателя): пустой слайд хуже повтора, берём кого есть.
      var cands = pool.filter(function (c) { return !lastAdv || advKey(c) !== lastAdv; });
      if (!cands.length) { cands = pool.slice(); }

      var most = 0;
      for (var j = 0; j < cands.length; j++) { most = Math.max(most, left[advKey(cands[j])]); }
      var top = cands.filter(function (c) { return left[advKey(c)] === most; });

      // Вес решает уже внутри группы самых многочисленных — так доля
      // ротации остаётся продаваемой, но порядок не ломается.
      var pick = pickWeighted(top, next);
      if (!pick) { break; }
      out.push(pick);
      lastAdv = advKey(pick);
      pool.splice(pool.indexOf(pick), 1);
    }
    return out;
  }

  // ==========================================================================
  //  Частотный лимит попапа
  // ==========================================================================
  //
  // seen: { "_v": 1, "<campaignId>": { last: ms, clicked: ms, hits: [ms, ...] } }
  // Все функции чистые и возвращают НОВЫЙ объект: вызывающий сам решает,
  // когда писать в localStorage (который в приватном Safari бросает).

  function popupCfg(campaign) {
    var raw = (campaign && campaign.popup) || {};
    var out = {};
    for (var k in POPUP_DEFAULTS) {
      if (!Object.prototype.hasOwnProperty.call(POPUP_DEFAULTS, k)) { continue; }
      var v = Math.round(Number(raw[k]));
      var lim = POPUP_LIMITS[k];
      out[k] = isFinite(v) ? Math.min(lim.max, Math.max(lim.min, v)) : POPUP_DEFAULTS[k];
    }
    return out;
  }

  function seenRec(seen, id) {
    var rec = seen && typeof seen === "object" ? seen[id] : null;
    if (!rec || typeof rec !== "object") { return { last: 0, clicked: 0, hits: [] }; }
    return {
      last: Number(rec.last) || 0,
      clicked: Number(rec.clicked) || 0,
      hits: Array.isArray(rec.hits) ? rec.hits.map(Number).filter(isFinite) : []
    };
  }

  function shouldShowPopup(campaign, seen, nowMs) {
    if (!campaign || !campaign.id) { return false; }
    var now = Number(nowMs);
    if (!isFinite(now)) { return false; }

    var cfg = popupCfg(campaign);
    var rec = seenRec(seen, campaign.id);

    if (rec.last && now - rec.last < cfg.capHours * 60 * 60 * 1000) { return false; }
    // Кто уже перешёл по объявлению, неделю его не видит: догонять
    // сконвертировавшегося пользователя — способ его разозлить.
    if (rec.clicked && now - rec.clicked < WEEK_MS) { return false; }

    var recent = 0;
    for (var i = 0; i < rec.hits.length; i++) {
      if (now - rec.hits[i] < WEEK_MS) { recent++; }
    }
    if (recent >= cfg.maxPerWeek) { return false; }

    return true;
  }

  function withRec(seen, id, rec) {
    var out = {};
    if (seen && typeof seen === "object") {
      for (var k in seen) {
        if (Object.prototype.hasOwnProperty.call(seen, k)) { out[k] = seen[k]; }
      }
    }
    out._v = 1;
    out[id] = rec;
    return out;
  }

  function recordPopupShown(seen, id, nowMs) {
    var now = Number(nowMs) || 0;
    var rec = seenRec(seen, id);
    // Подрезаем историю до недельного окна на каждой записи, иначе массив
    // растёт бесконечно в localStorage у постоянного посетителя.
    var hits = rec.hits.filter(function (t) { return now - t < WEEK_MS; });
    hits.push(now);
    return withRec(seen, id, { last: now, clicked: rec.clicked, hits: hits });
  }

  function recordPopupClicked(seen, id, nowMs) {
    var now = Number(nowMs) || 0;
    var rec = seenRec(seen, id);
    return withRec(seen, id, { last: rec.last, clicked: now, hits: rec.hits });
  }

  // ==========================================================================
  //  Нормализация документа
  // ==========================================================================

  function clampInt(v, min, max, dflt) {
    var n = Math.round(Number(v));
    if (!isFinite(n)) { return dflt; }
    return Math.min(max, Math.max(min, n));
  }

  function str(v) { return String(v == null ? "" : v).trim(); }

  function normalizeCreative(raw) {
    if (!raw || typeof raw !== "object") { return null; }
    var src = str(raw.src);
    if (!src) { return null; }
    var anim = raw.anim === true;
    return {
      src: src,
      w: clampInt(raw.w, 0, 20000, 0),
      h: clampInt(raw.h, 0, 20000, 0),
      anim: anim,
      // Постер обязателен для анимации: он нужен и для
      // prefers-reduced-motion, и для кнопки паузы, и для PNG-экспорта,
      // который иначе снял бы случайный кадр. Если его не прислали —
      // откатываемся на сам креатив, чтобы не показать пустоту.
      poster: str(raw.poster) || (anim ? src : "")
    };
  }

  // Никогда не бросает. На вход может прийти что угодно: битый JSON из
  // localStorage, ответ сервера с ошибкой, старый формат. Всё непонятное
  // отбрасывается молча — сайт должен пережить сломанную рекламу.
  function normalizeDoc(raw) {
    var doc = (raw && typeof raw === "object" && !Array.isArray(raw)) ? raw : {};
    var src = Array.isArray(doc.campaigns) ? doc.campaigns : [];
    var out = [], usedIds = {};

    for (var i = 0; i < src.length; i++) {
      var c = src[i];
      if (!c || typeof c !== "object") { continue; }
      var id = str(c.id);
      if (!ID_RE.test(id)) { continue; }
      if (Object.prototype.hasOwnProperty.call(usedIds, id)) { continue; }
      usedIds[id] = true;

      var slots = [];
      var rawSlots = Array.isArray(c.slots) ? c.slots : [];
      for (var s = 0; s < rawSlots.length; s++) {
        var sl = str(rawSlots[s]);
        if (SLOTS.indexOf(sl) >= 0 && slots.indexOf(sl) < 0) { slots.push(sl); }
      }

      var creatives = {};
      var rawCre = (c.creatives && typeof c.creatives === "object") ? c.creatives : {};
      for (var k = 0; k < SLOTS.length; k++) {
        var cre = normalizeCreative(rawCre[SLOTS[k]]);
        if (cre) { creatives[SLOTS[k]] = cre; }
      }

      out.push({
        id: id,
        name: str(c.name) || id,
        advertiser: str(c.advertiser),
        enabled: c.enabled !== false,
        weight: clampInt(c.weight, 1, 100, 1),
        start: DATE_RE.test(str(c.start)) ? str(c.start) : "",
        end: DATE_RE.test(str(c.end)) ? str(c.end) : "",
        href: safeHref(c.href),
        text: str(c.text),
        cta: str(c.cta),
        erid: ERID_RE.test(str(c.erid)) ? str(c.erid) : "",
        slots: slots,
        creatives: creatives,
        popup: popupCfg(c),
        notes: str(c.notes)
      });
    }

    return { v: 1, rev: clampInt(doc.rev, 0, Number.MAX_SAFE_INTEGER, 0), campaigns: out };
  }

  // ==========================================================================
  //  Импорт старого баннера
  // ==========================================================================

  // state.ad = { text, image, link } — единственный рекламный блок, который
  // был на сайте до этой системы. Функция даёт кнопку «Импортировать текущий
  // баннер» в админке. Односторонняя: сам state.ad не трогаем и не удаляем,
  // чтобы откат сводился к отключению promo.js.
  function migrateLegacyAd(stateAd) {
    var ad = stateAd && typeof stateAd === "object" ? stateAd : {};
    var text = str(ad.text), image = str(ad.image), link = str(ad.link);
    if (!text && !image && !link) { return null; }

    var camp = {
      id: "c_legacy",
      name: "Старый баннер",
      advertiser: "",
      enabled: true,
      weight: 1,
      start: "",
      end: "",
      href: link,
      text: text,
      cta: "",
      erid: "",
      slots: image ? ["strip"] : [],
      creatives: image ? { strip: { src: image, w: 0, h: 0, anim: false, poster: "" } } : {},
      notes: "импортирован из state.ad"
    };
    return normalizeDoc({ campaigns: [camp] }).campaigns[0] || null;
  }

  var api = {
    SLOTS: SLOTS,
    MAX_STRIP_SLIDES: MAX_STRIP_SLIDES,
    MSK_OFFSET_MS: MSK_OFFSET_MS,
    POPUP_DEFAULTS: POPUP_DEFAULTS,
    safeHref: safeHref,
    dayBoundsMsk: dayBoundsMsk,
    inWindow: inWindow,
    creativeFor: creativeFor,
    eligible: eligible,
    pickWeighted: pickWeighted,
    orderForCarousel: orderForCarousel,
    shouldShowPopup: shouldShowPopup,
    recordPopupShown: recordPopupShown,
    recordPopupClicked: recordPopupClicked,
    normalizeDoc: normalizeDoc,
    migrateLegacyAd: migrateLegacyAd
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.PROMO = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
