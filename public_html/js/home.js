
(function () {
  'use strict';

  var LANG_KEY = 'nexus-lang-v1';
  var i18n = window.I18N;
  if (!i18n) return;

  var stored = null;
  try { stored = localStorage.getItem(LANG_KEY); } catch (e) {}
  var lang = i18n.pickLang(stored, navigator.language);

  function tx(key) { return i18n.t(key, lang); }

  function apply(next) {
    if (next) {
      lang = next;
      try { localStorage.setItem(LANG_KEY, lang); } catch (e) {}
    }
    document.documentElement.lang = lang;

    document.querySelectorAll('[data-i18n]').forEach(function (el) {
      el.textContent = tx(el.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
      el.title = tx(el.dataset.i18nTitle);
    });
    document.querySelectorAll('[data-i18n-label]').forEach(function (el) {
      el.setAttribute('aria-label', tx(el.dataset.i18nLabel));
    });
    document.querySelectorAll('#langSwitch [data-lang]').forEach(function (b) {
      var on = b.dataset.lang === lang;
      b.classList.toggle('active', on);
      b.setAttribute('aria-pressed', String(on));
    });
  }

  var box = document.getElementById('langSwitch');
  if (box) {
    box.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-lang]');
      if (btn) apply(btn.dataset.lang);
    });
  }

  apply();
})();

(function () {
  'use strict';

  var list = document.querySelector('.hm-faq-list');
  if (!list) return;

  list.addEventListener('click', function (e) {
    var btn = e.target.closest('.hm-faq-q');
    if (!btn || !list.contains(btn)) return;

    var open = btn.getAttribute('aria-expanded') === 'true';

    list.querySelectorAll('.hm-faq-q[aria-expanded="true"]').forEach(function (other) {
      other.setAttribute('aria-expanded', 'false');
    });

    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
  });
})();

(function () {
  'use strict';

  var box = document.querySelector('.hm-cards');
  var row = box && box.querySelector('.hm-cards-row');
  if (!box || !row) return;

  var originals = Array.prototype.slice.call(row.children);
  if (!originals.length) return;

  originals.forEach(function (li) {
    var clone = li.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    clone.querySelectorAll('a, button, [tabindex]').forEach(function (el) {
      el.setAttribute('tabindex', '-1');
    });
    row.appendChild(clone);
  });

  var setW = 0;
  function measure() {
    var first = row.children[0];
    var clone = row.children[originals.length];
    setW = clone ? clone.offsetLeft - first.offsetLeft : 0;
  }

  var SPEED = 32;
  var offset = 0;
  var hovered = false;
  var dragging = false;
  var onScreen = true;
  var last = 0;

  var mq = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  var reduce = !!(mq && mq.matches);
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function (e) { reduce = e.matches; });
  }

  function normalize() {
    if (setW > 0) offset = ((offset % setW) + setW) % setW;
  }

  function paint() {
    row.style.transform = 'translate3d(' + (-offset) + 'px, 0, 0)';
  }

  function frame(now) {
    requestAnimationFrame(frame);
    var dt = last ? (now - last) / 1000 : 0;
    last = now;

    if (dt > 0.05) dt = 0.05;
    if (reduce || hovered || dragging || !onScreen || document.hidden) return;
    offset += SPEED * dt;
    normalize();
    paint();
  }

  box.addEventListener('pointerenter', function (e) {
    if (e.pointerType === 'mouse') hovered = true;
  });
  box.addEventListener('pointerleave', function (e) {
    if (e.pointerType === 'mouse') hovered = false;
  });

  box.addEventListener('focusin', function () { hovered = true; });
  box.addEventListener('focusout', function () { hovered = false; });

  if (window.IntersectionObserver) {
    new IntersectionObserver(function (entries) {
      onScreen = entries[0].isIntersecting;
    }).observe(box);
  }

  var startX = 0;
  var startOffset = 0;
  var moved = 0;
  var pointerId = null;

  var DRAG_AT = 6;
  var captured = false;

  box.addEventListener('pointerdown', function (e) {
    if (e.button && e.button !== 0) return;
    dragging = true;
    moved = 0;
    startX = e.clientX;
    startOffset = offset;
    pointerId = e.pointerId;
    captured = false;
    box.classList.add('is-drag');
  });

  box.addEventListener('pointermove', function (e) {
    if (!dragging) return;
    var dx = e.clientX - startX;
    if (Math.abs(dx) > moved) moved = Math.abs(dx);

    if (!captured && moved > DRAG_AT) {
      captured = true;
      try { box.setPointerCapture(pointerId); } catch (err) {}
    }
    if (!captured) return;
    offset = startOffset - dx;
    normalize();
    paint();
  });

  function endDrag() {
    if (!dragging) return;
    dragging = false;
    box.classList.remove('is-drag');
    if (captured) {
      try { box.releasePointerCapture(pointerId); } catch (err) {}
      captured = false;
    }
  }
  box.addEventListener('pointerup', endDrag);
  box.addEventListener('pointercancel', endDrag);

  box.addEventListener('dragstart', function (e) { e.preventDefault(); });

  box.addEventListener('click', function (e) {
    if (moved > DRAG_AT) {
      e.preventDefault();
      e.stopPropagation();
    }
    moved = 0;
  }, true);

  var remeasure = 0;
  window.addEventListener('resize', function () {
    if (remeasure) return;
    remeasure = requestAnimationFrame(function () {
      remeasure = 0;
      measure();
      normalize();
      paint();
    });
  });

  measure();
  paint();
  requestAnimationFrame(frame);
})();
