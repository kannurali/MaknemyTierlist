// Unit tests for the interface dictionary. Run: node --test tests/i18n_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import fs from 'node:fs';

const require = createRequire(import.meta.url);
const I18N = require('../public_html/js/i18n.js');
const { t, pickLang, supported, url, stripPrefix } = I18N;

const SRC = {};
for (const lang of supported()) {
    SRC[lang] = JSON.parse(fs.readFileSync(new URL(`../public_html/lang/${lang}.json`, import.meta.url), 'utf8'));
}

test('every language exposes exactly the same keys', () => {
    const [first, ...rest] = supported();
    const reference = Object.keys(SRC[first]).sort();
    for (const lang of rest) {
        const keys = Object.keys(SRC[lang]).sort();
        assert.deepEqual(reference.filter(k => !keys.includes(k)), [], `${lang} is missing keys`);
        assert.deepEqual(keys.filter(k => !reference.includes(k)), [], `${lang} has keys ${first} does not`);
    }
});

test('no translation is empty or left as whitespace', () => {
    for (const lang of supported()) {
        for (const [key, value] of Object.entries(SRC[lang])) {
            assert.equal(typeof value, 'string', `${lang}.${key} is not a string`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('the English table carries no Cyrillic left over from copy-paste', () => {
    for (const [key, value] of Object.entries(SRC.en)) {
        assert.ok(!/[А-Яа-яЁё]/.test(value), `en.${key} still contains Russian: ${value}`);
    }
});

test('every language declares the same placeholders for a key', () => {
    const names = s => (String(s).match(/\{(\w+)\}/g) || []).sort().join(',');
    for (const lang of supported()) {
        for (const key of Object.keys(SRC.ru)) {
            assert.equal(names(SRC[lang][key]), names(SRC.ru[key]), `${lang}: placeholders differ for ${key}`);
        }
    }
});

const SERVER_ONLY = ['meta.', 'ld.', 'og.', 'ads.'];
const serverOnly = key => SERVER_ONLY.some(p => key.startsWith(p));

test('markup inside a string is the same in every language and limited to inline tags', () => {
    const tags = s => (String(s).match(/<[^>]+>/g) || []);
    for (const [key, value] of Object.entries(SRC.ru)) {
        const ref = tags(value);
        for (const t of ref) {
            assert.match(t, /^<\/?(b|strong|i|em|a|br|span|small|sup|sub)\b/, `ru.${key}: unexpected tag ${t}`);
        }
        for (const lang of supported()) {
            assert.deepEqual(tags(SRC[lang][key]), ref, `${lang}.${key}: tags differ from Russian`);
        }
    }
});

test('built dictionaries carry the sources, minus server-only keys', () => {
    for (const lang of supported()) {
        for (const key of Object.keys(SRC[lang]).filter(serverOnly)) {
            assert.equal(globalThis.I18N_DICTS[lang][key], undefined, `js/i18n/${lang}.js ships server-only ${key}`);
        }
        for (const [key, value] of Object.entries(SRC[lang]).filter(([k]) => !serverOnly(k))) {
            assert.equal(globalThis.I18N_DICTS[lang][key], value, `js/i18n/${lang}.js is stale at ${key}`);
        }
    }
});

test('t returns the requested language', () => {
    assert.equal(t('filters.all', 'ru'), 'Все');
    assert.equal(t('filters.all', 'en'), 'All');
});

test('t falls back to the page language (Russian outside a browser)', () => {
    assert.equal(t('filters.all', 'xx'), SRC.ru['filters.all']);
    assert.equal(t('filters.all', undefined), SRC.ru['filters.all']);
});

test('t returns the key itself when nothing is defined', () => {
    assert.equal(t('nope.not.here', 'en'), 'nope.not.here');
});

test('t is not fooled by inherited Object properties', () => {
    assert.equal(t('constructor', 'en'), 'constructor');
    assert.equal(t('toString', 'ru'), 'toString');
});

test('t substitutes named variables and keeps unknown ones visible', () => {
    const en = t('msg.confirmDeleteTier', 'en', { tier: 'S', count: 3 });
    assert.ok(en.includes('S') && en.includes('3') && !en.includes('{'), en);
    assert.equal(t('msg.confirmDeleteTier', 'ru', {}).includes('{tier}'), true);
    assert.equal(t('filters.all', 'en', { tier: 'X' }), 'All');
});

test('UI strings that used to be hardcoded now live in the dictionary', () => {
    for (const key of ['footer.urlPrompt', 'msg.pngSaveFailed', 'msg.pngFileHint']) {
        for (const lang of supported()) {
            const value = t(key, lang);
            assert.notEqual(value, key, `${lang}.${key} is missing`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('pickLang follows the page, not storage or the browser', () => {
    assert.equal(pickLang('en', 'en-US'), 'ru');
    assert.equal(pickLang(null, 'en-GB'), 'ru');
    assert.equal(pickLang(undefined, undefined), 'ru');
});

test('url adds the language prefix', () => {
    assert.equal(url('/tierlist', 'en'), '/en/tierlist');
    assert.equal(url('/', 'en'), '/en');
    assert.equal(url('/giveaways#g5', 'en'), '/en/giveaways#g5');
    assert.equal(url('/?x=1', 'en'), '/en?x=1');
    assert.equal(url('/profile?id=7', 'en'), '/en/profile?id=7');
    assert.equal(url('/tierlist', 'ru'), '/tierlist');
    assert.equal(url('/tierlist', 'xx'), '/tierlist');
    assert.equal(url('/tierlist'), '/tierlist', 'outside a browser the page is Russian');
});

test('stripPrefix removes only a known prefix', () => {
    assert.equal(stripPrefix('/en/news/5'), '/news/5');
    assert.equal(stripPrefix('/en'), '/');
    assert.equal(stripPrefix('/tierlist'), '/tierlist');
    assert.equal(stripPrefix('/ru/x'), '/ru/x');
    assert.equal(stripPrefix('/de/x'), '/x');
    assert.equal(stripPrefix('/uk'), '/');
    assert.equal(stripPrefix('/xx/x'), '/xx/x');
    assert.equal(stripPrefix(''), '/');
});

test('locale maps languages to formatting locales', () => {
    assert.equal(I18N.locale('ru'), 'ru-RU');
    assert.equal(I18N.locale('en'), 'en-GB');
    assert.equal(I18N.locale('xx'), 'ru-RU');
    assert.equal(I18N.locale(), 'ru-RU');
});

test('plural follows the language rules and keeps the three dictionary slots', () => {
    const ru = [1, 2, 5, 11, 21, 22, 25].map(n => I18N.plural(n, 'ru'));
    assert.deepEqual(ru, ['one', 'few', 'many', 'many', 'one', 'few', 'many']);
    const en = [1, 2, 5, 21].map(n => I18N.plural(n, 'en'));
    assert.deepEqual(en, ['one', 'many', 'many', 'many']);
});

test('Latin-script languages carry no Cyrillic the English does not have', () => {
    for (const lang of ['de', 'fr', 'es', 'it', 'pt', 'pl', 'tr']) {
        for (const [key, value] of Object.entries(SRC[lang])) {
            if (/[А-Яа-яЁё]/.test(SRC.en[key])) continue;
            assert.ok(!/[А-Яа-яЁё]/.test(value), `${lang}.${key} contains Cyrillic: ${value}`);
        }
    }
});

test('Ukrainian is a translation, not a copy of Russian', () => {
    const keys = Object.keys(SRC.ru).filter(k => /[А-Яа-яЁё]{4,}/.test(SRC.ru[k]));
    const same = keys.filter(k => SRC.uk[k] === SRC.ru[k]);
    assert.ok(same.length <= keys.length * 0.06, `${same.length} of ${keys.length} uk strings equal ru`);
    assert.ok(!Object.values(SRC.uk).some(v => /[ыэъё]/i.test(v)), 'uk uses letters that only Russian has');
});
