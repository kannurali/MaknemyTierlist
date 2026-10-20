# Карты смещения для стеклянных фигур лида главной (эффект Glass из Figma).
#
#   python tools/make-glass-maps.py
#
# Пишет public_html/assets/design/home/glass-<фигура>.png. Это вход
# feDisplacementMap в SVG-фильтрах home.php: R и G — сдвиг по x и y
# (0.5 = на месте), вектор направлен от края внутрь фигуры. У края фон
# берётся из глубины стекла и растягивается — так выглядит преломление
# Figma (refraction) в полосе шириной depth.
#
# Форма в единицах бокса 0..1 повторяет макет: круг 163, квадраты 226 и
# 94.65 со скруглением 18, треугольник — правильный, вписанный в круг 109,
# со скруглением 11 (так Figma рисует POLYGON с тремя углами).
#
# Сила сдвига задаётся в home.php (scale фильтра), здесь только форма
# поля: 1 у самого края, 0 на глубине depth и дальше.

import math
import os

import numpy as np
from PIL import Image

N = 256
OUT = os.path.join(os.path.dirname(__file__), '..', 'public_html', 'assets', 'design', 'home')


def grid():
    c = (np.arange(N) + 0.5) / N
    return np.meshgrid(c, c)


def sd_round_box(x, y, half, radius):
    qx = np.abs(x - 0.5) - (half - radius)
    qy = np.abs(y - 0.5) - (half - radius)
    out = np.hypot(np.maximum(qx, 0), np.maximum(qy, 0))
    ins = np.minimum(np.maximum(qx, qy), 0)
    return out + ins - radius


def sd_circle(x, y):
    return np.hypot(x - 0.5, y - 0.5) - 0.5


def sd_polygon(x, y, pts):
    d = np.full(x.shape, np.inf)
    sign = np.ones(x.shape)
    n = len(pts)
    for i in range(n):
        ax, ay = pts[i]
        bx, by = pts[(i + 1) % n]
        ex, ey = bx - ax, by - ay
        wx, wy = x - ax, y - ay
        t = np.clip((wx * ex + wy * ey) / (ex * ex + ey * ey), 0, 1)
        dx, dy = wx - ex * t, wy - ey * t
        d = np.minimum(d, dx * dx + dy * dy)
        c1 = y >= ay
        c2 = y < by
        c3 = ex * wy > ey * wx
        flip = (c1 & c2 & c3) | (~c1 & ~c2 & ~c3)
        sign = np.where(flip, -sign, sign)
    return sign * np.sqrt(d)


def sd_round_triangle(x, y):
    size = 109.0
    r = 11.0 / size
    cx = cy = 0.5
    rad = 0.5
    verts = [(cx + rad * math.sin(math.radians(a)), cy - rad * math.cos(math.radians(a))) for a in (0, 120, 240)]
    k = (rad / 2 - r) / (rad / 2)
    inner = [(cx + k * (vx - cx), cy + k * (vy - cy)) for vx, vy in verts]
    return sd_polygon(x, y, inner) - r


def write(name, sd, depth):
    inside = -sd
    gy, gx = np.gradient(sd, 1.0 / N)
    norm = np.hypot(gx, gy) + 1e-9
    nx, ny = gx / norm, gy / norm
    t = np.clip(inside / depth, 0, 1)
    m = np.where(inside > 0, (1 - t) ** 2, 0)
    dx = -nx * m
    dy = -ny * m
    rgb = np.zeros((N, N, 3), dtype=np.uint8)
    rgb[..., 0] = np.clip(np.round((0.5 + 0.5 * dx) * 255), 0, 255)
    rgb[..., 1] = np.clip(np.round((0.5 + 0.5 * dy) * 255), 0, 255)
    rgb[..., 2] = 128
    path = os.path.join(OUT, 'glass-' + name + '.png')
    Image.fromarray(rgb, 'RGB').save(path, optimize=True)
    print(path, os.path.getsize(path))


x, y = grid()
write('circle', sd_circle(x, y), 38 / 163)
write('square-lg', sd_round_box(x, y, 0.5, 18 / 226), 33 / 226)
write('square-sm', sd_round_box(x, y, 0.5, 18 / 94.65), 21 / 94.65)
write('tri', sd_round_triangle(x, y), 24 / 109)
