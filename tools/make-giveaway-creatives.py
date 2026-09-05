"""Креативы кампании «Розыгрыш 5 permanent Magnet» под все рекламные слоты.

    python tools/make-giveaway-creatives.py                 # все варианты в out/
    python tools/make-giveaway-creatives.py --variant neon --out public_html/assets/promo

Кампания — собственная (t.me/theMaknemy/5302), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Размеры берутся из CREATIVE_SPECS в api/lib/images.php: файл, который не влез
в потолок слота, сервер либо ужмёт (still), либо отвергнет (анимация).

Исходники — tools/art/giveaway-magnet.webp и giveaway-robot.webp: арт предмета
Magnet и робота Update 30, обрезанные по альфе.

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

# Тексты. Русский — как у объявления, которое уже стоит в тир-листе.
T_HEAD = "РОЗЫГРЫШ"
T_PRIZE = "5 × PERMANENT MAGNET"
T_PRIZE_2 = ["5 ×", "PERMANENT", "MAGNET"]
T_WINNERS = "5 ПОБЕДИТЕЛЕЙ"
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
#  Вариант 1 — «НЕОН»: палитра сайта, приз справа, заголовок слева.
# ===========================================================================

def neon(slot, w, h):
    img = bg_neon(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")
    robot = art("giveaway-robot.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        pad = 22 if big else 14
        frame(img, CYAN, pad, 18 if big else 12, 3 if big else 2)

        m = scaled(magnet, h=int(h * (1.02 if big else 0.96)))
        r = scaled(robot, h=int(h * (0.70 if big else 0.62)))
        drop(img, r, w - r.width - pad - 6, h - r.height - pad - 4)
        drop(img, m, w - m.width - int(w * (0.15 if big else 0.19)), int(h * 0.01))

        left = int(w * 0.055)
        colw = int(w * (0.60 if big else 0.56)) - left
        f_top = font(int(h * (0.105 if big else 0.11)))
        d.text((left, int(h * 0.17)), T_UPD, font=f_top, fill=CYAN + (255,), anchor="lm")
        f_head = fit(d, T_HEAD, colw, int(h * 0.42))
        text_glow(img, (left, int(h * 0.47)), T_HEAD, f_head, INK, CYAN, max(6, h // 22), anchor="lm")
        d = ImageDraw.Draw(img)
        f_prize = fit(d, T_PRIZE, colw, int(h * 0.17))
        grad = hgrad(w, h, CYAN, MK)
        text_grad(img, (left, int(h * 0.72)), T_PRIZE, f_prize, grad, anchor="lm")
        d = ImageDraw.Draw(img)
        f_cta = font(int(h * (0.093 if big else 0.10)))
        cw = d.textlength(T_CTA, font=f_cta)
        chip(img, [left, int(h * 0.82), left + cw + int(h * 0.22), int(h * 0.96)], T_CTA, f_cta,
             bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)
        return img

    if slot == "rail":
        frame(img, CYAN, 12, 14, 3)
        d.text((w / 2, 54), T_UPD, font=fit(d, T_UPD, w - 46, 30), fill=CYAN + (255,), anchor="mm")
        f_head = fit(d, T_HEAD, w - 40, 74)
        text_glow(img, (w / 2, 118), T_HEAD, f_head, INK, CYAN, 12, anchor="mm")
        m = scaled(magnet, w=int(w * 0.98))
        drop(img, m, (w - m.width) // 2, 168)
        d = ImageDraw.Draw(img)
        y = 168 + m.height + 14
        grad = vgrad(w, h, CYAN, MK)
        for i, line in enumerate(["5 ШТУК", "PERMANENT", "MAGNET"]):
            f = fit(d, line, w - 44, 78)
            text_grad(img, (w / 2, y + i * 74), line, f, grad, anchor="mm")
        d = ImageDraw.Draw(img)
        y += 3 * 74 + 6
        d.text((w / 2, y), T_WINNERS, font=fit(d, T_WINNERS, w - 46, 40), fill=INK + (255,), anchor="mm")
        rb = scaled(robot, w=int(w * 0.86))
        drop(img, rb, (w - rb.width) // 2, y + 34)
        g = tg_glyph(58)
        img.alpha_composite(g, ((w - 58) // 2, h - 150))
        d = ImageDraw.Draw(img)
        d.text((w / 2, h - 66), T_TG, font=fit(d, T_TG, w - 40, 40), fill=INK + (255,), anchor="mm")
        d.text((w / 2, h - 32), "УЧАСТВОВАТЬ", font=fit(d, "УЧАСТВОВАТЬ", w - 60, 30),
               fill=CYAN + (255,), anchor="mm")
        return img

    # popup 800×800
    frame(img, CYAN, 20, 32, 3)
    d.text((w / 2, 74), T_UPD, font=font(34), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 150), T_HEAD, fit(d, T_HEAD, w - 130, 126), INK, CYAN, 16, anchor="mm")
    d = ImageDraw.Draw(img)
    m = scaled(magnet, h=330)
    r = scaled(robot, h=250)
    drop(img, r, w - r.width - 40, 250)
    drop(img, m, 28, 214)
    d = ImageDraw.Draw(img)
    grad = hgrad(w, h, CYAN, MK)
    text_grad(img, (w / 2, 594), T_PRIZE, fit(d, T_PRIZE, w - 90, 72), grad, anchor="mm")
    d = ImageDraw.Draw(img)
    d.text((w / 2, 654), T_WINNERS, font=font(40), fill=INK + (255,), anchor="mm")
    f_cta = font(38)
    cw = d.textlength(T_TG, font=f_cta)
    chip(img, [(w - cw) / 2 - 104, 700, (w + cw) / 2 + 44, 762], T_TG, f_cta,
         bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)
    g = tg_glyph(46)
    img.alpha_composite(g, (int((w - cw) / 2 - 90), 708))
    return img


# ===========================================================================
#  Вариант 2 — «БИЛЕТ»: контрастный, гигантская пятёрка, подарок.
# ===========================================================================

def ticket(slot, w, h):
    img = bg_ticket(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        # Левый блок «×5» на жёлтом поле, скошенный, как корешок билета.
        band_w = int(w * 0.26)
        band = Image.new("RGBA", img.size, (0, 0, 0, 0))
        ImageDraw.Draw(band).polygon([(0, 0), (band_w, 0), (band_w - int(h * 0.22), h), (0, h)],
                                     fill=GOLD + (255,))
        img.alpha_composite(band)
        d = ImageDraw.Draw(img)
        f5 = font(int(h * 0.78))
        d.text((band_w * 0.46, h * 0.54), "5", font=f5, fill=(18, 14, 40, 255), anchor="mm")
        d.text((band_w * 0.46, h * 0.86), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", band_w * 0.78, int(h * 0.12)),
               fill=(18, 14, 40, 255), anchor="mm")

        m = scaled(magnet, h=int(h * (1.02 if big else 0.94)))
        drop(img, m, w - m.width - int(w * 0.015), int(h * (-0.02 if big else 0.03)))

        left = band_w + int(w * 0.035)
        colw = int(w * (0.68 if big else 0.63)) - left
        f_head = fit(d, T_HEAD, colw, int(h * 0.40))
        text_glow(img, (left, int(h * 0.31)), T_HEAD, f_head, INK, (255, 60, 170), max(5, h // 26),
                  anchor="lm")
        d = ImageDraw.Draw(img)
        f_prize = fit(d, "PERMANENT MAGNET", colw, int(h * 0.20))
        d.text((left, int(h * 0.60)), "PERMANENT MAGNET", font=f_prize, fill=GOLD + (255,), anchor="lm")
        f_cta = font(int(h * 0.11))
        cw = d.textlength(T_CTA, font=f_cta)
        chip(img, [left, int(h * 0.74), left + cw + int(h * 0.24), int(h * 0.92)], T_CTA, f_cta,
             bg=INK, fg=(18, 14, 40))
        return img

    if slot == "rail":
        band_h = 300
        band = Image.new("RGBA", img.size, (0, 0, 0, 0))
        ImageDraw.Draw(band).polygon([(0, 0), (w, 0), (w, band_h), (0, band_h - 46)], fill=GOLD + (255,))
        img.alpha_composite(band)
        d = ImageDraw.Draw(img)
        d.text((w / 2, 120), "5", font=font(230), fill=(18, 14, 40, 255), anchor="mm")
        d.text((w / 2, 244), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", w - 60, 58), fill=(18, 14, 40, 255), anchor="mm")

        f_head = fit(d, T_HEAD, w - 34, 78)
        text_glow(img, (w / 2, 366), T_HEAD, f_head, INK, (255, 60, 170), 12, anchor="mm")
        d = ImageDraw.Draw(img)
        m = scaled(magnet, w=int(w * 1.02))
        drop(img, m, (w - m.width) // 2, 420)
        d = ImageDraw.Draw(img)
        y = 420 + m.height + 10
        for i, line in enumerate(["PERMANENT", "MAGNET"]):
            d.text((w / 2, y + i * 66), line, font=fit(d, line, w - 40, 68), fill=GOLD + (255,), anchor="mm")
        y += 150
        gift = gift_mark(120)
        img.alpha_composite(gift, ((w - 120) // 2, y))
        d = ImageDraw.Draw(img)
        d.text((w / 2, y + 168), T_WINNERS, font=fit(d, T_WINNERS, w - 40, 42), fill=INK + (255,), anchor="mm")
        f_cta = font(38)
        chip(img, [22, h - 96, w - 22, h - 34], T_TG, f_cta, bg=INK, fg=(18, 14, 40), radius=14)
        return img

    # popup
    band = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(band).polygon([(0, 0), (w, 0), (w, 196), (0, 244)], fill=GOLD + (255,))
    img.alpha_composite(band)
    d = ImageDraw.Draw(img)
    d.text((150, 108), "5", font=font(210), fill=(18, 14, 40, 255), anchor="mm")
    d.text((470, 78), "ПРИЗОВ", font=fit(d, "ПРИЗОВ", 400, 92), fill=(18, 14, 40, 255), anchor="mm")
    d.text((470, 156), "УЧАСТВУЙ", font=fit(d, "УЧАСТВУЙ", 400, 62), fill=(48, 36, 12, 255), anchor="mm")

    f_head = fit(d, T_HEAD, w - 120, 122)
    text_glow(img, (w / 2, 320), T_HEAD, f_head, INK, (255, 60, 170), 16, anchor="mm")
    d = ImageDraw.Draw(img)
    m = scaled(magnet, h=272)
    drop(img, m, (w - m.width) // 2, 372)
    d = ImageDraw.Draw(img)
    d.text((w / 2, 672), "PERMANENT MAGNET", font=fit(d, "PERMANENT MAGNET", w - 110, 78),
           fill=GOLD + (255,), anchor="mm")
    f_cta = font(40)
    chip(img, [140, 714, w - 140, 776], T_TG, f_cta, bg=INK, fg=(18, 14, 40), radius=16)
    return img


# ===========================================================================
#  Вариант 3 — «БОСС»: кинематографичный постер с роботом Update 30.
# ===========================================================================

def boss(slot, w, h):
    img = bg_boss(w, h)
    d = ImageDraw.Draw(img)
    magnet = art("giveaway-magnet.webp")
    robot = art("giveaway-robot.webp")

    if slot in ("strip", "dock"):
        big = slot == "strip"
        r = scaled(robot, h=int(h * 1.10))
        drop(img, r, int(w * 0.45), int(-h * 0.07))
        m = scaled(magnet, h=int(h * 0.84))
        drop(img, m, w - m.width - int(w * 0.015), int(h * 0.11))

        # Затемнение слева, чтобы текст лёг на арт.
        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, int(w * 0.50), h], fill=(4, 8, 22, 224))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(w // 26)))

        left = int(w * 0.045)
        colw = int(w * 0.42)
        d = ImageDraw.Draw(img)
        f_head = fit(d, "МЕГА-РОЗЫГРЫШ", colw, int(h * 0.24))
        d.text((left, int(h * 0.18)), "МЕГА-РОЗЫГРЫШ", font=f_head, fill=CYAN + (255,), anchor="lm")
        f_prize = fit(d, "5 PERMANENT", colw, int(h * 0.34))
        text_glow(img, (left, int(h * 0.45)), "5 PERMANENT", f_prize, INK, CYAN, max(5, h // 26), anchor="lm")
        d = ImageDraw.Draw(img)
        f_prize2 = fit(d, "MAGNET", colw, int(h * 0.34))
        text_glow(img, (left, int(h * 0.72)), "MAGNET", f_prize2, INK, MK, max(5, h // 26), anchor="lm")
        d = ImageDraw.Draw(img)
        f_cta = font(int(h * 0.10))
        d.text((left, int(h * 0.93)), T_CTA, font=f_cta, fill=MUTED + (255,), anchor="lm")
        return img

    if slot == "rail":
        r = scaled(robot, w=int(w * 1.24))
        drop(img, r, (w - r.width) // 2, 300)
        m = scaled(magnet, w=int(w * 0.9))
        drop(img, m, (w - m.width) // 2, 640)
        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, w, 300], fill=(4, 8, 22, 190))
        ImageDraw.Draw(veil).rectangle([0, h - 300, w, h], fill=(4, 8, 22, 190))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(28)))
        d = ImageDraw.Draw(img)
        d.text((w / 2, 70), "МЕГА", font=fit(d, "МЕГА", w - 60, 78), fill=CYAN + (255,), anchor="mm")
        text_glow(img, (w / 2, 150), T_HEAD, fit(d, T_HEAD, w - 30, 78), INK, CYAN, 12, anchor="mm")
        d = ImageDraw.Draw(img)
        d.text((w / 2, 232), T_WINNERS, font=fit(d, T_WINNERS, w - 50, 46), fill=MUTED + (255,), anchor="mm")
        for i, line in enumerate(["5 PERMANENT", "MAGNET"]):
            text_glow(img, (w / 2, h - 238 + i * 74), line, fit(d, line, w - 30, 76), INK,
                      MK if i else CYAN, 10, anchor="mm")
        d = ImageDraw.Draw(img)
        d.text((w / 2, h - 96), T_TG, font=fit(d, T_TG, w - 40, 42), fill=INK + (255,), anchor="mm")
        d.text((w / 2, h - 52), "УЧАСТВОВАТЬ", font=fit(d, "УЧАСТВОВАТЬ", w - 60, 32),
               fill=CYAN + (255,), anchor="mm")
        return img

    # popup
    r = scaled(robot, h=430)
    drop(img, r, (w - r.width) // 2 + 40, 210)
    m = scaled(magnet, h=300)
    drop(img, m, 20, 330)
    veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(veil).rectangle([0, 0, w, 210], fill=(4, 8, 22, 200))
    ImageDraw.Draw(veil).rectangle([0, h - 210, w, h], fill=(4, 8, 22, 205))
    img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(34)))
    d = ImageDraw.Draw(img)
    d.text((w / 2, 66), "МЕГА-РОЗЫГРЫШ", font=fit(d, "МЕГА-РОЗЫГРЫШ", w - 120, 76), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 146), "5 PERMANENT MAGNET", fit(d, "5 PERMANENT MAGNET", w - 80, 86),
              INK, MK, 14, anchor="mm")
    d = ImageDraw.Draw(img)
    d.text((w / 2, 626), T_WINNERS, font=font(46), fill=INK + (255,), anchor="mm")
    d.text((w / 2, 686), T_UPD, font=font(34), fill=MUTED + (255,), anchor="mm")
    f_cta = font(40)
    cw = d.textlength(T_TG, font=f_cta)
    chip(img, [(w - cw) / 2 - 104, 720, (w + cw) / 2 + 44, 782], T_TG, f_cta,
         bg_grad=dgrad(w, h, GRAD_A, GRAD_B), fg=INK)
    img.alpha_composite(tg_glyph(46), (int((w - cw) / 2 - 90), 728))
    return img


BUILDERS = {"neon": neon, "ticket": ticket, "boss": boss}


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
