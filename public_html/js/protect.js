
(function (root) {
  "use strict";

  function inEditable(target) {
    return !!(target && target.closest &&
      target.closest('input, textarea, [contenteditable="true"]'));
  }

  function install(isAdminFn, doc) {
    var d = doc || (typeof document !== "undefined" ? document : null);
    if (!d || d.nxProtected) { return; }
    d.nxProtected = true;

    d.addEventListener("contextmenu", function (e) {
      if (isAdminFn() || inEditable(e.target)) { return; }
      e.preventDefault();
    });

    ["copy", "cut"].forEach(function (type) {
      d.addEventListener(type, function (e) {
        if (isAdminFn() || inEditable(e.target)) { return; }
        e.preventDefault();
      });
    });

    d.addEventListener("dragstart", function (e) {
      if (isAdminFn()) { return; }
      e.preventDefault();
    });

    d.addEventListener("selectstart", function (e) {
      if (isAdminFn() || inEditable(e.target)) { return; }
      e.preventDefault();
    });
  }

  function applyClass(isAdmin, doc) {
    var d = doc || (typeof document !== "undefined" ? document : null);
    if (!d || !d.body) { return; }
    d.body.classList.toggle("protected", !isAdmin);
  }

  function auto() {
    if (typeof document === "undefined" || !root.location) { return; }
    if (/^\/admin(\/|$)/.test(root.location.pathname)) { return; }
    var isAdmin = function () { return !!root.NX_ADMIN_PAGE; };
    install(isAdmin);
    var mark = function () { applyClass(isAdmin()); };
    if (document.body) { mark(); } else { document.addEventListener("DOMContentLoaded", mark); }
  }

  var api = { inEditable: inEditable, install: install, applyClass: applyClass };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NX_PROTECT = api;
  auto();
})(typeof globalThis !== "undefined" ? globalThis : this);
