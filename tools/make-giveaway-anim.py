"""Анимированные макеты розыгрыша Arcsteel Magnet из видео владельца.

    python tools/make-giveaway-anim.py --popup IMG_7664.MOV --rail IMG_7679.MOV --dock IMG_7695.MOV
    python tools/make-giveaway-anim.py ... --out tools/out   # превью

Видео сняты на iPhone (HEVC, 60 к/с, петля ~2 с) и в репозиторий не кладутся:
по 6 МБ каждое. Это те же макеты, что и статичные giveaway-*.webp, только
анимированные, поэтому статичные остаются постерами для prefers-reduced-motion.

  окно     1080×1080 -> 800×800
  борт      480×1920 -> 320×1280, снизу срезается 80 px пустого фона -> 320×1200
  плашка   1920×600  -> 640×200

Берётся каждый третий кадр (20 к/с), длительность петли сохраняется. Трясётся
весь кадр, межкадровое сжатие почти не помогает, и 30 к/с даже на q80 дают
1,7 МБ окна при потолке 900 КБ; 20 к/с на q70 в потолки слотов влезают.

Петля крутится бесконечно (loop=0) по решению владельца. Потолок 15 с
(CREATIVE_MAX_ANIM_MS в api/lib/images.php) проверяется только при загрузке
креатива через админку; эта кампания своя и лежит в коде, её он не касается.

Нужны opencv-python (декодирует HEVC) и Pillow.
"""
import argparse
import pathlib

import cv2
from PIL import Image

ROOT = pathlib.Path(__file__).resolve().parent.parent

# Слот -> (ширина, высота, срез снизу после масштабирования, качество WebP).
SLOTS = {
    "popup": (800, 800, 0, 70),
    "rail": (320, 1200, 80, 70),
    "dock": (640, 200, 0, 70),
}
STEP = 3
LOOP = 0  # бесконечно


def frames(path):
    cap = cv2.VideoCapture(str(path))
    fps = cap.get(cv2.CAP_PROP_FPS)
    out = []
    while True:
        ok, bgr = cap.read()
        if not ok:
            break
        out.append(Image.fromarray(cv2.cvtColor(bgr, cv2.COLOR_BGR2RGB)))
    if not out:
        raise SystemExit(f"{path}: не читается")
    return out, fps


def fit(img, w, h, crop_bottom):
    sw, sh = img.size
    scaled_h = round(sh * w / sw)
    if scaled_h - crop_bottom != h:
        raise SystemExit(f"{sw}x{sh} не ложится в {w}x{h} со срезом {crop_bottom}")
    return img.resize((w, scaled_h), Image.LANCZOS).crop((0, 0, w, h))


def build(src, slot, out):
    w, h, crop_bottom, quality = SLOTS[slot]
    all_frames, fps = frames(src)
    picked = [fit(f, w, h, crop_bottom) for f in all_frames[::STEP]]
    total_ms = round(len(all_frames) * 1000 / fps)
    # Целые миллисекунды на кадр так, чтобы сумма совпала с длиной петли.
    edges = [round(i * total_ms / len(picked)) for i in range(len(picked) + 1)]
    durations = [b - a for a, b in zip(edges, edges[1:])]
    path = out / f"giveaway-{slot}-anim.webp"
    picked[0].save(
        path, "WEBP", save_all=True, append_images=picked[1:],
        duration=durations, loop=LOOP, quality=quality, method=6,
    )
    print(f"{path.name}: {w}x{h} {len(picked)} кадров, петля {total_ms} мс, "
          f"{path.stat().st_size} байт")


def main():
    ap = argparse.ArgumentParser()
    for slot in SLOTS:
        ap.add_argument(f"--{slot}", type=pathlib.Path)
    ap.add_argument("--out", default=str(ROOT / "public_html" / "assets" / "promo"))
    args = ap.parse_args()
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    for slot in SLOTS:
        src = getattr(args, slot)
        if src:
            build(src, slot, out)


if __name__ == "__main__":
    main()
