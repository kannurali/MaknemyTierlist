
(function (root) {
  "use strict";

  var VALUE_RE = /^\d+(\.\d+)?$/;

  function parseValue(raw) {
    if (typeof raw === "number") {
      return Number.isFinite(raw) && raw >= 0 ? raw : null;
    }
    if (typeof raw !== "string") { return null; }
    var s = raw.trim();
    if (s === "" || !VALUE_RE.test(s)) { return null; }
    var n = Number(s);
    return Number.isFinite(n) ? n : null;
  }

  function itemValue(item) {
    var v = item ? parseValue(item.value) : null;
    return v === null ? 0 : v;
  }

  function flattenTierlist(data) {
    var items = [];
    if (!data || !Array.isArray(data.tiers)) { return items; }
    for (var i = 0; i < data.tiers.length; i++) {
      var tier = data.tiers[i];
      if (!tier || !Array.isArray(tier.items)) { continue; }
      for (var j = 0; j < tier.items.length; j++) {
        var it = tier.items[j];
        if (it && typeof it === "object" && it.id) { items.push(it); }
      }
    }
    return items;
  }

  function buildCatalogIndex(items) {
    var idx = Object.create(null);
    (items || []).forEach(function (it) { if (it && it.id) { idx[it.id] = it; } });
    return idx;
  }

  var MAX_SLOTS = 4;

  function canAddToSide(entries, item, maxSlots) {
    var max = typeof maxSlots === "number" ? maxSlots : MAX_SLOTS;
    return (entries || []).length < max;
  }

  function addToSide(entries, item, count, maxSlots) {
    var max = typeof maxSlots === "number" ? maxSlots : MAX_SLOTS;
    var next = (entries || []).slice();
    if (!item) { return next; }
    var add = count > 0 ? Math.round(count) : 1;
    for (var i = 0; i < add && next.length < max; i++) {
      next.push({ item: item, count: 1 });
    }
    return next;
  }

  function removeOneFromSide(entries, id) {
    var next = [];
    var removed = false;
    (entries || []).forEach(function (e) {
      if (!removed && e.item && e.item.id === id) { removed = true; return; }
      next.push(e);
    });
    return next;
  }

  function clearSide() { return []; }

  function sideTotal(entries) {
    return (entries || []).reduce(function (sum, e) {
      return sum + itemValue(e.item);
    }, 0);
  }

  var BADGE_CODES = ["fr", "cs", "cm", "ms", "pm", "gp", "cr", "vh"];
  var RAW_TYPE_TO_BADGE = {
    "": "fr", "f": "fr",
    "p": "pm",
    "s": "cs",
    "m": "cm",
    "gp": "gp",
    "cr": "cr",
    "v": "vh"
  };

  function badgeCodeFor(type) {
    var t = typeof type === "string" ? type.trim().toLowerCase() : "";
    if (BADGE_CODES.indexOf(t) >= 0) { return t; }
    return Object.prototype.hasOwnProperty.call(RAW_TYPE_TO_BADGE, t) ? RAW_TYPE_TO_BADGE[t] : "fr";
  }

  var DEMAND_WEIGHT = { neon: 12, green: 10, yellow: 8, orange: 5, red: 2 };

  function demandBalance(entries) {
    var counted = 0;
    var weighted = 0;
    (entries || []).forEach(function (e) {
      var demand = e.item && e.item.demand;
      if (!Object.prototype.hasOwnProperty.call(DEMAND_WEIGHT, demand)) { return; }
      counted += 1;
      weighted += DEMAND_WEIGHT[demand];
    });
    if (counted === 0) { return null; }
    return weighted / counted;
  }

  function demandBucket(balance) {
    if (typeof balance !== "number" || !isFinite(balance)) { return null; }
    if (balance > 10) { return "neon"; }
    if (balance > 8) { return "green"; }
    if (balance > 5) { return "yellow"; }
    if (balance > 2) { return "orange"; }
    return "red";
  }

  var THRESHOLD_PCT = 5;

  var DEMAND_NOTE_THRESHOLD = 3;

  function computeTrade(leftEntries, rightEntries, thresholdPct) {
    var threshold = typeof thresholdPct === "number" ? thresholdPct : THRESHOLD_PCT;
    var leftTotal = sideTotal(leftEntries);
    var rightTotal = sideTotal(rightEntries);
    var diffAbs = rightTotal - leftTotal;

    var basis = leftTotal !== 0 ? leftTotal : rightTotal;
    var diffPct = basis !== 0 ? (diffAbs / basis) * 100 : 0;

    var verdict;
    if (Math.abs(diffPct) <= threshold) { verdict = "fair"; }
    else { verdict = diffPct > 0 ? "win" : "lose"; }

    var demandNote = null;
    if (verdict === "fair") {
      var leftBalance = demandBalance(leftEntries);
      var rightBalance = demandBalance(rightEntries);
      if (leftBalance !== null && rightBalance !== null) {
        var balanceDiff = leftBalance - rightBalance;
        if (balanceDiff >= DEMAND_NOTE_THRESHOLD) { demandNote = "receiveLow"; }
        else if (balanceDiff <= -DEMAND_NOTE_THRESHOLD) { demandNote = "giveLow"; }
      }
    }

    return {
      leftTotal: leftTotal,
      rightTotal: rightTotal,
      diffAbs: diffAbs,
      diffPct: diffPct,
      verdict: verdict,
      demandNote: demandNote
    };
  }

  var ID_TOKEN_RE = /^([A-Za-z0-9_-]{1,64}):([1-9]\d{0,2})$/;

  var MAX_RAW_LEN = 4000;
  var MAX_TOKENS = 200;
  var MAX_COUNT = 999;

  function encodeSide(entries) {
    return (entries || [])
      .filter(function (e) { return e && e.item && e.item.id; })
      .slice(0, MAX_SLOTS)
      .map(function (e) { return String(e.item.id) + ":1"; })
      .join(",");
  }

  function decodeSide(raw, catalogIndex) {
    try {
      if (typeof raw !== "string" || raw === "") { return []; }
      if (raw.length > MAX_RAW_LEN) { return []; }
      var tokens = raw.split(",").slice(0, MAX_TOKENS);
      var slots = [];
      for (var i = 0; i < tokens.length && slots.length < MAX_SLOTS; i++) {
        var m = ID_TOKEN_RE.exec(tokens[i]);
        if (!m) { continue; }
        var id = m[1];
        if (!catalogIndex || !Object.prototype.hasOwnProperty.call(catalogIndex, id)) { continue; }
        var n = parseInt(m[2], 10);
        if (!Number.isFinite(n) || n < 1) { continue; }
        n = Math.min(MAX_COUNT, n);
        for (var k = 0; k < n && slots.length < MAX_SLOTS; k++) {
          slots.push({ item: catalogIndex[id], count: 1 });
        }
      }
      return slots;
    } catch (_e) {
      return [];
    }
  }

  function encodeShareQuery(leftEntries, rightEntries) {
    var params = [];
    var l = encodeSide(leftEntries);
    var r = encodeSide(rightEntries);
    if (l) { params.push("l=" + encodeURIComponent(l)); }
    if (r) { params.push("r=" + encodeURIComponent(r)); }
    return params.join("&");
  }

  function decodeShareQuery(searchParamsLike, catalogIndex) {
    try {
      var l = searchParamsLike && typeof searchParamsLike.get === "function" ? searchParamsLike.get("l") : null;
      var r = searchParamsLike && typeof searchParamsLike.get === "function" ? searchParamsLike.get("r") : null;
      return {
        left: decodeSide(l || "", catalogIndex),
        right: decodeSide(r || "", catalogIndex)
      };
    } catch (_e) {
      return { left: [], right: [] };
    }
  }

  var api = {
    THRESHOLD_PCT: THRESHOLD_PCT,
    DEMAND_WEIGHT: DEMAND_WEIGHT,
    DEMAND_NOTE_THRESHOLD: DEMAND_NOTE_THRESHOLD,
    MAX_SLOTS: MAX_SLOTS,
    BADGE_CODES: BADGE_CODES,
    badgeCodeFor: badgeCodeFor,
    parseValue: parseValue,
    itemValue: itemValue,
    flattenTierlist: flattenTierlist,
    buildCatalogIndex: buildCatalogIndex,
    canAddToSide: canAddToSide,
    addToSide: addToSide,
    removeOneFromSide: removeOneFromSide,
    clearSide: clearSide,
    sideTotal: sideTotal,
    demandBalance: demandBalance,
    demandBucket: demandBucket,
    computeTrade: computeTrade,
    encodeSide: encodeSide,
    decodeSide: decodeSide,
    encodeShareQuery: encodeShareQuery,
    decodeShareQuery: decodeShareQuery
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.CALC = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
