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
  assert.deepEqual(GWC.PLACES, ['tier', 'trade', 'calc', 'stock', 'news']);
  GWC.PLACES.forEach(p => {
    const src = GWC.src(p);
    assert.match(src, /^c-[a-z]{3,6}$/, 'giveaway.js accepts it');
    assert.ok(src.length <= 8, 'fits giveaway_entries.src VARCHAR(8)');
  });
});

test('choose: the first running giveaway the visitor has not joined', () => {
  const d = { live: [live(1), live(2), live(3, { ends_at: NOW - 1 }), live(4, { phase: 'ended' })] };
  assert.deepEqual(GWC.choose(d, () => false, NOW), { g: d.live[0], joined: false, count: 2 });
  assert.equal(GWC.choose(d, id => id === 1, NOW).g.id, 2, 'skips the joined one');
  const all = GWC.choose(d, () => true, NOW);
  assert.equal(all.g.id, 1, 'all joined — still shows the first');
  assert.equal(all.joined, true);
  assert.equal(GWC.choose({ live: [] }, () => false, NOW), null, 'nothing running — no card');
  assert.equal(GWC.choose(null, () => false, NOW), null);
  assert.equal(GWC.choose({ live: [live(3, { ends_at: NOW })] }, () => false, NOW), null, 'ended exactly now');
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
