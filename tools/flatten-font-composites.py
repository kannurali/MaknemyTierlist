#!/usr/bin/env python3
"""Разворачивает вложенные композитные глифы Bootshaus в простые контуры.

Зачем. Глиф «Й» (uni0419) в исходном шрифте собран как композит из uni0418 и
breve, а uni0418 сам композит из N. Вложенность второго уровня Skia не
разворачивает: базовая буква пропадает, на экране остаётся одна надстрочная
дужка. На сайте это выглядело как «СА˘ТЕ» вместо «САЙТЕ» и «МО˘» вместо «МОЙ».
FreeType такие глифы рисует правильно, поэтому в Photoshop и в предпросмотре
шрифта проблема не видна — только в браузере.

Лечим на уровне файла, а не подменой шрифта через unicode-range: у Bootshaus и
Proto Sans разный рисунок и ширина, «Й» посреди слова читался бы как вставка из
чужого шрифта.

    pip install fonttools
    python3 tools/flatten-font-composites.py

Скрипт идемпотентен: повторный запуск на уже вылеченном шрифте ничего не меняет.
"""

import os
import sys

from fontTools.ttLib import TTFont
from fontTools.pens.recordingPen import DecomposingRecordingPen
from fontTools.pens.ttGlyphPen import TTGlyphPen

FONT = os.path.join(
    os.path.dirname(os.path.abspath(__file__)),
    "..", "public_html", "assets", "fonts", "Bootshaus", "Bootshaus-Regular.ttf",
)


def composite_depth(glyf, name, seen=None):
    """Глубина вложенности композита. 0 — обычный глиф с контурами."""
    seen = seen or set()
    if name in seen:                      # защита от циклической ссылки
        return 0
    glyph = glyf[name]
    if not glyph.isComposite():
        return 0
    return 1 + max(composite_depth(glyf, c.glyphName, seen | {name})
                   for c in glyph.components)


def main():
    path = os.path.normpath(FONT)
    font = TTFont(path)
    glyf, glyph_set = font["glyf"], font.getGlyphSet()

    deep = [name for name in font.getGlyphOrder() if composite_depth(glyf, name) >= 2]
    if not deep:
        print("вложенных композитов нет — шрифт уже вылечен")
        return

    for name in deep:
        # DecomposingRecordingPen разворачивает вложенность на любую глубину и
        # отдаёт готовые контуры, которые TTGlyphPen пишет обратно в glyf.
        pen = DecomposingRecordingPen(glyph_set)
        glyph_set[name].draw(pen)
        out = TTGlyphPen(glyph_set)
        pen.replay(out)
        # Ширину не трогаем: hmtx остаётся прежним, метрика текста не поедет.
        glyph = out.glyph()
        # Габариты у собранного заново глифа не посчитаны, а maxp.recalc ниже
        # их читает — без этого он падает на первом же развёрнутом глифе.
        glyph.recalcBounds(glyf)
        glyf[name] = glyph
        print(f"  развёрнут {name}")

    font["maxp"].recalc(font)
    font.save(path)
    print(f"сохранено: {path} ({len(deep)} глифов)")


if __name__ == "__main__":
    sys.exit(main())
