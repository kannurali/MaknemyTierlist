# Live link previews for the tier list and news

Sharing `/` or `/news` in Telegram, Discord or VK showed a static banner —
`assets/og-image.jpg`, one fixed title, one fixed description — no matter what
the tier list or the news feed actually said at that moment. This work makes
the preview card echo the live data: the current top tier and its items on the
tier list, the current freshest post on the news feed.

## Why this cannot be done in JS

The site is a client-rendered SPA: `index.html`/`news.html` shipped an empty
shell and `js/app.js`/`js/news-page.js` fetched the live state and painted it
in the browser. That is fine for a human with a browser, but a crawler
building a link-preview card — Telegram's, Discord's, VK's, Slack's — fetches
the HTML once, reads a fixed set of `<meta property="og:*">` tags out of it,
and never executes a line of JavaScript. Whatever those tags say at the
moment of the HTTP response is the card the user's friend sees; nothing the
client would later render on top of that shell exists as far as the crawler
is concerned.

So a preview that reflects live data has exactly one place it can be
computed: server-side, before the first byte of `<head>` goes out.

## Why the entry points became PHP

`index.html` and `news.html` were static files with no server-side step at
all — Apache served them byte-for-byte. To stamp live `og:*` values into
`<head>` before the response leaves the server, something has to run on the
server for every request to `/` and `/news`, which a static file categorically
cannot do.

The alternative to renaming would have been an Apache-level trick — an SSI
directive or a rewrite to a PHP wrapper that `include`s the static file
unchanged. Both exist only to avoid the file extension changing, and both add
a layer of indirection for no behavioural gain: the project already has a PHP
runtime and API endpoints, and every other dynamic page these two mirror
(`api/tierlist.php`, `api/news.php`) is already a plain `.php` file with a
`require_once __DIR__ . '/api/_bootstrap.php'` at the top. Renaming
`index.html` → `index.php` and `news.html` → `news.php` and prepending the
`og:*` computation is the same pattern the rest of the codebase already uses,
not a new one. The body of each file is byte-for-byte what the `.html` file
contained (see git history) — only `<head>` changed, and only the `og:*` /
`twitter:*` lines inside it.

`.htaccess` gained the routing this rename requires: `DirectoryIndex
index.php` (the file `index.html` no longer exists, so the shared-hosting
default can't be assumed), a redirect from the old literal `/news.html` to
`/news`, and `news/?$` now rewrites to `news.php` instead of `news.html`. The
`<FilesMatch "\.html$">` cache-control block has nothing left to match — both
pages are `.php` now — but a pattern change to catch `.php` by filename would
also catch `public_html/api/news.php` (the feed endpoint, unrelated file,
same basename) and silently override its own deliberate
`Cache-Control: no-cache` with a different value. So the `\.html$` rule is
left as a harmless no-op, and each PHP entry point sets
`Cache-Control: no-cache, must-revalidate` on itself instead — the same thing
Safari-cache-bug protection the old rule gave `.html`, just moved to the file
that now needs it.

Both pages compute their `og:*` block defensively: `tierlist_og_data()` /
`news_og_data()` wrap the DB read in `try`/`catch` and fall back to the
original static banner and copy on any failure (empty tier list, malformed
JSON, DB unreachable). The page must always render — a broken preview is a
cosmetic problem, a 500 on the tier list or the news feed is not.

## The preview image itself

`assets/og-image.jpg` was one fixed 1920×1080 JPEG. It is replaced, when live
data exists, by a PHP+GD-rendered 1200×630 PNG per endpoint:
`api/og-tierlist.php` draws the current top tier and its items over the
site's own poster background; `api/og-news.php` draws the freshest post's
category, date, title and (if it has one) its own image. 1200×630 rather than
1920×1080 because that is the size Telegram/Discord/Twitter actually render a
link-preview image at — the old banner was oversized for its one job.

Both share `api/lib/og_render.php` (GD primitives: cover-fit crop, the scrim
gradient, `imagettfbbox`-measured text layout and truncation, the pill badge)
and `api/lib/og.php` (pure functions with no PDO and no GD: version parsing,
cache-path building, pulling a renderable summary out of the live tier list /
news row, and the shared `og:title`/`og:description` text). The split matters
for testing: `api/lib/og.php` is plain data transforms and is covered by
`tests/og_test.php`; the GD half touches real fonts, real bitmaps and a real
filesystem and is verified by rendering and looking at the PNG, not by a unit
test asserting on pixel output.

### Why the version lives in the image URL, not a query-string cache-buster alone

`og:image` points at `/api/og-tierlist.php?v=<rev>` (tier list) or
`/api/og-news.php?v=<id><published_at>` (news) — the version is a GET
parameter, but it is also the entire cache key: `handle_og_tierlist()` /
`handle_og_news()` build the on-disk path as
`images/og/<prefix>-<version>.png` and check `is_file()` on that exact name
before touching the database or GD at all. Two things follow from that:

- **A given version generates once, ever.** Telegram, Discord and every other
  crawler that fetches the same `?v=` after the first request gets
  `readfile()` of an already-rendered file — no GD, no DB query, no
  regeneration cost, no matter how many times the same tier list gets shared.
- **A new version is a new URL, so it needs no cache invalidation at all.**
  When the tier list changes, `rev` changes, `?v=` changes, and the resulting
  path is a file that has never existed — there is nothing to invalidate.
  `Cache-Control: public, max-age=31536000, immutable` on the response is safe
  to set precisely because the URL is the version: it can never point at
  stale content, because stale content would be a different URL.

This is the same reason `api/tierlist.php` already serves `?rev=N` with an
immutable cache header (see `2026-07-25-php-mysql-migration-design.md`) — the
og-image endpoints reuse that idea rather than inventing a second scheme.
News has no `rev` of its own (deliberately — see
`2026-08-16-news-page-design.md`, "Why news does not live in the tier list
blob"), so its version is `id` and `published_at` glued into one integer:
`og_news_summary()` in `api/lib/og.php` builds it as
`og_parse_version($id . $publishedAt)`. Either half changing — a different
post becomes the newest, or the same post gets re-dated — produces a
different version and therefore a different cache file, without giving news a
version handle that would recreate the coupling the separate table exists to
avoid.

`og_parse_version()` accepts only a string of pure digits (or a non-negative
int); anything else, including a value that merely starts with digits
(`"1abc"`), returns `null`. The version is concatenated straight into a
filename on disk (`og_cache_filename()`), so this is the one validation gate
between an attacker-controlled query string and a path under
`images/og/` — `(int)"1abc"` silently becoming `1abc` truncated to `1abc`'s
leading digits would create a cache file under an attacker-chosen name; a
strict all-digits check does not have that failure mode. A request whose `v`
fails validation, or whose `v` does not match the *current* live version (an
old rev nobody ever requested and generated before), gets a `302` to the
static banner rather than an attempt to render — see `handle_og_tierlist()` /
`handle_og_news()` in the respective endpoint files.

## Tier list image layout

The top tier's items are drawn in a single column rather than two. Two
~500 px columns cannot hold real item names — `imagettfbbox` measurements
against actual data (`Eclipse Dragon (CHROMATIC)`, `Permanent Kitsune`,
`Galaxy (Empyrean Kitsune)`) show a name-plus-value pair regularly needs
700-800 px at a legible size, while a single column spanning the full
1088 px content width holds the same names with no truncation at all. Fewer,
fully readable rows beat more rows that overlap or run off the canvas.

Layout is measured, not assumed: for each row, the value is measured first
with `imagettfbbox` and drawn right-aligned to the column's right edge (values
are the point of the image and must stay intact and forming a clean edge);
the name gets whatever width remains — column width minus the value's actual
pixel width minus a fixed gap — and is truncated with `og_truncate_to_width()`
(binary search over character count, re-measuring the candidate plus an
ellipsis after every cut, since a character-count truncation is wrong for a
proportional font) if it doesn't fit that budget. The one defensive fallback —
truncating the value itself — only fires if a value alone is wider than the
whole column, which does not happen on real data but must not be allowed to
cross the canvas edge either.

## Scrim over the post's own photo

`api/og-news.php` draws the post's own image full-bleed when it has one
(falling back to the site's poster background otherwise), with white text on
top. A photo that happens to be light would make that text illegible, so
`og_apply_scrim()` in `api/lib/og_render.php` darkens it first — the same
top-to-bottom gradient `.stage::before` already applies to the poster
background in `css/base.css`
(`rgba(6,8,18,.45) 0% → rgba(6,8,18,.12) 30% → rgba(6,8,18,.35) 100%`), so the
preview image is not a second, invented look. It is drawn as horizontal bands
of `imagefilledrectangle` with per-band alpha interpolated between the same
three stops, which is cheap enough at 1200×630 and smooth enough in the final
PNG. A flat wash was rejected on purpose: it would read as a dark rectangle
rather than a photo, and the whole point of drawing the post's own image is
that it stays recognisable as itself.
