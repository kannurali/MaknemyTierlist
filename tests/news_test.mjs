// Unit tests for the news feed module. Run: node --test tests/news_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const NEWS = require('../public_html/js/news.js');

const { CATEGORIES, isCategory, ALIGNS, isAlign, pickLang, formatDate, toParagraphs, cropToSourceRect } = NEWS;

test('the three categories are the ones the API accepts', () => {
    assert.deepEqual(CATEGORIES.map(c => c.key), ['tierlist', 'game', 'project']);
    assert.equal(isCategory('game'), true);
    assert.equal(isCategory('trade'), false);
});

test('the three alignments are the ones the API accepts, in left/center/right order', () => {
    assert.deepEqual(ALIGNS.map(a => a.key), ['left', 'center', 'right']);
    assert.equal(isAlign('right'), true);
    assert.equal(isAlign('top'), false);
});

test('every alignment entry has an i18n key', () => {
    for (const a of ALIGNS) {
        assert.equal(typeof a.i18n, 'string');
        assert.ok(a.i18n.length > 0, 'i18n key is not empty');
    }
});

test('alignments and categories are independent lists', () => {
    // Same shape ({key, i18n}), but the two lists must not be the same array
    // or share keys — an alignment is not a category and vice versa.
    assert.notEqual(ALIGNS, CATEGORIES);
    const alignKeys = ALIGNS.map(a => a.key);
    const catKeys = CATEGORIES.map(c => c.key);
    assert.equal(alignKeys.some(k => catKeys.includes(k)), false, 'no key overlap');
    assert.equal(isCategory('center'), false, 'isCategory does not accept an alignment key');
    assert.equal(isAlign('game'), false, 'isAlign does not accept a category key');
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

// ---------- cropToSourceRect (крой-редактор картинки новости) ----------

test('a crop covering the whole image at zoom 1 returns the full source rect', () => {
    const image = { width: 800, height: 600 };
    const frame = { x: 0, y: 0, w: 800, h: 600 };
    const rect = cropToSourceRect(frame, 1, { x: 0, y: 0 }, image);
    assert.deepEqual(rect, { sx: 0, sy: 0, sw: 800, sh: 600 });
});

test('zooming in halves the source rect', () => {
    const image = { width: 800, height: 600 };
    const frame = { x: 0, y: 0, w: 800, h: 600 };
    const rect = cropToSourceRect(frame, 2, { x: 0, y: 0 }, image);
    assert.deepEqual(rect, { sx: 0, sy: 0, sw: 400, sh: 300 });
});

test('panning shifts the source rect', () => {
    // Картинка 1000×1000, холст показывает окно 500×500 (frame = весь холст).
    // Панорама (-300, -100) означает: картинку утащили на 300px влево и на
    // 100px вверх, поэтому видимое окно сдвигается вглубь исходника ровно на
    // столько же — clamp здесь ни на что не влияет (300+500=800 ≤ 1000).
    const image = { width: 1000, height: 1000 };
    const frame = { x: 0, y: 0, w: 500, h: 500 };
    const rect = cropToSourceRect(frame, 1, { x: -300, y: -100 }, image);
    assert.deepEqual(rect, { sx: 300, sy: 100, sw: 500, sh: 500 });
});

test('panning past an edge is clamped — a crop can never read outside the source', () => {
    const image = { width: 1000, height: 1000 };
    const frame = { x: 0, y: 0, w: 500, h: 500 };

    // Картинку утащили далеко влево (pan.x = -700): наивно окно читало бы
    // sx=700..1200, за правым краем исходника. Итог зажимается так, чтобы
    // правый край окна совпал с правым краем картинки, а не вышел за него.
    const rightEdge = cropToSourceRect(frame, 1, { x: -700, y: 0 }, image);
    assert.deepEqual(rightEdge, { sx: 500, sy: 0, sw: 500, sh: 500 });

    // Обратный случай: картинку утащили далеко вправо (pan.x = 600) — наивно
    // окно читало бы sx=-600..-100, перед левым краем исходника. Зажимается
    // к нулю.
    const leftEdge = cropToSourceRect(frame, 1, { x: 600, y: 0 }, image);
    assert.deepEqual(leftEdge, { sx: 0, sy: 0, sw: 500, sh: 500 });

    // Инвариант в общем виде: результат всегда лежит внутри исходника,
    // сколь угодно абсурдная панорама.
    for (const panX of [-5000, -1000, 0, 1000, 5000]) {
        const r = cropToSourceRect(frame, 1, { x: panX, y: panX }, image);
        assert.ok(r.sx >= 0 && r.sx + r.sw <= image.width, `sx in bounds for pan.x=${panX}`);
        assert.ok(r.sy >= 0 && r.sy + r.sh <= image.height, `sy in bounds for pan.x=${panX}`);
    }
});

test('a crop frame smaller than the viewport maps to the correct sub-rectangle', () => {
    const image = { width: 800, height: 600 };
    const frame = { x: 100, y: 50, w: 200, h: 150 };
    const rect = cropToSourceRect(frame, 1, { x: 0, y: 0 }, image);
    assert.deepEqual(rect, { sx: 100, sy: 50, sw: 200, sh: 150 });
});
