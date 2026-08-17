// Панель управления рекламой.
//
// Отдельная страница, а не инлайн-правка на постере: слотов три, кампаний
// может быть десяток, и у каждой свои даты, вес и три макета — contentEditable
// с window.prompt() этого не тянет.
//
// i18n сюда сознательно не подключён: файл словаря качает каждый посетитель
// сайта, и сорок админских ключей утяжелили бы его ради внутреннего
// инструмента. Строки здесь русские и живут прямо в разметке.
(function () {
  "use strict";

  var API_SESSION = "/api/session.php";
  var API_LOGIN   = "/api/login.php";
  var API_PROMO   = "/api/promo.php";
  var API_UPLOAD  = "/api/upload.php";
  var API_TIERLIST = "/api/tierlist.php";
  var PREVIEW_KEY = "nx-ptn-preview";

  // Зеркало CREATIVE_SPECS из api/lib/images.php. Расходиться им нельзя:
  // здесь это подсказка «влезет / не влезет» ДО загрузки, а решает всё равно
  // сервер. Те же числа стоят в таблице форматов медиакита.
  var SPECS = {
    strip: { label: "Карусель в постере", w: 1200, h: 300,  maxW: 1200, maxH: 400,  bytes: 400000, animBytes: 900000 },
    rail:  { label: "Боковой борт",       w: 320,  h: 1200, maxW: 320,  maxH: 1200, bytes: 300000, animBytes: 700000 },
    popup: { label: "Всплывающее окно",   w: 800,  h: 800,  maxW: 900,  maxH: 900,  bytes: 400000, animBytes: 900000 }
  };
  var SLOTS = ["strip", "rail", "popup"];

  var $ = function (s) { return document.querySelector(s); };
  var doc = { v: 1, rev: 0, campaigns: [] };
  var loadedRev = 0;          // ревизия, с которой мы начали править
  var current = null;         // id выбранной кампании
  var dirty = false;

  // ---------------------------------------------------------------- утилиты

  function hint(text, cls) {
    var el = $("#saveHint");
    el.textContent = text || "";
    el.className = "hint" + (cls ? " " + cls : "");
  }

  function kb(n) { return Math.round(n / 1024) + " КБ"; }

  function fmtDate(s) { return s ? s.split("-").reverse().join(".") : "—"; }

  function newId() {
    // Идентификатор — ключ, по которому позже сойдётся статистика кликов,
    // поэтому он не переиспользуется и не меняется после создания.
    return "c_" + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  function camp() {
    for (var i = 0; i < doc.campaigns.length; i++) {
      if (doc.campaigns[i].id === current) return doc.campaigns[i];
    }
    return null;
  }

  function markDirty() { dirty = true; hint("есть несохранённые правки"); }

  // ------------------------------------------------------------------ вход

  function showGate(err) {
    $("#gate").hidden = false;
    $("#app").hidden = true;
    var e = $("#gateErr");
    e.hidden = !err;
    e.textContent = err || "";
  }

  function checkSession() {
    return fetch(API_SESSION, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) { return !!(j && j.admin); })
      .catch(function () { return false; });
  }

  $("#gateForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var pass = $("#gatePass").value;
    fetch(API_LOGIN, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ password: pass })
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.j && res.j.error === "too_many_attempts") {
          showGate("Слишком много попыток. Подождите " + (res.j.retry_after || 300) + " с.");
          return;
        }
        if (!res.ok || !res.j || !res.j.ok) { showGate("Неверный пароль."); return; }
        $("#gatePass").value = "";
        start();
      })
      .catch(function () { showGate("Сервер недоступен. Панель работает только там, где отвечает PHP."); });
  });

  // -------------------------------------------------------------- загрузка

  function loadDoc() {
    return fetch(API_PROMO, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        doc = PROMO.normalizeDoc(j);
        loadedRev = doc.rev;
        return true;
      })
      .catch(function () { return false; });
  }

  // ------------------------------------------------------------- список

  function renderList() {
    var ul = $("#list");
    ul.innerHTML = "";
    $("#listCount").textContent = doc.campaigns.length ? "(" + doc.campaigns.length + ")" : "";
    $("#emptyHint").hidden = doc.campaigns.length > 0;

    var now = Date.now();
    doc.campaigns.forEach(function (c) {
      var li = document.createElement("li");
      if (c.id === current) li.className = "on";

      var top = document.createElement("div");
      top.className = "li-top";
      var name = document.createElement("span");
      name.className = "li-name";
      name.textContent = c.name || c.id;
      top.appendChild(name);

      var live = c.enabled && PROMO.inWindow(c, now);
      var pill = document.createElement("span");
      pill.className = "pill " + (live ? "live" : (c.enabled ? "" : "off"));
      pill.textContent = live ? "идёт" : (c.enabled ? "вне срока" : "выкл");
      top.appendChild(pill);
      li.appendChild(top);

      var sub = document.createElement("div");
      sub.className = "li-sub";
      sub.textContent = (c.advertiser || "—") + " · " + fmtDate(c.start) + " – " + fmtDate(c.end) +
        " · вес " + c.weight + " · " + (c.slots.length ? c.slots.join(", ") : "нет слотов");
      li.appendChild(sub);

      li.addEventListener("click", function () { current = c.id; renderAll(); });
      ul.appendChild(li);
    });
  }

  // ------------------------------------------------------------- редактор

  function shareText(c) {
    // Доля показов считается среди тех, кто прямо сейчас имеет право
    // показаться в том же слоте — иначе число вводит в заблуждение.
    var now = Date.now();
    var slot = c.slots[0];
    if (!slot) return "";
    var pool = PROMO.eligible(doc, slot, now);
    var total = pool.reduce(function (s, x) { return s + x.weight; }, 0);
    var mine = pool.filter(function (x) { return x.id === c.id; })
      .reduce(function (s, x) { return s + x.weight; }, 0);
    if (!total || !mine) return "(сейчас не показывается)";
    return "≈ " + Math.round(mine / total * 100) + " % показов в «" + slot + "»";
  }

  function renderEditor() {
    var c = camp();
    $("#editor").hidden = !c;
    if (!c) return;

    $("#fName").value = c.name;
    $("#fAdvertiser").value = c.advertiser;
    $("#fEnabled").checked = c.enabled;
    $("#fWeight").value = Math.min(10, c.weight);
    $("#weightShare").textContent = shareText(c);
    $("#fStart").value = c.start;
    $("#fEnd").value = c.end;
    $("#fHref").value = c.href;
    $("#fText").value = c.text;
    $("#fCta").value = c.cta;
    $("#fNotes").value = c.notes;
    $("#fDelay").value = Math.round(c.popup.delayMs / 1000);
    $("#fCap").value = c.popup.capHours;
    $("#fWeek").value = c.popup.maxPerWeek;

    var live = c.enabled && PROMO.inWindow(c, Date.now());
    $("#windowNote").textContent = live
      ? "Идёт сейчас. Пустая дата = без ограничения."
      : "Сейчас не показывается. Даты считаются по московскому времени, конец включительно.";

    $("#hrefErr").hidden = !(c.href === "" && $("#fHref").value.trim() !== "");
    renderSlots(c);
  }

  function renderSlots(c) {
    var box = $("#slots");
    box.innerHTML = "";
    SLOTS.forEach(function (slot) {
      var spec = SPECS[slot];
      var cre = c.creatives[slot] || null;
      var on = c.slots.indexOf(slot) >= 0;

      var el = document.createElement("div");
      el.className = "slot";

      var head = document.createElement("label");
      head.className = "slot-head";
      var chk = document.createElement("input");
      chk.type = "checkbox";
      chk.checked = on;
      chk.addEventListener("change", function () {
        var i = c.slots.indexOf(slot);
        if (chk.checked && i < 0) c.slots.push(slot);
        if (!chk.checked && i >= 0) c.slots.splice(i, 1);
        markDirty(); renderAll();
      });
      head.appendChild(chk);
      head.appendChild(document.createTextNode(" " + spec.label));
      el.appendChild(head);

      var s = document.createElement("div");
      s.className = "slot-spec";
      s.textContent = spec.w + "×" + spec.h + " · статика ≤ " + kb(spec.bytes) +
        " · анимация ≤ " + kb(spec.animBytes);
      el.appendChild(s);

      var prev = document.createElement("div");
      prev.className = "slot-prev";
      if (cre) {
        var img = document.createElement("img");
        img.src = cre.src;
        prev.appendChild(img);
      } else {
        var e = document.createElement("span");
        e.className = "empty";
        e.textContent = "макет не загружен";
        prev.appendChild(e);
      }
      el.appendChild(prev);

      if (cre) {
        var badge = document.createElement("span");
        var fits = cre.w <= spec.maxW && cre.h <= spec.maxH;
        badge.className = "badge " + (fits ? "ok" : "bad");
        badge.textContent = cre.w + "×" + cre.h + (cre.anim ? " · анимация" : " · статика") +
          (fits ? "" : " · не по спецификации");
        el.appendChild(badge);
      }

      var row = document.createElement("div");
      row.className = "slot-row";
      row.appendChild(fileBtn("Загрузить макет", slot, "src", c));
      if (cre && cre.anim) {
        var p = fileBtn(cre.poster && cre.poster !== cre.src ? "Постер ✓" : "Постер", slot, "poster", c);
        if (!cre.poster || cre.poster === cre.src) p.classList.add("poster-need");
        p.title = "Статичный кадр: он показывается при отключённой анимации, " +
                  "по кнопке паузы и в PNG-постере";
        row.appendChild(p);
      }
      if (cre) {
        var del = document.createElement("button");
        del.className = "btn small danger";
        del.type = "button";
        del.textContent = "Убрать";
        del.addEventListener("click", function () {
          delete c.creatives[slot];
          markDirty(); renderAll();
        });
        row.appendChild(del);
      }
      el.appendChild(row);

      var err = document.createElement("div");
      err.className = "err";
      err.hidden = true;
      el.appendChild(err);
      el.dataset.slot = slot;

      box.appendChild(el);
    });
  }

  function fileBtn(label, slot, field, c) {
    var wrap = document.createElement("span");
    wrap.className = "btn small file-btn";
    wrap.appendChild(document.createTextNode(label));
    var inp = document.createElement("input");
    inp.type = "file";
    inp.accept = "image/png,image/jpeg,image/webp,image/gif";
    inp.addEventListener("change", function () {
      if (inp.files && inp.files[0]) upload(inp.files[0], slot, field, c);
      inp.value = "";
    });
    wrap.appendChild(inp);
    return wrap;
  }

  function slotError(slot, msg) {
    var el = document.querySelector('.slot[data-slot="' + slot + '"] .err');
    if (!el) return;
    el.hidden = !msg;
    el.textContent = msg || "";
  }

  // -------------------------------------------------------------- загрузка

  // Анимацию нельзя прогонять через canvas: перерисовка в холст оставляет
  // первый кадр ровно так же, как это делает GD на сервере. Смотрим на
  // байты, а не на расширение.
  function isAnimatedFile(file) {
    return file.slice(0, 64).arrayBuffer().then(function (buf) {
      var b = new Uint8Array(buf);
      var str = function (i, n) {
        var s = "";
        for (var k = i; k < i + n && k < b.length; k++) s += String.fromCharCode(b[k]);
        return s;
      };
      // Любой GIF считаем анимацией: холст всё равно сохранил бы его в PNG.
      if (str(0, 3) === "GIF") return true;
      if (str(0, 4) === "RIFF" && str(8, 4) === "WEBP") {
        return str(12, 4) === "VP8X" && (b[20] & 0x02) !== 0;
      }
      return false;
    });
  }

  function readRaw(file) {
    return new Promise(function (res, rej) {
      var fr = new FileReader();
      fr.onload = function () { res(fr.result); };
      fr.onerror = function () { rej(new Error("не удалось прочитать файл")); };
      fr.readAsDataURL(file);
    });
  }

  // Статику ужимаем на клиенте, чтобы не упереться в лимит байт из-за
  // исходника прямо из фотошопа.
  function compress(file, spec) {
    return readRaw(file).then(function (url) {
      return new Promise(function (res) {
        var im = new Image();
        im.onload = function () {
          var k = Math.min(1, spec.maxW / im.width, spec.maxH / im.height);
          var cv = document.createElement("canvas");
          cv.width = Math.max(1, Math.round(im.width * k));
          cv.height = Math.max(1, Math.round(im.height * k));
          cv.getContext("2d").drawImage(im, 0, 0, cv.width, cv.height);
          var out = url;
          [0.9, 0.8, 0.7, 0.6].some(function (q) {
            var d = cv.toDataURL("image/webp", q);
            if (d.indexOf("data:image/webp") !== 0) return false;   // старый Safari
            out = d;
            return d.length * 0.75 < spec.bytes;
          });
          res(out);
        };
        im.onerror = function () { res(url); };
        im.src = url;
      });
    });
  }

  function upload(file, slot, field, c) {
    var spec = SPECS[slot];
    slotError(slot, "");
    hint("загружаю макет…");
    isAnimatedFile(file)
      .then(function (anim) { return anim ? readRaw(file) : compress(file, spec); })
      .then(function (dataUrl) {
        return fetch(API_UPLOAD, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ data: dataUrl, slot: slot })
        }).then(function (r) {
          return r.json().then(function (j) {
            if (!r.ok) throw new Error(j.error || "загрузка не удалась");
            return j;
          });
        });
      })
      .then(function (j) {
        if (!c.creatives[slot]) c.creatives[slot] = { src: "", w: 0, h: 0, anim: false, poster: "" };
        if (field === "poster") {
          c.creatives[slot].poster = j.url;
        } else {
          c.creatives[slot].src = j.url;
          c.creatives[slot].w = j.w;
          c.creatives[slot].h = j.h;
          c.creatives[slot].anim = j.anim;
          if (!c.creatives[slot].poster && j.anim) c.creatives[slot].poster = j.url;
        }
        if (c.slots.indexOf(slot) < 0) c.slots.push(slot);
        markDirty();
        renderAll();
      })
      .catch(function (e) {
        // Сообщение сервера намеренно показываем как есть: в нём названы
        // фактический и допустимый размер, его можно переслать рекламодателю.
        slotError(slot, e.message);
        hint("макет не принят", "bad");
      });
  }

  // ------------------------------------------------------------ сохранение

  function save() {
    hint("сохраняю…");
    return fetch(API_PROMO, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ doc: doc, expectRev: loadedRev })
    })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (res) {
        if (res.status === 409) {
          // Кто-то (или вторая вкладка) сохранил раньше. Молча затирать чужую
          // правку — именно то, чем страдает блоб тирлиста; здесь спрашиваем.
          if (confirm("Кампании уже изменили в другом месте.\n\nПерезагрузить их? Ваши несохранённые правки пропадут.")) {
            return loadDoc().then(function () { current = null; renderAll(); hint("перезагружено"); });
          }
          hint("не сохранено: конфликт версий", "bad");
          return;
        }
        if (res.status !== 200 || !res.j || !res.j.ok) {
          hint((res.j && res.j.error) || "ошибка сохранения", "bad");
          return;
        }
        loadedRev = res.j.rev;
        doc.rev = res.j.rev;
        dirty = false;
        hint("сохранено", "ok");
        setTimeout(function () { if (!dirty) hint(""); }, 2500);
      })
      .catch(function () { hint("сервер недоступен", "bad"); });
  }

  // --------------------------------------------------------------- команды

  function addCampaign(from) {
    var c = PROMO.normalizeDoc({
      campaigns: [from
        ? JSON.parse(JSON.stringify(from))
        : { id: newId(), name: "Новая кампания", enabled: false, weight: 1, slots: [] }]
    }).campaigns[0];
    if (from) {
      c.id = newId();
      c.name = (from.name || "Кампания") + " (копия)";
      c.enabled = false;
    }
    doc.campaigns.push(c);
    current = c.id;
    markDirty();
    renderAll();
  }

  function bind(sel, apply) {
    var el = $(sel);
    var ev = (el.type === "checkbox" || el.type === "range" || el.type === "date") ? "change" : "input";
    el.addEventListener(ev, function () {
      var c = camp();
      if (!c) return;
      apply(c, el.value, el.checked);
      markDirty();
      renderList();
      if (el.type === "range" || el.type === "date" || el.type === "checkbox") renderEditor();
    });
  }

  function wire() {
    bind("#fName", function (c, v) { c.name = v; });
    bind("#fAdvertiser", function (c, v) { c.advertiser = v; });
    bind("#fEnabled", function (c, v, checked) { c.enabled = checked; });
    bind("#fWeight", function (c, v) { c.weight = Math.max(1, Math.min(100, parseInt(v, 10) || 1)); });
    bind("#fStart", function (c, v) { c.start = v; });
    bind("#fEnd", function (c, v) { c.end = v; });
    bind("#fText", function (c, v) { c.text = v; });
    bind("#fCta", function (c, v) { c.cta = v; });
    bind("#fNotes", function (c, v) { c.notes = v; });
    bind("#fDelay", function (c, v) { c.popup.delayMs = Math.max(5000, Math.min(60000, (parseInt(v, 10) || 12) * 1000)); });
    bind("#fCap", function (c, v) { c.popup.capHours = Math.max(1, Math.min(720, parseInt(v, 10) || 24)); });
    bind("#fWeek", function (c, v) { c.popup.maxPerWeek = Math.max(1, Math.min(50, parseInt(v, 10) || 3)); });

    $("#fHref").addEventListener("input", function () {
      var c = camp();
      if (!c) return;
      var raw = $("#fHref").value.trim();
      c.href = PROMO.safeHref(raw);
      $("#hrefErr").hidden = !(raw !== "" && c.href === "");
      markDirty();
    });

    $("#btnUtm").addEventListener("click", function () {
      var c = camp();
      if (!c || !c.href) return;
      var slot = c.slots[0] || "strip";
      var sep = c.href.indexOf("?") >= 0 ? "&" : "?";
      c.href = PROMO.safeHref(c.href + sep + "utm_source=maknemytierlist&utm_medium=" + slot +
        "&utm_campaign=" + encodeURIComponent(c.id));
      $("#fHref").value = c.href;
      markDirty();
    });

    $("#btnNew").addEventListener("click", function () { addCampaign(null); });
    $("#btnDup").addEventListener("click", function () { var c = camp(); if (c) addCampaign(c); });
    $("#btnDel").addEventListener("click", function () {
      var c = camp();
      if (!c || !confirm("Удалить кампанию «" + (c.name || c.id) + "»?")) return;
      doc.campaigns = doc.campaigns.filter(function (x) { return x.id !== c.id; });
      current = null;
      markDirty();
      renderAll();
    });
    $("#btnSave").addEventListener("click", save);

    // Перенос старого одиночного баннера из state.ad. Односторонний: сам
    // state.ad не трогаем, поэтому откат остаётся мгновенным.
    $("#btnImportLegacy").addEventListener("click", function () {
      hint("читаю тирлист…");
      fetch(API_TIERLIST, { cache: "no-store" })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var legacy = PROMO.migrateLegacyAd(j && j.tierlist ? j.tierlist.ad : null);
          if (!legacy) { hint("в тирлисте нет баннера", "bad"); return; }
          var exists = doc.campaigns.some(function (c) { return c.id === legacy.id; });
          if (exists) { hint("уже импортирован", "bad"); return; }
          doc.campaigns.push(legacy);
          current = legacy.id;
          markDirty();
          renderAll();
        })
        .catch(function () { hint("не удалось прочитать тирлист", "bad"); });
    });

    // Предпросмотр показывает НЕсохранённые правки: рекламодатель видит свой
    // макет на настоящем сайте до того, как что-то опубликовано. Без
    // ?promo_preview=1 механизм на сайте полностью инертен.
    $("#btnPreview").addEventListener("click", function () {
      try {
        sessionStorage.setItem(PREVIEW_KEY, JSON.stringify(doc));
        // Без noopener намеренно: sessionStorage копируется в новую вкладку
        // только когда связь с открывающей сохранена, а с noopener черновик
        // до сайта просто не доезжает. Открываем свою же страницу на том же
        // домене, так что защищаться тут не от чего.
        var w = window.open("/?promo_preview=1", "_blank");
        if (!w) hint("браузер заблокировал новое окно", "bad");
      } catch (e) {
        hint("не удалось открыть предпросмотр", "bad");
      }
    });

    window.addEventListener("beforeunload", function (e) {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = "";
    });
  }

  function renderAll() { renderList(); renderEditor(); }

  function start() {
    $("#gate").hidden = true;
    $("#app").hidden = false;
    loadDoc().then(function (ok) {
      if (!ok) hint("не удалось загрузить кампании", "bad");
      renderAll();
    });
  }

  wire();
  checkSession().then(function (isAdmin) {
    if (isAdmin) start();
    else showGate("");
  });
})();
