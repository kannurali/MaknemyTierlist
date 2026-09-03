"""Креатив собственного объявления: телеграм-канал проекта.

    python tools/make-house-tg-creative.py

Пишет public_html/assets/promo/house-tg-popup.webp — макет 800×800 под слот
"popup" (потолок 900×900, см. CREATIVE_SPECS в api/lib/images.php). Файл
лежит в репозитории и уезжает на сайт: это не демо-данные, а то, что видит
посетитель, пока окно не выкуплено рекламодателем.

Почему отдельный генератор, а не строка в make-placeholder-creatives.py:
у той заглушки задача обратная — сказать «здесь может быть ваша реклама» и
продать место. Здесь объявление настоящее, со своим предложением, и общего
у файлов только шрифт.

На картинке намеренно нет ни одной фразы на русском или английском: заголовок
и подпись кнопки окно берёт из словаря js/i18n.js (ключи promo.houseTgText и
promo.houseTgCta) и переводит вместе с остальным интерфейсом. Всё, что
запечено в пиксели, языконезависимо — знак, название бренда и @-адрес.

Нужен Pillow.
"""
import pathlib

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "public_html" / "assets" / "promo" / "house-tg-popup.webp"
LOGO = ROOT / "public_html" / "assets" / "design" / "logo-mk-square.png"
FONT = ROOT / "public_html" / "assets" / "fonts" / "Bootshaus" / "Bootshaus-Regular.ttf"

W = H = 800

# Те же цвета, что у сайта: фон карточки окна (styles.css .ptn-pop-card) и
# градиент кнопок шапки (topbar.css --mk-grad). Креатив, набранный чужой
# палитрой, читается как баннер стороннего рекламодателя, а это наше
# собственное объявление.
BG_TOP = (22, 41, 79)
BG_BOTTOM = (10, 20, 48)
GRAD_A = (97, 181, 233)
GRAD_B = (45, 74, 237)
INK = (255, 255, 255)
MUTED = (159, 182, 216)


def font(px):
    return ImageFont.truetype(str(FONT), px)


def fit_px(draw, text, box_w, start_px):
    """Наибольший кегль, при котором строка влезает в box_w."""
    px = start_px
    while px > 8:
        f = font(px)
        if draw.textlength(text, font=f) <= box_w:
            return f
        px -= 2
    return font(8)


def vertical_gradient(w, h, top, bottom):
    base = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(base)
    for y in range(h):
        k = y / max(1, h - 1)
        d.line([(0, y), (w, y)],
               fill=tuple(round(top[i] + (bottom[i] - top[i]) * k) for i in range(3)))
    return base


def diagonal_gradient(w, h, a, b):
    """Градиент под 255° из макета: светлый край справа сверху."""
    base = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(base)
    for i in range(w + h):
        k = i / max(1, w + h - 1)
        d.line([(i, 0), (0, i)],
               fill=tuple(round(b[j] + (a[j] - b[j]) * k) for j in range(3)))
    return base


def halftone(w, h, pitch, alpha=48):
    """Полутоновая сетка — тот же приём, что у заглушки слотов."""
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    r = pitch * 0.26
    for y in range(0, h + pitch, pitch):
        for x in range(0, w + pitch, pitch):
            d.ellipse([x - r, y - r, x + r, y + r], fill=(122, 176, 233, alpha))
    return layer


def glow(w, h, cx, cy, rx, ry, strength=120):
    layer = Image.new("L", (w, h), 0)
    ImageDraw.Draw(layer).ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=strength)
    return layer.filter(ImageFilter.GaussianBlur(max(w, h) // 10))


def telegram_glyph(size):
    """Знак канала: круг с бумажным самолётиком.

    Рисуется здесь, а не берётся файлом: официальный логотип Telegram —
    чужой товарный знак со своими правилами использования, и тащить его в
    репозиторий ради собственного объявления незачем. Самолётик — обычная
    геометрическая фигура, узнаваемая и без фирменного знака.
    """
    ss = 4  # сглаживание рисованием в четырёхкратном размере
    s = size * ss
    layer = Image.new("RGBA", (s, s), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)

    disc = Image.new("L", (s, s), 0)
    ImageDraw.Draw(disc).ellipse([0, 0, s - 1, s - 1], fill=255)
    layer.paste(diagonal_gradient(s, s, GRAD_A, GRAD_B).convert("RGBA"), (0, 0), disc)

    # Самолётик: тело, отражённый «хвост» и линия сгиба.
    u = s / 100.0
    body = [(20 * u, 50 * u), (82 * u, 25 * u), (70 * u, 78 * u), (52 * u, 62 * u), (40 * u, 72 * u)]
    d.polygon(body, fill=INK + (255,))
    d.line([(40 * u, 72 * u), (42 * u, 56 * u), (82 * u, 25 * u)],
           fill=BG_BOTTOM + (255,), width=round(2.4 * u), joint="curve")

    return layer.resize((size, size), Image.LANCZOS)


def build():
    img = vertical_gradient(W, H, BG_TOP, BG_BOTTOM)
    img = Image.composite(Image.new("RGB", (W, H), (52, 96, 168)), img,
                          glow(W, H, W / 2, H * 0.34, W * 0.42, H * 0.30, strength=92))
    dots = halftone(W, H, 26)
    img.paste(dots, (0, 0), dots)

    d = ImageDraw.Draw(img)
    pad = 18
    d.rounded_rectangle([pad, pad, W - pad - 1, H - pad - 1], radius=44,
                        outline=(96, 190, 255), width=3)

    glyph = telegram_glyph(240)
    img.paste(glyph, ((W - glyph.width) // 2, 128), glyph)

    inner = W - pad * 4
    d.text((W / 2, 446), "MAKNEMY", font=fit_px(d, "MAKNEMY", inner, 108), fill=INK, anchor="mm")

    # Разделительная черта градиентом кнопок — та же деталь, что у карточек
    # сайта: она связывает объявление с оформлением страницы.
    rule = Image.new("L", (W, H), 0)
    ImageDraw.Draw(rule).rounded_rectangle([W * 0.30, 502, W * 0.70, 508], radius=3, fill=255)
    img = Image.composite(diagonal_gradient(W, H, GRAD_A, GRAD_B), img, rule)
    d = ImageDraw.Draw(img)

    d.text((W / 2, 558), "TELEGRAM", font=fit_px(d, "TELEGRAM", inner, 46), fill=MUTED, anchor="mm")
    d.text((W / 2, 638), "@THEMAKNEMY", font=fit_px(d, "@THEMAKNEMY", inner, 82), fill=INK, anchor="mm")

    # Кнопки на картинке нет намеренно: своя кнопка есть у самого окна
    # (.ptn-pop-cta), и вторая, нарисованная в пикселях, читалась бы как
    # ещё одно нажимаемое место, которое не нажимается.
    logo = Image.open(LOGO).convert("RGBA").resize((66, 66), Image.LANCZOS)
    img.paste(logo, ((W - 66) // 2, 700), logo)

    return img


def main():
    OUT.parent.mkdir(parents=True, exist_ok=True)
    build().save(OUT, "WEBP", quality=88, method=6)
    print(f"  assets/promo/{OUT.name}  {W}x{H}  {OUT.stat().st_size // 1024} KB")


if __name__ == "__main__":
    main()
