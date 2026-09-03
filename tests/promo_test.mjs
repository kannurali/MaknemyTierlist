// Unit tests for the promo campaign logic. Run: node --test tests/promo_test.mjs
//
// This module decides what a paying advertiser actually gets shown, so the
// scheduling, rotation share and frequency cap are all asserted exactly.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const PROMO = require('../public_html/js/promo.js');

const {
    SLOTS, MAX_STRIP_SLIDES,
    safeHref, dayBoundsMsk, inWindow, eligible,
    pickWeighted, orderForCarousel,
    shouldShowPopup, recordPopupShown, recordPopupClicked,
    normalizeDoc, migrateLegacyAd,
    HOUSE_TG, HOUSE_SLOT, popupPick
} = PROMO;

const DAY = 24 * 60 * 60 * 1000;
const HOUR = 60 * 60 * 1000;

// A minimal campaign with everything the filters look at.
function camp(over = {}) {
    return normalizeDoc({
        campaigns: [Object.assign({
            id: 'c1', name: 'Test', advertiser: 'Shop', enabled: true, weight: 1,
            start: '', end: '', href: 'https://shop.example/', text: 'buy', cta: 'go',
            slots: ['strip'], creatives: { strip: { src: '/images/a.webp', w: 1200, h: 300 } }
        }, over)]
    }).campaigns[0];
}

// ---------------------------------------------------------------- safeHref

test('safeHref blocks dangerous schemes', () => {
    assert.equal(safeHref('javascript:alert(1)'), '');
    assert.equal(safeHref('JavaScript:alert(1)'), '');
    assert.equal(safeHref('data:text/html,<script>x</script>'), '');
    assert.equal(safeHref('vbscript:msgbox'), '');
    assert.equal(safeHref('file:///etc/passwd'), '');
});

test('safeHref passes and completes real addresses', () => {
    assert.equal(safeHref('https://shop.example/'), 'https://shop.example/');
    assert.equal(safeHref('http://shop.example'), 'http://shop.example');
    assert.equal(safeHref('shop.example'), 'https://shop.example');
    assert.equal(safeHref('//shop.example'), 'https://shop.example');
    assert.equal(safeHref('mailto:ad@shop.example'), 'mailto:ad@shop.example');
    assert.equal(safeHref('tel:+79990000000'), 'tel:+79990000000');
});

test('safeHref rejects the leftover placeholder and blank input', () => {
    // The admin form pre-fills "https://"; an untouched field is not a link.
    assert.equal(safeHref('https://'), '');
    assert.equal(safeHref('https:///'), '');
    assert.equal(safeHref(''), '');
    assert.equal(safeHref('   '), '');
    assert.equal(safeHref(null), '');
    assert.equal(safeHref(undefined), '');
});

test('safeHref rejects addresses containing whitespace or markup', () => {
    assert.equal(safeHref('https://a.com/ b'), '');
    assert.equal(safeHref('https://a.com/"onclick="x'), '');
});

// ------------------------------------------------------------ dayBoundsMsk

test('dayBoundsMsk anchors a date to Moscow midnight, not the visitor timezone', () => {
    // 2026-08-20 00:00:00 +03:00 == 2026-08-19 21:00:00 UTC
    const b = dayBoundsMsk('2026-08-20');
    assert.equal(b.startMs, Date.UTC(2026, 7, 19, 21, 0, 0, 0));
    assert.equal(b.endMs, Date.UTC(2026, 7, 20, 20, 59, 59, 999));
});

test('dayBoundsMsk keeps the same offset in winter (Russia has no DST)', () => {
    const b = dayBoundsMsk('2026-01-15');
    assert.equal(b.startMs, Date.UTC(2026, 0, 14, 21, 0, 0, 0));
});

test('dayBoundsMsk rejects malformed and impossible dates', () => {
    assert.equal(dayBoundsMsk('2026-02-31'), null);   // would roll over to March
    assert.equal(dayBoundsMsk('2026-13-01'), null);
    assert.equal(dayBoundsMsk('20.08.2026'), null);
    assert.equal(dayBoundsMsk('2026-8-20'), null);
    assert.equal(dayBoundsMsk(''), null);
    assert.equal(dayBoundsMsk(null), null);
});

// ---------------------------------------------------------------- inWindow

test('inWindow covers the whole start and end day inclusively', () => {
    const c = camp({ start: '2026-08-20', end: '2026-09-20' });
    const start = dayBoundsMsk('2026-08-20').startMs;
    const end = dayBoundsMsk('2026-09-20').endMs;

    assert.equal(inWindow(c, start - 1), false);
    assert.equal(inWindow(c, start), true);
    assert.equal(inWindow(c, end), true);
    assert.equal(inWindow(c, end + 1), false);
});

test('inWindow treats an empty bound as open-ended', () => {
    const now = Date.UTC(2026, 7, 20);
    assert.equal(inWindow(camp({ start: '', end: '' }), now), true);
    assert.equal(inWindow(camp({ start: '2020-01-01', end: '' }), now), true);
    assert.equal(inWindow(camp({ start: '', end: '2099-01-01' }), now), true);
    assert.equal(inWindow(camp({ start: '', end: '2020-01-01' }), now), false);
});

test('inWindow hides a campaign whose dates are unusable', () => {
    // normalizeDoc drops a malformed date to "", so feed a raw object: an
    // unparseable schedule must never be read as "runs forever".
    assert.equal(inWindow({ start: '2026-02-31', end: '' }, Date.UTC(2026, 7, 20)), false);
    assert.equal(inWindow(null, Date.UTC(2026, 7, 20)), false);
    assert.equal(inWindow(camp(), NaN), false);
});

// ---------------------------------------------------------------- eligible

test('eligible filters by enabled, slot list, creative and window', () => {
    const now = Date.UTC(2026, 7, 20, 12);
    const doc = {
        campaigns: [
            { id: 'ok', slots: ['strip'], creatives: { strip: { src: '/i/a.webp' } } },
            { id: 'off', enabled: false, slots: ['strip'], creatives: { strip: { src: '/i/b.webp' } } },
            { id: 'noSlot', slots: [], creatives: { strip: { src: '/i/c.webp' } } },
            { id: 'noCreative', slots: ['strip'], creatives: {} },
            { id: 'emptySrc', slots: ['strip'], creatives: { strip: { src: '  ' } } },
            { id: 'expired', slots: ['strip'], end: '2020-01-01', creatives: { strip: { src: '/i/d.webp' } } }
        ]
    };
    assert.deepEqual(eligible(doc, 'strip', now).map(c => c.id), ['ok']);
});

test('eligible separates slots so one campaign can buy only some placements', () => {
    const now = Date.UTC(2026, 7, 20);
    const doc = {
        campaigns: [{
            id: 'c1', slots: ['strip', 'popup'],
            creatives: { strip: { src: '/i/a.webp' }, rail: { src: '/i/b.webp' }, popup: { src: '/i/c.webp' } }
        }]
    };
    assert.equal(eligible(doc, 'strip', now).length, 1);
    assert.equal(eligible(doc, 'popup', now).length, 1);
    // The rail creative exists but the slot was not bought.
    assert.equal(eligible(doc, 'rail', now).length, 0);
});

test('eligible returns an empty array for junk input and unknown slots', () => {
    assert.deepEqual(eligible(null, 'strip', 0), []);
    assert.deepEqual(eligible({ campaigns: 'nope' }, 'strip', 0), []);
    assert.deepEqual(eligible({ campaigns: [{ id: 'c1', slots: ['strip'], creatives: { strip: { src: '/i/a.webp' } } }] }, 'sidebar', 0), []);
});

// ------------------------------------------------------------ pickWeighted

test('pickWeighted maps the random value onto the weight ranges', () => {
    const list = [{ id: 'a', weight: 3 }, { id: 'b', weight: 1 }];
    // total 4: [0,3) -> a, [3,4) -> b
    assert.equal(pickWeighted(list, 0).id, 'a');
    assert.equal(pickWeighted(list, 0.7).id, 'a');     // 2.8
    assert.equal(pickWeighted(list, 0.75).id, 'b');    // 3.0
    assert.equal(pickWeighted(list, 0.9999).id, 'b');
});

test('pickWeighted accepts a generator function as well as a number', () => {
    const list = [{ id: 'a', weight: 1 }, { id: 'b', weight: 1 }];
    assert.equal(pickWeighted(list, () => 0).id, 'a');
    assert.equal(pickWeighted(list, () => 0.6).id, 'b');
});

test('pickWeighted never returns undefined for degenerate input', () => {
    assert.equal(pickWeighted([], 0.5), null);
    assert.equal(pickWeighted(null, 0.5), null);
    // All-zero weights fall back to uniform rather than dropping out of the loop.
    const zeros = [{ id: 'a', weight: 0 }, { id: 'b', weight: 0 }];
    assert.equal(pickWeighted(zeros, 0).id, 'a');
    assert.equal(pickWeighted(zeros, 0.99).id, 'b');
    // Out-of-range randomness is clamped, not allowed to fall through.
    assert.equal(pickWeighted(zeros, 1).id, 'b');
    assert.equal(pickWeighted(zeros, -1).id, 'a');
    assert.equal(pickWeighted([{ id: 'a', weight: 'x' }], 0.5).id, 'a');
});

// -------------------------------------------------------- orderForCarousel

test('orderForCarousel shows every campaign once and is deterministic', () => {
    const list = [
        { id: 'a', advertiser: 'A', weight: 1 },
        { id: 'b', advertiser: 'B', weight: 1 },
        { id: 'c', advertiser: 'C', weight: 1 }
    ];
    const seq = [0, 0, 0];
    let i = 0;
    const rnd = () => seq[i++ % seq.length];

    const first = orderForCarousel(list, rnd, 8).map(c => c.id);
    i = 0;
    const second = orderForCarousel(list, rnd, 8).map(c => c.id);

    assert.deepEqual(first, second);
    assert.equal(first.length, 3);
    assert.deepEqual([...first].sort(), ['a', 'b', 'c']);
});

test('orderForCarousel never places the same advertiser back to back', () => {
    // Two campaigns from one advertiser and one from another shop: a
    // rotation that showed the same one twice in a row would look like a
    // stuck slideshow.
    const list = [
        { id: 'p1', advertiser: 'Shop', weight: 5 },
        { id: 'p2', advertiser: 'Shop', weight: 5 },
        { id: 'x1', advertiser: 'Other', weight: 1 }
    ];
    for (let seed = 0; seed < 20; seed++) {
        let n = seed;
        const rnd = () => ((n = (n * 9301 + 49297) % 233280) / 233280);
        const ids = orderForCarousel(list, rnd, 8);
        for (let k = 1; k < ids.length; k++) {
            assert.notEqual(ids[k].advertiser, ids[k - 1].advertiser,
                `seed ${seed}: ${ids.map(c => c.id).join(',')}`);
        }
    }
});

test('orderForCarousel still shows everyone when a repeat is unavoidable', () => {
    // Three of four campaigns share an advertiser: no arrangement avoids a
    // neighbouring pair, and dropping a paid campaign is the worse answer.
    const list = [
        { id: 'p1', advertiser: 'Shop', weight: 1 },
        { id: 'p2', advertiser: 'Shop', weight: 1 },
        { id: 'p3', advertiser: 'Shop', weight: 1 },
        { id: 'x1', advertiser: 'Other', weight: 1 }
    ];
    const ids = orderForCarousel(list, () => 0.4, 8).map(c => c.id);
    assert.equal(ids.length, 4);
    assert.deepEqual([...ids].sort(), ['p1', 'p2', 'p3', 'x1']);
});

test('orderForCarousel respects the cap and the module ceiling', () => {
    const many = Array.from({ length: 20 }, (_, k) => ({ id: 'c' + k, advertiser: 'A' + k, weight: 1 }));
    assert.equal(orderForCarousel(many, () => 0.5, 3).length, 3);
    // A caller asking for more than the memory ceiling still gets the ceiling.
    assert.equal(orderForCarousel(many, () => 0.5, 999).length, MAX_STRIP_SLIDES);
    assert.equal(orderForCarousel(many, () => 0.5).length, MAX_STRIP_SLIDES);
    assert.deepEqual(orderForCarousel([], () => 0.5, 3), []);
});

// -------------------------------------------------------- popup frequency

test('shouldShowPopup allows a first-time visitor', () => {
    assert.equal(shouldShowPopup(camp({ id: 'c1' }), {}, Date.now()), true);
    assert.equal(shouldShowPopup(camp({ id: 'c1' }), null, Date.now()), true);
});

test('shouldShowPopup enforces the per-campaign cooldown to the millisecond', () => {
    const now = 1_700_000_000_000;
    const c = camp({ id: 'c1', popup: { capHours: 24 } });
    const seen = recordPopupShown({}, 'c1', now);

    assert.equal(shouldShowPopup(c, seen, now + 24 * HOUR - 1), false);
    assert.equal(shouldShowPopup(c, seen, now + 24 * HOUR), true);
});

test('shouldShowPopup honours a per-campaign capHours override', () => {
    const now = 1_700_000_000_000;
    const c = camp({ id: 'c1', popup: { capHours: 2 } });
    const seen = recordPopupShown({}, 'c1', now);
    assert.equal(shouldShowPopup(c, seen, now + HOUR), false);
    assert.equal(shouldShowPopup(c, seen, now + 2 * HOUR), true);
});

test('shouldShowPopup suppresses a campaign the visitor already clicked', () => {
    const now = 1_700_000_000_000;
    const c = camp({ id: 'c1' });
    const seen = recordPopupClicked({}, 'c1', now);
    assert.equal(shouldShowPopup(c, seen, now + 6 * DAY), false);
    assert.equal(shouldShowPopup(c, seen, now + 8 * DAY), true);
});

test('shouldShowPopup enforces the rolling weekly quota', () => {
    const now = 1_700_000_000_000;
    const c = camp({ id: 'c1', popup: { capHours: 1, maxPerWeek: 3 } });

    let seen = {};
    seen = recordPopupShown(seen, 'c1', now);
    seen = recordPopupShown(seen, 'c1', now + 2 * DAY);
    seen = recordPopupShown(seen, 'c1', now + 4 * DAY);
    // Three shown inside the window: the cooldown is over but the quota is not.
    assert.equal(shouldShowPopup(c, seen, now + 5 * DAY), false);
    // The first hit ages out of the 7-day window and a slot frees up.
    assert.equal(shouldShowPopup(c, seen, now + 7 * DAY + 1), true);
});

test('recordPopupShown is pure and prunes history to the weekly window', () => {
    const now = 1_700_000_000_000;
    const before = Object.freeze({ _v: 1, c1: Object.freeze({ last: 1, clicked: 0, hits: Object.freeze([1]) }) });
    const after = recordPopupShown(before, 'c1', now);

    assert.notEqual(after, before);
    assert.equal(before.c1.last, 1, 'input must not be mutated');
    assert.equal(after.c1.last, now);
    // The ancient hit at t=1 is dropped, only the new one survives.
    assert.deepEqual(after.c1.hits, [now]);
});

test('recordPopupClicked keeps the show history intact', () => {
    const now = 1_700_000_000_000;
    const shown = recordPopupShown({}, 'c1', now);
    const clicked = recordPopupClicked(shown, 'c1', now + 1000);
    assert.equal(clicked.c1.last, now);
    assert.equal(clicked.c1.clicked, now + 1000);
    assert.deepEqual(clicked.c1.hits, [now]);
});

test('popup records for different campaigns do not interfere', () => {
    const now = 1_700_000_000_000;
    let seen = recordPopupShown({}, 'c1', now);
    seen = recordPopupShown(seen, 'c2', now);
    assert.equal(shouldShowPopup(camp({ id: 'c1' }), seen, now + HOUR), false);
    assert.equal(shouldShowPopup(camp({ id: 'c3' }), seen, now + HOUR), true);
});

test('shouldShowPopup refuses campaigns and clocks it cannot reason about', () => {
    assert.equal(shouldShowPopup(null, {}, Date.now()), false);
    assert.equal(shouldShowPopup({ name: 'no id' }, {}, Date.now()), false);
    assert.equal(shouldShowPopup(camp(), {}, NaN), false);
});

test('a corrupted seen record degrades to "show it" instead of throwing', () => {
    const now = Date.now();
    assert.equal(shouldShowPopup(camp({ id: 'c1' }), { c1: 'garbage' }, now), true);
    assert.equal(shouldShowPopup(camp({ id: 'c1' }), { c1: { hits: 'nope' } }, now), true);
});

// ----------------------------------------------------------- normalizeDoc

test('normalizeDoc clamps weight into the sellable range', () => {
    const d = normalizeDoc({
        campaigns: [
            { id: 'a', weight: 0 }, { id: 'b', weight: 999 },
            { id: 'c', weight: -5 }, { id: 'd', weight: 'x' }, { id: 'e' }
        ]
    });
    assert.deepEqual(d.campaigns.map(c => c.weight), [1, 100, 1, 1, 1]);
});

test('normalizeDoc drops entries without a usable id and de-duplicates', () => {
    const d = normalizeDoc({
        campaigns: [
            { id: 'keep' },
            { id: '' },
            { name: 'no id at all' },
            { id: 'has space' },
            { id: 'x'.repeat(65) },
            { id: 'keep', name: 'duplicate' }
        ]
    });
    assert.deepEqual(d.campaigns.map(c => c.id), ['keep']);
    assert.equal(d.campaigns[0].name, 'keep', 'first entry wins, not the duplicate');
});

test('normalizeDoc keeps only known slots and creatives', () => {
    const d = normalizeDoc({
        campaigns: [{
            id: 'c1',
            slots: ['strip', 'strip', 'sidebar', 'popup'],
            creatives: { strip: { src: '/i/a.webp' }, sidebar: { src: '/i/b.webp' }, popup: { src: '' } }
        }]
    });
    assert.deepEqual(d.campaigns[0].slots, ['strip', 'popup']);
    assert.deepEqual(Object.keys(d.campaigns[0].creatives), ['strip']);
});

test('normalizeDoc gives an animated creative a poster to fall back on', () => {
    const d = normalizeDoc({
        campaigns: [{
            id: 'c1',
            creatives: {
                strip: { src: '/i/anim.webp', anim: true },
                rail: { src: '/i/anim.gif', anim: true, poster: '/i/still.png' },
                popup: { src: '/i/static.webp' }
            }
        }]
    });
    const cre = d.campaigns[0].creatives;
    // Animation must always have a still frame: reduced motion, the pause
    // button and the PNG export all need one.
    assert.equal(cre.strip.poster, '/i/anim.webp');
    assert.equal(cre.rail.poster, '/i/still.png');
    assert.equal(cre.popup.poster, '');
    assert.equal(cre.popup.anim, false);
});

test('normalizeDoc sanitises hrefs and blanks malformed dates', () => {
    const d = normalizeDoc({
        campaigns: [{ id: 'c1', href: 'javascript:alert(1)', start: '20.08.2026', end: '2026-09-20' }]
    });
    assert.equal(d.campaigns[0].href, '');
    assert.equal(d.campaigns[0].start, '');
    assert.equal(d.campaigns[0].end, '2026-09-20');
});

// erid is printed on the page next to the banner, so a token that is not a
// token has to become the empty string - which draws nothing - rather than
// reach the DOM.
test('normalizeDoc keeps a valid erid and blanks anything else', () => {
    const d = normalizeDoc({
        campaigns: [
            { id: 'a', erid: '2VtzquZgWvo' },
            { id: 'b', erid: '  2Vfnxw-Qs_1  ' },
            { id: 'c', erid: '<script>alert(1)</script>' },
            { id: 'd', erid: 'x'.repeat(65) },
            { id: 'e' }
        ]
    });
    assert.equal(d.campaigns[0].erid, '2VtzquZgWvo');
    assert.equal(d.campaigns[1].erid, '2Vfnxw-Qs_1', 'trimmed, not rejected');
    assert.equal(d.campaigns[2].erid, '');
    assert.equal(d.campaigns[3].erid, '', 'over 64 characters is not a token');
    assert.equal(d.campaigns[4].erid, '', 'a campaign without one is normal');
});

test('normalizeDoc clamps popup settings into their safe range', () => {
    const d = normalizeDoc({
        campaigns: [
            { id: 'a', popup: { delayMs: 10, capHours: 0, maxPerWeek: 9999 } },
            { id: 'b', popup: { delayMs: 999999 } },
            { id: 'c' }
        ]
    });
    assert.deepEqual(d.campaigns[0].popup, { delayMs: 5000, capHours: 1, maxPerWeek: 50 });
    assert.equal(d.campaigns[1].popup.delayMs, 60000);
    assert.deepEqual(d.campaigns[2].popup, PROMO.POPUP_DEFAULTS);
});

test('normalizeDoc treats a missing enabled flag as enabled', () => {
    const d = normalizeDoc({ campaigns: [{ id: 'a' }, { id: 'b', enabled: false }, { id: 'c', enabled: true }] });
    assert.deepEqual(d.campaigns.map(c => c.enabled), [true, false, true]);
});

test('normalizeDoc survives anything the network or localStorage can hand it', () => {
    for (const junk of [null, undefined, [], 'string', 42, { campaigns: 'x' }, { campaigns: [null, 7, 'a'] }]) {
        const d = normalizeDoc(junk);
        assert.equal(d.v, 1);
        assert.equal(Array.isArray(d.campaigns), true);
        assert.equal(d.campaigns.length, 0);
    }
});

test('normalizeDoc exposes the slot list the rest of the code shares', () => {
    assert.deepEqual(SLOTS, ['strip', 'rail', 'dock', 'popup']);
});

// -------------------------------------------------- popupPick / HOUSE_TG

// Собственное объявление о телеграм-канале — то, что видит посетитель, пока
// окно не выкуплено. Оно показывается на трёх страницах сразу, поэтому его
// форма и частота проверяются здесь, а не в разметке каждой из них.

test('HOUSE_TG is a popup-only campaign pointing at the project channel', () => {
    assert.deepEqual(HOUSE_TG.slots, ['popup']);
    assert.equal(HOUSE_TG.enabled, true);
    assert.equal(safeHref(HOUSE_TG.href), 'https://t.me/theMaknemy');
    assert.ok(PROMO.creativeFor(HOUSE_TG, 'popup'), 'a popup creative must ship with it');
    // Раз в сутки: заказано владельцем. maxPerWeek должен пускать все семь
    // показов, иначе «раз в сутки» кончалось бы на третьем дне недели.
    assert.equal(HOUSE_TG.popup.capHours, 24);
    assert.ok(HOUSE_TG.popup.maxPerWeek >= 7);
    // Маркировка erid для рекламы собственного ресурса не нужна.
    assert.equal(HOUSE_TG.erid, '');
    // Текст берётся из словаря, а не запечён строкой: своё объявление обязано
    // говорить на языке интерфейса.
    assert.ok(HOUSE_TG.textKey && HOUSE_TG.ctaKey);
});

// Заглушка свободного места. Её показывают борта и нижняя полоса на всех
// трёх страницах, поэтому объект обязан быть один — по его id ведётся счёт,
// и вторая копия под другим именем развела бы страницы.
test('HOUSE_SLOT fills the banner slots but never the popup', () => {
    assert.deepEqual(HOUSE_SLOT.slots, ['strip', 'rail', 'dock']);
    assert.equal(HOUSE_SLOT.enabled, true);
    for (const slot of HOUSE_SLOT.slots) {
        const cre = PROMO.creativeFor(HOUSE_SLOT, slot);
        assert.ok(cre, `у слота ${slot} должен быть макет`);
        assert.ok(cre.src.startsWith('/assets/promo/'),
            'макеты лежат в репозитории, а не в базе: заглушка обязана работать на чистой установке');
    }
    // Попапа у неё нет намеренно: окно «ВАША РЕКЛАМА» каждому посетителю
    // раздражает, а продать место не помогает — для окна есть HOUSE_TG.
    assert.equal(PROMO.creativeFor(HOUSE_SLOT, 'popup'), null);
    assert.equal(popupPick({ campaigns: [] }, {}, Date.now(), 0.5).id, HOUSE_TG.id);
    // Два своих объявления обязаны различаться по id, иначе счётчик показов
    // одного гасил бы другое.
    assert.notEqual(HOUSE_SLOT.id, HOUSE_TG.id);
});

test('popupPick prefers a paid campaign over the house ad', () => {
    const doc = { campaigns: [{
        id: 'c_paid', slots: ['popup'], href: 'https://shop.example/',
        creatives: { popup: { src: '/images/p.webp', w: 800, h: 800 } }
    }] };
    assert.equal(popupPick(doc, {}, Date.now(), 0.5).id, 'c_paid');
});

test('popupPick falls back to the house ad when nothing is sold', () => {
    assert.equal(popupPick({ campaigns: [] }, {}, Date.now(), 0.5).id, HOUSE_TG.id);
    assert.equal(popupPick(null, {}, Date.now(), 0.5).id, HOUSE_TG.id);
});

test('popupPick falls back to the house ad once the paid one is capped today', () => {
    const now = Date.now();
    const doc = { campaigns: [{
        id: 'c_paid', slots: ['popup'], href: 'https://shop.example/',
        creatives: { popup: { src: '/images/p.webp', w: 800, h: 800 } }
    }] };
    const seen = recordPopupShown({}, 'c_paid', now);
    assert.equal(popupPick(doc, seen, now + HOUR, 0.5).id, HOUSE_TG.id);
    // А через сутки платная кампания снова забирает место.
    assert.equal(popupPick(doc, seen, now + 25 * HOUR, 0.5).id, 'c_paid');
});

test('popupPick shows the house ad once a day and not twice', () => {
    const now = Date.now();
    const seen = recordPopupShown({}, HOUSE_TG.id, now);
    assert.equal(popupPick({ campaigns: [] }, seen, now + 23 * HOUR, 0.5), null);
    assert.equal(popupPick({ campaigns: [] }, seen, now + 25 * HOUR, 0.5).id, HOUSE_TG.id);
});

test('popupPick keeps the house ad running all week, not three days', () => {
    // Значение по умолчанию maxPerWeek = 3 остановило бы своё объявление на
    // четвёртые сутки — «раз в день» превратилось бы в «трижды в неделю».
    let seen = {};
    const now = Date.now();
    for (let day = 0; day < 7; day++) {
        const at = now + day * DAY;
        const pick = popupPick({ campaigns: [] }, seen, at, 0.5);
        assert.equal(pick && pick.id, HOUSE_TG.id, `день ${day + 1} должен показать объявление`);
        seen = recordPopupShown(seen, HOUSE_TG.id, at);
    }
});

// -------------------------------------------------------- migrateLegacyAd

test('migrateLegacyAd returns null for the untouched default ad', () => {
    assert.equal(migrateLegacyAd({ text: '', image: '', link: '' }), null);
    assert.equal(migrateLegacyAd({}), null);
    assert.equal(migrateLegacyAd(null), null);
});

test('migrateLegacyAd converts the old single banner into a strip campaign', () => {
    const c = migrateLegacyAd({ text: 'МЕСТО ДЛЯ ВАШЕЙ РЕКЛАМЫ', image: '/images/abc.webp', link: 't.me/mksvtnc' });
    assert.equal(c.id, 'c_legacy');
    assert.equal(c.text, 'МЕСТО ДЛЯ ВАШЕЙ РЕКЛАМЫ');
    assert.equal(c.href, 'https://t.me/mksvtnc');
    assert.deepEqual(c.slots, ['strip']);
    assert.equal(c.creatives.strip.src, '/images/abc.webp');
    assert.equal(c.enabled, true);
});

test('migrateLegacyAd handles a text-only banner with no image', () => {
    const c = migrateLegacyAd({ text: 'только текст', image: '', link: '' });
    assert.deepEqual(c.slots, []);
    assert.deepEqual(c.creatives, {});
    assert.equal(c.text, 'только текст');
});
