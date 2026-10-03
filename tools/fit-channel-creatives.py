"""Макеты телеграм-канала «BLOX FRUITS: Новости, Обновления, Находки»
(t.me/+VQVMx_Imrus1Zjhi), подогнанные под четыре рекламных места.

    python tools/fit-channel-creatives.py                  # боевые, в assets/promo
    python tools/fit-channel-creatives.py --out tools/out  # превью

Кампания собственная и стоит во всех свободных местах, где нет Playerok,
поэтому макеты лежат в репозитории, как у розыгрыша.

Исходники лежат в tools/art:

  channel-strip.jpg   1200×390   — полоса
  channel-dock.jpg    614×200    — нижняя плашка
  channel-rail.jpg    320×750    — борт
  channel-popup.jpg   1254×1254  — окно

Полоса, плашка и борт — те же картинки, что 2026-10-03 загрузили в кампанию
«Новая кампания» в админке. Ни одна не в размер слота, а бокс слота режет
лишнее через `object-fit: cover`:

- полосу 1200×390 бокс 4:1 обрезал бы по 45 px сверху и снизу и задел кнопку
  TELEGRAM. Здесь окно 1200×300 сдвинуто вниз (STRIP_TOP): над «BLOX FRUITS»
  остаётся небо, под кнопкой — облака;
- плашку 614×200 масштабирование до ширины 640 делает 640×208, лишние 8 строк
  снимаются поровну, текст не задет;
- борт 320×750 в боксе 4:15 потерял бы почти 40 % ширины вместе с надписями.
  Он ставится целиком по центру холста 320×1200, а поля сверху и снизу
  заполняет тот же арт, растянутый на весь холст, размытый и уходящий к краям
  в тёмно-синий. Края исходника растворяются в этом фоне (FEATHER).

Сжатие q90: всё, кроме окна, пришло в JPEG, и второе сжатие на q84 даёт
заметную грязь. Размеры и потолки веса — CREATIVE_SPECS в api/lib/images.php.

Нужен Pillow.
"""
import argparse
import pathlib

from PIL import Image, ImageDraw, ImageFilter

ROOT = pathlib.Path(__file__).resolve().parent.parent
ART = ROOT / "tools" / "art"

STRIP_TOP = 66
# Борт: размытие фона, яркость фона у самых краёв холста и цвет, в который
# он уходит. Растворение краёв исходника не глубже 34 px: ниже уже надпись.
BLUR = 30
EDGE_LIGHT = 0.25
EDGE_COLOR = (8, 10, 24)
FEATHER = 34


def strip():
    img = Image.open(ART / "channel-strip.jpg").convert("RGB")
    return img.crop((0, STRIP_TOP, 1200, STRIP_TOP + 300))


def dock():
    img = Image.open(ART / "channel-dock.jpg").convert("RGB")
    img = img.resize((640, round(img.height * 640 / img.width)), Image.LANCZOS)
    top = (img.height - 200) // 2
    return img.crop((0, top, 640, top + 200))


def rail():
    img = Image.open(ART / "channel-rail.jpg").convert("RGB")
    w, h = 320, 1200
    top = (h - img.height) // 2

    bg = img.resize((round(img.width * h / img.height), h), Image.LANCZOS)
    left = (bg.width - w) // 2
    bg = bg.crop((left, 0, left + w, h)).filter(ImageFilter.GaussianBlur(BLUR))

    shade = Image.new("L", (w, h), 255)
    draw = ImageDraw.Draw(shade)
    for y in range(h):
        if y < top:
            t = (top - y) / top
        elif y >= top + img.height:
            t = (y - top - img.height + 1) / top
        else:
            t = 0
        draw.line([(0, y), (w, y)], fill=round(255 * (1 - (1 - EDGE_LIGHT) * t)))
    bg = Image.composite(bg, Image.new("RGB", (w, h), EDGE_COLOR), shade)

    mask = Image.new("L", img.size, 255)
    draw = ImageDraw.Draw(mask)
    for y in range(FEATHER):
        a = round(255 * (y / FEATHER) ** 1.5)
        draw.line([(0, y), (img.width, y)], fill=a)
        draw.line([(0, img.height - 1 - y), (img.width, img.height - 1 - y)], fill=a)
    bg.paste(img, (0, top), mask)
    return bg


def popup():
    img = Image.open(ART / "channel-popup.jpg").convert("RGB")
    return img.resize((800, 800), Image.LANCZOS)


SLOTS = {"strip": strip, "dock": dock, "rail": rail, "popup": popup}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=str(ROOT / "public_html" / "assets" / "promo"))
    args = ap.parse_args()
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for slot, make in SLOTS.items():
        path = out / f"channel-{slot}.webp"
        img = make()
        img.save(path, "WEBP", quality=90, method=6)
        print(f"{path.name}: {img.width}x{img.height} {path.stat().st_size} bytes")


if __name__ == "__main__":
    main()
