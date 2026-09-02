// Unit tests for content-language selection. Run: node --test tests/content_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const CONTENT = require('../public_html/js/content.js');

const { descFor, textFor } = CONTENT;

test('descFor returns the language-specific text when both exist', () => {
    const item = { desc: 'Русское описание', descEn: 'English description' };
    assert.equal(descFor(item, 'ru'), 'Русское описание');
    assert.equal(descFor(item, 'en'), 'English description');
});

test('descFor falls back to Russian when the English variant is empty', () => {
    // A half-filled item must still show something, not a blank panel.
    const item = { desc: 'Только по-русски', descEn: '' };
    assert.equal(descFor(item, 'en'), 'Только по-русски');
    assert.equal(descFor(item, 'ru'), 'Только по-русски');
});

test('descFor falls back to English when only the English variant is filled', () => {
    const item = { desc: '', descEn: 'English only' };
    assert.equal(descFor(item, 'ru'), 'English only');
    assert.equal(descFor(item, 'en'), 'English only');
});

test('descFor returns empty string when neither variant is filled', () => {
    assert.equal(descFor({ desc: '', descEn: '' }, 'en'), '');
    assert.equal(descFor({ desc: '   ', descEn: '  ' }, 'ru'), '');
});

test('descFor tolerates a legacy item with no descEn field', () => {
    // Items saved before the English field existed only have `desc`.
    assert.equal(descFor({ desc: 'Старый предмет' }, 'en'), 'Старый предмет');
    assert.equal(descFor({ desc: 'Старый предмет' }, 'ru'), 'Старый предмет');
});

test('descFor trims surrounding whitespace', () => {
    assert.equal(descFor({ desc: '  padded  ', descEn: '' }, 'ru'), 'padded');
});

test('descFor treats an unknown language like the primary (Russian)', () => {
    const item = { desc: 'основной', descEn: 'secondary' };
    assert.equal(descFor(item, 'de'), 'основной');
    assert.equal(descFor(item, undefined), 'основной');
});

test('descFor returns empty string for a missing item', () => {
    assert.equal(descFor(null, 'en'), '');
    assert.equal(descFor(undefined, 'ru'), '');
});

// textFor is the general rule descFor is a special case of: the same
// language fallback over any base/baseEn pair on the item (terms, tag).
test('textFor picks the language variant of an arbitrary field', () => {
    const item = { terms: 'Нужен использованный клинок', termsEn: 'Requires a used blade' };
    assert.equal(textFor(item, 'terms', 'ru'), 'Нужен использованный клинок');
    assert.equal(textFor(item, 'terms', 'en'), 'Requires a used blade');
});

test('textFor falls back to the other language for any field', () => {
    assert.equal(textFor({ tag: 'LIMITED' }, 'tag', 'en'), 'LIMITED');
    assert.equal(textFor({ tagEn: 'LIMITED' }, 'tag', 'ru'), 'LIMITED');
});

test('textFor returns empty string for a field the item does not have', () => {
    // Items saved before terms/tag existed carry neither key.
    assert.equal(textFor({ desc: 'старый предмет' }, 'terms', 'ru'), '');
    assert.equal(textFor({ desc: 'старый предмет' }, 'tag', 'en'), '');
});

test('textFor trims and tolerates a missing item or base', () => {
    assert.equal(textFor({ tag: '  LIMITED  ' }, 'tag', 'ru'), 'LIMITED');
    assert.equal(textFor(null, 'tag', 'ru'), '');
    assert.equal(textFor({ tag: 'LIMITED' }, '', 'ru'), '');
});

test('descFor is textFor over the desc field', () => {
    const item = { desc: 'основной', descEn: 'secondary' };
    assert.equal(descFor(item, 'en'), textFor(item, 'desc', 'en'));
    assert.equal(descFor(item, 'ru'), textFor(item, 'desc', 'ru'));
});
