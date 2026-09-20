"""Креативы кампании «Раздача фруктов в телеграме».

    python tools/make-giveaway-creatives.py                        # превью в out/
    python tools/make-giveaway-creatives.py --as giveaway --out public_html/assets/promo

Кампания — собственная (t.me/theMaknemy/5302), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Размеры берутся из CREATIVE_SPECS в api/lib/images.php: файл, который не влез
в потолок слота, сервер либо ужмёт (still), либо отвергнет (анимация).

Исходники — tools/art/giveaway-fruit-mech.webp, giveaway-fruit-kitsune.webp и
giveaway-fruit-gold.webp: арты фруктов, обрезанные по альфе.

Слов «розыгрыш», «приз» и «участвовать» в макетах нет намеренно: раздача идёт
в телеграм-канале, и единственное, что макет обязан сказать за секунду, — что
раздают фрукты и где. Отсюда три строки и ни одной лишней.

Нужен Pillow.
"""
import argparse
import math
import pathlib

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"
FONT_PATH = ROOT / "public_html" / "assets" / "fonts" / "Bootshaus" / "Bootshaus-Regular.ttf"

# Размеры слотов = CREATIVE_SPECS.
SLOTS = {
    "strip": (1200, 300),
    "rail": (320, 1200),
    "dock": (640, 200),
    "popup": (800, 800),
}

# Палитра сайта: base.css :root + градиент кнопок шапки.
CYAN = (79, 214, 255)
MK = (214, 90, 255)
INK = (255, 255, 255)
GRAD_A = (97, 181, 233)
GRAD_B = (45, 74, 237)

# Тексты. Русский — как у объявления, которое стоит в тир-листе, и как в
# канале, где идёт раздача.
T_EYEBROW = "В ТЕЛЕГРАМЕ"
T_HEAD = ["РАЗДАЧА", "ФРУКТОВ"]
T_HEAD_ONE = "РАЗДАЧА ФРУКТОВ"
T_TG = "@THEMAKNEMY"

# Три фрукта в порядке показа. Хвост — доля высоты/ширины места: меху даём
# больше всех, золотой фрукт мельче остальных, иначе ряд выглядит как
# лестница.
FRUITS = [
    ("giveaway-fruit-kitsune.webp", 1.00),
    ("giveaway-fruit-mech.webp", 1.06),
    ("giveaway-fruit-gold.webp", 0.86),
]


def font(px):
    return ImageFont.truetype(str(FONT_PATH), px)


def fit(draw, text, box_w, start_px, min_px=8):
    px = start_px
    while px > min_px:
        f = font(px)
        if draw.textlength(text, font=f) <= box_w:
            return f
        px -= 1
    return font(min_px)


def vgrad(w, h, top, bottom):
    img = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(img)
    for y in range(h):
        k = y / max(1, h - 1)
        d.line([(0, y), (w, y)], fill=tuple(round(top[i] + (bottom[i] - top[i]) * k) for i in range(3)))
    return img.convert("RGBA")


def dgrad(w, h, a, b):
    img = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(img)
    for i in range(w + h):
        k = i / max(1, w + h - 1)
        d.line([(i, 0), (0, i)], fill=tuple(round(b[j] + (a[j] - b[j]) * k) for j in range(3)))
    return img.convert("RGBA")


def halftone(w, h, pitch, colour=(122, 176, 233), alpha=24):
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    r = pitch * 0.26
    for y in range(0, h + pitch, pitch):
        for x in range(0, w + pitch, pitch):
            d.ellipse([x - r, y - r, x + r, y + r], fill=colour + (alpha,))
    return layer


def radial(img, cx, cy, rx, ry, colour, strength=140, blur_div=8):
    """Мягкое пятно света поверх фона."""
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=strength)
    mask = mask.filter(ImageFilter.GaussianBlur(max(img.size) // blur_div))
    tint = Image.new("RGBA", img.size, colour + (255,))
    img.paste(tint, (0, 0), mask)
    return img


def rays(w, h, cx, cy, colour, count=14, alpha=26):
    """Лучи из точки — «товар в свете софитов»."""
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    span = max(w, h) * 2.2
    for i in range(count):
        a0 = (360 / count) * i
        a1 = a0 + (360 / count) * 0.42
        p = [(cx, cy)]
        for a in (a0, a1):
            rad = math.radians(a)
            p.append((cx + span * math.cos(rad), cy + span * math.sin(rad)))
        d.polygon(p, fill=colour + (alpha,))
    return layer.filter(ImageFilter.GaussianBlur(max(w, h) // 90 + 1))


def text_glow(img, xy, text, f, fill, glow_col, blur, anchor="mm", stroke=0, stroke_fill=None):
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).text(xy, text, font=f, fill=glow_col + (255,), anchor=anchor,
                               stroke_width=stroke + max(2, blur // 2), stroke_fill=glow_col + (255,))
    img.alpha_composite(layer.filter(ImageFilter.GaussianBlur(blur)))
    ImageDraw.Draw(img).text(xy, text, font=f, fill=fill + (255,), anchor=anchor,
                             stroke_width=stroke, stroke_fill=stroke_fill)


def art(name):
    return Image.open(ART / name).convert("RGBA")


def scaled(im, h=None, w=None):
    if h:
        k = h / im.height
    else:
        k = w / im.width
    return im.resize((max(1, round(im.width * k)), max(1, round(im.height * k))), Image.LANCZOS)


def glow_under(img, im, x, y, colour, strength=150):
    """Пятно света под фруктом: без него арт висит на фоне сам по себе."""
    mask = Image.new("L", img.size, 0)
    cx, cy = x + im.width / 2, y + im.height / 2
    rx, ry = im.width * 0.46, im.height * 0.46
    ImageDraw.Draw(mask).ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=strength)
    mask = mask.filter(ImageFilter.GaussianBlur(max(im.size) // 3 + 4))
    tint = Image.new("RGBA", img.size, colour + (255,))
    img.paste(tint, (0, 0), mask)


def drop(img, im, x, y, shadow=True):
    if shadow:
        sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
        a = im.split()[3].point(lambda v: v * 0.55)
        black = Image.new("RGBA", im.size, (0, 0, 0, 255))
        black.putalpha(a)
        sh.alpha_composite(black, (max(0, x + im.width // 40), max(0, y + im.height // 30)))
        img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(max(im.size) // 28 + 2)))
    img.alpha_composite(im, (x, y))


def chip(img, box, text, f, bg_grad=None, bg=None, fg=(10, 18, 38), radius=None):
    x0, y0, x1, y1 = box
    r = radius if radius is not None else (y1 - y0) // 2
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle(box, radius=r, fill=255)
    fillimg = bg_grad if bg_grad is not None else Image.new("RGBA", img.size, bg + (255,))
    img.paste(fillimg, (0, 0), mask)
    ImageDraw.Draw(img).text(((x0 + x1) / 2, (y0 + y1) / 2 - (y1 - y0) * 0.06), text,
                             font=f, fill=fg + (255,), anchor="mm")


def tg_glyph(size):
    """Самолётик в круге. Свой рисунок, не фирменный знак Telegram."""
    ss = 4
    s = size * ss
    layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    disc = Image.new("L", (s, s), 0)
    ImageDraw.Draw(disc).ellipse([0, 0, s - 1, s - 1], fill=255)
    layer.paste(dgrad(s, s, GRAD_A, GRAD_B), (0, 0), disc)
    d = ImageDraw.Draw(layer)
    u = s / 100.0
    d.polygon([(20 * u, 50 * u), (82 * u, 25 * u), (70 * u, 78 * u), (52 * u, 62 * u), (40 * u, 72 * u)],
              fill=INK + (255,))
    d.line([(40 * u, 72 * u), (42 * u, 56 * u), (82 * u, 25 * u)], fill=(10, 20, 48, 255),
           width=round(2.4 * u), joint="curve")
    return layer.resize((size, size), Image.LANCZOS)


def tg_chip(img, cx, cy, width, height, text=T_TG):
    """Плашка канала: кружок с самолётиком и адрес. Центр задаётся по (cx, cy)."""
    x0, x1 = round(cx - width / 2), round(cx + width / 2)
    y0, y1 = round(cy - height / 2), round(cy + height / 2)
    g = round(height * 0.72)
    d = ImageDraw.Draw(img)
    f = fit(d, text, width - g - round(height * 0.9), round(height * 0.62))
    tw = d.textlength(text, font=f)
    chip(img, [x0, y0, x1, y1], "", font(8), bg_grad=dgrad(img.width, img.height, GRAD_A, GRAD_B))
    block = g + round(height * 0.24) + tw
    gx = round(cx - block / 2)
    img.alpha_composite(tg_glyph(g), (gx, round(cy - g / 2)))
    ImageDraw.Draw(img).text((gx + g + round(height * 0.24), cy - height * 0.06), text,
                             font=f, fill=INK + (255,), anchor="lm")


def frame(img, colour, pad, radius, width):
    ImageDraw.Draw(img).rounded_rectangle([pad, pad, img.width - pad - 1, img.height - pad - 1],
                                          radius=radius, outline=colour + (210,), width=width)


def bg(w, h, cx, cy):
    img = vgrad(w, h, (20, 40, 78), (7, 12, 30))
    img.alpha_composite(rays(w, h, cx, cy, CYAN, count=16, alpha=18))
    img = radial(img, cx, cy, w * 0.42, h * 0.58, (46, 96, 176), 116)
    img.alpha_composite(halftone(w, h, max(14, min(w, h) // 18)))
    return img


def row(img, box, heights_of):
    """Три фрукта в ряд по центру прямоугольника box = (x0, y0, x1, y1).

    heights_of — базовая высота, которую домножает вес фрукта. Ряд сначала
    собирается целиком, потом при нужде ужимается под ширину: иначе золотой
    фрукт вылезал бы за борт раньше остальных.
    """
    x0, y0, x1, y1 = box
    gap = round((x1 - x0) * 0.035)
    ims = [scaled(art(n), h=round(heights_of * k)) for n, k in FRUITS]
    total = sum(i.width for i in ims) + gap * (len(ims) - 1)
    room = (x1 - x0)
    if total > room:
        k = room / total
        ims = [scaled(i, h=max(1, round(i.height * k))) for i in ims]
        gap = round(gap * k)
        total = sum(i.width for i in ims) + gap * (len(ims) - 1)
    x = round(x0 + (room - total) / 2)
    cy = (y0 + y1) / 2
    for i, im in enumerate(ims):
        # Средний фрукт приподнят: ряд из трёх одинаково посаженных артов
        # читается как таблица, а не как витрина.
        lift = im.height * 0.07 if i == 1 else 0
        y = round(cy - im.height / 2 - lift)
        glow_under(img, im, x, y, (40, 110, 190), 130)
        drop(img, im, x, y)
        x += im.width + gap
    return ims


def column(img, box, widths_of):
    """Те же три фрукта, но столбиком — для борта 320x1200."""
    x0, y0, x1, y1 = box
    ims = [scaled(art(n), w=round(widths_of * k)) for n, k in FRUITS]
    gap = max(8, round(((y1 - y0) - sum(i.height for i in ims)) / len(ims)))
    total = sum(i.height for i in ims) + gap * (len(ims) - 1)
    if total > (y1 - y0):
        k = (y1 - y0) / total
        ims = [scaled(i, w=max(1, round(i.width * k))) for i in ims]
        gap = round(gap * k)
        total = sum(i.height for i in ims) + gap * (len(ims) - 1)
    y = round(y0 + ((y1 - y0) - total) / 2)
    cx = (x0 + x1) / 2
    for im in ims:
        x = round(cx - im.width / 2)
        glow_under(img, im, x, y, (40, 110, 190), 130)
        drop(img, im, x, y)
        y += im.height + gap
    return ims


# ===========================================================================
#  Макеты
# ===========================================================================

def build(slot, w, h):
    if slot in ("strip", "dock"):
        big = slot == "strip"
        img = bg(w, h, w * 0.70, h * 0.50)
        frame(img, CYAN, 18 if big else 11, 18 if big else 12, 3 if big else 2)

        left = int(w * 0.050)
        colw = int(w * 0.33)
        d = ImageDraw.Draw(img)
        d.text((left, int(h * 0.19)), T_EYEBROW, font=fit(d, T_EYEBROW, colw, int(h * 0.125)),
               fill=CYAN + (255,), anchor="lm")
        f_head = fit(d, max(T_HEAD, key=len), colw, int(h * 0.27))
        for i, line in enumerate(T_HEAD):
            text_glow(img, (left, int(h * (0.42 + 0.23 * i))), line, f_head, INK, CYAN,
                      max(4, h // 28), anchor="lm")

        tg_chip(img, left + colw * 0.47, int(h * 0.87), colw * 0.94, int(h * 0.15))

        row(img, (int(w * 0.395), int(h * 0.06), int(w * 0.955), int(h * 0.94)),
            int(h * (0.76 if big else 0.72)))
        return img

    if slot == "rail":
        img = bg(w, h, w * 0.5, h * 0.52)
        frame(img, CYAN, 12, 14, 3)
        d = ImageDraw.Draw(img)
        d.text((w / 2, 56), T_EYEBROW, font=fit(d, T_EYEBROW, w - 46, 44), fill=CYAN + (255,), anchor="mm")
        f_head = fit(d, max(T_HEAD, key=len), w - 36, 88)
        for i, line in enumerate(T_HEAD):
            text_glow(img, (w / 2, 132 + i * 78), line, f_head, INK, CYAN, 12, anchor="mm")

        column(img, (14, 250, w - 14, h - 150), int(w * 0.80))
        tg_chip(img, w / 2, h - 80, w - 40, 64)
        return img

    # popup 800x800
    img = bg(w, h, w * 0.5, h * 0.48)
    frame(img, CYAN, 20, 32, 3)
    d = ImageDraw.Draw(img)
    d.text((w / 2, 88), T_EYEBROW, font=font(46), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 190), T_HEAD_ONE, fit(d, T_HEAD_ONE, w - 120, 112), INK, CYAN, 16, anchor="mm")

    row(img, (26, 262, w - 26, 636), 348)

    tg_chip(img, w / 2, 706, 520, 86)
    return img


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "tools" / "out" / "giveaway"))
    ap.add_argument("--prefix", default="")
    ap.add_argument("--as", dest="as_name", default="giveaway",
                    help="имя файла: --as giveaway -> giveaway-strip.webp")
    ap.add_argument("--png", action="store_true", help="писать PNG вместо WebP (для превью)")
    args = ap.parse_args()

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    for slot, (w, h) in SLOTS.items():
        img = build(slot, w, h).convert("RGB")
        name = f"{args.prefix}{args.as_name}-{slot}." + ("png" if args.png else "webp")
        path = out / name
        if args.png:
            img.save(path)
        else:
            img.save(path, "WEBP", quality=88, method=6)
        print(f"  {path.relative_to(ROOT) if path.is_relative_to(ROOT) else path}  {w}x{h}  {path.stat().st_size // 1024} KB")


if __name__ == "__main__":
    main()
