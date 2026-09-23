
(() => {
  "use strict";

  const NP = window.NEWSPAGE;

  if (!NP || !NP.isAdmin() || !document.querySelector("#newsEditor")) { return; }

  const $ = sel => document.querySelector(sel);

  const LANG = () => NP.getLang();
  const tx = key => I18N.t(key, LANG());

  const NB = window.NEWSBLOCKS;

  function showError(msg) { $("#neError").textContent = msg || ""; }

  let editorBlocks = [];

  let editorLang = "ru";

  function newBlock(type) {
    if (type === "p")     { return { t: "p", ru: [], en: [] }; }
    if (type === "quote") { return { t: "quote", ru: [], en: [], collapsible: false }; }
    if (type === "code")  { return { t: "code", ru: "", en: "" }; }
    if (type === "list")  { return { t: "list", ordered: false, items: [{ ru: [], en: [] }] }; }
    if (type === "image") { return { t: "image", url: "", w: 0, h: 0, pct: 100, align: "center", wrap: false, cap_ru: [], cap_en: [] }; }
    return { t: "album", items: [], cap_ru: [], cap_en: [] };
  }

  function spansToEditable(spans) {
    const frag = document.createDocumentFragment();
    for (const sp of spans) {
      let node = NB.textWithBreaks(document, sp.s);
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

  const ZWSP = "​";

  function sameFormat(a, b) {
    for (const k of ["b", "i", "u", "st", "c", "sp", "href"]) {
      if ((a[k] || false) !== (b[k] || false)) { return false; }
    }
    return true;
  }

  function blockToSpans(root) {
    const out = [];
    const push = (text, state) => {
      const sp = { s: text };
      for (const k in state) { if (state[k]) { sp[k] = state[k]; } }
      const prev = out[out.length - 1];
      if (prev && sameFormat(prev, sp)) { prev.s += sp.s; } else { out.push(sp); }
    };
    const atLineStart = () => !out.length || out[out.length - 1].s.endsWith("\n");
    const walk = (node, state) => {
      if (node.nodeType === 3) {
        const text = node.nodeValue.split(ZWSP).join("");
        if (text === "") { return; }
        push(text, state);
        return;
      }
      if (node.nodeType !== 1) { return; }
      if (node.tagName === "BR") { push("\n", state); return; }
      const isLine = node.tagName === "DIV" || node.tagName === "P";
      if (isLine && !atLineStart()) { push("\n", {}); }
      const next = Object.assign({}, state);
      const flag = TAG_FLAG[node.tagName];
      if (flag) { next[flag] = true; }
      if (node.tagName === "SPAN" && node.classList.contains("nw-spoiler")) { next.sp = true; }
      if (node.tagName === "A") {
        const href = node.getAttribute("href") || "";

        if (NB.isSafeHref(href)) { next.href = href; }
      }
      for (const kid of Array.from(node.childNodes)) { walk(kid, next); }
      if (isLine && !atLineStart()) { push("\n", {}); }
    };
    for (const kid of Array.from(root.childNodes)) { walk(kid, {}); }
    return trimBreaks(out, false);
  }

  function trimBreaks(spans, leading) {
    const out = spans.map(sp => Object.assign({}, sp));
    if (leading) {
      while (out.length) {
        out[0].s = out[0].s.replace(/^\n+/, "");
        if (out[0].s !== "") { break; }
        out.shift();
      }
    }
    while (out.length) {
      const last = out[out.length - 1];
      last.s = last.s.replace(/\n+$/, "");
      if (last.s !== "") { break; }
      out.pop();
    }
    return out;
  }

  function joinSpans(...lists) {
    const out = [];
    for (const list of lists) {
      for (const sp of list) {
        if (!sp || sp.s === "") { continue; }
        const prev = out[out.length - 1];
        if (prev && sameFormat(prev, sp)) { prev.s += sp.s; } else { out.push(Object.assign({}, sp)); }
      }
    }
    return out;
  }

  const plainSpans = s => (s === "" ? [] : [{ s: s }]);

  const spansLength = spans => spans.reduce((n, sp) => n + sp.s.length, 0);

  function cutAtCaret(ed) {
    const sel = document.getSelection();
    if (!sel || !sel.rangeCount) { return null; }
    const range = sel.getRangeAt(0);
    if (!ed.contains(range.startContainer) || !ed.contains(range.endContainer)) { return null; }
    range.deleteContents();
    const rest = document.createRange();
    rest.setStart(range.startContainer, range.startOffset);
    rest.setEnd(ed, ed.childNodes.length);
    const box = document.createElement("div");
    box.append(rest.extractContents());
    return { head: blockToSpans(ed), tail: trimBreaks(blockToSpans(box), true) };
  }

  function pastedLines(text) {
    const lines = text.split("\n").map(l => l.replace(/\s+$/, ""));
    while (lines.length && lines[0].trim() === "") { lines.shift(); }
    while (lines.length && lines[lines.length - 1].trim() === "") { lines.pop(); }
    return lines.join("\n");
  }

  function pasteMultiline(ed, text) {
    const bi = Number(ed.dataset.index);
    const block = editorBlocks[bi];
    const holder = ed._holder;
    const cut = cutAtCaret(ed);
    if (!block || !holder || !cut) { return; }

    let focus = { index: bi, nth: 0, offset: 0 };

    if (holder === block && block.t === "p") {
      const paras = NB.pasteParagraphs(text);
      const room = Math.max(0, NB.LIMITS.blocks - editorBlocks.length);
      if (paras.length > room + 1) {
        paras.splice(room, paras.length - room, paras.slice(room).filter(p => p !== NB.SPACER).join("\n\n"));
      }
      if (paras.length <= 1) {
        const mid = plainSpans(paras[0] || "");
        holder[editorLang] = joinSpans(cut.head, mid, cut.tail);
        focus.offset = spansLength(cut.head) + spansLength(mid);
      } else {
        holder[editorLang] = joinSpans(cut.head, plainSpans(paras[0]));
        const added = paras.slice(1).map((para, i, rest) => {
          const nb = newBlock("p");
          nb[editorLang] = i === rest.length - 1 ? joinSpans(plainSpans(para), cut.tail) : plainSpans(para);
          return nb;
        });
        editorBlocks.splice(bi + 1, 0, ...added);
        focus = { index: bi + added.length, nth: 0, offset: paras[paras.length - 1].length };
      }
    } else if (block.t === "list" && holder !== block) {
      const ii = block.items.indexOf(holder);
      const items = NB.pasteListItems(text);
      const room = Math.max(0, NB.LIMITS.listItems - block.items.length);
      if (items.length > room + 1) { items.splice(room, items.length - room, items.slice(room).join(" ")); }
      if (items.length <= 1) {
        const mid = plainSpans(items[0] || "");
        holder[editorLang] = joinSpans(cut.head, mid, cut.tail);
        focus = { index: bi, nth: ii, offset: spansLength(cut.head) + spansLength(mid) };
      } else {
        holder[editorLang] = joinSpans(cut.head, plainSpans(items[0]));
        const added = items.slice(1).map((line, i, rest) => {
          const it = { ru: [], en: [] };
          it[editorLang] = i === rest.length - 1 ? joinSpans(plainSpans(line), cut.tail) : plainSpans(line);
          return it;
        });
        block.items.splice(ii + 1, 0, ...added);
        focus = { index: bi, nth: ii + added.length, offset: items[items.length - 1].length };
      }
    } else {
      const mid = plainSpans(pastedLines(text));
      holder[editorLang] = joinSpans(cut.head, mid, cut.tail);
      const nth = Array.from(document.querySelectorAll('.ne-block[data-index="' + bi + '"] .ne-editable')).indexOf(ed);
      focus = { index: bi, nth: Math.max(0, nth), offset: spansLength(cut.head) + spansLength(mid) };
    }

    renderBlockList();
    focusAt(focus.index, focus.offset, focus.nth);
  }

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

  let previewDebounceTimer = null;
  function schedulePreviewUpdate() {
    clearTimeout(previewDebounceTimer);
    previewDebounceTimer = setTimeout(updatePreview, 150);
  }

  function renderBlockList() {
    const box = $("#neBlocks");
    box.innerHTML = "";
    editorBlocks.forEach((b, i) => box.append(blockRow(b, i)));

    $("#neBlockCount").textContent = I18N.t("news.blockCount", LANG(),
      { n: editorBlocks.length, max: NB.LIMITS.blocks });
    updatePreview();
  }

  function blockRow(b, index) {
    const row = document.createElement("div");
    row.className = "ne-block ne-block-" + b.t;
    row.dataset.index = String(index);

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

    ed._holder = holder;
    ed.append(spansToEditable(holder[editorLang] || []));

    ed.addEventListener("paste", ev => {
      ev.preventDefault();
      const text = ((ev.clipboardData || window.clipboardData).getData("text/plain") || "").replace(/\r\n?/g, "\n");
      if (text.indexOf("\n") === -1) {
        document.execCommand("insertText", false, text);
        return;
      }
      pasteMultiline(ed, text);
    });
    ed.addEventListener("input", () => {
      holder[editorLang] = blockToSpans(ed);
      schedulePreviewUpdate();
    });
    return ed;
  }

  let cropResolve = null;

  let cropTargetPct = 100;

  function settleCrop(result) {
    if (cropResolve) { cropResolve(result); cropResolve = null; }
  }

  function pickImage(file, pct) {
    cropTargetPct = pct;
    return new Promise(resolve => {
      cropResolve = resolve;
      startCropFor(file);
    });
  }

  async function startCropFor(file) {
    if (cropSrc) { closeCropUI(); }

    const originalDataUrl = await readFileAsDataURL(file);
    const decoded = await decodeImageForCrop(file).catch(() => null);

    if (!decoded) {
      pickedImageDataUrl = originalDataUrl;
      settleCrop(await uploadPickedImage());
      return;
    }

    cropSrc = decoded;
    cropOriginalDataUrl = originalDataUrl;

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

    const geom = b.t === "image" ? imageGeometryControls(b) : null;

    drawThumbs();
    if (geom) { wrap.append(geom.el); }

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

  const EXEC = { b: "bold", i: "italic", u: "underline", st: "strikeThrough" };

  function applyFormat(kind, fromMarkdown) {
    if (EXEC[kind] && !fromMarkdown) {
      document.execCommand(EXEC[kind], false, null);
    } else if (FMT_TAG[kind]) {
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

  function wrapSelection(fmt) {
    const sel = document.getSelection();
    if (!sel.rangeCount || sel.isCollapsed) { return; }
    const [tag, cls] = FMT_TAG[fmt];
    const range = sel.getRangeAt(0);
    const el = document.createElement(tag);
    if (cls) { el.className = cls; }
    el.append(range.extractContents());
    range.insertNode(el);

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

  function syncActiveEditable() {
    const ed = activeEditable();
    if (!ed || !ed._holder) { return; }
    ed._holder[editorLang] = blockToSpans(ed);
    schedulePreviewUpdate();
  }

  const editor = $("#newsEditor");
  let editingPost = null;

  let pickedImageDataUrl = "";

  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);

  let cropSrc = null;
  let cropOriginalDataUrl = "";
  let cropOutputMime = "image/png";
  let cropViewport = { w: 0, h: 0 };
  let cropZoomFit = 1;
  let cropZoom = 1;
  let cropPan = { x: 0, y: 0 };
  let cropFrame = { x: 0, y: 0, w: 0, h: 0 };
  let cropCtx = null;
  let cropDrag = null;

  const CROP_MIN_FRAME = 32;
  const CROP_KEY_STEP = 5;

  function cropEffectiveZoom() { return cropZoomFit * cropZoom; }

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

  const isoDay = ms => {
    const d = new Date(ms);
    const p = n => String(n).padStart(2, "0");
    return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate());
  };
  const dayToMs = value => {
    const [y, m, d] = String(value).split("-").map(Number);
    return new Date(y, (m || 1) - 1, d || 1, 12, 0, 0).getTime();
  };

  function readFileAsDataURL(file) {
    return new Promise(res => {
      const fr = new FileReader();
      fr.onload = () => res(fr.result);
      fr.readAsDataURL(file);
    });
  }

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

  function clampPan() {
    if (!cropSrc) { return; }
    const eff = cropEffectiveZoom();
    const iw = cropSrc.width * eff, ih = cropSrc.height * eff;
    const vw = cropViewport.w, vh = cropViewport.h;
    cropPan.x = clamp(cropPan.x, Math.min(0, vw - iw), Math.max(0, vw - iw));
    cropPan.y = clamp(cropPan.y, Math.min(0, vh - ih), Math.max(0, vh - ih));
  }

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

    const half = 8;
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

  function updateCropUI() {
    drawCrop();
    positionCropFrameEl();
    updateCropDimsOutput();
  }

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

  async function confirmCrop() {
    if (!cropSrc) { return; }
    const rect = NEWS.cropToSourceRect(cropFrame, cropEffectiveZoom(), cropPan, { width: cropSrc.width, height: cropSrc.height });
    const sx = Math.round(rect.sx), sy = Math.round(rect.sy);
    const sw = Math.max(1, Math.round(rect.sw)), sh = Math.max(1, Math.round(rect.sh));

    const cap = NEWS.newsImageCap(cropTargetPct);
    const longestSide = Math.max(sw, sh);
    const scale = longestSide > cap ? cap / longestSide : 1;
    const dw = Math.max(1, Math.round(sw * scale));
    const dh = Math.max(1, Math.round(sh * scale));

    const out = document.createElement("canvas");
    out.width = dw;
    out.height = dh;
    const octx = out.getContext("2d");

    octx.drawImage(cropSrc.source, sx, sy, sw, sh, 0, 0, dw, dh);

    const quality = cropOutputMime === "image/jpeg" ? 0.9 : undefined;
    const dataUrl = out.toDataURL(cropOutputMime, quality);

    closeCropUI(false);
    pickedImageDataUrl = dataUrl;
    settleCrop(await uploadPickedImage());
  }

  async function skipCrop() {
    const original = cropOriginalDataUrl;
    closeCropUI(false);
    pickedImageDataUrl = original;
    settleCrop(await uploadPickedImage());
  }

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

  function buildPreviewPost() {
    const dateVal = $("#neDate").value;
    const previewDoc = currentDoc();
    const previewFirstImage = NB.firstImage(previewDoc.blocks);
    return {
      id: editingPost ? editingPost.id : 0,
      category: getCat(),
      title_ru: $("#neTitleRu").value,
      title_en: $("#neTitleEn").value,

      body_ru: NB.toPlainText(previewDoc.blocks, "ru"),
      body_en: NB.toPlainText(previewDoc.blocks, "en"),
      image_url: previewFirstImage ? previewFirstImage.url : "",
      image_width: previewFirstImage ? previewFirstImage.w : null,
      image_height: previewFirstImage ? previewFirstImage.h : null,

      image_pct: 100,
      image_align: "center",
      image_wrap: false,
      body_json: previewDoc,

      published_at: dateVal ? dayToMs(dateVal) : Date.now(),
    };
  }

  const PREVIEW_SIZES = {
    desktop: { viewport: 1443, card: 810 },
    phone:   { viewport: 366,  card: 366 }
  };
  const PREVIEW_MODE_KEY = "nexus-news-preview-v1";

  let previewMode = "desktop";
  try {
    if (localStorage.getItem(PREVIEW_MODE_KEY) === "phone") { previewMode = "phone"; }
  } catch (e) {}

  let previewFrame = null;
  let previewClip = null;
  let previewReady = false;
  let previewCard = null;

  function previewDocHtml() {
    const esc = s => String(s).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;");
    const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map(l => l.href)
      .filter(href => href && !/admin-shell|news-editor/.test(href))
      .map(href => '<link rel="stylesheet" href="' + esc(href) + '">')
      .join("");
    return '<!doctype html><html lang="' + esc(document.documentElement.lang || "ru") + '"><head><meta charset="utf-8">'
      + links
      + '<style>html,body{margin:0}body{overflow:hidden}.nw-lead{min-height:0;padding:0}.nw-feed{margin:0}</style>'
      + '</head><body class="news-bg"><main class="nw-page"><div class="nw-lead"><div class="nw-feed"></div></div></main></body></html>';
  }

  function ensurePreviewFrame(box) {
    if (previewFrame) { return; }
    previewClip = document.createElement("div");
    previewClip.className = "ne-preview-clip";
    previewFrame = document.createElement("iframe");
    previewFrame.className = "ne-preview-frame";
    previewFrame.title = tx("news.previewHeading");
    previewFrame.addEventListener("load", () => {
      const doc = previewFrame.contentDocument;
      if (!doc || !doc.querySelector(".nw-feed")) { return; }
      previewReady = true;
      doc.addEventListener("click", ev => {
        const el = ev.target.closest && ev.target.closest(".nw-spoiler, .nw-quote-collapsible");
        if (el) { el.classList.add("is-open"); }
      });
      new previewFrame.contentWindow.ResizeObserver(layoutPreview).observe(doc.querySelector(".nw-feed"));
      showPreviewCard();
    });
    previewFrame.srcdoc = previewDocHtml();
    previewClip.append(previewFrame);
    box.append(previewClip);
    new ResizeObserver(layoutPreview).observe(box);
  }

  function showPreviewCard() {
    if (!previewReady || !previewCard) { return; }
    const doc = previewFrame.contentDocument;
    const card = doc.importNode(previewCard, true);
    for (const img of card.querySelectorAll("img")) { img.loading = "eager"; }
    doc.querySelector(".nw-feed").replaceChildren(card);
    layoutPreview();
  }

  function layoutPreview() {
    if (!previewReady) { return; }
    const box = $("#nePreviewCard");
    const size = PREVIEW_SIZES[previewMode];
    const cs = getComputedStyle(box);
    const room = box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
    const scale = room > 0 ? Math.min(1, room / size.card) : 1;
    previewFrame.style.width = size.viewport + "px";
    const feed = previewFrame.contentDocument.querySelector(".nw-feed");
    const height = Math.ceil(feed.getBoundingClientRect().bottom);
    previewFrame.style.height = height + "px";
    previewFrame.style.transform = scale < 1 ? "scale(" + scale + ")" : "";
    previewClip.style.width = Math.floor(size.card * scale) + "px";
    previewClip.style.height = Math.ceil(height * scale) + "px";
  }

  function setPreviewMode(mode) {
    previewMode = mode === "phone" ? "phone" : "desktop";
    try { localStorage.setItem(PREVIEW_MODE_KEY, previewMode); } catch (e) {}
    for (const b of document.querySelectorAll("#nePreviewMode [data-v]")) {
      b.classList.toggle("active", b.dataset.v === previewMode);
    }
    layoutPreview();
  }

  function updatePreview() {
    const box = $("#nePreviewCard");

    if (!box) { return; }
    ensurePreviewFrame(box);
    previewCard = NP.cardFor(buildPreviewPost(), false);
    showPreviewCard();
  }

  function closeEditor() {
    closeCropUI();
    closeSlashMenu();
    $("#neFmt").hidden = true;
    editor.hidden = true;
  }

  function openEditor(post) {
    closeCropUI();
    editingPost = post;
    $("#neHeading").textContent = tx(post ? "news.modalEdit" : "news.modalNew");
    $("#neTitleRu").value = post ? post.title_ru : "";
    $("#neTitleEn").value = post ? post.title_en : "";
    $("#neDate").value = isoDay(post ? post.published_at : Date.now());
    setCat(post ? post.category : "tierlist");

    pickedImageDataUrl = "";

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

    if (!dateVal) {
      $("#neError").textContent = tx("news.dateRequired");
      return;
    }

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

      body_json: doc,
      published_at: dayToMs(dateVal),
    };
    if (editingPost) { body.id = editingPost.id; }

    const btn = $("#nePublish");
    btn.disabled = true;
    btn.textContent = tx("news.publishing");
    try {
      const r = await fetch("/api/news_save.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });
      const d = await r.json();
      if (!r.ok || !d.ok) {
        if (d.error === "body_json too large") { throw new Error(tx("news.blocksTooBig")); }
        throw new Error(d.error || "http " + r.status);
      }
      closeEditor();
      await NP.reload();
      if (d.translate_failed > 0) { alert(tx("news.translateFailed")); }
    } catch (e) {
      $("#neError").textContent = tx("news.saveFailed") + " " + e.message;
    } finally {
      btn.disabled = false;
      btn.textContent = tx("news.publish");
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
      alert(tx("news.deleteFailed") + " " + e.message);
    }
  }

  async function uploadPickedImage() {
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

      applyCropZoom(pct, cropViewport.w / 2, cropViewport.h / 2);
    });

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

    window.addEventListener("resize", () => {
      if (!cropSrc) { return; }
      setupCropCanvasSize();
      clampPan();
      clampFrameToViewport();
      updateCropUI();
    });

    for (const id of ["neTitleRu", "neTitleEn"]) {
      $("#" + id).addEventListener("input", schedulePreviewUpdate);
    }
    $("#neDate").addEventListener("change", schedulePreviewUpdate);
    $("#neDate").addEventListener("input", schedulePreviewUpdate);

    $("#neAddRow").addEventListener("click", ev => {
      const btn = ev.target.closest("[data-add]");
      if (!btn) { return; }
      if (editorBlocks.length >= NB.LIMITS.blocks) { return; }
      editorBlocks.push(newBlock(btn.dataset.add));
      renderBlockList();
    });

    $("#neLang").addEventListener("click", ev => {
      const btn = ev.target.closest("[data-v]");
      if (!btn) { return; }
      editorLang = btn.dataset.v === "en" ? "en" : "ru";
      for (const b of $("#neLang").querySelectorAll("[data-v]")) {
        b.classList.toggle("active", b.dataset.v === editorLang);
      }
      renderBlockList();
    });

    const previewSeg = $("#nePreviewMode");
    if (previewSeg) {
      previewSeg.addEventListener("click", ev => {
        const btn = ev.target.closest("[data-v]");
        if (btn) { setPreviewMode(btn.dataset.v); }
      });
      setPreviewMode(previewMode);
    }

    const blocksBox = $("#neBlocks");
    blocksBox.addEventListener("keydown", onBlocksKeydown);
    blocksBox.addEventListener("keyup", onBlocksKeyup);
    blocksBox.addEventListener("input", onBlocksInput);
    blocksBox.addEventListener("dragstart", onBlocksDragStart);
    blocksBox.addEventListener("dragover", onBlocksDragOver);
    blocksBox.addEventListener("drop", onBlocksDrop);

    document.addEventListener("click", ev => {
      if (!ev.target.closest("#neSlash")) { closeSlashMenu(); }
    });

    document.addEventListener("selectionchange", positionFormatBar);

    document.addEventListener("scroll", positionFormatBar, true);

    $("#neFmt").addEventListener("mousedown", ev => {
      ev.preventDefault();
      const btn = ev.target.closest("[data-fmt]");
      if (btn) { applyFormat(btn.dataset.fmt); }
    });
  }

  function focusBlock(index) {
    focusAt(index, Infinity, 0);
  }

  function focusAt(index, offset, nth) {
    const ed = document.querySelectorAll('.ne-block[data-index="' + index + '"] .ne-editable')[nth || 0];
    if (!ed) { return; }
    ed.focus();
    const range = document.createRange();
    let left = offset;
    let placed = false;
    const walk = node => {
      if (node.nodeType === 3) {
        if (left <= node.nodeValue.length) { range.setStart(node, left); placed = true; return; }
        left -= node.nodeValue.length;
        return;
      }
      if (node.nodeName === "BR") {
        if (left === 0) { range.setStartBefore(node); placed = true; return; }
        left -= 1;
        return;
      }
      for (const kid of Array.from(node.childNodes)) {
        walk(kid);
        if (placed) { return; }
      }
    };
    walk(ed);
    if (placed) {
      range.collapse(true);
    } else {
      range.selectNodeContents(ed);
      range.collapse(false);
    }
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
      ev.preventDefault();
      const block = editorBlocks[bi];
      const splittable = block && ed._holder === block && (block.t === "p" || block.t === "quote");
      const cut = splittable ? cutAtCaret(ed) : null;
      if (cut) {
        block[editorLang] = cut.head;
        const next = newBlock(cut.tail.length ? block.t : "p");
        if (next.t === "quote") { next.collapsible = block.collapsible; }
        next[editorLang] = cut.tail;
        editorBlocks.splice(bi + 1, 0, next);
        renderBlockList();
        focusAt(bi + 1, 0, 0);
        return;
      }
      editorBlocks.splice(bi + 1, 0, newBlock("p"));
      renderBlockList();
      focusBlock(bi + 1);
      return;
    }

    if (ev.key === "Backspace" && ed.textContent === "" && bi > 0) {
      ev.preventDefault();
      editorBlocks.splice(bi, 1);
      renderBlockList();
      focusBlock(bi - 1);
    }
  }

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

    menu.style.left = Math.round(rect.left) + "px";
    menu.style.top = Math.round(rect.bottom + 4) + "px";
    editor.append(menu);
  }

  function onBlocksKeyup(ev) {
    const ed = ev.target.closest && ev.target.closest(".ne-editable");
    if (!ed || ev.key !== "/") { return; }

    if (ed.textContent !== "/") { return; }
    openSlashMenu(ed, Number(ed.dataset.index));
  }

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

  let dragFrom = -1;

  function onBlocksDragStart(ev) {
    const row = ev.target.closest(".ne-block");
    if (!row) { return; }
    dragFrom = Number(row.dataset.index);
    ev.dataTransfer.effectAllowed = "move";

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

    fmt.style.left = Math.round(Math.max(4, rect.left)) + "px";
    fmt.style.top = Math.round(Math.max(4, rect.top - fmt.offsetHeight - 6)) + "px";
  }

  NP.onLang(updatePreview);

  NP.onEdit(openEditor);
  NP.onDelete(removePost);

  document.body.classList.add("nw-editing");
  wireAdmin();
})();
