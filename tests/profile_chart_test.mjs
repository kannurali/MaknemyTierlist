// График профиля под настоящим прогоном. Run: node --test tests/profile_chart_test.mjs
//
// Зачем поведенческий прогон, а не чтение исходника. js/profile-chart.js —
// единственный файл профиля, который РАЗБИРАЕТ ответ эндпоинта и строит по
// нему узлы. Проверка «в файле есть слово barFill» доказывает, что строка не
// удалена, и ровно ничего не говорит о том, что случится, когда узла на
// странице нет: скрипт падает на первом же обращении, а вместе с ним молча
// пропадают и таблица для скринридера, и счётчики под графиком — всё, что
// рисуется ПОСЛЕ полосы. Именно это и случилось бы на каждом чужом профиле,
// когда полосу оборота перестали печатать.
//
// Настоящего DOM здесь нет и не нужно: скрипту требуются getElementById,
// createElement(NS), classList, dataset и getBoundingClientRect. Заглушка
// ниже даёт ровно их — как в tests/news_blocks_test.mjs. window и document
// собирают СВОИ обработчики (а не глушат addEventListener в пустоту), иначе
// mk:langchange и resize оставались бы недостижимыми путями кода.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const src  = readFileSync(new URL('../public_html/js/profile-chart.js', import.meta.url), 'utf8');
const I18N = require('../public_html/js/i18n.js');

// Узлы, которые profile.php печатает ВСЕГДА. Полосы оборота (pfBarNow,
// pfBarFill, pfBarMax) среди них нет: её печатает только свой профиль.
const COMMON = ['pfChart', 'pfPlot', 'pfMonth', 'pfReadout', 'pfChartEmpty',
                'pfChartTable', 'pfStatDeals', 'pfStatCreated', 'pfStatCancelled'];
const BAR    = ['pfBarFill', 'pfBarNow', 'pfBarMax'];

function node(tag) {
    const n = {
        tagName: String(tag).toUpperCase(),
        children: [], attrs: {}, style: {}, dataset: {}, handlers: {}, options: [],
        hidden: false, scope: '', colSpan: 0, _text: '',
        classList: {
            _s: new Set(),
            add(...c) { c.forEach(x => this._s.add(x)); },
            remove(...c) { c.forEach(x => this._s.delete(x)); },
            toggle(c, on) { on ? this._s.add(c) : this._s.delete(c); },
            contains(c) { return this._s.has(c); },
        },
        appendChild(k) { n.children.push(k); return k; },
        setAttribute(k, v) { n.attrs[k] = String(v); },
        removeAttribute(k) { delete n.attrs[k]; },
        addEventListener(t, f) { (n.handlers[t] ||= []).push(f); },
        getBoundingClientRect() { return { width: 960, height: 230, left: 0, top: 0 }; },
        get textContent() { return n._text + n.children.map(c => c.textContent).join(''); },
        set textContent(v) { n._text = String(v); n.children.length = 0; },
    };
    return n;
}

function walk(root) {
    const out = [];
    (function step(n) { out.push(n); (n.children || []).forEach(step); })(root);
    return out;
}

// Ответ эндпоинта ровно в той форме, в какой его собирает
// handle_profile_stats(): у своего профиля денежные ключи есть, у чужого их
// НЕТ — не нули, а отсутствие. Фикстура обязана повторять именно это, иначе
// тест проверял бы форму, которой сервер не отдаёт.
function payload(self) {
    const day = (d, ok, declined, sum) =>
        self ? { day: d, ok, declined, sum } : { day: d, ok, declined };
    return {
        authed: true, available: true, month: '2026-09', lastDay: 3,
        months: ['2026-09', '2026-08'],
        days: [day(1, 2, 1, 100), day(2, 0, 0, 0), day(3, 1, 0, 50)],
        totals: self ? { ok: 3, declined: 1, sum: 150, scale: 300 }
                     : { ok: 3, declined: 1 },
        lifetime: { ok: 3, declined: 1, total: 4 },
    };
}

// self/peer управляет только РАЗМЕТКОЙ (какие узлы существуют и что несёт
// data-peer); answer управляет тем, что отдаёт "эндпоинт". По умолчанию они
// согласованы — как на настоящей странице, — но часть тестов НАРОЧНО их
// расщепляет: колонку и полосу обязана решать форма ответа, а не то, что
// стоит в data-peer, иначе атрибут в DevTools подделывает кто угодно.
async function run({ self, answer = null, fetchFails = false, failAfter = null } = {}) {
    const ids = self ? [...COMMON, ...BAR] : COMMON;
    const map = {};
    for (const id of ids) { map[id] = node(id === 'pfChartTable' ? 'table' : 'div'); }
    map.pfChart.dataset.profile = self ? '900000001' : '900000004';
    if (!self) { map.pfChart.dataset.peer = '1'; }

    const docHandlers = {};
    const winHandlers = {};
    const doc = {
        documentElement: { lang: 'ru' },
        getElementById: id => map[id] || null,
        createElement: node,
        createElementNS: (ns, t) => node(t),
        addEventListener(t, f) { (docHandlers[t] ||= []).push(f); },
        dispatchEvent(ev) { (docHandlers[ev.type] || []).forEach(f => f(ev)); return true; },
    };
    const win = {
        I18N,
        addEventListener(t, f) { (winHandlers[t] ||= []).push(f); },
    };

    // Падение внутри load() приходит необработанным промисом: скрипт зовёт
    // её сам и никому не отдаёт. Без этого перехвата тест на сломанном коде
    // оставался бы ЗЕЛЁНЫМ, а страница в браузере была бы пустой.
    const boom = [];
    const onReject = e => boom.push((e && e.message) || String(e));
    process.on('unhandledRejection', onReject);

    let n = 0;
    const calls = [];
    const fetchStub = async url => {
        calls.push(url);
        n++;
        if (fetchFails || (failAfter !== null && n > failAfter)) { throw new Error('offline'); }
        return { ok: true, status: 200, json: async () => JSON.parse(JSON.stringify(answer || payload(self))) };
    };

    new Function('window', 'document', 'fetch', 'CustomEvent', src)(
        win, doc, fetchStub,
        class { constructor(t, i) { this.type = t; Object.assign(this, i); } },
    );
    await new Promise(r => setTimeout(r, 30));
    process.off('unhandledRejection', onReject);

    const table = map.pfChartTable;
    const readTable = () => ({
        head: walk(table).filter(x => x.tagName === 'TH' && x.scope === 'col').map(x => x.textContent),
        rows: walk(table).filter(x => x.tagName === 'TR').slice(1)
                         .map(tr => tr.children.map(c => c.textContent)),
    });

    return {
        map, doc, calls, boom, docHandlers, winHandlers,
        ...readTable(), readTable,
        press(key) { map.pfPlot.handlers.keydown[0]({ key, preventDefault() {} }); },
        async changeMonth(m) {
            map.pfMonth.value = m;
            map.pfMonth.handlers.change[0]();
            await new Promise(r => setTimeout(r, 30));
        },
        async fireLangChange() {
            docHandlers['mk:langchange'][0]();
            await new Promise(r => setTimeout(r, 0));
        },
        async fireResize() {
            winHandlers.resize[0]();
            await new Promise(r => setTimeout(r, 160));
        },
    };
}

// ГЛАВНАЯ проверка этого файла. До неё чужой профиль падал на barFill.style
// и вместе с полосой терял таблицу и счётчики.
test('чужой профиль рисуется без полосы оборота и без падения', async () => {
    const r = await run({ self: false });
    assert.deepEqual(r.boom, [], 'ни одного необработанного падения');
    assert.equal(r.map.pfStatDeals.textContent, '3', 'счётчики под графиком заполнены');
    assert.ok(r.rows.length > 0, 'таблица для скринридера построена');
});

test('на своём профиле полоса оборота заполняется из ответа', async () => {
    const r = await run({ self: true });
    assert.deepEqual(r.boom, []);
    assert.equal(r.map.pfBarNow.textContent, '150', 'оборот месяца');
    assert.equal(r.map.pfBarMax.textContent, '300', 'шкала — лучший месяц');
    assert.equal(r.map.pfBarFill.style.width, '50%', '150 из 300 — половина полосы');
});

test('колонка «Оборот» в таблице появляется только там, где сервер прислал суммы', async () => {
    const mine = await run({ self: true });
    assert.deepEqual(mine.head, ['День', 'Успешно', 'Отказ', 'Оборот']);
    assert.deepEqual(mine.rows[0], ['1', '2', '1', '100']);

    const peer = await run({ self: false });
    assert.deepEqual(peer.head, ['День', 'Успешно', 'Отказ']);
    assert.deepEqual(peer.rows[0], ['1', '2', '1'], 'сделки за день на месте, суммы нет');
});

// Разрыв между узлами и ключом ответа — ровно та лазейка, ради которой
// написан весь остальной файл: атрибут data-peer в браузере подделывает
// кто угодно через DevTools, а ключ, которого сервер не прислал, — никто.
// Если бы колонку решал атрибут (`!PEER`), а не `d.sum !== undefined`, эти
// два прогона разошлись бы с ожиданием.
test('колонку решает форма ответа, а не разметка своего/чужого профиля', async () => {
    const selfMarkupPeerData = await run({ self: true, answer: payload(false) });
    assert.deepEqual(selfMarkupPeerData.head, ['День', 'Успешно', 'Отказ'],
        'узлы полосы есть, но сервер сумм не прислал — колонки нет');
    assert.equal(selfMarkupPeerData.map.pfBarNow.textContent, '0',
        'полоса не выдумывает оборот из отсутствующих данных');

    const peerMarkupSelfData = await run({ self: false, answer: payload(true) });
    assert.deepEqual(peerMarkupSelfData.head, ['День', 'Успешно', 'Отказ', 'Оборот'],
        'разметка чужая, но сервер прислал суммы — колонка обязана появиться');
    assert.deepEqual(peerMarkupSelfData.rows[0], ['1', '2', '1', '100']);
});

// Заглушка пустого месяца растягивается на всю ширину таблицы. Число было
// вбито литералом 4 — на чужом профиле оно разошлось бы с тремя колонками.
test('заглушка пустого месяца занимает столько колонок, сколько их есть', async () => {
    const empty = d => ({ day: d, ok: 0, declined: 0 });
    const peer = await run({ self: false, answer: {
        authed: true, available: true, month: '2026-09', lastDay: 3, months: ['2026-09'],
        days: [empty(1), empty(2), empty(3)],
        totals: { ok: 0, declined: 0 }, lifetime: { ok: 0, declined: 0, total: 0 },
    } });
    assert.deepEqual(peer.head, ['День', 'Успешно', 'Отказ']);
    const stub = walk(peer.map.pfChartTable).find(n => n.tagName === 'TD' && n.textContent === '—');
    assert.ok(stub, 'заглушка есть');
    assert.equal(stub.colSpan, 3, 'на три колонки, а не на четыре');
});

// Подсказку читает скринридер вслух — она обязана молчать про оборот там,
// где его нет, и называть его словом из СЛОВАРЯ, а не литералом: window.I18N
// здесь настоящий (не заглушка null), иначе опечатка или переименование
// ключа осталась бы незамеченной — I18N.t на пропавшем ключе вернул бы сам
// ключ строкой, и подсказка читалась бы как «profile.chartSum 100».
test('подсказка по дню называет оборот словом из словаря и только на своём профиле', async () => {
    const mine = await run({ self: true });
    mine.press('Home');
    assert.match(mine.map.pfReadout.textContent, /Оборот 100/);
    assert.equal(I18N.t('profile.chartSum', 'ru'), 'Оборот', 'слово в подсказке и в словаре — одно и то же');

    const peer = await run({ self: false });
    peer.press('Home');
    assert.doesNotMatch(peer.map.pfReadout.textContent, /Оборот/);
    assert.match(peer.map.pfReadout.textContent, /Успешно 2/, 'сделки по-прежнему названы');
});

// Смена языка перерисовывает таблицу — это ЕДИНСТВЕННЫЙ путь, которым
// заголовки колонок обновляются без перезагрузки страницы. Мимо него легко
// пройти: обработчик собран отдельно от render() при первой отрисовке.
test('смена языка не возвращает колонку оборота чужому профилю', async () => {
    const peer = await run({ self: false });
    assert.deepEqual(peer.head, ['День', 'Успешно', 'Отказ']);
    peer.doc.documentElement.lang = 'en';
    await peer.fireLangChange();
    assert.deepEqual(peer.readTable().head, ['Day', 'Successful', 'Declined'],
        'заголовки переведены и колонки оборота по-прежнему нет');
});

// После обрыва сети график гасится собственным состоянием, а не чужой
// формой, переписанной руками, — и делает это ОДИНАКОВО что после смены
// месяца, что после resize (поворот экрана, скрытие адресной строки).
test('после сбоя сети чужой профиль не обзаводится оборотом', async () => {
    const r = await run({ self: false, failAfter: 1 });
    assert.deepEqual(r.boom, [], 'первая загрузка успешна');

    await r.changeMonth('2026-08');
    assert.deepEqual(r.boom, [], 'сбой обработан, а не уронил страницу');
    assert.deepEqual(r.readTable().head, ['День', 'Успешно', 'Отказ'], 'колонки оборота не появилось');

    // Перерисовка ПРОИЗОШЛА, а не «колонки нет, потому что таблицу вообще
    // перестали обновлять»: строки схлопнуты в заглушку, счётчики обнулены.
    const { rows } = r.readTable();
    assert.equal(rows.length, 1, 'дни без сделок схлопнуты в одну строку');
    assert.equal(rows[0][0], '—', 'заглушка пустого месяца');
    assert.ok(r.map.pfChart.classList.contains('is-error'), 'состояние ошибки отражено в классе');
});

// Ровно тот баг, который правка задевала: resize после сбоя сети снимал
// is-error и вместо сообщения «не удалось загрузить» подставлял «в этом
// месяце сделок не было» — то есть график начинал ВРАТЬ о причине пустоты
// вместо того, чтобы говорить о сбое сети.
test('resize после сбоя сети не подменяет ошибку на "сделок не было"', async () => {
    const r = await run({ self: false, failAfter: 1 });
    await r.changeMonth('2026-08');
    assert.match(r.map.pfChartEmpty.textContent, /удалось загрузить/,
        'до resize сообщение — про сбой сети');
    assert.ok(r.map.pfChart.classList.contains('is-error'));

    await r.fireResize();
    assert.match(r.map.pfChartEmpty.textContent, /удалось загрузить/,
        'после resize сообщение остаётся про сбой сети, а не подменяется на пустой месяц');
    assert.ok(r.map.pfChart.classList.contains('is-error'), 'класс ошибки не снят resize-ом');
});

// Адресата графика печатает сервер (data-profile). Разбирать адресную
// строку скрипт не должен: PHP и регулярка читают ?id=1&id=2 по-разному, и
// карточка показывала бы одного человека, а график другого.
test('график спрашивает у эндпоинта того, кого назвал сервер', async () => {
    const peer = await run({ self: false });
    assert.match(peer.calls[0], /id=900000004/);
    const mine = await run({ self: true });
    assert.match(mine.calls[0], /id=900000001/, 'свой профиль тоже шлёт id — по нему сервер и решает');
});
