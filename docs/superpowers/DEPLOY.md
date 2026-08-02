# Deploy to shared hosting (cPanel)

Target: Apache + PHP + MySQL shared host, replacing Firebase + Vercel.

**Domain (registered 2026-07-27, expires 2027-07-27): `maknemytierlist.site`.**
Hosting for it was not yet confirmed as of that date — a domain alone cannot
serve PHP/MySQL, so verify a hosting plan exists before following these steps.

A ready-to-upload bundle (site + migrated images + schema.sql + import.sql +
a step-by-step Russian guide) is produced by staging `public_html/` together
with `tools/out/` — see step 5 below for regenerating the data.

**Source of truth: `kannurali/MaknemyTierlist`, branch `master`** (moved there
2026-07-29; it used to be `kannurali/NexusTierlist`). cPanel clones it into
`/home/maknemyt/repositories/MaknemyTierlist`. Branch `vercel-backup` on the
same repo pins `852cd55`, the last Firebase + Vercel state, purely as a rollback
reference — Vercel itself is retired and its build now fails, which is expected.

## One-time setup

1. **Database.** In cPanel create a MySQL database + user, grant all privileges.
   In phpMyAdmin run `schema.sql` (creates the `tierlist` + `likes` singleton rows).

2. **Upload the site.** Upload the contents of `public_html/` to the host's web
   root (usually `public_html/` on cPanel).

3. **config.php ABOVE the web root.** Copy `config.sample.php` to `config.php`
   and place it one level above the web root (NOT inside `public_html/`). Fill in:
   - `dsn` → `mysql:host=localhost;dbname=<cpanel_db>;charset=utf8mb4`
   - `db_user`, `db_pass` → the cPanel DB user
   - `admin_hash` → generate with:
     `php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"`
   - `images_dir` → absolute path to the uploaded `public_html/images`
   Then point `public_html/api/_bootstrap.php`'s `CONFIG_PATH` at that location
   (default is `__DIR__ . '/../../config.php'` = one level above `public_html`,
   which already matches if you place `config.php` directly above the web root).

4. **Images dir writable.** Ensure `public_html/images/` is writable by the web
   user (permissions 755).

5. **Migrate the data (run locally once, then upload):**
   - `node tools/migrate.mjs` → produces `tools/out/images/`, `tierlist.clean.json`,
     `import.sql`.
   - Upload `tools/out/images/*` into `public_html/images/`.
   - Run `tools/out/import.sql` in phpMyAdmin (loads the cleaned tierlist + the
     current likes count).

6. **Domain + SSL.** Point the free domain at the host; enable SSL (cPanel →
   SSL/TLS / AutoSSL). The site forces HTTPS via `.htaccess`.

## After deploying the icon size cap (one-time)

Icons uploaded before `ICON_MAX_SIDE` existed are still stored at up to 800x800.
The push webhook does not do this — it only ships code, so new uploads get
capped while the already-stored icons stay full size. Run it once by hand.

On the server, from the repository clone (NOT the web root). `config.php` lives
above the web root, outside the clone, so point the script at it:

```
cd /home/maknemyt/repositories/MaknemyTierlist
php tools/downscale-images.php --config=/home/maknemyt/config.php --dry-run
php tools/downscale-images.php --config=/home/maknemyt/config.php
```

Without `--config` the script looks next to the repo and then in `$HOME`, and
prints exactly which paths it tried if it finds nothing.

It writes each resized icon under a new content-hash name and updates the
tierlist row. The originals stay on disk — `/images/` is served `immutable`, so
reusing a filename would leave browsers on the old bytes. The script prints the
`rm` lines for the orphans; run them only after checking the site.

Requires the GD extension with WebP support. Without a matching encoder the
script leaves that image untouched and says so, rather than failing.

## Smoke test after deploy

- Load the site: the tier list renders, images load from `/images/...`
  (check the Network tab — image requests, not base64).
- Like button increments (and persists across reload).
- "Войти" → enter the admin password → admin controls appear.
- Edit something → Save → succeeds; reload shows the change.
- Pick a new icon → Network shows POST `/api/upload.php` returning a `/images/...`
  URL, and the icon renders.
- Logged out, `POST /api/save.php` returns 401.

## Auto-deploy on push (optional)

cPanel's Git Version Control does not deploy by itself — without this, every
push needs "Update from Remote" + "Deploy HEAD Commit" clicked by hand.
`public_html/api/deploy.php` closes that loop.

1. **Generate a secret** and put it in `config.php` (above the web root,
   git-ignored — the repository is public, so the secret must never live in it):

   ```
   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

   Add `deploy_secret`, `deploy_repo`, `deploy_path` and `deploy_branch` as
   shown in `config.sample.php`. An empty `deploy_secret` keeps the endpoint
   disabled: it answers 503 and runs nothing.

2. **GitHub → `kannurali/MaknemyTierlist` → Settings → Webhooks → Add webhook**
   - Payload URL: `https://maknemytierlist.site/api/deploy.php`
   - Content type: `application/json`
   - Secret: the value from step 1
   - Events: "Just the push event"

3. GitHub immediately sends a `ping`; the endpoint answers `pong` without
   deploying. A green tick in "Recent Deliveries" means the signature matched.

What it does on a push to the deploy branch: `git fetch` + `git merge --ff-only`,
then the same additive `cp -R public_html/. <web root>/` as `.cpanel.yml`. It
never delete-syncs, so admin-uploaded images survive. Every delivery — accepted,
skipped or rejected — is appended to `deploy.log` next to `config.php`.

Requests without a valid `X-Hub-Signature-256` HMAC are rejected with 401
before anything runs, and pushes to other branches are logged and ignored.

If deploys fail with `exec_disabled` or `git_not_found`, the host has locked
down `exec()` or hidden git; fall back to clicking Deploy in cPanel.

## Notes

- No Composer / external PHP packages required.
- "Unlimited traffic" on shared hosting still has fair-use CPU/process caps —
  images are served as static files (cheap) and only the tiny structure JSON +
  like counter go through PHP/MySQL, keeping load low.
- `config.php` and `public_html/images/*` are git-ignored; never commit real
  credentials or uploaded images.
