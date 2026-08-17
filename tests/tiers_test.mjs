// Unit tests for tier-logo normalization. Run: node --test tests/tiers_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';

const require = createRequire(import.meta.url);
const TIERS = require('../public_html/js/tiers.js');

const { TIER_LOGOS, normalizeTierLogos } = TIERS;

test('default tiers carry the brand marks of the header', () => {
    assert.equal(TIER_LOGOS.MK, 'assets/logo-mk.png');
    assert.equal(TIER_LOGOS.GLH, 'assets/logo-glh.png');
    // Ярлык остался «💧», марка — молния, как в шапке.
    assert.equal(TIER_LOGOS['💧'], 'assets/logo-bolt.png');
});

test('the three tier marks are files that ship with the site', () => {
    // Обрыв пути молча превратил бы логотип в подпись: img.onerror гасит марку.
    for (const src of Object.values(TIER_LOGOS)) {
        assert.doesNotThrow(
            () => readFileSync(new URL('../public_html/' + src, import.meta.url)),
            `missing asset: ${src}`,
        );
    }
});

test('a saved tier still on the retired flame is moved to the bolt', () => {
    // Ради этого правка и делалась: в базе и в localStorage лежит старый путь.
    const tiers = [{ label: '💧', logo: 'assets/logo-flame.png' }];
    normalizeTierLogos(tiers, false);
    assert.equal(tiers[0].logo, 'assets/logo-bolt.png');
});

test('the retired flame is replaced whatever the tier is labelled', () => {
    // Ярлык админ правит свободно, а марка всё равно должна стать молнией.
    const tiers = [{ label: 'Топ', logo: 'assets/logo-flame.png' }];
    normalizeTierLogos(tiers, true);
    assert.equal(tiers[0].logo, 'assets/logo-bolt.png');
});

test('a tier with no logo field gets the mark of its label back', () => {
    const tiers = [{ label: 'MK' }, { label: 'GLH' }, { label: '💧' }];
    normalizeTierLogos(tiers, false);
    assert.deepEqual(tiers.map(t => t.logo), [
        'assets/logo-mk.png', 'assets/logo-glh.png', 'assets/logo-bolt.png',
    ]);
});

test('a logo the admin switched off stays off when loading localStorage', () => {
    // Пустая строка — осознанный выбор админа, а не пробел в старом снимке.
    const tiers = [{ label: 'MK', logo: '' }];
    normalizeTierLogos(tiers, false);
    assert.equal(tiers[0].logo, '');
});

test('an empty logo from the server or an import gets the mark back', () => {
    const tiers = [{ label: 'MK', logo: '' }];
    normalizeTierLogos(tiers, true);
    assert.equal(tiers[0].logo, 'assets/logo-mk.png');
});

test('a custom uploaded logo is never overwritten', () => {
    const tiers = [{ label: 'MK', logo: 'images/custom.png' }];
    normalizeTierLogos(tiers, true);
    assert.equal(tiers[0].logo, 'images/custom.png');
});

test('a tier whose label has no default mark is left alone', () => {
    const tiers = [{ label: 'Новый тир', logo: '' }];
    normalizeTierLogos(tiers, true);
    assert.equal(tiers[0].logo, '');
});

test('normalizeTierLogos survives broken input', () => {
    assert.doesNotThrow(() => normalizeTierLogos(undefined, true));
    assert.doesNotThrow(() => normalizeTierLogos(null, false));
    assert.doesNotThrow(() => normalizeTierLogos([null, undefined], true));
    assert.doesNotThrow(() => normalizeTierLogos('not an array', true));
});
