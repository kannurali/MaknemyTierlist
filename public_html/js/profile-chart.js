(function () {
  'use strict';

  const root = document.getElementById('pfChart');
  if (!root) { return; }

  const plot     = document.getElementById('pfPlot');
  const monthSel = document.getElementById('pfMonth');
  const readout  = document.getElementById('pfReadout');
  const empty    = document.getElementById('pfChartEmpty');
  const table    = document.getElementById('pfChartTable');
  const barFill  = document.getElementById('pfBarFill');
  const barNow   = document.getElementById('pfBarNow');
  const barMax   = document.getElementById('pfBarMax');
  const NS       = 'http://www.w3.org/2000/svg';

  const PAD = { l: 46, r: 14, t: 14, b: 30 };

  function geometry() {
    const box = plot.getBoundingClientRect();
    const W = Math.max(320, Math.round(box.width) || 960);
    const H = Math.max(160, Math.round(box.height) || 230);
    return { W: W, H: H, IW: W - PAD.l - PAD.r, IH: H - PAD.t - PAD.b };
  }

  const MONTHS_RU = ['январь','февраль','март','апрель','май','июнь',
                     'июль','август','сентябрь','октябрь','ноябрь','декабрь'];
  const MONTHS_RU_GEN = ['января','февраля','марта','апреля','мая','июня',
                         'июля','августа','сентября','октября','ноября','декабря'];
  const MONTHS_EN = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];

  const lang = () => (document.documentElement.lang === 'en' ? 'en' : 'ru');
  const tx   = (key, fallback) => (window.I18N ? window.I18N.t(key, lang()) : fallback);

  function monthLabel(ym) {
    const [y, m] = ym.split('-').map(Number);
    const names = lang() === 'en' ? MONTHS_EN : MONTHS_RU;
    return names[m - 1] + ' ' + y;
  }

  const fmt = n => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

  function el(name, attrs, text) {
    const n = document.createElementNS(NS, name);
    for (const k in attrs) { n.setAttribute(k, attrs[k]); }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }

  const GRID_STEPS = 4;

  const NICE = [1, 2, 3, 4, 5, 6, 8, 10];

  function niceStep(max) {
    const raw = Math.max(1, max) / GRID_STEPS;
    const mag = Math.max(1, Math.pow(10, Math.floor(Math.log10(Math.max(1, raw)))));
    for (const m of NICE) {
      if (raw <= mag * m) { return mag * m; }
    }
    return mag * 10;
  }

  function niceTop(max) { return niceStep(max) * GRID_STEPS; }

  let state = null;

  function render(data) {
    state = data;
    root.classList.remove('is-error');
    plot.textContent = '';

    plot.__i = undefined;
    readout.hidden = true;
    readout.textContent = '';

    const days = data.days;
    const g    = geometry();
    const W = g.W, H = g.H, IW = g.IW, IH = g.IH;
    const top  = niceTop(Math.max(1, ...days.map(d => Math.max(d.ok, d.declined))));
    const x    = i => PAD.l + (days.length === 1 ? IW / 2 : (IW * i) / (days.length - 1));
    const y    = v => PAD.t + IH - (IH * v) / top;

    const svg = el('svg', {
      viewBox: `0 0 ${W} ${H}`,
      class: 'pf-plot-svg',
      role: 'img',
      'aria-labelledby': 'pfChartTitle',
    });

    const gridStep = top / GRID_STEPS;
    for (let v = 0; v <= top; v += gridStep) {
      svg.appendChild(el('line', {
        x1: PAD.l, x2: W - PAD.r, y1: y(v), y2: y(v), class: 'pf-grid-line',
      }));
      svg.appendChild(el('text', {
        x: PAD.l - 8, y: y(v) + 4, class: 'pf-axis-text', 'text-anchor': 'end',
      }, String(Math.round(v))));
    }

    days.forEach((d, i) => {
      const isEdge = d.day === 1 || d.day === days.length;
      if (!isEdge && d.day % 5 !== 0) { return; }
      svg.appendChild(el('text', {
        x: x(i), y: H - 10, class: 'pf-axis-text', 'text-anchor': 'middle',
      }, String(d.day)));
    });

    const last  = Math.max(1, Math.min(days.length, data.lastDay || days.length));
    const drawn = days.slice(0, last);
    const path = key => {
      const pts = drawn.map((d, i) => `${x(i).toFixed(1)},${y(d[key]).toFixed(1)}`);
      if (pts.length === 1) { return `M${pts[0]}L${pts[0]}`; }
      return pts.map((p, i) => `${i ? 'L' : 'M'}${p}`).join('');
    };
    svg.appendChild(el('path', { d: path('declined'), class: 'pf-line pf-line-no' }));
    svg.appendChild(el('path', { d: path('ok'),       class: 'pf-line pf-line-ok' }));

    if (last < days.length) {
      svg.appendChild(el('rect', {
        x: x(last - 1), y: PAD.t, width: Math.max(0, x(days.length - 1) - x(last - 1)), height: IH,
        class: 'pf-future',
      }));
    }

    const hover = el('g', { class: 'pf-hover', hidden: 'hidden' });
    hover.appendChild(el('line', { class: 'pf-hover-line', y1: PAD.t, y2: PAD.t + IH }));
    hover.appendChild(el('circle', { class: 'pf-hover-dot pf-dot-ok', r: 4 }));
    hover.appendChild(el('circle', { class: 'pf-hover-dot pf-dot-no', r: 4 }));
    svg.appendChild(hover);

    plot.appendChild(svg);
    plot.__hover = hover;
    plot.__x = x; plot.__y = y; plot.__geo = g;

    const any = drawn.some(d => d.ok || d.declined);
    empty.hidden = any;
    if (!any) {
      const ever = data.lifetime && data.lifetime.total > 0;
      empty.textContent = ever
        ? tx('profile.chartNoMonth', 'В этом месяце сделок не было')
        : tx('profile.chartNoData', 'Сделок пока нет — статистика появится после первого обмена');
    }

    const sum = data.totals.sum, scale = data.totals.scale;
    barFill.style.width = scale > 0 ? Math.max(0, Math.min(100, (sum / scale) * 100)) + '%' : '0%';
    barNow.textContent  = fmt(sum);
    barMax.textContent  = fmt(scale);
    root.classList.toggle('is-empty', !any);

    if (table) { renderTable(data, days); }

    const life = data.lifetime || { ok: 0, declined: 0, total: 0 };
    setCounter('pfStatDeals', life.ok);
    setCounter('pfStatCreated', life.total);
    setCounter('pfStatCancelled', life.declined);
  }

  function cell(tag, text, scope) {
    const n = document.createElement(tag);
    n.textContent = text;
    if (scope) { n.scope = scope; }
    return n;
  }

  function renderTable(data, days) {
    table.textContent = '';

    const caption = document.createElement('caption');
    caption.textContent = monthLabel(data.month);
    table.appendChild(caption);

    const head = document.createElement('thead');
    const hrow = document.createElement('tr');
    [tx('profile.chartDay', 'День'), tx('profile.chartOk', 'Успешно'),
     tx('profile.chartFail', 'Отказ'), tx('profile.chartSum', 'Оборот')]
      .forEach(t => hrow.appendChild(cell('th', t, 'col')));
    head.appendChild(hrow);
    table.appendChild(head);

    const body = document.createElement('tbody');
    const rows = days.filter(d => d.ok || d.declined);
    if (!rows.length) {
      const tr = document.createElement('tr');
      const td = cell('td', '—');
      td.colSpan = 4;
      tr.appendChild(td);
      body.appendChild(tr);
    } else {
      rows.forEach(d => {
        const tr = document.createElement('tr');
        tr.appendChild(cell('th', String(d.day), 'row'));
        tr.appendChild(cell('td', String(d.ok)));
        tr.appendChild(cell('td', String(d.declined)));
        tr.appendChild(cell('td', fmt(d.sum)));
        body.appendChild(tr);
      });
    }
    table.appendChild(body);
  }

  function setCounter(id, value) {
    const node = document.getElementById(id);
    if (node) { node.textContent = fmt(value); }
  }

  function nearestIndex(clientX) {
    const box = plot.getBoundingClientRect();
    if (!box.width || !state) { return -1; }
    const g   = plot.__geo || geometry();
    const rel = ((clientX - box.left) / box.width) * g.W;
    const n   = state.days.length;
    const i   = Math.round(((rel - PAD.l) / g.IW) * (n - 1));
    const max = Math.max(0, Math.min(n, state.lastDay || n) - 1);
    return Math.max(0, Math.min(max, i));
  }

  function showAt(i) {
    if (!state || i < 0) { return; }
    const d = state.days[i];
    if (!d) { return; }

    plot.__i = i;

    const hover = plot.__hover;
    if (hover) {
      const px = plot.__x(i);
      hover.removeAttribute('hidden');
      hover.children[0].setAttribute('x1', px);
      hover.children[0].setAttribute('x2', px);
      hover.children[1].setAttribute('cx', px);
      hover.children[1].setAttribute('cy', plot.__y(d.ok));
      hover.children[2].setAttribute('cx', px);
      hover.children[2].setAttribute('cy', plot.__y(d.declined));
    }
    readout.hidden = false;
    const mi = Number(state.month.split('-')[1]) - 1;
    const dayMonth = lang() === 'en' ? MONTHS_EN[mi] + ' ' + d.day : d.day + ' ' + MONTHS_RU_GEN[mi];
    readout.textContent = `${dayMonth} · `
      + `${tx('profile.chartOk', 'Успешно')} ${d.ok} · `
      + `${tx('profile.chartFail', 'Отказ')} ${d.declined} · `
      + `${tx('profile.chartSum', 'Оборот')} ${fmt(d.sum)}`;
  }

  function hide() {
    if (plot.__hover) { plot.__hover.setAttribute('hidden', 'hidden'); }
    readout.hidden = true;
  }

  plot.addEventListener('pointermove', e => showAt(nearestIndex(e.clientX)));
  plot.addEventListener('pointerleave', hide);

  plot.addEventListener('keydown', e => {
    if (!state) { return; }
    const lastIdx = Math.max(0, Math.min(state.days.length, state.lastDay || state.days.length) - 1);
    const cur = Math.min(plot.__i === undefined ? 0 : plot.__i, lastIdx);
    let next = cur;
    if (e.key === 'ArrowRight') { next = Math.min(lastIdx, cur + 1); }
    else if (e.key === 'ArrowLeft') { next = Math.max(0, cur - 1); }
    else if (e.key === 'Home') { next = 0; }
    else if (e.key === 'End') { next = lastIdx; }
    else { return; }
    e.preventDefault();
    plot.__i = next;
    showAt(next);
  });
  plot.addEventListener('blur', hide);

  let seq = 0;
  let failed = false;

  async function load(month) {
    const mine = ++seq;
    root.classList.add('is-loading');
    try {
      const url = '/api/profile-stats.php' + (month ? '?month=' + encodeURIComponent(month) : '');
      const res = await fetch(url, { cache: 'no-store' });
      if (mine !== seq) { return; }
      if (res.status === 401 || res.status === 403) {
        failed = false;
        document.dispatchEvent(new CustomEvent('mk:profiledata', { detail: { authed: false } }));
        return;
      }
      if (!res.ok) { throw new Error('HTTP ' + res.status); }
      const data = await res.json();
      if (mine !== seq) { return; }
      failed = false;

      if (!data.authed) {
        document.dispatchEvent(new CustomEvent('mk:profiledata', { detail: data }));
        return;
      }

      if (monthSel && !monthSel.options.length) {
        const list = (data.months && data.months.length) ? data.months : [data.month];
        list.forEach(m => {
          const o = document.createElement('option');
          o.value = m;
          o.textContent = monthLabel(m);
          monthSel.appendChild(o);
        });
        monthSel.value = data.month;
      }
      render(data);

      document.dispatchEvent(new CustomEvent('mk:profiledata', { detail: data }));
    } catch (e) {
      if (mine !== seq) { return; }
      failed = true;
      if (state && monthSel) { monthSel.value = state.month; }
      if (state) {
        render({
          ...state,
          available: false,
          days: state.days.map(d => ({ day: d.day, ok: 0, declined: 0, sum: 0 })),
          totals: { ok: 0, declined: 0, sum: 0, scale: 0 },
        });
      }
      showError();
    } finally {
      if (mine === seq) { root.classList.remove('is-loading'); }
    }
  }

  function showError() {
    empty.hidden = false;
    empty.textContent = tx('profile.chartError',
      'Не удалось загрузить статистику. Попробуйте обновить страницу.');
    root.classList.add('is-empty', 'is-error');
  }

  if (monthSel) { monthSel.addEventListener('change', () => load(monthSel.value)); }

  document.addEventListener('mk:langchange', () => {
    if (state) { render(state); }
    if (failed) { showError(); }
    if (monthSel) {
      [...monthSel.options].forEach(o => { o.textContent = monthLabel(o.value); });
    }
  });

  let resizeTimer = null;
  window.addEventListener('resize', () => {
    if (resizeTimer) { clearTimeout(resizeTimer); }
    resizeTimer = setTimeout(() => { if (state) { render(state); } }, 150);
  });

  load(null);
})();
