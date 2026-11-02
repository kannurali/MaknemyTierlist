(function (root) {
  "use strict";

  var STAT = "/api/gw_stat.php";
  var HINT_KEY = "nx-gwc-live-v1";
  var SEEN_MS = 1000;
  var FLUSH_MS = 2500;
  var MAX_BATCH = 12;

  var PLACES = ["tier", "trade", "calc", "stock", "news", "hunt"];

  function src(place) { return "c-" + place; }

  function liveOf(d, nowSec) {
    var live = (d && Array.isArray(d.live)) ? d.live : [];
    return live.filter(function (g) { return g && g.phase === "live" && g.ends_at > nowSec; });
  }

  function choose(d, joined, nowSec) {
    var live = liveOf(d, nowSec);
    if (!live.length) { return null; }
    for (var i = 0; i < live.length; i++) {
      if (!joined(live[i].id)) { return { g: live[i], joined: false, count: live.length }; }
    }
    return { g: live[0], joined: true, count: live.length };
  }

  function feedRef(items, isAd, self) {
    if (!items.length) { return null; }
    if (items.length < 2) { return { after: items[items.length - 1] }; }
    var next = items[1].nextElementSibling;
    if (next && next === self) { next = next.nextElementSibling; }
    if (next && isAd(next) && items.length > 2) { return { after: items[2] }; }
    return { after: items[1] };
  }

  function prizeText(g, pickName) {
    var p = g && Array.isArray(g.prizes) ? g.prizes[0] : null;
    if (!p || !p.name) { return pickName(g); }
    return (p.count > 1 ? p.count + " × " : "") + p.name;
  }

  var api = {
    PLACES: PLACES,
    src: src,
    liveOf: liveOf,
    choose: choose,
    feedRef: feedRef,
    prizeText: prizeText
  };

  if (typeof module === "object" && module.exports) { module.exports = api; return; }
  root.NX_GWC = api;

  var GW = root.NX_GW;
  if (!GW || !root.fetch || !root.Promise) { return; }
  if (root.NX_ADMIN_PAGE || /^\/admin(\/|$)/.test(location.pathname)) { return; }

  var pick = null;
  var nodes = {};
  var queue = [];
  var flushTimer = 0;
  var counted = {};
  var watchers = {};
  var io = null;

  function t(key, vars) { return GW.t(key, vars); }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function num(n) {
    try { return Number(n).toLocaleString(root.I18N ? root.I18N.locale(GW.lang()) : "ru-RU"); }
    catch (_) { return String(n); }
  }

  function readHint() {
    try { return localStorage.getItem(HINT_KEY); } catch (_) { return null; }
  }

  function writeHint(on) {
    try { localStorage.setItem(HINT_KEY, on ? "1" : "0"); } catch (_) {}
  }

  function prize(g) { return prizeText(g, function (x) { return GW.pick(x.title); }); }

  function extra(g) {
    var n = Array.isArray(g.prizes) ? g.prizes.length - 1 : 0;
    return n > 0 ? t("gwc.more." + GW.plural(n), { n: n }) : "";
  }

  function byCount(g) { return g.end_by === "entries" && g.max_entries > 0; }

  function when(g, dash) {
    if (byCount(g)) {
      return t("gwc.endsCount", { n: t("gwc.people." + GW.plural(g.max_entries), { n: num(g.max_entries) }) });
    }
    return t(dash ? "gwc.endsAtDash" : "gwc.endsAt", { date: GW.date(g.ends_at) });
  }

  function spots(g) {
    if (!(g.max_entries > 0)) { return ""; }
    var left = Math.max(0, g.max_entries - (g.entries || 0));
    return left ? t("gw.spotsLeft." + GW.plural(left), { n: num(left) }) : t("gw.spotsNone");
  }

  function arrow() {
    var s = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    s.setAttribute("viewBox", "0 0 20 20");
    s.setAttribute("aria-hidden", "true");
    s.setAttribute("focusable", "false");
    s.setAttribute("class", "gwc-arrow");
    var p = document.createElementNS("http://www.w3.org/2000/svg", "path");
    p.setAttribute("d", "M4 10h11M10.5 5.5 15 10l-4.5 4.5");
    s.appendChild(p);
    return s;
  }

  function button(key) {
    var b = el("span", "gwc-btn");
    b.appendChild(el("span", "gwc-btn-text", t(key)));
    b.appendChild(arrow());
    return b;
  }

  function pic(g) {
    var p = Array.isArray(g.prizes) ? g.prizes[0] : null;
    return GW.prizePic(p, "gwc-pic");
  }

  function check() {
    var s = document.createElementNS("http://www.w3.org/2000/svg", "svg");
    s.setAttribute("viewBox", "0 0 16 16");
    s.setAttribute("aria-hidden", "true");
    s.setAttribute("focusable", "false");
    s.setAttribute("class", "gwc-check");
    var p = document.createElementNS("http://www.w3.org/2000/svg", "path");
    p.setAttribute("d", "M3.5 8.4 6.6 11.4 12.6 4.8");
    s.appendChild(p);
    return s;
  }

  function joinedMark() {
    var m = el("span", "gwc-joined");
    m.appendChild(check());
    m.appendChild(el("span", null, t("gw.joined")));
    return m;
  }

  function meta(g, more) {
    var box = el("span", "gwc-meta");
    var add = function (cls, text) { if (text) { box.appendChild(el("span", cls, text)); } };
    if (more) { add("gwc-extra", extra(g)); }
    add("gwc-when" + (byCount(g) ? " gwc-count" : ""), when(g));
    add("gwc-spots gwc-count", spots(g));
    return box;
  }

  function rich(cls, key, vars) {
    var mark = String.fromCharCode(1);
    var copy = {};
    Object.keys(vars).forEach(function (k) { copy[k] = vars[k]; });
    copy.prize = mark;
    var box = el("span", cls);
    t(key, copy).split(mark).forEach(function (part, i) {
      if (i) { box.appendChild(el("b", "gwc-prize", vars.prize)); }
      if (part) { box.appendChild(document.createTextNode(part)); }
    });
    return box;
  }

  function tag(key) {
    var k = el("span", "gwc-kicker");
    k.appendChild(GW.giftIcon("gwc-gift"));
    k.appendChild(el("span", null, t(key)));
    return k;
  }

  var BUILD = {
    tier: function (g) {
      var body = el("span", "gwc-body");
      var line = el("span", "gwc-line");
      line.appendChild(GW.giftIcon("gwc-gift"));
      line.appendChild(el("b", "gwc-title", t("gwc.tierLine", { prize: prize(g) })));
      body.appendChild(line);
      body.appendChild(meta(g, false));
      return [pic(g), body, button("gwc.see")];
    },
    trade: function (g) {
      var body = el("span", "gwc-body");
      body.appendChild(tag("gwc.tradeHead"));
      var text = rich("gwc-text", "gwc.tradeLead", { prize: prize(g) });
      text.appendChild(el("span", "gwc-when" + (byCount(g) ? " gwc-count" : ""), " " + when(g, true) + "."));
      body.appendChild(text);
      var sp = spots(g);
      if (sp) { body.appendChild(el("span", "gwc-spots gwc-count", sp)); }
      return [pic(g), body, button("gwc.open")];
    },
    calc: function (g) {
      var body = el("span", "gwc-body");
      body.appendChild(rich("gwc-text", "gwc.calcText", { prize: prize(g) }));
      return [pic(g), body, button("gwc.watch")];
    },
    stock: function (g, info) {
      var many = info.count > 1 || (Array.isArray(g.prizes) && g.prizes.length > 1);
      var body = el("span", "gwc-body");
      body.appendChild(rich("gwc-text", many ? "gwc.stockText" : "gwc.stockTextOne", { prize: prize(g) }));
      return [pic(g), body, button("gwc.seeAll")];
    },
    news: function (g) {
      var body = el("span", "gwc-body");
      body.appendChild(tag("gwc.kicker"));
      body.appendChild(el("b", "gwc-title", prize(g)));
      body.appendChild(meta(g, true));
      return [pic(g), body, button("gwc.see")];
    },
    hunt: function (g) {
      var body = el("span", "gwc-body");
      body.appendChild(tag("gwc.huntKicker"));
      body.appendChild(el("b", "gwc-title", prize(g)));
      body.appendChild(meta(g, true));
      return [pic(g), body, button("gwc.huntBtn")];
    }
  };

  var IDLE = {
    hunt: function () {
      var body = el("span", "gwc-body");
      body.appendChild(tag("gwc.huntKicker"));
      body.appendChild(el("b", "gwc-title", t("gwc.huntIdle")));
      var m = el("span", "gwc-meta");
      m.appendChild(el("span", "gwc-when", t("gwc.huntIdleText")));
      body.appendChild(m);
      return [GW.prizePic(null, "gwc-pic"), body, button("gwc.huntBtn")];
    }
  };

  function siteUrl(path) { return root.I18N && root.I18N.url ? root.I18N.url(path) : path; }

  function hrefFor(place, g) {
    return siteUrl(place === "stock" || place === "hunt" ? "/giveaways" : "/giveaways#g" + g.id);
  }

  function idle(shell, place) {
    shell.hidden = false;
    var a = el("a", "gwc gwc--" + place + " is-idle");
    a.href = siteUrl("/giveaways");
    IDLE[place]().forEach(function (n) { a.appendChild(n); });
    shell.appendChild(a);
  }

  function shellFor(place) {
    var shell = el(place === "trade" ? "li" : "div", "gwc-slot gwc-slot--" + place);
    if (place === "tier") { shell.classList.add("ptn-export-hide"); }
    shell.setAttribute("data-gwc-place", place);
    return shell;
  }

  function fill(shell, place) {
    shell.textContent = "";
    shell.classList.remove("is-wait");
    if (!pick && IDLE[place]) { idle(shell, place); return; }
    if (!pick) { shell.hidden = true; return; }
    shell.hidden = false;
    var g = pick.g;
    var a = el("a", "gwc gwc--" + place + (pick.joined ? " is-joined" : ""));
    a.href = hrefFor(place, g);
    a.setAttribute("data-gwc-id", String(g.id));
    BUILD[place](g, pick).forEach(function (n) { if (n) { a.appendChild(n); } });
    if (pick.joined && place !== "stock") {
      var body = a.querySelector(".gwc-body");
      if (body) { body.appendChild(joinedMark()); }
    }
    a.addEventListener("click", function () { onClick(place, g.id); });
    shell.appendChild(a);
    watch(shell, place, g.id);
  }

  function nodeFor(place) {
    if (!nodes[place]) {
      nodes[place] = shellFor(place);
      if (pick === null && readHint() !== "0") { nodes[place].classList.add("is-wait"); }
    }
    return nodes[place];
  }

  function send(events) {
    if (!events.length) { return; }
    var body = JSON.stringify({ e: events });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(STAT, new Blob([body], { type: "application/json" }))) { return; }
    } catch (_) {}
    try {
      fetch(STAT, { method: "POST", keepalive: true, credentials: "same-origin",
        headers: { "Content-Type": "application/json" }, body: body }).catch(function () {});
    } catch (_) {}
  }

  function flush() {
    clearTimeout(flushTimer);
    flushTimer = 0;
    while (queue.length) { send(queue.splice(0, MAX_BATCH)); }
  }

  function track(place, id, kind) {
    queue.push({ id: id, p: place, k: kind });
    if (kind === "c") { flush(); return; }
    if (!flushTimer) { flushTimer = setTimeout(flush, FLUSH_MS); }
  }

  function goal(name, place, id) {
    if (root.PROMO && root.PROMO.goal) { root.PROMO.goal(name, { id: "gw" + id, place: place }); }
  }

  function onClick(place, id) {
    GW.noteFromCard(id, src(place));
    track(place, id, "c");
    goal("gw_card_click", place, id);
  }

  function countView(place, id) {
    var key = place + ":" + id;
    if (counted[key]) { return; }
    counted[key] = true;
    track(place, id, "v");
    goal("gw_card_view", place, id);
  }

  function watch(shell, place, id) {
    if (!("IntersectionObserver" in root)) { countView(place, id); return; }
    if (!io) {
      io = new IntersectionObserver(function (list) {
        list.forEach(function (e) {
          var n = e.target;
          var p = n.getAttribute("data-gwc-place");
          var gid = +n.getAttribute("data-gwc-gid");
          if (!p || !gid) { return; }
          if (e.isIntersecting && e.intersectionRatio >= 0.5 && document.visibilityState === "visible") {
            if (!watchers[p]) { watchers[p] = setTimeout(function () { watchers[p] = 0; countView(p, gid); }, SEEN_MS); }
          } else if (watchers[p]) {
            clearTimeout(watchers[p]);
            watchers[p] = 0;
          }
        });
      }, { threshold: [0, 0.5, 1] });
    }
    shell.setAttribute("data-gwc-gid", String(id));
    io.unobserve(shell);
    io.observe(shell);
  }

  function isAd(n) { return n.hasAttribute("data-ptn-k"); }

  function placeTiers(box) {
    var stage = document.getElementById("stage");
    var off = !pick && readHint() === "0";
    var editing = stage && stage.classList.contains("editing");
    var first = Array.prototype.filter.call(box.children, function (n) { return n.classList.contains("tier"); })[0];
    var node = nodeFor("tier");
    if (editing || off || !first) { if (node.parentNode) { node.parentNode.removeChild(node); } return; }
    if (node.nextElementSibling !== first || node.parentNode !== box) { box.insertBefore(node, first); }
  }

  function placeFeed(box, sel) {
    var node = nodeFor("trade");
    var items = Array.prototype.filter.call(box.children, function (n) { return n !== node && n.matches(sel); });
    var off = !pick && readHint() === "0";
    var ref = off ? null : feedRef(items, isAd, node);
    if (!ref) { if (node.parentNode) { node.parentNode.removeChild(node); } return; }
    if (node.previousElementSibling === ref.after && node.parentNode === box) { return; }
    box.insertBefore(node, ref.after.nextSibling);
  }

  function observe(box, fn) {
    fn();
    if (root.MutationObserver) {
      new MutationObserver(fn).observe(box, { childList: true });
    }
  }

  function mountAll() {
    document.querySelectorAll("[data-gwc]").forEach(function (slot) {
      var place = slot.getAttribute("data-gwc");
      if (PLACES.indexOf(place) < 0 || nodes[place]) { return; }
      slot.classList.add("gwc-slot", "gwc-slot--" + place);
      slot.setAttribute("data-gwc-place", place);
      if (readHint() !== "0") { slot.classList.add("is-wait"); }
      nodes[place] = slot;
    });
    var tiers = document.querySelector("[data-gwc-tiers]");
    if (tiers) { observe(tiers, function () { placeTiers(tiers); }); }
    var feed = document.querySelector("[data-gwc-feed]");
    if (feed) {
      var sel = feed.getAttribute("data-gwc-feed") || "li";
      observe(feed, function () { placeFeed(feed, sel); });
    }
  }

  function render() {
    Object.keys(nodes).forEach(function (place) { fill(nodes[place], place); });
    var tiers = document.querySelector("[data-gwc-tiers]");
    if (tiers) { placeTiers(tiers); }
    var feed = document.querySelector("[data-gwc-feed]");
    if (feed) { placeFeed(feed, feed.getAttribute("data-gwc-feed") || "li"); }
  }

  function sig(p) { return p ? p.g.id + ":" + p.joined + ":" + p.count : ""; }

  function start() {
    mountAll();
    GW.load().then(function (d) {
      var next = function (s) { return choose(d, function (id) { return GW.isJoined(id, s); }, Date.now() / 1000) || false; };
      pick = next(null);
      writeHint(!!pick);
      render();
      GW.session().then(function (s) {
        var p = next(s);
        if (sig(p) !== sig(pick)) { pick = p; render(); }
      });
    });
    document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") { flush(); } });
    root.addEventListener("pagehide", flush);
    var relang = function () { if (pick !== null) { render(); } };
    document.addEventListener("mk:langchange", relang);
    if (root.MutationObserver) {
      new MutationObserver(relang).observe(document.documentElement, { attributes: true, attributeFilter: ["lang"] });
    }
  }

  if (document.readyState === "loading") { document.addEventListener("DOMContentLoaded", start); }
  else { start(); }
})(typeof globalThis !== "undefined" ? globalThis : this);
