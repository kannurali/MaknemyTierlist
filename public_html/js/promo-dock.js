// Нижняя рекламная полоса на телефоне (слот "dock") для ленты и калькулятора.
//
// На тирлисте такая полоса уже есть, но её код вплетён в app.js: он умеет
// прятать её при экспорте PNG, разводить по углам плавающие кнопки лайка и
// доната, пересчитывать нижнее поле сцены. Ни того, ни другого, ни третьего
// в ленте и калькуляторе нет, а копировать сотню строк в две страницы значит
// завести три независимых механизма показа рекламы — ровно то, чего вся
// система промо старательно избегает. Поэтому общий кусок вынесен сюда, а
// app.js оставлен как есть: трогать работающий тирлист ради рефакторинга
// рекламы дороже, чем один модуль на две новые страницы.
//
// Отбор кампании не свой: eligible()/pickWeighted()/creativeFor() из
// js/promo.js — того же модуля, что решает, какой борт показать. Здесь
// только DOM.
//
// Имя файла с префиксом promo-, а не ad-: ##[class^="ad-"] и ad.js —
// стандартные косметические правила EasyList (см. шапку js/promo.js).
(function (root) {
  "use strict";

  // Тот же порог, что у .ptn-dock в css/promo-dock.css и у тирлиста.
  // Выше него полосу не просто прячем — не строим вовсе: скрытая через
  // display: none картинка всё равно скачивается, а это сотни килобайт
  // ради того, чего никто не увидит.
  var MQ = root.matchMedia ? root.matchMedia("(max-width: 640px)") : null;

  var ro = null;          // ResizeObserver за высотой полосы
  var lastEl = null;      // что перерисовывать при смене ширины окна
  var lastDoc = null;
  var bound = false;

  function t(key, fallback) {
    var i18n = root.I18N;
    if (!i18n) { return fallback; }
    var stored = null;
    try { stored = localStorage.getItem("nexus-lang-v1"); } catch (_) { /* приватный режим */ }
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

  // doc — ответ /api/promo.php как есть: нормализацию делаем здесь, чтобы
  // страница могла отдать один и тот же сырой документ и бортам, и полосе.
  function render(el, doc) {
    var promo = root.PROMO;
    if (!el || !promo) { return false; }

    lastEl = el;
    lastDoc = doc || null;

    // Поворот телефона в альбом (шире 640px) обязан полосу убрать, возврат
    // в портрет — вернуть. Подписка ставится до всех ранних выходов: чаще
    // всего первый вызов как раз ничего не строит (кампании нет либо экран
    // широкий), и подписка внутри удачной ветки не сработала бы никогда.
    if (MQ && !bound) {
      bound = true;
      var onChange = function () { if (lastEl) { render(lastEl, lastDoc); } };
      if (MQ.addEventListener) { MQ.addEventListener("change", onChange); }
      else if (MQ.addListener) { MQ.addListener(onChange); }
    }

    var narrow = MQ ? MQ.matches : false;
    var list = narrow ? promo.eligible(promo.normalizeDoc(doc), "dock", Date.now()) : [];
    // Не куплено — стоит заглушка «ВАША РЕКЛАМА», та же, что у тирлиста
    // (PROMO.HOUSE_SLOT). Прятать свободное место нельзя: продать можно
    // только то, что видно.
    if (narrow && !list.length) { list = [promo.HOUSE_SLOT]; }
    if (!list.length) { teardown(el); return false; }

    var camp = promo.pickWeighted(list, Math.random());
    var cre = camp ? promo.creativeFor(camp, "dock") : null;
    if (!cre || !cre.src) { teardown(el); return false; }

    el.textContent = "";

    // Плашка «РЕКЛАМА». data-i18n, а не готовый текст: applyLang() страницы
    // проходит по [data-i18n] и переведёт её при переключении языка, не
    // перестраивая полосу.
    var chip = document.createElement("span");
    chip.className = "ptn-chip";
    chip.setAttribute("data-i18n", "ad.chip");
    chip.textContent = t("ad.chip", "РЕКЛАМА");
    el.appendChild(chip);

    var img = document.createElement("img");
    img.className = "ptn-dock-img";
    img.src = cre.src;
    // Пустой alt намеренно: доступное имя у всей полосы (data-i18n-label
    // promo.region в разметке), и подпись на картинке дублировала бы его
    // вторым «Реклама» подряд. Заодно alt не пришлось бы переводить
    // отдельным проходом — [data-i18n] его не покрывает.
    img.alt = "";
    img.decoding = "async";
    img.draggable = false;
    if (cre.w) { img.width = cre.w; }
    if (cre.h) { img.height = cre.h; }
    el.appendChild(img);

    // Маркировка рекламы обязательна по закону, если рекламодатель её
    // прислал: без erid полосу показывать можно, а выкидывать присланный
    // идентификатор — нельзя.
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

    // Высота зависит от пропорций присланного макета, поэтому её меряем, а
    // не задаём числом: от неё считается нижнее поле страницы, иначе полоса
    // навсегда закрывает подвал.
    var measure = function () {
      document.body.style.setProperty("--ptn-dock-h", Math.round(el.offsetHeight) + "px");
    };
    measure();
    // Картинка приезжает позже разметки, и до её загрузки полоса ниже, чем
    // будет: без второго замера поле снизу остаётся заниженным.
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
