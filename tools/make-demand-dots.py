# Рисует точки спроса assets/dot-{red,orange,yellow,green}.png.
#
# Макет пришёл картинками 30x30 (2026-09-19): тёмная обводка и яркая заливка,
# круг заливки — 11/15 внешнего радиуса, у красной заливка чуть темнеет слева
# направо. В 30 px точки мылились везде, где браузер или экспорт в PNG меняли
# их размер: на экранах с масштабом 125–200 % и в экспорте (inlineStageImages
# в app.js не рисует картинку крупнее её собственного размера). Здесь та же
# геометрия считается заново в 72x72, как у dot-neon.png, по 16x16 выборок
# на пиксель.
#
# Жёлтой в наборе не было: заливка — жёлтый легенды из макета (#fff200), а
# обводка темнее заливки во столько же раз, что у оранжевой (#704700 к
# #ffa100, ~0,44), отсюда #706a00.
#
# dot-neon.png — кадр неонового градиента, этот скрипт его не трогает.
#
#   python tools/make-demand-dots.py

import os

import numpy as np
from PIL import Image

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
DST = os.path.join(ROOT, "public_html", "assets")

SIZE = 72
SS = 16
INNER = 11 / 15


def red_fill(u, v):
    # Плоскость, снятая с присланной 30x30: 207 в центре, -1,21 на пиксель по x
    # и -0,16 по y; u и v здесь в долях внешнего радиуса (15 px).
    r = 207.1 - 18.12 * u - 2.33 * v
    return np.stack([r, np.zeros_like(r), np.zeros_like(r)], axis=-1)


DOTS = {
    "red": ((0x6E, 0x00, 0x00), red_fill),
    "orange": ((0x70, 0x47, 0x00), (0xFF, 0xA1, 0x00)),
    "yellow": ((0x70, 0x6A, 0x00), (0xFF, 0xF2, 0x00)),
    "green": ((0x00, 0x64, 0x03), (0x19, 0xFF, 0x00)),
}


def render(rim, fill):
    n = SIZE * SS
    c = (np.arange(n) + 0.5) / SS
    x, y = np.meshgrid(c, c)
    half = SIZE / 2
    u = (x - half) / half
    v = (y - half) / half
    d = np.hypot(u, v)

    if callable(fill):
        f = fill(u, v)
    else:
        f = np.broadcast_to(np.array(fill, float), u.shape + (3,))
    col = np.where((d <= INNER)[..., None], f, np.array(rim, float))
    a = (d <= 1.0).astype(float)

    # Усреднение в предумноженной альфе: край круга остаётся цветом обводки,
    # а не темнеет к прозрачному чёрному.
    prem = (col * a[..., None]).reshape(SIZE, SS, SIZE, SS, 3).mean(axis=(1, 3))
    alpha = a.reshape(SIZE, SS, SIZE, SS).mean(axis=(1, 3))
    rgb = np.where(alpha[..., None] > 0, prem / np.maximum(alpha[..., None], 1e-9), 0)
    rgba = np.dstack([np.clip(np.rint(rgb), 0, 255), np.rint(alpha * 255)])
    return Image.fromarray(rgba.astype(np.uint8), "RGBA")


def main():
    for name, (rim, fill) in DOTS.items():
        path = os.path.join(DST, "dot-%s.png" % name)
        render(rim, fill).save(path, optimize=True)
        print(path, os.path.getsize(path), "bytes")


if __name__ == "__main__":
    main()
