// Unit tests for the block model. Run: node --test tests/news_blocks_test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const B = require('../public_html/js/news-blocks.js');

const IMG = '/images/' + 'a'.repeat(40) + '.webp';
const p = (ru, en) => ({ t: 'p', ru: [{ s: ru }], en: en ? [{ s: en }] : [] });
const doc = blocks => ({ v: 1, blocks: blocks });

test('a minimal paragraph document validates', () => {
    const r = B.validateDoc(doc([p('Привет')]));
    assert.equal(r.ok, true, r.error);
    assert.equal(r.blocks.length, 1);
    assert.equal(r.blocks[0].t, 'p');
});

test('a document with the wrong version is rejected, not coerced', () => {
    assert.equal(B.validateDoc({ v: 2, blocks: [p('x')] }).ok, false);
    assert.equal(B.validateDoc({ blocks: [p('x')] }).ok, false);
});

test('an unknown block type is rejected', () => {
    assert.equal(B.validateDoc(doc([{ t: 'video', ru: [], en: [] }])).ok, false);
});

test('an unknown key inside a block is rejected, not silently dropped', () => {
    const bad = { t: 'p', ru: [{ s: 'x' }], en: [], onclick: 'alert(1)' };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('an unknown span flag is rejected', () => {
    const bad = { t: 'p', ru: [{ s: 'x', evil: true }], en: [] };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('every telegram flag is accepted and composes', () => {
    const span = { s: 'x', b: true, i: true, u: true, st: true, c: true, sp: true };
    assert.equal(B.validateDoc(doc([{ t: 'p', ru: [span], en: [] }])).ok, true);
});

test('only http and https hrefs survive', () => {
    assert.equal(B.isSafeHref('https://example.com/a'), true);
    assert.equal(B.isSafeHref('http://example.com/a'), true);
    assert.equal(B.isSafeHref('javascript:alert(1)'), false);
    assert.equal(B.isSafeHref('data:text/html,x'), false);
    assert.equal(B.isSafeHref('/news/1'), false);
    assert.equal(B.isSafeHref('JavaScript:alert(1)'), false);
});

test('a bad href fails the whole document', () => {
    const bad = { t: 'p', ru: [{ s: 'x', href: 'javascript:alert(1)' }], en: [] };
    assert.equal(B.validateDoc(doc([bad])).ok, false);
});

test('an image url outside the upload directory is rejected', () => {
    const mk = url => doc([{ t: 'image', url: url, w: 10, h: 10, pct: 100, align: 'center', wrap: false, cap_ru: [], cap_en: [] }]);
    assert.equal(B.validateDoc(mk(IMG)).ok, true);
    assert.equal(B.validateDoc(mk('https://evil.example/x.png')).ok, false);
    assert.equal(B.validateDoc(mk('/images/../../etc/passwd')).ok, false);
});

test('image geometry follows the same rules as the legacy columns', () => {
    const mk = extra => doc([Object.assign({ t: 'image', url: IMG, w: 10, h: 10, pct: 100, align: 'center', wrap: false, cap_ru: [], cap_en: [] }, extra)]);
    assert.equal(B.validateDoc(mk({ pct: 9 })).ok, false, 'below 10');
    assert.equal(B.validateDoc(mk({ pct: 101 })).ok, false, 'above 100');
    assert.equal(B.validateDoc(mk({ align: 'top' })).ok, false, 'not an alignment');
});

test('ceilings are enforced', () => {
    const many = [];
    for (let i = 0; i < B.LIMITS.blocks + 1; i++) { many.push(p('x')); }
    assert.equal(B.validateDoc(doc(many)).ok, false, 'too many blocks');

    const items = [];
    for (let i = 0; i < B.LIMITS.albumItems + 1; i++) { items.push({ url: IMG, w: 10, h: 10 }); }
    assert.equal(B.validateDoc(doc([{ t: 'album', items: items, cap_ru: [], cap_en: [] }])).ok, false);
});

test('plain text concatenates text blocks and falls back across languages', () => {
    const blocks = [
        { t: 'p', ru: [{ s: 'Первый' }, { s: ' абзац', b: true }], en: [{ s: 'First para' }] },
        { t: 'quote', ru: [{ s: 'Цитата' }], en: [], collapsible: false },
        { t: 'image', url: IMG, w: 1, h: 1, pct: 100, align: 'center', wrap: false, cap_ru: [{ s: 'Подпись' }], cap_en: [] }
    ];
    assert.equal(B.toPlainText(blocks, 'ru'), 'Первый абзац\n\nЦитата\n\nПодпись');
    // en is empty on the quote and the caption, so those fall back to ru —
    // the same rule pickLang() already applies to the whole post.
    assert.equal(B.toPlainText(blocks, 'en'), 'First para\n\nЦитата\n\nПодпись');
});

test('firstImage finds the first picture, album or not', () => {
    const alb = { t: 'album', items: [{ url: IMG, w: 4, h: 5 }], cap_ru: [], cap_en: [] };
    assert.deepEqual(B.firstImage([p('x'), alb]), { url: IMG, w: 4, h: 5 });
    assert.equal(B.firstImage([p('x')]), null);
});
