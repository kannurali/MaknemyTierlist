// Unit tests for the giveaway cards on the site pages (js/gw-card.js).
// Run: node --test tests/gw_card_test.mjs
//
// The owner's brief (2026-10-18): one card of the running giveaway per place —
// tier list top and middle, trades feed after the second listing, calculator,
// stock, news — and per-place views, clicks and entries.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const GWC = require('../public_html/js/gw-card.js');
const I18N = require('../public_html/js/i18n.js');

const NOW = 1790000000;
const live = (id, extra = {}) => ({ id, phase: 'live', ends_at: NOW + 3600, prizes: [{ name: 'Kitsune Fruit', count: 1 }], ...extra });

test('places match the server list and become c-* sources', () => {
  assert.deepEqual(GWC.PLACES, ['tier', 'trade', 'calc', 'stock', 'news', 'hunt']);
  GWC.PLACES.forEach(p => {
    const src = GWC.src(p);
    assert.match(src, /^c-[a-z]{3,6}$/, 'giveaway.js accepts it');
    assert.ok(src.length <= 8, 'fits giveaway_entries.src VARCHAR(8)');
  });
});

test('choose: every running giveaway the visitor has not joined, all of them when every one is joined', () => {
  const d = { live: [live(1), live(2), live(5), live(3, { ends_at: NOW - 1 }), live(4, { phase: 'ended' })] };
  const ids = p => p.list.map(e => e.g.id);
  const all = GWC.choose(d, () => false, NOW);
  assert.deepEqual(ids(all), [1, 2, 5], 'owner 2026-11-10: the card goes round every live giveaway');
  assert.equal(all.count, 3);
  assert.ok(all.list.every(e => e.joined === false));
  assert.deepEqual(ids(GWC.choose(d, id => id === 2, NOW)), [1, 5], 'skips the joined one');
  const joined = GWC.choose(d, () => true, NOW);
  assert.deepEqual(ids(joined), [1, 2, 5], 'all joined — still shows them, marked');
  assert.ok(joined.list.every(e => e.joined === true));
  assert.equal(GWC.choose({ live: [] }, () => false, NOW), null, 'nothing running — no card');
  assert.equal(GWC.choose(null, () => false, NOW), null);
  assert.equal(GWC.choose({ live: [live(3, { ends_at: NOW })] }, () => false, NOW), null, 'ended exactly now');
});

test('turn: the round starts at another giveaway, order kept', () => {
  const p = GWC.choose({ live: [live(1), live(2), live(3)] }, () => false, NOW);
  const ids = q => q.list.map(e => e.g.id);
  assert.deepEqual(ids(GWC.turn(p, 0)), [1, 2, 3]);
  assert.deepEqual(ids(GWC.turn(p, 1)), [2, 3, 1]);
  assert.deepEqual(ids(GWC.turn(p, 5)), [3, 1, 2]);
  assert.equal(GWC.turn(p, 1).count, 3);
  assert.deepEqual(ids(p), [1, 2, 3], 'the pick itself is not touched');
  assert.equal(GWC.turn(null, 2), null);
});

test('slides: every prize of every giveaway, repeats folded, a giveaway without prizes shows its title', () => {
  const venom = { name: 'Venom', count: 1, icon: '/i/v.webp' };
  const d = { live: [
    live(8, { prizes: [venom, venom, { name: 'Hellfire', count: 1, icon: '/i/h.png' }] }),
    live(2, { prizes: [{ name: 'Werewolf', count: 1, icon: '' }, { name: 'Tiger Fruit', count: 2, icon: '' }] }),
    live(9, { prizes: [], title: { ru: 'Сюрприз' } })
  ] };
  const p = GWC.choose(d, () => false, NOW);
  const s = GWC.slides(p, g => g.title.ru);
  assert.deepEqual(s.map(x => [x.g.id, x.text]), [
    [8, '2 × Venom'], [8, 'Hellfire'], [2, 'Werewolf'], [2, '2 × Tiger Fruit'], [9, 'Сюрприз']
  ]);
  assert.equal(s[0].prize.icon, '/i/v.webp', 'the picture goes with its prize');
  assert.equal(s[4].prize, null, 'no prize — the gift picture');
  assert.deepEqual(GWC.slides(null, () => ''), []);
});

test('feedRef: after the second listing, after the third when an ad already sits there', () => {
  const node = name => ({ name, nextElementSibling: null, ad: name.startsWith('ad') });
  const chain = list => { list.forEach((n, i) => { n.nextElementSibling = list[i + 1] || null; }); return list; };
  const isAd = n => n.ad;
  let [t1, t2, t3] = chain([node('t1'), node('t2'), node('t3')]);
  assert.equal(GWC.feedRef([t1, t2, t3], isAd, null).after, t2);

  const ad = node('ad1');
  [t1, t2] = [node('t1'), node('t2')];
  t3 = node('t3');
  chain([t1, t2, ad, t3]);
  assert.equal(GWC.feedRef([t1, t2, t3], isAd, null).after, t3, 'ad right after the second listing — move past it');

  const self = node('card');
  [t1, t2, t3] = [node('t1'), node('t2'), node('t3')];
  const ad2 = node('ad2');
  chain([t1, t2, self, ad2, t3]);
  assert.equal(GWC.feedRef([t1, t2, t3], isAd, self).after, t3, 'its own node does not hide the ad');

  [t1] = [node('t1')];
  assert.equal(GWC.feedRef([t1], isAd, null).after, t1, 'one listing — after it');
  assert.equal(GWC.feedRef([], isAd, null), null, 'empty feed — no card');
});

test('prizeText: count × name, title when there are no prizes', () => {
  const title = g => g.title.ru;
  assert.equal(GWC.prizeText({ prizes: [{ name: 'Permanent Magnet', count: 5 }] }, title), '5 × Permanent Magnet');
  assert.equal(GWC.prizeText({ prizes: [{ name: 'Kitsune Fruit', count: 1 }] }, title), 'Kitsune Fruit');
  assert.equal(GWC.prizeText({ prizes: [], title: { ru: 'Розыгрыш' } }, title), 'Розыгрыш');
});

test('prizeItems: every item once, in prize order, repeats folded into one with the total count', () => {
  const g = { prizes: [
    { name: 'Venom', count: 1, icon: '' },
    { name: 'Gas', count: 2, icon: '/i/gas.webp' },
    { name: ' venom ', count: 2, icon: '/i/venom.webp' },
    { name: '', count: 3, icon: '/i/x.webp' },
    { name: 'Gas', count: 0, icon: '' }
  ] };
  assert.deepEqual(GWC.prizeItems(g), [
    { name: 'Venom', count: 3, icon: '/i/venom.webp' },
    { name: 'Gas', count: 3, icon: '/i/gas.webp' }
  ]);
  assert.deepEqual(GWC.prizeItems(g).map(GWC.itemText), ['3 × Venom', '3 × Gas']);
  assert.deepEqual(GWC.prizeItems({ prizes: [{ name: 'Kitsune Fruit', count: 1 }] }).map(GWC.itemText), ['Kitsune Fruit']);
  assert.deepEqual(GWC.prizeItems({ prizes: [] }), []);
  assert.deepEqual(GWC.prizeItems(null), []);
  assert.deepEqual(GWC.prizeItems({ prizes: [{ name: '__proto__', count: 1 }, { name: '__proto__', count: 1 }] }),
    [{ name: '__proto__', count: 2, icon: '' }], 'odd names are plain keys');
  assert.equal(g.prizes[0].count, 1, 'the giveaway itself is not touched');
});

test('i18n: every gwc key exists in both languages', () => {
  const keys = ['gwc.kicker', 'gwc.tierLine', 'gwc.endsAt', 'gwc.endsAtDash', 'gwc.endsCount',
    'gwc.people.one', 'gwc.people.few', 'gwc.people.many', 'gwc.more.one', 'gwc.more.few', 'gwc.more.many',
    'gwc.see', 'gwc.open', 'gwc.watch', 'gwc.seeAll', 'gwc.tradeHead', 'gwc.tradeLead', 'gwc.calcText',
    'gwc.stockText', 'gwc.stockTextOne', 'gwc.count'];
  for (const lang of ['ru', 'en']) {
    for (const k of keys) { assert.notEqual(I18N.t(k, lang), k, `${lang}: ${k}`); }
  }
  assert.equal(I18N.t('gwc.tradeLead', 'ru', { prize: 'Kitsune Fruit' }), 'Разыгрываем Kitsune Fruit.');
  assert.equal(I18N.t('gwc.endsCount', 'ru', { n: I18N.t('gwc.people.many', 'ru', { n: '1 000' }) }), 'Итоги на 1 000 участников');
});
