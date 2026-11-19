"""Полоса 1200×300 «Курс по трейдам в Blox Fruits» из макета 16:9.

    python tools/make-course-creative.py                  # боевая, в assets/promo
    python tools/make-course-creative.py --out tools/out  # превью

Исходник tools/art/trade-course-src.jpg (2000×1125): текст слева сверху,
два фрукта справа снизу. В 4:1 они по высоте не помещаются, поэтому
полоса собирается заново в масштабе SCALE:

- фон — сам макет, прижатый к правому краю и поднятый на SHIFT_Y, чтобы
  фрукты встали посередине по высоте; слева он продолжен крайней
  колонкой (там ровный тёмно-синий);
- старый текст стёрт с фона: буквы заменены размытым окружением
  (нормированная свёртка, от узкой к широкой), блёстки куба не задеты — у них почти нет
  красного канала и зелёный выше красного на 90+, у белых букв
  каналы почти равны; рядом с кубом берётся только то, что примыкает
  к белой сердцевине буквы;
- текст переносится на новый фон по центру высоты. Непрозрачность буквы —
  её красный канал, делённый на красный цвет строки (LINES), и
  out = src + (1 − a) · (новый фон − старый фон): буквы не тащат с собой
  ни старый фон, ни свечение.

Правый верхний угол остаётся пустым под плашку «РЕКЛАМА».

Нужны Pillow, numpy и opencv-python.
"""
import argparse
import pathlib

import cv2
import numpy as np
from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "tools" / "art" / "trade-course-src.jpg"
OUT = ROOT / "public_html" / "assets" / "promo"

W, H = 2000, 500
SCALE = 0.9
SHIFT_Y = -315
TEXT_BOX = (60, 215, 1195, 672)
TEXT_X = 80
LINES = [
    (222, 294, 66, 817, 164),
    (358, 514, 69, 1184, 254),
    (509, 662, 70, 1042, 164),
]
SPARKLE_FROM = (1100, 425)


def text_alpha(src):
    r = src[:, :, 0].astype(np.float32)
    g = src[:, :, 1].astype(np.float32)
    sx, sy = SPARKLE_FROM
    near = np.zeros(r.shape, bool)
    near[sy:, sx:] = True
    core = cv2.dilate((r > 200).astype(np.uint8), np.ones((5, 5), np.uint8)) > 0
    keep = ~near | (core & (r > 40) & ((g - r) < 45))
    a = np.zeros(r.shape, np.float32)
    for y0, y1, x0, x1, red in LINES:
        part = np.clip((r[y0:y1, x0:x1] - 3) / (red - 3), 0, 1)
        a[y0:y1, x0:x1] = np.maximum(a[y0:y1, x0:x1], part * keep[y0:y1, x0:x1])
    return a


def erase(src, a):
    hole = cv2.dilate((a > 0.02).astype(np.uint8), np.ones((19, 19), np.uint8)).astype(np.float32)
    valid = 1 - hole
    img = src.astype(np.float32)
    fill = img.copy()
    todo = np.ones(hole.shape, bool)
    for sigma in (5, 10, 20, 40):
        num = cv2.GaussianBlur(img * valid[:, :, None], (0, 0), sigma)
        den = cv2.GaussianBlur(valid, (0, 0), sigma)
        ok = todo & (den > 0.08)
        fill[ok] = num[ok] / den[ok][:, None]
        todo &= ~ok
    soft = cv2.GaussianBlur(hole, (0, 0), 3)[:, :, None]
    return img * (1 - soft) + fill * soft


def build():
    src = np.asarray(Image.open(SRC).convert("RGB"))
    a = text_alpha(src)
    clean = erase(src, a)

    sw, sh = round(src.shape[1] * SCALE), round(src.shape[0] * SCALE)
    bg = cv2.resize(clean, (sw, sh), interpolation=cv2.INTER_AREA)
    dx = W - sw
    canvas = np.zeros((H, W, 3), np.float32)
    canvas[:, dx:] = bg[-SHIFT_Y:-SHIFT_Y + H, :]
    canvas[:, :dx] = canvas[:, dx:dx + 1]

    x0, y0, x1, y1 = TEXT_BOX
    tw, th = round((x1 - x0) * SCALE), round((y1 - y0) * SCALE)
    size = lambda m: cv2.resize(np.ascontiguousarray(m[y0:y1, x0:x1]), (tw, th), interpolation=cv2.INTER_AREA)
    s_src = size(src.astype(np.float32))
    s_old = size(clean)
    s_a = size(a)[:, :, None]
    ty = (H - th) // 2
    new = canvas[ty:ty + th, TEXT_X:TEXT_X + tw]
    canvas[ty:ty + th, TEXT_X:TEXT_X + tw] = np.where(
        s_a > 0, s_src + (1 - s_a) * (new - s_old), new)

    out = np.clip(canvas, 0, 255).astype(np.uint8)
    return Image.fromarray(out).resize((1200, 300), Image.LANCZOS)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", type=pathlib.Path, default=OUT)
    args = ap.parse_args()
    args.out.mkdir(parents=True, exist_ok=True)
    path = args.out / "trade-course-strip.webp"
    build().save(path, "WEBP", quality=90, method=6)
    print(path, path.stat().st_size)


if __name__ == "__main__":
    main()
