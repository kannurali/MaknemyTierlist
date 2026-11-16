#!/usr/bin/env python3
"""Convert advertiser-supplied artwork into the slot creatives of a code campaign.

An advertiser delivers one PNG per placement, already at (or close to) the slot
geometry.  Nothing here composes art -- unlike tools/make-giveaway-creatives.py,
which draws our own banners.  This script re-encodes to WebP and walks the quality
down until the file fits the per-slot byte ceiling enforced by CREATIVE_SPECS in
public_html/api/lib/images.php.

Playerok: one shape correction happens on the way.  The rail is delivered
300x1200, but the rail box in css/styles.css used to be 160x600 with
object-fit: cover, so a 300-wide source was scaled to fill the width and then
cropped by ~37 source px at the top and bottom -- exactly where the Playerok
wordmark and the round badge sit.  Rather than stretch or crop the artwork, the
canvas is widened to 320 and the 10 px bands on either side are filled by
repeating the outermost source column of each row.  The background there is a
smooth gradient over most of the height, and the one place it is not (the
creature's fur, around y=900) smears by ~5 px at display size.

Starvell: since PR #132 every rail box takes the creative's own aspect ratio
(PROMO.fitBox), so the 300x1200 rail is kept as delivered.  Starvell also sends
a collapsed-dock face ("Mobile mini", 810x224); it is not converted.  At most
48 px tall its tagline is unreadable, so the folded bar shows the dock creative
as a thumbnail next to the line promo.starvellDockText, the way Playerok does.

Kupa: the popup arrives as a 1254x1254 square and is scaled down to 800x800.
Kupa's collapsed-dock face is a 374x47 line ("kupa.gg - cheap fruits") whose
text stays legible at the 48 px the folded bar allows, so unlike Starvell's it
ships as dockMini, at its delivered size (inside the 640x80 dockMini spec).

kupa-robux: on 2026-11-17 the owner had the slogan on Kupa's strip, rail, dock
and dockMini changed from "fruits" to "robux" (the popup kept its own text).
The relettered sources are our edit of the advertiser's art, not theirs; they
ship under new names because /assets images are cached for an hour, and the
popup keeps using kupa-popup.webp.

The source images are not in the repository: they are the advertiser's originals
and weigh ~2 MB together.  Point --src at the directory holding them (searched
recursively, so unpacked zip folders work); PNG and WebP are both read.  Files
are matched by pixel size, not by name, because the delivered names mix Latin
"x" and Cyrillic "x" as the separator.

Usage:
    python tools/make-promo-creatives.py --advertiser playerok --src ~/Downloads
    python tools/make-promo-creatives.py --advertiser starvell --src ~/Downloads/starvell
    python tools/make-promo-creatives.py --advertiser kupa --src ~/Downloads/kupa
    python tools/make-promo-creatives.py --advertiser kupa-robux --src ~/kupa-robux
"""

import argparse
import os
import sys

from PIL import Image

# advertiser -> slot -> (source width, source height, output width, output height,
# max bytes).  The byte ceilings mirror CREATIVE_SPECS for a still image.  Output
# geometry differs from the source only for the Playerok rail (widened) and the
# Kupa popup (scaled down); see above.  Slots
# are written as <advertiser>-<file>.webp, file being the slot name except for
# dockMini (dock-mini).
ADVERTISERS = {
    "playerok": {
        "strip": (1200, 300, 1200, 300, 400000),
        "rail": (300, 1200, 320, 1200, 300000),
        "dock": (640, 200, 640, 200, 200000),
        "popup": (800, 800, 800, 800, 400000),
    },
    "starvell": {
        "strip": (1200, 300, 1200, 300, 400000),
        "rail": (300, 1200, 300, 1200, 300000),
        "dock": (640, 200, 640, 200, 200000),
        "popup": (800, 800, 800, 800, 400000),
    },
    "kupa": {
        "strip": (1200, 300, 1200, 300, 400000),
        "rail": (300, 1200, 300, 1200, 300000),
        "dock": (640, 200, 640, 200, 200000),
        "dockMini": (374, 47, 374, 47, 100000),
        "popup": (1254, 1254, 800, 800, 400000),
    },
    "kupa-robux": {
        "strip": (1200, 300, 1200, 300, 400000),
        "rail": (300, 1200, 300, 1200, 300000),
        "dock": (640, 200, 640, 200, 200000),
        "dockMini": (374, 47, 374, 47, 100000),
    },
}

FILE_NAMES = {"dockMini": "dock-mini"}

OUT_DIR = os.path.join("public_html", "assets", "promo")


def find_sources(src_dir, slots):
    """Map each slot to the source file whose pixel size matches it."""
    by_size = {}
    for dirpath, _dirs, names in sorted(os.walk(src_dir)):
        for name in sorted(names):
            if not name.lower().endswith((".png", ".webp")):
                continue
            path = os.path.join(dirpath, name)
            try:
                with Image.open(path) as im:
                    by_size.setdefault(im.size, path)
            except Exception:
                continue

    found = {}
    for slot, (sw, sh, _ow, _oh, _cap) in slots.items():
        path = by_size.get((sw, sh))
        if path is None:
            raise SystemExit(
                "no %dx%d image in %s (needed for the %s slot)" % (sw, sh, src_dir, slot)
            )
        found[slot] = path
    return found


def widen(im, out_w):
    """Centre im on an out_w canvas, filling the side bands with its edge columns."""
    src_w, h = im.size
    if out_w == src_w:
        return im
    if out_w < src_w:
        raise SystemExit("widen() only pads, cannot crop %d -> %d" % (src_w, out_w))

    left = (out_w - src_w) // 2
    right = out_w - src_w - left
    canvas = Image.new("RGB", (out_w, h))
    canvas.paste(im, (left, 0))
    if left:
        band = im.crop((0, 0, 1, h)).resize((left, h), Image.NEAREST)
        canvas.paste(band, (0, 0))
    if right:
        band = im.crop((src_w - 1, 0, src_w, h)).resize((right, h), Image.NEAREST)
        canvas.paste(band, (out_w - right, 0))
    return canvas


def fit(im, out_w, out_h):
    """Scale im down to out_w x out_h when the shape is the same, else pad its width."""
    src_w, src_h = im.size
    if (src_w, src_h) != (out_w, out_h) and src_w * out_h == src_h * out_w:
        if out_w > src_w:
            raise SystemExit("fit() only scales down, cannot grow %d -> %d" % (src_w, out_w))
        return im.resize((out_w, out_h), Image.LANCZOS)
    return widen(im, out_w)


def encode(im, dest_path, cap):
    """Write im as WebP at the highest quality that stays under cap bytes."""
    for quality in range(92, 39, -2):
        im.save(dest_path, "WEBP", quality=quality, method=6)
        size = os.path.getsize(dest_path)
        if size <= cap:
            return quality, size
    raise SystemExit("cannot fit %s under %d bytes" % (dest_path, cap))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--advertiser", required=True, choices=sorted(ADVERTISERS))
    ap.add_argument("--src", required=True, help="directory with the source PNG/WebP files")
    ap.add_argument("--out", default=OUT_DIR, help="output directory")
    args = ap.parse_args()

    slots = ADVERTISERS[args.advertiser]
    sources = find_sources(os.path.expanduser(args.src), slots)
    os.makedirs(args.out, exist_ok=True)

    for slot, (sw, sh, ow, oh, cap) in slots.items():
        name = "%s-%s.webp" % (args.advertiser, FILE_NAMES.get(slot, slot))
        dest = os.path.join(args.out, name)
        with Image.open(sources[slot]) as src:
            im = fit(src.convert("RGB"), ow, oh)
        if im.size != (ow, oh):
            raise SystemExit("%s came out %dx%d, expected %dx%d" % ((slot,) + im.size + (ow, oh)))
        quality, size = encode(im, dest, cap)
        print(
            "%-8s %4dx%-4d  q%-3d %7d bytes  (cap %d)  <- %s %dx%d"
            % (slot, ow, oh, quality, size, cap, os.path.basename(sources[slot]), sw, sh)
        )


if __name__ == "__main__":
    sys.exit(main())
