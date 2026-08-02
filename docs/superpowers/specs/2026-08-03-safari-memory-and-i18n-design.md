# Safari rendering failures + interface language switch

Two pieces of work driven by one report: on Safari (iPhone, iPad and Mac) icons
vanish, parts of the page turn black while scrolling or interacting, and icons
appear late. Separately, the interface needed a RU/EN switch.

## Part 1 — Safari

### What was measured

Reproduced on the real WebKit engine (Playwright, iPhone 390x844 DPR 3 and
desktop 1440x900 DPR 2) against a local copy of production data: 10 tiers,
113 items, 114 uploaded images.

Peak surfaces the engine has to hold, filter "Все" (everything visible):

| surface                              | iPhone | Mac    |
| ------------------------------------ | ------ | ------ |
| stage backing store                  | 30 MB  | 43 MB  |
| `.petals` (opacity < 1 → own layer)  | 30 MB  | 43 MB  |
| decoded item icons                   | 63 MB  | 63 MB  |
| **total**                            | **154 MB** | **191 MB** |

Elements carrying `filter: drop-shadow`: 118 on iPhone, 365 on Mac.

A Safari tab has a few hundred MB before the engine starts shedding memory.
The decoded-image cache is what it drops first, which produces all three
reported symptoms from a single cause:

- dropped decoded bitmaps → icons visibly disappear;
- re-decoding them when they scroll back → icons appear late;
- a composited layer whose backing store cannot be allocated → black rectangle.

### Root causes and fixes

**1. Icons stored at source resolution.** Uploads were saved untouched, up to
800x800, while an icon paints at 133 device px (Mac) / 123 device px (iPhone).
`save_image_bytes` now caps the long side at `ICON_MAX_SIDE = 256` — roughly a
2x reserve over the largest real paint size — keeping the source format and the
alpha channel. Images already within the cap pass through byte-for-byte, so
their sha1 filename and immutable cache entry stay valid.

Already-uploaded icons are migrated by `tools/downscale-images.php`, which
writes each resized image under a new content-hash name, repoints the stored
state and bumps `rev`. Originals are left on disk — `/images/` is served
`immutable`, so overwriting a name in place would pin browsers to stale bytes.
The script lists the now-unreferenced files for manual cleanup and supports
`--dry-run`.

Result: 63 MB → 18.5 MB of decoded icons.

**2. `.petals` carried `opacity: .7`.** Any opacity below 1 makes WebKit render
the element into a transparency layer the size of the whole stage. The 0.7 is
now baked into the alpha channel of `petals.png` / `petals.webp` /
`petals-m.webp` and the CSS property is gone. Verified pixel-identical: max
channel difference 6/255 across 300 of 2 million pixels, all from WebP
re-encoding rounding.

This follows the same reasoning that had already removed `mix-blend-mode` from
this element; the `opacity` was simply missed at the time.

**3. `filter: drop-shadow` on hundreds of tiny elements.** Each one is a
separate render surface. The rule zeroing them for `.dot`, `.trend` and
`.tbadge` lived only in the phone media query, although those elements are
14x14 and 42x19 px on desktop too — the shadow is not perceivable at that size.
It moved into the base styles. On phones the item icon shadow is dropped as
well (39 px cell, 113 of them, and the phone is the memory-constrained
platform); on desktop, at 62 px, it stays.

Result: filter surfaces 118 → 5 on iPhone, 365 → 118 on Mac.

**4. Every icon was `loading="lazy"`, including the first screen.** A lazy image
is only fetched after layout, so the top rows — always visible — arrived late.
`render()` now gives the first two rows' worth of icons `loading="eager"` and
`fetchpriority="high"`; everything below stays lazy. `eagerLoadStageImages()`
(the PNG export pre-pass) now awaits every stage image rather than only the
ones still marked lazy, which would otherwise skip the eager ones.

### Measured outcome

| | before | after |
| --- | --- | --- |
| iPhone, filter "Все" | 154 MB | 48.5 MB |
| Mac, filter "Все"    | 191 MB | 59 MB |
| filter surfaces (iPhone) | 118 | 5 |
| above-the-fold icons loaded at first paint | 0 | all |

### How to reproduce this class of bug locally

Scrolling the real page in headless WebKit proves nothing: a Mac never reaches
the memory ceiling that makes the engine shed decoded images, so the broken and
the fixed build both look clean. Two things matter when building a repro:

- **The icons must be unique.** The engine decodes each distinct URL once, so
  duplicating the same 113 items any number of times does not grow the decoded
  cache at all. Inflating the list 40x (4520 items) only grew the stage backing
  store — to 977 MB, which WebKit held without dropping anything.
- **Pressure comes from unique pixels.** 500 generated 800x800 icons put 220 MB
  of decoded bitmaps in the cache. Then: screenshot the top of the page, scroll
  to the bottom and back twice, return to the top, screenshot again. If the two
  frames differ, the engine dropped and re-rasterised the icons.

Measured with that harness, three runs each:

| icons                        | decoded | top frame after a full scroll |
| ---------------------------- | ------- | ----------------------------- |
| 500 unique, 800x800 (before) | 220 MB  | differs — textures dropped    |
| the same 500 at 256x256      | 22 MB   | identical                     |

The difference mask lines up exactly with the item icons, row by row — not the
background, not the text.

### Not changed, and why

`.stage::before` is a plain gradient overlay with no opacity, transform or
filter, so WebKit paints it into the stage's own layer rather than a separate
backing store. Folding it into the stage's `background-image` would mean
touching all three `image-set` fallback declarations for no measurable gain.

## Part 2 — RU/EN switch

Scope: interface only. Tier and item names, descriptions, the ad block, credits
and footer links come from the database exactly as the admin typed them and are
not translated. Export diagnostics in `app.js` (html2canvas troubleshooting,
admin-only, console) are developer tooling, not interface.

- `public_html/js/i18n.js` — the `{ru, en}` dictionary plus `t(key, lang)` and
  `pickLang(stored, navigatorLang)`. No DOM access, so it loads via `<script>`
  in the browser and via `require` in node tests.
- Markup carries `data-i18n`, `data-i18n-title`, `data-i18n-alt`,
  `data-i18n-label` and `data-i18n-placeholder`. Nothing is matched by text
  content at runtime.
- `applyLang(lang)` in `app.js` walks the marked nodes, sets
  `document.documentElement.lang`, and updates the switch's `aria-pressed`.
  It runs before the first `render()` so the interface never flashes the wrong
  language. Switching also re-renders, because some labels (save button, tier
  tooltips) are created in JS.
- The switch is a segmented `RU | EN` pair in the toolbar built from the
  existing `.chip` tokens. The choice persists in `localStorage`; with no stored
  choice the browser language decides, defaulting to Russian.
- The legend lives inside `#stage`, so **the exported PNG follows the selected
  language** — chosen deliberately, so an English-speaking admin can publish an
  English image.

The translation helper is named `tx`, not `t`: `t` is already bound to a tier or
a timer in nine places in `app.js`, and a shadowed `t("key")` would fail
silently inside those blocks.

### Tests

`tests/i18n_test.mjs` (`node --test`, wired into `tests/run_all.sh`) covers key
parity between the two tables, empty values, Cyrillic left in the English table,
fallback for unknown language and unknown key, prototype-property confusion
(`t('constructor')`), and every `pickLang` branch.

`tests/images_test.php` covers the cap, aspect ratio, pass-through below the
cap, no upscaling, alpha preservation, format preservation, undecodable input,
that the stored filename hashes the stored bytes, dedup, and every branch of the
migration including dry mode.
