"""Заглушка «ВАША РЕКЛАМА» под размеры трёх слотов.

Исходник — tools/art/placeholder-src.jpg, 1280×720, нарисован владельцем.
Кадрировать его под 4:1 нельзя: надпись занимает почти всю высоту и от
обрезки теряет половину букв. Поэтому от исходника берётся фон (градиент,
цветы, свечение), а надпись рисуется заново под пропорции каждого слота
шрифтом сайта.

    python tools/make-placeholder-creatives.py

Пишет в public_html/assets/promo/ — в отличие от демо-креативов эти файлы
лежат в репозитории и уезжают на сайт: их показывает карусель, борта и
полоса, пока не продана ни одна кампания. Нужен Pillow.
"""
import pathlib

from PIL import Image, ImageDraw, ImageFilter, ImageFont

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "tools" / "art" / "placeholder-src.jpg"
OUT = ROOT / "public_html" / "assets" / "promo"
FONT = ROOT / "public_html" / "assets" / "fonts" / "Bootshaus" / "Bootshaus-Regular.ttf"

INK = (255, 255, 255)
EDGE = (96, 190, 255)

# Размеры слотов из CREATIVE_SPECS в api/lib/images.php. Попапа здесь нет
# намеренно: окно «ВАША РЕКЛАМА» поверх сайта каждому посетителю — это
# раздражение без выгоды, продать место оно не помогает.
SLOTS = {
    "strip": (1200, 300),
    "rail": (320, 1200),
    "dock": (640, 200),
}

# Откуда в исходнике брать фон. Для широких слотов подходит весь кадр: обрезка
# берёт почти всю ширину, и лёгкого размытия хватает, чтобы старая надпись
# исчезла. Борт 320×1200 — другое дело: чтобы заполнить его по высоте, кадр
# увеличивается вдвое и берётся узкая колонка, а в ней буквы всплывают
# читаемыми полосами. Поэтому для борта берём левый край исходника, где
# надписи нет вовсе, — там как раз цветы и градиент.
SRC_CROP = {"rail": (0, 0, 360, 720)}


def font(px):
    return ImageFont.truetype(str(FONT), px)


def cover(img, w, h):
    """Масштаб до заполнения + центральная обрезка, как object-fit: cover."""
    k = max(w / img.width, h / img.height)
    r = img.resize((max(1, round(img.width * k)), max(1, round(img.height * k))), Image.LANCZOS)
    left = (r.width - w) // 2
    top = (r.height - h) // 2
    return r.crop((left, top, left + w, top + h))


def fit_px(draw, text, box_w, start_px):
    """Наибольший кегль, при котором строка влезает в box_w."""
    px = start_px
    while px > 8:
        f = font(px)
        if draw.textlength(text, font=f) <= box_w:
            return f
        px -= 2
    return font(8)


def halftone(w, h, pitch, colour=(9, 16, 38), alpha=105):
    """Полутоновая сетка из исходника. Рисуется, а не вырезается: у исходной
    она лежит под надписью, которую мы как раз убираем размытием."""
    layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    r = pitch * 0.28
    for y in range(0, h + pitch, pitch):
        for x in range(0, w + pitch, pitch):
            d.ellipse([x - r, y - r, x + r, y + r], fill=colour + (alpha,))
    return layer


def glow(w, h, cx, cy, rx, ry, strength=150):
    """Мягкое пятно света под надписью — в исходнике оно даёт объём."""
    layer = Image.new("L", (w, h), 0)
    ImageDraw.Draw(layer).ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=strength)
    return layer.filter(ImageFilter.GaussianBlur(max(w, h) // 12))


def build(slot, w, h):
    # Фон исходника несёт и надпись, и полутоновую сетку. Лёгкое размытие
    # снимает старый текст, оставляя цвет, цветы и свечение; сетка и блик
    # возвращаются поверх, новая надпись ложится последней.
    src = Image.open(SRC).convert("RGB")
    if slot in SRC_CROP:
        src = src.crop(SRC_CROP[slot])
    bg = cover(src, w, h).filter(ImageFilter.GaussianBlur(max(6, min(w, h) // 22)))
    img = Image.blend(bg, Image.new("RGB", (w, h), (7, 13, 34)), 0.42)

    img = Image.composite(Image.new("RGB", (w, h), (150, 186, 228)), img,
                          glow(w, h, w / 2, h * 0.46, w * 0.30, h * 0.26, strength=70))
    dots = halftone(w, h, max(8, round(min(w, h) / 20)), alpha=62)
    img.paste(dots, (0, 0), dots)

    d = ImageDraw.Draw(img)
    pad = max(2, round(min(w, h) * 0.02))
    d.rounded_rectangle([pad, pad, w - pad - 1, h - pad - 1],
                        radius=max(8, round(min(w, h) * 0.06)), outline=EDGE, width=max(2, w // 400))

    inner = w - pad * 4
    if slot == "rail":
        # Вертикальная свеча: слова друг под другом, иначе кегль падает втрое.
        f = fit_px(d, "РЕКЛАМА", inner, 150)
        d.text((w / 2, h * 0.44), "ВАША", font=f, fill=INK, anchor="mm")
        d.text((w / 2, h * 0.56), "РЕКЛАМА", font=f, fill=INK, anchor="mm")
        d.text((w / 2, h * 0.93), "MAKNEMY TIER LIST", font=fit_px(d, "MAKNEMY TIER LIST", inner, 34),
               fill=(150, 190, 235), anchor="mm")
    else:
        f = fit_px(d, "ВАША РЕКЛАМА", inner, round(h * 0.5))
        d.text((w / 2, h * 0.47), "ВАША РЕКЛАМА", font=f, fill=INK, anchor="mm")
        cap_px = max(18, round(h * 0.11))
        d.text((w / 2, h - pad * 2 - cap_px / 2), "MAKNEMY TIER LIST",
               font=fit_px(d, "MAKNEMY TIER LIST", inner, cap_px), fill=(150, 190, 235), anchor="mm")

    return img


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for slot, (w, h) in SLOTS.items():
        path = OUT / f"placeholder-{slot}.webp"
        build(slot, w, h).save(path, "WEBP", quality=88, method=6)
        print(f"  assets/promo/{path.name}  {w}x{h}  {path.stat().st_size // 1024} KB")


if __name__ == "__main__":
    main()
