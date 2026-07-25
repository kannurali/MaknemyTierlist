# Deploy to shared hosting (cPanel)

Target: Apache + PHP + MySQL shared host (e.g. HostIQ), replacing Firebase + Vercel.

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

## Smoke test after deploy

- Load the site: the tier list renders, images load from `/images/...`
  (check the Network tab — image requests, not base64).
- Like button increments (and persists across reload).
- "Войти" → enter the admin password → admin controls appear.
- Edit something → Save → succeeds; reload shows the change.
- Pick a new icon → Network shows POST `/api/upload.php` returning a `/images/...`
  URL, and the icon renders.
- Logged out, `POST /api/save.php` returns 401.

## Notes

- No Composer / external PHP packages required.
- "Unlimited traffic" on shared hosting still has fair-use CPU/process caps —
  images are served as static files (cheap) and only the tiny structure JSON +
  like counter go through PHP/MySQL, keeping load low.
- `config.php` and `public_html/images/*` are git-ignored; never commit real
  credentials or uploaded images.
