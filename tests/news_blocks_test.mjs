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

// Крошечная замена document: ровно те методы, которые зовёт renderBlocks.
// Настоящего DOM в node нет, а тянуть jsdom ради шести методов — лишняя
// зависимость в проекте, где их сейчас ровно одна (playwright).
function fakeDoc() {
    const mkNode = tag => {
        const el = {
            tagName: tag.toUpperCase(), children: [], attrs: {}, style: {},
            className: '', _text: '',
            append(...kids) { for (const k of kids) { el.children.push(k); } },
            setAttribute(k, v) { el.attrs[k] = String(v); },
            get textContent() {
                return el._text + el.children.map(c => c.textContent).join('');
            },
            set textContent(v) { el._text = String(v); el.children.length = 0; }
        };
        return el;
    };
    return {
        createElement: mkNode,
        createTextNode: t => ({ tagName: '#text', children: [], textContent: String(t) }),
        createDocumentFragment: () => mkNode('#fragment')
    };
}

const flat = node => {
    const out = [];
    const walk = n => { out.push(n); (n.children || []).forEach(walk); };
    walk(node);
    return out;
};
const tags = node => flat(node).map(n => n.tagName);

test('a paragraph renders as P with its text', () => {
    const d = fakeDoc();
    const frag = B.renderBlocks(d, [p('Привет')], 'ru');
    assert.ok(tags(frag).includes('P'));
    assert.equal(frag.textContent, 'Привет');
});

test('bold and italic become STRONG and EM, not raw markup', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'жирно', b: true }, { s: 'косо', i: true }], en: [] }];
    const t = tags(B.renderBlocks(d, blocks, 'ru'));
    assert.ok(t.includes('STRONG'));
    assert.ok(t.includes('EM'));
});

test('a link gets target and a hardened rel', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'тут', href: 'https://example.com/a' }], en: [] }];
    const a = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.tagName === 'A');
    assert.ok(a, 'an A element exists');
    assert.equal(a.attrs.href, 'https://example.com/a');
    assert.equal(a.attrs.rel, 'noopener noreferrer nofollow');
    assert.equal(a.attrs.target, '_blank');
});

test('an unsafe href never reaches the DOM as a link', () => {
    // Второй рубеж после validateDoc: рендер может получить блоки из старой
    // записи, сохранённой до появления какой-то проверки, и всё равно не
    // имеет права построить javascript:-ссылку.
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'тут', href: 'javascript:alert(1)' }], en: [] }];
    const frag = B.renderBlocks(d, blocks, 'ru');
    assert.equal(tags(frag).includes('A'), false, 'no anchor');
    assert.equal(frag.textContent, 'тут', 'text survives');
});

test('a spoiler is a span with the spoiler class, text intact', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'секрет', sp: true }], en: [] }];
    const sp = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.className === 'nw-spoiler');
    assert.ok(sp, 'spoiler span exists');
    assert.equal(sp.textContent, 'секрет');
});

test('an ordered list renders OL, an unordered one UL', () => {
    const d = fakeDoc();
    const mk = ordered => ({ t: 'list', ordered: ordered, items: [{ ru: [{ s: 'раз' }], en: [] }] });
    assert.ok(tags(B.renderBlocks(d, [mk(true)], 'ru')).includes('OL'));
    assert.ok(tags(B.renderBlocks(d, [mk(false)], 'ru')).includes('UL'));
});

test('an image block carries its own width, alignment and caption', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'image', url: IMG, w: 800, h: 600, pct: 50, align: 'left', wrap: true, cap_ru: [{ s: 'Подпись' }], cap_en: [] }];
    const nodes = flat(B.renderBlocks(d, blocks, 'ru'));
    const img = nodes.find(n => n.tagName === 'IMG');
    assert.equal(img.style.width, '50%');
    assert.equal(img.className, 'nw-image nw-img-float-left');
    assert.equal(img.attrs.width, '800');
    assert.equal(img.attrs.height, '600');
    assert.ok(nodes.some(n => n.className === 'nw-caption' && n.textContent === 'Подпись'));
});

test('center plus wrap falls back to a block image, as it does today', () => {
    // float не умеет «по центру» — cardFor() уже принимает это решение для
    // легаси-поста, и блок обязан вести себя так же.
    const d = fakeDoc();
    const blocks = [{ t: 'image', url: IMG, w: 8, h: 6, pct: 100, align: 'center', wrap: true, cap_ru: [], cap_en: [] }];
    const img = flat(B.renderBlocks(d, blocks, 'ru')).find(n => n.tagName === 'IMG');
    assert.equal(img.className, 'nw-image nw-img-center');
});

test('an album renders one figure with a picture per item', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'album', items: [{ url: IMG, w: 4, h: 3 }, { url: IMG, w: 4, h: 3 }], cap_ru: [{ s: 'Три кадра' }], cap_en: [] }];
    const nodes = flat(B.renderBlocks(d, blocks, 'ru'));
    assert.equal(nodes.filter(n => n.tagName === 'IMG').length, 2);
    const grid = nodes.find(n => n.className && n.className.indexOf('nw-album') === 0);
    assert.equal(grid.className, 'nw-album nw-album-2');
});

test('english text falls back to russian per block', () => {
    const d = fakeDoc();
    const blocks = [{ t: 'p', ru: [{ s: 'Русский' }], en: [] }, { t: 'p', ru: [{ s: 'Тоже' }], en: [{ s: 'English' }] }];
    assert.equal(B.renderBlocks(d, blocks, 'en').textContent, 'РусскийEnglish');
});
