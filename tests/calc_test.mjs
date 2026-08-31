// Unit tests for the trade-calculator logic. Run: node --test tests/calc_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const CALC = require('../public_html/js/calc.js');

const {
    parseValue, itemValue, flattenTierlist, buildCatalogIndex,
    canAddToSide, addToSide, removeOneFromSide, sideTotal, demandBalance, demandBucket, computeTrade,
    encodeSide, decodeSide, encodeShareQuery, decodeShareQuery,
    badgeCodeFor, MAX_SLOTS,
} = CALC;

function item(id, value, demand) {
    return { id, name: id, value: String(value), icon: '/images/x.webp', type: 'f', demand: demand || 'green' };
}

// --------------------------------------------------------------------------
//  parseValue — включая мусор
// --------------------------------------------------------------------------

test('parseValue reads a clean integer string, the shape every live item has', () => {
    assert.equal(parseValue('45000'), 45000);
    assert.equal(parseValue('0'), 0);
});

test('parseValue accepts a numeric value and a decimal string', () => {
    assert.equal(parseValue(1234), 1234);
    assert.equal(parseValue('12.5'), 12.5);
    assert.equal(parseValue('-3'), -3);
});

test('parseValue rejects junk instead of guessing', () => {
    for (const junk of ['12abc', '1,234', '', '   ', 'Infinity', 'NaN', '0x10', '1e10', null, undefined, {}, [], true]) {
        assert.equal(parseValue(junk), null, `expected null for ${JSON.stringify(junk)}`);
    }
});

test('itemValue falls back to 0 for an unparsable value instead of poisoning the sum', () => {
    assert.equal(itemValue({ value: 'garbage' }), 0);
    assert.equal(itemValue(null), 0);
    assert.equal(itemValue({ value: '500' }), 500);
});

// --------------------------------------------------------------------------
//  Catalog helpers
// --------------------------------------------------------------------------

test('flattenTierlist pulls every item out of every tier, tolerating a broken shape', () => {
    const data = { tiers: [{ items: [item('a', 1)] }, { items: [item('b', 2)] }] };
    assert.deepEqual(flattenTierlist(data).map(i => i.id), ['a', 'b']);
    assert.deepEqual(flattenTierlist(null), []);
    assert.deepEqual(flattenTierlist({}), []);
    assert.deepEqual(flattenTierlist({ tiers: 'nope' }), []);
    assert.deepEqual(flattenTierlist({ tiers: [null, { items: null }, { items: [null, item('c', 3)] }] }).map(i => i.id), ['c']);
});

test('buildCatalogIndex is immune to prototype-pollution-shaped ids', () => {
    const idx = buildCatalogIndex([item('constructor', 1), item('toString', 2)]);
    assert.equal(idx.constructor.value, '1');
    assert.equal(idx.toString.value, '2');
});

// --------------------------------------------------------------------------
//  Side state transitions
// --------------------------------------------------------------------------

test('adding the same item twice shows a count instead of a duplicate row', () => {
    const dragon = item('d1', 1000);
    let side = addToSide([], dragon);
    side = addToSide(side, dragon);
    assert.equal(side.length, 1, 'one row, not two');
    assert.equal(side[0].count, 2);
});

test('adding a different item appends a new row', () => {
    let side = addToSide([], item('a', 1));
    side = addToSide(side, item('b', 2));
    assert.equal(side.length, 2);
});

test('removing one instance decrements the count, and removes the row at zero', () => {
    const dragon = item('d1', 1000);
    let side = addToSide(addToSide([], dragon), dragon); // count 2
    side = removeOneFromSide(side, 'd1');
    assert.equal(side[0].count, 1);
    side = removeOneFromSide(side, 'd1');
    assert.deepEqual(side, []);
});

test('removing from an empty side does not throw', () => {
    assert.deepEqual(removeOneFromSide([], 'nope'), []);
});

// --------------------------------------------------------------------------
//  6-slot cap — the redesign's hard cap on distinct items per side
// --------------------------------------------------------------------------

test('MAX_SLOTS is 6, matching the 2x3 slot grid the page renders', () => {
    assert.equal(MAX_SLOTS, 6);
});

test('canAddToSide allows a new item while under the cap', () => {
    let side = [];
    for (let i = 0; i < 5; i++) side = addToSide(side, item('i' + i, 100));
    assert.equal(canAddToSide(side, item('new', 100)), true);
});

test('canAddToSide refuses a 7th distinct item once all 6 slots are taken', () => {
    let side = [];
    for (let i = 0; i < 6; i++) side = addToSide(side, item('i' + i, 100));
    assert.equal(side.length, 6);
    assert.equal(canAddToSide(side, item('seventh', 100)), false);
});

test('canAddToSide still allows stacking a count on an item already occupying a slot at the cap', () => {
    let side = [];
    for (let i = 0; i < 6; i++) side = addToSide(side, item('i' + i, 100));
    assert.equal(canAddToSide(side, item('i0', 100)), true); // same id — no new slot needed
});

// --------------------------------------------------------------------------
//  Totals
// --------------------------------------------------------------------------

test('sideTotal sums value times count', () => {
    const side = [{ item: item('a', 1000), count: 2 }, { item: item('b', 500), count: 1 }];
    assert.equal(sideTotal(side), 2500);
});

test('sideTotal of an empty side is 0', () => {
    assert.equal(sideTotal([]), 0);
});

// --------------------------------------------------------------------------
//  Verdict — boundary at +/-5% on both sides, and the empty-side case
// --------------------------------------------------------------------------

test('equal totals are Fair with zero difference', () => {
    const t = computeTrade([{ item: item('a', 1000), count: 1 }], [{ item: item('b', 1000), count: 1 }]);
    assert.equal(t.verdict, 'fair');
    assert.equal(t.diffAbs, 0);
    assert.equal(t.diffPct, 0);
});

test('exactly +5% (getting more) is still Fair — the boundary is inclusive', () => {
    const t = computeTrade([{ item: item('a', 1000), count: 1 }], [{ item: item('b', 1050), count: 1 }]);
    assert.equal(t.diffPct, 5);
    assert.equal(t.verdict, 'fair');
});

test('just over +5% flips to Win', () => {
    const t = computeTrade([{ item: item('a', 1000), count: 1 }], [{ item: item('b', 1051), count: 1 }]);
    assert.ok(t.diffPct > 5);
    assert.equal(t.verdict, 'win');
});

test('exactly -5% (getting less) is still Fair', () => {
    const t = computeTrade([{ item: item('a', 1000), count: 1 }], [{ item: item('b', 950), count: 1 }]);
    assert.equal(t.diffPct, -5);
    assert.equal(t.verdict, 'fair');
});

test('just under -5% flips to Lose', () => {
    const t = computeTrade([{ item: item('a', 1000), count: 1 }], [{ item: item('b', 949), count: 1 }]);
    assert.ok(t.diffPct < -5);
    assert.equal(t.verdict, 'lose');
});

test('both sides empty is Fair with a 0/0 difference, not a crash', () => {
    const t = computeTrade([], []);
    assert.equal(t.leftTotal, 0);
    assert.equal(t.rightTotal, 0);
    assert.equal(t.diffPct, 0);
    assert.equal(t.verdict, 'fair');
});

test('giving nothing and receiving something is an unambiguous Win, no division by zero', () => {
    const t = computeTrade([], [{ item: item('b', 500), count: 1 }]);
    assert.equal(t.verdict, 'win');
    assert.equal(Number.isFinite(t.diffPct), true);
});

test('giving something and receiving nothing is an unambiguous Lose', () => {
    const t = computeTrade([{ item: item('a', 500), count: 1 }], []);
    assert.equal(t.verdict, 'lose');
    assert.equal(Number.isFinite(t.diffPct), true);
});

// --------------------------------------------------------------------------
//  Demand note — advisory only, never touches totals/verdict
// --------------------------------------------------------------------------

test('a fair trade where you receive mostly low-demand items gets a note', () => {
    const left = [{ item: item('a', 1000, 'green'), count: 1 }];
    const right = [{ item: item('b', 1000, 'red'), count: 1 }];
    const t = computeTrade(left, right);
    assert.equal(t.verdict, 'fair');
    assert.equal(t.demandNote, 'receiveLow');
    // Демонстрирует требование ТЗ: спрос не трогает арифметику.
    assert.equal(t.leftTotal, 1000);
    assert.equal(t.rightTotal, 1000);
});

test('the mirror case (you give away the low-demand side) gets the opposite note', () => {
    const left = [{ item: item('a', 1000, 'red'), count: 1 }];
    const right = [{ item: item('b', 1000, 'green'), count: 1 }];
    const t = computeTrade(left, right);
    assert.equal(t.demandNote, 'giveLow');
});

test('similar demand profiles produce no note even on a fair trade', () => {
    const left = [{ item: item('a', 1000, 'green'), count: 1 }];
    const right = [{ item: item('b', 1000, 'yellow'), count: 1 }];
    const t = computeTrade(left, right);
    assert.equal(t.demandNote, null);
});

test('no note outside a Fair verdict — a clear win already says enough', () => {
    const left = [{ item: item('a', 100, 'green'), count: 1 }];
    const right = [{ item: item('b', 1000, 'red'), count: 1 }];
    const t = computeTrade(left, right);
    assert.equal(t.verdict, 'win');
    assert.equal(t.demandNote, null);
});

test('demandBalance is null for an empty side, not NaN', () => {
    assert.equal(demandBalance([]), null);
});

// --------------------------------------------------------------------------
//  demandBucket — the side's aggregate demand meter, bucketed into the same
//  four colours as the per-item demand dot
// --------------------------------------------------------------------------

test('demandBucket maps a null/non-finite balance to null, not a crash', () => {
    assert.equal(demandBucket(null), null);
    assert.equal(demandBucket(undefined), null);
    assert.equal(demandBucket(NaN), null);
});

test('demandBucket sorts pure demand scores into their own colour', () => {
    assert.equal(demandBucket(2), 'green');
    assert.equal(demandBucket(1), 'yellow');
    assert.equal(demandBucket(-1), 'orange');
    assert.equal(demandBucket(-2), 'red');
});

test('demandBucket boundaries sit at the midpoints between the four weights', () => {
    assert.equal(demandBucket(1.5), 'green');
    assert.equal(demandBucket(1.49), 'yellow');
    assert.equal(demandBucket(0), 'yellow');
    assert.equal(demandBucket(-0.01), 'orange');
    assert.equal(demandBucket(-1.5), 'orange');
    assert.equal(demandBucket(-1.51), 'red');
});

// --------------------------------------------------------------------------
//  badgeCodeFor — raw item.type -> the legend's two-letter badge code
//  (index.php's redesigned legend is the source of truth for the codes/colours)
// --------------------------------------------------------------------------

test('badgeCodeFor maps every raw type value seen in production data', () => {
    assert.equal(badgeCodeFor('f'), 'fr');
    assert.equal(badgeCodeFor(''), 'fr');
    assert.equal(badgeCodeFor('p'), 'pm');
    assert.equal(badgeCodeFor('s'), 'cs');
    assert.equal(badgeCodeFor('m'), 'cm');
    assert.equal(badgeCodeFor('gp'), 'gp');
    assert.equal(badgeCodeFor('cr'), 'cr');
    assert.equal(badgeCodeFor('v'), 'vh');
});

test('badgeCodeFor passes through a legend code stored directly, case-insensitively', () => {
    assert.equal(badgeCodeFor('MS'), 'ms');
    assert.equal(badgeCodeFor('vh'), 'vh');
});

test('badgeCodeFor falls back to the plain-fruit badge for junk instead of throwing', () => {
    assert.equal(badgeCodeFor('nope'), 'fr');
    assert.equal(badgeCodeFor(null), 'fr');
    assert.equal(badgeCodeFor(undefined), 'fr');
    assert.equal(badgeCodeFor(123), 'fr');
});

// --------------------------------------------------------------------------
//  Share-link round trip
// --------------------------------------------------------------------------

test('encodeSide then decodeSide restores the same items and counts', () => {
    const catalog = buildCatalogIndex([item('a', 100), item('b', 200)]);
    const side = [{ item: catalog.a, count: 3 }, { item: catalog.b, count: 1 }];
    const encoded = encodeSide(side);
    const decoded = decodeSide(encoded, catalog);
    assert.deepEqual(decoded.map(e => [e.item.id, e.count]), [['a', 3], ['b', 1]]);
});

test('encodeShareQuery / decodeShareQuery round-trip through URLSearchParams like a real link', () => {
    const catalog = buildCatalogIndex([item('a', 100), item('b', 200), item('c', 300)]);
    const left = [{ item: catalog.a, count: 2 }];
    const right = [{ item: catalog.b, count: 1 }, { item: catalog.c, count: 5 }];
    const query = encodeShareQuery(left, right);
    const params = new URLSearchParams(query);
    const restored = decodeShareQuery(params, catalog);
    assert.deepEqual(restored.left.map(e => [e.item.id, e.count]), [['a', 2]]);
    assert.deepEqual(restored.right.map(e => [e.item.id, e.count]), [['b', 1], ['c', 5]]);
});

test('repeating the same id in a share token sums the counts instead of overwriting', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    const decoded = decodeSide('a:2,a:3', catalog);
    assert.deepEqual(decoded.map(e => [e.item.id, e.count]), [['a', 5]]);
});

test('an out-of-range count is clamped, not rejected or overflowed', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    const decoded = decodeSide('a:2000', catalog); // regex caps digits, but clamp is a second line of defense
    assert.deepEqual(decoded, []); // 2000 has 4 digits — the token itself does not match the strict format
});

// --------------------------------------------------------------------------
//  Hostile input -> empty state, never a throw, never an unknown item
// --------------------------------------------------------------------------

test('an id that is not in the catalog is silently dropped', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    assert.deepEqual(decodeSide('ghost:1', catalog), []);
});

test('garbage with no valid tokens at all yields an empty side, not an error', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    const hostileInputs = [
        '<script>alert(1)</script>',
        '../../etc/passwd',
        'a:0', 'a:-1', 'a:abc', ':1', 'a:', ',,,,',
        'a'.repeat(5000) + ':1',
    ];
    for (const raw of hostileInputs) {
        assert.doesNotThrow(() => decodeSide(raw, catalog));
        assert.deepEqual(decodeSide(raw, catalog), [], `expected empty for ${JSON.stringify(raw).slice(0, 40)}`);
    }
});

test('a non-string, null, or missing catalog never throws', () => {
    assert.doesNotThrow(() => decodeSide(null, {}));
    assert.doesNotThrow(() => decodeSide(undefined, {}));
    assert.doesNotThrow(() => decodeSide(123, {}));
    assert.doesNotThrow(() => decodeSide('a:1', null));
    assert.deepEqual(decodeSide('a:1', null), []);
});

test('a hostile query object (no working .get) produces an empty calculator, not a crash', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    assert.doesNotThrow(() => decodeShareQuery(null, catalog));
    assert.doesNotThrow(() => decodeShareQuery({}, catalog));
    assert.doesNotThrow(() => decodeShareQuery('not even an object', catalog));
    assert.deepEqual(decodeShareQuery({}, catalog), { left: [], right: [] });
});

test('a real URLSearchParams built from a hostile raw query string is still safe', () => {
    const catalog = buildCatalogIndex([item('a', 100)]);
    const params = new URLSearchParams('l=<script>%00&r=../../a:1;DROP TABLE');
    const restored = decodeShareQuery(params, catalog);
    assert.deepEqual(restored, { left: [], right: [] });
});

// Значки легенды переименовывались уже дважды (fv -> fr вместе с legend в
// тирлисте), и калькулятор молча показывал пустой белый квадрат вместо значка
// у всех фруктов: <img> с несуществующим src не роняет страницу и ничего не
// пишет в консоль. Проверяем не список кодов, а НАЛИЧИЕ ФАЙЛОВ — иначе тест
// зелёный ровно до того момента, когда его надо было бы завалить.
test('каждому коду значка соответствует файл на диске', () => {
    for (const code of CALC.BADGE_CODES) {
        const rel = `../public_html/assets/design/legend/badge-${code}.svg`;
        assert.doesNotThrow(
            () => readFileSync(new URL(rel, import.meta.url)),
            `нет файла значка: badge-${code}.svg`,
        );
    }
});

// Тип из данных тирлиста обязан попадать в существующий значок, а не в
// дефолт: молчаливый откат к «фрукту» скрыл бы новый тип предмета.
test('каждый тип из тирлиста даёт код с существующим файлом', () => {
    for (const type of ['f', 'p', 's', 'm', 'gp', 'cr', 'v', '']) {
        const code = CALC.badgeCodeFor(type);
        assert.ok(CALC.BADGE_CODES.includes(code), `тип "${type}" дал код вне списка: ${code}`);
        assert.doesNotThrow(
            () => readFileSync(new URL(`../public_html/assets/design/legend/badge-${code}.svg`, import.meta.url)),
            `тип "${type}" -> badge-${code}.svg, файла нет`,
        );
    }
});
