"""Креативы кампании «Раздача фруктов в телеграме».

    python tools/make-giveaway-creatives.py --png --out tools/out/giveaway   # превью
    python tools/make-giveaway-creatives.py --out public_html/assets/promo   # боевые

Кампания — собственная (t.me/theMaknemy/5302), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Размеры и потолки веса берутся из CREATIVE_SPECS в api/lib/images.php: файл,
который не влез в потолок слота, сервер либо ужмёт (still), либо отвергнет.

Исходники — tools/art/giveaway-fruit-mech.webp, giveaway-fruit-kitsune.webp и
giveaway-fruit-gold.webp: арты фруктов, обрезанные по альфе.

Арт-направление — «афиша»: фоном идёт ночная сцена самого сайта
(assets/design/page-bg.webp), предметы стоят в луче света с контровым
свечением и коротким отражением, заголовок набран как на игровом постере —
свечение, тень, тёмный контур, градиентная заливка. Баннер должен выглядеть
продолжением страницы, а не наклейкой поверх неё, поэтому фон берётся из
дизайна сайта, а не рисуется градиентом заново.

Слов «розыгрыш», «приз» и «участвовать» в макетах нет намеренно: раздача идёт
в телеграм-канале, и единственное, что макет обязан сказать за секунду, — что
раздают фрукты и где.

Нужен Pillow.
"""
import argparse
import math
import pathlib
import random

from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageFilter, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"
DESIGN = ROOT / "public_html" / "assets" / "design"
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
CYAN_PALE = (198, 240, 255)
INK = (255, 255, 255)
GRAD_A = (132, 205, 255)
GRAD_B = (40, 96, 222)

T_EYEBROW = "В ТЕЛЕГРАМЕ"
T_HEAD = ["РАЗДАЧА", "ФРУКТОВ"]
T_HEAD_ONE = "РАЗДАЧА ФРУКТОВ"
T_TG = "@THEMAKNEMY"

KITSUNE = "giveaway-fruit-kitsune.webp"
MECH = "giveaway-fruit-mech.webp"
GOLD = "giveaway-fruit-gold.webp"

# Раскладка предметов: (арт, мерить по "h" или "w", доля холста, центр в долях
# холста, наклон в градусах, герой ли). Ряд из трёх одинаковых артов в линейку
# читается как таблица товаров, поэтому композиция строится иначе: мех — герой,
# он крупнее, стоит выше и перекрывает соседей, боковые развёрнуты в разные
# стороны, посажены ниже и приглушены на план назад. Порядок в списке — порядок
# отрисовки, герой всегда последний.
LAYOUTS = {
    "strip": [
        (KITSUNE, "h", 0.620, (0.500, 0.590), -13, False),
        (GOLD, "h", 0.600, (0.862, 0.580), 12, False),
        (MECH, "h", 0.820, (0.680, 0.500), -4, True),
    ],
    # У дока пропорции другие (3.2:1 против 4:1), поэтому при тех же долях
    # предметы лезли бы друг на друга: своя строка, размеры мельче.
    "dock": [
        (KITSUNE, "h", 0.550, (0.478, 0.600), -13, False),
        (GOLD, "h", 0.530, (0.845, 0.575), 12, False),
        (MECH, "h", 0.750, (0.672, 0.500), -4, True),
    ],
    "popup": [
        (KITSUNE, "h", 0.265, (0.172, 0.650), -14, False),
        (GOLD, "h", 0.262, (0.828, 0.628), 13, False),
        (MECH, "h", 0.385, (0.500, 0.520), -4, True),
    ],
    # Борт узкий и длинный: предметы идут зигзагом, иначе столбик по центру
    # выглядит списком.
    "rail": [
        (KITSUNE, "w", 0.720, (0.440, 0.305), -12, False),
        (GOLD, "w", 0.660, (0.400, 0.735), 12, False),
        (MECH, "w", 0.800, (0.550, 0.525), -4, True),
    ],
}


# ===========================================================================
#  Базовое
# ===========================================================================

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


def art(name):
    return Image.open(ART / name).convert("RGBA")


def scaled(im, h=None, w=None):
    k = (h / im.height) if h else (w / im.width)
    return im.resize((max(1, round(im.width * k)), max(1, round(im.height * k))), Image.LANCZOS)


def cover(path, w, h, focus=(0.5, 0.36)):
    """Фон сайта, обрезанный по точке интереса и растянутый под холст."""
    im = Image.open(path).convert("RGB")
    k = max(w / im.width, h / im.height)
    im = im.resize((max(1, round(im.width * k)), max(1, round(im.height * k))), Image.LANCZOS)
    x = min(max(0, round(im.width * focus[0] - w / 2)), im.width - w)
    y = min(max(0, round(im.height * focus[1] - h / 2)), im.height - h)
    return im.crop((x, y, x + w, y + h)).convert("RGBA")


def radial_layer(size, cx, cy, rx, ry, colour, strength=150, blur_div=6):
    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=strength)
    mask = mask.filter(ImageFilter.GaussianBlur(max(size) // blur_div))
    layer = Image.new("RGBA", size, colour + (0,))
    layer.putalpha(mask)
    return layer


def rays(size, cx, cy, colour, count=13, alpha=14):
    """Лучи из точки — «товар в свете софитов»."""
    w, h = size
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    span = max(w, h) * 2.4
    for i in range(count):
        a0 = (360 / count) * i
        a1 = a0 + (360 / count) * 0.40
        p = [(cx, cy)]
        for a in (a0, a1):
            r = math.radians(a)
            p.append((cx + span * math.cos(r), cy + span * math.sin(r)))
        d.polygon(p, fill=colour + (alpha,))
    return layer.filter(ImageFilter.GaussianBlur(max(w, h) // 80 + 1))


def sparkles(size, colour, count=40, seed=3, rmin=1.5, rmax=4.5):
    """Искры в воздухе. Сид фиксирован: пересборка не должна менять картинку."""
    rnd = random.Random(seed)
    w, h = size
    layer = Image.new("RGBA", size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    for _ in range(count):
        x, y = rnd.uniform(0, w), rnd.uniform(0, h)
        r = rnd.uniform(rmin, rmax)
        a = rnd.randint(70, 210)
        d.ellipse([x - r, y - r, x + r, y + r], fill=colour + (a,))
        if r > rmax * 0.7:
            d.line([(x - r * 3, y), (x + r * 3, y)], fill=colour + (a // 2,), width=1)
            d.line([(x, y - r * 3), (x, y + r * 3)], fill=colour + (a // 2,), width=1)
    return layer.filter(ImageFilter.GaussianBlur(0.6))


def vignette(img, strength=165, spread=0.62):
    w, h = img.size
    mask = Image.new("L", (w, h), 255)
    ImageDraw.Draw(mask).ellipse([-w * (1 - spread), -h * (1 - spread),
                                  w * (2 - spread), h * (2 - spread)], fill=0)
    mask = mask.filter(ImageFilter.GaussianBlur(max(w, h) // 8))
    dark = Image.new("RGBA", (w, h), (2, 5, 14, 255))
    dark.putalpha(mask.point(lambda v: v * strength // 255))
    img.alpha_composite(dark)
    return img


def scrim(img, top_h=0.0, bottom_h=0.0, alpha=205, colour=(4, 9, 24)):
    """Затемнение под текстом: фон-картинка не имеет права спорить с буквами."""
    w, h = img.size
    for part, height in (("top", top_h), ("bottom", bottom_h)):
        if not height:
            continue
        n = round(h * height)
        layer = Image.new("RGBA", (w, n), colour + (0,))
        m = Image.new("L", (w, n))
        d = ImageDraw.Draw(m)
        for y in range(n):
            k = (1 - y / n) if part == "top" else (y / n)
            d.line([(0, y), (w, y)], fill=round(alpha * k ** 1.5))
        layer.putalpha(m)
        img.alpha_composite(layer, (0, 0 if part == "top" else h - n))
    return img


# ===========================================================================
#  Типографика
# ===========================================================================

def poster_text(img, xy, text, f, anchor="mm", fill_top=INK, fill_bottom=CYAN_PALE,
                stroke_col=(6, 14, 36), glow=CYAN, glow_alpha=150):
    """Заголовок афиши: свечение, тень, тёмный контур, градиентная заливка."""
    w, h = img.size
    px = f.size
    stroke = max(3, round(px * 0.085))

    g = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(g).text(xy, text, font=f, fill=glow + (glow_alpha,), anchor=anchor,
                           stroke_width=stroke + max(3, px // 8), stroke_fill=glow + (glow_alpha,))
    img.alpha_composite(g.filter(ImageFilter.GaussianBlur(max(6, px // 6))))

    sh = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(sh).text((xy[0], xy[1] + max(2, px * 0.07)), text, font=f, fill=(0, 0, 0, 190),
                            anchor=anchor, stroke_width=stroke, stroke_fill=(0, 0, 0, 190))
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(max(3, px // 12))))

    ImageDraw.Draw(img).text(xy, text, font=f, fill=stroke_col + (255,), anchor=anchor,
                             stroke_width=stroke, stroke_fill=stroke_col + (255,))
    mask = Image.new("L", (w, h), 0)
    ImageDraw.Draw(mask).text(xy, text, font=f, fill=255, anchor=anchor)
    img.paste(vgrad(w, h, fill_top, fill_bottom), (0, 0), mask)


def tracked(draw, xy, text, f, fill, spacing):
    """Разрядка: у Bootshaus её нет, поэтому надстрочник рисуется посимвольно."""
    widths = [draw.textlength(c, font=f) for c in text]
    total = sum(widths) + spacing * (len(text) - 1)
    x = xy[0] - total / 2
    for c, cw in zip(text, widths):
        draw.text((x, xy[1]), c, font=f, fill=fill, anchor="lm")
        x += cw + spacing


# ===========================================================================
#  Предметы
# ===========================================================================

def rim(im, colour, width=6, alpha=190):
    """Контровой свет по силуэту — предмет отделяется от тёмного фона."""
    a = im.split()[3]
    grown = a.filter(ImageFilter.MaxFilter(width * 2 + 1))
    edge = ImageChops.subtract(grown, a).filter(ImageFilter.GaussianBlur(width * 0.9))
    layer = Image.new("RGBA", im.size, colour + (0,))
    layer.putalpha(edge.point(lambda v: v * alpha // 255))
    return layer


def darken(im, k):
    """Приглушить арт, не трогая альфу: Brightness множит и её тоже."""
    r, g, b, a = im.split()
    rgb = ImageEnhance.Brightness(Image.merge("RGB", (r, g, b))).enhance(k)
    return Image.merge("RGBA", rgb.split() + (a,))


def pedestal(img, im, x, y, colour=(60, 150, 235)):
    """Свет и короткое отражение под предметом: он стоит, а не висит.

    Меряем по силуэту, а не по холсту: после поворота вокруг арта остаётся
    прозрачная кайма, и без этого свет с отражением уезжали бы вниз.
    """
    w, h = img.size
    bb = im.split()[3].getbbox() or (0, 0, im.width, im.height)
    l, t, r, b = bb
    iw, ih = r - l, b - t
    img.alpha_composite(radial_layer((w, h), x + (l + r) / 2, y + b - ih * 0.04,
                                     iw * 0.46, ih * 0.16, colour, 170, blur_div=14))
    ref = im.crop(bb).transpose(Image.FLIP_TOP_BOTTOM)
    ref = ref.crop((0, 0, iw, round(ih * 0.38)))
    m = Image.new("L", ref.size)
    d = ImageDraw.Draw(m)
    for yy in range(ref.height):
        d.line([(0, yy), (ref.width, yy)], fill=round(64 * (1 - yy / ref.height) ** 2))
    ref.putalpha(ImageChops.multiply(ref.split()[3], m))
    img.alpha_composite(ref.filter(ImageFilter.GaussianBlur(4)), (x + l, y + b))


def place_fruit(img, im, x, y, glow_col=(70, 170, 245)):
    img.alpha_composite(rim(im, glow_col, width=max(3, im.width // 90)), (x, y))
    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    a = im.split()[3].point(lambda v: v * 0.6)
    black = Image.new("RGBA", im.size, (0, 0, 0, 255))
    black.putalpha(a)
    sh.alpha_composite(black, (x + im.width // 40, y + im.height // 26))
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(max(im.size) // 24 + 2)))
    img.alpha_composite(im, (x, y))


def scatter(img, slot):
    """Разложить предметы по таблице LAYOUTS: наклон, план, перекрытия."""
    w, h = img.size
    for name, by, size, (fx, fy), rot, front in LAYOUTS[slot]:
        im = art(name)
        im = scaled(im, h=round(size * h)) if by == "h" else scaled(im, w=round(size * w))
        if not front:
            im = darken(im, 0.92)
        if rot:
            im = im.rotate(rot, resample=Image.BICUBIC, expand=True)
        x = round(fx * w - im.width / 2)
        y = round(fy * h - im.height / 2)
        pedestal(img, im, x, y)
        place_fruit(img, im, x, y, glow_col=(70, 170, 245) if front else (52, 138, 214))


# ===========================================================================
#  Детали
# ===========================================================================

def tg_glyph(size):
    """Самолётик в круге. Свой рисунок, не фирменный знак Telegram."""
    ss = 4
    s = size * ss
    layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    disc = Image.new("L", (s, s), 0)
    ImageDraw.Draw(disc).ellipse([0, 0, s - 1, s - 1], fill=255)
    layer.paste(dgrad(s, s, (97, 181, 233), (45, 74, 237)), (0, 0), disc)
    d = ImageDraw.Draw(layer)
    u = s / 100.0
    d.polygon([(20 * u, 50 * u), (82 * u, 25 * u), (70 * u, 78 * u), (52 * u, 62 * u), (40 * u, 72 * u)],
              fill=INK + (255,))
    d.line([(40 * u, 72 * u), (42 * u, 56 * u), (82 * u, 25 * u)], fill=(10, 20, 48, 255),
           width=round(2.4 * u), joint="curve")
    return layer.resize((size, size), Image.LANCZOS)


def tg_button(img, cx, cy, w, h, text=T_TG):
    """Кнопка канала: тень, градиент, кант и блик — как кнопка в игре."""
    x0, x1 = round(cx - w / 2), round(cx + w / 2)
    y0, y1 = round(cy - h / 2), round(cy + h / 2)

    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle([x0, y0 + h * 0.16, x1, y1 + h * 0.16],
                                         radius=h // 2, fill=(0, 0, 0, 170))
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(max(4, h // 7))))

    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([x0, y0, x1, y1], radius=h // 2, fill=255)
    img.paste(vgrad(img.width, img.height, GRAD_A, GRAD_B), (0, 0), mask)
    ImageDraw.Draw(img).rounded_rectangle([x0, y0, x1, y1], radius=h // 2,
                                          outline=INK + (220,), width=max(2, h // 22))

    gloss = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(gloss).rounded_rectangle([x0 + h * 0.12, y0 + h * 0.13, x1 - h * 0.12, y0 + h * 0.44],
                                            radius=h // 3, fill=(255, 255, 255, 60))
    img.alpha_composite(gloss.filter(ImageFilter.GaussianBlur(max(1, h // 18))))

    d = ImageDraw.Draw(img)
    g = round(h * 0.68)
    f = fit(d, text, w - g - h, round(h * 0.52))
    tw = d.textlength(text, font=f)
    gx = round(cx - (g + h * 0.22 + tw) / 2)
    img.alpha_composite(tg_glyph(g), (gx, round(cy - g / 2)))
    tx, ty = gx + g + h * 0.22, cy - h * 0.04
    d.text((tx, ty), text, font=f, anchor="lm", fill=(9, 20, 52, 255),
           stroke_width=max(1, h // 40), stroke_fill=(9, 20, 52, 255))
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).text((tx, ty), text, font=f, fill=255, anchor="lm")
    img.paste(Image.new("RGBA", img.size, INK + (255,)), (0, 0), mask)


def frame(img, colour, pad, radius, width):
    g = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(g).rounded_rectangle([pad, pad, img.width - pad - 1, img.height - pad - 1],
                                        radius=radius, outline=colour + (150,), width=width * 3)
    img.alpha_composite(g.filter(ImageFilter.GaussianBlur(width * 3)))
    ImageDraw.Draw(img).rounded_rectangle([pad, pad, img.width - pad - 1, img.height - pad - 1],
                                          radius=radius, outline=colour + (225,), width=width)


# ===========================================================================
#  Макеты
# ===========================================================================

def stage(w, h):
    """Сцена: ночной фон сайта, луч света и искры под предметы."""
    img = cover(DESIGN / "page-bg.webp", w, h)
    img = ImageEnhance.Brightness(img).enhance(1.35)
    img = ImageEnhance.Color(img).enhance(1.15)
    img.alpha_composite(Image.new("RGBA", (w, h), (12, 34, 82, 44)))
    img.alpha_composite(rays((w, h), w * 0.5, h * 0.04, (170, 225, 255)))
    img.alpha_composite(radial_layer((w, h), w * 0.5, h * 0.54, w * 0.44, h * 0.34, (40, 120, 210), 105))
    img = scrim(img, top_h=0.30, bottom_h=0.26)
    img.alpha_composite(sparkles((w, h), (190, 235, 255), count=max(18, w * h // 26000)))
    return vignette(img)


def build(slot, w, h):
    img = stage(w, h)
    d = ImageDraw.Draw(img)

    if slot == "popup":
        tracked(d, (w / 2, 76), T_EYEBROW, font(40), CYAN + (255,), 8)
        poster_text(img, (w / 2, 170), T_HEAD_ONE, fit(d, T_HEAD_ONE, w - 120, 116))
        scatter(img, slot)
        tg_button(img, w / 2, 706, 540, 92)
        frame(img, CYAN, 18, 30, 3)
        return img

    if slot == "rail":
        tracked(d, (w / 2, 54), T_EYEBROW, font(30), CYAN + (255,), 5)
        f = fit(d, "ФРУКТОВ", w - 40, 84)
        for i, line in enumerate(T_HEAD):
            poster_text(img, (w / 2, 126 + i * 80), line, f)
        scatter(img, slot)
        tg_button(img, w / 2, h - 82, w - 36, 70)
        frame(img, CYAN, 11, 16, 3)
        return img

    # strip и dock: текст колонкой слева, предметы рядом справа.
    big = slot == "strip"
    left = int(w * 0.055)
    colw = int(w * 0.34)
    cx = left + colw * 0.40
    tracked(d, (cx, int(h * 0.19)), T_EYEBROW, fit(d, T_EYEBROW, colw * 0.8, int(h * 0.115)),
            CYAN + (255,), max(2, int(h * 0.012)))
    f = fit(d, "ФРУКТОВ", colw, int(h * 0.30))
    for i, line in enumerate(T_HEAD):
        poster_text(img, (cx, int(h * (0.42 + 0.235 * i))), line, f)
    tg_button(img, cx, int(h * 0.865), colw * 0.96, int(h * 0.17))
    scatter(img, slot)
    frame(img, CYAN, 16 if big else 10, 18 if big else 12, 3 if big else 2)
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
