(function (root) {
  "use strict";

  var START  = "/api/roblox_start.php";
  var LOGOUT = "/api/logout.php";
  var SESSION = "/api/session.php";
  var INVITE_KEY = "nexus-signin-v1";

  var sessionPromise = null;

  function here() {
    var q = location.search.replace(/([?&])login=[^&]*(&|$)/, "$1").replace(/[?&]$/, "");
    return location.pathname + q + location.hash;
  }

  function startUrl(ret) {
    return START + "?return=" + encodeURIComponent(ret || here());
  }

  function logout(then) {
    return fetch(LOGOUT, { method: "POST", cache: "no-store" })
      .catch(function () {})
      .then(function () {
        if (then) { then(); } else { location.reload(); }
      });
  }

  function switchAccount(ret) {
    var url = startUrl(ret);
    return logout(function () { location.assign(url); });
  }

  function session() {
    if (!sessionPromise) {
      sessionPromise = fetch(SESSION, { cache: "no-store", credentials: "same-origin" })
        .then(function (r) { return r.ok ? r.json() : null; })
        .catch(function () { return null; });
    }
    return sessionPromise;
  }

  function canLogin(s) {
    if (!s || !s.roblox) { return false; }
    if (s.roblox_public) { return true; }
    try { return localStorage.getItem(INVITE_KEY) === "1"; } catch (_) { return false; }
  }

  root.MKAuth = {
    here: here,
    startUrl: startUrl,
    logout: logout,
    switchAccount: switchAccount,
    session: session,
    canLogin: canLogin
  };
})(window);
