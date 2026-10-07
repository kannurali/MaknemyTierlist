
(function () {
  "use strict";

  var head = document.querySelector(".mk-top");

  var LANG_KEY = "nexus-lang-v1";
  var FALLBACK = {
    "topbar.soon": "В активной разработке",
    "topbar.showNav": "Показать разделы",
    "topbar.hideNav": "Скрыть разделы",

    "lang.switch": "Язык интерфейса",
    "user.login": "Войти через Roblox",
    "user.menu": "Меню профиля",
    "user.mine": "Мой профиль",
    "user.profile": "Профиль в Roblox",
    "user.logout": "Выйти",
    "nick.owner": "Владелец",
    "nick.developer": "Разработчик",
    "nick.designer": "Дизайнер",
    "user.admin": "Админка",
    "user.support": "Обращения",
    "user.cancelled": "Вход отменён",
    "user.expired": "Вход занял слишком много времени — попробуйте ещё раз",
    "user.error": "Не удалось войти — попробуйте ещё раз",
    "user.banned": "Этот аккаунт заблокирован на сайте",
    "user.bannedUntil": "Этот аккаунт заблокирован на сайте до {date}"
  };

  function uiLang() {
    var i18n = window.I18N;
    if (!i18n) return "ru";
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    return i18n.pickLang(stored, navigator.language);
  }

  function tx(key) {
    var i18n = window.I18N;
    if (!i18n) return FALLBACK[key];
    return i18n.t(key, uiLang());
  }

  var STICK_AT = 48;
  var UNSTICK_AT = 4;

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

      var next = WIDE.matches && y > (stuck ? UNSTICK_AT : STICK_AT);

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

  function showToast(text, ms) {
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
    hideTimer = setTimeout(function () { toast.classList.remove("is-on"); }, ms || 2200);
  }

  function showSoon() { showToast(tx("topbar.soon")); }

  document.addEventListener("click", function (e) {
    var el = e.target.closest ? e.target.closest("[data-soon]") : null;
    if (!el) return;
    e.preventDefault();
    showSoon();
  });

  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var el = e.target.closest ? e.target.closest("a.mk-pill, a.mk-chat, a.mk-avatar") : null;
    if (el) el.classList.add("is-pressed");
  });

  window.addEventListener("pageshow", function (e) {
    if (!e.persisted) return;
    var on = document.querySelectorAll(".is-pressed");
    for (var i = 0; i < on.length; i++) on[i].classList.remove("is-pressed");
  });

  var PROFILE_PATH = "/profile";

  var AUTH_STATE = "/api/session.php";

  var auth = window.MKAuth;

  function takeLoginFlag() {
    var m = /[?&]login=([a-z]+(?:-[0-9]+)?)/.exec(location.search);
    if (!m) return "";
    if (window.history && history.replaceState) {
      try { history.replaceState(null, "", auth.here()); } catch (_) {}
    }
    return m[1];
  }

  function banDate(sec) {
    var d = new Date(sec * 1000);
    var opts = { day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" };
    if (d.getFullYear() !== new Date().getFullYear()) opts.year = "numeric";
    try { return d.toLocaleString(uiLang() === "en" ? "en-GB" : "ru-RU", opts); }
    catch (_) { return d.toLocaleString(); }
  }

  function reportLogin(flag) {
    var ban = /^banned(?:-([0-9]+))?$/.exec(flag);
    if (flag === "cancelled") showToast(tx("user.cancelled"));
    else if (flag === "expired") showToast(tx("user.expired"));
    else if (flag === "error") showToast(tx("user.error"));
    else if (ban && ban[1]) showToast(tx("user.bannedUntil").replace("{date}", banDate(+ban[1])), 6000);
    else if (ban) showToast(tx("user.banned"), 6000);
  }

  var INVITE_KEY = "nexus-signin-v1";
  var INVITE_RE = /([?&])signin(=[^&]*)?(&|$)/;

  function takeInvite() {
    if (!INVITE_RE.test(location.search)) {
      try { return localStorage.getItem(INVITE_KEY) === "1"; } catch (_) { return false; }
    }
    try { localStorage.setItem(INVITE_KEY, "1"); } catch (_) {}
    if (window.history && history.replaceState) {
      var q = location.search.replace(INVITE_RE, "$1").replace(/[?&]$/, "");
      try { history.replaceState(null, "", location.pathname + q + location.hash); } catch (_) {}
    }
    return true;
  }

  function addLoginItem(menu) {
    var a = document.createElement("a");
    a.className = "mk-user-item mk-user-login";
    a.setAttribute("role", "menuitem");
    a.href = auth.startUrl();
    a.textContent = tx("user.login");
    a.setAttribute("data-i18n", "user.login");
    menu.insertBefore(a, menu.firstChild);
  }

  var PANEL = {
    admin: { href: "/admin", key: "user.admin" },
    support: { href: "/admin/support", key: "user.support" }
  };

  var langBox = document.getElementById("langSwitch");

  function buildMenu() {
    var menu = document.createElement("div");
    menu.className = "mk-user-menu";
    menu.setAttribute("role", "menu");
    menu.hidden = true;

    if (langBox) {
      var lang = document.createElement("div");
      lang.className = "mk-user-lang";
      var label = document.createElement("span");
      label.className = "mk-user-lang-label";
      label.textContent = tx("lang.switch");
      label.setAttribute("data-i18n", "lang.switch");
      lang.appendChild(label);
      lang.appendChild(langBox);
      menu.appendChild(lang);
    }

    document.body.appendChild(menu);
    return menu;
  }

  function fillUserMenu(menu, user, panel) {
    var name = document.createElement("div");
    name.className = "mk-user-name";

    name.textContent = user.display || user.name || "";
    if (user.logo) {
      var key = "nick." + user.logo.role;
      var badge = document.createElement("span");
      badge.className = "nx-nick-badge";
      badge.setAttribute("role", "img");
      badge.setAttribute("data-tip", key);
      badge.setAttribute("data-i18n-label", key);
      badge.setAttribute("aria-label", tx(key));
      var logo = document.createElement("img");
      logo.className = "nx-nick-logo";
      logo.src = user.logo.src;
      logo.alt = "";
      badge.appendChild(logo);
      name.appendChild(badge);
    }
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

    var desk = null;
    if (PANEL[panel]) {
      desk = document.createElement("a");
      desk.className = "mk-user-item";
      desk.setAttribute("role", "menuitem");
      desk.href = PANEL[panel].href;
      desk.textContent = tx(PANEL[panel].key);
      desk.setAttribute("data-i18n", PANEL[panel].key);
    }

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

    var first = menu.firstChild;
    menu.insertBefore(name, first);
    menu.insertBefore(mine, first);
    menu.insertBefore(prof, first);
    if (desk) menu.insertBefore(desk, first);
    menu.appendChild(out);
  }

  function showUser(btn, menu, user, panel) {
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
    fillUserMenu(menu, user, panel);
  }

  function initMenu(btn) {
    btn.removeAttribute("data-soon");
    var label = tx("user.menu");
    btn.setAttribute("aria-label", label);
    btn.setAttribute("title", label);
    btn.setAttribute("data-i18n-label", "user.menu");
    btn.setAttribute("data-i18n-title", "user.menu");
    btn.setAttribute("aria-haspopup", "menu");
    btn.setAttribute("aria-expanded", "false");

    var menu = buildMenu();
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
    return menu;
  }

  var chatLink = head ? head.querySelector(".mk-chat") : null;
  var unreadBadge = null;
  var unreadOn = false;
  var unreadPushed = false;
  var unreadTimer = 0;
  var unreadAt = 0;
  var UNREAD_MS = 60000;
  var UNREAD_WAKE_MS = 15000;

  function setUnread(n) {
    if (!chatLink) return;
    n = Math.max(0, Math.floor(Number(n) || 0));
    head.classList.toggle("has-unread", n > 0);
    if (!n) {
      if (unreadBadge && unreadBadge.parentNode) unreadBadge.parentNode.removeChild(unreadBadge);
      return;
    }
    if (!unreadBadge) {
      unreadBadge = document.createElement("span");
      unreadBadge.className = "mk-chat-badge";
      unreadBadge.setAttribute("aria-hidden", "true");
    }
    unreadBadge.textContent = n > 9 ? "9+" : String(n);
    if (!unreadBadge.parentNode) chatLink.appendChild(unreadBadge);
  }

  function unreadLater() {
    clearTimeout(unreadTimer);
    if (!unreadOn || unreadPushed || document.hidden) return;
    unreadTimer = setTimeout(unreadNow, UNREAD_MS);
  }

  function unreadNow() {
    clearTimeout(unreadTimer);
    if (!unreadOn || unreadPushed || document.hidden) return;
    unreadAt = Date.now();
    fetch(AUTH_STATE, { cache: "no-store" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        if (!s || !s.user) { unreadOn = false; setUnread(0); return; }
        if (!unreadPushed) setUnread(s.unread);
      })
      .catch(function () {})
      .then(unreadLater);
  }

  function startUnread(n) {
    if (!chatLink || unreadOn) return;
    unreadOn = true;
    unreadAt = Date.now();
    if (!unreadPushed) setUnread(n);
    unreadLater();
  }

  document.addEventListener("visibilitychange", function () {
    if (!unreadOn) return;
    if (document.hidden) { clearTimeout(unreadTimer); return; }
    if (Date.now() - unreadAt > UNREAD_WAKE_MS) unreadNow();
    else unreadLater();
  });

  document.addEventListener("mk:unread", function (e) {
    unreadPushed = true;
    clearTimeout(unreadTimer);
    setUnread(e.detail);
  });

  var avatarBtn = head ? head.querySelector(".mk-avatar") : null;
  var avatarMenu = avatarBtn ? initMenu(avatarBtn) : null;
  if (avatarBtn && window.fetch && auth) {
    reportLogin(takeLoginFlag());
    fetch(AUTH_STATE, { cache: "no-store" })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (s) {
        var invited = takeInvite();
        if (!s || !s.roblox) return;
        if (s.user) {
          showUser(avatarBtn, avatarMenu, s.user, s.admin ? "admin" : s.moderator ? "support" : "");
          startUnread(s.unread);
        }
        else if (s.roblox_public || invited) addLoginItem(avatarMenu);
      })
      .catch(function () {});
  }

  var tip = null;
  var tipFor = null;
  var tipPinned = false;

  function badgeOf(node) {
    return node && node.closest ? node.closest(".nx-nick-badge") : null;
  }

  function showTip(badge, pinned) {
    if (!tip) {
      tip = document.createElement("div");
      tip.className = "nx-nick-tip";
      tip.setAttribute("aria-hidden", "true");
      document.body.appendChild(tip);
    }
    tip.textContent = tx(badge.getAttribute("data-tip")) || "";
    var r = badge.getBoundingClientRect();
    var below = r.top < 56;
    tip.classList.toggle("is-below", below);
    tip.style.left = Math.round(r.left + r.width / 2) + "px";
    tip.style.top = Math.round(below ? r.bottom : r.top) + "px";
    tip.hidden = false;
    tipFor = badge;
    tipPinned = pinned;
  }

  function hideTip() {
    if (tip) tip.hidden = true;
    tipFor = null;
    tipPinned = false;
  }

  document.addEventListener("click", function (e) {
    var badge = badgeOf(e.target);
    if (!badge) {
      if (tipPinned) hideTip();
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    if (tipFor === badge && tipPinned) hideTip();
    else showTip(badge, true);
  }, true);

  document.addEventListener("pointerover", function (e) {
    if (e.pointerType !== "mouse" || tipPinned) return;
    var badge = badgeOf(e.target);
    if (badge && badge !== tipFor) showTip(badge, false);
  });

  document.addEventListener("pointerout", function (e) {
    if (e.pointerType !== "mouse" || tipPinned || !tipFor) return;
    if (badgeOf(e.target) === tipFor && !tipFor.contains(e.relatedTarget)) hideTip();
  });

  document.addEventListener("focusin", function (e) {
    if (e.target.classList && e.target.classList.contains("nx-nick-badge")) showTip(e.target, false);
  });

  document.addEventListener("focusout", function (e) {
    if (!tipPinned && tipFor && e.target === tipFor) hideTip();
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && tipFor) hideTip();
  });

  document.addEventListener("scroll", function () { if (tipFor) hideTip(); }, true);
  window.addEventListener("resize", function () { if (tipFor) hideTip(); });
})();
