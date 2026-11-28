(function () {
  "use strict";

  var root = document.getElementById("ts");
  if (!root) { return; }

  var tg = window.Telegram && window.Telegram.WebApp;

  var COLS = 13;
  var ROWS = 15;
  var START_LEN = 5;
  var LAG = 0.5;
  var TAIL_OUT = 0.4;
  var R_BODY = 0.34;
  var R_NECK = 0.26;
  var WAVE_AMP = 0.04;
  var WAVE_LEN = 2.6;
  var BULGE_SPEED = 1.45;
  var BULGE_AMP = 0.34;
  var EAT_AT = 0.5;
  var IMPACT = 0.3;
  var OVER_AT = 950;
  var BEST_KEY = "ts-best";
  var SHADES = 24;
  var CHUNK = 9;
  var SHADOW_DOWN = 7;
  var SMAX = 4096;
  var HCX = 0.26;
  var LX = -0.5;
  var LY = -0.866;
  var TAU = Math.PI * 2;
  var TONGUE = "#ff3d5e";
  var PAL = { dark: "#070d1d", light: "#edf1f8" };
  var DIRS = [{ x: 0, y: -1 }, { x: 1, y: 0 }, { x: 0, y: 1 }, { x: -1, y: 0 }];
  var KEYS = { ArrowUp: 0, KeyW: 0, ArrowRight: 1, KeyD: 1, ArrowDown: 2, KeyS: 2, ArrowLeft: 3, KeyA: 3 };
  var JUICE = ["#fff0a8", "#ffd34d", "#ffb21f", "#ff8c1a"];
  var FRUITS = [
    { src: "assets/snake/buddha.webp", glow: "255,196,70", juice: ["#fff3b0", "#ffd34d", "#ffb21f", "#ff8c1a"] },
    { src: "assets/snake/love.webp", glow: "255,92,170", juice: ["#ffe0ef", "#ff9fd0", "#ff5aa8", "#e3368a"] },
    { src: "assets/snake/magma.webp", glow: "255,104,48", juice: ["#ffe08a", "#ffa63d", "#ff5a1f", "#c92a0e"] },
    { src: "assets/snake/light.webp", glow: "255,216,90", juice: ["#fffbe0", "#ffe98a", "#ffd23d", "#ffb01f"] },
    { src: "assets/snake/dragon.webp", glow: "90,232,150", juice: ["#dcffe9", "#86f2b8", "#33d184", "#f5c542"] }
  ];
  var HEAD = [
    ["m", -0.2, 0],
    ["c", -0.2, -0.3, -0.06, -0.44, 0.12, -0.44],
    ["c", 0.42, -0.44, 0.72, -0.29, 0.74, -0.08],
    ["q", 0.755, 0, 0.74, 0.08],
    ["c", 0.72, 0.29, 0.42, 0.44, 0.12, 0.44],
    ["c", -0.06, 0.44, -0.2, 0.3, -0.2, 0]
  ];

  var T = {
    title: "Змейка",
    score: "Фрукты",
    best: "Рекорд",
    startTouch: "Проведи пальцем, чтобы начать",
    startKeys: "Нажми стрелку или WASD, чтобы начать",
    hintTouch: "Свайпай по экрану, чтобы поворачивать. Стены и собственный хвост — смерть.",
    hintKeys: "Стрелки или WASD — поворот, пробел — пауза. Стены и собственный хвост — смерть.",
    pause: "Пауза",
    resume: "Продолжить",
    over: "Игра окончена",
    record: "Новый рекорд!",
    win: "Поле заполнено!",
    again: "Ещё раз",
    eaten: "Съедено фруктов",
    board: "Игровое поле змейки"
  };

  var params = readParams();
  var SLOW = params.slow;
  var TICK = 1000 / params.speed;

  function readParams() {
    var q = null;
    try { q = new URLSearchParams(window.location.search); } catch (e) { q = null; }
    function num(name, def, lo, hi) {
      var v = q ? parseFloat(q.get(name)) : NaN;
      return isFinite(v) && v >= lo && v <= hi ? v : def;
    }
    var seed = q ? parseInt(q.get("seed"), 10) : NaN;
    var sch = q ? q.get("scheme") : "";
    return {
      slow: num("slow", 1, 0.02, 4),
      speed: num("speed", 9, 3, 20),
      seed: isFinite(seed) ? seed : Math.floor(Math.random() * 1e9),
      scheme: sch === "light" || sch === "dark" ? sch : ""
    };
  }

  function clamp(v, a, b) { return v < a ? a : v > b ? b : v; }
  function lerp(a, b, t) { return a + (b - a) * t; }
  function smooth(a, b, v) {
    var t = clamp((v - a) / (b - a), 0, 1);
    return t * t * (3 - 2 * t);
  }
  function easeOutBack(t) {
    var c = 2.1;
    t -= 1;
    return 1 + t * t * ((c + 1) * t + c);
  }
  function easeOutCubic(t) {
    t = 1 - t;
    return 1 - t * t * t;
  }

  function rng(seed) {
    var a = seed >>> 0;
    return function () {
      a = (a + 0x6d2b79f5) >>> 0;
      var t = a;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }

  function hexRgb(h) {
    h = h.replace("#", "");
    if (h.length === 3) { h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2); }
    var n = parseInt(h, 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  function mix(a, b, t) { return [lerp(a[0], b[0], t), lerp(a[1], b[1], t), lerp(a[2], b[2], t)]; }
  function rgba(c, a) {
    return "rgba(" + Math.round(c[0]) + "," + Math.round(c[1]) + "," + Math.round(c[2]) + "," + (a == null ? 1 : a) + ")";
  }

  function ver(v) {
    try { return !!(tg && typeof tg.isVersionAtLeast === "function" && tg.isVersionAtLeast(v)); } catch (e) { return false; }
  }
  function inTelegram() {
    return !!(tg && typeof tg.initData === "string" && tg.initData !== "");
  }
  function call(fn) {
    try { fn(); } catch (e) { return; }
  }
  function raf(fn) {
    if (typeof window.requestAnimationFrame === "function") { return window.requestAnimationFrame(fn); }
    return window.setTimeout(function () { fn(Date.now()); }, 16);
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function createGame(cols, rows, rand) {
    var g = { cols: cols, rows: rows, rand: rand, cells: [], dir: DIRS[1], queue: [], food: null, pending: 0, alive: true, steps: 0, score: 0 };
    resetGame(g);
    return g;
  }

  function resetGame(g) {
    var y = Math.floor(g.rows / 2) + 2;
    g.cells = [];
    for (var i = 0; i < START_LEN; i++) { g.cells.push({ x: START_LEN - i, y: y }); }
    g.dir = DIRS[1];
    g.queue = [];
    g.pending = 0;
    g.alive = true;
    g.steps = 0;
    g.score = 0;
    g.food = placeFood(g);
  }

  function blockedAt(g, x, y, ignoreTail) {
    if (x < 0 || y < 0 || x >= g.cols || y >= g.rows) { return true; }
    var n = g.cells.length - (ignoreTail ? 1 : 0);
    for (var i = 0; i < n; i++) {
      if (g.cells[i].x === x && g.cells[i].y === y) { return true; }
    }
    return false;
  }

  function placeFood(g) {
    var free = [];
    var far = [];
    var h = g.cells[0];
    for (var y = 0; y < g.rows; y++) {
      for (var x = 0; x < g.cols; x++) {
        if (blockedAt(g, x, y, false)) { continue; }
        free.push({ x: x, y: y });
        if (Math.abs(x - h.x) + Math.abs(y - h.y) >= 4) { far.push({ x: x, y: y }); }
      }
    }
    var pool = far.length ? far : free;
    if (!pool.length) { return null; }
    var spot = pool[Math.floor(g.rand() * pool.length)];
    var was = g.food ? g.food.kind : -1;
    var kind = Math.floor(g.rand() * (FRUITS.length - (was < 0 ? 0 : 1)));
    if (was >= 0 && kind >= was) { kind += 1; }
    return { x: spot.x, y: spot.y, kind: kind };
  }

  function steer(g, d) {
    var last = g.queue.length ? g.queue[g.queue.length - 1] : g.dir;
    if (d.x === last.x && d.y === last.y) { return false; }
    if (d.x === -last.x && d.y === -last.y) { return false; }
    if (g.queue.length >= 3) { return false; }
    g.queue.push(d);
    return true;
  }

  function stepGame(g) {
    if (!g.alive) { return null; }
    if (g.queue.length) { g.dir = g.queue.shift(); }
    var h = g.cells[0];
    var nx = h.x + g.dir.x;
    var ny = h.y + g.dir.y;
    var grow = g.pending > 0;
    if (blockedAt(g, nx, ny, !grow)) {
      g.alive = false;
      return { died: true, dir: g.dir };
    }
    g.cells.unshift({ x: nx, y: ny });
    var tail = null;
    if (grow) { g.pending -= 1; } else { tail = g.cells.pop(); }
    var ate = null;
    if (g.food && g.food.x === nx && g.food.y === ny) {
      ate = g.food;
      g.pending += 1;
      g.score += 1;
      g.food = placeFood(g);
    }
    g.steps += 1;
    return { died: false, grew: grow, tail: tail, ate: ate };
  }

  function buildTrack(cells, ghost) {
    var pts = cells.slice();
    if (ghost) { pts.push(ghost); }
    var m = pts.length;
    var out = [];
    for (var j = 0; j < m; j++) {
      var c = pts[j];
      var dox;
      var doy;
      if (j > 0) { dox = pts[j - 1].x - c.x; doy = pts[j - 1].y - c.y; }
      else if (m > 1) { dox = c.x - pts[1].x; doy = c.y - pts[1].y; }
      else { dox = 1; doy = 0; }
      var dix = dox;
      var diy = doy;
      if (j < m - 1) { dix = c.x - pts[j + 1].x; diy = c.y - pts[j + 1].y; }
      var p = { cx: c.x + 0.5, cy: c.y + 0.5, dx: dox, dy: doy, arc: false, ox: 0, oy: 0, a0: 0, sw: 0 };
      if ((dix !== dox || diy !== doy) && !(dix === -dox && diy === -doy)) {
        p.arc = true;
        p.ox = p.cx - 0.5 * dix + 0.5 * dox;
        p.oy = p.cy - 0.5 * diy + 0.5 * doy;
        p.a0 = Math.atan2(p.cy - 0.5 * diy - p.oy, p.cx - 0.5 * dix - p.ox);
        p.sw = dix * doy - diy * dox > 0 ? Math.PI / 2 : -Math.PI / 2;
      }
      out.push(p);
    }
    return out;
  }

  function trackAt(tr, v, o) {
    var m = tr.length;
    var j = Math.floor(v + 0.5);
    if (j < 0) { j = 0; } else if (j > m - 1) { j = m - 1; }
    var p = tr[j];
    var f = j + 0.5 - v;
    if (p.arc && f >= 0 && f <= 1) {
      var a = p.a0 + f * p.sw;
      var ca = Math.cos(a);
      var sa = Math.sin(a);
      o.x = p.ox + 0.5 * ca;
      o.y = p.oy + 0.5 * sa;
      if (p.sw > 0) { o.tx = -sa; o.ty = ca; } else { o.tx = sa; o.ty = -ca; }
    } else {
      o.x = p.cx + (j - v) * p.dx;
      o.y = p.cy + (j - v) * p.dy;
      o.tx = p.dx;
      o.ty = p.dy;
    }
  }

  var calm = false;
  var mq = null;
  try { mq = window.matchMedia ? window.matchMedia("(prefers-reduced-motion: reduce)") : null; } catch (e) { mq = null; }
  calm = !!(mq && mq.matches);

  var rand = rng(params.seed);
  var fxr = rng(params.seed ^ 0x5bd1e995);
  var game = createGame(COLS, ROWS, rand);
  var track = buildTrack(game.cells, null);
  var ghost = null;
  var grewLast = false;
  var phase = "ready";
  var frozenA = 1;
  var best = readBest();
  var won = false;
  var keysFirst = false;
  var dead = false;
  var dieAt = 0;
  var dieDir = DIRS[1];
  var dieTurn = 0;
  var impactAt = 1e12;
  var fadeAt = 0;
  var bornAt = -1e9;
  var epoch = 0;
  var simNow = 0;
  var lastCommit = 0;
  var lastTs = -1;
  var vfood = [];
  var bulges = [];
  var timers = [];
  var parts = [];
  var rings = [];
  var gulpAt = -1e9;
  var blinkAt = -1e9;
  var blinkNext = 1600;
  var blinkTwice = false;
  var tongueAt = -1e9;
  var tongueNext = 900;
  var shaking = false;

  var SX = new Float32Array(SMAX);
  var SY = new Float32Array(SMAX);
  var SR = new Float32Array(SMAX);
  var STX = new Float32Array(SMAX);
  var STY = new Float32Array(SMAX);
  var SS = new Float32Array(SMAX);
  var sn = 0;
  var curL = 1;
  var TP = { x: 0, y: 0, tx: 1, ty: 0 };
  var HP = { x: 0, y: 0, tx: 1, ty: 0 };

  var pal = { base: [], rim: [], band: [], spec: [], mark: [], shadow: "#000", shadowA: 0.5, cellA: "#0b1630", cellB: "#0d1a38", sheen: "#13244a", vignette: "#030814" };

  var frame;
  var board;
  var canvas;
  var ctx;
  var hintEl;
  var scoreEl;
  var bestEl;
  var chipEl;
  var overEl;
  var overTitle;
  var overNum;
  var overSub;
  var overBtn;
  var sprites = [];
  var bgCv = document.createElement("canvas");
  var shCv = document.createElement("canvas");
  var fruitCv = document.createElement("canvas");
  var glowCv = document.createElement("canvas");
  var view = { w: 0, h: 0, dpr: 0, ux: 1, uy: 1, ready: false };

  function readColor(cs, name, def) {
    var s = (cs.getPropertyValue(name) || "").trim();
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(s) ? s : def;
  }

  function buildPalette() {
    var cs = window.getComputedStyle(document.documentElement);
    var c1 = hexRgb(readColor(cs, "--ts-snake-1", "#2f5cf2"));
    var c2 = hexRgb(readColor(cs, "--ts-snake-2", "#3f86ee"));
    var c3 = hexRgb(readColor(cs, "--ts-snake-3", "#64bbea"));
    var deep = hexRgb(readColor(cs, "--ts-snake-deep", "#04103c"));
    var white = [255, 255, 255];
    for (var i = 0; i < SHADES; i++) {
      var t = i / (SHADES - 1);
      var b = t < 0.55 ? mix(c1, c2, t / 0.55) : mix(c2, c3, (t - 0.55) / 0.45);
      pal.base[i] = rgba(b);
      pal.rim[i] = rgba(mix(b, deep, 0.66));
      pal.band[i] = rgba(mix(b, white, 0.3));
      pal.spec[i] = rgba(mix(b, white, 0.85), 0.9);
      pal.mark[i] = rgba(mix(b, deep, 0.55), 0.5);
    }
    pal.shadow = readColor(cs, "--ts-shadow", "#000510");
    var sa = parseFloat(cs.getPropertyValue("--ts-shadow-a"));
    pal.shadowA = isFinite(sa) ? sa : 0.5;
    pal.cellA = readColor(cs, "--ts-cell-a", "#0b1630");
    pal.cellB = readColor(cs, "--ts-cell-b", "#0d1a38");
    pal.sheen = readColor(cs, "--ts-sheen", "#13244a");
    pal.vignette = readColor(cs, "--ts-vignette", "#030814");
  }

  function shade(s) {
    return Math.min(SHADES - 1, Math.max(0, Math.round((s / curL) * (SHADES - 1))));
  }

  function paintBoard() {
    bgCv.width = canvas.width;
    bgCv.height = canvas.height;
    var c = bgCv.getContext("2d");
    c.setTransform(view.ux, 0, 0, view.uy, 0, 0);
    c.fillStyle = pal.cellA;
    c.fillRect(0, 0, COLS, ROWS);
    c.beginPath();
    for (var y = 0; y < ROWS; y++) {
      for (var x = 0; x < COLS; x++) {
        if ((x + y) % 2) { c.rect(x, y, 1, 1); }
      }
    }
    c.fillStyle = pal.cellB;
    c.fill();
    var sh = hexRgb(pal.sheen);
    var g = c.createLinearGradient(0, 0, 0, ROWS);
    g.addColorStop(0, rgba(sh, 0.55));
    g.addColorStop(0.45, rgba(sh, 0));
    c.fillStyle = g;
    c.fillRect(0, 0, COLS, ROWS);
    var vg = hexRgb(pal.vignette);
    var rg = c.createRadialGradient(COLS / 2, ROWS * 0.46, Math.min(COLS, ROWS) * 0.3, COLS / 2, ROWS * 0.5, Math.max(COLS, ROWS) * 0.78);
    rg.addColorStop(0, rgba(vg, 0));
    rg.addColorStop(1, rgba(vg, 0.55));
    c.fillStyle = rg;
    c.fillRect(0, 0, COLS, ROWS);
  }

  function paintFruit() {
    var S = Math.max(8, Math.ceil(view.ux));
    fruitCv.width = S;
    fruitCv.height = S;
    var c = fruitCv.getContext("2d");
    c.setTransform(S, 0, 0, S, 0, 0);
    c.translate(0.5, 0.53);
    var R = 0.27;
    var g = c.createRadialGradient(-0.09, -0.11, 0.01, -0.02, -0.02, 0.37);
    g.addColorStop(0, "#fff7d6");
    g.addColorStop(0.2, "#ffd85e");
    g.addColorStop(0.62, "#ffa31f");
    g.addColorStop(1, "#dc5a0a");
    c.beginPath();
    c.ellipse(0, 0, R, R * 0.94, 0, 0, TAU);
    c.fillStyle = g;
    c.fill();
    c.beginPath();
    for (var a = 0; a <= TAU * 1.35; a += 0.12) {
      var rr = 0.018 + a * 0.019;
      var px = 0.035 + Math.cos(a + 2.2) * rr;
      var py = 0.03 + Math.sin(a + 2.2) * rr;
      if (a === 0) { c.moveTo(px, py); } else { c.lineTo(px, py); }
    }
    c.strokeStyle = "rgba(190, 74, 6, .45)";
    c.lineWidth = 0.028;
    c.lineCap = "round";
    c.lineJoin = "round";
    c.stroke();
    c.beginPath();
    c.arc(0, 0, R - 0.035, 0.2, 1.4);
    c.strokeStyle = "rgba(255, 238, 180, .55)";
    c.lineWidth = 0.022;
    c.stroke();
    c.beginPath();
    c.moveTo(0.0, -R + 0.04);
    c.quadraticCurveTo(0.0, -R - 0.05, 0.05, -R - 0.09);
    c.strokeStyle = "#6a3a10";
    c.lineWidth = 0.036;
    c.stroke();
    c.save();
    c.translate(0.035, -R - 0.05);
    c.rotate(-0.45);
    c.beginPath();
    c.moveTo(0, 0);
    c.quadraticCurveTo(0.08, -0.085, 0.2, -0.02);
    c.quadraticCurveTo(0.1, 0.06, 0, 0);
    var lg = c.createLinearGradient(0, -0.06, 0.18, 0.03);
    lg.addColorStop(0, "#5ae6b2");
    lg.addColorStop(1, "#139a74");
    c.fillStyle = lg;
    c.fill();
    c.beginPath();
    c.moveTo(0.015, 0);
    c.quadraticCurveTo(0.09, -0.025, 0.17, -0.02);
    c.strokeStyle = "rgba(255, 255, 255, .35)";
    c.lineWidth = 0.012;
    c.stroke();
    c.restore();
    c.beginPath();
    c.ellipse(-0.1, -0.12, 0.075, 0.038, -0.7, 0, TAU);
    c.fillStyle = "rgba(255, 255, 255, .88)";
    c.fill();

    var G = Math.max(8, Math.ceil(view.ux * 2));
    glowCv.width = G;
    glowCv.height = G;
    var gc = glowCv.getContext("2d");
    var gg = gc.createRadialGradient(G / 2, G / 2, 0, G / 2, G / 2, G / 2);
    gg.addColorStop(0, "rgba(255, 196, 80, .5)");
    gg.addColorStop(0.35, "rgba(255, 170, 50, .2)");
    gg.addColorStop(1, "rgba(255, 150, 40, 0)");
    gc.fillStyle = gg;
    gc.fillRect(0, 0, G, G);
    for (var k = 0; k < sprites.length; k++) { paintSprite(sprites[k]); }
  }

  function paintSprite(sp) {
    if (!view.ready) { return; }
    var G = Math.max(8, Math.ceil(view.ux * 2));
    sp.glow.width = G;
    sp.glow.height = G;
    var gc = sp.glow.getContext("2d");
    var gg = gc.createRadialGradient(G / 2, G / 2, 0, G / 2, G / 2, G / 2);
    gg.addColorStop(0, "rgba(" + sp.col + ", .5)");
    gg.addColorStop(0.35, "rgba(" + sp.col + ", .2)");
    gg.addColorStop(1, "rgba(" + sp.col + ", 0)");
    gc.fillStyle = gg;
    gc.fillRect(0, 0, G, G);
    if (!sp.ok) { return; }
    var S = Math.max(8, Math.ceil(view.ux * 1.06));
    sp.cv.width = S;
    sp.cv.height = S;
    var c = sp.cv.getContext("2d");
    c.imageSmoothingEnabled = true;
    if ("imageSmoothingQuality" in c) { c.imageSmoothingQuality = "high"; }
    c.drawImage(sp.img, 0, 0, S, S);
  }

  function loadSprites() {
    for (var i = 0; i < FRUITS.length; i++) {
      (function (f) {
        var sp = { img: new Image(), cv: document.createElement("canvas"), glow: document.createElement("canvas"), col: f.glow, juice: f.juice, ok: false };
        sp.img.decoding = "async";
        sp.img.addEventListener("load", function () {
          sp.ok = true;
          paintSprite(sp);
        });
        sp.img.src = f.src;
        sprites.push(sp);
      })(FRUITS[i]);
    }
  }

  function resize() {
    if (!board || !canvas) { return; }
    var w = board.clientWidth;
    var h = board.clientHeight;
    if (w && !h) {
      h = Math.round(w * ROWS / COLS);
      board.style.height = h + "px";
    }
    if (!w || !h) { return; }
    var dpr = Math.min(3, window.devicePixelRatio || 1);
    if (w === view.w && h === view.h && dpr === view.dpr) { return; }
    view.w = w;
    view.h = h;
    view.dpr = dpr;
    canvas.width = Math.round(w * dpr);
    canvas.height = Math.round(h * dpr);
    view.ux = canvas.width / COLS;
    view.uy = canvas.height / ROWS;
    shCv.width = Math.max(4, Math.ceil(canvas.width / SHADOW_DOWN));
    shCv.height = Math.max(4, Math.ceil(canvas.height / SHADOW_DOWN));
    view.ready = true;
    paintBoard();
    paintFruit();
  }

  function later(at, fn) {
    timers.push({ at: at, fn: fn, epoch: epoch });
  }

  function runTimers() {
    for (var guard = 0; guard < 32; guard++) {
      var idx = -1;
      for (var i = 0; i < timers.length; i++) {
        if (timers[i].at <= simNow && (idx < 0 || timers[i].at < timers[idx].at)) { idx = i; }
      }
      if (idx < 0) { return; }
      var t = timers.splice(idx, 1)[0];
      if (t.epoch === epoch) { t.fn(t.at); }
    }
  }

  function haptic(kind) {
    if (!inTelegram() || !ver("6.1") || !tg.HapticFeedback) { return; }
    call(function () {
      if (kind === "error" || kind === "success") { tg.HapticFeedback.notificationOccurred(kind); }
      else if (kind === "select") { tg.HapticFeedback.selectionChanged(); }
      else { tg.HapticFeedback.impactOccurred(kind); }
    });
  }

  function addFood(f, at) {
    if (!f) { return; }
    vfood.push({ x: f.x, y: f.y, kind: f.kind, born: at, popAt: 0, phase: fxr() * 5000 });
    later(at, function () {
      if (!calm) { rings.push({ x: f.x + 0.5, y: f.y + 0.5, born: at, life: 520, r0: 0.12, r1: 0.62, w: 0.05, col: fruitCol(f.kind), a: 0.5 }); }
    });
    later(at + 260, function (t) { flick(t, false); });
  }

  function fruitCol(kind) {
    return FRUITS[kind] ? FRUITS[kind].glow : "255,200,90";
  }

  function burst(x, y, at, kind) {
    var juice = FRUITS[kind] ? FRUITS[kind].juice : JUICE;
    rings.push({ x: x, y: y, born: at, life: 420, r0: 0.22, r1: 0.95, w: 0.07, col: fruitCol(kind), a: 0.6 });
    if (calm) { return; }
    var n = 10;
    for (var i = 0; i < n; i++) {
      var ang = (i / n) * TAU + fxr() * 0.5;
      var sp = 2.4 + fxr() * 2.2;
      parts.push({
        x: x, y: y,
        vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp,
        r: 0.045 + fxr() * 0.045,
        born: at, life: 380 + fxr() * 220,
        col: juice[i % juice.length]
      });
    }
  }

  function eat(food, next, at) {
    for (var i = 0; i < vfood.length; i++) {
      var f = vfood[i];
      if (!f.popAt && f.x === food.x && f.y === food.y) { f.popAt = at; }
    }
    burst(food.x + 0.5, food.y + 0.5, at, food.kind);
    bulges.push({ at: at, s: 0.3, a: 0 });
    gulpAt = at;
    haptic("light");
    showScore(true);
    addFood(next, at + 170);
  }

  function flick(t, force) {
    if (!force && t - tongueAt < 1100) { return; }
    tongueAt = t;
    tongueNext = t + 2600 + fxr() * 3600;
  }

  function commit() {
    var ev = stepGame(game);
    if (!ev) { return; }
    if (ev.died) { die(ev); return; }
    ghost = ev.tail;
    grewLast = ev.grew;
    track = buildTrack(game.cells, ghost);
    if (ev.ate) {
      var food = ev.ate;
      var next = game.food;
      later(lastCommit + EAT_AT * TICK, function (at) { eat(food, next, at); });
      if (!next) {
        won = true;
        phase = "won";
        later(lastCommit + EAT_AT * TICK + 700, function () { showOver(); });
      }
    }
  }

  function die(ev) {
    phase = "dying";
    dead = true;
    dieAt = lastCommit;
    dieDir = ev.dir;
    trackAt(track, LAG, HP);
    var turn = Math.atan2(dieDir.y, dieDir.x) - Math.atan2(HP.ty, HP.tx);
    while (turn > Math.PI) { turn -= TAU; }
    while (turn < -Math.PI) { turn += TAU; }
    dieTurn = Math.abs(turn) < 0.01 ? 0 : turn;
    impactAt = dieAt + IMPACT * TICK;
    later(impactAt, bonk);
    later(dieAt + OVER_AT, function () { showOver(); });
  }

  function bonk(at) {
    haptic("error");
    if (calm) { return; }
    shaking = true;
    trackAt(track, LAG - deathPush(), HP);
    var face = Math.atan2(HP.ty, HP.tx) + deathTurn();
    var x = HP.x + Math.cos(face) * 0.7;
    var y = HP.y + Math.sin(face) * 0.7;
    for (var i = 0; i < 7; i++) {
      var ang = face + Math.PI + (i - 3) * 0.42 + (fxr() - 0.5) * 0.2;
      var sp = 1.6 + fxr() * 1.8;
      parts.push({ x: x, y: y, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp, r: 0.03 + fxr() * 0.03, born: at, life: 300 + fxr() * 160, col: i % 2 ? "#ffffff" : "#bfe4ff" });
    }
    rings.push({ x: x, y: y, born: at, life: 300, r0: 0.08, r1: 0.5, w: 0.05, col: "220,236,255", a: 0.7 });
  }

  function fadeOut(at) {
    phase = "fading";
    fadeAt = at;
    later(at + 380, respawn);
  }

  function restart() {
    if (phase !== "over") { return; }
    hideOver();
    fadeOut(simNow);
    sync();
  }

  function respawn(at) {
    epoch += 1;
    timers = [];
    resetGame(game);
    ghost = null;
    grewLast = false;
    track = buildTrack(game.cells, null);
    phase = "ready";
    won = false;
    dead = false;
    impactAt = 1e12;
    lastCommit = at;
    bornAt = at;
    bulges = [];
    vfood = [];
    addFood(game.food, at + 240);
    showScore(false);
    sync();
  }

  function begin(d) {
    if (d && d.x === -game.dir.x && d.y === -game.dir.y) { return; }
    phase = "live";
    if (d) { steer(game, d); }
    lastCommit = simNow;
    commit();
    haptic("select");
    sync();
  }

  function pause() {
    if (phase !== "live") { return; }
    frozenA = clamp((simNow - lastCommit) / TICK, 0, 1);
    phase = "paused";
    sync();
  }

  function resume() {
    if (phase !== "paused") { return; }
    lastCommit = simNow - frozenA * TICK;
    phase = "live";
    sync();
  }

  function readBest() {
    var v = 0;
    try { v = parseInt(window.localStorage.getItem(BEST_KEY), 10); } catch (e) { v = 0; }
    return isFinite(v) && v > 0 ? v : 0;
  }

  function saveBest(v) {
    try { window.localStorage.setItem(BEST_KEY, String(v)); } catch (e) { return; }
  }

  function showScore(pop) {
    if (!scoreEl) { return; }
    scoreEl.textContent = String(game.score);
    bestEl.textContent = String(Math.max(best, game.score));
    if (pop && !calm) {
      scoreEl.classList.remove("is-pop");
      void scoreEl.offsetWidth;
      scoreEl.classList.add("is-pop");
    }
  }

  function showOver() {
    if (phase !== "dying" && phase !== "won") { return; }
    var rec = game.score > best;
    if (rec) {
      best = game.score;
      saveBest(best);
    }
    phase = "over";
    overTitle.textContent = won ? T.win : rec ? T.record : T.over;
    overNum.textContent = String(game.score);
    overSub.textContent = T.eaten;
    overEl.classList.toggle("is-record", rec || won);
    showScore(false);
    sync();
    if (rec || won) { haptic("success"); }
  }

  function hideOver() {
    overEl.classList.remove("is-record");
  }

  function deathPush() {
    if (!dead) { return 0; }
    var reach = dieTurn ? 0.12 : IMPACT;
    var hit = IMPACT * TICK;
    var t = simNow - dieAt;
    if (t < hit) { return reach * t / hit; }
    return reach - (dieTurn ? 0.05 : 0.13) * easeOutCubic(Math.min(1, (t - hit) / 220));
  }

  function deathTurn() {
    if (!dead || !dieTurn) { return 0; }
    var hit = IMPACT * TICK;
    var t = simNow - dieAt;
    var e = easeOutCubic(Math.min(1, t / hit));
    var back = t > hit ? 0.14 * easeOutCubic(Math.min(1, (t - hit) / 240)) : 0;
    return dieTurn * (e - back);
  }

  function snakeAlpha() {
    if (phase === "fading") { return 1 - smooth(0, 380, simNow - fadeAt); }
    return smooth(0, 320, simNow - bornAt);
  }

  function radiusAt(s, L) {
    var r = s < 1 ? lerp(R_NECK, R_BODY, smooth(0.1, 1, s)) : R_BODY;
    var tl = Math.min(L * 0.62, 3.8);
    var u = (s - (L - tl)) / tl;
    if (u > 0) { r *= 1 - 0.8 * Math.pow(Math.min(u, 1), 1.3); }
    for (var i = 0; i < bulges.length; i++) {
      var b = bulges[i];
      if (b.a <= 0) { continue; }
      var d = (s - b.s) / 0.42;
      if (d > -3 && d < 3) { r *= 1 + b.a * Math.exp(-d * d); }
    }
    return r;
  }

  function updateBulges(L) {
    for (var i = bulges.length - 1; i >= 0; i--) {
      var b = bulges[i];
      var t = simNow - b.at;
      b.s = 0.3 + (t / TICK) * BULGE_SPEED;
      b.a = BULGE_AMP * smooth(0, 90, t);
      if (b.s > L + 0.6) { bulges.splice(i, 1); }
    }
  }

  function sampleBody(vh, L) {
    var vt = vh + L;
    var v = vh;
    var amp = calm ? 0 : WAVE_AMP;
    sn = 0;
    for (;;) {
      var s = v - vh;
      var r = radiusAt(s, L);
      trackAt(track, v, TP);
      var x = TP.x;
      var y = TP.y;
      if (amp > 0) {
        var e = smooth(0.5, 2.2, s) * amp * Math.sin(TAU * (game.steps - v) / WAVE_LEN);
        x -= TP.ty * e;
        y += TP.tx * e;
      }
      SX[sn] = x;
      SY[sn] = y;
      SR[sn] = r;
      STX[sn] = TP.tx;
      STY[sn] = TP.ty;
      SS[sn] = s;
      sn++;
      if (v >= vt || sn >= SMAX) { break; }
      v = Math.min(vt, v + clamp(r * 0.26, 0.02, 0.1));
    }
  }

  function circles(c, a, b, kR, off) {
    c.beginPath();
    for (var i = a; i <= b; i++) {
      var r = SR[i];
      var x = SX[i] + LX * r * off;
      var y = SY[i] + LY * r * off;
      var rr = r * kR;
      c.moveTo(x + rr, y);
      c.arc(x, y, rr, 0, TAU);
    }
  }

  function bodyLayer(c, kR, off, colors) {
    if (sn < 2) { return; }
    var chunks = Math.ceil((sn - 1) / CHUNK);
    for (var k = chunks - 1; k >= 0; k--) {
      var a = k * CHUNK;
      var b = Math.min(sn - 1, a + CHUNK);
      circles(c, a, b, kR, off);
      c.fillStyle = colors[shade(SS[(a + b) >> 1])];
      c.fill();
    }
  }

  function drawMarks(c, L) {
    var i = 0;
    c.lineCap = "round";
    c.lineJoin = "round";
    for (var s = 0.62; s < L - 0.7; s += 0.5) {
      while (i < sn - 2 && SS[i + 1] < s) { i++; }
      var span = SS[i + 1] - SS[i];
      var t = span > 0 ? clamp((s - SS[i]) / span, 0, 1) : 0;
      var x = lerp(SX[i], SX[i + 1], t);
      var y = lerp(SY[i], SY[i + 1], t);
      var r = lerp(SR[i], SR[i + 1], t);
      var tx = lerp(STX[i], STX[i + 1], t);
      var ty = lerp(STY[i], STY[i + 1], t);
      var tl = Math.sqrt(tx * tx + ty * ty) || 1;
      tx /= tl;
      ty /= tl;
      var a = 0.13 * (r / R_BODY);
      var w = r * 0.62;
      c.beginPath();
      c.moveTo(x - tx * a - ty * w, y - ty * a + tx * w);
      c.lineTo(x + tx * a, y + ty * a);
      c.lineTo(x - tx * a + ty * w, y - ty * a - tx * w);
      c.strokeStyle = pal.mark[shade(s)];
      c.lineWidth = 0.065 * (r / R_BODY);
      c.stroke();
    }
  }

  function glossLine(c, colors) {
    var first = 0;
    while (first < sn - 1 && SS[first] < 0.24) { first++; }
    if (sn - first < 2) { return; }
    var chunks = Math.ceil((sn - 1 - first) / CHUNK);
    c.lineCap = "round";
    c.lineJoin = "round";
    for (var k = chunks - 1; k >= 0; k--) {
      var a = first + k * CHUNK;
      var b = Math.min(sn - 1, a + CHUNK);
      var mid = (a + b) >> 1;
      c.beginPath();
      for (var i = a; i <= b; i++) {
        var r = SR[i];
        var x = SX[i] + LX * r * 0.5;
        var y = SY[i] + LY * r * 0.5;
        if (i === a) { c.moveTo(x, y); } else { c.lineTo(x, y); }
      }
      c.strokeStyle = colors[shade(SS[mid])];
      c.lineWidth = SR[mid] * 0.34;
      c.stroke();
    }
  }

  function headPath(c, k, ox, oy) {
    c.beginPath();
    for (var i = 0; i < HEAD.length; i++) {
      var p = HEAD[i];
      var q = [];
      for (var j = 1; j < p.length; j += 2) {
        q.push(HCX + (p[j] - HCX) * k + ox, p[j + 1] * k + oy);
      }
      if (p[0] === "m") { c.moveTo(q[0], q[1]); }
      else if (p[0] === "q") { c.quadraticCurveTo(q[0], q[1], q[2], q[3]); }
      else { c.bezierCurveTo(q[0], q[1], q[2], q[3], q[4], q[5]); }
    }
    c.closePath();
  }

  function eyeOpen() {
    if (calm) { return 1; }
    var t = simNow - blinkAt;
    var d = 150;
    if (t >= 0 && t < d) { return 1 - 0.92 * Math.sin(Math.PI * t / d); }
    if (blinkTwice && t >= d + 70 && t < 2 * d + 70) { return 1 - 0.92 * Math.sin(Math.PI * (t - d - 70) / d); }
    return 1;
  }

  function tongueOut() {
    if (calm || dead || simNow - gulpAt < 320) { return 0; }
    var t = simNow - tongueAt;
    var d = 460;
    if (t < 0 || t > d) { return 0; }
    return Math.pow(Math.sin(Math.PI * t / d), 0.65);
  }

  function drawTongue(c, e) {
    var t = simNow - tongueAt;
    var len = 0.42 * e;
    var wig = Math.sin(t * 0.055) * 0.035 * e;
    var x0 = 0.68;
    var x1 = x0 + len;
    var spread = 0.42 + 0.2 * Math.sin(t * 0.08);
    var fl = 0.12 * e;
    c.beginPath();
    c.moveTo(x0, 0);
    c.quadraticCurveTo(x0 + len * 0.5, -wig, x1, wig);
    c.moveTo(x1, wig);
    c.lineTo(x1 + Math.cos(spread) * fl, wig - Math.sin(spread) * fl);
    c.moveTo(x1, wig);
    c.lineTo(x1 + Math.cos(spread) * fl, wig + Math.sin(spread) * fl);
    c.strokeStyle = TONGUE;
    c.lineWidth = 0.055;
    c.lineCap = "round";
    c.lineJoin = "round";
    c.stroke();
  }

  function visibleFood() {
    for (var i = vfood.length - 1; i >= 0; i--) {
      var f = vfood[i];
      if (!f.popAt && simNow >= f.born) { return f; }
    }
    return null;
  }

  function headPose(c, x, y, ang, k, qx, qy) {
    c.translate(x, y);
    c.rotate(ang);
    c.translate(HCX, 0);
    c.scale(k * qx, k * qy);
    c.translate(-HCX, 0);
  }

  function headLayer(c, x, y, ang, k, qx, qy, layer) {
    var ca = Math.cos(ang);
    var sa = Math.sin(ang);
    var llx = LX * ca + LY * sa;
    var lly = -LX * sa + LY * ca;
    c.save();
    headPose(c, x, y, ang, k, qx, qy);
    if (layer === 0) {
      var e = tongueOut();
      if (e > 0.01) { drawTongue(c, e); }
      headPath(c, 1, 0, 0);
      c.fillStyle = pal.rim[0];
    } else if (layer === 1) {
      headPath(c, 0.86, llx * 0.035, lly * 0.035);
      c.fillStyle = pal.base[0];
    } else {
      headPath(c, 0.56, llx * 0.13, lly * 0.13);
      c.fillStyle = pal.band[0];
    }
    c.fill();
    c.restore();
  }

  function drawHead(c, x, y, ang, k, qx, qy) {
    var ca = Math.cos(ang);
    var sa = Math.sin(ang);
    var llx = LX * ca + LY * sa;
    var lly = -LX * sa + LY * ca;
    c.save();
    headPose(c, x, y, ang, k, qx, qy);

    c.beginPath();
    c.ellipse(HCX + llx * 0.22, lly * 0.22, 0.11, 0.042, Math.atan2(lly, llx) + Math.PI / 2, 0, TAU);
    c.fillStyle = pal.spec[0];
    c.fill();

    c.fillStyle = pal.rim[0];
    c.beginPath();
    c.arc(0.66, -0.09, 0.021, 0, TAU);
    c.moveTo(0.681, 0.09);
    c.arc(0.66, 0.09, 0.021, 0, TAU);
    c.fill();

    var f = visibleFood();
    var lookX = 1;
    var lookY = 0;
    if (f) {
      var dx = f.x + 0.5 - x;
      var dy = f.y + 0.5 - y;
      lookX = dx * ca + dy * sa;
      lookY = -dx * sa + dy * ca;
      var ll = Math.sqrt(lookX * lookX + lookY * lookY) || 1;
      lookX /= ll;
      lookY /= ll;
    }
    var open = eyeOpen();
    var hurt = dead && simNow >= impactAt;
    for (var side = -1; side <= 1; side += 2) {
      var ex = 0.3;
      var ey = 0.25 * side;
      var er = 0.13;
      c.beginPath();
      c.arc(ex, ey, er * 1.2, 0, TAU);
      c.fillStyle = pal.rim[0];
      c.fill();
      if (hurt) {
        c.beginPath();
        var xr = er * 0.72;
        c.moveTo(ex - xr, ey - xr);
        c.lineTo(ex + xr, ey + xr);
        c.moveTo(ex + xr, ey - xr);
        c.lineTo(ex - xr, ey + xr);
        c.strokeStyle = "#f2f8ff";
        c.lineWidth = 0.045;
        c.lineCap = "round";
        c.stroke();
        continue;
      }
      c.beginPath();
      c.ellipse(ex, ey, er * open, er, 0, 0, TAU);
      c.fillStyle = "#f2f8ff";
      c.fill();
      if (open > 0.75) {
        var la = Math.atan2(lly, llx) + Math.PI;
        c.beginPath();
        c.arc(ex, ey, er * 0.84, la - 1.15, la + 1.15);
        c.strokeStyle = "rgba(48, 82, 170, .28)";
        c.lineWidth = er * 0.3;
        c.lineCap = "round";
        c.stroke();
      }
      if (open > 0.3) {
        var px = ex + lookX * 0.042;
        var py = ey + lookY * 0.042;
        c.beginPath();
        c.ellipse(px, py, 0.074 * open, 0.074, 0, 0, TAU);
        c.fillStyle = "#06102e";
        c.fill();
        c.beginPath();
        c.arc(px + llx * 0.03, py + lly * 0.03, 0.024, 0, TAU);
        c.fillStyle = "#ffffff";
        c.fill();
      }
    }

    var flash = simNow >= impactAt ? Math.exp(-(simNow - impactAt) / 90) : 0;
    if (flash > 0.02) {
      headPath(c, 1.02, 0, 0);
      c.fillStyle = "rgba(255, 255, 255, " + (0.6 * flash).toFixed(3) + ")";
      c.fill();
    }
    c.restore();
  }

  function sparkle(c, x, y, R) {
    var k = R * 0.18;
    c.moveTo(x, y - R);
    c.quadraticCurveTo(x + k, y - k, x + R, y);
    c.quadraticCurveTo(x + k, y + k, x, y + R);
    c.quadraticCurveTo(x - k, y + k, x - R, y);
    c.quadraticCurveTo(x - k, y - k, x, y - R);
  }

  function drawStars(c, x, y) {
    if (!dead || calm || simNow < impactAt + 40) { return; }
    var t = simNow - impactAt - 40;
    var life = 1350;
    if (t > life) { return; }
    var a = smooth(0, 150, t) * (1 - smooth(life - 320, life, t));
    c.save();
    c.globalAlpha = a;
    c.beginPath();
    for (var i = 0; i < 3; i++) {
      var ang = t * 0.0062 + (i * TAU) / 3;
      var sx = x + Math.cos(ang) * 0.58;
      var sy = y + Math.sin(ang) * 0.26;
      sparkle(c, sx, sy, 0.14 + 0.035 * Math.sin(t * 0.012 + i * 2));
    }
    c.fillStyle = "#ffd54a";
    c.fill();
    c.lineWidth = 0.025;
    c.strokeStyle = "rgba(120, 60, 0, .55)";
    c.stroke();
    c.restore();
  }

  function foodState(f) {
    var t = simNow - f.born;
    if (t < 0) { return null; }
    var k = 1;
    var a = 1;
    if (f.popAt) {
      var p = (simNow - f.popAt) / 150;
      if (p >= 1) { return null; }
      k = 1 + 0.4 * p;
      a = 1 - p;
    } else if (calm) {
      a = smooth(0, 260, t);
    } else if (t < 420) {
      k = Math.max(0, easeOutBack(t / 420));
    }
    if (phase === "fading" && !f.popAt) { a *= snakeAlpha(); }
    var bob = calm ? 0 : (Math.sin(((simNow + f.phase) / 1900) * TAU) * 0.5 + 0.5) * 0.07;
    var wob = calm ? 0 : Math.sin(((simNow + f.phase) / 2700) * TAU) * 0.08;
    var glow = calm ? 0.8 : 0.72 + 0.28 * Math.sin(((simNow + f.phase) / 1300) * TAU);
    return { k: k, a: a, bob: bob, wob: wob, glow: glow };
  }

  function drawFoodUnder(c) {
    for (var i = 0; i < vfood.length; i++) {
      var f = vfood[i];
      var s = foodState(f);
      if (!s) { continue; }
      var cx = f.x + 0.5;
      var cy = f.y + 0.5;
      var gk = Math.min(1.1, s.k);
      var sp = sprites[f.kind];
      var real = !!(sp && sp.ok);
      c.globalAlpha = s.a * s.glow;
      c.drawImage(sp && sp.glow.width ? sp.glow : glowCv, cx - gk, cy - gk, gk * 2, gk * 2);
      c.globalAlpha = s.a * (0.5 - s.bob * 2.2);
      c.beginPath();
      if (real) { c.ellipse(cx, cy + 0.42, 0.27 * gk * (1 - s.bob * 1.8), 0.07 * gk, 0, 0, TAU); }
      else { c.ellipse(cx, cy + 0.29, 0.19 * gk * (1 - s.bob * 1.8), 0.06 * gk, 0, 0, TAU); }
      c.fillStyle = pal.shadow;
      c.fill();
    }
    c.globalAlpha = 1;
  }

  function drawFood(c) {
    for (var i = vfood.length - 1; i >= 0; i--) {
      var f = vfood[i];
      if (f.popAt && simNow - f.popAt > 400) { vfood.splice(i, 1); continue; }
      var s = foodState(f);
      if (!s || s.k <= 0.001) { continue; }
      c.save();
      c.globalAlpha = s.a;
      c.translate(f.x + 0.5, f.y + 0.5 - s.bob);
      c.rotate(s.wob);
      var sp = sprites[f.kind];
      if (sp && sp.ok && sp.cv.width) {
        var z = 1.06 * s.k;
        c.drawImage(sp.cv, -z / 2, -z / 2 - 0.02, z, z);
      } else {
        c.drawImage(fruitCv, -0.5 * s.k, -0.53 * s.k, s.k, s.k);
      }
      c.restore();
    }
  }

  function drawFx(c) {
    for (var i = parts.length - 1; i >= 0; i--) {
      var p = parts[i];
      var age = (simNow - p.born) / 1000;
      var t = (simNow - p.born) / p.life;
      if (t >= 1) { parts.splice(i, 1); continue; }
      if (t < 0) { continue; }
      var dr = 6;
      var m = (1 - Math.exp(-dr * age)) / dr;
      c.globalAlpha = 1 - t * t;
      c.beginPath();
      c.arc(p.x + p.vx * m, p.y + p.vy * m, p.r * (1 - 0.45 * t), 0, TAU);
      c.fillStyle = p.col;
      c.fill();
    }
    c.globalAlpha = 1;
    for (var j = rings.length - 1; j >= 0; j--) {
      var g = rings[j];
      var u = (simNow - g.born) / g.life;
      if (u >= 1) { rings.splice(j, 1); continue; }
      if (u < 0) { continue; }
      var eu = easeOutCubic(u);
      c.beginPath();
      c.arc(g.x, g.y, lerp(g.r0, g.r1, eu), 0, TAU);
      c.strokeStyle = "rgba(" + g.col + "," + (g.a * (1 - u)).toFixed(3) + ")";
      c.lineWidth = Math.max(0.005, g.w * (1 - eu));
      c.stroke();
    }
  }

  function drawShadow(hx, hy, ang, k, qx, qy, alpha) {
    var c = shCv.getContext("2d");
    c.setTransform(1, 0, 0, 1, 0, 0);
    c.clearRect(0, 0, shCv.width, shCv.height);
    c.setTransform(shCv.width / COLS, 0, 0, shCv.height / ROWS, 0, 0);
    c.fillStyle = pal.shadow;
    circles(c, 0, sn - 1, 1.05, 0);
    c.fill();
    c.save();
    headPose(c, hx, hy, ang, k, qx, qy);
    headPath(c, 1.03, 0, 0);
    c.fill();
    c.restore();
    ctx.save();
    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = alpha * pal.shadowA;
    ctx.drawImage(shCv, view.ux * 0.06, view.uy * 0.14, canvas.width, canvas.height);
    ctx.restore();
  }

  function applyShake() {
    if (!shaking) { return; }
    var t = simNow - impactAt;
    if (t > 420 || calm) {
      shaking = false;
      frame.style.transform = "";
      return;
    }
    var d = 3.6 * Math.exp(-t / 120) * Math.sin(t * 0.075);
    var p = 1.2 * Math.exp(-t / 90) * Math.sin(t * 0.11);
    frame.style.transform = "translate(" + (dieDir.x * d - dieDir.y * p).toFixed(2) + "px," + (dieDir.y * d + dieDir.x * p).toFixed(2) + "px)";
  }

  function render() {
    if (!view.ready) { return; }
    var a = phase === "live" ? clamp((simNow - lastCommit) / TICK, 0, 1) : phase === "paused" ? frozenA : 1;
    var n = game.cells.length;
    var vh = LAG + (1 - a) - deathPush();
    var L = n - 1 + TAIL_OUT - LAG - (grewLast ? 1 - a : 0);
    curL = L;
    updateBulges(L);
    sampleBody(vh, L);
    trackAt(track, vh, HP);
    var hx = HP.x;
    var hy = HP.y;
    var ang = Math.atan2(HP.ty, HP.tx) + deathTurn();
    var gt = simNow - gulpAt;
    var k = gt >= 0 && gt < 260 ? 1 + 0.12 * Math.sin(Math.PI * gt / 260) : 1;
    var qx = 1;
    var qy = 1;
    if (dead && simNow >= impactAt && !calm) {
      var rt = simNow - impactAt;
      var q = Math.exp(-rt / 150) * Math.cos(rt * 0.028);
      qx = 1 - 0.2 * q;
      qy = 1 + 0.14 * q;
    }
    var alpha = snakeAlpha();

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.globalAlpha = 1;
    ctx.drawImage(bgCv, 0, 0);
    ctx.setTransform(view.ux, 0, 0, view.uy, 0, 0);
    drawFoodUnder(ctx);
    if (alpha > 0.002) { drawShadow(hx, hy, ang, k, qx, qy, alpha); }
    drawFood(ctx);
    drawFx(ctx);
    if (alpha > 0.002) {
      ctx.globalAlpha = alpha;
      bodyLayer(ctx, 1, 0, pal.rim);
      headLayer(ctx, hx, hy, ang, k, qx, qy, 0);
      bodyLayer(ctx, 0.84, 0.06, pal.base);
      headLayer(ctx, hx, hy, ang, k, qx, qy, 1);
      bodyLayer(ctx, 0.56, 0.3, pal.band);
      headLayer(ctx, hx, hy, ang, k, qx, qy, 2);
      drawMarks(ctx, L);
      glossLine(ctx, pal.spec);
      drawHead(ctx, hx, hy, ang, k, qx, qy);
      ctx.globalAlpha = 1;
    }
    drawStars(ctx, hx + Math.cos(ang) * HCX, hy + Math.sin(ang) * HCX);
    applyShake();
  }

  function idle() {
    if (simNow >= blinkNext) {
      blinkAt = simNow;
      blinkTwice = fxr() < 0.28;
      blinkNext = simNow + 2300 + fxr() * 3400;
    }
    if (simNow >= tongueNext) { flick(simNow, true); }
  }

  function loop(ts) {
    if (lastTs < 0) { lastTs = ts; }
    var dt = Math.min(50, Math.max(0, ts - lastTs)) * SLOW;
    lastTs = ts;
    simNow += dt;
    for (var guard = 0; guard < 8 && phase === "live" && simNow - lastCommit >= TICK; guard++) {
      lastCommit += TICK;
      commit();
    }
    runTimers();
    idle();
    render();
    raf(loop);
  }

  function sync() {
    if (!root) { return; }
    root.setAttribute("data-phase", phase === "won" ? "dying" : phase);
    var paused = phase === "paused";
    var over = phase === "over";
    overEl.hidden = !(paused || over);
    overEl.classList.toggle("is-pause", paused);
    if (paused) {
      overTitle.textContent = T.pause;
      overNum.textContent = String(game.score);
      overSub.textContent = T.eaten;
      overBtn.textContent = T.resume;
    } else if (over) {
      overBtn.textContent = T.again;
    }
    chipEl.hidden = phase !== "ready";
  }

  function userSteer(d) {
    if (phase === "ready") { begin(d); return; }
    if (phase === "paused") { resume(); }
    if (phase !== "live") { return; }
    if (steer(game, d)) { haptic("select"); }
  }

  function action() {
    if (phase === "ready") { begin(null); }
    else if (phase === "live") { pause(); }
    else if (phase === "paused") { resume(); }
    else if (phase === "over") { restart(); }
  }

  function onOverBtn() {
    if (phase === "paused") { resume(); }
    else if (phase === "over") { restart(); }
  }

  function scheme() {
    var s = params.scheme || (inTelegram() && tg.colorScheme === "light" ? "light" : "dark");
    document.documentElement.setAttribute("data-scheme", s);
    buildPalette();
    if (view.ready) { paintBoard(); }
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

  function build() {
    root.className = "ts";
    root.style.setProperty("--ts-ratio", String(COLS / ROWS));
    var col = el("div", "ts-col");
    var head = el("header", "ts-head");
    head.appendChild(el("h1", "ts-title", T.title));
    var stats = el("div", "ts-stats");
    var s1 = el("div", "ts-stat");
    s1.appendChild(el("span", "ts-stat-k", T.score));
    scoreEl = el("span", "ts-stat-v ts-stat-main", "0");
    scoreEl.setAttribute("aria-live", "polite");
    s1.appendChild(scoreEl);
    var s2 = el("div", "ts-stat");
    s2.appendChild(el("span", "ts-stat-k", T.best));
    bestEl = el("span", "ts-stat-v", String(best));
    s2.appendChild(bestEl);
    stats.appendChild(s1);
    stats.appendChild(s2);
    head.appendChild(stats);
    col.appendChild(head);
    frame = el("div", "ts-frame");
    board = el("div", "ts-board");
    board.style.aspectRatio = COLS + " / " + ROWS;
    canvas = el("canvas", "ts-canvas");
    canvas.setAttribute("role", "img");
    canvas.setAttribute("aria-label", T.board);
    board.appendChild(canvas);
    chipEl = el("div", "ts-chip");
    var arrows = el("span", "ts-chip-ico");
    arrows.setAttribute("aria-hidden", "true");
    chipEl.appendChild(arrows);
    chipEl.appendChild(el("span", "", keysFirst ? T.startKeys : T.startTouch));
    board.appendChild(chipEl);
    overEl = el("div", "ts-over");
    overEl.hidden = true;
    var card = el("div", "ts-card");
    overTitle = el("p", "ts-card-title", T.over);
    overNum = el("p", "ts-card-num", "0");
    overSub = el("p", "ts-card-sub", T.eaten);
    overBtn = el("button", "ts-btn ts-btn-main", T.again);
    overBtn.type = "button";
    card.appendChild(overTitle);
    card.appendChild(overNum);
    card.appendChild(overSub);
    card.appendChild(overBtn);
    overEl.appendChild(card);
    board.appendChild(overEl);
    frame.appendChild(board);
    col.appendChild(frame);
    hintEl = el("p", "ts-hint", keysFirst ? T.hintKeys : T.hintTouch);
    col.appendChild(hintEl);
    root.appendChild(col);
    root.setAttribute("aria-busy", "false");
    ctx = canvas.getContext("2d");
    sync();
  }

  function bindInput() {
    overBtn.addEventListener("click", onOverBtn);
    document.addEventListener("keydown", function (e) {
      if (e.altKey || e.ctrlKey || e.metaKey) { return; }
      if (e.code === "Space" || e.code === "Enter" || e.code === "NumpadEnter") {
        e.preventDefault();
        if (!e.repeat) { action(); }
        return;
      }
      if (e.code === "Escape" || e.code === "KeyP") {
        if (phase === "live") { pause(); } else if (phase === "paused") { resume(); }
        return;
      }
      var k = KEYS[e.code];
      if (k == null) { k = KEYS[e.key]; }
      if (k == null) { return; }
      e.preventDefault();
      if (phase === "over") { return; }
      userSteer(DIRS[k]);
    });
    var sw = null;
    root.addEventListener("pointerdown", function (e) {
      if (e.isPrimary === false || e.button > 0) { return; }
      if (e.target && e.target.closest && e.target.closest("button")) { return; }
      sw = { id: e.pointerId, x: e.clientX, y: e.clientY, moved: false };
      call(function () { root.setPointerCapture(e.pointerId); });
    });
    root.addEventListener("pointermove", function (e) {
      if (!sw || e.pointerId !== sw.id) { return; }
      var dx = e.clientX - sw.x;
      var dy = e.clientY - sw.y;
      var th = 14;
      if (Math.abs(dx) < th && Math.abs(dy) < th) { return; }
      var d = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? DIRS[1] : DIRS[3]) : (dy > 0 ? DIRS[2] : DIRS[0]);
      sw.moved = true;
      if (phase !== "over") { userSteer(d); }
      sw.x = e.clientX;
      sw.y = e.clientY;
    });
    function end(e) {
      if (!sw || e.pointerId !== sw.id) { return; }
      var tap = !sw.moved && e.type === "pointerup";
      sw = null;
      if (tap && phase === "ready") { begin(null); }
    }
    root.addEventListener("pointerup", end);
    root.addEventListener("pointercancel", end);
    document.addEventListener("visibilitychange", function () {
      if (document.hidden) { pause(); }
    });
    window.addEventListener("blur", pause);
    if (typeof window.ResizeObserver === "function") {
      new window.ResizeObserver(resize).observe(board);
    }
    window.addEventListener("resize", resize);
    if (mq) {
      var onCalm = function () { calm = !!mq.matches; };
      if (typeof mq.addEventListener === "function") { mq.addEventListener("change", onCalm); }
      else if (typeof mq.addListener === "function") { mq.addListener(onCalm); }
    }
  }

  document.documentElement.lang = "ru";
  try { keysFirst = !!(window.matchMedia && window.matchMedia("(hover: hover) and (pointer: fine)").matches); } catch (e) { keysFirst = false; }
  scheme();
  loadSprites();
  build();
  bindInput();
  resize();
  addFood(game.food, 200);

  if (inTelegram()) {
    call(function () { tg.ready(); });
    call(function () { tg.expand(); });
    if (ver("7.7") && typeof tg.disableVerticalSwipes === "function") {
      call(function () { tg.disableVerticalSwipes(); });
    }
    if (typeof tg.onEvent === "function") {
      call(function () { tg.onEvent("themeChanged", scheme); });
      call(function () { tg.onEvent("deactivated", pause); });
    }
  }

  raf(loop);
})();
