# PSD poster redesign

Reproduce the design in `макнеми тир под сайт.psd` on the live site, with two
deliberate deviations requested by the owner:

1. The three "антивор" author cards (blacked out in the reference screenshot)
   are **not** reproduced. The tier list is a plain stack of bands with no
   comment cards.
2. The bottom of the poster — legend and link panel — is reproduced as drawn.

An earlier design attempt (PR #8, `design-2026-08`) was rejected and reverted on
2026-08-07. This one works from the actual PSD instead of a screenshot.

## Source of truth

`C:\Users\nural\Downloads\макнеми тир под сайт.psd`, canvas **1523 × 2486**.

Every number below is expressed in `cqw` — percent of stage width — because the
stage already uses `container-type: inline-size` and sizes its whole interior in
`cqw`. Conversion is `px / 1523 * 100`.

Measurements were taken with `psd-tools`: layer bounds and corner radii from the
vector origination data, type runs for fonts, and ink bounding boxes sampled off
a full-resolution composite render for cap heights (PSD point sizes are useless
here — every text layer carries a transform).

Two typefaces, both already shipped in `assets/fonts/`:

| PSD font           | CSS var      | used for                          |
| ------------------ | ------------ | --------------------------------- |
| `ProtoSans56`      | `--proto`    | date, `MAKNEMY TIER LIST` title   |
| `Bootshaus-Regular`| `--display`  | legend, footer links, tier labels |

The title currently renders in Bootshaus. Switching it to Proto Sans is the
single largest visible change in the header.

## Scope

Changes: `css/styles.css`, `index.html` (legend markup, script versions),
`js/app.js` (footer avatars), `js/i18n.js` (one new legend string), and the
background/asset set under `assets/`.

Unchanged: tier data model, toolbar, admin editing, likes, donate modal, PNG
export, guest copy protection, rev polling, PHP API, credits row.

## 1. Background

`assets/bg-ck.png` is already committed and is **byte-identical** (SHA-256
`6A501CE9…`) to the file the owner sent. It is 1523 × 2486 — the same dimensions
as the current `bg.png` — so it drops straight into the existing pipeline. It is
the same artwork as `bg.png` regraded from warm sunset to blue night; "цк" =
цветокор, i.e. the colour grade is already baked in.

Everything cut from the PSD lands under `assets/poster/`, leaving the previous
design's files untouched. Deployment copies the tree over without deleting, so
removing them would not clear the server anyway — and keeping them means a
rollback is a pure revert.

- Rebuild the background from `bg-ck.png`: `bg.webp` (1200 × 1958), `bg-m.webp`
  (760 × 1240) and `bg-export.jpg` for the export path. **Do not** multiply by
  `#091640` the way the current export does — that step existed to darken the
  ungraded `bg.png`, and applying it to an already-graded source would crush it.
- `background-size: 100% auto`, `background-position: center top`. The flat
  colour under it drops to `#05091f` to match the new grade.
- The mobile `@media` block overrides both the stage and the petals with the
  small variants. It must be repointed too, or phones keep loading the old warm
  background while desktop shows the new one.
- No `mix-blend-mode` and no `opacity < 1` anywhere on the stage. This is a hard
  constraint from the Safari memory work — each one costs WebKit an offscreen
  layer the size of the stage (~17 MB per group on iPhone).
- `.petals` is regenerated from the PSD `декор` group (sakura branches, drifting
  petals, the boulder) in the new blue grade. The current petals are pink-white
  and will read wrong over a blue background. Opacity stays baked into the
  alpha channel of the file, not applied in CSS.

`.petals` moves from `background-size: cover` to the same `100% auto` / `center
top` as the stage background. Under `cover` the layer stretched to the height of
the page — ~4400 px on real data — which dragged the bright bokeh from the
poster's corners into the middle and parked a blue smear over the legend.
Aligning it to its own background costs the decor below the image's runout,
where the stage is flat `#05091f`; that region was already flat before.

Bump `?v=` on `styles.css`, `app.js` and `i18n.js` in `index.html` — the site is
served with immutable per-revision caching and visitors otherwise keep old files.

## 2. Assets extracted from the PSD

Rendered from the composite (so the global colour-grade stack is included), then
converted to WebP with a PNG fallback where the existing code expects PNG:

| asset               | PSD source                            | size (px) |
| ------------------- | ------------------------------------- | --------- |
| tier band           | `Прямоугольник 2` + clipped dots layer| 1289 × 89 |
| header BF logo      | `лого бф копия`                       | 158 × 85  |
| brand marks         | bolt + `GLH` + `MK` smart objects     | 225 × 46  |
| legend badges       | `навигация` → `1 блок` icons          | 25 × 25   |
| legend dots         | `2 блок` icons                        | 18 × 18   |
| legend trend icons  | `3 блок` icons, incl. `new`           | ~28–33    |
| footer avatars ×5   | `аватары` group, circular             | 46 × 46   |

The header marks in the PSD are **bolt + GLH + MK**. The current
`brand-logos.png` uses a flame instead of the bolt, so it is replaced.

The tier band is baked as an image rather than rebuilt in CSS: its look is a
white rounded rectangle with a clipped halftone-dot texture and a global colour
grade on top, which CSS gradients only approximate. It stretches with
`background-size: 100% 100%`, same mechanism as today's `dots-band.png`.

## 3. Stage grid and the tier block

| | PSD | current |
| --- | --- | --- |
| content width | **84.63cqw**, centred (7.68cqw gutters) | 94.8cqw (2.6cqw padding) |
| backdrop plate | rounded 1.64cqw, white vertical alpha gradient | none |
| tier band | h **5.84cqw**, radius **1.71cqw** | h 4.6cqw, pill |
| gap between tiers | **3.85cqw** | 1.2cqw |

The plate (`Прямоугольник 1`, 133→1424 × 336→1852) is a white fill with a
vertical alpha ramp, at 30% layer opacity. Effective values:

```
linear-gradient(180deg,
  rgba(255,255,255,.085) 25%,
  rgba(255,255,255,.063) 50%,
  rgba(255,255,255,.030) 75%)
```

(alpha sampled off the isolated layer at 25/50/75% of its height — 72, 53 and
25 of 255 — multiplied by the 77/255 layer opacity)

It sits behind the whole tier stack, not per tier, and is nearly flush with the
band width (2px inset in the PSD) — it reads as a soft veil, not a card.

Consequence to accept: narrowing content from 94.8 to 84.63cqw drops roughly one
item per row. This is what the poster specifies.

Tier structure is unchanged — header band plus dark items panel, as today.
`.tier-items` is restyled from its opaque dark navy to the poster's translucent
treatment so it reads as part of the plate rather than a separate card.

## 4. Header

| element | value |
| --- | --- |
| BF logo | left 1.51cqw, top 1.64cqw, w 10.37cqw |
| brand marks | right 1.38cqw, top 1.12cqw, w 14.77cqw |
| date | Proto Sans, cap-height 2.10cqw, top 5.25cqw |
| title line 1 | Proto Sans, cap-height 4.92cqw, top 8.54cqw |
| title line 2 | cap-height 4.73cqw, line pitch 4.92cqw (line-height ≈ .92) |
| title → plate | 3.87cqw |

Title and date switch from `--display` to `--proto`. Font sizes are set to hit
the cap heights above; Proto Sans cap height is ≈ 0.72 em, so the title lands
around 6.8cqw against today's 8.6cqw — the poster's title is proportionally
smaller than the current one.

The header keeps its `1fr auto 1fr` grid, so the title centres between the two
logos rather than on the canvas — which is what the PSD does (title centre sits
at 54.2% of canvas width, not 50%).

## 5. Legend

Panel: full content width, height 12.41cqw, radius 1.18cqw, `rgba(255,255,255,.30)`,
0.85cqw below the tier plate.

Restructured from five equal columns into the poster's three blocks:

- **Block 1** — two sub-columns. `F / S / M` at x 1.31cqw from the panel edge,
  `P / GP / CR` at 12.08cqw. Badges 1.64cqw, row pitch 2.33cqw.
- **Block 2** — starts at 36.83cqw. `хорошо / средне` and `ниже среднего /
  плохо`. Dots 1.18cqw, row pitch 2.36cqw.
- **Block 3** — starts at 69.53cqw, ends 2.56cqw short of the panel's right
  edge. `под вопросом / рост / перерасмотр / упадок / новый`. Row pitch 2.20cqw
  (uneven in the PSD: 34/35/38/27px). **`новый` does not exist in the current
  legend** and is added, with the `new` badge already used on item cells.

Title `ПОМОЩЬ ДЛЯ НОВЕНЬКИХ` — Bootshaus, cap-height 1.97cqw, centred over
block 2 (not over the panel).

The new string needs a `legend.new` key in both `ru` and `en` in `i18n.js`.
`tests/i18n_test.mjs` enforces RU/EN parity and no orphaned keys.

## 6. Footer panel and avatars

Panel: height 10.77cqw, radius 1.25cqw, `rgba(255,255,255,.30)`, 1.12cqw below
the legend panel.

Three columns × two rows. Column origins at 3.81 / 25.48 / 48.39cqw from the
panel edge, row pitch 4.14cqw. Each entry is a **circular 3.02cqw avatar** with
the title in cyan `#00F7FF` and the URL below it in white.

Avatars are new. Data model:

- `state.footer[i].icon` — optional string, same shape as `item.icon`: either a
  path under `images/` returned by `api/upload.php`, or empty.
- `renderFooter` renders `<img class="fl-avatar">` when `icon` is set and a
  neutral placeholder circle when it is not, so a link added later is not broken.
- In edit mode each link gets an upload control next to the existing 🔗 and ✕
  tools, reusing the item-icon path: shrink to WebP client-side, POST to
  `api/upload.php` (which caps the long side at 256px and preserves alpha), store
  the returned `/images/<hash>` path.

`validate.php` needs no change — it only checks that `tiers` is an array and
that the serialized state fits in 512 KB; unknown keys pass through untouched.

`walk_state_images` in `api/lib/images.php` **does** need one. It currently
visits exactly three image sites — tier logos, item icons, `ad.image` — and
`state.footer[].icon` becomes a fourth. `state.donate.qr` is added at the same
time: the client already uploads it in `compactState`, but the server-side walk
never knew about it, so it carried the same latent bug. Two things break without
the entry:

- `extract_embedded_images` never pulls a `data:` URL out of a footer icon, so
  an avatar pasted rather than uploaded stays inline and eats the 512 KB budget;
- `downscale_stored_images` builds its orphan list from the same walk, so every
  avatar file would be reported as unreferenced and deleted by a cleanup run.

The five PSD avatars ship as defaults in `assets/poster/`. `renderFooter` falls
back to a table keyed by the link's address — stripped of scheme, `www.` and any
trailing slash — when `icon` is unset. The key is the address rather than the
title because the title is edited in place on the page.

The poster's footer is out of date against production: it lists
`discord.gg/a4zz6bsxcm` and `t.me/mksvtnc`, while the live panel carries
`discord.gg/lycoris`, `t.me/themaknemy`, `t.me/bfsnews`, a YouTube channel and a
trade chat. The table holds both old and current addresses, which covers three
of the six live links. The other three render an empty circle until someone
uploads an avatar — the same placeholder any newly added link gets.

## 7. Unchanged bottom row

The credits row (`.credits` — Автор / Дизайнер / Аналитик / …) stays exactly as
it is, below the footer panel. The poster has no equivalent because the credits
lived inside the "антивор" cards being dropped.

## 8. Mobile

The `@media` block at the end of `styles.css` scales type up on narrow screens
(`.tier-band` 7.2cqw, `.lg` 2.7cqw and so on). Every value changed above needs
its mobile counterpart re-checked, particularly:

- the legend's three-block layout, which is wider than the five-column one and
  will need to stack on phones;
- the footer's three columns, likely two on phones;
- the 84.63cqw content width, which on a 375px phone leaves gutters too wide to
  be worth keeping — the gutters collapse below the existing breakpoint.

## 9. PNG export

`html2canvas` renders the stage as-is, so the export follows the redesign for
free. Two things to verify rather than assume:

- `app.js` swaps in a plain-URL background for export because `image-set()` is
  not resolved by html2canvas. That swap now points at
  `assets/poster/bg-export.jpg`.
- Avatars are same-origin under `images/`, so they do not taint the canvas.

## Testing

- `tests/i18n_test.mjs` — RU/EN parity after adding `legend.new`.
- `tests/images_test.php` — `walk_state_images` reaches `footer[].icon`; a
  `data:` URL there is extracted to a file; a referenced avatar is not reported
  as an orphan. Run PHP with `-d extension=gd`, otherwise this suite fakes
  failures.
- `tests/save_test.php` — round-trip of a footer entry carrying an icon.
- Visual: local server, compare against the PSD composite render at desktop
  width, then 375×812 and 768×1024.
- PNG export produces a file with the new background and no missing images.

## Risks

- **Rejected again.** The previous redesign was reverted wholesale. Ship on a
  branch as a single PR so a revert is one `git revert -m 1`.
- **Deployment is additive.** The webhook deploy copies over the tree without
  deleting; removed assets keep answering 200. Do not treat a file's continued
  presence as proof a rollback failed.
- **Narrower content.** 84.63cqw is a real reduction in items per row. Flagged
  above; owner has accepted the poster proportions.
- **`.tier-items` translucency.** Item icons currently sit on opaque dark navy.
  Over a translucent panel, low-contrast icons may read worse. Check against
  real production data, not the 3-tier sample.

## Out of scope

- The "антивор" author cards and their comments.
- Any change to tier/item data or the editor's behaviour.
- Replacing the credits row.

The ad banner keeps its place in the middle of the tier list and its behaviour;
only its fill is retuned to the panel tone so it reads as part of the same
plate. The poster has no ad block to copy, so this is a judgement call, not a
measurement.
