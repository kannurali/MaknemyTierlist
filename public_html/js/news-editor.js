/* ============================================================
   Редактор новости — только /admin/news.
   ============================================================
   Раньше жил в news-page.js и уезжал КАЖДОМУ читателю ленты: и модалка на
   восемь полей, и кроп-канвас с pointer-обвязкой, — при том что открыть их
   может один человек. admin-news.php подключает этот файл дополнительно к
   news-page.js, публичная news.php — нет.

   Всё, что нужно от ленты (нарисовать карточку превью тем же кодом, что
   рисует ленту, перезагрузить её после сохранения, узнать текущий язык),
   берётся через window.NEWSPAGE — шов, который news-page.js объявляет в
   самом низу. Это единственная связь между файлами: собственного рендера
   карточки у редактора нет и быть не должно, иначе превью начнёт врать.
   ============================================================ */
(() => {
  "use strict";

  const NP = window.NEWSPAGE;
  // Разметки редактора на публичной ленте нет вовсе, а NX_ADMIN_PAGE ставит
  // только admin-news.php. Без этой проверки addEventListener на null бросил
  // бы прямо в теле IIFE, если файл однажды подключат не туда.
  if (!NP || !NP.isAdmin() || !document.querySelector("#newsEditor")) { return; }

  const $ = sel => document.querySelector(sel);
  // Язык живёт в news-page.js (он же его и переключает) — здесь только
  // читается, поэтому функция, а не копия значения: копия протухла бы на
  // первом же переключении RU/EN.
  const LANG = () => NP.getLang();
  const tx = key => I18N.t(key, LANG());

  const NB = window.NEWSBLOCKS;

  // Прежний редактор писал ошибки в #neError напрямую, в семи местах. Блочный
  // добавляет ещё несколько — одна функция вместо восьмой копии той же строки.
  function showError(msg) { $("#neError").textContent = msg || ""; }

  // ------------------------------------------------------------------------
  //  Блоки поста
  // ------------------------------------------------------------------------
  // editorBlocks — единственный источник правды о содержимом: DOM редактора
  // рисуется из него, а не наоборот. Обратное чтение (DOM → спаны) происходит
  // только для текста внутри блока, где разметкой управляет contenteditable.
  let editorBlocks = [];
  // Какой язык сейчас правится. Раскладку не трогает: переключатель меняет
  // только текст, картинки и порядок блоков общие — см. спеку.
  let editorLang = "ru";

  function newBlock(type) {
    if (type === "p")     { return { t: "p", ru: [], en: [] }; }
    if (type === "quote") { return { t: "quote", ru: [], en: [], collapsible: false }; }
    if (type === "code")  { return { t: "code", ru: "", en: "" }; }
    if (type === "list")  { return { t: "list", ordered: false, items: [{ ru: [], en: [] }] }; }
    if (type === "image") { return { t: "image", url: "", w: 0, h: 0, pct: 100, align: "center", wrap: false, cap_ru: [], cap_en: [] }; }
    return { t: "album", items: [], cap_ru: [], cap_en: [] };
  }

  // Спаны → редактируемый DOM. Теги ровно те же, что строит renderBlocks в
  // news-blocks.js, чтобы Ctrl+B в редакторе давал то же самое, что потом
  // покажет лента.
  function spansToEditable(spans) {
    const frag = document.createDocumentFragment();
    for (const sp of spans) {
      let node = document.createTextNode(sp.s);
      const wrap = (tag, cls, attrs) => {
        const w = document.createElement(tag);
        if (cls) { w.className = cls; }
        if (attrs) { for (const k in attrs) { w.setAttribute(k, attrs[k]); } }
        w.append(node);
        node = w;
      };
      if (sp.b)  { wrap("strong"); }
      if (sp.i)  { wrap("em"); }
      if (sp.u)  { wrap("u"); }
      if (sp.st) { wrap("s"); }
      if (sp.c)  { wrap("code"); }
      if (sp.sp) { wrap("span", "nw-spoiler is-open"); }
      if (sp.href) { wrap("a", "", { href: sp.href }); }
      frag.append(node);
    }
    return frag;
  }

  const TAG_FLAG = { STRONG: "b", B: "b", EM: "i", I: "i", U: "u", S: "st", STRIKE: "st", CODE: "c" };

  // Пробел нулевой ширины — служебный разделитель каретки (см. wrapSelection).
  // В модель он не уезжает никогда.
  const ZWSP = "​";

  function sameFormat(a, b) {
    for (const k of ["b", "i", "u", "st", "c", "sp", "href"]) {
      if ((a[k] || false) !== (b[k] || false)) { return false; }
    }
    return true;
  }

  // Редактируемый DOM → спаны. Белый список тегов: всё, чего в нём нет,
  // схлопывается в текст. Это второй рубеж после «вставка только как plain
  // text»: браузер и сам любит насовать <font> и <div> в contenteditable, и
  // ни один из них не имеет права доехать до базы.
  function blockToSpans(root) {
    const out = [];
    const walk = (node, state) => {
      if (node.nodeType === 3) {
        const text = node.nodeValue.split(ZWSP).join("");
        if (text === "") { return; }
        const sp = { s: text };
        for (const k in state) { if (state[k]) { sp[k] = state[k]; } }
        // Соседние одинаково оформленные куски склеиваются: contenteditable
        // дробит текст на узлы, а в базе нужен минимальный список.
        const prev = out[out.length - 1];
        if (prev && sameFormat(prev, sp)) { prev.s += sp.s; } else { out.push(sp); }
        return;
      }
      if (node.nodeType !== 1) { return; }
      if (node.tagName === "BR") { return; }
      const next = Object.assign({}, state);
      const flag = TAG_FLAG[node.tagName];
      if (flag) { next[flag] = true; }
      if (node.tagName === "SPAN" && node.classList.contains("nw-spoiler")) { next.sp = true; }
      if (node.tagName === "A") {
        const href = node.getAttribute("href") || "";
        // Небезопасный href не переносится в модель вовсе — текст остаётся,
        // ссылка исчезает. Ровно то же делает рендер (spansToFragment).
        if (NB.isSafeHref(href)) { next.href = href; }
      }
      for (const kid of Array.from(node.childNodes)) { walk(kid, next); }
    };
    for (const kid of Array.from(root.childNodes)) { walk(kid, {}); }
    return out;
  }

  // Документ, который уедет на сервер. Пустые блоки выбрасываются здесь, а не
  // запрещаются при вводе: пустой абзац — нормальное промежуточное состояние
  // набора текста, но в базе ему делать нечего.
  function currentDoc() {
    const blocks = editorBlocks.filter(b => {
      if (b.t === "p" || b.t === "quote") { return (b.ru.length + b.en.length) > 0; }
      if (b.t === "code") { return b.ru !== "" || b.en !== ""; }
      if (b.t === "list") { return b.items.some(i => i.ru.length + i.en.length > 0); }
      if (b.t === "image") { return !!b.url; }
      return b.items.length >= 2;
    });
    return { v: NB.DOC_VERSION, blocks: blocks };
  }

  // Легаси-пост превращается в блоки в памяти. Миграции данных нет: пока админ
  // не нажал «Опубликовать», в базе всё лежит по-старому.
  function blocksFromLegacy(post) {
    const blocks = [];
    if (post.image_url) {
      blocks.push({
        t: "image", url: post.image_url,
        w: post.image_width || 0, h: post.image_height || 0,
        pct: Number(post.image_pct) || 100,
        align: NEWS.isAlign(post.image_align) ? post.image_align : "center",
        wrap: !!post.image_wrap,
        cap_ru: [], cap_en: []
      });
    }
    const ru = NEWS.toParagraphs(post.body_ru || "");
    const en = NEWS.toParagraphs(post.body_en || "");
    const n = Math.max(ru.length, en.length);
    for (let i = 0; i < n; i++) {
      blocks.push({
        t: "p",
        ru: ru[i] ? [{ s: ru[i] }] : [],
        en: en[i] ? [{ s: en[i] }] : []
      });
    }
    return blocks;
  }

  // Превью — заметная работа на каждое нажатие клавиши, поэтому обновление
  // дебаунсится. Живёт на уровне модуля (а не внутри wireAdmin, как раньше):
  // его зовёт и обвязка полей, и каждый блок.
  let previewDebounceTimer = null;
  function schedulePreviewUpdate() {
    clearTimeout(previewDebounceTimer);
    previewDebounceTimer = setTimeout(updatePreview, 150);
  }

  // --------------------------- Отрисовка списка ---------------------------

  function renderBlockList() {
    const box = $("#neBlocks");
    box.innerHTML = "";
    editorBlocks.forEach((b, i) => box.append(blockRow(b, i)));
    // Третий аргумент I18N.t — подстановки, как в news.confirmDelete.
    $("#neBlockCount").textContent = I18N.t("news.blockCount", LANG(),
      { n: editorBlocks.length, max: NB.LIMITS.blocks });
    updatePreview();
  }

  function blockRow(b, index) {
    const row = document.createElement("div");
    row.className = "ne-block ne-block-" + b.t;
    row.dataset.index = String(index);
    // Тащим только за ручку: иначе внутри блока нельзя было бы выделить текст.
    row.draggable = false;

    const grip = document.createElement("button");
    grip.type = "button";
    grip.className = "ne-grip";
    grip.title = tx("news.blockMove");
    grip.textContent = "⠿";
    grip.addEventListener("pointerdown", () => { row.draggable = true; });
    row.addEventListener("dragend", () => { row.draggable = false; });

    const del = document.createElement("button");
    del.type = "button";
    del.className = "ne-block-del";
    del.title = tx("news.blockRemove");
    del.textContent = "✕";
    del.addEventListener("click", () => { editorBlocks.splice(index, 1); renderBlockList(); });

    row.append(grip, blockEditor(b, index), del);
    return row;
  }

  function blockEditor(b, index) {
    if (b.t === "code") {
      const ta = document.createElement("textarea");
      ta.className = "ne-code";
      ta.rows = 3;
      ta.value = b[editorLang];
      ta.addEventListener("input", () => { b[editorLang] = ta.value; schedulePreviewUpdate(); });
      return ta;
    }
    if (b.t === "image" || b.t === "album") { return mediaBlockEditor(b, index); }

    const wrap = document.createElement("div");
    wrap.className = "ne-block-body";

    if (b.t === "quote") {
      wrap.append(checkbox("news.blockCollapsible", b.collapsible, v => { b.collapsible = v; schedulePreviewUpdate(); }));
    }
    if (b.t === "list") {
      wrap.append(checkbox("news.blockOrdered", b.ordered, v => { b.ordered = v; schedulePreviewUpdate(); }));
      b.items.forEach(it => wrap.append(editableFor(it, index)));
      return wrap;
    }
    wrap.append(editableFor(b, index));
    return wrap;
  }

  function checkbox(i18nKey, checked, onChange) {
    const lbl = document.createElement("label");
    lbl.className = "ne-inline-check";
    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.checked = !!checked;
    cb.addEventListener("change", () => onChange(cb.checked));
    lbl.append(cb, document.createTextNode(" " + tx(i18nKey)));
    return lbl;
  }

  function editableFor(holder, index) {
    const ed = document.createElement("div");
    ed.className = "ne-editable";
    ed.contentEditable = "true";
    ed.dataset.index = String(index);
    // Куда писать прочитанные спаны — прямо на элементе. holder бывает и самим
    // блоком, и пунктом списка, и подписью картинки; вычислять его заново из
    // индексов значило бы завести второй способ сказать то же самое и потом
    // гадать, какой из них устарел.
    ed._holder = holder;
    ed.append(spansToEditable(holder[editorLang] || []));

    // Вставка всегда простым текстом: скопированный из телеграма пост несёт
    // чужую разметку, и белый список в blockToSpans — не повод пускать её в
    // DOM редактора вообще.
    ed.addEventListener("paste", ev => {
      ev.preventDefault();
      const text = (ev.clipboardData || window.clipboardData).getData("text/plain");
      document.execCommand("insertText", false, text);
    });
    ed.addEventListener("input", () => {
      holder[editorLang] = blockToSpans(ed);
      schedulePreviewUpdate();
    });
    return ed;
  }

  // Кто ждёт результата кропа. Ровно один за раз: диалог модальный, а
  // повторный выбор файла до подтверждения предыдущего сначала закрывает
  // предыдущий (см. startCropFor).
  let cropResolve = null;
  // Ширина, под которую надо сохранить файл: потолок стороны считается от неё
  // (NEWS.newsImageCap → resolve_upload_max_side на сервере). Раньше её давал
  // общий на весь пост #nePct, теперь — блок, который открыл диалог.
  let cropTargetPct = 100;

  function settleCrop(result) {
    if (cropResolve) { cropResolve(result); cropResolve = null; }
  }

  // Открывает кроп для файла и обещает {url, w, h} — то, что вернул
  // upload.php после пересжатия. null, если админ закрыл диалог, ничего не
  // выбрав, или заливка не удалась.
  function pickImage(file, pct) {
    cropTargetPct = pct;
    return new Promise(resolve => {
      cropResolve = resolve;
      startCropFor(file);
    });
  }

  // Показывает кроп-редактор для выбранного файла. Раньше это было тело
  // обработчика "change" на #neImageFile — единственного поля выбора файла на
  // весь пост. Теперь файл приходит от конкретного блока (см. pickImage), а
  // результат возвращается ему же обещанием, а не пишется в общее состояние.
  async function startCropFor(file) {
    // Повторный выбор файла до подтверждения предыдущего кропа: закрываем
    // старый диалог и отказываем его обещанию, иначе тот блок остался бы
    // ждать картинку вечно.
    if (cropSrc) { closeCropUI(); }

    const originalDataUrl = await readFileAsDataURL(file);
    const decoded = await decodeImageForCrop(file).catch(() => null);

    if (!decoded) {
      // Файл не декодировался ни через createImageBitmap, ни через <img>
      // (неизвестный браузеру формат вроде HEIC) — показывать кроп-редактор
      // нечем. Откатываемся на прежнее поведение: заливаем файл как есть,
      // без кадрирования, а не блокируем загрузку картинки вовсе.
      pickedImageDataUrl = originalDataUrl;
      settleCrop(await uploadPickedImage());
      return;
    }

    cropSrc = decoded;
    cropOriginalDataUrl = originalDataUrl;
    // JPEG-исходник остаётся JPEG при экспорте кропа (см. confirmCrop) —
    // всё остальное (PNG, WebP, GIF-как-картинка) становится PNG, чтобы не
    // потерять альфа-канал, который downscale_image_bytes() на сервере тоже
    // бережёт только для не-JPEG форматов.
    cropOutputMime = file.type === "image/jpeg" ? "image/jpeg" : "image/png";
    $("#neCrop").hidden = false;
    initCropState();
  }

  function mediaBlockEditor(b, index) {
    const wrap = document.createElement("div");
    wrap.className = "ne-media";

    const strip = document.createElement("div");
    strip.className = "ne-thumbs";
    wrap.append(strip);

    const drawThumbs = () => {
      strip.innerHTML = "";
      const items = b.t === "image"
        ? (b.url ? [{ url: b.url, w: b.w, h: b.h }] : [])
        : b.items;
      items.forEach((im, j) => {
        const th = document.createElement("div");
        th.className = "ne-thumb";
        const img = document.createElement("img");
        img.src = im.url;
        img.alt = "";
        const x = document.createElement("button");
        x.type = "button";
        x.title = tx("news.blockRemoveImage");
        x.textContent = "✕";
        x.addEventListener("click", () => {
          if (b.t === "image") { b.url = ""; b.w = 0; b.h = 0; }
          else { b.items.splice(j, 1); }
          drawThumbs();
          if (geom) { geom.refresh(); }
          updatePreview();
        });
        th.append(img, x);
        strip.append(th);
      });

      const roomLeft = b.t === "image" ? !b.url : b.items.length < NB.LIMITS.albumItems;
      if (roomLeft) {
        const add = document.createElement("button");
        add.type = "button";
        add.className = "ne-thumb ne-thumb-add";
        add.title = tx("news.blockAddImage");
        add.textContent = "＋";
        add.addEventListener("click", () => chooseFile(b, drawThumbs, geom));
        strip.append(add);
      }
    };

    // Геометрия — только у одиночной картинки. У альбома её нет: плитка всегда
    // во всю ширину, и ползунок ширины там означал бы не то же, что у картинки.
    const geom = b.t === "image" ? imageGeometryControls(b) : null;

    drawThumbs();
    if (geom) { wrap.append(geom.el); }

    // Подпись — тот же редактируемый div, что у абзаца, только пишет в
    // cap_ru/cap_en. Спаны в подписи разрешены, поэтому ссылка в ней работает
    // так же, как везде.
    const capHolder = {
      get ru() { return b.cap_ru; }, set ru(v) { b.cap_ru = v; },
      get en() { return b.cap_en; }, set en(v) { b.cap_en = v; }
    };
    const cap = document.createElement("div");
    cap.className = "ne-cap-field";
    const capLbl = document.createElement("span");
    capLbl.className = "ne-cap-label";
    capLbl.textContent = tx("news.blockCaption");
    cap.append(capLbl, editableFor(capHolder, index));
    wrap.append(cap);

    return wrap;
  }

  function chooseFile(b, redraw, geom) {
    const input = document.createElement("input");
    input.type = "file";
    input.accept = "image/*";
    input.addEventListener("change", async () => {
      const file = input.files && input.files[0];
      if (!file) { return; }
      const got = await pickImage(file, b.t === "image" ? b.pct : 100);
      if (!got) { return; }
      if (b.t === "image") { b.url = got.url; b.w = got.w; b.h = got.h; }
      else { b.items.push({ url: got.url, w: got.w, h: got.h }); }
      redraw();
      if (geom) { geom.refresh(); }
      updatePreview();
    });
    input.click();
  }

  function imageGeometryControls(b) {
    const row = document.createElement("div");
    row.className = "ne-geom";

    const pct = document.createElement("input");
    pct.type = "range";
    pct.min = "10"; pct.max = "100"; pct.step = "5";
    pct.value = String(b.pct);
    const out = document.createElement("output");
    out.textContent = b.pct + "%";

    // Подсказка о мыле — та же, что раньше висела на весь пост (#nePctHint),
    // только теперь у каждой картинки своя: файл сохранён под потолок той
    // ширины, что стояла при заливке, и поднять ширину выше значит растянуть
    // уже сжатые пиксели. Перезалить нечем: исходника открытого поста в
    // памяти браузера нет.
    const hint = document.createElement("p");
    hint.className = "ne-hint";
    const refresh = () => {
      const stored = Math.max(b.w || 0, b.h || 0);
      const stale = !!b.url && stored > 0 && NEWS.newsImageCap(b.pct) > stored;
      hint.hidden = !stale;
      hint.textContent = stale ? tx("news.imageStale") : "";
    };
    refresh();

    pct.addEventListener("input", () => {
      b.pct = Number(pct.value);
      out.textContent = b.pct + "%";
      refresh();
      schedulePreviewUpdate();
    });

    const seg = document.createElement("div");
    seg.className = "ne-cat-seg";
    for (const a of NEWS.ALIGNS) {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = I18N.t(a.i18n, LANG());
      btn.className = a.key === b.align ? "active" : "";
      btn.addEventListener("click", () => {
        b.align = a.key;
        for (const other of seg.children) { other.classList.remove("active"); }
        btn.classList.add("active");
        updatePreview();
      });
      seg.append(btn);
    }

    row.append(pct, out, seg,
      checkbox("news.fieldImageWrap", b.wrap, v => { b.wrap = v; updatePreview(); }),
      hint);
    return { el: row, refresh: refresh };
  }

  // ------------------------ Форматирование выделения ------------------------

  const EXEC = { b: "bold", i: "italic", u: "underline", st: "strikeThrough" };

  // fromMarkdown=true — вызов из разметки на лету: там форматирование обязано
  // закончиться вместе с распознанным куском, поэтому даже жирный/курсив
  // ставятся своей обёрткой, а не execCommand. У execCommand стиль «липкий»:
  // после **жирный** весь дальнейший ввод продолжал бы идти жирным.
  function applyFormat(kind, fromMarkdown) {
    if (EXEC[kind] && !fromMarkdown) {
      document.execCommand(EXEC[kind], false, null);
    } else if (FMT_TAG[kind]) {
      // is-open у спойлера: прятать от автора текст, который он же и пишет,
      // незачем — в ленте класс ставится с нуля.
      wrapSelection(kind);
    } else if (kind === "a") {
      const url = window.prompt(tx("news.linkPrompt"), "https://");
      if (url === null) { return; }
      if (!NB.isSafeHref(url)) { showError(tx("news.linkBad")); return; }
      document.execCommand("createLink", false, url);
    }
    syncActiveEditable();
  }

  const FMT_TAG = {
    b: ["strong", ""], i: ["em", ""], u: ["u", ""], st: ["s", ""],
    c: ["code", ""], sp: ["span", "nw-spoiler is-open"]
  };

  // Обёртка выделения тегом. Каретка ставится ПОСЛЕ обёртки, в свежий пустой
  // текстовый узел: без него дальнейший ввод попадал бы внутрь только что
  // созданного <strong> (у execCommand это «липкое» состояние стиля), а
  // removeAllRanges вместо этого оставлял бы блок вообще без каретки — и
  // следующая же буква улетала бы в начало абзаца.
  function wrapSelection(fmt) {
    const sel = document.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) { return; }
    const [tag, cls] = FMT_TAG[fmt];
    const range = sel.getRangeAt(0);
    const el = document.createElement(tag);
    if (cls) { el.className = cls; }
    el.append(range.extractContents());
    range.insertNode(el);

    // Пробел нулевой ширины, а не пустой текстовый узел: каретку сразу за
    // инлайновым элементом Chrome считает «внутри» него, и следующая буква
    // уезжала бы в тот же <strong>. Внутри настоящего символа она снаружи
    // однозначно. Сам символ в контент не попадает — blockToSpans его срезает.
    const after = document.createTextNode(ZWSP);
    el.parentNode.insertBefore(after, el.nextSibling);
    const caret = document.createRange();
    caret.setStart(after, 1);
    caret.collapse(true);
    sel.removeAllRanges();
    sel.addRange(caret);
  }

  function activeEditable() {
    const sel = document.getSelection();
    const node = sel && sel.anchorNode;
    if (!node) { return null; }
    const el = node.nodeType === 1 ? node : node.parentElement;
    return el ? el.closest(".ne-editable") : null;
  }

  // execCommand меняет DOM, а не наш массив. Без обратного чтения превью
  // отставало бы на одно действие, а «Опубликовать» отправил бы текст без
  // только что поставленного форматирования.
  function syncActiveEditable() {
    const ed = activeEditable();
    if (!ed || !ed._holder) { return; }
    ed._holder[editorLang] = blockToSpans(ed);
    schedulePreviewUpdate();
  }


  const editor = $("#newsEditor");
  let editingPost = null;
  // Data URL картинки, выбранной в текущей сессии кропа. Живёт от выбора файла
  // до ответа upload.php — дальше блок хранит уже залитый /images/<sha1>.
  let pickedImageDataUrl = "";


  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

  // ---------- Кроп-редактор картинки (между выбором файла и заливкой) ----------
  // Геометрия (перевод рамки+зума+панорамы в прямоугольник исходника) живёт в
  // NEWS.cropToSourceRect (news.js, покрыта тестами в node) — здесь только
  // canvas и pointer-обработчики, которые эту геометрию используют и рисуют.
  //
  // cropSrc — декодированный исходник, с которого рисует canvas:
  // { source: ImageBitmap|HTMLImageElement, width, height, isBitmap, objectUrl? }.
  // Держится ровно один битмап за раз (см. closeCropUI) — требование
  // "не хранить несколько полноразмерных копий" из
  // docs/superpowers/specs/2026-08-03-safari-memory-and-i18n-design.md,
  // тот же файл объясняет, почему в этом проекте к декодированной памяти
  // относятся всерьёз, а не формально.
  let cropSrc = null;
  let cropOriginalDataUrl = "";  // байты файла как есть — источник для «Без кадрирования»
  let cropOutputMime = "image/png";
  let cropViewport = { w: 0, h: 0 }; // css-размер холста редактора
  let cropZoomFit = 1;               // масштаб, вписывающий весь исходник в холст (zoom=1)
  let cropZoom = 1;                  // множитель поверх cropZoomFit, из ползунка/колеса (1..4)
  let cropPan = { x: 0, y: 0 };      // левый верхний угол картинки на холсте, css-px
  let cropFrame = { x: 0, y: 0, w: 0, h: 0 }; // рамка выделения, те же координаты
  let cropCtx = null;
  let cropDrag = null; // активный pointer-жест: {kind, pointerId, ...}

  const CROP_MIN_FRAME = 32; // минимальный размер рамки, px — чтобы её не сжали в точку
  const CROP_KEY_STEP = 5;   // шаг стрелок клавиатуры, px

  function cropEffectiveZoom() { return cropZoomFit * cropZoom; }

  // Сегмент категорий строится из того же списка, что рисует чипы фильтра —
  // добавленная в news.js категория появляется в редакторе сама.
  let editorCat = "tierlist";

  function renderCatSeg() {
    const box = $("#neCat");
    box.innerHTML = "";
    for (const c of NEWS.CATEGORIES) {
      const b = document.createElement("button");
      b.type = "button";
      b.dataset.v = c.key;
      b.className = c.key === editorCat ? "active" : "";
      b.textContent = tx(c.i18n);
      b.addEventListener("click", () => { editorCat = c.key; renderCatSeg(); updatePreview(); });
      box.append(b);
    }
  }
  function setCat(key) { editorCat = NEWS.isCategory(key) ? key : "tierlist"; renderCatSeg(); }
  function getCat() { return editorCat; }



  // <input type="date"> работает в YYYY-MM-DD и в локальном времени.
  // Полдень, а не полночь: сдвиг часового пояса на пару часов не должен
  // перекидывать дату поста на соседний день.
  const isoDay = ms => {
    const d = new Date(ms);
    const p = n => String(n).padStart(2, "0");
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
  };
  const dayToMs = value => {
    const [y, m, d] = String(value).split("-").map(Number);
    return new Date(y, (m || 1) - 1, d || 1, 12, 0, 0).getTime();
  };



  // Читает File как data URL — то же, что раньше делал обработчик
  // #neImageFile целиком; вынесено отдельно, потому что теперь нужно и для
  // «Без кадрирования» (байты как есть), и как запасной путь, если картинку
  // не удалось декодировать для кропа.
  function readFileAsDataURL(file) {
    return new Promise(res => {
      const fr = new FileReader();
      fr.onload = () => res(fr.result);
      fr.readAsDataURL(file);
    });
  }

  // Декодирует выбранный файл в рисуемый на canvas источник для кроп-редактора.
  //
  // createImageBitmap(file, {imageOrientation: "from-image"}) — единственный
  // способ отменить EXIF-поворот САМОСТОЯТЕЛЬНО, до того как картинка ляжет
  // на canvas: телефон пишет портретное фото как альбомное с флагом
  // Orientation в EXIF, а не физически поворачивает пиксели. Без этой опции
  // (или без самого createImageBitmap) кроп-редактор нарисует фото с айфона
  // лежащим на боку — ровно тот баг, из-за которого этот метод обязателен.
  //
  // Фолбэк на <img>, если createImageBitmap недоступен или не смог
  // декодировать конкретный файл: <img> EXIF Orientation не читает вообще
  // (это не его контракт), так что портретное фото ляжет повернутым — этот
  // путь существует только чтобы кроп-редактор продолжал работать в старых
  // браузерах (Safari < 15.4 и т.п.), а не чтобы решить проблему ориентации
  // так же хорошо, как createImageBitmap.
  async function decodeImageForCrop(file) {
    if (typeof createImageBitmap === "function") {
      try {
        const bmp = await createImageBitmap(file, { imageOrientation: "from-image" });
        return { source: bmp, width: bmp.width, height: bmp.height, isBitmap: true };
      } catch (e) {
        console.warn("createImageBitmap не смог декодировать файл, пробуем <img>", e);
      }
    }
    const objectUrl = URL.createObjectURL(file);
    try {
      const img = await new Promise((resolve, reject) => {
        const el = new Image();
        el.onload = () => resolve(el);
        el.onerror = () => reject(new Error("image decode failed"));
        el.src = objectUrl;
      });
      return { source: img, width: img.naturalWidth, height: img.naturalHeight, isBitmap: false, objectUrl };
    } catch (e) {
      URL.revokeObjectURL(objectUrl);
      return null;
    }
  }

  // Размер холста редактора берётся из реальной раскладки (getBoundingClientRect),
  // а не из фиксированной константы — модалка адаптивная (@media в base.css),
  // и ширина холста меняется вместе с ней.
  //
  // DPR зажат в 2 (а не взят как есть — на телефоне 3): холст временный,
  // существует только на время редактирования, но не должен раздувать
  // decoded-буфер втрое ради резкости, которую всё равно перекроет финальный
  // рендер в полном разрешении при подтверждении (см. confirmCrop).
  function setupCropCanvasSize() {
    const stage = $("#neCropStage");
    const canvas = $("#neCropCanvas");
    const rect = stage.getBoundingClientRect();
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    cropViewport = { w: rect.width, h: rect.height };
    canvas.width = Math.max(1, Math.round(rect.width * dpr));
    canvas.height = Math.max(1, Math.round(rect.height * dpr));
    cropCtx = canvas.getContext("2d");
    cropCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  // Зажимает панораму так, чтобы картинка всегда полностью закрывала холст —
  // иначе при перетаскивании по краю появлялась бы пустая область без
  // изображения. При zoom>=cropZoomFit картинка на обеих осях не меньше
  // холста, поэтому диапазон [vw-iw, 0] (и его аналог по Y) всегда корректен.
  function clampPan() {
    if (!cropSrc) { return; }
    const eff = cropEffectiveZoom();
    const iw = cropSrc.width * eff, ih = cropSrc.height * eff;
    const vw = cropViewport.w, vh = cropViewport.h;
    cropPan.x = clamp(cropPan.x, Math.min(0, vw - iw), Math.max(0, vw - iw));
    cropPan.y = clamp(cropPan.y, Math.min(0, vh - ih), Math.max(0, vh - ih));
  }

  // Рамка выделения — независимый прямоугольник холста (не привязан к
  // картинке под ним впрямую, только к границам холста и минимальному
  // размеру); cropToSourceRect() сам зажимает то, что рамка в итоге читает
  // из исходника, так что здесь достаточно не выпускать рамку за холст.
  function clampFrameToViewport() {
    cropFrame.w = clamp(cropFrame.w, CROP_MIN_FRAME, cropViewport.w);
    cropFrame.h = clamp(cropFrame.h, CROP_MIN_FRAME, cropViewport.h);
    cropFrame.x = clamp(cropFrame.x, 0, cropViewport.w - cropFrame.w);
    cropFrame.y = clamp(cropFrame.y, 0, cropViewport.h - cropFrame.h);
  }

  function drawCrop() {
    if (!cropSrc || !cropCtx) { return; }
    const eff = cropEffectiveZoom();
    cropCtx.clearRect(0, 0, cropViewport.w, cropViewport.h);
    cropCtx.drawImage(
      cropSrc.source, 0, 0, cropSrc.width, cropSrc.height,
      cropPan.x, cropPan.y, cropSrc.width * eff, cropSrc.height * eff
    );
  }

  function positionCropFrameEl() {
    const el = $("#neCropFrame");
    el.style.left = cropFrame.x + "px";
    el.style.top = cropFrame.y + "px";
    el.style.width = cropFrame.w + "px";
    el.style.height = cropFrame.h + "px";
    // Хэндлы позиционируются в координатах самой рамки (0..w, 0..h) — она
    // сама position:absolute, так что дети-хэндлы анкерятся к её боксу.
    const half = 8; // половина стороны хэндла, см. .ne-crop-handle в news.css
    const corners = { nw: [0, 0], ne: [cropFrame.w, 0], sw: [0, cropFrame.h], se: [cropFrame.w, cropFrame.h] };
    for (const corner of Object.keys(corners)) {
      const [dx, dy] = corners[corner];
      const h = el.querySelector('[data-corner="' + corner + '"]');
      h.style.left = (dx - half) + "px";
      h.style.top = (dy - half) + "px";
    }
  }

  function updateCropDimsOutput() {
    if (!cropSrc) { return; }
    const rect = NEWS.cropToSourceRect(cropFrame, cropEffectiveZoom(), cropPan, { width: cropSrc.width, height: cropSrc.height });
    const w = Math.max(1, Math.round(rect.sw));
    const h = Math.max(1, Math.round(rect.sh));
    $("#neCropDims").textContent = I18N.t("news.cropDims", LANG(), { w, h });
  }

  // Общая точка входа после любого изменения состояния (панорама, зум,
  // перемещение/ресайз рамки) — перерисовывает холст, рамку и индикатор
  // итогового размера разом, чтобы вызывающему коду не нужно было помнить,
  // какие три вещи держать в синхроне.
  function updateCropUI() {
    drawCrop();
    positionCropFrameEl();
    updateCropDimsOutput();
  }

  // Масштабирует вокруг точки холста (anchorX, anchorY) — точка исходника,
  // которая была под этой точкой холста, остаётся под ней и после смены
  // зума. Без этого движение ползунка/колеса «уводило» бы картинку в
  // сторону от того места, где админ крутил колесо.
  function applyCropZoom(zoomPct, anchorX, anchorY) {
    if (!cropSrc) { return; }
    const oldEff = cropEffectiveZoom();
    const imgX = (anchorX - cropPan.x) / oldEff;
    const imgY = (anchorY - cropPan.y) / oldEff;
    cropZoom = clamp(zoomPct / 100, 1, 4);
    const newEff = cropEffectiveZoom();
    cropPan.x = anchorX - imgX * newEff;
    cropPan.y = anchorY - imgY * newEff;
    clampPan();
    updateCropUI();
  }

  // Начальное состояние после декодирования картинки: зум «вписать целиком»,
  // картинка отцентрована, рамка выделения — почти весь холст, но НЕ вплотную
  // к краям (6% отступ с каждой стороны). Рамка вплотную к краю оставляла бы
  // panning (перетаскивание холста ЗА пределами рамки) недоступным сразу
  // после открытия — схватить нечего, вся площадь холста уже занята рамкой.
  // Отступ даёт видимую полосу картинки вокруг рамки, за которую можно
  // потянуть, не требуя сначала сузить рамку вручную.
  function initCropState() {
    setupCropCanvasSize();
    const vw = cropViewport.w, vh = cropViewport.h;
    cropZoomFit = Math.min(vw / cropSrc.width, vh / cropSrc.height) || 1;
    cropZoom = 1;
    const eff = cropEffectiveZoom();
    cropPan = { x: (vw - cropSrc.width * eff) / 2, y: (vh - cropSrc.height * eff) / 2 };
    const marginX = vw * 0.06, marginY = vh * 0.06;
    cropFrame = { x: marginX, y: marginY, w: vw - marginX * 2, h: vh - marginY * 2 };
    $("#neCropZoom").value = 100;
    $("#neCropZoomValue").textContent = "100%";
    updateCropUI();
  }

  // Закрывает вкладку кроп-редактора и освобождает декодированный источник —
  // ImageBitmap.close() отпускает декодированный битмап немедленно, не
  // дожидаясь сборщика мусора (та же дисциплина памяти, что и у остального
  // проекта, см. Safari-спеку в шапке файла). У фолбэка на <img> вместо
  // close() отзывается blob-URL, который держал его src.
  // settle=false зовут confirmCrop/skipCrop: у них результат ещё впереди, и
  // разрешать обещание null-ом на этом шаге значило бы отменить собственную
  // же заливку. Все остальные пути закрытия (Escape, ✕, повторный выбор
  // файла) — это отказ, и блок обязан узнать о нём, иначе он навсегда
  // останется ждать картинку.
  function closeCropUI(settle = true) {
    if (settle && cropResolve) { cropResolve(null); cropResolve = null; }
    if (cropSrc) {
      if (cropSrc.isBitmap) { cropSrc.source.close(); }
      if (cropSrc.objectUrl) { URL.revokeObjectURL(cropSrc.objectUrl); }
    }
    cropSrc = null;
    cropOriginalDataUrl = "";
    cropDrag = null;
    const box = $("#neCrop");
    if (box) { box.hidden = true; }
  }

  // Кадрирует в ПОЛНОМ разрешении исходника — единственный раз, здесь, а не
  // на каждый кадр превью. Рисует прямо из cropSrc.source (ImageBitmap/<img>)
  // в новый холст размера кропа, а не масштабирует уже уменьшенный
  // превью-канвас (тот и так CSS-размера редактора, из него не вытянуть
  // пиксели исходника обратно).
  async function confirmCrop() {
    if (!cropSrc) { return; }
    const rect = NEWS.cropToSourceRect(cropFrame, cropEffectiveZoom(), cropPan, { width: cropSrc.width, height: cropSrc.height });
    const sx = Math.round(rect.sx), sy = Math.round(rect.sy);
    const sw = Math.max(1, Math.round(rect.sw)), sh = Math.max(1, Math.round(rect.sh));

    // Потолок стороны — тот же, что сервер применит к текущей выбранной
    // ширине (news_image_cap() в api/lib/images.php, см. NEWS.newsImageCap()
    // в news.js). Без этого шага исходник шёл на выходной canvas в полный
    // размер кропа В ПИКСЕЛЯХ ИСТОЧНИКА: скриншот телефона 3000×4000,
    // обрезанный не в упор, легко даёт 15-25 МБ PNG (~30 МБ в base64) —
    // сервер такое всё равно обрежет под потолок, но не раньше, чем
    // NEWS_IMAGE_MAX_BYTES (6 МБ) в save_image_bytes() бросит "image too
    // large", а то и post_max_size на хостинге оборвёт запрос ещё раньше.
    // Даунскейл здесь — не дублирование серверного, а экономия byte'ов,
    // которые сервер всё равно бы выбросил.
    const cap = NEWS.newsImageCap(cropTargetPct);
    const longestSide = Math.max(sw, sh);
    const scale = longestSide > cap ? cap / longestSide : 1;
    const dw = Math.max(1, Math.round(sw * scale));
    const dh = Math.max(1, Math.round(sh * scale));

    const out = document.createElement("canvas");
    out.width = dw;
    out.height = dh;
    const octx = out.getContext("2d");
    // sx/sy/sw/sh (окно в исходнике) → 0/0/dw/dh (выходной canvas) одним
    // вызовом: так ресемплинг делает сам drawImage за один проход, а не
    // канвас-в-канвас в два прохода с двойной потерей резкости.
    octx.drawImage(cropSrc.source, sx, sy, sw, sh, 0, 0, dw, dh);
    // JPEG-исходник остаётся JPEG (0.9 — тот же компромисс качество/вес, что
    // и у imagejpeg(..., 88) на сервере в images.php), всё остальное — PNG,
    // чтобы не потерять альфа-канал.
    const quality = cropOutputMime === "image/jpeg" ? 0.9 : undefined;
    const dataUrl = out.toDataURL(cropOutputMime, quality);

    closeCropUI(false);
    pickedImageDataUrl = dataUrl;
    settleCrop(await uploadPickedImage());
  }

  // «Без кадрирования»: заливает файл ровно так, как он был выбран — байты
  // из readFileAsDataURL, а не что-либо, прошедшее через canvas.
  async function skipCrop() {
    const original = cropOriginalDataUrl;
    closeCropUI(false);
    pickedImageDataUrl = original;
    settleCrop(await uploadPickedImage());
  }

  // ---- pointer-жесты кроп-редактора: панорама картинки, перемещение и
  // ресайз рамки. Оба обработчика рано выходят, если cropDrag нет (жест не
  // из кроп-редактора) — их можно вешать на document безусловно.
  function cropPointerMove(ev) {
    if (!cropDrag || ev.pointerId !== cropDrag.pointerId) { return; }
    const dx = ev.clientX - cropDrag.startX;
    const dy = ev.clientY - cropDrag.startY;

    if (cropDrag.kind === "pan") {
      cropPan = { x: cropDrag.startPan.x + dx, y: cropDrag.startPan.y + dy };
      clampPan();
    } else if (cropDrag.kind === "move") {
      cropFrame = {
        ...cropDrag.startFrame,
        x: clamp(cropDrag.startFrame.x + dx, 0, cropViewport.w - cropDrag.startFrame.w),
        y: clamp(cropDrag.startFrame.y + dy, 0, cropViewport.h - cropDrag.startFrame.h),
      };
    } else if (cropDrag.kind === "resize") {
      resizeFrameFromAnchor(cropDrag.anchor, cropDrag.startCorner.x + dx, cropDrag.startCorner.y + dy);
    }
    updateCropUI();
  }

  function cropPointerUp(ev) {
    if (!cropDrag || ev.pointerId !== cropDrag.pointerId) { return; }
    cropDrag = null;
  }

  // Перетаскивает противоположный от anchor угол рамки в точку (px, py) холста,
  // зажатую в границы холста и не ближе CROP_MIN_FRAME к anchor — та же
  // формула, что описана и протестирована для cropToSourceRect: рамка это
  // просто прямоугольник между anchor и текущей точкой курсора.
  function resizeFrameFromAnchor(anchor, px, py) {
    px = clamp(px, 0, cropViewport.w);
    py = clamp(py, 0, cropViewport.h);
    if (Math.abs(px - anchor.x) < CROP_MIN_FRAME) {
      px = anchor.x + CROP_MIN_FRAME * (px < anchor.x ? -1 : 1);
    }
    if (Math.abs(py - anchor.y) < CROP_MIN_FRAME) {
      py = anchor.y + CROP_MIN_FRAME * (py < anchor.y ? -1 : 1);
    }
    px = clamp(px, 0, cropViewport.w);
    py = clamp(py, 0, cropViewport.h);
    cropFrame = {
      x: Math.min(anchor.x, px), y: Math.min(anchor.y, py),
      w: Math.abs(px - anchor.x), h: Math.abs(py - anchor.y),
    };
  }

  // anchor — противоположный (неподвижный) угол рамки, se остаётся точкой на
  // месте, пока тащат nw, и т.д. corner — сам перетаскиваемый угол, нужен
  // отдельно, чтобы прибавлять dx/dy к его СОБСТВЕННОЙ стартовой позиции, а
  // не к левому верхнему углу рамки (это не одно и то же для трёх из
  // четырёх хэндлов).
  const CROP_ANCHORS = {
    nw: f => ({ x: f.x + f.w, y: f.y + f.h }),
    ne: f => ({ x: f.x,       y: f.y + f.h }),
    sw: f => ({ x: f.x + f.w, y: f.y }),
    se: f => ({ x: f.x,       y: f.y }),
  };
  const CROP_CORNER = {
    nw: f => ({ x: f.x,       y: f.y }),
    ne: f => ({ x: f.x + f.w, y: f.y }),
    sw: f => ({ x: f.x,       y: f.y + f.h }),
    se: f => ({ x: f.x + f.w, y: f.y + f.h }),
  };

  // Собирает пост из текущего состояния формы — ровно то, что publish()
  // отправит на сервер, только ещё не отправленное. cardFor() строит из него
  // настоящую карточку, поэтому превью не может нарисовать что-то, чего не
  // нарисует и лента.
  function buildPreviewPost() {
    const dateVal = $("#neDate").value;
    const previewDoc = currentDoc();
    const previewFirstImage = NB.firstImage(previewDoc.blocks);
    return {
      id: editingPost ? editingPost.id : 0,
      category: getCat(),
      title_ru: $("#neTitleRu").value,
      title_en: $("#neTitleEn").value,
      // Плоский текст и первая картинка считаются ТЕМ ЖЕ кодом, что и на
      // сервере (news_blocks_plain / news_blocks_first_image в
      // api/lib/news_blocks.php): если превью и лента когда-нибудь разойдутся,
      // это будет видно здесь же, а не после публикации.
      body_ru: NB.toPlainText(previewDoc.blocks, "ru"),
      body_en: NB.toPlainText(previewDoc.blocks, "en"),
      image_url: previewFirstImage ? previewFirstImage.url : "",
      image_width: previewFirstImage ? previewFirstImage.w : null,
      image_height: previewFirstImage ? previewFirstImage.h : null,
      // Геометрия блочного поста живёт на блоках; эти колонки для него ничего
      // не значат — cardFor() их и не читает, когда есть body_json.
      image_pct: 100,
      image_align: "center",
      image_wrap: false,
      body_json: previewDoc,
      // Пустая/битая дата не должна ронять превью на "Invalid Date" —
      // берём "сейчас" вместо неё; publish() всё равно проверяет дату
      // отдельно перед отправкой.
      published_at: dateVal ? dayToMs(dateVal) : Date.now(),
    };
  }

  function updatePreview() {
    const box = $("#nePreviewCard");
    // applyLang() зовёт превью при каждой смене языка, в том числе на
    // публичной ленте, где редактора нет.
    if (!box) { return; }
    box.innerHTML = "";
    box.append(NP.cardFor(buildPreviewPost(), false));
  }

  // Единая точка закрытия модалки редактора — обязана освобождать открытый
  // кроп-битмап (closeCropUI), а не только прятать модалку: без этого уход из
  // редактора Escape-ом/крестиком/кликом по фону мимо кнопок «Обрезать»/«Без
  // кадрирования» оставлял бы декодированный ImageBitmap висеть в памяти до
  // следующего выбора файла (или вовсе до перезагрузки страницы).
  function closeEditor() {
    closeCropUI();
    closeSlashMenu();
    $("#neFmt").hidden = true;
    editor.hidden = true;
  }

  function openEditor(post) {
    // На случай, если предыдущая сессия редактирования была прервана не
    // через closeEditor() (не должно происходить, но дешевле подстраховаться,
    // чем держать это инвариантом, который нечем проверить).
    closeCropUI();
    editingPost = post;
    $("#neHeading").textContent = tx(post ? "news.modalEdit" : "news.modalNew");
    $("#neTitleRu").value = post ? post.title_ru : "";
    $("#neTitleEn").value = post ? post.title_en : "";
    $("#neDate").value = isoDay(post ? post.published_at : Date.now());
    setCat(post ? post.category : "tierlist");
    // Новая сессия — пустой источник для заливки. Открытие существующего поста
    // не держит исходные файлы его картинок в памяти браузера: там уже лежат
    // залитые /images/<sha1>, и перезаливать их не из чего.
    pickedImageDataUrl = "";

    // Копия, а не сама структура поста: пока не нажали «Опубликовать», правки
    // не должны быть видны в уже отрисованной под модалкой ленте.
    if (post && post.body_json) {
      const v = NB.validateDoc(post.body_json);
      editorBlocks = v.ok ? JSON.parse(JSON.stringify(v.blocks)) : blocksFromLegacy(post);
    } else if (post) {
      editorBlocks = blocksFromLegacy(post);
    } else {
      editorBlocks = [newBlock("p")];
    }
    editorLang = "ru";
    for (const b of document.querySelectorAll("#neLang [data-v]")) {
      b.classList.toggle("active", b.dataset.v === "ru");
    }

    showError("");
    editor.hidden = false;
    renderBlockList();
    setTimeout(() => $("#neTitleRu").focus(), 30);
  }

  async function publish() {
    const dateVal = $("#neDate").value;
    // Пустая дата уходит в dayToMs("") как NaN → отрицательный timestamp,
    // news_save.php видит published_at <= 0 и молча подставляет "сейчас".
    // На новом посте это безобидно, а вот на правке существующего — тихая
    // порча реальной даты публикации без единого предупреждения. #neDate
    // отмечен required, но модалка — не <form>, кнопка «Опубликовать» не
    // сабмит, так что required без явной проверки браузер не остановил бы
    // ничего — проверяем здесь же, перед запросом.
    if (!dateVal) {
      $("#neError").textContent = tx("news.dateRequired");
      return;
    }
    // Кадратор открыт и не подтверждён — значит картинка ещё НЕ загружена и
    // currentImage пуст. Без этой проверки «Опубликовать» молча сохраняет пост
    // без картинки: человек её выбрал, видит на экране в кадраторе и уверен,
    // что она вставлена. Именно так и терялись фотографии.
    if (cropSrc) {
      $("#neError").textContent = tx("news.cropPending");
      return;
    }
    const doc = currentDoc();
    const check = NB.validateDoc(doc);
    if (!check.ok) { showError(check.error); return; }
    if (NB.toPlainText(doc.blocks, "ru") === "") {
      showError(tx("news.blocksEmpty"));
      return;
    }

    const body = {
      category: getCat(),
      title_ru: $("#neTitleRu").value.trim(),
      title_en: $("#neTitleEn").value.trim(),
      // body_ru/body_en/image_url НЕ отправляются: их выводит из блоков сервер
      // (см. handle_news_save в api/news_save.php). Прислать их значило бы
      // завести второй источник правды о том же тексте.
      body_json: doc,
      published_at: dayToMs(dateVal),
    };
    if (editingPost) { body.id = editingPost.id; }

    try {
      const r = await fetch("/api/news_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const d = await r.json();
      if (!r.ok || !d.ok) {
        // Единственная серверная ошибка, которую стоит перевести: у остальных
        // (bad category, bad id...) фронт и так не даёт им случиться, а эта
        // достижима обычным постом с десятком картинок.
        if (d.error === "body_json too large") { throw new Error(tx("news.blocksTooBig")); }
        throw new Error(d.error || "http " + r.status);
      }
      closeEditor();
      await NP.reload();
    } catch (e) {
      // Модалка остаётся открытой намеренно: потерять набранный текст из-за
      // отвалившейся сети — худшее, что может здесь произойти.
      $("#neError").textContent = tx("news.saveFailed") + " " + e.message;
    }
  }

  async function removePost(post) {
    const picked = NEWS.pickLang(post, LANG());
    if (!confirm(I18N.t("news.confirmDelete", LANG(), { title: picked.title }))) { return; }
    try {
      const r = await fetch("/api/news_delete.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: post.id }),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) { throw new Error(d.error || "http " + r.status); }
      await NP.reload();
    } catch (e) {
      // #neError показывать некуда: удаление запускается с ✕ на карточке
      // в ленте, редактор при этом закрыт, и текст внутри модалки никто
      // не увидит. alert() — тот же приём, что и в app.js для ошибок вне
      // форм (msg.readFailed и т. п.): системное окно видно независимо от
      // того, что сейчас на экране.
      alert(tx("news.deleteFailed") + " " + e.message);
    }
  }

  // Заливает pickedImageDataUrl под текущую выбранную ширину редактора.
  // Общая для первого выбора файла и для перезаливки при смене ширины (см.
  // обработчик "change" на #nePct в wireAdmin ниже) — оба случая шлют один
  // и тот же запрос, отличается только источник в pickedImageDataUrl и
  // повод вызова.
  async function uploadPickedImage() {
    // kind: "news" + pct — потолок стороны по выбранной в редакторе ширине,
    // см. resolve_upload_max_side() в upload.php.
    const r = await fetch("/api/upload.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ data: pickedImageDataUrl, kind: "news", pct: cropTargetPct }),
    });
    const d = await r.json();
    if (r.ok && d.url) {
      showError("");
      return { url: d.url, w: d.width, h: d.height };
    }
    showError(tx("news.saveFailed") + " " + (d.error || ""));
    return null;
  }

  // Кнопка «Добавить» и вся обвязка модалки. Вызывается только на /admin/news:
  // на публичной ленте этих узлов нет, и addEventListener на null бросил бы
  // прямо в теле IIFE — то есть убил бы и загрузку ленты для посетителя.
  function wireAdmin() {
    const bar = $("#newsAdminBar");
    if (bar) {
      bar.hidden = false;
      const add = document.createElement("button");
      add.className = "btn primary";
      add.dataset.i18n = "news.add";
      add.textContent = tx("news.add");
      add.addEventListener("click", () => openEditor(null));
      bar.append(add);
    }
    if (!editor) { return; }

    $("#nePublish").addEventListener("click", publish);
    $("#neCancel").addEventListener("click", closeEditor);
    $("#neClose").addEventListener("click", closeEditor);
    editor.addEventListener("click", e => { if (e.target === editor) { closeEditor(); } });
    document.addEventListener("keydown", e => {
      if (e.key === "Escape" && !editor.hidden) { closeEditor(); }
    });



    // ---- pointer/клавиатурная обвязка кроп-редактора. Живёт здесь, а не на
    // верхнем уровне IIFE, ровно по той же причине, что и остальная обвязка
    // редактора: #neCropStage/#neCropFrame существуют только внутри разметки
    // #newsEditor, которую вставляет admin-news.php — на публичной ленте их
    // нет, и addEventListener на null бросил бы прямо здесь.
    document.addEventListener("pointermove", cropPointerMove);
    document.addEventListener("pointerup", cropPointerUp);
    document.addEventListener("pointercancel", cropPointerUp);

    $("#neCropStage").addEventListener("pointerdown", ev => {
      if (!cropSrc || ev.target.closest("#neCropFrame")) { return; }
      ev.preventDefault();
      ev.target.setPointerCapture(ev.pointerId);
      cropDrag = { kind: "pan", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY, startPan: { ...cropPan } };
    });

    $("#neCropFrame").addEventListener("pointerdown", ev => {
      if (!cropSrc || ev.target.closest(".ne-crop-handle")) { return; }
      ev.preventDefault();
      ev.target.setPointerCapture(ev.pointerId);
      cropDrag = { kind: "move", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY, startFrame: { ...cropFrame } };
    });

    for (const handle of document.querySelectorAll(".ne-crop-handle")) {
      handle.addEventListener("pointerdown", ev => {
        if (!cropSrc) { return; }
        ev.preventDefault();
        ev.stopPropagation();
        ev.target.setPointerCapture(ev.pointerId);
        const corner = handle.dataset.corner;
        const startFrame = { ...cropFrame };
        cropDrag = {
          kind: "resize", pointerId: ev.pointerId, startX: ev.clientX, startY: ev.clientY,
          startFrame, anchor: CROP_ANCHORS[corner](startFrame), startCorner: CROP_CORNER[corner](startFrame),
        };
      });
    }

    $("#neCropStage").addEventListener("wheel", ev => {
      if (!cropSrc) { return; }
      ev.preventDefault();
      const rect = $("#neCropStage").getBoundingClientRect();
      const ax = ev.clientX - rect.left, ay = ev.clientY - rect.top;
      const nextPct = clamp(cropZoom * 100 + (ev.deltaY > 0 ? -10 : 10), 100, 400);
      applyCropZoom(nextPct, ax, ay);
      $("#neCropZoom").value = Math.round(nextPct);
      $("#neCropZoomValue").textContent = Math.round(nextPct) + "%";
    }, { passive: false });

    $("#neCropZoom").addEventListener("input", () => {
      const pct = Number($("#neCropZoom").value) || 100;
      $("#neCropZoomValue").textContent = pct + "%";
      // Якорь — центр холста: ползунком и клавиатурой (в отличие от колеса над
      // конкретной точкой) не целятся в конкретное место картинки.
      applyCropZoom(pct, cropViewport.w / 2, cropViewport.h / 2);
    });

    // Клавиатурный путь для рамки: стрелки двигают, Shift+стрелки меняют
    // размер (растут вправо/вниз, сужаются к тому же углу — простое,
    // предсказуемое правило вместо «какой угол ресайзили последним»).
    // Описание — в news.cropFrameLabel (title/aria-label на самом элементе,
    // задаётся в HTML и обновляется applyLang()).
    $("#neCropFrame").addEventListener("keydown", ev => {
      if (!cropSrc) { return; }
      const step = CROP_KEY_STEP;
      let handled = true;
      if (ev.shiftKey) {
        if (ev.key === "ArrowRight") { cropFrame.w = clamp(cropFrame.w + step, CROP_MIN_FRAME, cropViewport.w - cropFrame.x); }
        else if (ev.key === "ArrowLeft") { cropFrame.w = clamp(cropFrame.w - step, CROP_MIN_FRAME, cropViewport.w - cropFrame.x); }
        else if (ev.key === "ArrowDown") { cropFrame.h = clamp(cropFrame.h + step, CROP_MIN_FRAME, cropViewport.h - cropFrame.y); }
        else if (ev.key === "ArrowUp") { cropFrame.h = clamp(cropFrame.h - step, CROP_MIN_FRAME, cropViewport.h - cropFrame.y); }
        else { handled = false; }
      } else {
        if (ev.key === "ArrowRight") { cropFrame.x = clamp(cropFrame.x + step, 0, cropViewport.w - cropFrame.w); }
        else if (ev.key === "ArrowLeft") { cropFrame.x = clamp(cropFrame.x - step, 0, cropViewport.w - cropFrame.w); }
        else if (ev.key === "ArrowDown") { cropFrame.y = clamp(cropFrame.y + step, 0, cropViewport.h - cropFrame.h); }
        else if (ev.key === "ArrowUp") { cropFrame.y = clamp(cropFrame.y - step, 0, cropViewport.h - cropFrame.h); }
        else { handled = false; }
      }
      if (handled) { ev.preventDefault(); updateCropUI(); }
    });

    $("#neCropConfirm").addEventListener("click", confirmCrop);
    $("#neCropSkip").addEventListener("click", skipCrop);

    // Поворот телефона (или ресайз окна на десктопе) меняет реальный css-размер
    // #neCropStage, пока редактор открыт — пересчитываем буфер холста и зажимаем
    // панораму/рамку под новый viewport, а не оставляем их посчитанными под уже
    // не существующий размер (иначе рамка могла бы оказаться шире холста).
    window.addEventListener("resize", () => {
      if (!cropSrc) { return; }
      setupCropCanvasSize();
      clampPan();
      clampFrameToViewport();
      updateCropUI();
    });

    // Живое превью: заголовок и дата не проходят через отдельные сеттеры
    // вроде setCat, поэтому слушаем input/change прямо на полях. Дебаунс —
    // в schedulePreviewUpdate() на уровне модуля: превью пересобирает всю
    // карточку целиком, и гнать это на каждое нажатие клавиши незачем.
    for (const id of ["neTitleRu", "neTitleEn"]) {
      $("#" + id).addEventListener("input", schedulePreviewUpdate);
    }
    $("#neDate").addEventListener("change", schedulePreviewUpdate);
    $("#neDate").addEventListener("input", schedulePreviewUpdate);

    // Добавление блока. Потолок тот же, что проверяет сервер (NB_LIMIT_BLOCKS
    // в api/lib/news_blocks.php) — кнопка просто перестаёт добавлять, а не
    // даёт собрать пост, который потом отклонят.
    $("#neAddRow").addEventListener("click", ev => {
      const btn = ev.target.closest("[data-add]");
      if (!btn) { return; }
      if (editorBlocks.length >= NB.LIMITS.blocks) { return; }
      editorBlocks.push(newBlock(btn.dataset.add));
      renderBlockList();
    });

    // Переключатель RU/EN. Перерисовка списка целиком, а не точечная: текст
    // всех блоков меняется разом, а порядок и картинки — нет, и это ровно то,
    // что переключатель обещает.
    $("#neLang").addEventListener("click", ev => {
      const btn = ev.target.closest("[data-v]");
      if (!btn) { return; }
      editorLang = btn.dataset.v === "en" ? "en" : "ru";
      for (const b of $("#neLang").querySelectorAll("[data-v]")) {
        b.classList.toggle("active", b.dataset.v === editorLang);
      }
      renderBlockList();
    });

    const blocksBox = $("#neBlocks");
    blocksBox.addEventListener("keydown", onBlocksKeydown);
    blocksBox.addEventListener("keyup", onBlocksKeyup);
    blocksBox.addEventListener("input", onBlocksInput);
    blocksBox.addEventListener("dragstart", onBlocksDragStart);
    blocksBox.addEventListener("dragover", onBlocksDragOver);
    blocksBox.addEventListener("drop", onBlocksDrop);
    // Клик мимо меню типов закрывает его — как любое всплывающее меню.
    document.addEventListener("click", ev => {
      if (!ev.target.closest("#neSlash")) { closeSlashMenu(); }
    });

    // Панель форматирования висит над выделением, пока оно есть и находится
    // внутри блока.
    document.addEventListener("selectionchange", positionFormatBar);
    // Тело модалки прокручивается своим скроллом: без этого панель осталась бы
    // висеть там, где текст был до прокрутки. capture — событие scroll не
    // всплывает.
    document.addEventListener("scroll", positionFormatBar, true);

    $("#neFmt").addEventListener("mousedown", ev => {
      // mousedown, а не click: click сначала снял бы выделение, и форматировать
      // стало бы нечего.
      ev.preventDefault();
      const btn = ev.target.closest("[data-fmt]");
      if (btn) { applyFormat(btn.dataset.fmt); }
    });
  }

  // --------------------- Клавиатура, «/» и markdown на лету ---------------------
  // Поведение списка блоков повторяет телеграм и Notion: там та же мышечная
  // память, которой уже пользуется автор постов.

  function focusBlock(index) {
    const ed = document.querySelector('.ne-block[data-index="' + index + '"] .ne-editable');
    if (!ed) { return; }
    ed.focus();
    const range = document.createRange();
    range.selectNodeContents(ed);
    range.collapse(false);
    const sel = document.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function onBlocksKeydown(ev) {
    const ed = ev.target.closest && ev.target.closest(".ne-editable");
    if (!ed) { return; }
    const bi = Number(ed.dataset.index);

    if ((ev.ctrlKey || ev.metaKey) && !ev.altKey) {
      const map = { b: "b", i: "i", u: "u", k: "a" };
      const kind = map[ev.key.toLowerCase()];
      if (kind) { ev.preventDefault(); applyFormat(kind); return; }
    }

    if (ev.key === "Enter" && !ev.shiftKey) {
      // Enter — новый блок, а не <br> внутри текущего: пост состоит из блоков,
      // и «пустая строка внутри абзаца» в этой модели не существует.
      // Shift+Enter остаётся браузеру — это перенос строки внутри блока.
      ev.preventDefault();
      editorBlocks.splice(bi + 1, 0, newBlock("p"));
      renderBlockList();
      focusBlock(bi + 1);
      return;
    }

    if (ev.key === "Backspace" && ed.textContent === "" && bi > 0) {
      // Пустой блок склеивается с предыдущим — то же, что делают телеграм и
      // Notion. Непустой не трогаем: удалять его — работа крестика, иначе
      // бэкспейс в начале абзаца сносил бы набранный текст.
      ev.preventDefault();
      editorBlocks.splice(bi, 1);
      renderBlockList();
      focusBlock(bi - 1);
    }
  }

  // «/» в пустом блоке открывает список типов — тот же набор, что в кнопках
  // #neAddRow, просто под рукой, без переноса взгляда вниз формы.
  const SLASH_TYPES = [
    ["p", "news.blockP"], ["quote", "news.blockQuote"], ["list", "news.blockList"],
    ["code", "news.blockCode"], ["image", "news.blockImage"], ["album", "news.blockAlbum"]
  ];

  function closeSlashMenu() {
    const old = document.querySelector("#neSlash");
    if (old) { old.remove(); }
  }

  function openSlashMenu(ed, index) {
    closeSlashMenu();
    const menu = document.createElement("div");
    menu.className = "ne-slash";
    menu.id = "neSlash";
    for (const [type, key] of SLASH_TYPES) {
      const b = document.createElement("button");
      b.type = "button";
      b.textContent = tx(key);
      // mousedown, а не click: click сначала увёл бы фокус из блока.
      b.addEventListener("mousedown", mev => {
        mev.preventDefault();
        editorBlocks[index] = newBlock(type);
        closeSlashMenu();
        renderBlockList();
        focusBlock(index);
      });
      menu.append(b);
    }
    const rect = ed.getBoundingClientRect();
    // fixed, по тем же соображениям, что и панель форматирования: модалка
    // position: fixed, и абсолютные координаты документа уехали бы мимо.
    menu.style.left = Math.round(rect.left) + "px";
    menu.style.top = Math.round(rect.bottom + 4) + "px";
    editor.append(menu);
  }

  function onBlocksKeyup(ev) {
    const ed = ev.target.closest && ev.target.closest(".ne-editable");
    if (!ed || ev.key !== "/") { return; }
    // Только в пустом блоке: «/» посреди текста — обычный слэш.
    if (ed.textContent !== "/") { return; }
    openSlashMenu(ed, Number(ed.dataset.index));
  }

  // Разметка на лету: закрывающий символ превращает пару в форматирование, как
  // в телеграме. Работает только по простому тексту прямо перед кареткой —
  // никакого разбора всего блока, иначе правка середины абзаца ломала бы уже
  // расставленное форматирование.
  const MD_RULES = [
    { re: /\*\*([^*]+)\*\*$/, fmt: "b" },
    { re: /__([^_]+)__$/,       fmt: "u" },
    { re: /~~([^~]+)~~$/,       fmt: "st" },
    { re: /\|\|([^|]+)\|\|$/,   fmt: "sp" },
    { re: /`([^`]+)`$/,         fmt: "c" }
  ];

  function onBlocksInput(ev) {
    const ed = ev.target.closest && ev.target.closest(".ne-editable");
    if (!ed) { return; }
    const sel = document.getSelection();
    if (!sel.rangeCount || !sel.isCollapsed) { return; }
    const node = sel.anchorNode;
    if (!node || node.nodeType !== 3) { return; }
    const upto = node.nodeValue.slice(0, sel.anchorOffset);

    for (const rule of MD_RULES) {
      const m = rule.re.exec(upto);
      if (!m) { continue; }
      const start = sel.anchorOffset - m[0].length;
      const range = document.createRange();
      range.setStart(node, start);
      range.setEnd(node, sel.anchorOffset);
      range.deleteContents();

      // Текст без служебных символов кладётся обратно и выделяется — дальше
      // за форматирование отвечает тот же applyFormat, что и кнопки панели.
      const text = document.createTextNode(m[1]);
      range.insertNode(text);
      const r2 = document.createRange();
      r2.selectNodeContents(text);
      sel.removeAllRanges();
      sel.addRange(r2);
      applyFormat(rule.fmt, true);
      break;
    }
  }

  // ------------------------ Перетаскивание блоков ------------------------
  // Тянут за ручку .ne-grip: она включает draggable на строке в pointerdown и
  // выключает на dragend (см. blockRow), иначе текст внутри блока нельзя было
  // бы выделить мышью.
  let dragFrom = -1;

  function onBlocksDragStart(ev) {
    const row = ev.target.closest(".ne-block");
    if (!row) { return; }
    dragFrom = Number(row.dataset.index);
    ev.dataTransfer.effectAllowed = "move";
    // Firefox не начинает перетаскивание без установленных данных.
    ev.dataTransfer.setData("text/plain", String(dragFrom));
  }

  function onBlocksDragOver(ev) {
    const row = ev.target.closest(".ne-block");
    if (!row || dragFrom < 0) { return; }
    ev.preventDefault();
    for (const r of $("#neBlocks").children) { r.classList.remove("is-drop-target"); }
    row.classList.add("is-drop-target");
  }

  function onBlocksDrop(ev) {
    const row = ev.target.closest(".ne-block");
    if (!row || dragFrom < 0) { return; }
    ev.preventDefault();
    const to = Number(row.dataset.index);
    const moved = editorBlocks.splice(dragFrom, 1)[0];
    editorBlocks.splice(to, 0, moved);
    dragFrom = -1;
    renderBlockList();
  }

  function positionFormatBar() {
    const fmt = $("#neFmt");
    const sel = document.getSelection();
    if (!sel || !sel.rangeCount || sel.isCollapsed || !activeEditable()) {
      fmt.hidden = true;
      return;
    }
    const rect = sel.getRangeAt(0).getBoundingClientRect();
    fmt.hidden = false;
    // Координаты окна, без прибавки прокрутки: панель position: fixed, как и
    // сама модалка, и считает от того же вьюпорта. Прижимается к левому краю
    // экрана, если выделение у самой кромки.
    fmt.style.left = Math.round(Math.max(4, rect.left)) + "px";
    fmt.style.top = Math.round(Math.max(4, rect.top - fmt.offsetHeight - 6)) + "px";
  }
  // Превью обязано перерисоваться при смене языка: applyLang() в news-page.js
  // зовёт этот хук после того, как обновит саму ленту. Раньше он вызывал
  // updatePreview() напрямую — теперь этой функции у него нет.
  NP.onLang(updatePreview);
  // ✎ и ✕ на карточке рисует cardFor() в news-page.js, но открывать редактор
  // и удалять пост умеет только этот файл — он и отдаёт туда обработчики.
  NP.onEdit(openEditor);
  NP.onDelete(removePost);

  document.body.classList.add("nw-editing");
  wireAdmin();
})();
