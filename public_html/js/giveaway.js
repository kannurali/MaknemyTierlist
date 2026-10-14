(function (root) {
  "use strict";

  var API = "/api/giveaways.php";
  var ENTER = "/api/giveaway_enter.php";
  var LINK = "/api/tg_link.php";
  var LANG_KEY = "nexus-lang-v1";
  var JOINED_KEY = "nx-gw-joined-v1";
  var RESUME_KEY = "nx-gw-resume-v1";
  var SESSION_WAIT = 5000;
  var LINK_POLL_MS = 3000;
  var LINK_POLL_FOR = 10 * 60 * 1000;

  var dataPromise = null;
  var sessionPromise = null;
  var ticker = 0;

  function lang() {
    var i18n = root.I18N;
    var stored = null;
    try { stored = localStorage.getItem(LANG_KEY); } catch (_) {}
    var doc = document.documentElement.lang;
    if (doc === "ru" || doc === "en") { return doc; }
    return i18n ? i18n.pickLang(stored, navigator.language) : "ru";
  }

  function t(key, vars) {
    return root.I18N ? root.I18N.t(key, lang(), vars) : key;
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function pick(obj) {
    if (!obj) { return ""; }
    var l = lang();
    return (l === "en" ? obj.en || obj.ru : obj.ru || obj.en) || "";
  }

  function load(fresh) {
    if (!dataPromise || fresh) {
      dataPromise = fetch(API, { cache: fresh ? "no-store" : "default" })
        .then(function (r) { return r.ok ? r.json() : { live: [], past: [] }; })
        .catch(function () { return { live: [], past: [] }; });
    }
    return dataPromise;
  }

  function session() {
    if (sessionPromise) { return sessionPromise; }
    sessionPromise = new Promise(function (resolve) {
      if (root.MK_SESSION !== undefined) { resolve(root.MK_SESSION); return; }
      var done = false;
      var finish = function (s) { if (!done) { done = true; resolve(s || null); } };
      document.addEventListener("mk:session", function (e) { finish(e.detail); }, { once: true });
      setTimeout(function () { finish(root.MK_SESSION); }, SESSION_WAIT);
    });
    return sessionPromise;
  }

  function readJoined() {
    try {
      var v = JSON.parse(localStorage.getItem(JOINED_KEY));
      return Array.isArray(v) ? v : [];
    } catch (_) { return []; }
  }

  function markJoined(id) {
    var list = readJoined().filter(function (x) { return x !== id; });
    list.push(id);
    try { localStorage.setItem(JOINED_KEY, JSON.stringify(list.slice(-50))); } catch (_) {}
  }

  function isJoined(id, s) {
    if (s && s.user && Array.isArray(s.giveaways) && s.giveaways.indexOf(id) >= 0) { return true; }
    return readJoined().indexOf(id) >= 0;
  }

  function setResume(id) {
    try { sessionStorage.setItem(RESUME_KEY, String(id)); } catch (_) {}
  }

  function takeResume() {
    var v = null;
    try { v = sessionStorage.getItem(RESUME_KEY); sessionStorage.removeItem(RESUME_KEY); } catch (_) {}
    var n = v ? parseInt(v, 10) : 0;
    return n > 0 ? n : 0;
  }

  function post(url, body) {
    return fetch(url, {
      method: "POST",
      cache: "no-store",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: r.status === 429 ? "rate_limited" : "net" }; });
    });
  }

  function pad(n) { return n < 10 ? "0" + n : String(n); }

  function clock(sec) {
    sec = Math.max(0, Math.floor(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var hms = pad(h) + ":" + pad(m) + ":" + pad(s);
    return d ? t("gw.days", { n: d }) + " " + hms : hms;
  }

  function date(ts) {
    var opts = { timeZone: "Europe/Moscow", day: "numeric", month: "long", hour: "2-digit", minute: "2-digit" };
    var s;
    try { s = new Date(ts * 1000).toLocaleString(lang() === "en" ? "en-GB" : "ru-RU", opts); }
    catch (_) { s = new Date(ts * 1000).toLocaleString(); }
    return s + " " + t("gw.msk");
  }

  function tick() {
    var now = Date.now() / 1000;
    document.querySelectorAll("[data-gw-ends]").forEach(function (n) {
      var left = +n.getAttribute("data-gw-ends") - now;
      var time = n.querySelector(".gw-clock-time");
      if (left > 0) {
        if (time) { time.textContent = clock(left); }
        return;
      }
      n.removeAttribute("data-gw-ends");
      var card = n.closest(".gw-card");
      if (card && card.gwEnded) { card.gwEnded(); }
    });
  }

  function startTicker() {
    if (ticker) { return; }
    ticker = setInterval(tick, 1000);
  }

  function bindSpoilers(box) {
    var open = function (e) {
      var sp = e.target.closest ? e.target.closest(".nw-spoiler") : null;
      if (!sp || sp.classList.contains("is-open")) { return; }
      if (e.type === "keydown" && e.key !== "Enter" && e.key !== " ") { return; }
      e.preventDefault();
      sp.classList.add("is-open");
      sp.removeAttribute("role");
      sp.removeAttribute("tabindex");
    };
    box.addEventListener("click", open);
    box.addEventListener("keydown", open);
  }

  function renderBody(g) {
    var box = el("div", "gw-body");
    if (root.NEWSBLOCKS && g.body && g.body.length) {
      box.appendChild(root.NEWSBLOCKS.renderBlocks(document, g.body, lang()));
      bindSpoilers(box);
    }
    return box;
  }

  function renderPrizes(g) {
    var list = el("ul", "gw-prizes");
    (g.prizes || []).forEach(function (p) {
      var li = el("li", "gw-prize");
      li.appendChild(el("span", "gw-prize-name", p.name));
      if (p.count > 1) { li.appendChild(el("span", "gw-prize-count", "×" + p.count)); }
      list.appendChild(li);
    });
    return list;
  }

  function winnerName(w) {
    if (w.display && w.name && w.display !== w.name) { return w.display + " (@" + w.name + ")"; }
    return w.display || (w.name ? "@" + w.name : "#" + w.id);
  }

  function renderWinners(g) {
    var sec = el("section", "gw-winners");
    sec.appendChild(el("h3", "gw-h", t("gw.winners")));
    if (!g.winners || !g.winners.length) {
      sec.appendChild(el("p", "gw-note", t("gw.noWinners")));
      return sec;
    }
    var list = el("ul", "gw-win-list");
    g.winners.forEach(function (w) {
      var li = el("li", "gw-win");
      var a = el("a", "gw-win-user");
      a.href = "/profile?id=" + encodeURIComponent(w.id);
      if (w.avatar) {
        var img = el("img", "gw-win-ava");
        img.src = w.avatar;
        img.alt = "";
        img.loading = "lazy";
        img.referrerPolicy = "no-referrer";
        a.appendChild(img);
      } else {
        a.appendChild(el("span", "gw-win-ava"));
      }
      a.appendChild(el("span", "gw-win-name", winnerName(w)));
      li.appendChild(a);
      var prize = g.prizes && g.prizes[w.prize] ? g.prizes[w.prize].name : "";
      li.appendChild(el("span", "gw-win-prize", prize));
      list.appendChild(li);
    });
    sec.appendChild(list);
    return sec;
  }

  function condItem(key, label) {
    var li = el("li", "gw-cond-item");
    li.setAttribute("data-k", key);
    li.appendChild(el("span", "gw-cond-mark"));
    li.appendChild(el("span", "gw-cond-text", label));
    return li;
  }

  function renderConditions(g, ui) {
    var sec = el("section", "gw-cond");
    sec.appendChild(el("h3", "gw-h", t("gw.conditions")));
    var list = el("ul", "gw-cond-list");
    ui.cLogin = condItem("login", t("gw.condLogin"));
    ui.cTg = condItem("tg", t("gw.condTg"));
    list.appendChild(ui.cLogin);
    list.appendChild(ui.cTg);
    ui.cChannels = {};
    (g.channels || []).forEach(function (c) {
      var li = condItem("ch", t("gw.condChannel", { title: c.title }));
      if (c.url) {
        var a = el("a", "gw-sub", t("gw.subscribe"));
        a.href = c.url;
        a.target = "_blank";
        a.rel = "noopener noreferrer";
        li.appendChild(a);
      }
      ui.cChannels[c.id] = li;
      list.appendChild(li);
    });
    sec.appendChild(list);
    return sec;
  }

  function mark(li, state) {
    if (!li) { return; }
    li.classList.toggle("is-ok", state === "ok");
    li.classList.toggle("is-bad", state === "bad");
  }

  function card(g, opts) {
    opts = opts || {};
    var ui = {};
    var root_ = el("article", "gw-card " + (opts.mode === "page" ? "is-page" : "is-pop"));
    root_.id = "g" + g.id;
    root_.setAttribute("data-phase", g.phase);

    var media = el("div", "gw-media" + (g.image ? "" : " is-empty"));
    if (g.image) {
      var img = el("img", "gw-img");
      img.src = g.image.url;
      img.alt = "";
      img.width = g.image.w;
      img.height = g.image.h;
      img.decoding = "async";
      media.appendChild(img);
    }
    media.appendChild(el("span", "gw-chip", t("gw.chip")));
    root_.appendChild(media);

    var main = el("div", "gw-main");
    var title = el("h2", "gw-title", pick(g.title));
    title.id = "gwTitle" + g.id;
    main.appendChild(title);

    if (g.phase === "live") {
      var clk = el("div", "gw-clock");
      clk.setAttribute("data-gw-ends", String(g.ends_at));
      clk.appendChild(el("span", "gw-clock-label", t("gw.endsIn")));
      clk.appendChild(el("span", "gw-clock-time", clock(g.ends_at - Date.now() / 1000)));
      main.appendChild(clk);
      ui.clock = clk;
    } else {
      main.appendChild(el("p", "gw-when", t("gw.endedAt", { date: date(g.ends_at) })));
    }

    main.appendChild(el("h3", "gw-h", t("gw.prizes")));
    main.appendChild(renderPrizes(g));
    main.appendChild(renderBody(g));

    if (g.phase === "live") { main.appendChild(renderConditions(g, ui)); }
    if (g.phase === "drawn") { main.appendChild(renderWinners(g)); }
    if (g.phase === "ended") { main.appendChild(el("p", "gw-note", t("gw.waitDraw"))); }

    ui.entries = el("p", "gw-entries", t("gw.entries", { n: g.entries || 0 }));
    main.appendChild(ui.entries);
    root_.appendChild(main);

    if (g.phase === "live") {
      var foot = el("footer", "gw-foot");
      ui.msg = el("p", "gw-msg");
      ui.msg.setAttribute("role", "status");
      ui.msg.setAttribute("aria-live", "polite");
      ui.cta = el("button", "gw-cta", t("gw.join"));
      ui.cta.type = "button";
      foot.appendChild(ui.msg);
      var actions = el("div", "gw-actions");
      actions.appendChild(ui.cta);
      if (opts.mode !== "page") {
        var all = el("a", "gw-all", t("gw.all"));
        all.href = "/giveaways#g" + g.id;
        actions.appendChild(all);
      }
      foot.appendChild(actions);
      root_.appendChild(foot);
      if (opts.preview) { ui.cta.disabled = true; }
      else { wire(root_, g, ui, opts); }
    }
    startTicker();
    return root_;
  }

  function wire(cardEl, g, ui, opts) {
    var st = { joined: false, busy: false, tgUrl: "", poll: 0, pollUntil: 0, user: null };

    function say(key, vars) {
      ui.msg.textContent = key ? t(key, vars) : "";
      ui.msg.classList.toggle("is-bad", !!key && key.indexOf("gw.err.") === 0);
    }

    function setCta(key, disabled) {
      ui.cta.textContent = t(key);
      ui.cta.disabled = !!disabled;
      ui.cta.setAttribute("data-key", key);
    }

    function showJoined() {
      st.joined = true;
      cardEl.classList.add("is-joined");
      mark(ui.cLogin, "ok");
      mark(ui.cTg, "ok");
      Object.keys(ui.cChannels || {}).forEach(function (k) { mark(ui.cChannels[k], "ok"); });
      setCta("gw.joined", true);
      say("gw.joinedNote", { date: date(g.ends_at) });
      if (opts.onJoined) { opts.onJoined(g); }
    }

    function stopPoll() { clearTimeout(st.poll); st.poll = 0; }

    function pollLink() {
      stopPoll();
      if (st.joined || Date.now() > st.pollUntil) { return; }
      st.poll = setTimeout(function () {
        if (document.hidden) { pollLink(); return; }
        post(LINK, { action: "status" }).then(function (r) {
          if (r && r.ok && r.tg && r.tg.linked) {
            mark(ui.cTg, "ok");
            st.tgUrl = "";
            attempt();
            return;
          }
          pollLink();
        }).catch(pollLink);
      }, LINK_POLL_MS);
    }

    function askTg() {
      mark(ui.cLogin, "ok");
      mark(ui.cTg, "bad");
      post(LINK, { action: "link", lang: lang() }).then(function (r) {
        if (r && r.ok && r.url) {
          st.tgUrl = r.url;
          setCta("gw.connectTg");
          say("gw.tgNote");
        } else {
          setCta("gw.join");
          say(r && r.error === "off" ? "gw.err.tg_off" : "gw.err.net");
        }
      }).catch(function () { setCta("gw.join"); say("gw.err.net"); });
    }

    function goLogin() {
      setResume(g.id);
      var auth = root.MKAuth;
      if (!auth) { say("gw.err.net"); return; }
      var ret = location.pathname + location.search + (opts.mode === "page" ? "#g" + g.id : "");
      location.assign(auth.startUrl(ret));
    }

    function attempt() {
      if (st.busy || st.joined) { return; }
      st.busy = true;
      cardEl.classList.add("is-busy");
      ui.cta.disabled = true;
      say("");
      post(ENTER, { id: g.id }).then(function (r) {
        st.busy = false;
        cardEl.classList.remove("is-busy");
        ui.cta.disabled = false;
        if (r && r.ok) {
          markJoined(g.id);
          showJoined();
          return;
        }
        var err = r && r.error ? r.error : "net";
        if (err === "login") { goLogin(); return; }
        if (err === "tg") { askTg(); return; }
        mark(ui.cLogin, "ok");
        if (err === "channels") {
          mark(ui.cTg, "ok");
          var missing = r.missing || [];
          Object.keys(ui.cChannels || {}).forEach(function (k) {
            mark(ui.cChannels[k], missing.indexOf(k) >= 0 ? "bad" : "ok");
          });
          setCta("gw.check");
          say("gw.err.channels");
          return;
        }
        if (err === "closed") { setCta("gw.ended", true); }
        var known = ["tg_used", "banned", "closed", "tg_down", "rate_limited"];
        say("gw.err." + (known.indexOf(err) >= 0 ? err : "net"));
      }).catch(function () {
        st.busy = false;
        cardEl.classList.remove("is-busy");
        ui.cta.disabled = false;
        say("gw.err.net");
      });
    }

    ui.cta.addEventListener("click", function () {
      if (st.tgUrl) {
        root.open(st.tgUrl, "_blank", "noopener");
        say("gw.tgWait");
        st.pollUntil = Date.now() + LINK_POLL_FOR;
        pollLink();
        return;
      }
      if (st.user === false) { goLogin(); return; }
      attempt();
    });

    cardEl.gwEnded = function () {
      stopPoll();
      if (st.joined) { return; }
      cardEl.setAttribute("data-phase", "ended");
      setCta("gw.ended", true);
      say("");
    };

    cardEl.gwJoin = attempt;

    session().then(function (s) {
      st.user = s ? !!s.user : null;
      if (s && s.user) { mark(ui.cLogin, "ok"); }
      if (isJoined(g.id, s)) { showJoined(); return; }
      if (st.user === false) { setCta("gw.joinLogin"); }
    });
  }

  root.NX_GW = {
    load: load,
    session: session,
    card: card,
    isJoined: isJoined,
    takeResume: takeResume,
    lang: lang,
    t: t,
    pick: pick
  };
})(window);
