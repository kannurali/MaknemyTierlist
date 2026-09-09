
(function (root) {
  "use strict";

  var MQ = root.matchMedia ? root.matchMedia("(max-width: 640px)") : null;

  var ro = null;
  var lastEl = null;
  var lastDoc = null;
  var bound = false;

  function t(key, fallback) {
    var i18n = root.I18N;
    if (!i18n) { return fallback; }
    var stored = null;
    try { stored = localStorage.getItem("nexus-lang-v1"); } catch (_) {}
    return i18n.t(key, i18n.pickLang(stored, navigator.language));
  }

  function teardown(el) {
    el.textContent = "";
    el.hidden = true;
    el.classList.remove("has-link");
    el.onclick = null;
    el.onkeydown = null;
    el.removeAttribute("tabindex");
    el.removeAttribute("role");
    document.body.classList.remove("has-promo-dock");
    document.body.style.removeProperty("--ptn-dock-h");
    if (ro) { ro.disconnect(); ro = null; }
  }

  function render(el, doc, page) {
    var promo = root.PROMO;
    if (!el || !promo) { return false; }

    lastEl = el;
    lastDoc = doc || null;

    if (MQ && !bound) {
      bound = true;
      var onChange = function () { if (lastEl) { render(lastEl, lastDoc); } };
      if (MQ.addEventListener) { MQ.addEventListener("change", onChange); }
      else if (MQ.addListener) { MQ.addListener(onChange); }
    }

    var narrow = MQ ? MQ.matches : false;
    var list = narrow ? promo.eligible(promo.normalizeDoc(doc), "dock", Date.now(), page) : [];

    if (narrow && !list.length) {
      var house = promo.houseFor("dock", Date.now(), page);
      if (house) { list = [house]; }
    }
    if (!list.length) { teardown(el); return false; }

    var camp = promo.pickWeighted(list, Math.random());
    var cre = camp ? promo.creativeFor(camp, "dock") : null;
    if (!cre || !cre.src) { teardown(el); return false; }

    el.textContent = "";

    var chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.setAttribute("data-i18n", "ad.chip");
    chip.textContent = t("ad.chip", "РЕКЛАМА");
    el.appendChild(chip);

    var img = document.createElement("img");
    img.className = "ptn-dock-img";
    img.src = cre.src;

    img.alt = "";
    img.decoding = "async";
    img.draggable = false;
    if (cre.w) { img.width = cre.w; }
    if (cre.h) { img.height = cre.h; }
    el.appendChild(img);

    if (camp.erid) {
      var erid = document.createElement("span");
      erid.className = "ptn-erid";
      erid.textContent = "erid: " + camp.erid;
      el.appendChild(erid);
    }

    var url = promo.safeHref(camp.href);
    el.classList.toggle("has-link", !!url);
    if (url) {
      var open = function () { root.open(url, "_blank", "noopener"); };
      el.onclick = open;
      el.tabIndex = 0;
      el.setAttribute("role", "link");
      el.onkeydown = function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(); }
      };
    } else {
      el.onclick = null;
      el.onkeydown = null;
      el.removeAttribute("tabindex");
      el.removeAttribute("role");
    }

    el.hidden = false;
    document.body.classList.add("has-promo-dock");

    var measure = function () {
      document.body.style.setProperty("--ptn-dock-h", Math.round(el.offsetHeight) + "px");
    };
    measure();

    img.addEventListener("load", measure, { once: true });
    if (root.ResizeObserver) {
      if (ro) { ro.disconnect(); }
      ro = new root.ResizeObserver(measure);
      ro.observe(el);
    }

    return true;
  }

  root.NX_PROMO_DOCK = { render: render };
})(window);
