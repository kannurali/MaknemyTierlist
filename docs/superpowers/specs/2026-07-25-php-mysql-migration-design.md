# Nexus Tier List — Migration to PHP/MySQL Shared Hosting

**Date:** 2026-07-25
**Status:** Approved design, pending spec review
**Author:** brainstormed with Claude

## Overview

Move the entire site off Firebase (Realtime Database + Auth) and Vercel
(serverless `/api/*` functions) onto a single LAMP shared host
(Apache + PHP + MySQL). One domain, one bill.

### Why

- Firebase Realtime DB free tier (Spark) blew its 10 GB/month **download**
  quota (142 GB used) because the full ~2.7 MB tierlist blob is re-served on
  every read. Shared hosting bills **traffic as "unlimited"** (no metered
  egress wall).
- Shared host includes a **free domain** (needed so the site indexes in search
  like a normal site).
- The recent refactor already replaced Firebase websockets with plain HTTP
  polling (`/api/state` frequent, `/api/tierlist` on `rev` change) — this maps
  1:1 onto PHP scripts, so the port is mechanical, not a rearchitect.

### Goals

1. Serve the static site + JSON data + like counter entirely from the shared host.
2. Remove all Firebase and Vercel dependencies.
3. Cut the tierlist payload from ~2.7 MB to ~46 KB by moving images out of the
   JSON and serving them as static files.
4. Keep the existing viewer UX and admin editing workflow intact.
5. Deliverable is a **turnkey working backend** tested locally in XAMPP, ready
   to upload to cPanel.

### Non-goals (YAGNI)

- Per-admin identity — a single shared admin password is enough for 3 admins.
- Realtime push — HTTP polling stays.
- CDN / edge caching — single origin is acceptable at this traffic.

## Current state (what exists today)

- `index.html` + `css/styles.css` + `js/app.js` (1680 lines) — static frontend.
- `js/firebase-config.js` — Firebase config + `ADMIN_UIDS` whitelist (3 admins).
- Vercel Node functions: `api/state.js`, `api/tierlist.js`, `api/like.js`.
- Data in Firebase Realtime DB: `/tierlist` (one JSON blob, ~2.7 MB — 70% item
  icons, 28% ad image, 2% structure) + `/likes` (integer) + `/tierlist/_rev`.
- Admin auth: Google OAuth via Firebase; write access gated by UID whitelist.
- Reads: client polls `/api/state` (tiny), fetches `/api/tierlist` only when
  `rev` changes. `publish()` writes the full state via `fbRef.set(state)`.

## Target architecture

```
public_html/
  index.html, css/, js/, assets/     # static, served by Apache
  images/<hash>.webp                 # item icons + ad image, static, immutable-cached
  api/
    _bootstrap.php                   # PDO connect, session bootstrap, helpers, require_admin()
    state.php       GET   -> {rev, likes}
    tierlist.php    GET   -> {tierlist, likes}      # ?rev=N -> immutable cache
    like.php        POST  -> atomic ±1 -> {ok, likes}
    save.php        POST  -> (admin) write tierlist, bump rev -> {ok, rev}
    upload.php      POST  -> (admin) save image -> {url}
    login.php       POST  -> {password} -> set session
    logout.php      POST  -> clear session
    session.php     GET   -> {admin: bool}
  .htaccess                          # force HTTPS, cache images/, deny config & sql
config.php                           # ABOVE webroot: DB creds + ADMIN_HASH
schema.sql                           # table definitions
```

Polling flow is unchanged from today; only the endpoint URLs change to `.php`
and the Firebase REST fallback is removed.

## Data model (MySQL)

Two singleton-row tables. No users table (shared password).

```sql
CREATE TABLE tierlist (
  id   TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  data LONGTEXT NOT NULL,          -- tierlist JSON; icon/logo/ad values are URLs, not base64
  rev  BIGINT UNSIGNED NOT NULL    -- version, unix milliseconds
);

CREATE TABLE likes (
  id    TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  count INT UNSIGNED NOT NULL DEFAULT 0
);

INSERT INTO tierlist (id, data, rev) VALUES (1, '{}', 0);
INSERT INTO likes (id, count) VALUES (1, 0);
```

Charset `utf8mb4`. Access via PDO with prepared statements only.

## Endpoint contracts

All responses are JSON. Admin endpoints return `401` when the session is not
authenticated. Same-origin only — no `Access-Control-Allow-Origin` header
(the wildcard from the Vercel era is dropped).

### `GET /api/state.php`
- Reads `rev` from `tierlist` and `count` from `likes` (two tiny SELECTs).
- `200 -> {rev: <int|null>, likes: <int>}`
- `Cache-Control: no-store` (always fresh; it is tiny).
- Optional later optimization: 5 s file cache to shield the DB under heavy
  polling. Not in v1.

### `GET /api/tierlist.php`
- Query: optional `?rev=<n>`.
- Reads `data` + `rev` from `tierlist`, `count` from `likes`.
- `200 -> {tierlist: <object>, likes: <int>}`
- Caching: if `?rev` present → `Cache-Control: public, max-age=31536000, immutable`
  (each rev is immutable; helps browser cache on repeat loads — no edge CDN here,
  but browser cache still avoids refetch). Without `rev` → `Cache-Control: no-cache`.

### `POST /api/like.php`
- Public (no auth), matching current open-like behavior.
- Body: `{dir: 1 | -1}` (default `1`; any other value rejected → treated as `1`).
- `UPDATE likes SET count = GREATEST(0, count + :dir)` then `SELECT count`.
- Atomic via the single UPDATE; negative clamp via `GREATEST`.
- `200 -> {ok: true, likes: <int>}`
- Client already enforces one-like-per-session in the UI; server stays open.

### `POST /api/save.php` (admin)
- Requires `$_SESSION['admin']`.
- Body: full tierlist state as JSON (`Content-Type: application/json`).
- Validation: `json_decode` succeeds; has `tiers` array; serialized size under
  512 KB (images are external now); **reject** if any `icon`/`logo`/`ad.image`
  is a `data:` URL larger than 2 KB — this enforces the "images are uploaded,
  not embedded" invariant with a clear error message.
- `rev` is generated **server-side**: `round(microtime(true) * 1000)` (client
  value is ignored — never trust the client for versioning).
- `UPDATE tierlist SET data = :json, rev = :rev`.
- `200 -> {ok: true, rev: <int>}`
- Errors: `401` (not admin), `400` (bad/oversized/embedded-image payload).

### `POST /api/upload.php` (admin)
- Requires `$_SESSION['admin']`.
- Accepts either a multipart file field `image` or a raw JSON body
  `{data: "data:image/webp;base64,..."}` (client already produces a shrunk WebP
  data URL — raw is the primary path).
- Validation: decodes to a real image (webp/png/jpeg), size under 500 KB.
- `hash = sha1(bytes)`; path `images/<hash>.<ext>`. Write only if absent
  (content-hash dedup — identical images never duplicate).
- `200 -> {url: "/images/<hash>.<ext>"}`
- Errors: `401`, `400` (not an image / too large).

### `POST /api/login.php`
- Body: `{password}`.
- `password_verify($password, ADMIN_HASH)` → on success set
  `$_SESSION['admin'] = true`, regenerate the session id, `200 -> {ok: true}`.
- On failure: increment a per-session/IP attempt counter, short `sleep`,
  `401 -> {ok: false}`.

### `POST /api/logout.php`
- `session_destroy()` → `200 -> {ok: true}`.

### `GET /api/session.php`
- `200 -> {admin: <bool>}`, `Cache-Control: no-store`.
- Called on page load so the client knows whether to show admin controls.

### `api/_bootstrap.php` (shared, not a route)
- Loads `config.php` from its path above the webroot.
- Opens a PDO connection (`utf8mb4`, `ERRMODE_EXCEPTION`).
- Starts the session with secure cookie params (`HttpOnly`, `Secure`,
  `SameSite=Lax`).
- Helpers: `json_out($data, $status = 200)`, `require_admin()`, `read_json_body()`.

## Client changes (`js/app.js`, `index.html`)

### Removals
- Firebase SDK `<script>` tags and `js/firebase-config.js` (delete the file).
- All `firebase.*` code: `initFirebase` internals, `auth`, `fbRef`,
  `onAuthStateChanged`, Google popup, `ADMIN_UIDS`.
- The Firebase REST fallback branches in `fetchState` / `fetchFull`.
- The `Access-Control-Allow-Origin` reliance (same-origin now).

### Auth (replaces Google OAuth)
- On load: `GET /api/session.php` → `setAdminMode(data.admin)`.
- `#btnLogin` → prompt for password → `POST /api/login.php` → on `ok`,
  `setAdminMode(true)` and refetch snapshot.
- `#btnLogout` → `POST /api/logout.php` → `setAdminMode(false)`.
- `index.html`: replace the Google login control with a password field/modal;
  bump `app.js?v=` cache-buster.

### Reads
- `fetchState()` → `GET /api/state.php`.
- `fetchFull(rev)` → `GET /api/tierlist.php?rev=<rev>` (keeps the rev-cache
  pattern already shipped).

### Writes
- `publish()` → `POST /api/save.php` with the full state; on `ok`, set
  `lastRev` from the response `rev`.

### Images
- Image pick handlers: keep the existing client-side WebP shrink
  (`shrinkDataURL` / `fileToSmallDataURL`), then instead of embedding base64,
  `POST` the blob to `upload.php` and store the returned URL in
  `item.icon` / `tier.logo` / `ad.image`.
- `compactState` / `isBigDataURL`: repurpose as a safety net — on save, any
  lingering large data URL is uploaded and swapped for a URL before `save.php`.
- Render path already uses the value as an `<img src>`, so URLs work directly.

## One-time data migration

Run locally once (Node script; the current 2.7 MB Firebase blob is already
available and re-fetchable):

1. Re-fetch current `/tierlist.json` and `/likes` from Firebase.
2. Walk `tiers[].logo`, `tiers[].items[].icon`, `ad.image`. For each `data:` URL:
   decode base64 → write `images/<sha1>.<ext>` → replace the value with
   `/images/<sha1>.<ext>`.
3. Write the cleaned `tierlist.json` (~46 KB).
4. Emit `import.sql` (or `import.php`): upsert the `tierlist` row with the
   cleaned JSON + a fresh `rev`, and set the `likes` row from the current count.

Deliverables: `images/` folder (~110 files), cleaned `tierlist.json`, import script.

## Security

- `config.php` lives **above** `public_html` (or `.htaccess` denies it) so DB
  creds and the admin hash never serve over HTTP.
- `.htaccess`: force HTTPS; `images/` gets `Cache-Control: immutable, 1 year`
  and PHP execution disabled (defense-in-depth against uploaded-file execution).
- Deny direct access to `*.sql` and any data files.
- PDO prepared statements everywhere — no SQL injection surface.
- Admin endpoints session-gated; session id regenerated on login.
- Upload validates MIME + size and stores under a content hash (no
  attacker-controlled filenames/paths).
- Cookies `HttpOnly` + `Secure` + `SameSite=Lax`.

## Local development

- XAMPP (Apache + PHP + MySQL) on Windows; project under `htdocs/`.
- Create a local DB via phpMyAdmin; run `schema.sql`.
- `config.php` with local creds + a locally-generated `ADMIN_HASH`.
- Exercise every endpoint before the host is purchased.

## Testing plan (local, manual)

- Site renders from the DB row; tierlist payload measured ~46 KB.
- Like button increments; verify the `likes` row in phpMyAdmin; negative clamp
  holds at 0.
- Login with the correct password → admin controls appear; wrong password →
  `401` + throttle.
- Edit + save → `tierlist` row updated, `rev` bumped; a second tab polling
  `state.php` picks up the new `rev` and refetches.
- Image upload → file lands in `images/`, URL stored in the item; re-uploading
  the same image reuses the hash (no duplicate).
- Non-admin `save.php` / `upload.php` → `401`.
- Oversized upload / embedded-image save → `400` with a clear message.
- Logout → admin controls disappear; `save.php` now `401`.

## Deploy steps (later, when the host is bought)

1. Create MySQL DB + user in cPanel; run `schema.sql`.
2. Upload `public_html/` contents; place `config.php` above the webroot with
   production creds + admin hash.
3. Upload the migrated `images/` folder; ensure it is writable (755).
4. Run the import (cleaned tierlist + likes count).
5. Point the free domain, enable SSL, smoke-test every endpoint.

## Risks

- "Unlimited traffic" on shared hosting has soft CPU/process/IO/inode fair-use
  caps — mitigated by serving images as static files (Apache, ~0 CPU) and
  keeping PHP+MySQL work to the tiny structure/counter.
- No edge CDN → higher latency for users far from the datacenter; acceptable at
  this traffic.
- `images/` write permissions on cPanel must be correct for uploads.
- Losing Google OAuth means a shared secret; mitigated by HTTPS, bcrypt, session
  regeneration, and login throttling.

