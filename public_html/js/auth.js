(function (root) {
  "use strict";

  var START  = "/api/roblox_start.php";
  var LOGOUT = "/api/logout.php";

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

  root.MKAuth = {
    here: here,
    startUrl: startUrl,
    logout: logout,
    switchAccount: switchAccount
  };
})(window);
