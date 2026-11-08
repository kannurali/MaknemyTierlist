(function () {
  "use strict";

  var tg = window.Telegram && window.Telegram.WebApp;
  var root = document.getElementById("tc");
  if (!root) { return; }

  var API = "api/cm_chess.php";
  var SVGNS = "http://www.w3.org/2000/svg";
  var PAL = { dark: "#0d0613", light: "#f3eef9" };
  var FILES = "abcdefgh";
  var POLL = 2000;
  var VAL = { q: 9, r: 5, b: 3, n: 3, p: 1, k: 0 };
  var START = { q: 1, r: 2, b: 2, n: 2, p: 8 };
  var ORDER = ["q", "r", "b", "n", "p"];
  var PROMO = ["q", "r", "b", "n"];
  var NAME = { k: "король", q: "ферзь", r: "ладья", b: "слон", n: "конь", p: "пешка" };
  var FEM = { r: true, p: true };
  var AVA = ["#8b00ff", "#b03cf0", "#5a3df0", "#d6338a", "#e0730c", "#1f9e95"];

  var SHAPES = {
    p: [
      ["b", "p", "M34 80C36 68 42 60 44.5 52h11C58 60 64 68 66 80z", "M50 52h5.5C58 60 64 68 66 80H50z"],
      ["b", "c", 50, 33, 12.5],
      ["b", "r", 37, 45, 26, 8, 4],
      ["b", "r", 25, 79, 50, 11, 5]
    ],
    r: [
      ["b", "p", "M34 80l3-34h26l3 34z", "M50 46h13l3 34H50z"],
      ["b", "p", "M30 19h9.5v7.5h6V19h9v7.5h6V19H70v19l-4 6H34l-4-6z", "M50 19h4.5v7.5h6V19H70v19l-4 6H50z"],
      ["b", "r", 33.5, 42, 33, 7, 3.5],
      ["b", "r", 23, 79, 54, 11, 5],
      ["l", "p", "M31 36h38"]
    ],
    b: [
      ["b", "p", "M34 80C36 68 41 60 43.5 53h13C59 60 64 68 66 80z", "M50 53h6.5C59 60 64 68 66 80H50z"],
      ["b", "c", 50, 13.5, 5],
      ["b", "p", "M50 17C61 25 66 35 63 44c-1.5 3.5-5 5-13 5s-11.5-1.5-13-5c-3-9 2-19 13-27z", "M50 17C61 25 66 35 63 44c-1.5 3.5-5 5-13 5z"],
      ["b", "r", 36, 47, 28, 8, 4],
      ["b", "r", 24, 79, 52, 11, 5],
      ["l", "p", "M57.5 26.5L48 37.5"]
    ],
    n: [
      ["b", "p", "M48 18L54 8.5L59 20z", ""],
      ["b", "p", "M31 80C31 70 36 63 42 57C38 58 33 61 27 61C22 61 17 58 17 53C17 49 19 46 22 43C26 37 31 30 36 24C38 21 41 18 43 17L46 7L52 16C62 19 70 28 73 42C76 56 75 68 71 80z", "M52 16C62 19 70 28 73 42C76 56 75 68 71 80H60C64 66 64 50 59 37C57 31 55 22 52 16z"],
      ["b", "r", 22, 79, 56, 11, 5],
      ["d", "c", 36.5, 31, 2.6],
      ["d", "c", 22, 52, 1.8],
      ["l", "p", "M55 19.5c8 4 13 12 14.5 24"]
    ],
    q: [
      ["b", "p", "M32 80C34 68 38 59 40 51h20C62 59 66 68 68 80z", "M50 51h10C62 59 66 68 68 80H50z"],
      ["b", "p", "M35 48L25 24l12 12 1-18 8 15 4-19 4 19 8-15 1 18 12-12-10 24z", "M50 14L54 33L62 18L63 36L75 24L65 48H50z"],
      ["b", "c", 25, 21.5, 4.2],
      ["b", "c", 38, 15.5, 4.2],
      ["b", "c", 50, 11, 4.2],
      ["b", "c", 62, 15.5, 4.2],
      ["b", "c", 75, 21.5, 4.2],
      ["b", "r", 34, 45, 32, 8, 4],
      ["b", "r", 21, 79, 58, 11, 5]
    ],
    k: [
      ["b", "p", "M31 80C33 67 37 58 39 50h22C63 58 67 67 69 80z", "M50 50h11C63 58 67 67 69 80H50z"],
      ["b", "p", "M46.5 7h7v6.5h6.5v7h-6.5V29h-7v-8.5H40v-7h6.5z", "M50 7h3.5v6.5H60v7h-6.5V29H50z"],
      ["b", "p", "M37 46c-4-9 0-19 13-19s17 10 13 19z", "M50 27C63 27 67 37 63 46H50z"],
      ["b", "r", 34, 44, 32, 8, 4],
      ["b", "r", 21, 79, 58, 11, 5],
      ["l", "p", "M37.5 38.5Q50 42 62.5 38.5"]
    ]
  };
  var INK = {
    w: { fill: "#fdfaff", shade: "#e2d5f2", line: "#1d0f2c", detail: "#1d0f2c", rim: "" },
    b: { fill: "#3b2852", shade: "#22142f", line: "#0c0614", detail: "#d8c6f2", rim: "rgba(236,226,252,.72)" }
  };

  var ICONS = {
    flag: [["p", "M6 21V4"], ["p", "M6 4.5c4-2 7 2 12 0v9c-5 2-8-2-12 0"]],
    eye: [["p", "M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"], ["c", 12, 12, 3]],
    wifi: [["p", "M3 9.5a13 13 0 0 1 18 0"], ["p", "M6.5 13a8 8 0 0 1 11 0"], ["p", "M10 16.5a3 3 0 0 1 4 0"], ["p", "M4 4l16 16"]]
  };

  var T = {
    outTitle: "Откройте партию из Telegram",
    outSub: "Кнопкой «Открыть доску» под сообщением бота.",
    authTitle: "Откройте партию заново из Telegram",
    authSub: "Ссылка устарела. Нажмите «Открыть доску» под сообщением бота ещё раз.",
    noneTitle: "Партия не найдена",
    noneSub: "Проверьте ссылку или начните новую партию в чате.",
    close: "Закрыть",
    myTurn: "Ваш ход",
    myCheck: "Шах — ваш ход",
    oppTurn: "Ход соперника",
    wTurn: "Ход белых",
    bTurn: "Ход чёрных",
    watch: "Вы смотрите партию",
    firstMe: "Сделайте первый ход",
    firstOpp: "Ждём первый ход соперника",
    firstW: "Ждём первый ход белых",
    firstB: "Ждём первый ход чёрных",
    left: " — осталось ",
    dot: " · ",
    draw: "Предложить ничью",
    drawSent: "Вы предложили ничью",
    resign: "Сдаться",
    resignAsk: "Сдаться? Партия закончится вашим поражением.",
    cancel: "Отмена",
    offer: "Соперник предлагает ничью",
    accept: "Принять",
    decline: "Отклонить",
    look: "Посмотреть доску",
    result: "Итог партии",
    noMoves: "Здесь появятся ходы партии",
    noneMoved: "Ходов не было",
    you: "вы",
    white: "Белые",
    black: "Чёрные",
    net: "Нет связи — пробуем снова",
    noSend: "Нет связи — ход не отправлен",
    rate: "Слишком часто — подождите пару секунд",
    illegal: "Так ходить нельзя",
    turn: "Сейчас ход соперника",
    over: "Партия уже закончилась",
    fail: "Не получилось — попробуйте ещё раз",
    board: "Шахматная доска",
    promo: "Во что превратить пешку",
    promoCancel: "Отменить превращение",
    to: ", сюда можно пойти",
    take: ", можно взять",
    picked: ", выбрано"
  };

  var gameId = 0;
  var st = null;
  var view = null;
  var offset = 0;
  var orient = "";
  var pending = false;
  var inflight = false;
  var seq = 0;
  var moveSeq = 0;
  var pollTimer = 0;
  var tickTimer = 0;
  var fails = 0;
  var stopped = false;
  var photosAsked = false;
  var endShown = false;
  var sel = -1;
  var targets = null;
  var focusIdx = -1;
  var down = null;
  var promo = null;
  var zeroAsked = false;
  var firstSpan = {};
  var ui = null;
  var tpl = {};
  var glyphs = {};
  var pcs = {};
  var actKey = "";
  var movesKey = "";
  var toastTimer = 0;
  var busyAct = "";

  function call(fn) {
    try { fn(); } catch (e) {}
  }

  function ver(v) {
    try { return !!(tg && typeof tg.isVersionAtLeast === "function" && tg.isVersionAtLeast(v)); } catch (e) { return false; }
  }

  function inTelegram() {
    return !!(tg && typeof tg.initData === "string" && tg.initData !== "");
  }

  function calm() {
    try { return !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches); } catch (e) { return false; }
  }

  function raf(fn) {
    if (typeof window.requestAnimationFrame === "function") { return window.requestAnimationFrame(fn); }
    return setTimeout(fn, 16);
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = String(text); }
    return n;
  }

  function btn(cls, text) {
    var b = el("button", cls, text);
    b.type = "button";
    return b;
  }

  function drop(n) {
    if (n && n.parentNode) { n.parentNode.removeChild(n); }
  }

  function clear(n) {
    while (n && n.firstChild) { n.removeChild(n.firstChild); }
  }

  function svg(view) {
    var s = document.createElementNS(SVGNS, "svg");
    s.setAttribute("viewBox", view);
    s.setAttribute("aria-hidden", "true");
    s.setAttribute("focusable", "false");
    return s;
  }

  function icon(name) {
    var s = svg("0 0 24 24");
    s.setAttribute("class", "tc-ico");
    (ICONS[name] || []).forEach(function (d) {
      var n;
      if (d[0] === "p") {
        n = document.createElementNS(SVGNS, "path");
        n.setAttribute("d", d[1]);
      } else {
        n = document.createElementNS(SVGNS, "circle");
        n.setAttribute("cx", d[1]); n.setAttribute("cy", d[2]); n.setAttribute("r", d[3]);
      }
      s.appendChild(n);
    });
    return s;
  }

  function shapeNode(s) {
    var n;
    if (s[1] === "p") {
      n = document.createElementNS(SVGNS, "path");
      n.setAttribute("d", s[2]);
    } else if (s[1] === "c") {
      n = document.createElementNS(SVGNS, "circle");
      n.setAttribute("cx", s[2]); n.setAttribute("cy", s[3]); n.setAttribute("r", s[4]);
    } else {
      n = document.createElementNS(SVGNS, "rect");
      n.setAttribute("x", s[2]); n.setAttribute("y", s[3]);
      n.setAttribute("width", s[4]); n.setAttribute("height", s[5]); n.setAttribute("rx", s[6]);
    }
    return n;
  }

  function shadeOf(s) {
    if (s[1] === "p") { return s[3] || ""; }
    if (s[1] === "c") {
      return "M" + s[2] + " " + (s[3] - s[4]) + "A" + s[4] + " " + s[4] + " 0 0 1 " + s[2] + " " + (s[3] + s[4]) + "z";
    }
    var x = s[2], y = s[3], w = s[4], h = s[5], r = s[6], m = x + w / 2;
    return "M" + m + " " + y + "H" + (x + w - r) + "A" + r + " " + r + " 0 0 1 " + (x + w) + " " + (y + r) +
      "V" + (y + h - r) + "A" + r + " " + r + " 0 0 1 " + (x + w - r) + " " + (y + h) + "H" + m + "z";
  }

  function group(attrs) {
    var g = document.createElementNS(SVGNS, "g");
    for (var k in attrs) { if (attrs.hasOwnProperty(k)) { g.setAttribute(k, attrs[k]); } }
    return g;
  }

  function drawPiece(code) {
    var side = sideOf(code);
    var list = SHAPES[code.toLowerCase()];
    var ink = INK[side];
    var s = svg("0 0 100 100");
    var shadow = group({ transform: "translate(1.5 2.5)", fill: "rgba(8,3,14,.28)", stroke: "rgba(8,3,14,.28)", "stroke-width": "3", "stroke-linejoin": "round" });
    list.forEach(function (d) { if (d[0] === "b") { shadow.appendChild(shapeNode(d)); } });
    s.appendChild(shadow);
    if (ink.rim) {
      var rim = group({ fill: ink.rim, stroke: ink.rim, "stroke-width": "7.5", "stroke-linejoin": "round" });
      list.forEach(function (d) { if (d[0] === "b") { rim.appendChild(shapeNode(d)); } });
      s.appendChild(rim);
    }
    var g = group({ "stroke-width": "3", "stroke-linejoin": "round", "stroke-linecap": "round" });
    list.forEach(function (d) {
      var n = shapeNode(d);
      if (d[0] === "b") {
        n.setAttribute("fill", ink.fill);
        g.appendChild(n);
        var sd = shadeOf(d);
        if (sd) {
          var sp = document.createElementNS(SVGNS, "path");
          sp.setAttribute("d", sd);
          sp.setAttribute("fill", ink.shade);
          g.appendChild(sp);
        }
        var o = shapeNode(d);
        o.setAttribute("fill", "none");
        o.setAttribute("stroke", ink.line);
        g.appendChild(o);
      } else if (d[0] === "l") {
        n.setAttribute("fill", "none");
        n.setAttribute("stroke", ink.detail);
        n.setAttribute("stroke-width", "2.6");
        g.appendChild(n);
      } else {
        n.setAttribute("fill", ink.detail);
        g.appendChild(n);
      }
    });
    s.appendChild(g);
    return s;
  }

  function piece(code) {
    if (!tpl[code]) { tpl[code] = drawPiece(code); }
    return tpl[code].cloneNode(true);
  }

  function glyph(t) {
    if (!glyphs[t]) {
      var s = svg("0 0 100 100");
      s.setAttribute("class", "tc-fig");
      var g = group({ fill: "currentColor", stroke: "currentColor", "stroke-width": "4", "stroke-linejoin": "round" });
      SHAPES[t].forEach(function (d) { if (d[0] === "b") { g.appendChild(shapeNode(d)); } });
      s.appendChild(g);
      glyphs[t] = s;
    }
    return glyphs[t].cloneNode(true);
  }

  function haptic(kind, style) {
    if (!tg || !tg.HapticFeedback || !ver("6.1")) { return; }
    call(function () {
      if (kind === "impact") { tg.HapticFeedback.impactOccurred(style || "light"); }
      else if (kind === "select") { tg.HapticFeedback.selectionChanged(); }
      else { tg.HapticFeedback.notificationOccurred(style); }
    });
  }

  function sideOf(code) {
    return code === code.toUpperCase() ? "w" : "b";
  }

  function other(side) {
    return side === "w" ? "b" : "w";
  }

  function idx(name) {
    if (typeof name !== "string" || name.length < 2) { return -1; }
    var f = FILES.indexOf(name.charAt(0));
    var r = parseInt(name.charAt(1), 10);
    if (f < 0 || !(r >= 1 && r <= 8)) { return -1; }
    return (8 - r) * 8 + f;
  }

  function sqName(i) {
    return FILES.charAt(i % 8) + (8 - Math.floor(i / 8));
  }

  function disp(i) {
    var r = Math.floor(i / 8), f = i % 8;
    return orient === "b" ? { row: 7 - r, col: 7 - f } : { row: r, col: f };
  }

  function fromDisp(row, col) {
    return orient === "b" ? (7 - row) * 8 + (7 - col) : row * 8 + col;
  }

  function parseFen(fen) {
    var b = [];
    var rows = String(fen || "").split(" ")[0].split("/");
    for (var r = 0; r < 8; r++) {
      var row = rows[r] || "";
      for (var k = 0; k < row.length && b.length < (r + 1) * 8; k++) {
        var ch = row.charAt(k);
        if (/[1-8]/.test(ch)) {
          for (var e = parseInt(ch, 10); e > 0 && b.length < (r + 1) * 8; e--) { b.push(""); }
        } else if (/[prnbqkPRNBQK]/.test(ch)) {
          b.push(ch);
        }
      }
      while (b.length < (r + 1) * 8) { b.push(""); }
    }
    return b;
  }

  function legalMap(list) {
    var m = {};
    if (!list || !list.length) { return null; }
    list.forEach(function (u) {
      if (typeof u !== "string" || u.length < 4) { return; }
      var f = idx(u.slice(0, 2)), t = idx(u.slice(2, 4));
      if (f < 0 || t < 0) { return; }
      if (!m[f]) { m[f] = {}; }
      if (!m[f][t]) { m[f][t] = []; }
      m[f][t].push(u);
    });
    return m;
  }

  function applyUci(board, uci) {
    var b = board.slice();
    var f = idx(uci.slice(0, 2)), t = idx(uci.slice(2, 4));
    var pc = b[f];
    if (!pc) { return b; }
    var kind = pc.toLowerCase();
    var df = (t % 8) - (f % 8);
    if (kind === "p" && df !== 0 && !b[t]) { b[Math.floor(f / 8) * 8 + (t % 8)] = ""; }
    if (kind === "k" && Math.abs(df) === 2) {
      var row = Math.floor(f / 8) * 8;
      var rf = df > 0 ? row + 7 : row;
      var rt = df > 0 ? t - 1 : t + 1;
      b[rt] = b[rf];
      b[rf] = "";
    }
    if (uci.length > 4 && kind === "p") {
      var pr = uci.charAt(4);
      pc = sideOf(pc) === "w" ? pr.toUpperCase() : pr.toLowerCase();
    }
    b[f] = "";
    b[t] = pc;
    return b;
  }

  function material(board) {
    var cnt = { w: { q: 0, r: 0, b: 0, n: 0, p: 0, k: 0 }, b: { q: 0, r: 0, b: 0, n: 0, p: 0, k: 0 } };
    board.forEach(function (c) { if (c) { cnt[sideOf(c)][c.toLowerCase()]++; } });
    var score = { w: 0, b: 0 };
    var caps = { w: [], b: [] };
    ["w", "b"].forEach(function (s) {
      ORDER.forEach(function (t) { score[s] += cnt[s][t] * VAL[t]; });
    });
    ORDER.forEach(function (t) {
      var lostB = Math.max(0, START[t] - cnt.b[t]);
      var lostW = Math.max(0, START[t] - cnt.w[t]);
      for (var k = 0; k < lostB; k++) { caps.w.push(t); }
      for (var j = 0; j < lostW; j++) { caps.b.push(t.toUpperCase()); }
    });
    return { caps: caps, diff: score.w - score.b };
  }

  function player(side) {
    var p = st && (side === "w" ? st.white : st.black);
    return p && typeof p === "object" ? p : {};
  }

  function nameOf(side) {
    var n = player(side).name;
    n = typeof n === "string" ? n.replace(/\s+/g, " ").trim() : "";
    return n || (side === "w" ? T.white : T.black);
  }

  function hash(s) {
    var h = 0;
    for (var i = 0; i < s.length; i++) { h = (h * 31 + s.charCodeAt(i)) | 0; }
    return Math.abs(h);
  }

  function safeImg(u) {
    return typeof u === "string" && /^https:\/\/(maknemy\.com|t\.me)\/[^\s"'()<>]+$/i.test(u) ? u : "";
  }

  function pad(n) {
    return n < 10 ? "0" + n : String(n);
  }

  function fmtClock(ms) {
    ms = Math.max(0, ms);
    if (ms < 10000) {
      var t = Math.floor(ms / 100);
      return "00:0" + Math.floor(t / 10) + "." + (t % 10);
    }
    var s = Math.floor(ms / 1000);
    return pad(Math.floor(s / 60)) + ":" + pad(s % 60);
  }

  function fmtShort(ms) {
    var s = Math.max(0, Math.ceil(ms / 1000));
    return Math.floor(s / 60) + ":" + pad(s % 60);
  }

  function now() {
    return Date.now() + offset;
  }

  function clockLeft(side) {
    if (view && view.frozen && view.held) { return view.held[side]; }
    var c = st && st.clock;
    if (!c || typeof c[side] !== "number") { return 0; }
    var v = c[side];
    if (st.status === "on" && c.run === side && !(view && view.frozen)) { v -= now() - st.now; }
    return Math.max(0, v);
  }

  function firstInfo() {
    var f = st && st.first;
    if (!f || st.status !== "on" || (f.side !== "w" && f.side !== "b") || typeof f.until !== "number") { return null; }
    if (view && view.frozen) { return null; }
    return f;
  }

  function scheme() {
    var s = tg && tg.colorScheme === "light" ? "light" : "dark";
    document.documentElement.setAttribute("data-scheme", s);
    if (!inTelegram()) { return; }
    var c = PAL[s];
    if (ver("6.1")) {
      call(function () { tg.setBackgroundColor(c); });
      if (ver("6.9")) { call(function () { tg.setHeaderColor(c); }); }
      else { call(function () { tg.setHeaderColor("secondary_bg_color"); }); }
    }
    if (ver("7.10") && typeof tg.setBottomBarColor === "function") {
      call(function () { tg.setBottomBarColor(c); });
    }
  }

  function readGame() {
    var key = "";
    try { key = new URLSearchParams(window.location.search).get("tgWebAppStartParam") || ""; } catch (e) { key = ""; }
    if (!key && tg && tg.initDataUnsafe && typeof tg.initDataUnsafe.start_param === "string") {
      key = tg.initDataUnsafe.start_param;
    }
    var m = /^ch([1-9][0-9]{0,9})$/.exec(key);
    return m ? parseInt(m[1], 10) : 0;
  }

  function post(body) {
    body.init = tg.initData;
    body.game = gameId;
    var p;
    try {
      p = fetch(API, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
        credentials: "same-origin",
        cache: "no-store"
      });
    } catch (e) {
      p = Promise.reject(e);
    }
    return p.then(function (r) {
      return r.json().catch(function () { return null; }).then(function (j) {
        return { status: r.status, data: j && typeof j === "object" ? j : {} };
      });
    });
  }

  function isState(j) {
    return !!(j && typeof j.fen === "string" && (j.turn === "w" || j.turn === "b"));
  }

  function classify(res) {
    var j = res.data;
    if (res.status === 200 && j.ok !== false && isState(j)) { return { ok: true, state: j }; }
    if (res.status === 403 || j.error === "auth") { return { fatal: "auth" }; }
    if (res.status === 404 || j.error === "none") { return { fatal: "none" }; }
    if (res.status === 429 || j.error === "rate") { return { rate: true, state: isState(j.state) ? j.state : null }; }
    if (res.status === 409 && isState(j.state)) { return { code: j.error, state: j.state }; }
    return { error: true };
  }

  function screen(kind) {
    stopped = true;
    clearTimeout(pollTimer);
    clearInterval(tickTimer);
    clear(root);
    root.className = "tc is-screen";
    root.setAttribute("aria-busy", "false");
    var box = el("section", "tc-screen");
    var art = el("div", "tc-screen-art");
    art.appendChild(piece(kind === "none" ? "n" : "N"));
    box.appendChild(art);
    var title = kind === "auth" ? T.authTitle : kind === "none" ? T.noneTitle : T.outTitle;
    var sub = kind === "auth" ? T.authSub : kind === "none" ? T.noneSub : T.outSub;
    box.appendChild(el("h1", "tc-screen-title", title));
    box.appendChild(el("p", "tc-screen-sub", sub));
    if (inTelegram()) {
      var b = btn("tc-btn is-main", T.close);
      b.addEventListener("click", closeApp);
      box.appendChild(b);
    }
    root.appendChild(box);
  }

  function closeApp() {
    if (tg && typeof tg.close === "function") { call(function () { tg.close(); }); }
  }

  function skeleton() {
    clear(root);
    root.className = "tc";
    var game = el("div", "tc-game is-skel");
    game.setAttribute("aria-hidden", "true");
    var col = el("div", "tc-col");
    function bar() {
      var b = el("div", "tc-bar");
      b.appendChild(el("span", "tc-sk tc-sk-ava"));
      var who = el("span", "tc-sk-who");
      who.appendChild(el("span", "tc-sk tc-sk-name"));
      who.appendChild(el("span", "tc-sk tc-sk-cap"));
      b.appendChild(who);
      b.appendChild(el("span", "tc-sk tc-sk-clock"));
      return b;
    }
    col.appendChild(bar());
    var bw = el("div", "tc-bw");
    bw.appendChild(el("div", "tc-board tc-sk-board"));
    col.appendChild(bw);
    col.appendChild(bar());
    game.appendChild(col);
    var side = el("div", "tc-side");
    side.appendChild(el("span", "tc-sk tc-sk-line"));
    side.appendChild(el("span", "tc-sk tc-sk-line is-short"));
    var acts = el("div", "tc-actions");
    acts.appendChild(el("span", "tc-sk tc-sk-btn"));
    acts.appendChild(el("span", "tc-sk tc-sk-btn"));
    side.appendChild(acts);
    game.appendChild(side);
    root.appendChild(game);
    root.appendChild(el("p", "tc-sr", "Загружаем партию"));
  }

  function makeBar() {
    var b = { root: el("div", "tc-bar") };
    b.ava = el("span", "tc-ava");
    b.letter = el("span", "tc-ava-l");
    b.ava.appendChild(b.letter);
    b.pip = el("span", "tc-pip");
    b.ava.appendChild(b.pip);
    b.who = el("div", "tc-who");
    var line = el("div", "tc-nameline");
    b.name = el("span", "tc-name");
    b.you = el("span", "tc-you", T.you);
    line.appendChild(b.name);
    line.appendChild(b.you);
    b.cap = el("div", "tc-cap");
    b.who.appendChild(line);
    b.who.appendChild(b.cap);
    b.clock = el("div", "tc-clock");
    b.clock.setAttribute("role", "timer");
    b.root.appendChild(b.ava);
    b.root.appendChild(b.who);
    b.root.appendChild(b.clock);
    b.side = "";
    b.photo = "";
    return b;
  }

  function buildGame() {
    clear(root);
    root.className = "tc";
    root.setAttribute("aria-busy", "false");
    ui = {};
    var game = el("div", "tc-game");
    var col = el("div", "tc-col");
    ui.top = makeBar();
    ui.bottom = makeBar();
    ui.top.root.className += " is-top";
    ui.bottom.root.className += " is-bottom";
    ui.bw = el("div", "tc-bw");
    ui.board = el("div", "tc-board");
    ui.board.setAttribute("role", "group");
    ui.board.setAttribute("aria-label", T.board);
    ui.layer = el("div", "tc-pieces is-still");
    ui.layer.setAttribute("aria-hidden", "true");
    ui.bw.appendChild(ui.board);
    ui.bw.appendChild(ui.layer);
    col.appendChild(ui.top.root);
    col.appendChild(ui.bw);
    col.appendChild(ui.bottom.root);
    game.appendChild(col);

    var side = el("div", "tc-side");
    ui.side = side;
    var info = el("div", "tc-info");
    ui.info = info;
    ui.dot = el("span", "tc-dot");
    ui.dot.setAttribute("aria-hidden", "true");
    var body = el("div", "tc-info-body");
    var line = el("p", "tc-status");
    ui.status = el("span", "tc-status-t");
    ui.status.setAttribute("role", "status");
    ui.status.setAttribute("aria-live", "polite");
    ui.count = el("span", "tc-count");
    ui.count.setAttribute("aria-hidden", "true");
    line.appendChild(ui.status);
    line.appendChild(ui.count);
    ui.note = el("p", "tc-note");
    body.appendChild(line);
    body.appendChild(ui.note);
    info.appendChild(ui.dot);
    info.appendChild(body);
    ui.fuse = el("div", "tc-fuse");
    ui.fuse.setAttribute("aria-hidden", "true");
    ui.fuseBar = el("span", "");
    ui.fuse.appendChild(ui.fuseBar);
    side.appendChild(info);
    side.appendChild(ui.fuse);
    ui.moves = el("ol", "tc-moves");
    ui.moves.setAttribute("aria-label", "Ходы партии");
    side.appendChild(ui.moves);
    ui.actions = el("div", "tc-actions");
    side.appendChild(ui.actions);
    game.appendChild(side);
    root.appendChild(game);

    ui.net = el("div", "tc-net");
    ui.net.setAttribute("role", "status");
    ui.net.appendChild(icon("wifi"));
    ui.net.appendChild(el("span", "", T.net));
    root.appendChild(ui.net);
    ui.toast = el("div", "tc-toast");
    ui.toast.setAttribute("role", "alert");
    root.appendChild(ui.toast);

    buildSquares();
    bindBoard();
    raf(function () { raf(function () { if (ui && ui.layer) { ui.layer.classList.remove("is-still"); } }); });
  }

  function buildSquares() {
    clear(ui.board);
    ui.sq = new Array(64);
    for (var row = 0; row < 8; row++) {
      for (var col = 0; col < 8; col++) {
        var i = fromDisp(row, col);
        var f = i % 8, rk = 8 - Math.floor(i / 8);
        var b = btn("", null);
        b._base = "tc-sq " + ((f + rk) % 2 ? "is-d" : "is-l");
        b.className = b._base;
        b.tabIndex = -1;
        b._i = i;
        if (row === 7) { b.appendChild(el("span", "tc-co is-f", FILES.charAt(f))); }
        if (col === 0) { b.appendChild(el("span", "tc-co is-r", String(rk))); }
        ui.board.appendChild(b);
        ui.sq[i] = b;
      }
    }
    focusIdx = fromDisp(6, 4);
    ui.sq[focusIdx].tabIndex = 0;
  }

  function setPos(node, i) {
    var d = disp(i);
    node.style.transform = "translate(" + (d.col * 100) + "%," + (d.row * 100) + "%)";
    node._i = i;
  }

  function makePc(code) {
    var n = el("div", "tc-pc");
    n.appendChild(piece(code));
    n._code = code;
    return n;
  }

  function placePieces(board, anim) {
    var next = {};
    var gone = [];
    var need = [];
    var i;
    for (i = 0; i < 64; i++) {
      var code = board[i], n = pcs[i];
      if (n && n._code === code) { next[i] = n; }
      else {
        if (n) { gone.push(n); }
        if (code) { need.push(i); }
      }
    }
    need.forEach(function (to) {
      var code = board[to], best = -1, bd = 99;
      gone.forEach(function (g, k) {
        if (g._code !== code) { return; }
        var a = disp(g._i), b = disp(to);
        var d = Math.abs(a.row - b.row) + Math.abs(a.col - b.col);
        if (d < bd) { bd = d; best = k; }
      });
      var n;
      if (best >= 0) {
        n = gone[best];
        gone.splice(best, 1);
        if (!anim) { n.classList.add("is-snap"); }
        setPos(n, to);
        if (!anim) { unsnap(n); }
      } else {
        n = makePc(code);
        n.classList.add("is-snap");
        setPos(n, to);
        ui.layer.appendChild(n);
        if (anim && !calm()) { n.classList.add("is-new"); }
        unsnap(n);
      }
      next[to] = n;
    });
    gone.forEach(function (g) {
      if (anim && !calm()) {
        g.classList.add("is-gone");
        setTimeout(function () { drop(g); }, 220);
      } else {
        drop(g);
      }
    });
    pcs = next;
  }

  function unsnap(n) {
    raf(function () { raf(function () { n.classList.remove("is-snap"); n.classList.remove("is-new"); }); });
  }

  function pieceLabel(code) {
    var t = code.toLowerCase();
    var w = sideOf(code) === "w";
    var adj = FEM[t] ? (w ? "белая" : "чёрная") : (w ? "белый" : "чёрный");
    return adj + " " + NAME[t];
  }

  function paint() {
    if (!ui || !view) { return; }
    var lf = -1, lt = -1;
    if (typeof view.last === "string" && view.last.length >= 4) {
      lf = idx(view.last.slice(0, 2));
      lt = idx(view.last.slice(2, 4));
    }
    var chk = typeof view.check === "string" ? idx(view.check) : -1;
    var over = down && down.over != null ? down.over : -1;
    for (var i = 0; i < 64; i++) {
      var b = ui.sq[i];
      var code = view.board[i];
      var cls = b._base;
      var lab = sqName(i);
      if (code) { lab += ", " + pieceLabel(code); }
      if (i === lf || i === lt) { cls += " is-last"; }
      if (i === sel) { cls += " is-sel"; lab += T.picked; }
      if (i === chk) { cls += " is-check"; }
      if (targets && targets[i]) {
        cls += code || isPassant(i) ? " is-take" : " is-to";
        lab += code ? T.take : T.to;
      }
      if (i === over) { cls += " is-over"; }
      if (b.className !== cls) { b.className = cls; }
      if (b._lab !== lab) { b._lab = lab; b.setAttribute("aria-label", lab); }
    }
  }

  function isPassant(i) {
    if (sel < 0 || !view) { return false; }
    var pc = view.board[sel];
    return !!(pc && pc.toLowerCase() === "p" && (i % 8) !== (sel % 8) && !view.board[i]);
  }

  function fromState(s) {
    var me = s.me === "w" || s.me === "b" ? s.me : null;
    return {
      board: parseFen(s.fen),
      turn: s.turn,
      last: typeof s.last === "string" ? s.last : null,
      check: typeof s.check === "string" ? s.check : null,
      legal: s.status === "on" && me && me === s.turn ? legalMap(s.legal) : null,
      frozen: false
    };
  }

  function adopt(s, force) {
    if (!isState(s)) { return; }
    if (!force && st && typeof s.rev === "number" && typeof st.rev === "number" && s.rev < st.rev) { return; }
    var same = !force && st && s.rev === st.rev && view && !view.frozen;
    offset = (typeof s.now === "number" ? s.now : Date.now()) - Date.now();
    var prev = st;
    st = s;
    if (!orient) {
      orient = s.me === "b" ? "b" : "w";
      buildGame();
    }
    if (same) { tick(); return; }
    zeroAsked = false;
    var oppMoved = !!(prev && s.moves && prev.moves && s.moves.length > prev.moves.length && s.me && s.turn === s.me);
    view = fromState(s);
    if (sel >= 0) {
      if (view.legal && view.legal[sel] && view.board[sel]) { targets = view.legal[sel]; }
      else { cancelDrag(); sel = -1; targets = null; }
    }
    if (promo && (!view.legal || !view.legal[promo.from])) { closePromo(true); }
    placePieces(view.board, !!prev);
    paint();
    renderBars();
    renderStatus();
    renderMoves();
    renderActions();
    tick();
    if (oppMoved) { haptic("impact", "medium"); }
    if (s.status === "done") { finish(); }
    else if (!tickTimer) { tickTimer = setInterval(tick, 100); }
  }

  function revert() {
    if (!st) { return; }
    view = fromState(st);
    sel = -1;
    targets = null;
    placePieces(view.board, true);
    paint();
    renderBars();
    renderStatus();
    renderMoves();
    renderActions();
    tick();
  }

  function renderBars() {
    var topSide = orient === "w" ? "b" : "w";
    fillBar(ui.top, topSide);
    fillBar(ui.bottom, orient);
  }

  function fillBar(bar, side) {
    var nm = nameOf(side);
    bar.side = side;
    if (bar.name.textContent !== nm) { bar.name.textContent = nm; }
    bar.you.hidden = st.me !== side;
    bar.pip.className = "tc-pip is-" + side;
    if (!bar.photo) {
      var letter = nm.charAt(0).toUpperCase();
      if (bar.letter.textContent !== letter) { bar.letter.textContent = letter; }
      bar.ava.style.backgroundColor = AVA[hash(nm) % AVA.length];
    }
    var mat = material(view.board);
    var caps = mat.caps[side];
    var plus = side === "w" ? mat.diff : -mat.diff;
    var key = caps.join("") + "|" + plus;
    if (bar.capKey !== key) {
      bar.capKey = key;
      clear(bar.cap);
      var prevT = "";
      caps.forEach(function (c) {
        var m = el("span", "tc-cap-pc" + (c.toLowerCase() === prevT ? " is-same" : ""));
        m.appendChild(piece(c));
        bar.cap.appendChild(m);
        prevT = c.toLowerCase();
      });
      if (plus > 0) { bar.cap.appendChild(el("span", "tc-plus", "+" + plus)); }
      var lbl = caps.length ? "Взято фигур: " + caps.length + (plus > 0 ? ", перевес +" + plus : "") : "";
      if (lbl) { bar.cap.setAttribute("aria-label", lbl); bar.cap.setAttribute("role", "img"); }
      else { bar.cap.removeAttribute("aria-label"); bar.cap.removeAttribute("role"); }
    }
    var on = st.status === "on";
    bar.root.classList.toggle("is-turn", on && view.turn === side);
    bar.root.classList.toggle("is-win", st.status === "done" && st.winner === side);
  }

  function setClock(bar, ms) {
    var s = fmtClock(ms);
    var node = bar.clock;
    if (node._s === s) { return; }
    if (!node._s || node._s.length !== s.length) {
      clear(node);
      node._d = [];
      for (var k = 0; k < s.length; k++) {
        var c = s.charAt(k);
        var d = el("span", /[0-9]/.test(c) ? "tc-dg" : "tc-sep");
        node.appendChild(d);
        node._d.push(d);
      }
    }
    for (var j = 0; j < s.length; j++) {
      if (node._d[j].textContent !== s.charAt(j)) { node._d[j].textContent = s.charAt(j); }
    }
    node._s = s;
    var sec = Math.ceil(ms / 1000);
    var lab = nameOf(bar.side) + ": " + Math.floor(sec / 60) + " мин " + (sec % 60) + " с";
    if (node._lab !== lab && (sec % 10 === 0 || !node._lab)) { node._lab = lab; node.setAttribute("aria-label", lab); }
  }

  function tick() {
    if (!st || !ui) { return; }
    var on = st.status === "on";
    [ui.top, ui.bottom].forEach(function (bar) {
      var side = bar.side;
      if (!side) { return; }
      var left = clockLeft(side);
      setClock(bar, left);
      var base = st.clock && st.clock.base ? st.clock.base : 0;
      var low = on && base > 30000 && left < 30000;
      bar.root.classList.toggle("is-low", low);
      bar.root.classList.toggle("is-crit", low && left < 10000 && view.turn === side);
      if (on && left <= 0 && st.clock && st.clock.run === side && !zeroAsked) {
        zeroAsked = true;
        schedule(250);
      }
    });
    var f = firstInfo();
    if (f) {
      var rest = f.until - now();
      var key = f.side + f.until;
      if (typeof f.ms === "number" && f.ms > 0) { firstSpan[key] = f.ms; }
      else if (!firstSpan[key] || rest > firstSpan[key]) { firstSpan[key] = Math.max(rest, 1); }
      var txt = (st.me === f.side ? T.left : T.dot) + fmtShort(rest);
      if (ui.count.textContent !== txt) { ui.count.textContent = txt; }
      ui.fuse.classList.add("is-on");
      ui.fuse.classList.toggle("is-low", rest < 15000);
      ui.fuseBar.style.transform = "scaleX(" + Math.max(0, Math.min(1, rest / firstSpan[key])).toFixed(4) + ")";
      if (rest <= 0 && !zeroAsked) {
        zeroAsked = true;
        schedule(400);
      }
    } else {
      if (ui.count.textContent) { ui.count.textContent = ""; }
      ui.fuse.classList.remove("is-on");
    }
  }

  function outcome() {
    var s = st, me = s.me === "w" || s.me === "b" ? s.me : null;
    var win = s.winner === "w" || s.winner === "b" ? s.winner : null;
    var o = { kind: "draw", title: "Ничья", sub: "", name: "", win: win };
    if (s.result === "abort" || s.reason === "abort") {
      o.kind = "abort";
      o.title = "Партия отменена";
      o.sub = "Не сделан первый ход";
      return o;
    }
    if (win) {
      var lose = other(win);
      if (me) {
        o.kind = me === win ? "win" : "loss";
        o.title = me === win ? "Вы победили!" : "Вы проиграли";
      } else {
        o.kind = "side";
        o.title = win === "w" ? "Победили белые" : "Победили чёрные";
        o.name = nameOf(win);
      }
      if (s.reason === "mate") { o.sub = "Мат"; }
      else if (s.reason === "resign") {
        o.sub = me ? (me === win ? "Соперник сдался" : "Вы сдались") : (lose === "w" ? "Белые сдались" : "Чёрные сдались");
      } else if (s.reason === "time") {
        o.sub = me ? (me === win ? "У соперника вышло время" : "Ваше время вышло") : (lose === "w" ? "У белых вышло время" : "У чёрных вышло время");
      } else { o.sub = "Партия окончена"; }
      return o;
    }
    var why = {
      stalemate: "Пат",
      material: "Недостаточно фигур для мата",
      repeat: "Троекратное повторение",
      fifty: "Правило 50 ходов",
      agreed: "Ничья по согласию",
      time: "Время вышло, а поставить мат уже нечем"
    };
    o.sub = why[s.reason] || "Партия окончена";
    return o;
  }

  function renderStatus() {
    var s = st, v = view;
    var me = s.me === "w" || s.me === "b" ? s.me : null;
    var text = "", note = "", mode = "";
    var turn = v.turn;
    if (s.status === "done") {
      var o = outcome();
      text = o.title;
      note = o.name ? o.name + " — " + o.sub.charAt(0).toLowerCase() + o.sub.slice(1) : o.sub;
      mode = o.kind === "win" ? "is-win" : o.kind === "loss" ? "is-loss" : "is-done";
      turn = "";
    } else {
      var f = firstInfo();
      if (f) {
        text = me === f.side ? T.firstMe : me ? T.firstOpp : (f.side === "w" ? T.firstW : T.firstB);
        mode = me === f.side ? "is-mine" : "";
      } else if (me) {
        text = turn === me ? (v.check ? T.myCheck : T.myTurn) : T.oppTurn;
        mode = turn === me ? (v.check ? "is-mine is-check" : "is-mine") : "";
      } else {
        text = turn === "w" ? T.wTurn : T.bTurn;
      }
      if (!me) { note = T.watch; }
    }
    if (ui.status.textContent !== text) { ui.status.textContent = text; }
    ui.note.textContent = note;
    ui.note.hidden = !note;
    ui.note.classList.toggle("is-watch", !me && s.status === "on");
    if (!me && s.status === "on") { ui.note.insertBefore(icon("eye"), ui.note.firstChild); }
    ui.dot.className = "tc-dot" + (turn ? " is-" + turn : "");
    ui.dot.hidden = !turn;
    ui.info.className = "tc-info " + mode;
  }

  function sanNode(san, side) {
    var n = el("span", "tc-san");
    var m = /^([KQRBN])(.*)$/.exec(san);
    var rest = san;
    n.appendChild(el("span", "tc-sr", side === "w" ? "белые: " : "чёрные: "));
    if (m) {
      n.appendChild(el("span", "tc-sr", NAME[m[1].toLowerCase()] + " "));
      n.appendChild(glyph(m[1].toLowerCase()));
      rest = m[2];
    }
    var pm = /^(.*=)([QRBN])(.*)$/.exec(rest);
    if (pm) {
      n.appendChild(document.createTextNode(pm[1]));
      n.appendChild(el("span", "tc-sr", NAME[pm[2].toLowerCase()]));
      n.appendChild(glyph(pm[2].toLowerCase()));
      n.appendChild(document.createTextNode(pm[3]));
    } else {
      n.appendChild(document.createTextNode(rest));
    }
    return n;
  }

  function renderMoves() {
    var san = Array.isArray(st.san) ? st.san : [];
    var key = san.length + "|" + san[san.length - 1] + "|" + st.status;
    if (key === movesKey) { return; }
    movesKey = key;
    clear(ui.moves);
    if (!san.length) {
      var e = el("li", "tc-moves-empty", st.status === "done" ? T.noneMoved : T.noMoves);
      ui.moves.appendChild(e);
      return;
    }
    for (var k = 0; k < san.length; k += 2) {
      var li = el("li", "tc-mvrow");
      li.appendChild(el("span", "tc-mvno", (k / 2 + 1) + "."));
      var a = sanNode(String(san[k]), "w");
      a.className += " tc-mv";
      if (k === san.length - 1) { a.className += " is-last"; }
      li.appendChild(a);
      if (k + 1 < san.length) {
        var b = sanNode(String(san[k + 1]), "b");
        b.className += " tc-mv";
        if (k + 1 === san.length - 1) { b.className += " is-last"; }
        li.appendChild(b);
      }
      ui.moves.appendChild(li);
    }
    raf(function () {
      ui.moves.scrollLeft = ui.moves.scrollWidth;
      ui.moves.scrollTop = ui.moves.scrollHeight;
    });
  }

  function renderActions() {
    var me = st.me === "w" || st.me === "b" ? st.me : null;
    var key = st.status + "|" + me + "|" + st.draw + "|" + busyAct;
    if (key === actKey) { return; }
    actKey = key;
    clear(ui.actions);
    ui.actions.className = "tc-actions";
    ui.side.classList.remove("is-offer");
    if (st.status === "done") {
      var r = btn("tc-btn", T.result);
      r.addEventListener("click", function () { showEnd(false); });
      var c = btn("tc-btn is-main", T.close);
      c.addEventListener("click", closeApp);
      ui.actions.appendChild(r);
      ui.actions.appendChild(c);
      return;
    }
    if (!me) {
      ui.actions.className = "tc-actions is-empty";
      return;
    }
    if (st.draw === other(me)) {
      ui.actions.className = "tc-actions is-offer";
      ui.side.classList.add("is-offer");
      var card = el("div", "tc-offer");
      var mark = el("span", "tc-offer-mark", "½");
      mark.setAttribute("aria-hidden", "true");
      var txt = el("p", "tc-offer-t", T.offer);
      var row = el("div", "tc-offer-btns");
      var no = btn("tc-btn", T.decline);
      var yes = btn("tc-btn is-main", T.accept);
      if (busyAct) { no.disabled = true; yes.disabled = true; }
      if (busyAct === "draw_no") { no.classList.add("is-busy"); }
      if (busyAct === "draw_yes") { yes.classList.add("is-busy"); }
      no.addEventListener("click", function () { act("draw_no"); });
      yes.addEventListener("click", function () { act("draw_yes"); });
      row.appendChild(no);
      row.appendChild(yes);
      var head = el("div", "tc-offer-head");
      head.appendChild(mark);
      head.appendChild(txt);
      card.appendChild(head);
      card.appendChild(row);
      ui.actions.appendChild(card);
      return;
    }
    var mine = st.draw === me;
    var d = btn("tc-btn", null);
    var dm = el("span", "tc-half", "½");
    dm.setAttribute("aria-hidden", "true");
    d.appendChild(dm);
    d.appendChild(el("span", "", mine ? T.drawSent : T.draw));
    if (mine) { d.disabled = true; d.classList.add("is-sent"); }
    if (busyAct === "draw") { d.disabled = true; d.classList.add("is-busy"); }
    d.addEventListener("click", function () { act("draw"); });
    var g = btn("tc-btn is-danger", null);
    g.appendChild(icon("flag"));
    g.appendChild(el("span", "", T.resign));
    if (busyAct === "resign") { g.disabled = true; g.classList.add("is-busy"); }
    g.addEventListener("click", askResign);
    ui.actions.appendChild(d);
    ui.actions.appendChild(g);
  }

  function askResign() {
    if (tg && typeof tg.showConfirm === "function" && ver("6.2") && inTelegram()) {
      call(function () {
        tg.showConfirm(T.resignAsk, function (ok) { if (ok) { act("resign"); } });
      });
      return;
    }
    dialog(T.resignAsk, T.resign, function () { act("resign"); });
  }

  function dialog(text, yesText, onYes) {
    var back = el("div", "tc-dlg");
    var card = el("div", "tc-dlg-card");
    card.setAttribute("role", "alertdialog");
    card.setAttribute("aria-modal", "true");
    var p = el("p", "tc-dlg-t", text);
    p.id = "tc-dlg-t";
    card.setAttribute("aria-labelledby", "tc-dlg-t");
    var row = el("div", "tc-dlg-btns");
    var no = btn("tc-btn", T.cancel);
    var yes = btn("tc-btn is-danger-main", yesText);
    var prev = document.activeElement;
    function close() {
      drop(back);
      document.removeEventListener("keydown", onKey);
      if (prev && prev.focus) { call(function () { prev.focus(); }); }
    }
    function onKey(e) { if (e.key === "Escape") { close(); } }
    no.addEventListener("click", close);
    yes.addEventListener("click", function () { close(); onYes(); });
    back.addEventListener("click", function (e) { if (e.target === back) { close(); } });
    document.addEventListener("keydown", onKey);
    row.appendChild(no);
    row.appendChild(yes);
    card.appendChild(p);
    card.appendChild(row);
    back.appendChild(card);
    root.appendChild(back);
    no.focus();
  }

  function act(name) {
    if (busyAct || !st || st.status !== "on") { return; }
    busyAct = name;
    renderActions();
    pending = true;
    moveSeq = ++seq;
    post({ act: name }).then(function (res) {
      pending = false;
      busyAct = "";
      var h = classify(res);
      if (h.fatal) { screen(h.fatal); return; }
      if (h.ok) {
        adopt(h.state, true);
      } else if (h.state) {
        adopt(h.state, true);
        toast(errText(h.code));
        haptic("note", "error");
      } else {
        toast(h.rate ? T.rate : T.fail);
      }
      actKey = "";
      renderActions();
      if (st.status === "on") { schedule(POLL); }
    }, function () {
      pending = false;
      busyAct = "";
      actKey = "";
      renderActions();
      toast(T.fail);
      schedule(POLL);
    });
  }

  function errText(code) {
    if (code === "illegal") { return T.illegal; }
    if (code === "turn") { return T.turn; }
    if (code === "over") { return T.over; }
    return T.fail;
  }

  function toast(text) {
    if (!ui) { return; }
    ui.toast.textContent = text;
    ui.toast.classList.add("is-on");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { ui.toast.classList.remove("is-on"); }, 2600);
  }

  function net(on) {
    if (ui && ui.net) { ui.net.classList.toggle("is-on", !!on); }
  }

  function finish() {
    stopped = true;
    clearTimeout(pollTimer);
    clearInterval(tickTimer);
    tickTimer = 0;
    cancelDrag();
    closePromo(true);
    sel = -1;
    targets = null;
    paint();
    tick();
    if (!endShown) {
      endShown = true;
      var o = outcome();
      showEnd(true);
      if (o.kind === "win") { haptic("note", "success"); }
      else if (o.kind === "loss") { haptic("note", "warning"); }
    }
  }

  function showEnd(fresh) {
    if (!ui || !st || st.status !== "done") { return; }
    hideEnd();
    var o = outcome();
    var box = el("div", "tc-end is-" + o.kind + (fresh ? " is-fresh" : ""));
    var card = el("div", "tc-card");
    card.tabIndex = -1;
    card.setAttribute("role", "dialog");
    card.setAttribute("aria-labelledby", "tc-card-t");
    var pics = el("div", "tc-card-pics");
    if (o.kind === "win" || o.kind === "loss" || o.kind === "side") {
      var k = el("span", "tc-card-k");
      k.appendChild(piece(o.win === "w" ? "K" : "k"));
      pics.appendChild(k);
    } else {
      var kw = el("span", "tc-card-k is-pair");
      kw.appendChild(piece("K"));
      var kb = el("span", "tc-card-k is-pair");
      kb.appendChild(piece("k"));
      pics.appendChild(kw);
      pics.appendChild(kb);
    }
    card.appendChild(pics);
    var h = el("h2", "tc-card-t", o.title);
    h.id = "tc-card-t";
    card.appendChild(h);
    if (o.name) { card.appendChild(el("p", "tc-card-name", o.name)); }
    if (o.sub) { card.appendChild(el("p", "tc-card-sub", o.sub)); }
    var row = el("div", "tc-card-btns");
    var look = btn("tc-btn is-ghost", T.look);
    look.addEventListener("click", function () {
      hideEnd();
      var r = ui.actions.querySelector("button");
      if (r) { r.focus(); }
    });
    var cl = btn("tc-btn is-main", T.close);
    cl.addEventListener("click", closeApp);
    row.appendChild(look);
    row.appendChild(cl);
    card.appendChild(row);
    box.appendChild(card);
    ui.bw.appendChild(box);
    ui.end = box;
    if (fresh && o.kind === "win") { confetti(box); }
    call(function () { card.focus({ preventScroll: true }); });
  }

  function hideEnd() {
    if (ui && ui.end) { drop(ui.end); ui.end = null; }
  }

  function confetti(host) {
    if (calm()) { return; }
    var box = el("div", "tc-confetti");
    box.setAttribute("aria-hidden", "true");
    var cols = ["#ffe27a", "#ffc142", "#ff8c12", "#ffffff", "#bb00ff", "#ffd75e"];
    for (var k = 0; k < 44; k++) {
      var p = el("i", k % 4 === 0 ? "is-dot" : k % 7 === 0 ? "is-long" : "");
      var a = -Math.PI / 2 + (Math.random() - 0.5) * 2.4;
      var sp = 90 + Math.random() * 150;
      var x = Math.cos(a) * sp, y = Math.sin(a) * sp;
      p.style.setProperty("--x", Math.round(x) + "px");
      p.style.setProperty("--y", Math.round(y) + "px");
      p.style.setProperty("--x2", Math.round(x * 1.25) + "px");
      p.style.setProperty("--y2", Math.round(y + 170 + Math.random() * 120) + "px");
      p.style.setProperty("--r", Math.round((Math.random() - 0.5) * 1440) + "deg");
      p.style.setProperty("--d", Math.round(120 + Math.random() * 160) + "ms");
      p.style.backgroundColor = cols[k % cols.length];
      box.appendChild(p);
    }
    host.appendChild(box);
    setTimeout(function () { drop(box); }, 2600);
  }

  function canMove() {
    return !!(view && view.legal && !pending && st && st.status === "on" && !promo);
  }

  function movable(i) {
    return canMove() && !!view.legal[i];
  }

  function select(i) {
    sel = i;
    targets = view.legal[i] || null;
    paint();
    haptic("select");
  }

  function deselect() {
    sel = -1;
    targets = null;
    paint();
  }

  function sqAt(x, y) {
    var r = ui.board.getBoundingClientRect();
    if (!r.width) { return -1; }
    var col = Math.floor((x - r.left) / r.width * 8);
    var row = Math.floor((y - r.top) / r.height * 8);
    if (col < 0 || col > 7 || row < 0 || row > 7) { return -1; }
    return fromDisp(row, col);
  }

  function bindBoard() {
    var b = ui.board;
    b.addEventListener("pointerdown", onDown);
    b.addEventListener("pointermove", onMove);
    b.addEventListener("pointerup", onUp);
    b.addEventListener("pointercancel", function () { cancelDrag(); });
    b.addEventListener("lostpointercapture", function () {
      if (!down) { return; }
      if (down.moved) { cancelDrag(); } else { down = null; }
    });
    b.addEventListener("click", onClick);
    b.addEventListener("keydown", onKey);
    b.addEventListener("contextmenu", function (e) { e.preventDefault(); });
    b.addEventListener("focusin", function (e) {
      var t = e.target;
      if (t && t._i != null && t._i !== focusIdx) {
        if (focusIdx >= 0) { ui.sq[focusIdx].tabIndex = -1; }
        focusIdx = t._i;
        t.tabIndex = 0;
      }
    });
  }

  function onDown(e) {
    if (e.pointerType === "mouse" && e.button !== 0) { return; }
    if (!view) { return; }
    if (down) {
      if (down.moved) { return; }
      down = null;
    }
    var i = sqAt(e.clientX, e.clientY);
    if (i < 0) { return; }
    if (sel >= 0 && targets && targets[i] && canMove()) {
      down = { i: i, mode: "to", id: e.pointerId, x: e.clientX, y: e.clientY };
      call(function () { ui.board.setPointerCapture(e.pointerId); });
      return;
    }
    if (movable(i)) {
      var was = sel === i;
      if (!was) { select(i); }
      down = { i: i, mode: "pc", was: was, id: e.pointerId, x: e.clientX, y: e.clientY, moved: false, over: null };
      call(function () { ui.board.setPointerCapture(e.pointerId); });
      if (e.pointerType !== "mouse") { e.preventDefault(); }
      return;
    }
    if (sel >= 0) { deselect(); }
  }

  function onMove(e) {
    if (!down || down.mode !== "pc" || e.pointerId !== down.id) { return; }
    var dx = e.clientX - down.x, dy = e.clientY - down.y;
    if (!down.moved && dx * dx + dy * dy < 36) { return; }
    var n = pcs[down.i];
    if (!n) { return; }
    if (!down.moved) {
      down.moved = true;
      n.classList.add("is-drag");
    }
    var r = ui.board.getBoundingClientRect();
    var size = r.width / 8;
    var px = e.clientX - r.left - size / 2;
    var py = e.clientY - r.top - size / 2 - (e.pointerType === "touch" ? size * 0.35 : 0);
    n.style.transform = "translate(" + px.toFixed(1) + "px," + py.toFixed(1) + "px)";
    var over = sqAt(e.clientX, e.clientY - (e.pointerType === "touch" ? size * 0.35 : 0));
    over = targets && targets[over] ? over : null;
    if (over !== down.over) {
      down.over = over;
      paint();
    }
  }

  function onUp(e) {
    if (!down || e.pointerId !== down.id) { return; }
    var d = down;
    var i;
    if (d.mode === "to") {
      down = null;
      i = sqAt(e.clientX, e.clientY);
      if (i === d.i && sel >= 0) { tryMove(sel, i, false); }
      return;
    }
    if (d.moved) {
      var r = ui.board.getBoundingClientRect();
      var lift = e.pointerType === "touch" ? r.width / 8 * 0.35 : 0;
      i = sqAt(e.clientX, e.clientY - lift);
      endDrag(i);
      return;
    }
    down = null;
    i = sqAt(e.clientX, e.clientY);
    if (d.was && i === d.i) { deselect(); }
  }

  function endDrag(i) {
    var d = down;
    down = null;
    var n = d ? pcs[d.i] : null;
    if (!n) { paint(); return; }
    n.classList.remove("is-drag");
    if (i >= 0 && i !== d.i && targets && targets[i]) {
      n.classList.add("is-snap");
      setPos(n, i);
      n._i = d.i;
      unsnap(n);
      paint();
      tryMove(d.i, i, true);
      return;
    }
    setPos(n, d.i);
    paint();
  }

  function cancelDrag() {
    if (!down) { return; }
    var d = down;
    down = null;
    var n = pcs[d.i];
    if (n) {
      n.classList.remove("is-drag");
      setPos(n, d.i);
    }
    paint();
  }

  function onClick(e) {
    if (e.detail !== 0) { return; }
    var t = e.target && e.target.closest ? e.target.closest(".tc-sq") : null;
    if (!t || t._i == null) { return; }
    var i = t._i;
    if (sel >= 0 && targets && targets[i] && canMove()) { tryMove(sel, i, false); return; }
    if (movable(i)) {
      if (sel === i) { deselect(); } else { select(i); }
      return;
    }
    if (sel >= 0) { deselect(); }
  }

  function onKey(e) {
    var t = e.target;
    if (!t || t._i == null) { return; }
    var d = disp(t._i);
    var row = d.row, col = d.col;
    if (e.key === "ArrowUp") { row--; }
    else if (e.key === "ArrowDown") { row++; }
    else if (e.key === "ArrowLeft") { col--; }
    else if (e.key === "ArrowRight") { col++; }
    else if (e.key === "Escape") { if (sel >= 0) { deselect(); } return; }
    else { return; }
    e.preventDefault();
    if (row < 0 || row > 7 || col < 0 || col > 7) { return; }
    ui.sq[fromDisp(row, col)].focus();
  }

  function tryMove(from, to, dropped) {
    var list = view && view.legal && view.legal[from] && view.legal[from][to];
    if (!list || !list.length) {
      if (dropped && pcs[from]) { setPos(pcs[from], from); }
      return;
    }
    if (list.length > 1) {
      openPromo(from, to, dropped);
      return;
    }
    commit(list[0]);
  }

  function openPromo(from, to, dropped) {
    var code = view.board[from];
    var side = sideOf(code);
    var d = disp(to);
    var box = el("div", "tc-promo");
    var dim = btn("tc-promo-dim", null);
    dim.setAttribute("aria-label", T.promoCancel);
    dim.addEventListener("click", function () { closePromo(false); });
    box.appendChild(dim);
    var colm = el("div", "tc-promo-col" + (d.row === 0 ? "" : " is-up"));
    colm.setAttribute("role", "group");
    colm.setAttribute("aria-label", T.promo);
    colm.style.left = (d.col * 12.5) + "%";
    var first = null;
    PROMO.forEach(function (t) {
      var c = side === "w" ? t.toUpperCase() : t;
      var b = btn("tc-promo-pick", null);
      b.setAttribute("aria-label", NAME[t]);
      b.appendChild(piece(c));
      b.addEventListener("click", function () {
        var p = promo;
        closePromo(true);
        if (p) { commit(sqName(p.from) + sqName(p.to) + t); }
      });
      colm.appendChild(b);
      if (!first) { first = b; }
    });
    box.appendChild(colm);
    box.addEventListener("keydown", function (e) { if (e.key === "Escape") { closePromo(false); } });
    ui.bw.appendChild(box);
    promo = { from: from, to: to, box: box, dropped: dropped };
    if (first) { call(function () { first.focus({ preventScroll: true }); }); }
  }

  function closePromo(keep) {
    if (!promo) { return; }
    var p = promo;
    promo = null;
    drop(p.box);
    if (pcs[p.from]) { setPos(pcs[p.from], p.from); }
    if (!keep) { deselect(); }
  }

  function commit(uci) {
    if (!view || pending) { return; }
    var from = idx(uci.slice(0, 2));
    if (from < 0 || !view.board[from]) { return; }
    pending = true;
    moveSeq = ++seq;
    var mover = sideOf(view.board[from]);
    var held = { w: clockLeft("w"), b: clockLeft("b") };
    if (uci.length > 4 && pcs[from]) {
      setPos(pcs[from], idx(uci.slice(2, 4)));
      pcs[from]._i = from;
    }
    view = {
      board: applyUci(view.board, uci),
      turn: other(mover),
      last: uci.slice(0, 4),
      check: null,
      legal: null,
      frozen: true,
      held: held
    };
    sel = -1;
    targets = null;
    placePieces(view.board, true);
    paint();
    renderBars();
    renderStatus();
    tick();
    haptic("impact", "light");
    clearTimeout(pollTimer);
    post({ move: uci }).then(function (res) {
      pending = false;
      var h = classify(res);
      if (h.fatal) { screen(h.fatal); return; }
      if (h.ok) {
        fails = 0;
        net(false);
        adopt(h.state, true);
      } else if (h.state) {
        adopt(h.state, true);
        toast(h.rate ? T.rate : errText(h.code));
        haptic("note", "error");
      } else {
        revert();
        toast(h.rate ? T.rate : T.fail);
        haptic("note", "error");
      }
      if (st && st.status === "on") { schedule(POLL); }
    }, function () {
      pending = false;
      revert();
      toast(T.noSend);
      haptic("note", "error");
      schedule(1000);
    });
  }

  function schedule(ms) {
    clearTimeout(pollTimer);
    pollTimer = 0;
    if (stopped) { return; }
    pollTimer = setTimeout(poll, ms);
  }

  function backoff() {
    return Math.min(15000, 1000 * Math.pow(2, Math.min(fails, 4)));
  }

  function poll() {
    pollTimer = 0;
    if (stopped) { return; }
    if (document.visibilityState === "hidden") { return; }
    if (pending || inflight) { schedule(POLL); return; }
    inflight = true;
    var my = ++seq;
    post({}).then(function (res) {
      inflight = false;
      if (stopped) { return; }
      if (pending || my < moveSeq) { schedule(POLL); return; }
      var h = classify(res);
      if (h.fatal) { screen(h.fatal); return; }
      if (h.ok) {
        fails = 0;
        net(false);
        adopt(h.state, false);
        if (!photosAsked) { loadPhotos(); }
        if (st.status === "on") { schedule(POLL); }
        return;
      }
      if (h.rate) { schedule(6000); return; }
      fails++;
      net(fails > 1 && !!ui);
      schedule(backoff());
    }, function () {
      inflight = false;
      if (stopped) { return; }
      fails++;
      net(fails > 1 && !!ui);
      if (!ui && fails > 1) { skelNet(); }
      schedule(backoff());
    });
  }

  function skelNet() {
    if (root.querySelector(".tc-net")) { return; }
    var n = el("div", "tc-net is-on");
    n.setAttribute("role", "status");
    n.appendChild(icon("wifi"));
    n.appendChild(el("span", "", T.net));
    root.appendChild(n);
  }

  function loadPhotos() {
    photosAsked = true;
    post({ photos: true }).then(function (res) {
      var j = res.data;
      if (res.status !== 200 || !ui) { return; }
      [ui.top, ui.bottom].forEach(function (bar) {
        var u = safeImg(j[bar.side]);
        if (!u) { return; }
        var img = new Image();
        img.alt = "";
        img.decoding = "async";
        img.referrerPolicy = "no-referrer";
        img.className = "tc-ava-img";
        img.onload = function () {
          bar.photo = u;
          bar.ava.insertBefore(img, bar.pip);
          bar.ava.classList.add("has-img");
          bar.ava.style.backgroundColor = "";
        };
        img.src = u;
      });
    }, function () {});
  }

  function wake() {
    if (stopped || document.visibilityState === "hidden") { return; }
    clearTimeout(pollTimer);
    poll();
  }

  document.documentElement.lang = "ru";
  scheme();

  if (!inTelegram()) {
    screen("outside");
    return;
  }

  gameId = readGame();
  call(function () { tg.ready(); });
  call(function () { tg.expand(); });
  if (ver("7.7") && typeof tg.disableVerticalSwipes === "function") {
    call(function () { tg.disableVerticalSwipes(); });
  }
  if (typeof tg.onEvent === "function") {
    call(function () { tg.onEvent("themeChanged", scheme); });
    if (ver("8.0")) { call(function () { tg.onEvent("activated", wake); }); }
  }
  if (!gameId) {
    screen("none");
    return;
  }
  document.addEventListener("visibilitychange", wake);
  skeleton();
  poll();
})();
