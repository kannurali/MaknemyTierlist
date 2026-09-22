"""Макеты розыгрыша Arcsteel Magnet, подогнанные под четыре рекламных места.

    python tools/fit-giveaway-creatives.py                  # боевые, в assets/promo
    python tools/fit-giveaway-creatives.py --out tools/out  # превью

Кампания — собственная (t.me/theMaknemy/5432), поэтому макеты лежат в
репозитории рядом с house-tg-popup.webp, а не приезжают загрузкой из админки.

Исходники нарисованы владельцем и лежат в tools/art:

  giveaway-arcsteel-wide.webp    1290×403  — полоса тир-листа и нижняя плашка
  giveaway-arcsteel-square.webp  2000×2000 — окно
  giveaway-arcsteel-tall.webp    500×2000  — борт

Пропорции исходников не совпадают со слотами, а текст в них стоит впритык к
краям, поэтому обрезать нельзя: у полосы 4:1 любое окно режет либо «РОЗЫГРЫШ»
сверху, либо плашку «В ТЕЛЕГРАМ» снизу, у борта 320×1200 — «УЧАСТВУЙ». Вместо
обрезки холст расширяется по бокам: картинка масштабируется по высоте слота,
а недостающая ширина добирается зеркальным продолжением фона с размытием.
Края исходников — тёмный размытый арт без текста, так что дорисованные поля
читаются как продолжение фона.

Нижняя плашка 640×200 — ровно 3.2:1, как и широкий исходник: чистое
масштабирование. Окно 800×800 — тоже.

Размеры и потолки веса — CREATIVE_SPECS в api/lib/images.php.

Нужен Pillow.
"""
import argparse
import pathlib

from PIL import Image, ImageFilter

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"

# Слот -> (исходник, ширина, высота).
SLOTS = {
    "strip": ("giveaway-arcsteel-wide.webp", 1200, 300),
    "dock": ("giveaway-arcsteel-wide.webp", 640, 200),
    "rail": ("giveaway-arcsteel-tall.webp", 320, 1200),
    "popup": ("giveaway-arcsteel-square.webp", 800, 800),
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


def fit(src, w, h):
    img = Image.open(src).convert("RGB")
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
    ap.add_argument("--quality", type=int, default=84)
    args = ap.parse_args()
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for slot, (name, w, h) in SLOTS.items():
        img = fit(ART / name, w, h)
        path = out / f"giveaway-{slot}.webp"
        img.save(path, "WEBP", quality=args.quality, method=6)
        print(f"{path.name}: {img.size[0]}x{img.size[1]} {path.stat().st_size} bytes")


if __name__ == "__main__":
    main()
