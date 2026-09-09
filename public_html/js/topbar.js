
(function () {
  "use strict";

  var head = document.querySelector(".mk-top");

  var LANG_KEY = "nexus-lang-v1";
  var FALLBACK = {
    "topbar.soon": "В активной разработке",
    "topbar.showNav": "Показать разделы",
    "topbar.hideNav": "Скрыть разделы",

    "user.login": "Войти через Roblox",
    "user.menu": "Меню профиля",
    "user.mine": "Мой профиль",
    "user.profile": "Профиль в Roblox",
    "user.logout": "Выйти",
    "user.cancelled": "Вход отменён",
    "user.expired": "Вход занял слишком много времени — попробуйте ещё раз",
    "user.error": "Не удалось войти — попробуйте ещё раз"
  };

  function tx(key) {
    var i18n = window.I18N;
    if (!i18n) return FALLBACK[key];
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  var STICK_AT = 4;

  var WIDE = window.matchMedia("(min-width: 761px)");

  var RECLOSE_AFTER = 60;

  if (head) {
    var toggle = head.querySelector(".mk-top-toggle");
    var stuck = false;
    var open = false;
    var openedAt = 0;
    var pending = false;

    function scrollY() {
      return window.scrollY || window.pageYOffset || 0;
    }

    function syncToggle() {
      if (!toggle) return;
      var key = open ? "topbar.hideNav" : "topbar.showNav";
      var label = tx(key);
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.setAttribute("aria-label", label);
      toggle.setAttribute("title", label);

      toggle.setAttribute("data-i18n-label", key);
      toggle.setAttribute("data-i18n-title", key);
    }

    function setOpen(next) {
      open = next;
      openedAt = scrollY();
      head.classList.toggle("is-nav-open", open);
      syncToggle();
    }

    var sync = function () {
      pending = false;
      var y = scrollY();

      var next = WIDE.matches && y > STICK_AT;

      if (next !== stuck) {
        stuck = next;
        head.classList.toggle("is-stuck", stuck);

        if (!stuck && open) setOpen(false);
        if (stuck) openedAt = y;
      }

      if (stuck && open && y - openedAt > RECLOSE_AFTER) setOpen(false);
    };

    if (toggle) {
      syncToggle();
      toggle.addEventListener("click", function () { setOpen(!open); });
    }

    window.addEventListener("scroll", function () {
      if (pending) return;
      pending = true;
      window.requestAnimationFrame(sync);
    }, { passive: true });

    if (WIDE.addEventListener) WIDE.addEventListener("change", sync);
    else if (WIDE.addListener) WIDE.addListener(sync);

    sync();
  }

  var toast = null;
  var hideTimer = 0;

  function showToast(text) {
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "mk-soon";

      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.appendChild(toast);
    }
    toast.textContent = text;

    clearTimeout(hideTimer);

    window.requestAnimationFrame(function () { toast.classList.add("is-on"); });
    hideTimer = setTimeout(function () { toast.classList.remove("is-on"); }, 2200);
  }

  function showSoon() { showToast(tx("topbar.soon")); }

  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-soon]") : null;
    if (!el) return;
    e.preventDefault();
    showSoon();
  });

  var PROFILE_PATH = "/profile";

  var AUTH_STATE = "/api/session.php";

  var auth = window.MKAuth;

  function takeLoginFlag() {
    var m = /[?&]login=([a-z]+)/.exec(location.search);
    if (!m) return "";
    if (window.history && history.replaceState) {
      try { history.replaceState(null, "", auth.here()); } catch (_) {}
    }
    return m[1];
  }

  function reportLogin(flag) {
    if (flag === "cancelled") showToast(tx("user.cancelled"));
    else if (flag === "expired") showToast(tx("user.expired"));
    else if (flag === "error") showToast(tx("user.error"));
  }

  function toLoginLink(btn) {
    var a = document.createElement("a");
    a.className = btn.className;
    a.href = auth.startUrl();
    a.innerHTML = btn.innerHTML;
    var label = tx("user.login");
    a.setAttribute("aria-label", label);
    a.setAttribute("title", label);

    a.setAttribute("data-i18n-label", "user.login");
    a.setAttribute("data-i18n-title", "user.login");
    btn.parentNode.replaceChild(a, btn);
    return a;
  }

  function buildMenu(user) {
    var menu = document.createElement("div");
    menu.className = "mk-user-menu";
    menu.setAttribute("role", "menu");
    menu.hidden = true;

    var name = document.createElement("div");
    name.className = "mk-user-name";

    name.textContent = user.display || user.name || "";
    var nick = document.createElement("span");
    nick.textContent = user.name ? "@" + user.name : "";
    name.appendChild(nick);

    var mine = document.createElement("a");
    mine.className = "mk-user-item";
    mine.setAttribute("role", "menuitem");
    mine.href = PROFILE_PATH;
    mine.textContent = tx("user.mine");
    mine.setAttribute("data-i18n", "user.mine");
    var onMine = location.pathname === PROFILE_PATH && !/[?&]id=/.test(location.search);
    if (onMine) mine.setAttribute("aria-current", "page");

    var prof = document.createElement("a");
    prof.className = "mk-user-item";
    prof.setAttribute("role", "menuitem");
    prof.href = user.profile;
    prof.target = "_blank";

    prof.rel = "noopener noreferrer";
    prof.textContent = tx("user.profile");
    prof.setAttribute("data-i18n", "user.profile");

    var out = document.createElement("button");
    out.className = "mk-user-item mk-user-out";
    out.type = "button";
    out.setAttribute("role", "menuitem");
    out.textContent = tx("user.logout");
    out.setAttribute("data-i18n", "user.logout");
    out.addEventListener("click", function () {
      out.disabled = true;

      auth.logout();
    });

    menu.appendChild(name);
    menu.appendChild(mine);
    menu.appendChild(prof);
    menu.appendChild(out);
    document.body.appendChild(menu);
    return menu;
  }

  function initUserMenu(btn, user) {
    btn.removeAttribute("data-soon");
    var label = tx("user.menu");
    btn.setAttribute("aria-label", label);
    btn.setAttribute("title", label);
    btn.setAttribute("data-i18n-label", "user.menu");
    btn.setAttribute("data-i18n-title", "user.menu");
    btn.setAttribute("aria-haspopup", "menu");
    btn.setAttribute("aria-expanded", "false");

    if (user.avatar) {
      var img = document.createElement("img");
      img.className = "mk-avatar-img";
      img.src = user.avatar;
      img.alt = "";
      img.setAttribute("aria-hidden", "true");

      img.referrerPolicy = "no-referrer";
      btn.textContent = "";
      btn.appendChild(img);
    }

    var menu = buildMenu(user);
    var menuOpen = false;

    function place() {
      var r = btn.getBoundingClientRect();
      menu.style.top = (r.bottom + 8) + "px";
      menu.style.right = Math.max(8, window.innerWidth - r.right) + "px";
    }

    function setMenuOpen(next) {
      menuOpen = next;
      menu.hidden = !menuOpen;
      btn.setAttribute("aria-expanded", menuOpen ? "true" : "false");
      if (menuOpen) place();
    }

    btn.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      setMenuOpen(!menuOpen);
    });

    document.addEventListener("click", function (e) {
      if (menuOpen && !menu.contains(e.target)) setMenuOpen(false);
    });
    document.addEventListener("keydown", function (e) {
      if (menuOpen && e.key === "Escape") { setMenuOpen(false); btn.focus(); }
    });

    window.addEventListener("scroll", function () { if (menuOpen) setMenuOpen(false); }, { passive: true });
    window.addEventListener("resize", function () { if (menuOpen) setMenuOpen(false); });
  }

  var avatarBtn = head ? head.querySelector(".mk-avatar") : null;
  if (avatarBtn && window.fetch && auth) {
    reportLogin(takeLoginFlag());
    fetch(AUTH_STATE, { cache: "no-store" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s || !s.roblox) return;
        if (s.user) initUserMenu(avatarBtn, s.user);
        else toLoginLink(avatarBtn);
      })
      .catch(function () {});
  }
})();
