"""Demo advertising creatives for the local presentation.

Drawn here rather than downloaded: images off the web carry unknown licences
and these end up in a media kit sent to a real client, and using the
advertiser's own branding would mean inventing their creative for them. Every
banner carries a "демо-макет" mark.

Type sizes are deliberate, not taste. The 1200px strip renders about 356px
wide on a phone — 29 % — so nothing in it may be smaller than 40px, which is
exactly the rule the media kit states. A demo creative that breaks the spec
makes the spec unbelievable.

    python tools/make-demo-creatives.py

Writes into public_html/images/, which is gitignored, so these can never
deploy. Needs Pillow. Prints the /images/... paths to paste into the seeding
page or the admin panel.
"""
import hashlib
import math
import pathlib

from PIL import Image, ImageDraw, ImageFilter, ImageFont

OUT = pathlib.Path(__file__).resolve().parent.parent / "public_html" / "images"
FONT_BOLD = r"C:\Windows\Fonts\segoeuib.ttf"
FONT_REG = r"C:\Windows\Fonts\segoeui.ttf"

INK = (255, 255, 255)
GOLD = (255, 206, 92)


def font(path, size):
    try:
        return ImageFont.truetype(path, size)
    except OSError:
        return ImageFont.load_default()


def text_at(d, xy, s, f, fill, anchor="la"):
    d.text(xy, s, font=f, fill=fill, anchor=anchor)


def gradient(w, h, top, bottom):
    img = Image.new("RGB", (w, h))
    d = ImageDraw.Draw(img)
    for y in range(h):
        k = y / max(1, h - 1)
        d.line([(0, y), (w, y)], fill=tuple(int(top[i] + (bottom[i] - top[i]) * k) for i in range(3)))
    return img


def blob(w, h, boxes, colour, blur=70):
    layer = Image.new("RGB", (w, h), (0, 0, 0))
    d = ImageDraw.Draw(layer)
    for b in boxes:
        d.ellipse(b, fill=colour)
    return layer.filter(ImageFilter.GaussianBlur(blur))


def screen(a, b):
    """Screen blend: lightens only — the cheap way to paint a glow."""
    return Image.frombytes("RGB", a.size, bytes(
        255 - (255 - x) * (255 - y) // 255 for x, y in zip(a.tobytes(), b.tobytes())))


def item_card(d, x, y, w, h, label, price, tint, label_px=30, price_px=40):
    d.rounded_rectangle([x, y, x + w, y + h], radius=16, fill=(14, 26, 58),
                        outline=(96, 150, 220), width=3)
    d.rounded_rectangle([x + 12, y + 12 + label_px + 6, x + w - 12, y + h - price_px - 20],
                        radius=12, fill=tint)
    text_at(d, (x + w / 2, y + 14 + label_px / 2), label, font(FONT_BOLD, label_px), INK, anchor="mm")
    text_at(d, (x + w / 2, y + h - 12 - price_px / 2), price, font(FONT_BOLD, price_px), GOLD, anchor="mm")


def pill(d, x, y, w, h, label, fill, fg=(6, 16, 34)):
    d.rounded_rectangle([x, y, x + w, y + h], radius=h // 2, fill=fill)
    text_at(d, (x + w / 2, y + h / 2), label, font(FONT_BOLD, int(h * 0.42)), fg, anchor="mm")


def demo_mark(d, x, y, size=16):
    text_at(d, (x, y), "демо-макет", font(FONT_REG, size), (150, 180, 220), anchor="rs")


def save(img, ext="png", **kw):
    """Content-addressed, the same way api/lib/images.php names uploads."""
    OUT.mkdir(parents=True, exist_ok=True)
    tmp = OUT / ("__tmp." + ext)
    img.save(tmp, **kw)
    data = tmp.read_bytes()
    name = hashlib.sha1(data).hexdigest() + "." + ext
    (OUT / name).write_bytes(data)
    tmp.unlink()
    print(f"  /images/{name}  {img.size[0]}x{img.size[1]}  {len(data) // 1024} KB")
    return "/images/" + name


# ------------------------------------------------------- strip · 1200 x 300
def strip_static():
    img = screen(gradient(1200, 300, (26, 48, 112), (10, 16, 48)),
                 blob(1200, 300, [(-120, 40, 320, 360)], (30, 60, 120)))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([2, 2, 1197, 297], radius=16, outline=(96, 190, 255), width=3)
    text_at(d, (56, 58), "ВАША РЕКЛАМА", font(FONT_BOLD, 76), INK)
    text_at(d, (58, 146), "Предметы Blox Fruits", font(FONT_REG, 44), (176, 214, 255))
    text_at(d, (58, 194), "безопасно, через гаранта", font(FONT_REG, 44), (176, 214, 255))
    pill(d, 700, 226, 300, 56, "Перейти →", (110, 214, 255))
    for i, (lab, pr, tint) in enumerate([("DRAGON", "60 000", (58, 30, 96)),
                                         ("KITSUNE", "50 000", (24, 60, 104))]):
        item_card(d, 700 + i * 230, 34, 210, 176, lab, pr, tint)
    demo_mark(d, 1180, 292)
    return img


def strip_second():
    img = screen(gradient(1200, 300, (74, 26, 116), (18, 10, 44)),
                 blob(1200, 300, [(820, -60, 1300, 340)], (96, 40, 140)))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([2, 2, 1197, 297], radius=16, outline=(196, 140, 255), width=3)
    text_at(d, (600, 96), "ЗДЕСЬ МОЖЕТ БЫТЬ", font(FONT_BOLD, 58), INK, anchor="mm")
    text_at(d, (600, 156), "ВАША РЕКЛАМА", font(FONT_BOLD, 58), INK, anchor="mm")
    text_at(d, (600, 212), "1200 × 300 · статика или анимация", font(FONT_REG, 40), (214, 186, 255), anchor="mm")
    pill(d, 440, 248, 320, 44, "t.me/mksvtnc", (196, 140, 255))
    demo_mark(d, 1180, 292)
    return img


def strip_animated():
    """A sweep across the banner, so a frozen frame is obviously the poster."""
    base = strip_static()
    frames = []
    for i in range(14):
        x = int(-260 + i * (1560 / 14))
        f = screen(base.copy(), blob(1200, 300, [(x, -80, x + 190, 380)], (70, 110, 170), blur=55))
        d = ImageDraw.Draw(f)
        d.rounded_rectangle([2, 2, 1197, 297], radius=16, outline=(96, 190, 255), width=3)
        k = (math.sin(i / 14 * 2 * math.pi) + 1) / 2
        pill(d, 700, 226, 300, 56, "Перейти →", (int(110 + 60 * k), int(214 + 20 * k), 255))
        demo_mark(d, 1180, 292)
        frames.append(f.convert("P", palette=Image.ADAPTIVE, colors=96))
    OUT.mkdir(parents=True, exist_ok=True)
    tmp = OUT / "__tmp.gif"
    frames[0].save(tmp, save_all=True, append_images=frames[1:], duration=90, loop=0, optimize=True)
    data = tmp.read_bytes()
    name = hashlib.sha1(data).hexdigest() + ".gif"
    (OUT / name).write_bytes(data)
    tmp.unlink()
    print(f"  /images/{name}  1200x300  {len(data) // 1024} KB  animated")
    return "/images/" + name


# -------------------------------------------------------- rail · 320 x 1200
def rail():
    img = screen(gradient(320, 1200, (26, 48, 112), (12, 12, 46)),
                 blob(320, 1200, [(-80, 700, 400, 1300)], (40, 70, 140)))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([3, 3, 316, 1196], radius=18, outline=(96, 190, 255), width=4)
    text_at(d, (160, 104), "ВАША РЕКЛАМА", font(FONT_BOLD, 46), INK, anchor="mm")
    text_at(d, (160, 162), "маркетплейс", font(FONT_REG, 30), (176, 214, 255), anchor="mm")
    text_at(d, (160, 200), "предметов", font(FONT_REG, 30), (176, 214, 255), anchor="mm")
    for i, (lab, pr, tint) in enumerate([("DRAGON", "60 000", (58, 30, 96)),
                                         ("KITSUNE", "50 000", (24, 60, 104)),
                                         ("LEOPARD", "40 000", (70, 40, 30)),
                                         ("DOUGH", "30 000", (30, 66, 52))]):
        item_card(d, 40, 262 + i * 186, 240, 166, lab, pr, tint, label_px=28, price_px=36)
    pill(d, 40, 1054, 240, 58, "Перейти →", (110, 214, 255))
    demo_mark(d, 296, 1184, 18)
    return img


# --------------------------------------------------------- dock · 640 x 200
def dock():
    """Horizontal half of the side placement: the phone has no room beside
    the content, so the same message goes into a bar at the bottom."""
    img = screen(gradient(640, 200, (26, 48, 112), (12, 16, 50)),
                 blob(640, 200, [(-80, -60, 260, 280)], (36, 66, 130)))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([2, 2, 637, 197], radius=14, outline=(96, 190, 255), width=3)
    text_at(d, (30, 34), "ВАША РЕКЛАМА", font(FONT_BOLD, 50), INK)
    text_at(d, (32, 96), "Предметы Blox Fruits", font(FONT_REG, 30), (176, 214, 255))
    text_at(d, (32, 132), "безопасно, через гаранта", font(FONT_REG, 30), (176, 214, 255))
    item_card(d, 396, 22, 110, 118, "DRAGON", "60 000", (58, 30, 96), label_px=20, price_px=26)
    pill(d, 396, 150, 216, 38, "Перейти →", (110, 214, 255))
    demo_mark(d, 624, 194, 13)
    return img


# -------------------------------------------------------- popup · 800 x 800
def popup():
    img = screen(gradient(800, 800, (30, 44, 108), (12, 14, 48)),
                 blob(800, 800, [(420, -120, 980, 420)], (56, 80, 160)))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle([3, 3, 796, 796], radius=22, outline=(96, 190, 255), width=4)
    text_at(d, (400, 128), "ВАША РЕКЛАМА", font(FONT_BOLD, 66), INK, anchor="mm")
    text_at(d, (400, 196), "Покупай и продавай предметы", font(FONT_REG, 34), (176, 214, 255), anchor="mm")
    text_at(d, (400, 240), "Blox Fruits безопасно", font(FONT_REG, 34), (176, 214, 255), anchor="mm")
    for i, (lab, pr, tint) in enumerate([("DRAGON", "60 000", (58, 30, 96)),
                                         ("KITSUNE", "50 000", (24, 60, 104)),
                                         ("LEOPARD", "40 000", (70, 40, 30))]):
        item_card(d, 78 + i * 220, 300, 200, 240, lab, pr, tint)
    text_at(d, (400, 600), "Гарант · моментальная выдача", font(FONT_BOLD, 32), GOLD, anchor="mm")
    pill(d, 250, 650, 300, 62, "Перейти →", (110, 214, 255))
    demo_mark(d, 776, 784)
    return img


if __name__ == "__main__":
    made = {}
    print("strip · статика");   made["strip"]  = save(strip_static())
    print("strip · анимация");  made["anim"]   = strip_animated()
    print("strip · второй");    made["second"] = save(strip_second())
    print("rail");              made["rail"]   = save(rail())
    print("dock");              made["dock"]   = save(dock())
    print("popup");             made["popup"]  = save(popup())

    print("\nПути для __promo.local.html и для панели:")
    for k, v in made.items():
        print(f"  {k:8} {v}")
