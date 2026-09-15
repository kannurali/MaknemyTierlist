
(function (root) {
  "use strict";

  var SLOTS = ["strip", "rail", "dock", "popup"];

  var PAGES = ["tierlist", "news", "calc"];

  var MAX_STRIP_SLIDES = 8;

  var MSK_OFFSET_MS = 3 * 60 * 60 * 1000;

  var DAY_MS = 24 * 60 * 60 * 1000;
  var WEEK_MS = 7 * DAY_MS;

  var POPUP_DEFAULTS = { delayMs: 12000, capHours: 24, maxPerWeek: 3 };
  var POPUP_LIMITS = {
    delayMs:    { min: 5000, max: 60000 },
    capHours:   { min: 1,    max: 720 },
    maxPerWeek: { min: 1,    max: 50 }
  };

  var ID_RE = /^[A-Za-z0-9_-]{1,64}$/;

  var ERID_RE = /^[A-Za-z0-9_-]{1,64}$/;
  var DATE_RE = /^(\d{4})-(\d{2})-(\d{2})$/;
  var SAFE_SCHEME = /^(https?:|mailto:|tel:)/i;

  function safeHref(href) {
    var s = String(href == null ? "" : href).trim();

    if (s === "" || /^https?:\/*$/i.test(s)) { return ""; }

    if (/[\s<>"]/.test(s)) { return ""; }

    if (/^[a-z][a-z0-9+.-]*:/i.test(s)) { return SAFE_SCHEME.test(s) ? s : ""; }

    if (s.indexOf("//") === 0) { return "https:" + s; }
    if (s.charAt(0) === "/" || s.charAt(0) === "#") { return s; }
    return "https://" + s.replace(/^\/+/, "");
  }

  function dayBoundsMsk(dateStr) {
    var m = DATE_RE.exec(String(dateStr == null ? "" : dateStr).trim());
    if (!m) { return null; }
    var y = Number(m[1]), mo = Number(m[2]), d = Number(m[3]);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) { return null; }
    var utcMidnight = Date.UTC(y, mo - 1, d);
    var probe = new Date(utcMidnight);

    if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== mo - 1 || probe.getUTCDate() !== d) {
      return null;
    }
    var startMs = utcMidnight - MSK_OFFSET_MS;
    return { startMs: startMs, endMs: startMs + DAY_MS - 1 };
  }

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

  function onPage(campaign, page) {
    if (!campaign) { return false; }
    var list = campaign.pages;
    if (!Array.isArray(list) || !list.length) { return true; }
    return !!page && list.indexOf(String(page)) >= 0;
  }

  function eligible(doc, slot, nowMs, page) {
    var d = normalizeDoc(doc);
    if (SLOTS.indexOf(slot) < 0) { return []; }
    var out = [];
    for (var i = 0; i < d.campaigns.length; i++) {
      var c = d.campaigns[i];
      if (!c.enabled) { continue; }
      if (c.slots.indexOf(slot) < 0) { continue; }
      if (!onPage(c, page)) { continue; }
      if (!creativeFor(c, slot)) { continue; }
      if (!inWindow(c, nowMs)) { continue; }
      out.push(c);
    }
    return out;
  }

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

  function pickWeighted(campaigns, rnd) {
    var list = Array.isArray(campaigns) ? campaigns.filter(Boolean) : [];
    if (!list.length) { return null; }
    var next = toRndFn(rnd);

    var total = 0, i;
    for (i = 0; i < list.length; i++) { total += Math.max(0, Number(list[i].weight) || 0); }

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

  function orderForCarousel(campaigns, rnd, max) {
    var pool = Array.isArray(campaigns) ? campaigns.filter(Boolean).slice() : [];
    var cap = Number(max);
    if (!isFinite(cap) || cap < 1) { cap = MAX_STRIP_SLIDES; }
    cap = Math.min(cap, MAX_STRIP_SLIDES);

    var next = toRndFn(rnd);
    var out = [], lastAdv = null;

    while (pool.length && out.length < cap) {
      var left = {};
      for (var i = 0; i < pool.length; i++) {
        var k = advKey(pool[i]);
        left[k] = (left[k] || 0) + 1;
      }

      var cands = pool.filter(function (c) { return !lastAdv || advKey(c) !== lastAdv; });
      if (!cands.length) { cands = pool.slice(); }

      var most = 0;
      for (var j = 0; j < cands.length; j++) { most = Math.max(most, left[advKey(cands[j])]); }
      var top = cands.filter(function (c) { return left[advKey(c)] === most; });

      var pick = pickWeighted(top, next);
      if (!pick) { break; }
      out.push(pick);
      lastAdv = advKey(pick);
      pool.splice(pool.indexOf(pick), 1);
    }
    return out;
  }

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

    var hits = rec.hits.filter(function (t) { return now - t < WEEK_MS; });
    hits.push(now);
    return withRec(seen, id, { last: now, clicked: rec.clicked, hits: hits });
  }

  function recordPopupClicked(seen, id, nowMs) {
    var now = Number(nowMs) || 0;
    var rec = seenRec(seen, id);
    return withRec(seen, id, { last: rec.last, clicked: now, hits: rec.hits });
  }

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

      poster: str(raw.poster) || (anim ? src : "")
    };
  }

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

      var pages = [];
      var rawPages = Array.isArray(c.pages) ? c.pages : [];
      for (var p = 0; p < rawPages.length; p++) {
        var pg = str(rawPages[p]);
        if (PAGES.indexOf(pg) >= 0 && pages.indexOf(pg) < 0) { pages.push(pg); }
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
        pages: pages,
        slots: slots,
        creatives: creatives,
        popup: popupCfg(c),
        notes: str(c.notes)
      });
    }

    return { v: 1, rev: clampInt(doc.rev, 0, Number.MAX_SAFE_INTEGER, 0), campaigns: out };
  }

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

  var HOUSE_TG = {
    id: "house-tg",
    name: "house-tg",
    advertiser: "",
    enabled: true,
    weight: 1,
    start: "",
    end: "",
    href: "https://t.me/theMaknemy",

    text: "",
    cta: "",
    textKey: "promo.houseTgText",
    ctaKey: "promo.houseTgCta",

    erid: "",
    slots: ["popup"],
    creatives: {
      popup: { src: "/assets/promo/house-tg-popup.webp", w: 800, h: 800, anim: false, poster: "" }
    },
    popup: { delayMs: 12000, capHours: 24, maxPerWeek: 7 },
    notes: ""
  };

  var HOUSE_SLOT = {
    id: "house",
    name: "house",
    advertiser: "",
    enabled: true,
    weight: 1,
    start: "",
    end: "",
    href: "https://t.me/mksvtnc",
    text: "",
    cta: "",
    erid: "",
    slots: ["strip", "rail", "dock"],
    creatives: {
      strip: { src: "/assets/promo/placeholder-strip.webp", w: 1200, h: 300, anim: false, poster: "" },
      rail:  { src: "/assets/promo/placeholder-rail.webp",  w: 320,  h: 1200, anim: false, poster: "" },
      dock:  { src: "/assets/promo/placeholder-dock.webp",  w: 640,  h: 200,  anim: false, poster: "" }
    },
    popup: { delayMs: 12000, capHours: 24, maxPerWeek: 3 },
    notes: ""
  };

  var HOUSE_GIVEAWAY = {
    id: "house-giveaway-magnet-chromatic",
    name: "house-giveaway-chromatic",
    advertiser: "",
    enabled: true,
    weight: 1,
    start: "",
    end: "",
    href: "https://t.me/theMaknemy/5302",
    text: "",
    cta: "",
    textKey: "promo.giveawayText",
    ctaKey: "promo.giveawayCta",

    erid: "",
    slots: ["strip", "rail", "dock", "popup"],
    creatives: {
      strip: { src: "/assets/promo/giveaway-strip.webp", w: 1200, h: 300,  anim: false, poster: "" },
      rail:  { src: "/assets/promo/giveaway-rail.webp",  w: 320,  h: 1200, anim: false, poster: "" },
      dock:  { src: "/assets/promo/giveaway-dock.webp",  w: 640,  h: 200,  anim: false, poster: "" },
      popup: { src: "/assets/promo/giveaway-popup.webp", w: 800,  h: 800,  anim: false, poster: "" }
    },

    popup: { delayMs: 12000, capHours: 24, maxPerWeek: 7 },
    notes: ""
  };

  var PLAYEROK = {
    id: "playerok-2026-09",
    name: "playerok-2026-09",
    advertiser: "Playerok",
    enabled: true,
    weight: 1,
    start: "",
    end: "",
    href: "https://plrk.co/p/Maknemy0509",
    text: "",
    cta: "",
    textKey: "promo.playerokText",
    ctaKey: "promo.playerokCta",
    erid: "",
    pages: ["tierlist"],
    slots: ["strip", "rail", "dock", "popup"],
    creatives: {
      strip: { src: "/assets/promo/playerok-strip.webp", w: 1200, h: 300,  anim: false, poster: "" },
      rail:  { src: "/assets/promo/playerok-rail.webp",  w: 320,  h: 1200, anim: false, poster: "" },
      dock:  { src: "/assets/promo/playerok-dock.webp",  w: 640,  h: 200,  anim: false, poster: "" },
      popup: { src: "/assets/promo/playerok-popup.webp", w: 800,  h: 800,  anim: false, poster: "" }
    },
    popup: { delayMs: 12000, capHours: 24, maxPerWeek: 3 },
    notes: ""
  };

  function bookedFor(campaign, slot, now, page) {
    return campaign.enabled
      && onPage(campaign, page)
      && inWindow(campaign, now)
      && !!creativeFor(campaign, slot);
  }

  function houseFor(slot, nowMs, page) {
    var now = isFinite(Number(nowMs)) ? Number(nowMs) : Date.now();
    if (bookedFor(PLAYEROK, slot, now, page)) { return PLAYEROK; }
    if (bookedFor(HOUSE_GIVEAWAY, slot, now, page)) { return HOUSE_GIVEAWAY; }
    if (slot === "popup") { return HOUSE_TG; }
    return creativeFor(HOUSE_SLOT, slot) ? HOUSE_SLOT : null;
  }

  function popupPick(doc, seen, nowMs, rnd, page) {
    var paid = eligible(doc, "popup", nowMs, page).filter(function (c) {
      return shouldShowPopup(c, seen, nowMs);
    });
    if (paid.length) { return pickWeighted(paid, rnd); }
    var house = houseFor("popup", nowMs, page);
    return (house && shouldShowPopup(house, seen, nowMs)) ? house : null;
  }

  var api = {
    SLOTS: SLOTS,
    PAGES: PAGES,
    MAX_STRIP_SLIDES: MAX_STRIP_SLIDES,
    MSK_OFFSET_MS: MSK_OFFSET_MS,
    POPUP_DEFAULTS: POPUP_DEFAULTS,
    safeHref: safeHref,
    dayBoundsMsk: dayBoundsMsk,
    inWindow: inWindow,
    creativeFor: creativeFor,
    onPage: onPage,
    eligible: eligible,
    pickWeighted: pickWeighted,
    orderForCarousel: orderForCarousel,
    shouldShowPopup: shouldShowPopup,
    HOUSE_TG: HOUSE_TG,
    HOUSE_SLOT: HOUSE_SLOT,
    HOUSE_GIVEAWAY: HOUSE_GIVEAWAY,
    PLAYEROK: PLAYEROK,
    houseFor: houseFor,
    popupPick: popupPick,
    recordPopupShown: recordPopupShown,
    recordPopupClicked: recordPopupClicked,
    normalizeDoc: normalizeDoc,
    migrateLegacyAd: migrateLegacyAd
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.PROMO = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
