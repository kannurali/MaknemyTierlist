// Unit tests for the ads between posts in the news and trading feeds.
// Run: node --test tests/promo_feed_test.mjs
//
// The owner's rule (2026-09-27): an ad every 3-4 posts, and the top of the
// feed always shows 2-3 real posts first. Positions are counted from the top
// of whatever the feed shows right now, so a freshly published post pushes
// the posts down under the same ad slots instead of carrying an ad with it.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const read = rel => readFileSync(new URL('../public_html/' + rel, import.meta.url), 'utf8').replace(/\r\n/g, '\n');
const PROMO = require('../public_html/js/promo.js');
const FEED = require('../public_html/js/promo-feed.js');
const I18N = require('../public_html/js/i18n.js');

const { gaps, positions, campaigns, copy, FIRST, EVERY } = FEED;

// A deterministic stand-in for Math.random. Small seeds are spread over the
// whole range first: seed * 48271 alone stays near zero for seeds below
// ~44000, and every "random" first roll would then be the lowest one.
function lcg(seed) {
    let s = (seed * 2654435761) % 2147483647;
    if (s <= 0) s += 2147483646;
    return () => (s = (s * 48271) % 2147483647) / 2147483647;
}

// ------------------------------------------------------------ positions

test('the lowest roll puts the first ad after 2 posts, then every 3', () => {
    assert.deepEqual(positions(10, gaps(() => 0)), [2, 5, 8]);
});

test('the highest roll puts the first ad after 3 posts, then every 4', () => {
    assert.deepEqual(positions(12, gaps(() => 0.9999)), [3, 7, 11]);
});

test('an ad stands only between posts, never after the last one', () => {
    const gap = gaps(() => 0.9999);
    assert.deepEqual(positions(11, gap), [3, 7]);
    assert.deepEqual(positions(3, gap), []);
    assert.deepEqual(positions(4, gap), [3]);
    assert.deepEqual(positions(2, gaps(() => 0)), []);
    assert.deepEqual(positions(0, gap), []);
    assert.deepEqual(positions(-5, gap), []);
    assert.deepEqual(positions('junk', gap), []);
});

test('whatever the roll, the top has 2-3 posts and the gaps are 3-4', () => {
    assert.deepEqual(FIRST, [2, 3]);
    assert.deepEqual(EVERY, [3, 4]);
    for (let seed = 1; seed <= 300; seed++) {
        const at = positions(60, gaps(lcg(seed)));
        assert.ok(at.length >= 14, `seed ${seed}: ${at.length} ads in 60 posts`);
        assert.ok(at[0] === 2 || at[0] === 3, `seed ${seed}: first ad after ${at[0]}`);
        for (let i = 1; i < at.length; i++) {
            const step = at[i] - at[i - 1];
            assert.ok(step === 3 || step === 4, `seed ${seed}: gap ${step}`);
        }
    }
});

test('both lengths of a gap actually occur', () => {
    const seen = new Set();
    const firsts = new Set();
    for (let seed = 1; seed <= 50; seed++) {
        const at = positions(40, gaps(lcg(seed)));
        firsts.add(at[0]);
        for (let i = 1; i < at.length; i++) seen.add(at[i] - at[i - 1]);
    }
    assert.deepEqual([...firsts].sort(), [2, 3]);
    assert.deepEqual([...seen].sort(), [3, 4]);
});

test('one page view keeps its rhythm: re-renders and "load more" do not move ads', () => {
    const gap = gaps(lcg(7));
    const first = positions(12, gap);
    assert.deepEqual(positions(12, gap), first, 'same list, same slots');
    const longer = positions(40, gap);
    assert.deepEqual(longer.slice(0, first.length), first, 'more posts only add slots below');
});

test('slots are counted from the top, so a new post never lands under an ad at the top', () => {
    const gap = gaps(lcg(11));
    const before = positions(9, gap);
    const after = positions(10, gap);
    assert.equal(after[0], before[0], 'the first ad still stands after the same number of posts');
    assert.ok(after[0] >= 2 && after[0] <= 3);
});

test('a broken random source still gives valid slots', () => {
    assert.deepEqual(positions(10, gaps(() => NaN)), [2, 5, 8]);
    assert.deepEqual(positions(10, gaps(() => -1)), [2, 5, 8]);
    assert.deepEqual(positions(12, gaps(() => 5)), [3, 7, 11]);
    assert.ok(positions(10, gaps()).length >= 2, 'no source falls back to Math.random');
});

// ------------------------------------------------------------ campaigns

const NOW = Date.UTC(2026, 8, 27, 12);

function doc(...list) {
    return {
        v: 1, rev: 3, campaigns: list.map((over, i) => Object.assign({
            id: 'c' + i, name: 'Shop ' + i, advertiser: 'Shop ' + i, enabled: true, weight: 1,
            href: 'https://shop.example/' + i, slots: ['strip'],
            creatives: { strip: { src: '/images/s' + i + '.webp', w: 1200, h: 300 } }
        }, over))
    };
}

test('with nothing sold the feed shows the house banner of that page', () => {
    for (const page of ['news', 'trading']) {
        const list = campaigns(null, page, NOW);
        assert.equal(list.length, 1, page);
        assert.equal(list[0].id, PROMO.houseFor('strip', NOW, page).id, page);
        assert.ok(PROMO.creativeFor(list[0], 'strip'), page + ': the house banner has a strip picture');
    }
});

test('from 3 October Playerok owns the trading feed, the news feed stays without it', () => {
    const run = Date.UTC(2026, 9, 5, 12);
    assert.notEqual(campaigns(null, 'news', run)[0].id, PROMO.PLAYEROK.id, 'news');
    assert.notEqual(campaigns(doc({}), 'news', run)[0].id, PROMO.PLAYEROK.id, 'news, with a sold banner');
    assert.deepEqual(campaigns(null, 'trading', run).map(c => c.id), [PROMO.PLAYEROK.id], 'trading');
    assert.deepEqual(campaigns(doc({}), 'trading', run).map(c => c.id), [PROMO.PLAYEROK.id],
        'trading: Playerok outranks a banner sold through the admin panel');
    assert.notEqual(campaigns(null, 'trading', NOW)[0].id, PROMO.PLAYEROK.id, 'trading before 3 October');
});

test('a sold strip banner takes every feed slot away from the house banner', () => {
    const list = campaigns(doc({}), 'news', NOW);
    assert.deepEqual(list.map(c => c.id), ['c0']);
});

test('several sold banners take turns, one advertiser never twice in a row', () => {
    const d = doc({ advertiser: 'A' }, { advertiser: 'A' }, { advertiser: 'B' });
    const list = campaigns(d, 'calc', NOW, lcg(5));
    assert.equal(list.length, 3);
    for (let i = 1; i < list.length; i++) {
        assert.ok(list[i].advertiser !== list[i - 1].advertiser || list.every(c => c.advertiser === 'A'));
    }
});

test('page targeting, slots and dates still decide who gets the feed', () => {
    assert.equal(campaigns(doc({ pages: ['tierlist'] }), 'news', NOW)[0].id,
        PROMO.houseFor('strip', NOW, 'news').id, 'bought for the tier list only');
    assert.deepEqual(campaigns(doc({ pages: ['news'] }), 'news', NOW).map(c => c.id), ['c0']);
    assert.notEqual(campaigns(doc({ pages: ['news'] }), 'trading', NOW)[0].id, 'c0', 'news-only stays off trading');
    assert.deepEqual(campaigns(doc({ pages: ['trading'] }), 'trading', NOW).map(c => c.id), ['c0']);
    assert.notEqual(campaigns(doc({ pages: ['trading'] }), 'calc', NOW)[0].id, 'c0',
        'trading-only stays off the calculator');
    assert.notEqual(campaigns(doc({ slots: ['rail'], creatives: { rail: { src: '/r.webp' } } }), 'news', NOW)[0].id,
        'c0', 'a rail-only campaign has no banner for the feed');
    assert.notEqual(campaigns(doc({ end: '2026-09-01' }), 'news', NOW)[0].id, 'c0', 'expired');
    assert.notEqual(campaigns(doc({ enabled: false }), 'news', NOW)[0].id, 'c0', 'switched off');
});

test('no promo module means no feed ads at all', () => {
    const saved = globalThis.PROMO;
    try {
        delete globalThis.PROMO;
        assert.deepEqual(campaigns(doc({}), 'news', NOW), []);
    } finally {
        globalThis.PROMO = saved;
    }
});

// ------------------------------------------------------------ copy

const tx = lang => key => I18N.t(key, lang);

test('the trading header reads the house copy from the dictionary', () => {
    const house = PROMO.HOUSE_GIVEAWAY;
    assert.deepEqual(copy(house, tx('ru')), {
        text: I18N.t(house.textKey, 'ru'), cta: I18N.t(house.ctaKey, 'ru')
    });
    assert.equal(copy(house, tx('en')).cta, I18N.t(house.ctaKey, 'en'));
});

test('every house banner of the feed has its header copy in both languages', () => {
    for (const house of [PROMO.HOUSE_SLOT, PROMO.HOUSE_GIVEAWAY]) {
        for (const lang of ['ru', 'en']) {
            const c = copy(house, tx(lang));
            assert.ok(c.text && c.text !== house.textKey, `${house.id} ${lang}: text`);
            assert.ok(c.cta && c.cta !== house.ctaKey, `${house.id} ${lang}: button`);
        }
    }
});

test('a sold campaign speaks with its own text, then its advertiser name', () => {
    const [own] = PROMO.normalizeDoc(doc({ text: 'Скидки', cta: 'Купить' })).campaigns;
    assert.deepEqual(copy(own, tx('ru')), { text: 'Скидки', cta: 'Купить' });
    const [bare] = PROMO.normalizeDoc(doc({ advertiser: 'Shop' })).campaigns;
    assert.deepEqual(copy(bare, tx('ru')), { text: 'Shop', cta: I18N.t('promo.cta', 'ru') });
});

test('a sold campaign cannot borrow site copy through a dictionary key', () => {
    const [c] = PROMO.normalizeDoc(doc({ textKey: 'promo.giveawayText', text: 'own' })).campaigns;
    assert.equal(copy(c, tx('ru')).text, 'own');
});

test('an empty slot has no copy', () => {
    assert.deepEqual(copy(null, tx('ru')), { text: '', cta: '' });
});

// ------------------------------------------------------------ wiring

test('both feeds load the module after promo.js and before their own script', () => {
    for (const [page, own] of [['news.php', 'js/news-page.js'], ['trading.php', 'js/trading-page.js']]) {
        const html = read(page);
        const at = ['js/promo.js?v=', 'js/promo-feed.js?v=', own + '?v='].map(s => html.indexOf('src="' + s));
        assert.ok(at.every(i => i >= 0), page + ': all three scripts are there');
        assert.ok(at[0] < at[1] && at[1] < at[2], page + ': promo.js, then the feed module, then the page');
    }
});

test('the news feed puts banners between news cards, trading puts cards between offers', () => {
    const news = read('js/news-page.js');
    assert.ok(news.includes('build: NX_PROMO_FEED.banner'));
    assert.ok(news.includes('feedAds.place(feedEl, ".nw-card")'));
    assert.ok(news.includes('feedAds.setDoc(doc)'));
    const trading = read('js/trading-page.js');
    assert.ok(trading.includes('build: NX_PROMO_FEED.card'));
    assert.ok(trading.includes('feedAds.place(feed, ".tr-card")'));
    assert.ok(trading.includes('feedAds.setDoc(doc)'));
});

test('an empty slot already has the banner height, so filling it moves nothing', () => {
    const rule = (css, sel) => {
        const hit = css.split('}').map(b => b.split('{')).find(([head]) => head.trim() === sel);
        return hit ? hit[1] : '';
    };
    assert.match(rule(read('css/news-design.css'), '.nw-ptn-in'), /aspect-ratio: 4 \/ 1;/);
    assert.match(rule(read('css/trading.css'), '.tr-ptn-media'), /aspect-ratio: 4 \/ 1;/);
});

test('the module ships without comments, innerHTML or ad-blocker bait in class names', () => {
    const js = read('js/promo-feed.js');
    assert.doesNotMatch(js, /(^|[^:"'])\/\/\s/m);
    assert.doesNotMatch(js, /\/\*/);
    assert.ok(!js.includes('innerHTML'));
    const classes = [...js.matchAll(/(?:el\("[a-z]+", |frame\(camp, env, |picture\(camp, env, )"([^"]+)"/g)]
        .map(m => m[1]);
    assert.ok(classes.length >= 12, 'class names found: ' + classes.length);
    for (const c of classes) {
        assert.doesNotMatch(c, /(^|[-_\s])(ad|ads|advert\w*|banner\w*|sponsor\w*)([-_\s]|$)/i, c);
    }
});
