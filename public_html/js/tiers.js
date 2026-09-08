
(function (root) {
  "use strict";

  var TIER_LOGOS = {
    MK: "assets/logo-mk.png",
    GLH: "assets/logo-glh.png",
    "💧": "assets/logo-bolt.png",
  };

  var LEGACY_LOGOS = {
    "assets/logo-flame.png": TIER_LOGOS["💧"],
  };

  function normalizeTierLogos(tiers, restoreEmpty) {
    if (!Array.isArray(tiers)) { return tiers; }
    tiers.forEach(function (tier) {
      if (!tier) { return; }
      if (LEGACY_LOGOS[tier.logo]) { tier.logo = LEGACY_LOGOS[tier.logo]; }
      var missing = restoreEmpty ? !tier.logo : tier.logo === undefined;
      if (missing && TIER_LOGOS[tier.label]) { tier.logo = TIER_LOGOS[tier.label]; }
    });
    return tiers;
  }

  var api = {
    TIER_LOGOS: TIER_LOGOS,
    LEGACY_LOGOS: LEGACY_LOGOS,
    normalizeTierLogos: normalizeTierLogos,
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.TIERS = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
