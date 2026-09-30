(function (root) {
  "use strict";

  var FIRST = [2, 3];
  var EVERY = [3, 4];
  var MARK = "data-ptn-k";

  function unit(rnd) {
    var n = Number(rnd());
    if (!isFinite(n) || n < 0) { return 0; }
    return n < 1 ? n : 0.999999999;
  }

  function spread(range, rnd) {
    return range[0] + Math.floor(unit(rnd) * (range[1] - range[0] + 1));
  }

  function gaps(rnd) {
    var next = typeof rnd === "function" ? rnd : Math.random;
    var seq = [];
    return function (k) {
      while (seq.length <= k) { seq.push(spread(seq.length ? EVERY : FIRST, next)); }
      return seq[k];
    };
  }

  function positions(count, gap) {
    var n = Math.max(0, Math.floor(Number(count) || 0));
    var out = [];
    for (var k = 0, at = gap(0); at < n; at += gap(++k)) { out.push(at); }
    return out;
  }

  function campaigns(doc, page, nowMs, rnd) {
    var promo = root.PROMO;
    if (!promo) { return []; }
    var now = (typeof nowMs === "number" && isFinite(nowMs)) ? nowMs : Date.now();
    var paid = promo.eligible(promo.normalizeDoc(doc), "strip", now, page);
    if (paid.length) { return promo.orderForCarousel(paid, rnd, promo.MAX_STRIP_SLIDES); }
    var house = promo.houseFor("strip", now, page);
    return house ? [house] : [];
  }

  function copy(camp, tx) {
    var t = function (key) { return key && typeof tx === "function" ? String(tx(key) || "") : ""; };
    if (!camp) { return { text: "", cta: "" }; }
    return {
      text: t(camp.textKey) || camp.text || camp.advertiser || "",
      cta: t(camp.ctaKey) || camp.cta || t("promo.cta")
    };
  }

  function goal(camp, page) {
    try {
      if (typeof root.ym === "function") {
        root.ym(111127188, "reachGoal", "promo_click", { id: camp.id, slot: "strip", place: "feed", page: page });
      }
    } catch (e) {}
  }

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) { node.className = cls; }
    if (text) { node.textContent = text; }
    return node;
  }

  function frame(camp, env, cls) {
    var url = camp && root.PROMO ? root.PROMO.safeHref(camp.href) : "";
    var box = el(url ? "a" : "div", cls);
    if (url) {
      box.href = url;
      box.target = "_blank";
      box.rel = "noopener nofollow";
      box.addEventListener("click", function () { goal(camp, env.page); });
    }
    return box;
  }

  function picture(camp, env, cls) {
    var promo = root.PROMO;
    var cre = camp && promo ? promo.creativeFor(camp, "strip") : null;
    if (!cre) { return null; }
    var img = el("img", cls);
    img.src = promo.srcFor(cre);
    img.alt = env.tx("ad.imageAlt");
    img.width = cre.w || 1200;
    img.height = cre.h || 300;
    img.loading = "lazy";
    img.decoding = "async";
    img.draggable = false;
    return img;
  }

  function stamp(img, camp, env) {
    var promo = root.PROMO;
    if (promo && promo.stampOn) { promo.stampOn(img, camp, "strip", env.tx); }
  }

  function erid(camp) {
    return camp && camp.erid ? el("span", "ptn-erid", "erid: " + camp.erid) : null;
  }

  function banner(camp, env) {
    var wrap = el("aside", "nw-ptn");
    wrap.setAttribute("aria-label", env.tx("ad.imageAlt"));
    var box = frame(camp, env, "nw-ptn-in");
    var img = picture(camp, env, "nw-ptn-img");
    if (img) { box.appendChild(img); stamp(img, camp, env); }
    if (camp) {
      box.appendChild(el("span", "ptn-chip", env.tx("ad.chip")));
      var mark = erid(camp);
      if (mark) { box.appendChild(mark); }
    }
    wrap.appendChild(box);
    return wrap;
  }

  function card(camp, env) {
    var li = el("li", "tr-ptn");
    li.setAttribute("aria-label", env.tx("ad.imageAlt"));
    var box = frame(camp, env, "tr-ptn-in");
    var head = el("span", "tr-ptn-head");
    var media = el("span", "tr-ptn-media");
    if (camp) {
      var c = copy(camp, env.tx);
      head.appendChild(el("span", "tr-ptn-mark", env.tx("ad.chip")));
      head.appendChild(el("span", "tr-ptn-text", c.text));
      if (box.tagName === "A" && c.cta) { head.appendChild(el("span", "tr-ptn-cta", c.cta)); }
      var img = picture(camp, env, "tr-ptn-img");
      if (img) { media.appendChild(img); stamp(img, camp, env); }
      var mark = erid(camp);
      if (mark) { media.appendChild(mark); }
    }
    box.appendChild(head);
    box.appendChild(media);
    li.appendChild(box);
    return li;
  }

  function create(opts) {
    var env = { page: opts.page, tx: opts.tx };
    var build = opts.build;
    var rnd = typeof opts.rnd === "function" ? opts.rnd : Math.random;
    var gap = gaps(rnd);
    var list = null;
    var cache = [];

    function nodeFor(k) {
      if (!cache[k]) {
        var node = build(list && list.length ? list[k % list.length] : null, env);
        node.setAttribute(MARK, String(k));
        cache[k] = node;
      }
      return cache[k];
    }

    function place(box, itemSel) {
      if (!box) { return; }
      var kids = Array.prototype.slice.call(box.children);
      var items = [];
      for (var i = 0; i < kids.length; i++) {
        if (kids[i].hasAttribute(MARK)) { box.removeChild(kids[i]); }
        else if (!itemSel || kids[i].matches(itemSel)) { items.push(kids[i]); }
      }
      if (list && !list.length) { return; }
      var at = positions(items.length, gap);
      for (var k = 0; k < at.length; k++) { box.insertBefore(nodeFor(k), items[at[k]]); }
    }

    return {
      setDoc: function (doc, nowMs) { list = campaigns(doc, env.page, nowMs, rnd); cache = []; },
      reset: function () { cache = []; },
      place: place
    };
  }

  var api = {
    FIRST: FIRST,
    EVERY: EVERY,
    gaps: gaps,
    positions: positions,
    campaigns: campaigns,
    copy: copy,
    banner: banner,
    card: card,
    create: create
  };

  if (typeof module === "object" && module.exports) { module.exports = api; }
  root.NX_PROMO_FEED = api;
})(typeof globalThis !== "undefined" ? globalThis : this);
