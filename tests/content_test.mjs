// Unit tests for content-language selection. Run: node --test tests/content_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const CONTENT = require('../public_html/js/content.js');

const { descFor } = CONTENT;

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
