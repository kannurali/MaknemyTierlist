(function () {
  "use strict";

  var tg = window.Telegram && window.Telegram.WebApp;
  var root = document.getElementById("ta");
  if (!root) { return; }

  var API = "api/gw_tg_app.php";
  var ALL = "https://maknemy.com/giveaways";
  var SVGNS = "http://www.w3.org/2000/svg";

  var TEXT = {
    ru: {
      outTitle: "Откройте розыгрыш из Telegram",
      outSub: "Кнопкой «Участвовать» под постом.",
      all: "Все розыгрыши на сайте",
      done: "Готово",
      recheck: "Проверить подписку",
      retry: "Попробовать ещё раз",
      results: "Итоги на сайте",
      close: "Закрыть",
      site: "Открыть на сайте",
      join: "Подписаться",
      went: "Открыт",
      prizes: "Призы",
      when: "Итоги: ",
      people: ["участник", "участника", "участников"],
      of: "из ",
      net: "Не получилось связаться с сервером.",
      netSub: "Проверьте интернет и попробуйте ещё раз.",
      missSub: "Вернитесь сюда после подписки — проверим сами.",
      still: "Подписка пока не видна. Если вы только что подписались, проверьте ещё раз через пару секунд.",
      ask: "Разрешите боту написать вам — так вы узнаете о выигрыше.",
      allow: "Разрешить",
      asked: "Бот напишет вам, если вы выиграете.",
      loading: "Проверяем участие",
      state: {
        joined: "Вы участвуете!",
        last: "Вы заняли последнее место — итоги совсем скоро.",
        already: "Вы уже участвуете.",
        missing: "Подпишитесь на каналы из условий.",
        closed: "Приём участников закрыт.",
        full: "Все места уже заняты.",
        banned: "Участие в розыгрышах для вас закрыто.",
        tg_used: "Этот Telegram уже участвует в розыгрыше.",
        site: "В этом розыгрыше участвуют через сайт.",
        none: "Розыгрыш не найден.",
        auth: "Откройте розыгрыш заново из Telegram.",
        rate: "Слишком много попыток. Подождите минуту.",
        down: "Розыгрыши сейчас недоступны.",
        error: "Что-то пошло не так."
      }
    },
    en: {
      outTitle: "Open the giveaway from Telegram",
      outSub: "Use the “Join” button under the post.",
      all: "All giveaways on the site",
      done: "Done",
      recheck: "Check subscription",
      retry: "Try again",
      results: "Results on the site",
      close: "Close",
      site: "Open on the site",
      join: "Subscribe",
      went: "Opened",
      prizes: "Prizes",
      when: "Results: ",
      people: ["participant", "participants"],
      of: "of ",
      net: "Couldn’t reach the server.",
      netSub: "Check your connection and try again.",
      missSub: "Come back here after subscribing — we’ll check on our own.",
      still: "We don’t see the subscription yet. If you’ve just subscribed, check again in a couple of seconds.",
      ask: "Let the bot message you — that’s how you’ll hear if you win.",
      allow: "Allow",
      asked: "The bot will message you if you win.",
      loading: "Checking your entry",
      state: {
        joined: "You’re in!",
        last: "You took the last spot — results very soon.",
        already: "You’re already in.",
        missing: "Subscribe to the channels from the conditions.",
        closed: "Entries are closed.",
        full: "All spots are taken.",
        banned: "Giveaways are closed for you.",
        tg_used: "This Telegram account is already in the giveaway.",
        site: "This giveaway takes entries on the site.",
        none: "Giveaway not found.",
        auth: "Open the giveaway again from Telegram.",
        rate: "Too many attempts. Wait a minute.",
        down: "Giveaways are unavailable right now.",
        error: "Something went wrong."
      }
    }
  };

  var ICONS = {
    check: [["p", "M6 12.6l4 4L18.2 8", "ta-tick"]],
    horn: [["p", "M2.5 9.4v4.4c0 .7.5 1.2 1.1 1.3L20 18.6c.8.2 1.5-.4 1.5-1.2V5.8c0-.8-.7-1.4-1.5-1.2L3.6 8.1c-.6.1-1.1.6-1.1 1.3z"], ["p", "M7 15.6l.8 3.7c.2.9 1.1 1.5 2 1.3l.6-.1c.9-.2 1.5-1.1 1.3-2l-.4-1.9"]],
    lock: [["r", 5, 10.5, 14, 10, 2.5], ["p", "M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"]],
    alert: [["c", 12, 12, 9], ["p", "M12 7.6v5.2"], ["p", "M12 16.3h.01"]],
    gift: [["r", 4, 8.5, 16, 4, 1], ["p", "M5.5 12.5V19c0 .6.4 1 1 1h11c.6 0 1-.4 1-1v-6.5"], ["p", "M12 8.5V20"], ["p", "M12 8.5C10.8 5.6 7.6 4.6 7.2 6.4c-.4 1.6 1.8 2.1 4.8 2.1z"], ["p", "M12 8.5c1.2-2.9 4.4-3.9 4.8-2.1.4 1.6-1.8 2.1-4.8 2.1z"]],
    ban: [["c", 12, 12, 9], ["p", "M5.7 5.7l12.6 12.6"]],
    info: [["c", 12, 12, 9], ["p", "M12 11v5.4"], ["p", "M12 7.6h.01"]],
    clock: [["c", 12, 12, 9], ["p", "M12 7v5l3.2 2"]],
    bell: [["p", "M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 1.5h-15z"], ["p", "M10 20.5a2.1 2.1 0 0 0 4 0"]],
    plug: [["p", "M9 3v4.5"], ["p", "M15 3v4.5"], ["p", "M6.5 7.5h11V11a5.5 5.5 0 0 1-11 0z"], ["p", "M12 16.5V21"]]
  };

  var KIND = {
    joined: "ok", last: "ok", already: "ok",
    missing: "missing",
    closed: "closed", full: "closed",
    down: "retry", error: "retry", rate: "retry",
    auth: "auth",
    none: "none",
    banned: "info", tg_used: "info", site: "info"
  };

  var lang = pickLang();
  var mainFn = null;
  var dockBtn = null;
  var busyNow = false;
  var lastKind = "";
  var lastAt = 0;
  var awaitBack = false;
  var askedWrite = false;
  var writeOk = false;
  var manualCheck = false;

  function L() { return TEXT[lang] || TEXT.ru; }

  function pickLang() {
    var code = "";
    if (tg && tg.initDataUnsafe && tg.initDataUnsafe.user && tg.initDataUnsafe.user.language_code) {
      code = String(tg.initDataUnsafe.user.language_code);
    } else if (!inTelegram() && navigator.language) {
      code = String(navigator.language);
    }
    code = code.slice(0, 2).toLowerCase();
    if (!code) { return "ru"; }
    return /^(ru|uk|be|kk)$/.test(code) ? "ru" : "en";
  }

  function inTelegram() {
    return !!(tg && typeof tg.initData === "string" && tg.initData !== "");
  }

  function ver(v) {
    try { return !!(tg && typeof tg.isVersionAtLeast === "function" && tg.isVersionAtLeast(v)); } catch (e) { return false; }
  }

  function call(fn) {
    try { fn(); } catch (e) {}
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = String(text); }
    return n;
  }

  function icon(name) {
    var svg = document.createElementNS(SVGNS, "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    (ICONS[name] || []).forEach(function (s) {
      var n;
      if (s[0] === "p") {
        n = document.createElementNS(SVGNS, "path");
        n.setAttribute("d", s[1]);
        if (s[2]) { n.setAttribute("class", s[2]); n.setAttribute("pathLength", "1"); }
      } else if (s[0] === "c") {
        n = document.createElementNS(SVGNS, "circle");
        n.setAttribute("cx", s[1]); n.setAttribute("cy", s[2]); n.setAttribute("r", s[3]);
      } else {
        n = document.createElementNS(SVGNS, "rect");
        n.setAttribute("x", s[1]); n.setAttribute("y", s[2]);
        n.setAttribute("width", s[3]); n.setAttribute("height", s[4]); n.setAttribute("rx", s[5]);
      }
      svg.appendChild(n);
    });
    return svg;
  }

  function num(n) {
    try { return new Intl.NumberFormat(lang === "ru" ? "ru-RU" : "en-US").format(n); } catch (e) { return String(n); }
  }

  function people(n) {
    var f = L().people;
    if (lang !== "ru") { return n === 1 ? f[0] : f[1]; }
    var a = Math.abs(n) % 100, b = a % 10;
    if (a > 10 && a < 20) { return f[2]; }
    if (b === 1) { return f[0]; }
    if (b > 1 && b < 5) { return f[1]; }
    return f[2];
  }

  function safeUrl(u) {
    return typeof u === "string" && /^https:\/\/[^\s]+$/i.test(u) ? u : "";
  }

  function openUrl(u) {
    u = safeUrl(u);
    if (!u) { return; }
    if (tg && typeof tg.openLink === "function" && inTelegram()) { call(function () { tg.openLink(u); }); }
    else { window.location.href = u; }
  }

  function openTg(u) {
    u = safeUrl(u);
    if (!u) { return; }
    if (/^https:\/\/t\.me\//i.test(u) && tg && typeof tg.openTelegramLink === "function") {
      call(function () { tg.openTelegramLink(u); });
    } else {
      openUrl(u);
    }
  }

  function haptic(kind) {
    if (!tg || !tg.HapticFeedback || !ver("6.1")) { return; }
    call(function () { tg.HapticFeedback.notificationOccurred(kind); });
  }

  function hasMain() {
    return !!(tg && inTelegram() && tg.MainButton && typeof tg.MainButton.setText === "function" &&
      typeof tg.MainButton.onClick === "function" && typeof tg.MainButton.show === "function");
  }

  function setMain(text, fn) {
    if (hasMain()) {
      var mb = tg.MainButton;
      if (mainFn && typeof mb.offClick === "function") { call(function () { mb.offClick(mainFn); }); }
      mainFn = null;
      if (!text) { call(function () { mb.hide(); }); return; }
      mainFn = function () { if (!busyNow) { fn(); } };
      call(function () {
        if (typeof mb.hideProgress === "function") { mb.hideProgress(); }
        mb.setText(text);
        mb.onClick(mainFn);
        if (typeof mb.enable === "function") { mb.enable(); }
        mb.show();
      });
      return;
    }
    dockBtn = null;
    if (!text) { return; }
    var dock = el("div", "ta-dock");
    var b = el("button", "ta-cta", text);
    b.type = "button";
    b.addEventListener("click", function () { if (!busyNow) { fn(); } });
    dock.appendChild(b);
    root.appendChild(dock);
    dockBtn = b;
  }

  function setBusy(on) {
    busyNow = on;
    root.setAttribute("aria-busy", on ? "true" : "false");
    if (hasMain()) {
      var mb = tg.MainButton;
      call(function () {
        if (on) {
          if (typeof mb.showProgress === "function") { mb.showProgress(false); }
          if (typeof mb.disable === "function") { mb.disable(); }
        } else {
          if (typeof mb.hideProgress === "function") { mb.hideProgress(); }
          if (typeof mb.enable === "function") { mb.enable(); }
        }
      });
    }
    if (dockBtn) {
      dockBtn.disabled = on;
      dockBtn.classList.toggle("is-busy", on);
    }
  }

  function typo(s) {
    return String(s).replace(/ ([\u2013\u2014]) /g, "\u00a0$1 ");
  }

  function hero(kind, glyph, title, sub) {
    var box = el("div", "ta-hero");
    var badge = el("div", "ta-badge is-" + kind);
    if (kind === "ok") {
      var stub = el("span", "ta-stub-art");
      stub.appendChild(icon(glyph));
      badge.appendChild(stub);
    } else {
      badge.appendChild(icon(glyph));
    }
    box.appendChild(badge);
    box.appendChild(el("h1", "ta-title", typo(title)));
    if (sub) { box.appendChild(el("p", "ta-sub", typo(sub))); }
    return box;
  }

  function list(a) {
    return Array.isArray(a) ? a : [];
  }

  function ticket(gw) {
    var off = gw.phase === "ended" || gw.phase === "drawn";
    var card = el("section", "ta-ticket" + (off ? " is-off" : ""));
    var img = safeUrl(gw.image) || (typeof gw.image === "string" && gw.image.charAt(0) === "/" ? gw.image : "");
    if (img) {
      var media = el("div", "ta-banner");
      var pic = el("img");
      pic.alt = "";
      pic.decoding = "async";
      pic.src = img;
      pic.addEventListener("error", function () {
        if (media.parentNode) { media.parentNode.removeChild(media); }
        card.classList.add("is-flat");
      });
      media.appendChild(pic);
      card.appendChild(media);
    } else {
      card.classList.add("is-flat");
    }
    var title = typeof gw.title === "string" && gw.title.trim() ? gw.title.trim() : "";
    if (title) { card.appendChild(el("h2", "ta-flag", typo(title))); }
    if (typeof gw.when === "string" && gw.when.trim()) {
      var when = el("p", "ta-when");
      when.appendChild(icon("clock"));
      when.appendChild(el("span", "", typo(L().when + gw.when.trim())));
      card.appendChild(when);
    }

    var prizes = list(gw.prizes).filter(function (p) { return p && typeof p.name === "string" && p.name.trim(); });
    var entries = typeof gw.entries === "number" && isFinite(gw.entries) ? Math.max(0, Math.floor(gw.entries)) : -1;
    var max = typeof gw.max === "number" && isFinite(gw.max) ? Math.max(0, Math.floor(gw.max)) : 0;
    if (!prizes.length && entries < 0) { return card; }

    var tear = el("div", "ta-tear");
    tear.setAttribute("aria-hidden", "true");
    tear.appendChild(el("span"));
    card.appendChild(tear);

    var stub = el("div", "ta-stub");
    if (prizes.length) {
      stub.appendChild(el("h3", "ta-head", L().prizes));
      var ul = el("ul", "ta-prizes");
      prizes.forEach(function (p) {
        var li = el("li", "ta-prize");
        li.appendChild(el("span", "ta-prize-name", p.name.trim()));
        var n = Math.floor(Number(p.count));
        if (n > 1) { li.appendChild(el("span", "ta-prize-n", "× " + num(n))); }
        ul.appendChild(li);
      });
      stub.appendChild(ul);
    }
    if (entries >= 0) {
      var box = el("div", "ta-count");
      var line = el("div", "ta-count-line");
      var left = el("div", "ta-count-n", num(entries));
      left.appendChild(el("span", "", people(entries)));
      line.appendChild(left);
      if (max > 0) { line.appendChild(el("div", "ta-count-of", L().of + num(max))); }
      box.appendChild(line);
      if (max > 0) {
        var bar = el("div", "ta-bar");
        bar.setAttribute("role", "progressbar");
        bar.setAttribute("aria-valuemin", "0");
        bar.setAttribute("aria-valuemax", String(max));
        bar.setAttribute("aria-valuenow", String(Math.min(entries, max)));
        var fill = el("span");
        fill.style.width = Math.min(100, entries / max * 100).toFixed(2) + "%";
        bar.appendChild(fill);
        box.appendChild(bar);
      }
      stub.appendChild(box);
    }
    card.appendChild(stub);
    return card;
  }

  function linkRow(text, fn) {
    var row = el("div", "ta-links");
    var b = el("button", "ta-link", text);
    b.type = "button";
    b.addEventListener("click", fn);
    row.appendChild(b);
    return row;
  }

  function hue(s) {
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
    return Math.abs(h) % 7;
  }

  function initial(s) {
    var m = s.match(/[0-9A-Za-zÀ-ÿĀ-žА-яЁё]/);
    return (m ? m[0] : s.charAt(0) || "#").toUpperCase();
  }

  function handle(u) {
    var m = /^https:\/\/t\.me\/([A-Za-z][A-Za-z0-9_]{3,})\/?$/i.exec(u);
    return m ? "@" + m[1] : "";
  }

  function channels(items) {
    var ul = el("ul", "ta-list");
    items.forEach(function (c) {
      var title = typeof c.title === "string" && c.title.trim() ? c.title.trim() : "Telegram";
      var url = safeUrl(c.url);
      var li = el("li", "ta-chan");
      var ava = el("span", "ta-ava c" + hue(title), initial(title));
      ava.setAttribute("aria-hidden", "true");
      li.appendChild(ava);
      var body = el("div", "ta-chan-body");
      body.appendChild(el("div", "ta-chan-name", title));
      var at = url ? handle(url) : "";
      if (at) { body.appendChild(el("div", "ta-chan-at", at)); }
      li.appendChild(body);
      if (url) {
        var b = el("button", "ta-join", L().join);
        b.type = "button";
        b.setAttribute("aria-label", L().join + ": " + title);
        b.addEventListener("click", function () {
          awaitBack = true;
          b.classList.add("is-went");
          openTg(url);
        });
        li.appendChild(b);
      }
      ul.appendChild(li);
    });
    return ul;
  }

  function askRow() {
    var u = tg && tg.initDataUnsafe && tg.initDataUnsafe.user;
    if (!u || u.allows_write_to_pm || !ver("6.9") || typeof tg.requestWriteAccess !== "function") { return null; }
    var row = el("div", "ta-ask");
    row.appendChild(icon("bell"));
    var col = el("div", "ta-ask-body");
    var text = el("p", "ta-ask-text", typo(writeOk ? L().asked : L().ask));
    col.appendChild(text);
    row.appendChild(col);
    if (writeOk) { row.classList.add("is-ok"); return row; }
    var again = el("button", "ta-ask-btn", L().allow);
    again.type = "button";
    again.hidden = true;
    col.appendChild(again);

    function ask() {
      again.disabled = true;
      call(function () {
        tg.requestWriteAccess(function (ok) {
          again.disabled = false;
          if (ok) {
            writeOk = true;
            u.allows_write_to_pm = true;
            text.textContent = L().asked;
            row.classList.add("is-ok");
            if (again.parentNode) { again.parentNode.removeChild(again); }
            haptic("success");
          } else {
            again.hidden = false;
          }
        });
      });
    }
    again.addEventListener("click", ask);
    if (!askedWrite) {
      askedWrite = true;
      setTimeout(ask, 900);
    } else {
      again.hidden = false;
    }
    return row;
  }

  function clear(solo) {
    while (root.firstChild) { root.removeChild(root.firstChild); }
    dockBtn = null;
    root.classList.toggle("is-solo", !!solo);
  }

  function enter(kind) {
    root.classList.remove("is-enter");
    if (kind !== lastKind) {
      void root.offsetWidth;
      root.classList.add("is-enter");
    }
    lastKind = kind;
  }

  function render(data) {
    var state = data.state;
    var kind = KIND[state] || "retry";
    var t = L();
    var text = typeof data.text === "string" && data.text.trim() ? data.text.trim() : (t.state[state] || t.state.error);
    var gw = data.gw && typeof data.gw === "object" ? data.gw : null;
    var site = gw ? safeUrl(gw.site) : "";
    var sameKind = kind === lastKind;

    clear(!gw && kind !== "missing");
    enter(kind);

    if (kind === "ok") {
      root.appendChild(hero("ok", "check", text, typeof data.note === "string" ? data.note.trim() : ""));
      var ask = askRow();
      if (ask) { root.appendChild(ask); }
      if (gw) { root.appendChild(ticket(gw)); }
      if (site) { root.appendChild(linkRow(t.site, function () { openUrl(site); })); }
      setMain(t.done, function () { call(function () { tg.close(); }); });
      if (state !== "already" && !sameKind) { haptic("success"); }
      return;
    }

    if (kind === "missing") {
      var miss = list(data.missing).filter(function (c) { return c && typeof c === "object"; });
      root.appendChild(hero("todo", "horn", text, t.missSub));
      if (miss.length) { root.appendChild(channels(miss)); }
      if (manualCheck && sameKind) {
        root.appendChild(el("p", "ta-note", typo(t.still)));
        haptic("warning");
      }
      if (gw) { root.appendChild(ticket(gw)); }
      setMain(t.recheck, function () { check(true); });
      return;
    }

    if (kind === "closed") {
      root.appendChild(hero("info", "lock", text));
      if (gw) { root.appendChild(ticket(gw)); }
      if (site) { setMain(t.results, function () { openUrl(site); }); }
      else { setMain("", null); root.appendChild(linkRow(t.all, function () { openUrl(ALL); })); }
      return;
    }

    if (kind === "retry") {
      var net = state === "error" && data.net;
      root.appendChild(hero("bad", net ? "plug" : "alert", text, net ? t.netSub : ""));
      if (gw) { root.appendChild(ticket(gw)); }
      setMain(t.retry, function () { check(true); });
      return;
    }

    if (kind === "auth") {
      root.appendChild(hero("info", "clock", text));
      setMain(t.close, function () { call(function () { tg.close(); }); });
      return;
    }

    if (kind === "none") {
      root.appendChild(hero("info", "gift", text));
      setMain("", null);
      root.appendChild(linkRow(t.all, function () { openUrl(ALL); }));
      return;
    }

    root.appendChild(hero(state === "banned" ? "bad" : "info", state === "banned" ? "ban" : "info", text));
    if (gw) { root.appendChild(ticket(gw)); }
    if (site) {
      if (state === "site") { setMain(t.site, function () { openUrl(site); }); }
      else { setMain("", null); root.appendChild(linkRow(t.site, function () { openUrl(site); })); }
    } else {
      setMain("", null);
      root.appendChild(linkRow(t.all, function () { openUrl(ALL); }));
    }
  }

  function adopt(data) {
    if (data && (data.lang === "ru" || data.lang === "en") && data.lang !== lang) {
      lang = data.lang;
      document.documentElement.lang = lang;
    }
  }

  function check(manual) {
    if (busyNow) { return; }
    manualCheck = !!manual;
    lastAt = Date.now();
    setBusy(true);
    var status = 0;
    var p;
    try {
      p = fetch(API, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ init: tg.initData }),
        credentials: "same-origin",
        cache: "no-store"
      });
    } catch (e) {
      p = Promise.reject(e);
    }
    p.then(function (r) {
      status = r.status;
      return r.json().catch(function () { return null; });
    }).then(function (j) {
      var data = j && typeof j === "object" ? j : {};
      if (typeof data.state !== "string" || !data.state) {
        data.state = status === 403 ? "auth" : status === 429 ? "rate" : "error";
        data.text = "";
      }
      return data;
    }, function () {
      return { state: "error", net: true, text: L().net };
    }).then(function (data) {
      setBusy(false);
      adopt(data);
      render(data);
      manualCheck = false;
    });
  }

  function onBack() {
    if (!awaitBack || busyNow || lastKind !== "missing") { return; }
    if (Date.now() - lastAt < 1500) { return; }
    awaitBack = false;
    setTimeout(function () { check(false); }, 350);
  }

  function outside() {
    clear(true);
    root.setAttribute("aria-busy", "false");
    enter("outside");
    var t = L();
    root.appendChild(hero("info", "gift", t.outTitle, t.outSub));
    var dock = el("div", "ta-dock");
    var a = el("a", "ta-cta", t.all);
    a.href = ALL;
    dock.appendChild(a);
    root.appendChild(dock);
  }

  function scheme() {
    var s = tg && tg.colorScheme === "light" ? "light" : "dark";
    document.documentElement.setAttribute("data-scheme", s);
  }

  document.documentElement.lang = lang;

  if (!inTelegram()) {
    outside();
    return;
  }

  scheme();
  var skel = root.querySelector(".ta-hero");
  if (skel) { skel.setAttribute("aria-label", L().loading); }
  call(function () { tg.ready(); });
  call(function () { tg.expand(); });
  if (ver("6.1")) {
    call(function () { tg.setHeaderColor("secondary_bg_color"); });
    call(function () { tg.setBackgroundColor("secondary_bg_color"); });
  }
  if (ver("7.10") && typeof tg.setBottomBarColor === "function") {
    call(function () { tg.setBottomBarColor("secondary_bg_color"); });
  }
  if (typeof tg.onEvent === "function") {
    call(function () { tg.onEvent("themeChanged", scheme); });
  }
  if (ver("8.0") && typeof tg.onEvent === "function") {
    call(function () { tg.onEvent("activated", onBack); });
  } else {
    document.addEventListener("visibilitychange", function () {
      if (document.visibilityState === "visible") { onBack(); }
    });
    window.addEventListener("focus", onBack);
  }

  check(false);
})();
