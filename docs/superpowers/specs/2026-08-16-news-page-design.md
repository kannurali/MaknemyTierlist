# News page

A news feed at `/news`, written and edited by the admin from the site itself,
covering three kinds of post: changes to the tier list, Blox Fruits game
updates, and project announcements.

The feed is one page. Individual posts get no address of their own, so there is
no server-side rendering, no slugs and no per-post SEO — the page is fetched and
drawn by JS exactly like the tier list is. This was a deliberate call by the
owner: per-post URLs are worth real search traffic on queries like "blox fruits
update", but they would force a PHP-rendered page and a routing layer that the
site does not otherwise need.

## Why news does not live in the tier list blob

The obvious cheap option is an array on the existing state blob — `state.news`
next to `state.tiers` — published by the same button. It was rejected for two
reasons, in order of weight.

**Publishing would be coupled to the tier list cache.** `save.php` stamps a new
`rev` on every write, and `tierlist.php` serves `?rev=N` with
`Cache-Control: public, max-age=31536000, immutable`. A typo fixed in a news
post would therefore bump `rev` and make every visitor re-download the entire
tier list. News is edited more often than prices change, so this is not a corner
case — it is the normal path.

**The 512 KB cap is shared.** `validate_state()` caps the serialized blob. The
tier list is bounded in size; a news feed is not. Long patch notes would
eventually exhaust the cap and take both features down at once.

A separate table also means one post can be rewritten without serializing the
whole site state, so two edits in quick succession cannot clobber each other.

## Data

```sql
CREATE TABLE IF NOT EXISTS news (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category     VARCHAR(16) NOT NULL,
  title_ru     VARCHAR(200) NOT NULL,
  title_en     VARCHAR(200) NOT NULL DEFAULT '',
  body_ru      TEXT NOT NULL,
  body_en      TEXT NOT NULL DEFAULT '',
  image_url    VARCHAR(255) NOT NULL DEFAULT '',
  published_at BIGINT UNSIGNED NOT NULL,
  KEY idx_feed (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`category` is a plain string, not an `ENUM`, because the test harness rebuilds
the schema in SQLite (`tests/lib.php`, `test_db()`) and `like.php` already
establishes that SQL here must run on both engines. The allowed values —
`tierlist`, `game`, `project` — are enforced in PHP against a single list, and
the same list is mirrored in `js/news.js` so the two cannot drift apart in what
they accept.

`published_at` holds milliseconds since the epoch, matching how `rev` is
generated (`(int) round(microtime(true) * 1000)`). Storing a number rather than
a `DATETIME` keeps the column portable to SQLite and removes the server's time
zone from the picture entirely: the browser formats the value for the reader.

The server stamps `published_at` when a post is inserted. Unlike `rev`, it is
not server-only afterwards: the editor's date field writes it, so a post can be
corrected or backdated. It is content, not a version handle, and nothing depends
on it being monotonic. `title_ru` is capped at 200 characters by the column;
`body_ru` and `body_en` are capped at 20 000 characters in PHP, which is roughly
ten screens of patch notes and far more than any real post.

English is optional per post. `title_en` and `body_en` default to empty, and the
renderer falls back to Russian when they are — the rule `content.js` already
applies to item descriptions, for the same reason stated there: a half-filled
post should show text in the wrong language rather than nothing at all.

There are no drafts, pinning, slugs, authors or tags. A single-admin feed with
no per-post addresses has no use for any of them.

## API

Three endpoints, each a pure `handle_*()` plus a dispatch tail guarded by
`if (!defined('TESTING'))`, matching every existing endpoint.

| File | Method | Access | Behaviour |
| --- | --- | --- | --- |
| `api/news.php` | GET | public | Up to 50 posts, newest first. `Cache-Control: no-cache`. |
| `api/news_save.php` | POST | admin | Insert when `id` is absent, update when present. |
| `api/news_delete.php` | POST | admin | Delete by `id`. |

No `rev` and no immutable caching: the feed has no version handle, and inventing
one would recreate the coupling this design exists to avoid.

`rate_limit_allow('news', $ip, 120, 3600, time())` guards the public GET — a
reader who opens the feed and switches categories a few times costs one request,
so 120 an hour is far above real use while still capping a scraper.

The 50-post ceiling is a hard limit, not a page size: there is no pagination, so
the 51st post pushes the oldest out of the feed permanently. That is acceptable
at the expected rate of a few posts a month and is the explicit follow-up when
the feed approaches the limit. `api/news.php` is written so adding an `?offset=`
parameter later does not disturb its callers.

The editor is shown by asking `api/session.php`, the same endpoint the tier list
uses. The admin session cookie is set with `path => '/'` in `start_admin_session()`,
so logging in on the main page already authenticates the news page.

### Images

Posts reuse `api/upload.php` rather than gaining an upload path of their own: it
already sniffs the format, names files by sha1 so identical uploads deduplicate,
and writes into `images/`, where `.htaccess` forbids execution and sets an
immutable cache header.

One change is needed. `downscale_image_bytes()` already accepts
`$maxSide = ICON_MAX_SIDE`, but `save_image_bytes()` calls it with no argument,
so every upload is capped at 256 px. That figure was measured for item icons,
which paint at most 133 device px; a news image spans the card and would be
visibly soft. `save_image_bytes()` gains a `$maxSide` parameter defaulting to
`ICON_MAX_SIDE` and forwards it, and `upload.php` passes `NEWS_IMAGE_MAX_SIDE`
(1280) when the caller sends `kind=news`. With the parameter omitted the
behaviour is byte-for-byte what it is today, so the icon path and the Safari
memory budget it was chosen for are untouched.

`save_image_bytes()` returns `/images/<sha1>.<ext>`. `news_save.php` accepts
`image_url` only when it matches `^/images/[0-9a-f]{40}\.(png|jpg|webp)$` — that
is, only a path our own uploader produced. Foreign hosts and `javascript:` URLs
cannot be stored by construction, so nothing downstream has to defend against
them.

## Page and rendering

`news.html` is a static shell like `index.html`: toolbar, stage header, category
chips, empty `<main id="feed">`. Content arrives from `api/news.php`.

The JS splits along the line the repo already draws — anything testable without
a browser lives in its own DOM-free file, as `i18n.js`, `tiers.js` and
`content.js` do:

- **`js/news.js`** — `CATEGORIES` (keys, labels, CSS classes),
  `pickLang(post, lang)`, `formatDate(ms)`, and `toParagraphs(text)`, which
  splits on blank lines and normalises `\r\n`. `formatDate` renders `DD.MM.YYYY`
  in both languages, matching the date already printed on the poster; it does
  not use `toLocaleDateString`, whose output varies by the reader's locale and
  would not match the design.
- **`js/news-page.js`** — fetching, rendering, filtering, and the editor.

**Post text is written with `textContent`, never `innerHTML`.**
`toParagraphs()` returns an array of strings; the renderer creates a `<p>` per
entry and assigns `textContent`. No HTML sanitiser is involved, so there is
nothing to get wrong or to keep patched. This is also why the post body carries
no inline formatting: bold, lists and links would mean parsing markup into
`innerHTML`, which trades a guarantee for a whitelist.

Designed states: loading, empty category, and network failure (message plus a
retry button). The category filter is client-side and is not reflected in the
URL — posts have no addresses, so there is no view worth linking to.

Interface strings go into `js/i18n.js` with `data-i18n` attributes on the
markup, as everywhere else. Post text is content, not interface, and is not
translated by the dictionary — it carries its own optional English fields.

`/news` resolves through a rewrite in `.htaccess` to `news.html`. The toolbar
gains a Tier list / News segmented pair, placed outside `#stage` so it stays out
of the exported PNG.

### Styling

Fonts, `:root` tokens and the `html, body` reset move out of `styles.css` into a
new `css/base.css`, which both pages load first. The alternative — having the
news page pull in all 1359 lines of tier-list-specific CSS, or duplicate the
palette — either wastes bytes on selectors the page never uses or guarantees the
two copies drift.

The stage header keeps the poster's `cqw` sizing so it scales as one piece with
the tier list. Post text is sized in `px`/`rem` instead: it is prose meant to be
read, and it must not shrink with the container on a narrow screen.

Category colours reuse existing tokens: `--cyan` for tier list, `--t-p` for game
updates, `--mk` for project announcements.

## Editor

A modal built like the item modal (`#modal`): Russian and English title,
Russian and English body, category as a segmented control in the style of
`#mType2`, date, and an image with preview and upload button mirroring
`#mIconPreview`.

It departs from the tier list in one respect. The tier list accumulates edits
and publishes them in a batch behind the dirty/clean `✓ Сохранено` button,
because editing it means dozens of small actions in a row. A post is written in
one sitting inside the modal, so **Publish sends its POST immediately** and the
feed needs no publish step of its own.

A failed save leaves the modal open and shows the error. Losing typed article
text to a dropped connection is not an acceptable outcome. Deletion asks for
confirmation.

## Tests

`tests/news_test.php` — category outside the allowed list is rejected; empty
`title_ru` or `body_ru` is rejected; over-length title and body are rejected;
the feed returns at most 50 rows, newest first; a payload with an `id` updates
instead of inserting; deleting a missing id is handled; an `image_url` that is
not one of our own paths is rejected.

`tests/news_test.mjs` — language fallback in both directions (including a post
with only English filled in), `formatDate` zero-padding single-digit days and
months, and `toParagraphs()` against `\r\n`, runs of blank lines, and empty
input.

`tests/lib.php` gains the `news` table in `test_db()`, in the same SQLite
dialect as the existing two. `bash tests/run_all.sh` must pass in full.

## Files

New: `public_html/news.html`, `public_html/css/base.css`,
`public_html/css/news.css`, `public_html/js/news.js`,
`public_html/js/news-page.js`, `public_html/api/news.php`,
`public_html/api/news_save.php`, `public_html/api/news_delete.php`,
`tests/news_test.php`, `tests/news_test.mjs`.

Changed: `schema.sql`, `tests/lib.php`, `public_html/.htaccess`,
`public_html/index.html`, `public_html/css/styles.css`,
`public_html/js/i18n.js`, `public_html/api/upload.php`,
`public_html/api/lib/images.php`, `public_html/sitemap.xml`, `README.md`.

`public_html/news-mockup.html` is a throwaway visual mockup used to agree the
look. It is deleted as part of this work and never deployed.
