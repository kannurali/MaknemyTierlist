"""Картинки Хэллоуин-темы: public_html/assets/halloween/.

    python tools/make-halloween-assets.py --figma <папка с выгрузкой из Figma>

Две группы.

1. Из макета (Figma, страница «хелоуин»). REST-рендер нод у файла лимитирован,
   поэтому всё выгружено через плагин Figma Console (exportAsync) в PNG:
     castle-top.png, castle-bot.png  исходник фона 1080×1920 двумя кусками
                                     (y 0–980 и 940–1920): за один экспорт
                                     плагин больше 1568 px не отдаёт;
     branch.png                      исходник веток 1249×700 (замена сакуры);
     bat.png                         летучая мышь, компонент «image 5», 2x;
     web.png                         паутина, компонент «image 6», 3x;
     card-*.png                      пять картинок карточек главной, 482×482.
   Фон режется так же, как в макете: нода 1983×3236 с заливкой FILL, то есть
   по высоте видна середина исходника 1080×1762. Ветки — по imageTransform
   своих нод (CROP), сакура новостей — FILL.

   Из того же фона собран stage-export.jpg — подложка сцены тирлиста при
   сохранении в PNG: на экране сцена прозрачная и стоит на фоне страницы,
   а в картинке фона страницы нет.

2. Синие картинки сайта (полосы тирлиста, свечение подвала) перекрашиваются
   тем же поворотом оттенка, что и цвета в tools/halloween_css.php: синий
   185–250° уходит в 268–292°, насыщенность и светлота не меняются. Края
   диапазона и малонасыщенные пиксели сдвигаются плавно, иначе на градиентах
   вылезают ступеньки.
"""
import argparse
from pathlib import Path

import numpy as np
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent / 'public_html' / 'assets'
OUT = ROOT / 'halloween'


def smoothstep(e0, e1, x):
    t = np.clip((x - e0) / (e1 - e0), 0, 1)
    return t * t * (3 - 2 * t)


def shift_blue(im):
    rgba = im.convert('RGBA')
    a = np.asarray(rgba).astype(np.float64) / 255
    rgb = a[..., :3]
    mx = rgb.max(-1)
    mn = rgb.min(-1)
    d = mx - mn
    l = (mx + mn) / 2
    s = np.where(d == 0, 0, d / np.where(l > 0.5, 2 - mx - mn, mx + mn + 1e-12))
    r, g, b = rgb[..., 0], rgb[..., 1], rgb[..., 2]
    dd = np.where(d == 0, 1, d)
    h = np.where(mx == r, ((g - b) / dd) % 6, np.where(mx == g, (b - r) / dd + 2, (r - g) / dd + 4)) * 60
    target = 268 + (np.clip(h, 185, 250) - 185) * 24 / 65
    w = smoothstep(170, 185, h) * (1 - smoothstep(250, 262, h)) * smoothstep(0.08, 0.2, s)
    nh = h + (target - h) * w
    hsv_s = np.where(mx == 0, 0, d / np.where(mx == 0, 1, mx))
    hh = (nh % 360) / 60
    c = mx * hsv_s
    x = c * (1 - np.abs(hh % 2 - 1))
    m = mx - c
    z = np.zeros_like(c)
    i = np.floor(hh).astype(int) % 6
    out = np.select(
        [i[..., None] == k for k in range(6)],
        [np.stack(t, -1) for t in [(c, x, z), (x, c, z), (z, c, x), (z, x, c), (x, z, c), (c, z, x)]],
    ) + m[..., None]
    a[..., :3] = out
    res = Image.fromarray(np.round(np.clip(a, 0, 1) * 255).astype(np.uint8), 'RGBA')
    return res if im.mode == 'RGBA' else res.convert(im.mode if im.mode in ('RGB', 'L') else 'RGB')


def save(im, name, **kw):
    path = OUT / name
    if name.endswith('.webp'):
        im.save(path, 'WEBP', quality=kw.get('quality', 82), method=6)
    elif name.endswith('.jpg'):
        im.convert('RGB').save(path, 'JPEG', quality=kw.get('quality', 85), optimize=True, progressive=True)
    else:
        im.save(path, 'PNG', optimize=True)
    print(name, im.size, path.stat().st_size)


def crop_transform(src, size, t):
    """Как Figma рисует заливку CROP: единичный квадрат ноды → часть картинки
    [tx, tx+sx] × [ty, ty+sy] в долях исходника. Всё, что за краем, — прозрачно."""
    (sx, _, tx), (_, sy, ty) = t
    w, h = src.size
    box = (tx * w, ty * h, (tx + sx) * w, (ty + sy) * h)
    return src.transform(size, Image.EXTENT, box, Image.BICUBIC, fillcolor=(0, 0, 0, 0))


def cover(src, size):
    w, h = src.size
    k = max(size[0] / w, size[1] / h)
    cw, ch = size[0] / k, size[1] / k
    x0 = (w - cw) / 2
    y0 = (h - ch) / 2
    return src.transform(size, Image.EXTENT, (x0, y0, x0 + cw, y0 + ch), Image.BICUBIC)


def from_figma(fig):
    top = Image.open(fig / 'castle-top.png').convert('RGB')
    bot = Image.open(fig / 'castle-bot.png').convert('RGB')
    castle = Image.new('RGB', (1080, 1920))
    castle.paste(bot, (0, 940))
    castle.paste(top, (0, 0))
    k = max(1983 / 1080, 3236 / 1920)
    vis = 3236 / k
    y0 = (1920 - vis) / 2
    bg = castle.crop((0, round(y0), 1080, round(y0 + vis)))
    save(bg, 'page-bg.webp', quality=80)
    save(bg.resize((760, round(760 * bg.height / bg.width)), Image.LANCZOS), 'page-bg-m.webp', quality=78)
    save(bg, 'stage-export.jpg', quality=86)

    branch = Image.open(fig / 'branch.png').convert('RGBA')
    save(crop_transform(branch, (868, 890), ((0.5465945601463318, 0, 0.37209352850914), (0, 1, 0.04540513455867767))),
         'branch-l.webp', quality=85)
    save(crop_transform(branch, (995, 936), ((0.6267796158790588, 0, 0.37209352850914), (0, 1.0517328977584839, -0.006327704526484013))),
         'branch-r.webp', quality=85)
    save(cover(branch, (868, 890)), 'branch.webp', quality=85)

    save(Image.open(fig / 'bat.png').convert('RGBA'), 'bat.webp', quality=90)
    save(Image.open(fig / 'web.png').convert('RGBA'), 'web.webp', quality=90)
    for name in ['fruits', 'tier', 'prices', 'giveaways', 'news']:
        save(Image.open(fig / f'card-{name}.png').convert('RGBA'), f'card-{name}.webp', quality=85)


def recolored():
    for src, dst, q in [
        ('design/foot/foot-bg.webp', 'foot-bg.webp', 85),
        ('poster/band.webp', 'band.webp', 90),
        ('poster/band.png', 'band.png', 0),
    ]:
        save(shift_blue(Image.open(ROOT / src)), dst, quality=q)


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--figma', type=Path, help='папка с PNG из Figma; без неё — только перекраска')
    args = ap.parse_args()
    OUT.mkdir(exist_ok=True)
    if args.figma:
        from_figma(args.figma)
    recolored()
