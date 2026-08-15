// Unit tests for the news feed module. Run: node --test tests/news_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const NEWS = require('../public_html/js/news.js');

const { CATEGORIES, isCategory, pickLang, formatDate, toParagraphs } = NEWS;

test('the three categories are the ones the API accepts', () => {
    assert.deepEqual(CATEGORIES.map(c => c.key), ['tierlist', 'game', 'project']);
    assert.equal(isCategory('game'), true);
    assert.equal(isCategory('trade'), false);
});

test('english falls back to russian when it is empty', () => {
    const post = { title_ru: 'Заголовок', title_en: '', body_ru: 'Текст', body_en: '' };
    assert.deepEqual(pickLang(post, 'en'), { title: 'Заголовок', body: 'Текст' });
});

test('russian falls back to english when only english is filled', () => {
    // Зеркало правила из content.js: пустой экран хуже текста не на том языке.
    const post = { title_ru: '', title_en: 'Title', body_ru: '', body_en: 'Body' };
    assert.deepEqual(pickLang(post, 'ru'), { title: 'Title', body: 'Body' });
});

test('each language wins when both are filled', () => {
    const post = { title_ru: 'РУ', title_en: 'EN', body_ru: 'ру', body_en: 'en' };
    assert.deepEqual(pickLang(post, 'ru'), { title: 'РУ', body: 'ру' });
    assert.deepEqual(pickLang(post, 'en'), { title: 'EN', body: 'en' });
});

test('a missing post does not throw', () => {
    assert.deepEqual(pickLang(null, 'ru'), { title: '', body: '' });
});

test('the date is DD.MM.YYYY with zero padding', () => {
    // Дата строится в локальном времени с обеих сторон, поэтому тест не зависит
    // от часового пояса машины, где он запущен.
    assert.equal(formatDate(new Date(2026, 7, 16).getTime()), '16.08.2026');
    assert.equal(formatDate(new Date(2026, 0, 3).getTime()), '03.01.2026');
});

test('paragraphs split on blank lines and survive CRLF', () => {
    assert.deepEqual(toParagraphs('Первый\r\n\r\nВторой'), ['Первый', 'Второй']);
});

test('runs of blank lines make one break, not several', () => {
    assert.deepEqual(toParagraphs('А\n\n\n\n Б '), ['А', 'Б']);
});

test('empty and missing input give no paragraphs', () => {
    assert.deepEqual(toParagraphs(''), []);
    assert.deepEqual(toParagraphs('   \n\n  '), []);
    assert.deepEqual(toParagraphs(null), []);
});
