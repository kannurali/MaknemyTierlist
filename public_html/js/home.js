/* ===================================================================
   Главная страница: аккордеон в блоке «Немного о важном».

   Всё остальное на странице — вступительная анимация, бегущая строка —
   живёт в css/home.css и обходится без JS. Здесь только раскрытие
   вопросов, потому что оно завязано на состояние и клавиатуру.
   =================================================================== */
(function () {
  'use strict';

  var list = document.querySelector('.hm-faq-list');
  if (!list) return;

  // Делегирование, а не слушатель на каждой кнопке: ответы будут
  // заливаться позже, и разметка может дописываться на сервере.
  list.addEventListener('click', function (e) {
    var btn = e.target.closest('.hm-faq-q');
    if (!btn || !list.contains(btn)) return;

    var open = btn.getAttribute('aria-expanded') === 'true';

    // Открыт всегда один вопрос: в макете раскрытый пункт раздвигает
    // плашку, и два раскрытых сразу сломали бы её высоту.
    list.querySelectorAll('.hm-faq-q[aria-expanded="true"]').forEach(function (other) {
      other.setAttribute('aria-expanded', 'false');
    });

    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
  });
})();

/* ===================================================================
   Ряд карточек — бесконечная лента.

   Едет влево сама, останавливается под курсором и тянется рукой.
   Сделано трансформом, а не прокруткой контейнера: у карточки арт
   вылезает за верхний край, а при наведении поднимается ещё на 71
   единицу макета — контейнер с overflow обрезал бы его.

   Вступительная анимация ряда написана на свойстве translate, лента
   двигает transform. Это разные свойства, они складываются, поэтому
   перехватывать анимацию не нужно.
   =================================================================== */
(function () {
  'use strict';

  var box = document.querySelector('.hm-cards');
  var row = box && box.querySelector('.hm-cards-row');
  if (!box || !row) return;

  var originals = Array.prototype.slice.call(row.children);
  if (!originals.length) return;

  // Вторая копия ряда: без неё лента доезжает до конца и обрывается.
  // Копия — те же самые ссылки, поэтому она скрыта от скринридеров и
  // выключена из обхода по Tab.
  originals.forEach(function (li) {
    var clone = li.cloneNode(true);
    clone.setAttribute('aria-hidden', 'true');
    clone.querySelectorAll('a, button, [tabindex]').forEach(function (el) {
      el.setAttribute('tabindex', '-1');
    });
    row.appendChild(clone);
  });

  // Ширина одной копии — расстояние от первой карточки до её клона.
  // Считать по сумме ширин и отступов нельзя: gap задан в единицах
  // макета и меняется на телефоне.
  var setW = 0;
  function measure() {
    var first = row.children[0];
    var clone = row.children[originals.length];
    setW = clone ? clone.offsetLeft - first.offsetLeft : 0;
  }

  var SPEED = 32;          // px/с — «медленно», карточка проходит ~11 с
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
    // Вкладка была в фоне — dt накопится в секунды, и лента прыгнет.
    if (dt > 0.05) dt = 0.05;
    if (reduce || hovered || dragging || !onScreen || document.hidden) return;
    offset += SPEED * dt;
    normalize();
    paint();
  }

  // ---------- пауза ----------
  box.addEventListener('pointerenter', function (e) {
    if (e.pointerType === 'mouse') hovered = true;
  });
  box.addEventListener('pointerleave', function (e) {
    if (e.pointerType === 'mouse') hovered = false;
  });
  // Клавиатура: пока фокус внутри ленты, она стоит — иначе карточка
  // уезжает из-под фокуса.
  box.addEventListener('focusin', function () { hovered = true; });
  box.addEventListener('focusout', function () { hovered = false; });

  if (window.IntersectionObserver) {
    new IntersectionObserver(function (entries) {
      onScreen = entries[0].isIntersecting;
    }).observe(box);
  }

  // ---------- перетаскивание ----------
  var startX = 0;
  var startOffset = 0;
  var moved = 0;
  var pointerId = null;

  // Порог, после которого нажатие считается протяжкой. Тот же, по которому
  // ниже глушится клик: иначе одно и то же движение было бы для захвата уже
  // протяжкой, а для ссылки ещё кликом.
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
    // Указатель захватываем не на нажатии, а только когда протяжка
    // действительно началась. Захват с первого pointerdown переносил на
    // .hm-cards и последующий click: его целью становился сам контейнер, а
    // не карточка под пальцем — карточки-ссылки переставали открываться, а
    // «Фрукты» и «Цены» молчали в ответ на нажатие (js/topbar.js ищет
    // [data-soon] от e.target).
    if (!captured && moved > DRAG_AT) {
      captured = true;
      try { box.setPointerCapture(pointerId); } catch (err) {}
    }
    if (!captured) return;   // ещё не протяжка — ленту не двигаем
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

  // Карточки — ссылки с картинками, и браузер на движении мышью с зажатой
  // кнопкой начинает свой drag-and-drop. Он отбирает указатель (прилетает
  // pointercancel), и протяжка обрывается на первом же кадре.
  box.addEventListener('dragstart', function (e) { e.preventDefault(); });

  // Свайп по карточке-ссылке не должен превращаться в переход.
  // Ловим на фазе перехвата, до штатного обработчика ссылки.
  box.addEventListener('click', function (e) {
    if (moved > DRAG_AT) {
      e.preventDefault();
      e.stopPropagation();
    }
    moved = 0;
  }, true);

  // ---------- запуск ----------
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
