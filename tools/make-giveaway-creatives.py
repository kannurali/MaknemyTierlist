"""Креативы кампании «Розыгрыш: 5 permanent Magnet + 50 Chromatic Box».

    python tools/make-giveaway-creatives.py                 # все варианты в out/
    python tools/make-giveaway-creatives.py --variant neon --out public_html/assets/promo

Кампания — собственная (t.me/theMaknemy/5302), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Размеры берутся из CREATIVE_SPECS в api/lib/images.php: файл, который не влез
в потолок слота, сервер либо ужмёт (still), либо отвергнет (анимация).

Исходники — tools/art/giveaway-magnet.webp, giveaway-chromatic.webp и
giveaway-robot.webp: арт предмета Magnet, сундук Chromatic (в самом арте уже
нарисован золотой значок «x50») и робот Update 30, обрезанные по альфе.

Призов теперь два, и это главное требование к макету: оба должны читаться за
секунду. Отсюда золотой значок «×5» у магнита — в арте сундука такой значок
уже есть, и без пары предметы выглядели бы неравноправно.

Нужен Pillow.
"""
import argparse
import pathlib

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"
FONT_PATH = ROOT / "public_html" / "assets" / "fonts" / "Bootshaus" / "Bootshaus-Regular.ttf"
LOGO = ROOT / "public_html" / "assets" / "design" / "logo-mk-square.png"

# Размеры слотов = CREATIVE_SPECS.
SLOTS = {
    "strip": (1200, 300),
    "rail": (320, 1200),
    "dock": (640, 200),
    "popup": (800, 800),
}

# Палитра сайта: base.css :root + градиент кнопок шапки.
CYAN = (79, 214, 255)
CYAN_DEEP = (31, 159, 214)
MK = (214, 90, 255)
INK = (255, 255, 255)
MUTED = (159, 182, 216)
GOLD = (255, 220, 0)
GRAD_A = (97, 181, 233)
GRAD_B = (45, 74, 237)

# Золото значка «x50» из арта сундука — им же рисуем «×5» у магнита, иначе
# два приза выглядят нарисованными в разных играх.
GOLD_HI = (253, 232, 63)
GOLD_LO = (247, 176, 24)
GOLD_INK = (46, 14, 7)

# Тексты. Русский — как у объявления, которое уже стоит в тир-листе.
T_HEAD = "РОЗЫГРЫШ"
T_HEAD_BIG = "МЕГА-РОЗЫГРЫШ"
T_PRIZE_A = "5 PERMANENT MAGNET"
T_PRIZE_B = "50 CHROMATIC BOX"
T_PRIZE_A2 = ["5 PERMANENT", "MAGNET"]
T_PRIZE_B2 = ["50 CHROMATIC", "BOX"]
T_PRIZES = "2 ПРИЗА"
T_CTA = "УЧАСТВОВАТЬ · @THEMAKNEMY"
T_TG = "@THEMAKNEMY"
T_UPD = "В ЧЕСТЬ UPDATE 30"


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


def hgrad(w, h, a, b):
    img = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(img)
    for x in range(w):
        k = x / max(1, w - 1)
        d.line([(x, 0), (x, h)], fill=tuple(round(a[i] + (b[i] - a[i]) * k) for i in range(3)))
    return img.convert("RGBA")


def halftone(w, h, pitch, colour=(122, 176, 233), alpha=42):
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
    """Лучи из точки — «приз в свете софитов»."""
    import math
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


def stripes(w, h, colour, pitch=46, alpha=38, thick=0.5):
    """Диагональная «лента конкурса»."""
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    for x in range(-h, w + h, pitch):
        d.polygon([(x, 0), (x + pitch * thick, 0), (x + pitch * thick - h, h), (x - h, h)],
                  fill=colour + (alpha,))
    return layer


def text_glow(img, xy, text, f, fill, glow_col, blur, anchor="mm", stroke=0, stroke_fill=None):
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(layer).text(xy, text, font=f, fill=glow_col + (255,), anchor=anchor,
                               stroke_width=stroke + max(2, blur // 2), stroke_fill=glow_col + (255,))
    img.alpha_composite(layer.filter(ImageFilter.GaussianBlur(blur)))
    ImageDraw.Draw(img).text(xy, text, font=f, fill=fill + (255,), anchor=anchor,
                             stroke_width=stroke, stroke_fill=stroke_fill)


def text_grad(img, xy, text, f, grad, anchor="mm", stroke=0, stroke_fill=None):
    """Текст, залитый градиентом (картинка того же размера, что холст)."""
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).text(xy, text, font=f, fill=255, anchor=anchor,
                              stroke_width=stroke, stroke_fill=255 if stroke_fill is None else 255)
    img.paste(grad, (0, 0), mask)


def gold_num(img, xy, text, px, anchor="mm"):
    """Число в стиле игрового значка: золотой градиент, тёмный контур, тень.

    Ровно так в арте сундука нарисовано «x50»; значок магнита рисуем сами,
    иначе один приз выглядит помеченным, а второй — нет.
    """
    f = font(px)
    stroke = max(2, round(px * 0.075))
    sh = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).text((xy[0] + px * 0.05, xy[1] + px * 0.09), text, font=f,
                            fill=(0, 0, 0, 190), anchor=anchor, stroke_width=stroke,
                            stroke_fill=(0, 0, 0, 190))
    img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(max(2, px // 14))))
    ImageDraw.Draw(img).text(xy, text, font=f, fill=GOLD_INK + (255,), anchor=anchor,
                             stroke_width=stroke, stroke_fill=GOLD_INK + (255,))
    text_grad(img, xy, text, f, vgrad(img.width, img.height, GOLD_HI, GOLD_LO), anchor=anchor)


def art(name):
    return Image.open(ART / name).convert("RGBA")


def scaled(im, h=None, w=None):
    if h:
        k = h / im.height
    else:
        k = w / im.width
    return im.resize((max(1, round(im.width * k)), max(1, round(im.height * k))), Image.LANCZOS)


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


def panel(img, box, radius, fill=(11, 20, 44, 168), outline=None, width=2):
    """Стеклянная карточка под предмет — граница между двумя призами."""
    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    d.rounded_rectangle(box, radius=radius, fill=fill,
                        outline=(outline + (190,)) if outline else None, width=width)
    img.alpha_composite(layer)


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


def gift_mark(size, body=(255, 90, 160), ribbon=GOLD):
    """Подарочная коробка — самый быстрый знак «это розыгрыш»."""
    ss = 4
    s = size * ss
    layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    u = s / 100.0
    d.rounded_rectangle([10 * u, 34 * u, 90 * u, 92 * u], radius=6 * u, fill=body + (255,))
    d.rounded_rectangle([4 * u, 22 * u, 96 * u, 44 * u], radius=5 * u, fill=tuple(min(255, c + 26) for c in body) + (255,))
    d.rectangle([42 * u, 22 * u, 58 * u, 92 * u], fill=ribbon + (255,))
    d.ellipse([22 * u, 6 * u, 52 * u, 30 * u], outline=ribbon + (255,), width=round(6 * u))
    d.ellipse([48 * u, 6 * u, 78 * u, 30 * u], outline=ribbon + (255,), width=round(6 * u))
    return layer.resize((size, size), Image.LANCZOS)


# ===========================================================================
#  Фоны вариантов
# ===========================================================================

def bg_neon(w, h):
    img = vgrad(w, h, (20, 40, 78), (7, 12, 30))
    img = radial(img, w * 0.72, h * 0.5, w * 0.42, h * 0.62, (46, 96, 176), 120)
    dots = halftone(w, h, max(14, min(w, h) // 18))
    img.alpha_composite(dots)
    return img


def bg_ticket(w, h):
    img = dgrad(w, h, (44, 30, 96), (12, 16, 44))
    img = radial(img, w * 0.22, h * 0.5, w * 0.45, h * 0.6, (150, 40, 150), 110)
    img = radial(img, w * 0.85, h * 0.45, w * 0.4, h * 0.6, (30, 70, 190), 110)
    img.alpha_composite(stripes(w, h, (255, 255, 255), pitch=max(28, min(w, h) // 9), alpha=16))
    return img


def bg_boss(w, h):
    img = vgrad(w, h, (10, 18, 42), (4, 6, 16))
    img.alpha_composite(rays(w, h, w * 0.5, h * 0.46, CYAN, count=16, alpha=20))
    img = radial(img, w * 0.5, h * 0.5, w * 0.36, h * 0.44, (24, 74, 150), 120)
    return img


def frame(img, colour, pad, radius, width):
    ImageDraw.Draw(img).rounded_rectangle([pad, pad, img.width - pad - 1, img.height - pad - 1],
                                          radius=radius, outline=colour + (210,), width=width)


# ===========================================================================
#  Вариант 1 — «ДУЭТ»: два приза равными карточками, у каждого свой значок.
# ===========================================================================

def duo(slot, w, h):
    img = bg_neon(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")
    chest = art("giveaway-chromatic.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        pad = 18 if big else 11
        frame(img, CYAN, pad, 18 if big else 12, 3 if big else 2)

        # Левая колонка — заголовок, правая — две карточки с призами.
        left = int(w * 0.045)
        colw = int(w * 0.275)
        d.text((left, int(h * 0.20)), T_UPD, font=fit(d, T_UPD, colw, int(h * 0.115)),
               fill=CYAN + (255,), anchor="lm")
        text_glow(img, (left, int(h * 0.48)), T_HEAD, fit(d, T_HEAD, colw, int(h * 0.34)),
                  INK, CYAN, max(5, h // 24), anchor="lm")
        d = ImageDraw.Draw(img)
        # Плашка не имеет права заехать под карточки: подпись ужимаем до
        # колонки, а на узком доке от неё остаётся только адрес канала.
        cta = T_CTA if big else T_TG
        f_cta = fit(d, cta, colw - int(h * 0.24), int(h * 0.11))
        cw = d.textlength(cta, font=f_cta)
        chip(img, [left, int(h * 0.70), left + cw + int(h * 0.22), int(h * 0.87)], cta, f_cta,
             bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)

        # Две карточки одинаковой ширины: приз слева от «+», приз справа.
        cx0 = int(w * 0.365)
        gap = int(w * 0.055)
        cardw = (w - cx0 - int(w * 0.032) - gap) // 2
        cardh = int(h * 0.80)
        top = (h - cardh) // 2
        for i, (im, name, num, tint) in enumerate((
                (magnet, "PERMANENT MAGNET", "×5", CYAN),
                (chest, "CHROMATIC BOX", None, MK))):
            x0 = cx0 + i * (cardw + gap)
            panel(img, [x0, top, x0 + cardw, top + cardh], radius=int(h * 0.07), outline=tint)
            a = scaled(im, h=int(cardh * 0.64))
            if a.width > cardw - 24:
                a = scaled(a, w=cardw - 24)
            drop(img, a, x0 + (cardw - a.width) // 2, top + int(cardh * 0.05))
            if num:
                gold_num(img, (x0 + cardw - int(cardw * 0.17), top + int(cardh * 0.56)),
                         num, int(cardh * 0.26))
            d = ImageDraw.Draw(img)
            d.text((x0 + cardw / 2, top + int(cardh * 0.86)), name,
                   font=fit(d, name, cardw - 20, int(h * 0.12)), fill=INK + (255,), anchor="mm")
        d = ImageDraw.Draw(img)
        gold_num(img, (cx0 + cardw + gap / 2, h / 2), "+", int(h * 0.21))
        return img

    if slot == "rail":
        frame(img, CYAN, 12, 14, 3)
        d.text((w / 2, 52), T_UPD, font=fit(d, T_UPD, w - 46, 30), fill=CYAN + (255,), anchor="mm")
        text_glow(img, (w / 2, 116), T_HEAD, fit(d, T_HEAD, w - 40, 76), INK, CYAN, 12, anchor="mm")
        d = ImageDraw.Draw(img)
        d.text((w / 2, 176), T_PRIZES, font=fit(d, T_PRIZES, w - 90, 46), fill=MK + (255,), anchor="mm")

        y = 214
        cardh = 366
        for im, name, num, tint in ((magnet, "PERMANENT MAGNET", "×5", CYAN),
                                    (chest, "CHROMATIC BOX", None, MK)):
            panel(img, [16, y, w - 16, y + cardh], radius=22, outline=tint)
            a = scaled(im, w=int(w * 0.80))
            if a.height > cardh * 0.68:
                a = scaled(a, h=int(cardh * 0.68))
            drop(img, a, (w - a.width) // 2, y + 20)
            if num:
                gold_num(img, (w - 62, y + int(cardh * 0.60)), num, 96)
            d = ImageDraw.Draw(img)
            for i, line in enumerate(name.split(" ")):
                d.text((w / 2, y + cardh - 74 + i * 40), line,
                       font=fit(d, line, w - 40, 40), fill=INK + (255,), anchor="mm")
            y += cardh + 22

        g = tg_glyph(56)
        img.alpha_composite(g, ((w - 56) // 2, h - 148))
        d = ImageDraw.Draw(img)
        d.text((w / 2, h - 66), T_TG, font=fit(d, T_TG, w - 40, 40), fill=INK + (255,), anchor="mm")
        d.text((w / 2, h - 32), "УЧАСТВОВАТЬ", font=fit(d, "УЧАСТВОВАТЬ", w - 60, 30),
               fill=CYAN + (255,), anchor="mm")
        return img

    # popup 800×800
    frame(img, CYAN, 20, 32, 3)
    d.text((w / 2, 70), T_UPD, font=font(34), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 146), T_HEAD, fit(d, T_HEAD, w - 130, 122), INK, CYAN, 16, anchor="mm")
    d = ImageDraw.Draw(img)
    d.text((w / 2, 214), T_PRIZES, font=font(44), fill=MK + (255,), anchor="mm")

    cardw, cardh, top = 336, 348, 248
    for i, (im, name, num, tint) in enumerate(((magnet, "PERMANENT MAGNET", "×5", CYAN),
                                               (chest, "CHROMATIC BOX", None, MK))):
        x0 = 42 + i * (cardw + 44)
        panel(img, [x0, top, x0 + cardw, top + cardh], radius=28, outline=tint)
        a = scaled(im, w=int(cardw * 0.86))
        if a.height > cardh * 0.66:
            a = scaled(a, h=int(cardh * 0.66))
        drop(img, a, x0 + (cardw - a.width) // 2, top + 18)
        if num:
            gold_num(img, (x0 + cardw - 58, top + int(cardh * 0.60)), num, 98)
        d = ImageDraw.Draw(img)
        for j, line in enumerate(name.split(" ")):
            d.text((x0 + cardw / 2, top + cardh - 78 + j * 42), line,
                   font=fit(d, line, cardw - 30, 44), fill=INK + (255,), anchor="mm")
    d = ImageDraw.Draw(img)
    gold_num(img, (w / 2, top + cardh * 0.44), "+", 92)

    d = ImageDraw.Draw(img)
    f_cta = font(38)
    cw = d.textlength(T_TG, font=f_cta)
    chip(img, [(w - cw) / 2 - 104, 664, (w + cw) / 2 + 44, 726], T_TG, f_cta,
         bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)
    img.alpha_composite(tg_glyph(46), (int((w - cw) / 2 - 90), 672))
    d.text((w / 2, 756), "УЧАСТВОВАТЬ", font=font(32), fill=CYAN + (255,), anchor="mm")
    return img


# ===========================================================================
#  Вариант 2 — «БИЛЕТ»: золотой корешок, числа призов вместо картинки-героя.
# ===========================================================================

def ticket(slot, w, h):
    img = bg_ticket(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")
    chest = art("giveaway-chromatic.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        # Левый корешок билета: сумма призов одним числом, как номинал.
        band_w = int(w * 0.245)
        band = Image.new("RGBA", img.size, (0, 0, 0, 0))
        ImageDraw.Draw(band).polygon([(0, 0), (band_w, 0), (band_w - int(h * 0.22), h), (0, h)],
                                     fill=GOLD + (255,))
        img.alpha_composite(band)
        d = ImageDraw.Draw(img)
        d.text((band_w * 0.40, h * 0.36), "5+50", font=fit(d, "5+50", band_w * 0.76, int(h * 0.44)),
               fill=(18, 14, 40, 255), anchor="mm")
        d.text((band_w * 0.40, h * 0.74), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", band_w * 0.62, int(h * 0.17)),
               fill=(18, 14, 40, 255), anchor="mm")

        c = scaled(chest, h=int(h * 0.66))
        drop(img, c, w - c.width - int(w * 0.014), int(h * 0.30))
        m = scaled(magnet, h=int(h * (0.70 if big else 0.62)))
        drop(img, m, w - c.width - m.width + int(w * (0.038 if big else 0.075)), int(h * 0.06))
        gold_num(img, (w - c.width - int(w * 0.020), int(h * 0.62)), "×5", int(h * 0.19))

        left = band_w + int(w * 0.028)
        colw = int(w * 0.36)
        d = ImageDraw.Draw(img)
        text_glow(img, (left, int(h * 0.26)), T_HEAD, fit(d, T_HEAD, colw, int(h * 0.36)),
                  INK, (255, 60, 170), max(5, h // 26), anchor="lm")
        d = ImageDraw.Draw(img)
        d.text((left, int(h * 0.52)), T_PRIZE_A, font=fit(d, T_PRIZE_A, colw, int(h * 0.17)),
               fill=GOLD + (255,), anchor="lm")
        d.text((left, int(h * 0.68)), T_PRIZE_B, font=fit(d, T_PRIZE_B, colw, int(h * 0.17)),
               fill=GOLD + (255,), anchor="lm")
        cta = T_CTA if big else T_TG
        f_cta = fit(d, cta, colw - int(h * 0.24), int(h * 0.11))
        cw = d.textlength(cta, font=f_cta)
        chip(img, [left, int(h * 0.80), left + cw + int(h * 0.24), int(h * 0.96)], cta, f_cta,
             bg=INK, fg=(18, 14, 40))
        return img

    if slot == "rail":
        band_h = 336
        band = Image.new("RGBA", img.size, (0, 0, 0, 0))
        ImageDraw.Draw(band).polygon([(0, 0), (w, 0), (w, band_h), (0, band_h - 46)], fill=GOLD + (255,))
        img.alpha_composite(band)
        d = ImageDraw.Draw(img)
        d.text((w / 2, 90), "5 + 50", font=fit(d, "5 + 50", w - 40, 150), fill=(18, 14, 40, 255), anchor="mm")
        d.text((w / 2, 200), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", w - 60, 62), fill=(18, 14, 40, 255), anchor="mm")
        d.text((w / 2, 268), T_UPD, font=fit(d, T_UPD, w - 50, 34), fill=(56, 42, 14, 255), anchor="mm")

        text_glow(img, (w / 2, 402), T_HEAD, fit(d, T_HEAD, w - 34, 78), INK, (255, 60, 170), 12, anchor="mm")
        d = ImageDraw.Draw(img)

        # Вертикальный бюджет: два предмета с подписями и плашка внизу.
        # 1200 px хватает ровно, если каждый предмет не выше 230.
        y = 426
        for im, lines, num, art_h in ((magnet, T_PRIZE_A2, "×5", 230),
                                      (chest, T_PRIZE_B2, None, 210)):
            a = scaled(im, w=int(w * 0.90))
            if a.height > art_h:
                a = scaled(a, h=art_h)
            drop(img, a, (w - a.width) // 2, y)
            if num:
                gold_num(img, (w - 52, y + int(a.height * 0.80)), num, 80)
            d = ImageDraw.Draw(img)
            yy = y + a.height + 18
            for i, line in enumerate(lines):
                d.text((w / 2, yy + i * 42), line, font=fit(d, line, w - 30, 44),
                       fill=GOLD + (255,), anchor="mm")
            y = yy + len(lines) * 42 + 20

        chip(img, [22, h - 128, w - 22, h - 58], T_TG, font(38), bg=INK, fg=(18, 14, 40), radius=14)
        return img

    # popup
    band = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(band).polygon([(0, 0), (w, 0), (w, 196), (0, 244)], fill=GOLD + (255,))
    img.alpha_composite(band)
    d = ImageDraw.Draw(img)
    d.text((236, 104), "5 + 50", font=font(150), fill=(18, 14, 40, 255), anchor="mm")
    d.text((582, 78), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", 340, 92), fill=(18, 14, 40, 255), anchor="mm")
    d.text((582, 156), "УЧАСТВУЙ", font=fit(d, "УЧАСТВУЙ", 340, 62), fill=(48, 36, 12, 255), anchor="mm")

    text_glow(img, (w / 2, 318), T_HEAD, fit(d, T_HEAD, w - 120, 122), INK, (255, 60, 170), 16, anchor="mm")
    d = ImageDraw.Draw(img)
    m = scaled(magnet, h=232)
    c = scaled(chest, h=214)
    drop(img, m, 44, 388)
    drop(img, c, w - c.width - 34, 402)
    gold_num(img, (m.width + 28, 566), "×5", 92)
    d = ImageDraw.Draw(img)
    gold_num(img, (w / 2, 486), "+", 84)
    d = ImageDraw.Draw(img)
    d.text((w / 2, 656), T_PRIZE_A, font=fit(d, T_PRIZE_A, w - 110, 62), fill=GOLD + (255,), anchor="mm")
    d.text((w / 2, 706), T_PRIZE_B, font=fit(d, T_PRIZE_B, w - 110, 62), fill=GOLD + (255,), anchor="mm")
    chip(img, [140, 736, w - 140, 782], T_TG, font(34), bg=INK, fg=(18, 14, 40), radius=14)
    return img


# ===========================================================================
#  Вариант 3 — «БОСС»: развитие того, что стоит на сайте, плюс второй приз.
# ===========================================================================

def boss(slot, w, h):
    img = bg_boss(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")
    chest = art("giveaway-chromatic.webp")
    robot = art("giveaway-robot.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        r = scaled(robot, h=int(h * 0.94))
        drop(img, r, int(w * 0.42), int(h * 0.04))
        c = scaled(chest, h=int(h * 0.60))
        drop(img, c, w - c.width - int(w * 0.012), int(h * 0.36))
        m = scaled(magnet, h=int(h * 0.62))
        drop(img, m, w - c.width - m.width + int(w * 0.040), int(h * 0.02))
        gold_num(img, (w - c.width - int(w * 0.018), int(h * 0.28)), "×5", int(h * 0.23))

        # Затемнение слева, чтобы текст лёг на арт.
        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, int(w * 0.47), h], fill=(4, 8, 22, 226))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(w // 26)))

        left = int(w * 0.045)
        colw = int(w * 0.40)
        d = ImageDraw.Draw(img)
        d.text((left, int(h * 0.15)), T_HEAD_BIG, font=fit(d, T_HEAD_BIG, colw, int(h * 0.20)),
               fill=CYAN + (255,), anchor="lm")
        text_glow(img, (left, int(h * 0.42)), T_PRIZE_A, fit(d, T_PRIZE_A, colw, int(h * 0.26)),
                  INK, CYAN, max(4, h // 30), anchor="lm")
        d = ImageDraw.Draw(img)
        text_glow(img, (left, int(h * 0.68)), T_PRIZE_B, fit(d, T_PRIZE_B, colw, int(h * 0.26)),
                  INK, MK, max(4, h // 30), anchor="lm")
        d = ImageDraw.Draw(img)
        d.text((left, int(h * 0.90)), T_CTA, font=fit(d, T_CTA, colw, int(h * 0.10)),
               fill=MUTED + (255,), anchor="lm")
        return img

    if slot == "rail":
        # Предметы идут каскадом и заканчиваются выше 940 — ниже начинается
        # нижняя вуаль с названием второго приза, и наезжать на неё нельзя.
        r = scaled(robot, w=int(w * 1.10))
        drop(img, r, (w - r.width) // 2, 240)
        m = scaled(magnet, w=int(w * 0.72))
        drop(img, m, 4, 520)
        gold_num(img, (w - 74, 612), "×5", 78)
        c = scaled(chest, w=int(w * 0.84))
        drop(img, c, w - int(w * 0.84) - 4, 660)

        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, w, 268], fill=(4, 8, 22, 190))
        ImageDraw.Draw(veil).rectangle([0, h - 268, w, h], fill=(4, 8, 22, 206))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(28)))
        d = ImageDraw.Draw(img)
        d.text((w / 2, 60), "МЕГА", font=fit(d, "МЕГА", w - 60, 70), fill=CYAN + (255,), anchor="mm")
        text_glow(img, (w / 2, 134), T_HEAD, fit(d, T_HEAD, w - 30, 78), INK, CYAN, 12, anchor="mm")
        d = ImageDraw.Draw(img)
        for i, line in enumerate(T_PRIZE_A2):
            d.text((w / 2, 198 + i * 44), line, font=fit(d, line, w - 40, 46),
                   fill=INK + (255,), anchor="mm")
        for i, line in enumerate(T_PRIZE_B2):
            text_glow(img, (w / 2, h - 236 + i * 58), line, fit(d, line, w - 30, 60), INK,
                      MK, 9, anchor="mm")
        d = ImageDraw.Draw(img)
        d.text((w / 2, h - 96), T_TG, font=fit(d, T_TG, w - 40, 42), fill=INK + (255,), anchor="mm")
        d.text((w / 2, h - 50), "УЧАСТВОВАТЬ", font=fit(d, "УЧАСТВОВАТЬ", w - 60, 32),
               fill=CYAN + (255,), anchor="mm")
        return img

    # popup
    r = scaled(robot, h=340)
    drop(img, r, (w - r.width) // 2, 184)
    m = scaled(magnet, h=236)
    drop(img, m, 4, 306)
    gold_num(img, (210, 470), "×5", 82)
    c = scaled(chest, h=222)
    drop(img, c, w - c.width - 4, 336)
    veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(veil).rectangle([0, 0, w, 190], fill=(4, 8, 22, 202))
    ImageDraw.Draw(veil).rectangle([0, h - 226, w, h], fill=(4, 8, 22, 212))
    img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(34)))
    d = ImageDraw.Draw(img)
    d.text((w / 2, 62), T_HEAD_BIG, font=fit(d, T_HEAD_BIG, w - 120, 76), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 138), T_PRIZE_A, fit(d, T_PRIZE_A, w - 80, 82), INK, CYAN, 13, anchor="mm")
    d = ImageDraw.Draw(img)
    text_glow(img, (w / 2, 622), T_PRIZE_B, fit(d, T_PRIZE_B, w - 80, 82), INK, MK, 13, anchor="mm")
    d = ImageDraw.Draw(img)
    d.text((w / 2, 678), T_UPD, font=font(34), fill=MUTED + (255,), anchor="mm")
    f_cta = font(40)
    cw = d.textlength(T_TG, font=f_cta)
    chip(img, [(w - cw) / 2 - 104, 706, (w + cw) / 2 + 44, 768], T_TG, f_cta,
         bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)
    img.alpha_composite(tg_glyph(46), (int((w - cw) / 2 - 90), 714))
    return img


BUILDERS = {"duo": duo, "ticket": ticket, "boss": boss}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--variant", choices=sorted(BUILDERS), action="append")
    ap.add_argument("--out", default=str(ROOT / "tools" / "out" / "giveaway"))
    ap.add_argument("--prefix", default="")
    ap.add_argument("--as", dest="as_name", default=None,
                    help="имя файла вместо имени варианта: --as giveaway -> giveaway-strip.webp")
    ap.add_argument("--png", action="store_true", help="писать PNG вместо WebP (для превью)")
    args = ap.parse_args()

    variants = args.variant or sorted(BUILDERS)
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    for v in variants:
        for slot, (w, h) in SLOTS.items():
            img = BUILDERS[v](slot, w, h).convert("RGB")
            name = f"{args.prefix}{args.as_name or v}-{slot}." + ("png" if args.png else "webp")
            path = out / name
            if args.png:
                img.save(path)
            else:
                img.save(path, "WEBP", quality=88, method=6)
            print(f"  {path.relative_to(ROOT) if path.is_relative_to(ROOT) else path}  {w}x{h}  {path.stat().st_size // 1024} KB")


if __name__ == "__main__":
    main()
