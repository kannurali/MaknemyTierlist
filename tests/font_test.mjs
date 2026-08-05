// Проверка шрифтов сцены на глифы, которые не рисует Chromium.
// Запуск: node --test tests/font_test.mjs
//
// История. Глиф «Й» в Bootshaus был собран как композит из «И» и дужки, а «И»
// сам был композитом из «N». Вложенность второго уровня Skia не разворачивает:
// базовая буква пропадала, на экране оставалась одна надстрочная дужка. На
// сайте это выглядело как «СА˘ТЕ» вместо «САЙТЕ» и «МО˘» вместо «МОЙ».
// FreeType такие глифы рисует правильно, поэтому в Photoshop и в системном
// предпросмотре шрифта дефект не виден — только в браузере.
//
// Тест читает TTF напрямую, без зависимостей: нужны только таблицы cmap, loca
// и glyf. На CFF-шрифтах (.otf) композитов нет по устройству формата, поэтому
// Proto Sans здесь не проверяется.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const FONT = join(HERE, '..', 'public_html', 'assets', 'fonts', 'Bootshaus', 'Bootshaus-Regular.ttf');

const ARGS_ARE_WORDS = 0x0001;
const MORE_COMPONENTS = 0x0020;
const WE_HAVE_A_SCALE = 0x0008;
const WE_HAVE_AN_X_AND_Y_SCALE = 0x0040;
const WE_HAVE_A_TWO_BY_TWO = 0x0080;

function readFont(path) {
    const buf = readFileSync(path);
    const view = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
    const tables = {};
    const count = view.getUint16(4);
    for (let i = 0; i < count; i++) {
        const rec = 12 + i * 16;
        const tag = String.fromCharCode(...[0, 1, 2, 3].map(k => buf[rec + k]));
        tables[tag] = { offset: view.getUint32(rec + 8), length: view.getUint32(rec + 12) };
    }
    return { view, tables };
}

// cmap формата 4 — единственный, который нужен: в этом шрифте кириллица лежит
// в базовой плоскости.
function readCmap({ view, tables }) {
    const base = tables.cmap.offset;
    const n = view.getUint16(base + 2);
    let sub = 0;
    for (let i = 0; i < n; i++) {
        const rec = base + 4 + i * 8;
        const platform = view.getUint16(rec);
        const encoding = view.getUint16(rec + 2);
        if ((platform === 3 && (encoding === 1 || encoding === 10)) || platform === 0) {
            sub = base + view.getUint32(rec + 4);
        }
    }
    assert.ok(sub, 'в шрифте нет подходящей таблицы cmap');
    assert.equal(view.getUint16(sub), 4, 'ожидался cmap формата 4');

    const segX2 = view.getUint16(sub + 6);
    const ends = sub + 14;
    const starts = ends + segX2 + 2;
    const deltas = starts + segX2;
    const ranges = deltas + segX2;

    const map = new Map();
    for (let s = 0; s < segX2 / 2; s++) {
        const end = view.getUint16(ends + s * 2);
        const start = view.getUint16(starts + s * 2);
        const delta = view.getInt16(deltas + s * 2);
        const rangeOffset = view.getUint16(ranges + s * 2);
        if (start === 0xffff) { continue; }
        for (let cp = start; cp <= end; cp++) {
            let gid;
            if (rangeOffset === 0) {
                gid = (cp + delta) & 0xffff;
            } else {
                const at = ranges + s * 2 + rangeOffset + (cp - start) * 2;
                gid = view.getUint16(at);
                if (gid !== 0) { gid = (gid + delta) & 0xffff; }
            }
            if (gid !== 0) { map.set(cp, gid); }
        }
    }
    return map;
}

function glyphOffsets({ view, tables }) {
    const longFormat = view.getInt16(tables.head.offset + 50) === 1;
    const n = view.getUint16(tables.maxp.offset + 4);
    const loca = tables.loca.offset;
    const out = [];
    for (let i = 0; i <= n; i++) {
        out.push(longFormat ? view.getUint32(loca + i * 4) : view.getUint16(loca + i * 2) * 2);
    }
    return out;
}

// Глубина вложенности композита: 0 — обычный глиф с контурами.
function depth(font, loca, gid, seen = new Set()) {
    const { view, tables } = font;
    if (seen.has(gid) || loca[gid] === loca[gid + 1]) { return 0; }
    const at = tables.glyf.offset + loca[gid];
    if (view.getInt16(at) >= 0) { return 0; }

    let p = at + 10;
    let deepest = 0;
    for (;;) {
        const flags = view.getUint16(p);
        const child = view.getUint16(p + 2);
        deepest = Math.max(deepest, depth(font, loca, child, new Set([...seen, gid])));
        p += 4 + (flags & ARGS_ARE_WORDS ? 4 : 2);
        if (flags & WE_HAVE_A_SCALE) { p += 2; }
        else if (flags & WE_HAVE_AN_X_AND_Y_SCALE) { p += 4; }
        else if (flags & WE_HAVE_A_TWO_BY_TWO) { p += 8; }
        if (!(flags & MORE_COMPONENTS)) { break; }
    }
    return 1 + deepest;
}

test('ни один кириллический глиф не собран композитом глубже первого уровня', () => {
    const font = readFont(FONT);
    const cmap = readCmap(font);
    const loca = glyphOffsets(font);

    const broken = [];
    for (const [cp, gid] of cmap) {
        const cyrillic = (cp >= 0x0400 && cp <= 0x04ff);
        if (cyrillic && depth(font, loca, gid) >= 2) {
            broken.push('U+' + cp.toString(16).toUpperCase().padStart(4, '0'));
        }
    }
    assert.deepEqual(broken, [], 'Chromium не отрисует базовую букву у этих глифов');
});

test('«Й» и «й» присутствуют и не пустые', () => {
    const font = readFont(FONT);
    const cmap = readCmap(font);
    const loca = glyphOffsets(font);

    for (const cp of [0x0419, 0x0439]) {
        const gid = cmap.get(cp);
        assert.ok(gid, 'в шрифте нет U+' + cp.toString(16));
        assert.notEqual(loca[gid], loca[gid + 1], 'глиф U+' + cp.toString(16) + ' пустой');
    }
});
