(() => {
  "use strict";

  const API = "/api/hunt_admin.php";
  const TIERLIST = "/api/tierlist.php";
  const MSK = 3 * 3600;
  const DAY = 86400;

  const $ = id => document.getElementById(id);

  const PHASES = {
    draft: "Черновик",
    soon: "Опубликован, ещё не начался",
    open: "Идёт",
    sales: "Семь дней прошли — продаются билеты",
    closed: "Продажа закрыта — можно разыгрывать",
    drawn: "Разыгран"
  };

  const ERRORS = {
    not_ready: "Таблиц нет — выполните docs/migrations/2026-10-30-hunt.sql.",
    bad_text: "Слишком длинный текст.",
    bad_start: "Укажите первый день ивента.",
    bad_rewards: "Награды — целые числа от 0 до 10000.",
    bad_claim: "Дней на подтверждение — от 1 до 60.",
    bad_status: "Неизвестный статус.",
    bad_sales: "Продажа билетов не может закончиться раньше конца семи дней.",
    bad_draw: "Итоги не могут быть раньше конца продажи билетов.",
    bad_prizes: "Призы: от 1 до 10, у каждого название и количество от 1 до 100.",
    bad_titles: "У обоих титулов нужен текст на двух языках (до 40 знаков).",
    locked: "Ивент уже идёт: дату начала и награды менять нельзя, снять в черновик тоже.",
    drawn: "Сезон уже разыгран.",
    not_ended: "Продажа билетов ещё идёт — разыграть можно после неё.",
    bad_move: "Такой переход статуса нельзя.",
    not_expired: "Срок подтверждения ещё не вышел.",
    none_left: "Очередь итогов кончилась — отдать приз некому.",
    bad: "Не найдено.",
    server: "Ошибка сервера."
  };

  const WIN = {
    won: "Выиграл, ждём подтверждения",
    confirmed: "Подтвердил",
    queued: "Ждёт выдачи",
    given: "Выдан",
    expired: "Не подтвердил вовремя",
    redrawn: "Отдан следующему"
  };

  const MOVES = {
    won: [["confirmed", "Подтвердил"], ["expired", "Просрочен"]],
    confirmed: [["queued", "В очередь на выдачу"], ["expired", "Просрочен"]],
    queued: [["given", "Выдан"]]
  };

  let state = null;
  let catalog = [];
  let busy = false;

  function el(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = String(text);
    return n;
  }

  function post(body) {
    return fetch(API, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(r => r.json().catch(() => ({ ok: false, error: "server" })));
  }

  function alertMsg(text, ok) {
    const a = $("haAlert");
    a.hidden = !text;
    a.textContent = text || "";
    a.classList.toggle("ok", !!ok);
  }

  function errText(d) {
    const code = (d && (d.status || d.error)) || "server";
    return ERRORS[code] || ("Ошибка: " + code);
  }

  function pad(n) { return (n < 10 ? "0" : "") + n; }

  function mskParts(ts) {
    const d = new Date((ts + MSK) * 1000);
    return { y: d.getUTCFullYear(), m: pad(d.getUTCMonth() + 1), d: pad(d.getUTCDate()), h: pad(d.getUTCHours()), i: pad(d.getUTCMinutes()) };
  }

  function toLocal(ts) {
    if (!ts) return "";
    const p = mskParts(ts);
    return p.y + "-" + p.m + "-" + p.d + "T" + p.h + ":" + p.i;
  }

  function fromLocal(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(v || "");
    if (!m) return 0;
    return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) / 1000 - MSK;
  }

  function dateTs(v) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || "");
    return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) / 1000 - MSK : 0;
  }

  function fmt(ts) {
    if (!ts) return "—";
    const p = mskParts(ts);
    return p.d + "." + p.m + "." + p.y + " " + p.h + ":" + p.i + " МСК";
  }

  function fmtDay(ts) {
    const p = mskParts(ts);
    return p.d + "." + p.m;
  }

  function intsBox(box, labels, values) {
    box.textContent = "";
    labels.forEach((label, i) => {
      const l = el("label", "", label);
      const inp = el("input");
      inp.type = "number";
      inp.min = "0";
      inp.max = "10000";
      inp.step = "1";
      inp.value = String(values[i] == null ? 0 : values[i]);
      inp.addEventListener("input", recalc);
      l.appendChild(inp);
      box.appendChild(l);
    });
  }

  function readInts(box) {
    return Array.prototype.map.call(box.querySelectorAll("input"), i => {
      const n = parseInt(i.value, 10);
      return isFinite(n) && n >= 0 ? n : 0;
    });
  }

  function recalc() {
    const login = readInts($("fLogin"));
    const hours = readInts($("fHours"));
    const sum = a => a.reduce((s, x) => s + x, 0);
    const cap = sum(login) + 7 * sum(hours);
    const price = Math.max(1, Math.floor(cap / 2));
    const s = $("fSum");
    s.textContent = "";
    s.appendChild(document.createTextNode("Всего можно собрать: "));
    s.appendChild(el("b", "", cap));
    s.appendChild(document.createTextNode(" (вход " + sum(login) + " + часы 7 × " + sum(hours) + "). Билет стоит "));
    s.appendChild(el("b", "", price));
    s.appendChild(document.createTextNode(", билетов не больше 2 — оба только у того, кто собрал всё."));
    const start = dateTs($("fStart").value);
    $("fDays").textContent = start
      ? fmtDay(start) + " – " + fmtDay(start + 6 * DAY) + ", каждый день с 00:00 по Москве. Токены копятся до " + fmt(start + 7 * DAY) + "."
      : "—";
  }

  function iconPath(url) {
    const m = /^(?:https:\/\/(?:www\.)?maknemy\.com)?(\/images\/[0-9a-f]{40}\.(?:png|jpg|webp))$/.exec(url || "");
    return m ? m[1] : "";
  }

  function loadCatalog() {
    return fetch(TIERLIST, { credentials: "same-origin" })
      .then(r => r.json())
      .then(d => {
        const t = d && d.tierlist ? (typeof d.tierlist === "string" ? JSON.parse(d.tierlist) : d.tierlist) : null;
        const seen = {};
        catalog = [];
        ((t && t.tiers) || []).forEach(tier => (tier.items || []).forEach(it => {
          const icon = iconPath(it.icon);
          if (!icon || !it.name || seen[icon]) return;
          seen[icon] = true;
          catalog.push({ name: String(it.name), icon: icon });
        }));
        const dl = $("haItems");
        dl.textContent = "";
        catalog.forEach(it => { const o = el("option"); o.value = it.name; dl.appendChild(o); });
      })
      .catch(() => {});
  }

  function prizeRow(p) {
    const row = el("div", "ha-prize");
    const img = el("img");
    img.alt = "";
    const name = el("input");
    name.type = "text";
    name.maxLength = 120;
    name.placeholder = "Gas (permanent)";
    name.value = p.name || "";
    const count = el("input");
    count.type = "number";
    count.min = "1";
    count.max = "100";
    count.step = "1";
    count.value = String(p.count || 1);
    count.title = "Сколько штук";
    const item = el("input", "ha-prize-item");
    item.type = "text";
    item.placeholder = "Иконка: предмет из тирлиста";
    item.setAttribute("list", "haItems");
    const del = el("button", "btn small danger", "✕");
    del.type = "button";
    del.title = "Убрать приз";
    row.dataset.icon = p.icon || "";
    const showIcon = () => {
      img.hidden = !row.dataset.icon;
      if (row.dataset.icon) img.src = row.dataset.icon;
    };
    const found = catalog.filter(it => it.icon === p.icon)[0];
    if (found) item.value = found.name;
    item.addEventListener("input", () => {
      const v = item.value.trim().toLowerCase();
      const hit = catalog.filter(it => it.name.toLowerCase() === v)[0];
      row.dataset.icon = hit ? hit.icon : "";
      showIcon();
    });
    del.addEventListener("click", () => row.remove());
    showIcon();
    row.append(img, name, count, item, del);
    return row;
  }

  function readPrizes() {
    return Array.prototype.map.call($("fPrizes").children, row => {
      const inputs = row.querySelectorAll("input");
      return { name: inputs[0].value.trim(), count: parseInt(inputs[1].value, 10) || 1, icon: row.dataset.icon || "" };
    });
  }

  function fillForm(h) {
    $("fStatus").value = h.status === "live" ? "live" : "draft";
    $("fStart").value = h.start_date || "";
    $("fSales").value = toLocal(h.sales_end_at);
    $("fDraw").value = toLocal(h.draw_at);
    $("fClaim").value = String(h.claim_days || 7);
    $("fTitleRu").value = h.title_ru || "";
    $("fTitleEn").value = h.title_en || "";
    $("fRulesRu").value = h.rules_ru || "";
    $("fRulesEn").value = h.rules_en || "";
    $("fTLoginRu").value = h.titles.login.ru;
    $("fTLoginEn").value = h.titles.login.en;
    $("fTHoursRu").value = h.titles.hours.ru;
    $("fTHoursEn").value = h.titles.hours.en;
    intsBox($("fLogin"), [1, 2, 3, 4, 5, 6, 7].map(n => "День " + n), h.login);
    intsBox($("fHours"), ["1 час", "2 часа"], h.hours);
    const box = $("fPrizes");
    box.textContent = "";
    (h.prizes || []).forEach(p => box.appendChild(prizeRow(p)));
    const started = h.phase && h.phase !== "draft" && h.phase !== "soon";
    $("fLocked").hidden = !started || h.phase === "drawn";
    $("fStart").disabled = started;
    $("fLogin").querySelectorAll("input").forEach(i => { i.disabled = started; });
    $("fHours").querySelectorAll("input").forEach(i => { i.disabled = started; });
    $("fStatus").disabled = started;
    $("haSave").disabled = h.phase === "drawn";
    recalc();
  }

  function readForm() {
    return {
      status: $("fStatus").value,
      start_date: $("fStart").value,
      sales_end_at: fromLocal($("fSales").value),
      draw_at: fromLocal($("fDraw").value),
      claim_days: parseInt($("fClaim").value, 10) || 0,
      title_ru: $("fTitleRu").value,
      title_en: $("fTitleEn").value,
      rules_ru: $("fRulesRu").value,
      rules_en: $("fRulesEn").value,
      login: readInts($("fLogin")),
      hours: readInts($("fHours")),
      prizes: readPrizes(),
      titles: {
        login: { ru: $("fTLoginRu").value.trim(), en: $("fTLoginEn").value.trim() },
        hours: { ru: $("fTHoursRu").value.trim(), en: $("fTHoursEn").value.trim() }
      }
    };
  }

  function phaseBadge(phase) {
    const b = $("haPhase");
    b.textContent = PHASES[phase] || "Ещё не заведён";
    b.className = "ha-phase" + (phase ? " is-" + phase : "");
  }

  function kpi(box, value, label) {
    const k = el("div", "ha-kpi");
    k.append(el("b", "", value), el("span", "", label));
    box.appendChild(k);
  }

  function renderStats(s, h) {
    $("haStats").hidden = false;
    const box = $("haKpis");
    box.textContent = "";
    kpi(box, s.hunters, "участников");
    kpi(box, s.earned, "токенов собрано всеми");
    kpi(box, s.tickets, "билетов (без аннулированных)");
    kpi(box, s.day ? s.day + " из 7" : "—", "день ивента");
    kpi(box, s.logins_today, "забрали вход сегодня");
    kpi(box, s.titles.login, "титул «" + h.titles.login.ru + "»");
    kpi(box, s.titles.hours, "титул «" + h.titles.hours.ru + "»");
  }

  function nick(u) {
    if (!u) return "—";
    const name = u.display_name || u.username || u.id;
    return u.username && u.username !== name ? name + " (@" + u.username + ")" : name;
  }

  function profileLink(u) {
    const a = el("a", "", nick(u));
    a.href = "/profile?id=" + encodeURIComponent(u.id);
    a.target = "_blank";
    a.rel = "noopener";
    return a;
  }

  function renderDraw(h, wins) {
    const sec = $("haDraw");
    sec.hidden = h.phase === "draft" || h.phase === "soon";
    const btn = $("haDrawBtn");
    btn.hidden = h.phase === "drawn";
    btn.disabled = h.phase !== "closed";
    $("haDrawNote").textContent = h.phase === "drawn"
      ? "Разыграно " + fmt(h.drawn_at) + ". Победитель подтверждает выигрыш на странице ивента (нужен Telegram для связи); не успел за " + h.claim_days + " дн. — «Отдать следующему»."
      : (h.draw_at ? "Итоги подведутся сами " + fmt(h.draw_at) + ": на странице ивента билеты победителей выпадут по одному. Кнопка — разыграть раньше, после конца продажи билетов (" + fmt(h.sales_end_at) + ")."
        : "Время итогов не задано — разыграйте кнопкой после конца продажи билетов: " + fmt(h.sales_end_at) + ".") + " Один человек — один приз, два билета — два шанса.";
    const t = $("haWins");
    t.textContent = "";
    if (!wins.length) return;
    const head = el("tr");
    ["Приз", "Победитель", "Билет", "Статус", "Срок", "Заметка", ""].forEach(x => head.appendChild(el("th", "", x)));
    t.appendChild(head);
    wins.forEach(w => {
      const tr = el("tr");
      tr.appendChild(el("td", "", w.prize.name));
      const who = el("td");
      who.appendChild(profileLink(w.user));
      if (!w.tg) who.appendChild(el("span", "muted small", " · нет Telegram"));
      tr.appendChild(who);
      tr.appendChild(el("td", "", w.ticket_no));
      const st = el("td");
      st.appendChild(el("span", "ha-st " + w.raw_status, WIN[w.raw_status] || w.raw_status));
      tr.appendChild(st);
      tr.appendChild(el("td", "", w.raw_status === "won" ? fmt(w.deadline) : ""));
      const noteTd = el("td");
      const note = el("input");
      note.type = "text";
      note.maxLength = 255;
      note.value = w.note || "";
      note.placeholder = "как выдан, ник для трейда…";
      noteTd.appendChild(note);
      tr.appendChild(noteTd);
      const act = el("td");
      (MOVES[w.raw_status] || []).forEach(([to, label]) => {
        const b = el("button", "btn small", label);
        b.type = "button";
        b.addEventListener("click", () => run({ action: "win", id: w.id, status: to, note: note.value }, "Статус обновлён."));
        act.appendChild(b);
      });
      if (w.raw_status === "expired") {
        const b = el("button", "btn small", "Отдать следующему");
        b.type = "button";
        b.addEventListener("click", () => {
          if (confirm("Отдать «" + w.prize.name + "» следующему билету из очереди итогов?")) run({ action: "redraw", id: w.id }, "Приз отдан следующему.");
        });
        act.appendChild(b);
      }
      tr.appendChild(act);
      t.appendChild(tr);
    });
  }

  function renderPeople(people) {
    $("haPeople").hidden = false;
    const q = $("haSearch").value.trim().toLowerCase();
    const list = people.filter(p => !q || (p.username + " " + p.display_name + " " + p.id).toLowerCase().indexOf(q) >= 0);
    $("haPeopleCount").textContent = String(people.length);
    const t = $("haPeopleTable");
    t.textContent = "";
    const head = el("tr");
    ["Ник", "Собрано", "Баланс", "Билеты", "Последний раз"].forEach(x => head.appendChild(el("th", "", x)));
    t.appendChild(head);
    list.forEach(p => {
      const tr = el("tr");
      const who = el("td");
      who.appendChild(profileLink(p));
      tr.appendChild(who);
      tr.appendChild(el("td", "", p.earned));
      tr.appendChild(el("td", "", p.balance));
      const tk = el("td");
      p.tickets.forEach(tick => {
        const span = el("span", "ha-ticket" + (tick.void ? " is-void" : ""), tick.no);
        if (tick.void) {
          span.title = tick.void_reason ? "Аннулирован: " + tick.void_reason : "Аннулирован";
        } else {
          const b = el("button", "btn small danger", "✕");
          b.type = "button";
          b.title = "Аннулировать билет";
          b.addEventListener("click", () => {
            const reason = prompt("Аннулировать билет " + tick.no + "? Токены не вернутся. Причина:");
            if (reason !== null) run({ action: "void", id: tick.id, reason: reason }, "Билет аннулирован.");
          });
          span.appendChild(b);
        }
        tk.appendChild(span);
      });
      tr.appendChild(tk);
      tr.appendChild(el("td", "", p.last_at ? fmt(p.last_at) : "—"));
      t.appendChild(tr);
    });
  }

  function render() {
    const h = state.hunt;
    if (!h) {
      phaseBadge("");
      fillForm(Object.assign({ phase: "", status: "draft" }, state.defaults));
      $("haStats").hidden = true;
      $("haDraw").hidden = true;
      $("haPeople").hidden = true;
      return;
    }
    phaseBadge(h.phase);
    fillForm(h);
    renderStats(state.stats, h);
    renderDraw(h, state.wins || []);
    renderPeople(state.people || []);
  }

  function load() {
    return post({ action: "get" }).then(d => {
      if (!d || d.ok === false) { alertMsg(errText(d)); return; }
      state = d;
      render();
    });
  }

  function run(body, okText) {
    if (busy) return Promise.resolve();
    busy = true;
    $("haHint").textContent = "…";
    return post(body).then(d => {
      busy = false;
      $("haHint").textContent = "";
      if (!d || d.ok === false) { alertMsg(errText(d)); return; }
      alertMsg(okText, true);
      return load();
    }).catch(() => { busy = false; $("haHint").textContent = ""; alertMsg(ERRORS.server); });
  }

  $("fStart").addEventListener("input", recalc);
  $("fPrizeAdd").addEventListener("click", () => $("fPrizes").appendChild(prizeRow({ name: "", count: 1, icon: "" })));
  $("haSave").addEventListener("click", () => {
    const f = readForm();
    if (f.status === "live" && (!state.hunt || state.hunt.status !== "live")
      && !confirm("Опубликовать ивент? Он появится на сайте, а с " + $("fStart").value + " начнут копиться токены.")) return;
    run({ action: "save", hunt: f }, "Сохранено.");
  });
  $("haDrawBtn").addEventListener("click", () => {
    if (confirm("Разыграть призы? Повторить нельзя.")) run({ action: "draw" }, "Разыграно.");
  });
  $("haSearch").addEventListener("input", () => { if (state && state.people) renderPeople(state.people); });

  loadCatalog().then(load);
})();
