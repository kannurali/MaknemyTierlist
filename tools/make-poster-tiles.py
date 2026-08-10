# Собирает «зеркальные» плитки фона и лепестков для .stage.
#
# Одиночная картинка макета кончается на высоте width * 1959/1200, а сцена на
# боевых данных вдвое выше — ниже оставался плоский #05091f. Плитка вида
# `картинка + её отражение` тайлится по вертикали без шва: верхняя строка плитки
# равна нижней, поэтому стык repeat-y невидим (в отличие от простого repeat-y
# самой картинки, где тёмный низ (0,2,6) встречается со светлым верхом (6,11,23)).
#
# Крайние строки отражения отбрасываются: иначе строки img[0] и img[h-1]
# дублировались бы на стыках.
#
# Источники — PNG без потерь, а не уже пережатые webp: пересжатие lossy→lossy
# добавило бы второе поколение артефактов верхней половине. Для фона это
# assets/bg-ck.png из макета, для лепестков — poster/petals-export.png:
# assets/petals.png макету не соответствует (альфа в 1.65 раза ниже и обрезана
# на 179), а petals-export.png — та самая картинка, из которой пожат
# petals.webp (альфа совпадает попиксельно, RGB при alpha≥250 расходится на 0.2).
#
#   python tools/make-poster-tiles.py

import os
import sys

from PIL import Image, ImageOps

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
SRC = os.path.join(ROOT, "public_html", "assets")
DST = os.path.join(SRC, "poster")


def tile(img):
    """img + вертикальное отражение без дублей крайних строк."""
    w, h = img.size
    flipped = ImageOps.flip(img).crop((0, 1, w, h - 1))
    out = Image.new(img.mode, (w, h + flipped.height))
    out.paste(img, (0, 0))
    out.paste(flipped, (0, h))
    return out


def load(relpath, mode):
    return Image.open(os.path.join(SRC, relpath)).convert(mode)


def resized(img, width):
    return img.resize((width, round(img.height * width / img.width)), Image.LANCZOS)


def save(img, name, **kw):
    path = os.path.join(DST, name)
    img.save(path, **kw)
    print("%-26s %sx%s %6d KB" % (name, img.width, img.height,
                                  round(os.path.getsize(path) / 1024)))


def main():
    bg = load("bg-ck.png", "RGB")
    petals = load(os.path.join("poster", "petals-export.png"), "RGBA")

    save(tile(resized(bg, 1200)), "bg-tile.webp", quality=80, method=6)
    save(tile(resized(bg, 760)), "bg-tile-m.webp", quality=80, method=6)
    save(tile(bg), "bg-tile-export.jpg", quality=82, optimize=True, progressive=True)

    save(tile(petals), "petals-tile.webp", quality=80, method=6)
    save(tile(resized(petals, 760)), "petals-tile-m.webp", quality=80, method=6)
    save(tile(petals), "petals-tile-export.png", optimize=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
