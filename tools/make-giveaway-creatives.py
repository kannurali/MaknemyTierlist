"""Креативы кампании «Розыгрыш: 5 permanent Magnet».

    python tools/make-giveaway-creatives.py                                   # в out/
    python tools/make-giveaway-creatives.py --as giveaway --out public_html/assets/promo

Кампания — собственная (t.me/theMaknemy/5302), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Размеры берутся из CREATIVE_SPECS в api/lib/images.php: файл, который не влез
в потолок слота, сервер либо ужмёт (still), либо отвергнет (анимация).

Исходники — tools/art/giveaway-magnet.webp и giveaway-robot.webp: арт предмета
Magnet и робот Update 30, обрезанные по альфе.

Приз один, и макет строится вокруг трёх вещей, которые обязаны прочитаться за
секунду: что разыгрывают, сколько осталось до итогов и куда нажать. Отсюда
счётчик COUNT/COUNT_UNIT и кнопка «УЧАСТВОВАТЬ» во всех четырёх местах.

ВАЖНО про счётчик: число нарисовано внутри картинки. Поменялось — правим
COUNT здесь и перегенерируем все четыре макета, иначе места разойдутся в
том, сколько осталось.

Вариант с двумя призами (Magnet + Chromatic Box) и три ранних макета
(«дуэт», «билет», «босс») лежат в истории git до этого коммита.

Нужен Pillow.
"""
import argparse
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
INK = (255, 255, 255)
MUTED = (159, 182, 216)
GRAD_A = (97, 181, 233)
GRAD_B = (45, 74, 237)

# Золото значка «x5»: снято пипеткой с игрового арта, чтобы дорисованный
# значок не выглядел нарисованным в другой игре.
GOLD_HI = (253, 232, 63)
GOLD_LO = (247, 176, 24)
GOLD_INK = (46, 14, 7)

# Тексты. Русский — как у объявления, которое стоит в канале.
T_HEAD = "РОЗЫГРЫШ"
T_HEAD_BIG = "МЕГА-РОЗЫГРЫШ"
T_PRIZE = "5 PERMANENT MAGNET"
T_PRIZE2 = ["5 PERMANENT", "MAGNET"]
T_COUNT_LEAD = "ДО ИТОГОВ ОСТАЛОСЬ"
T_COUNT_LEAD2 = ["ДО ИТОГОВ", "ОСТАЛОСЬ"]
COUNT = "500"
COUNT_UNIT = "УЧАСТНИКОВ"
T_BTN = "УЧАСТВОВАТЬ"


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


def hgrad(w, h, a, b):
    img = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(img)
    for x in range(w):
        k = x / max(1, w - 1)
        d.line([(x, 0), (x, h)], fill=tuple(round(a[i] + (b[i] - a[i]) * k) for i in range(3)))
    return img.convert("RGBA")


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
    """Число в стиле игрового значка: золотой градиент, тёмный контур, тень."""
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


# ===========================================================================
#  Счётчик и кнопка
# ===========================================================================

def count_lead(img, xy, max_w, px, anchor="lm"):
    """Подпись «ДО ИТОГОВ ОСТАЛОСЬ» — приглушённая, число рядом громче."""
    d = ImageDraw.Draw(img)
    d.text(xy, T_COUNT_LEAD, font=fit(d, T_COUNT_LEAD, max_w, px), fill=MUTED + (255,), anchor=anchor)


def count_value(img, x, y, max_w, num_px, unit_px, center=False):
    """«2000 УЧАСТНИКОВ»: число золотом, единица — приглушённым текстом.

    Строка ужимается целиком: если ужать только число, единица уедет за край
    колонки, а если только единицу — она станет нечитаемой раньше числа.
    """
    d = ImageDraw.Draw(img)
    while True:
        nw = d.textlength(COUNT, font=font(num_px))
        uw = d.textlength(COUNT_UNIT, font=font(unit_px))
        gap = num_px * 0.32
        if nw + gap + uw <= max_w or num_px <= 12:
            break
        num_px -= 2
        unit_px = max(9, int(unit_px * 0.94))
    x0 = x - (nw + gap + uw) / 2 if center else x
    gold_num(img, (x0, y), COUNT, num_px, anchor="lm")
    d.text((x0 + nw + gap, y + num_px * 0.05), COUNT_UNIT, font=font(unit_px),
           fill=MUTED + (255,), anchor="lm")


def button(img, box, text=T_BTN):
    """Кнопка «Участвовать»: градиент шапки сайта, свечение под ней.

    Градиент считается по коробке кнопки, а не по холсту: иначе одна и та же
    кнопка выходит светлой внизу макета и тёмной наверху.
    """
    x0, y0, x1, y1 = [int(v) for v in box]
    bw, bh = x1 - x0, y1 - y0

    glow = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(glow).rounded_rectangle([x0 + bh * 0.10, y0 + bh * 0.30, x1 - bh * 0.10, y1 + bh * 0.22],
                                           radius=bh // 2, fill=(60, 120, 235, 150))
    img.alpha_composite(glow.filter(ImageFilter.GaussianBlur(max(4, bh // 3))))

    layer = Image.new("RGBA", img.size, (0, 0, 0, 0))
    layer.paste(hgrad(bw, bh, GRAD_A, GRAD_B), (x0, y0))
    mask = Image.new("L", img.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([x0, y0, x1, y1], radius=bh // 2, fill=255)
    img.paste(layer, (0, 0), mask)

    d = ImageDraw.Draw(img)
    d.rounded_rectangle([x0, y0, x1, y1], radius=bh // 2, outline=(190, 226, 255, 130),
                        width=max(1, bh // 30))
    d.text(((x0 + x1) / 2, (y0 + y1) / 2 - bh * 0.06), text,
           font=fit(d, text, bw - bh, int(bh * 0.50)), fill=INK + (255,), anchor="mm")


def button_width(text, px, pad):
    d = ImageDraw.Draw(Image.new("RGBA", (10, 10)))
    return d.textlength(text, font=font(px)) + pad * 2


def bg(w, h):
    img = vgrad(w, h, (10, 18, 42), (4, 6, 16))
    img.alpha_composite(rays(w, h, w * 0.5, h * 0.46, CYAN, count=16, alpha=20))
    img = radial(img, w * 0.5, h * 0.5, w * 0.36, h * 0.44, (24, 74, 150), 120)
    return img


# ===========================================================================
#  Макет
# ===========================================================================

def creative(slot, w, h):
    img = bg(w, h)
    magnet = art("giveaway-magnet.webp")
    robot = art("giveaway-robot.webp")

    if slot in ("strip", "dock"):
        r = scaled(robot, h=int(h * 0.96))
        drop(img, r, int(w * 0.50), int(h * 0.02))
        m = scaled(magnet, h=int(h * 0.76))
        drop(img, m, w - m.width - int(w * 0.03), int(h * 0.12))
        gold_num(img, (w - m.width - int(w * 0.026), int(h * 0.30)), "×5", int(h * 0.23))

        # Затемнение слева, чтобы текст лёг на арт.
        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, int(w * 0.52), h], fill=(4, 8, 22, 228))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(w // 26)))

        left = int(w * 0.045)
        colw = int(w * 0.44)
        d = ImageDraw.Draw(img)
        d.text((left, int(h * 0.12)), T_HEAD_BIG, font=fit(d, T_HEAD_BIG, colw, int(h * 0.17)),
               fill=CYAN + (255,), anchor="lm")
        text_glow(img, (left, int(h * 0.33)), T_PRIZE, fit(d, T_PRIZE, colw, int(h * 0.26)),
                  INK, CYAN, max(4, h // 30), anchor="lm")
        count_lead(img, (left, int(h * 0.53)), colw * 0.72, int(h * 0.115))
        count_value(img, left, int(h * 0.67), colw, int(h * 0.17), int(h * 0.105))

        bh = int(h * 0.17)
        button(img, [left, h - bh - int(h * 0.055), left + button_width(T_BTN, int(bh * 0.50), bh * 0.62),
                     h - int(h * 0.055)])
        return img

    if slot == "rail":
        # Предметы каскадом; ниже 840 начинается вуаль со счётчиком и
        # кнопкой, и наезжать на неё арту нельзя.
        r = scaled(robot, w=int(w * 1.08))
        drop(img, r, (w - r.width) // 2, 300)
        m = scaled(magnet, w=int(w * 0.82))
        drop(img, m, (w - m.width) // 2 - 6, 600)
        gold_num(img, (w - 58, 702), "×5", 84)

        veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
        ImageDraw.Draw(veil).rectangle([0, 0, w, 300], fill=(4, 8, 22, 196))
        ImageDraw.Draw(veil).rectangle([0, h - 360, w, h], fill=(4, 8, 22, 218))
        img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(28)))

        d = ImageDraw.Draw(img)
        d.text((w / 2, 62), "МЕГА", font=fit(d, "МЕГА", w - 60, 68), fill=CYAN + (255,), anchor="mm")
        text_glow(img, (w / 2, 136), T_HEAD, fit(d, T_HEAD, w - 30, 78), INK, CYAN, 12, anchor="mm")
        d = ImageDraw.Draw(img)
        for i, line in enumerate(T_PRIZE2):
            d.text((w / 2, 204 + i * 46), line, font=fit(d, line, w - 40, 48),
                   fill=INK + (255,), anchor="mm")

        for i, line in enumerate(T_COUNT_LEAD2):
            d.text((w / 2, 866 + i * 36), line, font=fit(d, line, w - 60, 34),
                   fill=MUTED + (255,), anchor="mm")
        gold_num(img, (w / 2, 972), COUNT, 104)
        d.text((w / 2, 1042), COUNT_UNIT, font=fit(d, COUNT_UNIT, w - 40, 34),
               fill=MUTED + (255,), anchor="mm")
        button(img, [22, h - 108, w - 22, h - 34])
        return img

    # popup 800×800
    r = scaled(robot, h=344)
    drop(img, r, (w - r.width) // 2, 172)
    m = scaled(magnet, h=250)
    drop(img, m, 6, 292)
    gold_num(img, (224, 462), "×5", 84)

    veil = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(veil).rectangle([0, 0, w, 196], fill=(4, 8, 22, 206))
    ImageDraw.Draw(veil).rectangle([0, h - 254, w, h], fill=(4, 8, 22, 232))
    img.alpha_composite(veil.filter(ImageFilter.GaussianBlur(30)))

    d = ImageDraw.Draw(img)
    d.text((w / 2, 62), T_HEAD_BIG, font=fit(d, T_HEAD_BIG, w - 120, 74), fill=CYAN + (255,), anchor="mm")
    text_glow(img, (w / 2, 142), T_PRIZE, fit(d, T_PRIZE, w - 80, 86), INK, CYAN, 13, anchor="mm")
    count_lead(img, (w / 2, 588), w - 220, 38, anchor="mm")
    count_value(img, w / 2, 646, w - 180, 76, 40, center=True)
    button(img, [150, 700, w - 150, 772])
    return img


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "tools" / "out" / "giveaway"))
    ap.add_argument("--as", dest="as_name", default="giveaway",
                    help="имя файла: --as giveaway -> giveaway-strip.webp")
    ap.add_argument("--png", action="store_true", help="писать PNG вместо WebP (для превью)")
    args = ap.parse_args()

    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    for slot, (w, h) in SLOTS.items():
        img = creative(slot, w, h).convert("RGB")
        path = out / (f"{args.as_name}-{slot}." + ("png" if args.png else "webp"))
        if args.png:
            img.save(path)
        else:
            img.save(path, "WEBP", quality=88, method=6)
        print(f"  {path.relative_to(ROOT) if path.is_relative_to(ROOT) else path}  {w}x{h}  {path.stat().st_size // 1024} KB")


if __name__ == "__main__":
    main()
