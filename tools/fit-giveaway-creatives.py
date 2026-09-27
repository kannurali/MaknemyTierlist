"""Макеты розыгрыша Arcsteel Magnet, подогнанные под четыре рекламных места.

    python tools/fit-giveaway-creatives.py                  # боевые, в assets/promo
    python tools/fit-giveaway-creatives.py --out tools/out  # превью

Кампания — собственная (t.me/theMaknemy/5432), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Исходники нарисованы владельцем и лежат в tools/art:

  giveaway-arcsteel-strip.jpg    2000×499  — полоса (лента новостей и трейдов)
  giveaway-arcsteel-dock.jpg     640×200   — нижняя плашка
  giveaway-arcsteel-rail.jpg     300×1200  — борт
  giveaway-arcsteel-popup.webp   800×800   — окно

Полоса 2026-09-28 пришла почти ровно 4:1, но с двумя строками светлой линии
по нижнему краю: в слоте они стали бы белой чертой под баннером. CROP
срезает их и по 6 px с боков, остаётся 1988×497, ровно 4:1, и дальше
обычное масштабирование. По краям тёмный размытый арт, текст не задет.

Борт 300×1200 уже слота: в боксе с `object-fit: cover` он потерял бы по
~37 px сверху и снизу, а текст в нём стоит впритык к краям. Поэтому холст
расширяется по бокам: картинка масштабируется по высоте слота, а недостающая
ширина добирается зеркальным продолжением фона с размытием. Края исходника —
тёмный размытый арт без текста, так что дорисованные поля читаются как
продолжение фона.

Нижняя плашка и окно нарисованы ровно в размер слота. Окно уже в WebP и
копируется как есть; полоса, плашка и борт пришли в JPEG, и второе сжатие на
q84 даёт заметную грязь, поэтому у них q90.

Размеры и потолки веса — CREATIVE_SPECS в api/lib/images.php.

Нужен Pillow.
"""
import argparse
import pathlib
import shutil

from PIL import Image, ImageFilter

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"

# Слот -> (исходник, ширина, высота, качество WebP).
SLOTS = {
    "strip": ("giveaway-arcsteel-strip.jpg", 1200, 300, 90),
    "dock": ("giveaway-arcsteel-dock.jpg", 640, 200, 90),
    "rail": ("giveaway-arcsteel-rail.jpg", 320, 1200, 90),
    "popup": ("giveaway-arcsteel-popup.webp", 800, 800, 84),
}

# Слот -> рамка (left, top, right, bottom), вырезаемая из исходника до подгонки.
CROP = {
    "strip": (6, 0, 1994, 497),
}


def extend_sides(img, pad, blur):
    """Добирает `pad` px с каждой стороны зеркальным продолжением фона."""
    w, h = img.size
    out = Image.new("RGB", (w + 2 * pad, h))
    left = img.crop((0, 0, pad, h)).transpose(Image.FLIP_LEFT_RIGHT)
    right = img.crop((w - pad, 0, w, h)).transpose(Image.FLIP_LEFT_RIGHT)
    out.paste(left.filter(ImageFilter.GaussianBlur(blur)), (0, 0))
    out.paste(img, (pad, 0))
    out.paste(right.filter(ImageFilter.GaussianBlur(blur)), (pad + w, 0))
    return out


def fit(src, w, h, crop=None):
    img = Image.open(src).convert("RGB")
    if crop:
        img = img.crop(crop)
    sw, sh = img.size
    if abs(sw / sh - w / h) < 0.01:
        return img.resize((w, h), Image.LANCZOS)
    # Исходник уже слота: масштаб по высоте, поля по бокам.
    scaled_w = round(sw * h / sh)
    if scaled_w > w:
        raise SystemExit(f"{src.name}: исходник шире слота {w}x{h}, поля не помогут")
    scaled = img.resize((scaled_w, h), Image.LANCZOS)
    pad = (w - scaled_w) // 2
    out = extend_sides(scaled, pad, blur=max(4, pad // 10))
    if out.size[0] != w:
        out = out.resize((w, h), Image.LANCZOS)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "public_html" / "assets" / "promo"))
    args = ap.parse_args()
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for slot, (name, w, h, quality) in SLOTS.items():
        src = ART / name
        path = out / f"giveaway-{slot}.webp"
        with Image.open(src) as probe:
            exact = probe.size == (w, h) and probe.format == "WEBP"
        if exact:
            shutil.copyfile(src, path)
        else:
            fit(src, w, h, CROP.get(slot)).save(path, "WEBP", quality=quality, method=6)
        print(f"{path.name}: {w}x{h} {path.stat().st_size} bytes")


if __name__ == "__main__":
    main()
