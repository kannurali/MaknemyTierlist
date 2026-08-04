// Unit tests for the interface dictionary. Run: node --test tests/i18n_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const I18N = require('../public_html/js/i18n.js');

const { STRINGS, t, pickLang, supported } = I18N;

test('every language exposes exactly the same keys', () => {
    // A key present in one table and missing from another is the failure mode
    // that ships a half-translated interface, so it has to fail the build.
    const [first, ...rest] = supported();
    const reference = Object.keys(STRINGS[first]).sort();
    for (const lang of rest) {
        const keys = Object.keys(STRINGS[lang]).sort();
        const missing = reference.filter(k => !keys.includes(k));
        const extra = keys.filter(k => !reference.includes(k));
        assert.deepEqual(missing, [], `${lang} is missing keys`);
        assert.deepEqual(extra, [], `${lang} has keys ${first} does not`);
    }
});

test('no translation is empty or left as whitespace', () => {
    for (const lang of supported()) {
        for (const [key, value] of Object.entries(STRINGS[lang])) {
            assert.equal(typeof value, 'string', `${lang}.${key} is not a string`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('the English table carries no Cyrillic left over from copy-paste', () => {
    for (const [key, value] of Object.entries(STRINGS.en)) {
        assert.ok(!/[А-Яа-яЁё]/.test(value), `en.${key} still contains Russian: ${value}`);
    }
});

test('t returns the requested language', () => {
    assert.equal(t('filters.all', 'ru'), 'Все');
    assert.equal(t('filters.all', 'en'), 'All');
});

test('t falls back to Russian for an unknown language', () => {
    assert.equal(t('filters.all', 'de'), STRINGS.ru['filters.all']);
    assert.equal(t('filters.all', undefined), STRINGS.ru['filters.all']);
});

test('t returns the key itself when nothing is defined', () => {
    // Better a visible key than an empty button.
    assert.equal(t('nope.not.here', 'en'), 'nope.not.here');
});

test('t is not fooled by inherited Object properties', () => {
    assert.equal(t('constructor', 'en'), 'constructor');
    assert.equal(t('toString', 'ru'), 'toString');
});

test('t substitutes named variables', () => {
    const ru = t('msg.confirmDeleteTier', 'ru', { tier: 'S', count: 3 });
    assert.ok(ru.includes('S') && ru.includes('3'), `no substitution: ${ru}`);
    assert.ok(!ru.includes('{'), `placeholder left behind: ${ru}`);

    const en = t('msg.confirmDeleteTier', 'en', { tier: 'S', count: 3 });
    assert.ok(en.includes('S') && en.includes('3'), `no substitution: ${en}`);
    assert.ok(!en.includes('{'), `placeholder left behind: ${en}`);
});

test('t leaves an unknown placeholder visible instead of dropping it', () => {
    // Молча потерянная подстановка даёт «Удалить тир «» вместе с  предметами?»
    // — лучше показать имя и заметить пропажу.
    assert.equal(t('msg.confirmDeleteTier', 'ru', {}).includes('{tier}'), true);
});

test('t ignores vars for strings without placeholders', () => {
    assert.equal(t('filters.all', 'en', { tier: 'X' }), 'All');
});

test('both languages declare the same placeholders for a key', () => {
    // Ключ с {count} в одном языке и без него в другом — тихая потеря данных
    // при переключении языка.
    const names = s => (String(s).match(/\{(\w+)\}/g) || []).sort().join(',');
    for (const key of Object.keys(STRINGS.ru)) {
        assert.equal(names(STRINGS.en[key]), names(STRINGS.ru[key]),
            `placeholders differ for ${key}`);
    }
});

test('UI strings that used to be hardcoded now live in the dictionary', () => {
    // Regression guard: the footer URL prompt and the PNG-export failure alert
    // were once literal Russian strings in app.js and stayed Russian under EN.
    // Key parity is covered above; here we pin the exact keys so nobody drops
    // them or re-hardcodes the text.
    const uiKeys = ['footer.urlPrompt', 'msg.pngSaveFailed', 'msg.pngFileHint'];
    for (const key of uiKeys) {
        for (const lang of supported()) {
            const value = t(key, lang);
            assert.notEqual(value, key, `${lang}.${key} is missing (falls back to the key)`);
            assert.ok(value.trim().length > 0, `${lang}.${key} is empty`);
        }
    }
});

test('pickLang honours an explicit stored choice', () => {
    assert.equal(pickLang('en', 'ru-RU'), 'en');
    assert.equal(pickLang('ru', 'en-US'), 'ru');
});

test('pickLang ignores a stored language that no longer exists', () => {
    assert.equal(pickLang('de', 'en-US'), 'en');
});

test('pickLang falls back to the browser language', () => {
    assert.equal(pickLang(null, 'ru-RU'), 'ru');
    assert.equal(pickLang(null, 'RU'), 'ru');
    assert.equal(pickLang(null, 'en-GB'), 'en');
});

test('pickLang keeps an unfamiliar locale on Russian', () => {
    // The audience is Russian-speaking; a Kazakh or Turkish locale is a normal
    // visitor here, not a foreigner. Only an explicit en* asks for English —
    // "anything but ru means en" would have handed them an English site.
    assert.equal(pickLang(null, 'kk-KZ'), 'ru');
    assert.equal(pickLang(null, 'tr-TR'), 'ru');
    assert.equal(pickLang(null, 'fr-FR'), 'ru');
});

test('pickLang defaults to Russian with no signal at all', () => {
    assert.equal(pickLang(null, ''), 'ru');
    assert.equal(pickLang(undefined, undefined), 'ru');
});
