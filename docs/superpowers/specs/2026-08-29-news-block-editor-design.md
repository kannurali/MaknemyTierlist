# Telegram-style block editor for the news feed

A news post today is a title, one flat body of text and one image nailed to
the top of the card. `NEWS.toParagraphs()` in `js/news.js` splits the body on
blank lines, `cardFor()` in `js/news-page.js` prints each paragraph through
`textContent`, and that is the whole vocabulary: no bold, no links, no
quotes, no second picture. The posts being written for this feed are Telegram
channel posts — bold fruit names, pull quotes, spoilers, several screenshots
each with its own caption — and they arrive flattened.

This work replaces the flat body with an ordered list of blocks, and replaces
the single-image form on `/admin/news` with a block editor that behaves like
the Telegram composer: type, select, format; insert a picture where the text
calls for it.

## What stays exactly as it is

The reader-facing security posture. Nothing in this design introduces
`innerHTML`. Blocks are rendered with `createElement` and `textContent`, the
same way paragraphs are rendered today, so a post body cannot become script
no matter what reaches the database.

The image pipeline. `api/upload.php`, `save_image_bytes()`, the crop editor
and the `newsImageCap()` ceiling shared between `js/news.js` and
`api/lib/images.php` are untouched. A block-level image is the same
`/images/<sha1>.<ext>` file with the same per-width cap; there are simply
several of them per post instead of one.

Link previews and the no-JS fallback. See "Derived columns" below — they keep
working without a line of change.

## Content model

A new column holds the structured body:

```sql
ALTER TABLE news ADD COLUMN body_json LONGTEXT NULL;
```

The value is a JSON document:

```json
{"v": 1, "blocks": [ ... ]}
```

`v` is a format version. It exists so that a future change of shape can be
detected rather than guessed at; a document whose `v` is not 1 is rejected by
the server rather than parsed optimistically.

### Spans

Formatted text is an array of spans. A span is a string plus optional flags:

```json
{"s": "Magnet", "b": true}
{"s": "the bulletin", "href": "https://example.com/x"}
```

Flags: `b` bold, `i` italic, `u` underline, `st` strikethrough, `c`
monospace, `sp` spoiler, and `href` for a link. They compose — a bold link is
one span with both. The flag set is deliberately Telegram's, minus what this
feed has no use for.

`href` accepts `http` and `https` only. Any other scheme (`javascript:`,
`data:`, a bare relative path) is a validation error, not a silently dropped
attribute — an editor that produced it is broken and should say so.

### Blocks

The block list is flat. Nothing nests: a quote cannot contain an image, a
list item cannot contain a list. This is a deliberate restriction — it makes
the validator a loop instead of a recursion, and no post being written for
this feed needs the depth.

| `t` | fields |
| --- | --- |
| `p` | `ru`, `en` — span arrays |
| `quote` | `ru`, `en`, `collapsible` (bool) |
| `list` | `ordered` (bool), `items[]` — each with `ru`, `en` |
| `code` | `ru`, `en` — plain strings, no spans |
| `image` | `url`, `w`, `h`, `pct`, `align`, `wrap`, `cap_ru`, `cap_en` |
| `album` | `items[]` (`url`, `w`, `h`), `cap_ru`, `cap_en` |

`code` carries plain strings on purpose: formatting inside a monospace block
means nothing and would only be one more thing to validate.

### Bilingual structure

One structure, two texts per block. Block order, images, widths and alignment
are shared between the languages; only the text and the captions have `ru`
and `en` variants. The editor has an RU/EN switch that swaps the text being
edited and leaves the layout alone.

The alternative — two independent documents — was rejected: it would mean
building the same post twice and would let the two languages drift into
different pictures in different places, for a flexibility nobody asked for.

### Limits

| what | ceiling | why |
| --- | --- | --- |
| blocks per post | 200 | a long post is ~40 blocks |
| images per album | 10 | Telegram's own album limit |
| `body_json` | 64 KB | roughly 3x the largest realistic post |
| derived plain text | `NEWS_BODY_MAX` (20000) | unchanged, still enforced |

Exceeding a ceiling is a `400`, consistent with how `image_pct` out of range
is handled today: values are rejected, never clamped, so the client cannot
quietly ship something the server did not agree to.

## Derived columns

`body_ru`, `body_en`, `image_url`, `image_width` and `image_height` are not
deleted. On save the **server** derives them from the blocks and writes them:

- `body_ru` / `body_en` — the concatenated plain text of every text block
- `image_url` / `image_width` / `image_height` — the post's first image

The client does not send these fields, and any value it does send is ignored.
Derivation on the server is what keeps the two representations from
disagreeing about what a post says.

`image_pct`, `image_align` and `image_wrap` are not derived. For a block post
they keep their column defaults and mean nothing: geometry now lives on each
image block, and the one consumer of the legacy image columns — the
link-preview renderer in `api/lib/og.php` — reads only `image_url`.

This is what makes the rest of the codebase indifferent to the change:
`api/lib/og.php` (which draws the link-preview image from `body_ru`), the
server-rendered `<meta>` tags and `<noscript>` body in `news.php:33` and
`news.php:78`, and anything else reading the legacy columns keep working
untouched.

## Rendering

`cardFor()` replaces its `toParagraphs()` loop with `renderBlocks()`. A post
with `body_json` renders as blocks; a post without one renders through the
existing paragraph path. Every post in the database today takes the second
path and is unaffected.

- Link is an `<a rel="noopener noreferrer nofollow" target="_blank">`, scheme
  re-checked on the client as well as the server.
- Spoiler is a `<span class="nw-spoiler">`, revealed on click.
- Album is a CSS grid, with layouts for 2, 3 and 4-or-more pictures.
- Image keeps the existing `nw-image` / `nw-img-float-*` classes and the same
  inline percentage width, now read from the block's own `pct` / `align` /
  `wrap` fields instead of the row's `image_pct` / `image_align` /
  `image_wrap` columns.

## Editor

Each block is its own `contenteditable`. Inline formatting is applied to the
selection: bold/italic/underline/strikethrough through `execCommand`,
spoiler/link/code through a wrapper this code owns. On save, each block's DOM
is serialised back into spans through a **tag whitelist** — anything not on
the list collapses to its text. Paste is always coerced to plain text, so a
copied Telegram post cannot smuggle markup in.

Keyboard behaviour follows Telegram and Notion, because those are the two
editors whose muscle memory the author already has:

- `Enter` starts a new paragraph block; `Backspace` at the start of an empty
  block merges it into the previous one
- `/` in an empty block opens the block type menu
- `Ctrl+B` / `Ctrl+I` / `Ctrl+U` / `Ctrl+K`
- inline markdown as you type: `**bold**`, `||spoiler||`, backtick-code

Blocks are reordered by dragging the handle on the left. The crop editor is
the existing one, invoked per image block. The live preview pane calls the
same `renderBlocks()` the feed calls, so the preview cannot drift from the
published result.

## File split

`js/news-page.js` is 1518 lines and is loaded by both `news.php` and
`/admin/news`, which means every reader downloads the editor and its crop
canvas for nothing. A block editor would roughly double that dead weight, so
the editor moves out:

| file | contents | loaded by |
| --- | --- | --- |
| `js/news-blocks.js` | `normalize`, `validate`, `toPlainText`, `spansToFragment(doc, spans)`, `renderBlocks(doc, blocks, lang)` | everyone |
| `js/news-page.js` | feed, filters, likes, copy-link | everyone |
| `js/news-editor.js` | editor, crop, uploads | `/admin/news` only |

`js/news-blocks.js` takes `document` as an argument rather than reaching for
the global, so it is requirable from node in tests — the same discipline
`js/news.js`, `js/tiers.js` and `js/content.js` already follow.

## Server validation

A new `api/lib/news_blocks.php` is the single source of truth on the server:

- `news_blocks_validate($doc)` — whitelist of block types, of each type's
  fields and of each span flag. An unknown key is a `400`, not a silent drop.
- `news_blocks_plain($blocks, $lang)` — the derived plain text.
- `news_blocks_first_image($blocks)` — `[url, w, h]` for the derived columns.

Image URLs are checked against the existing `NEWS_IMAGE_RE`
(`/images/<sha1>.<ext>`), so a block cannot point the feed at a foreign host
or at a path outside the upload directory.

## The migration window

Code reaches production by push; SQL is run by hand. `api/news.php` already
copes with one version of this gap — a missing `news` table is treated as an
empty feed. This change opens a worse one: `SELECT body_json` against a
not-yet-migrated database fails with `1054 unknown column` and takes the
whole feed down, on a page that is in `sitemap.xml`.

So `handle_news()` catches error `1054` specifically and re-runs the query
without `body_json`. During the window the feed serves every post through the
legacy path, which is exactly right: no post has blocks yet anyway.

Migration file: `docs/migrations/2026-08-29-news-blocks.sql`.

## Testing

- `tests/news_blocks_test.mjs` — normalisation, validation and plain-text
  derivation on the JS side
- `tests/news_test.php` — the whitelist, every ceiling, a bad `href`, a
  foreign-host image URL, and that the derived columns match the blocks
- `tests/news_page_test.php` — the server-rendered fallback for a block post
- `tests/og_test.php` — the link-preview image for a block post

Cache-bust versions are bumped for `js/news.js`, `js/news-page.js`,
`css/news-design.css` and the new files.

## Out of scope

Video and GIF blocks, polls, reactions beyond the existing heart, scheduled
publishing, nested blocks, and formatting inside `code`. None of them are
needed to publish the posts this feed publishes, and each would widen the
validator.
