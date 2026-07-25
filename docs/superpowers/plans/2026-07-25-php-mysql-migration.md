# PHP/MySQL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the Nexus Tier List backend off Firebase/Vercel onto a single Apache+PHP+MySQL shared host, splitting images out to static files.

**Architecture:** Static frontend + `api/*.php` endpoints backed by two MySQL singleton rows (`tierlist`, `likes`). Each endpoint is a pure `handle_*(PDO, array): [int, array]` function with a thin dispatch tail, so it is unit-testable with an in-memory SQLite DB. Images live as content-hashed static files under `images/`. Admin auth is a shared bcrypt password in a PHP session.

**Tech Stack:** PHP 7.4+ (PDO), MySQL 5.7+/MariaDB, plain JS (existing `app.js`), Node 18+ (one-time migration script only). Zero Composer dependencies — a tiny hand-rolled test runner is used.

## Global Constraints

- PHP baseline: **7.4+** (uses arrow fns sparingly, typed params; no 8.x-only syntax).
- **No Composer / external PHP packages** — shared hosting friendliness.
- All DB access via **PDO prepared statements**, `ERRMODE_EXCEPTION`, `utf8mb4`.
- SQL must run on **both MySQL and SQLite** (tests use SQLite): no `GREATEST`, no engine-specific functions in queries.
- Endpoint files must have **no side effects when `define('TESTING', 1)`** is set before inclusion — only the dispatch tail runs live.
- Same-origin only: **no `Access-Control-Allow-Origin` header**.
- `rev` is always **server-generated**: `(int) round(microtime(true) * 1000)`.
- Per-image cap: **500 KB**. Serialized tierlist blob cap: **512 KB**.
- Real `config.php` is **git-ignored** and lives above webroot in production; repo ships `config.sample.php`.
- Bump `js/app.js?v=` in `index.html` whenever `app.js` changes.

---

## File Structure

```
public_html/
  index.html                 # MODIFY: drop firebase, add login UI, bump version
  css/, assets/              # unchanged
  js/
    app.js                   # MODIFY: remove firebase, wire .php endpoints, password auth, upload
    firebase-config.js       # DELETE
  images/                    # NEW: content-hashed webp/png (writable dir); .htaccess inside
  api/
    _bootstrap.php           # NEW: db(), json_out(), read_json_body(), require_admin(), start_session()
    lib/
      images.php             # NEW: save_image_bytes(), data_url_to_bytes(), extract_embedded_images()
      validate.php           # NEW: validate_state()
    state.php                # NEW
    tierlist.php             # NEW
    like.php                 # NEW
    save.php                 # NEW
    upload.php               # NEW
    login.php                # NEW
    logout.php               # NEW
    session.php              # NEW
  .htaccess                  # NEW: force HTTPS, deny sensitive files
config.sample.php            # NEW: template for real config.php
schema.sql                   # NEW
.gitignore                   # MODIFY: ignore config.php, images/*, scratch
tools/
  migrate.mjs                # NEW: one-time Firebase blob -> images/ + clean json + import.sql
tests/
  lib.php                    # NEW: test runner + assertions + test_db()
  images_test.php            # NEW
  validate_test.php          # NEW
  like_test.php              # NEW
  state_test.php             # NEW
  tierlist_test.php          # NEW
  auth_test.php              # NEW
  save_test.php              # NEW
  upload_test.php            # NEW
```

---

## Task 1: Test harness, schema, config template

**Files:**
- Create: `tests/lib.php`
- Create: `schema.sql`
- Create: `config.sample.php`
- Modify: `.gitignore`
- Test: `tests/harness_selfcheck.php`

**Interfaces:**
- Produces: `test(string $name, callable $fn)`, `assert_eq($exp, $got, $msg='')`, `assert_true($c, $msg='')`, `assert_throws(callable $fn, $msg='')`, `run_tests()`, `test_db(): PDO`. All later test files require `tests/lib.php` and use these.

- [ ] **Step 1: Write the test runner**

Create `tests/lib.php`:

```php
<?php
// Zero-dependency test runner. Usage: require this, call test(), then run_tests().
$GLOBALS['__tests'] = [];
$GLOBALS['__asserts'] = 0;
$GLOBALS['__fails'] = 0;

function test(string $name, callable $fn): void { $GLOBALS['__tests'][$name] = $fn; }

function assert_eq($exp, $got, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    if ($exp !== $got) {
        $GLOBALS['__fails']++;
        fwrite(STDERR, "  ASSERT FAIL $msg\n    expected: " . var_export($exp, true) .
            "\n    got:      " . var_export($got, true) . "\n");
    }
}

function assert_true($c, string $msg = ''): void { assert_eq(true, (bool)$c, $msg); }

function assert_throws(callable $fn, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    try { $fn(); } catch (Throwable $e) { return; }
    $GLOBALS['__fails']++;
    fwrite(STDERR, "  ASSERT FAIL $msg — expected an exception, none thrown\n");
}

function run_tests(): void {
    foreach ($GLOBALS['__tests'] as $name => $fn) {
        try { $fn(); echo "ok - $name\n"; }
        catch (Throwable $e) {
            $GLOBALS['__fails']++;
            fwrite(STDERR, "ERROR - $name: " . $e->getMessage() . "\n");
        }
    }
    echo "\n{$GLOBALS['__asserts']} assertions, {$GLOBALS['__fails']} failures\n";
    exit($GLOBALS['__fails'] ? 1 : 0);
}

// In-memory SQLite DB seeded with the app schema (portable subset of schema.sql).
function test_db(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE tierlist (id INTEGER PRIMARY KEY, data TEXT NOT NULL, rev INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE likes (id INTEGER PRIMARY KEY, count INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("INSERT INTO tierlist (id, data, rev) VALUES (1, '{}', 0)");
    $pdo->exec("INSERT INTO likes (id, count) VALUES (1, 0)");
    return $pdo;
}
```

- [ ] **Step 2: Write the production schema**

Create `schema.sql`:

```sql
-- Run once in phpMyAdmin (or `mysql < schema.sql`) on the production DB.
CREATE TABLE IF NOT EXISTS tierlist (
  id   TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  data LONGTEXT NOT NULL,
  rev  BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS likes (
  id    TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  count INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tierlist (id, data, rev) VALUES (1, '{}', 0);
INSERT IGNORE INTO likes (id, count) VALUES (1, 0);
```

- [ ] **Step 3: Write the config template**

Create `config.sample.php` (copy to `config.php`, fill in, keep out of git / above webroot in prod):

```php
<?php
// Copy to config.php. In production place ABOVE public_html and point
// _bootstrap.php's CONFIG_PATH at it. NEVER commit the real config.php.
return [
    // PDO DSN. Local XAMPP example below; production uses the cPanel DB.
    'dsn'      => 'mysql:host=127.0.0.1;dbname=nexus;charset=utf8mb4',
    'db_user'  => 'root',
    'db_pass'  => '',
    // bcrypt hash of the shared admin password.
    // Generate: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
    'admin_hash' => '$2y$10$REPLACE_WITH_A_REAL_BCRYPT_HASH................................',
    // Absolute path to the writable images directory.
    'images_dir' => __DIR__ . '/public_html/images',
];
```

- [ ] **Step 4: Update .gitignore**

Add to `.gitignore` (create if missing):

```
/config.php
/public_html/images/*
!/public_html/images/.gitkeep
/tools/scratch_*
/scratch_*
```

- [ ] **Step 5: Write the harness self-check test**

Create `tests/harness_selfcheck.php`:

```php
<?php
require __DIR__ . '/lib.php';

test('assert_eq passes on equal', function () {
    assert_eq(1, 1, 'ints equal');
});

test('test_db seeds singleton rows', function () {
    $pdo = test_db();
    assert_eq(0, (int)$pdo->query("SELECT count FROM likes WHERE id=1")->fetchColumn(), 'likes seeded 0');
    assert_eq('{}', $pdo->query("SELECT data FROM tierlist WHERE id=1")->fetchColumn(), 'tierlist seeded');
});

run_tests();
```

- [ ] **Step 6: Run the self-check**

Run: `php tests/harness_selfcheck.php`
Expected: prints `ok - assert_eq passes on equal`, `ok - test_db seeds singleton rows`, then `3 assertions, 0 failures`, exit 0.

- [ ] **Step 7: Commit**

```bash
git add tests/lib.php tests/harness_selfcheck.php schema.sql config.sample.php .gitignore
git commit -m "test: zero-dep PHP test harness + schema + config template"
```

---

## Task 2: Bootstrap helpers (`_bootstrap.php`)

**Files:**
- Create: `public_html/api/_bootstrap.php`
- Test: `tests/bootstrap_test.php`

**Interfaces:**
- Consumes: `config.php` returning the array from Task 1.
- Produces:
  - `db(): PDO` — lazy singleton from config; sets `ERRMODE_EXCEPTION`.
  - `json_out(array $data, int $status = 200): void` — sends JSON + status, no CORS header.
  - `read_json_body(): array` — parses `php://input` JSON body, `[]` on empty/invalid.
  - `start_admin_session(): void` — starts a session with secure cookie params.
  - `is_admin(): bool` — `!empty($_SESSION['admin'])`.
  - `require_admin(): void` — `json_out(['error'=>'unauthorized'], 401); exit;` when not admin.
  - `app_config(): array` — cached loader for `config.php`.
  - Requiring the file must have **no side effects** (functions only).

- [ ] **Step 1: Write the failing test**

Create `tests/bootstrap_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

test('read_json_body parses injected raw body', function () {
    $GLOBALS['__RAW_BODY__'] = '{"dir":-1}';
    assert_eq(['dir' => -1], read_json_body(), 'parses JSON');
});

test('read_json_body returns [] on garbage', function () {
    $GLOBALS['__RAW_BODY__'] = 'not json';
    assert_eq([], read_json_body(), 'garbage -> []');
});

test('is_admin false without session flag', function () {
    $_SESSION = [];
    assert_eq(false, is_admin(), 'no flag -> not admin');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/bootstrap_test.php`
Expected: FAIL — `require(...)_bootstrap.php` does not exist yet (fatal error / file not found).

- [ ] **Step 3: Write `_bootstrap.php`**

Create `public_html/api/_bootstrap.php`:

```php
<?php
// Shared bootstrap. Requiring this declares functions only — no side effects,
// so tests can include it safely (they define TESTING and inject their own PDO).

if (!defined('CONFIG_PATH')) {
    // Production: override by defining CONFIG_PATH before including this file
    // (e.g. to a path above webroot). Default assumes repo layout.
    define('CONFIG_PATH', __DIR__ . '/../../config.php');
}

function app_config(): array {
    static $cfg = null;
    if ($cfg === null) { $cfg = require CONFIG_PATH; }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config();
        $pdo = new PDO($c['dsn'], $c['db_user'], $c['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function read_json_body(): array {
    // Tests inject via $GLOBALS['__RAW_BODY__']; live reads php://input.
    $raw = $GLOBALS['__RAW_BODY__'] ?? file_get_contents('php://input');
    if ($raw === '' || $raw === false) { return []; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

function is_admin(): bool { return !empty($_SESSION['admin']); }

function require_admin(): void {
    start_admin_session();
    if (!is_admin()) { json_out(['error' => 'unauthorized'], 401); exit; }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/bootstrap_test.php`
Expected: PASS — `3 assertions, 0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/_bootstrap.php tests/bootstrap_test.php
git commit -m "feat: bootstrap helpers (db, json_out, session, require_admin)"
```

---

## Task 3: Images library (`lib/images.php`)

**Files:**
- Create: `public_html/api/lib/images.php`
- Test: `tests/images_test.php`

**Interfaces:**
- Produces:
  - `image_ext_for(string $bytes): ?string` — returns `'webp'|'png'|'jpg'` from magic bytes, `null` if not a supported image.
  - `data_url_to_bytes(string $dataUrl): ?string` — decodes a `data:image/...;base64,` URL to raw bytes, `null` if not a data URL.
  - `save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000): string` — validates size + type, writes `<dir>/<sha1>.<ext>` if absent, returns the public URL `"/images/<sha1>.<ext>"`. Throws `RuntimeException` on oversize/invalid.
  - `extract_embedded_images(array $state, string $dir): array` — returns `$state` with every `data:` URL in `tiers[].logo`, `tiers[].items[].icon`, `ad.image` replaced by a saved-file URL (small non-data values untouched).

- [ ] **Step 1: Write the failing test**

Create `tests/images_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/images.php';

// 1x1 PNG (binary), base64 constant for tests.
const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir(): string {
    $d = sys_get_temp_dir() . '/imgtest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

test('image_ext_for detects png', function () {
    assert_eq('png', image_ext_for(base64_decode(PNG_B64)), 'png magic');
});

test('image_ext_for rejects non-image', function () {
    assert_eq(null, image_ext_for('hello world not an image'), 'text -> null');
});

test('data_url_to_bytes decodes', function () {
    $bytes = data_url_to_bytes('data:image/png;base64,' . PNG_B64);
    assert_eq(base64_decode(PNG_B64), $bytes, 'roundtrip bytes');
});

test('data_url_to_bytes returns null for plain url', function () {
    assert_eq(null, data_url_to_bytes('/images/abc.webp'), 'plain url -> null');
});

test('save_image_bytes writes hashed file and dedups', function () {
    $dir = tmp_dir();
    $bytes = base64_decode(PNG_B64);
    $url1 = save_image_bytes($bytes, $dir);
    $url2 = save_image_bytes($bytes, $dir);
    assert_eq($url1, $url2, 'same bytes -> same url');
    assert_eq('/images/' . sha1($bytes) . '.png', $url1, 'url shape');
    assert_true(file_exists($dir . '/' . sha1($bytes) . '.png'), 'file written');
});

test('save_image_bytes rejects oversize', function () {
    $dir = tmp_dir();
    assert_throws(function () use ($dir) {
        save_image_bytes(base64_decode(PNG_B64), $dir, 10); // 10-byte cap
    }, 'oversize throws');
});

test('extract_embedded_images rewrites data urls, keeps plain urls', function () {
    $dir = tmp_dir();
    $state = [
        'tiers' => [
            ['logo' => 'data:image/png;base64,' . PNG_B64,
             'items' => [
                 ['icon' => 'data:image/png;base64,' . PNG_B64],
                 ['icon' => '/images/existing.webp'],
             ]],
        ],
        'ad' => ['image' => 'data:image/png;base64,' . PNG_B64],
    ];
    $out = extract_embedded_images($state, $dir);
    $expected = '/images/' . sha1(base64_decode(PNG_B64)) . '.png';
    assert_eq($expected, $out['tiers'][0]['logo'], 'logo rewritten');
    assert_eq($expected, $out['tiers'][0]['items'][0]['icon'], 'icon rewritten');
    assert_eq('/images/existing.webp', $out['tiers'][0]['items'][1]['icon'], 'plain url kept');
    assert_eq($expected, $out['ad']['image'], 'ad rewritten');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/images_test.php`
Expected: FAIL — `lib/images.php` not found.

- [ ] **Step 3: Write `lib/images.php`**

Create `public_html/api/lib/images.php`:

```php
<?php
// Image helpers: type sniffing, data-url decode, hashed save, embedded extraction.

function image_ext_for(string $bytes): ?string {
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') { return 'webp'; }
    return null;
}

function data_url_to_bytes(string $dataUrl): ?string {
    if (strncmp($dataUrl, 'data:', 5) !== 0) { return null; }
    $comma = strpos($dataUrl, ',');
    if ($comma === false) { return null; }
    $meta = substr($dataUrl, 5, $comma - 5);
    $payload = substr($dataUrl, $comma + 1);
    if (strpos($meta, ';base64') !== false) {
        $decoded = base64_decode($payload, true);
        return $decoded === false ? null : $decoded;
    }
    return rawurldecode($payload);
}

function save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000): string {
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('image too large');
    }
    $ext = image_ext_for($bytes);
    if ($ext === null) {
        throw new RuntimeException('unsupported image type');
    }
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = sha1($bytes) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $name;
    if (!file_exists($path)) {
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('failed to write image');
        }
    }
    return '/images/' . $name;
}

function extract_embedded_images(array $state, string $dir): array {
    $rewrite = function ($val) use ($dir) {
        if (is_string($val) && strncmp($val, 'data:', 5) === 0) {
            $bytes = data_url_to_bytes($val);
            if ($bytes !== null) { return save_image_bytes($bytes, $dir); }
        }
        return $val;
    };
    if (isset($state['tiers']) && is_array($state['tiers'])) {
        foreach ($state['tiers'] as &$tier) {
            if (isset($tier['logo'])) { $tier['logo'] = $rewrite($tier['logo']); }
            if (isset($tier['items']) && is_array($tier['items'])) {
                foreach ($tier['items'] as &$item) {
                    if (isset($item['icon'])) { $item['icon'] = $rewrite($item['icon']); }
                }
                unset($item);
            }
        }
        unset($tier);
    }
    if (isset($state['ad']['image'])) {
        $state['ad']['image'] = $rewrite($state['ad']['image']);
    }
    return $state;
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/images_test.php`
Expected: PASS — all assertions, `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/images.php tests/images_test.php
git commit -m "feat: image lib (type sniff, data-url decode, hashed save, extraction)"
```

---

## Task 4: State validation (`lib/validate.php`)

**Files:**
- Create: `public_html/api/lib/validate.php`
- Test: `tests/validate_test.php`

**Interfaces:**
- Produces: `validate_state($state, int $maxBytes = 524288): array` — returns `['ok' => bool, 'error' => string]`. Valid when `$state` is an array with a `tiers` array and its JSON serialization is `<= $maxBytes`.

- [ ] **Step 1: Write the failing test**

Create `tests/validate_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/validate.php';

test('valid minimal state', function () {
    $r = validate_state(['tiers' => []]);
    assert_true($r['ok'], 'tiers array ok');
});

test('missing tiers rejected', function () {
    $r = validate_state(['nope' => 1]);
    assert_eq(false, $r['ok'], 'no tiers -> invalid');
});

test('non-array rejected', function () {
    $r = validate_state('a string');
    assert_eq(false, $r['ok'], 'string -> invalid');
});

test('oversize rejected', function () {
    $big = ['tiers' => [['items' => [['name' => str_repeat('x', 2000)]]]]];
    $r = validate_state($big, 100); // 100-byte cap
    assert_eq(false, $r['ok'], 'over cap -> invalid');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/validate_test.php`
Expected: FAIL — `lib/validate.php` not found.

- [ ] **Step 3: Write `lib/validate.php`**

Create `public_html/api/lib/validate.php`:

```php
<?php
function validate_state($state, int $maxBytes = 524288): array {
    if (!is_array($state)) {
        return ['ok' => false, 'error' => 'state must be a JSON object'];
    }
    if (!isset($state['tiers']) || !is_array($state['tiers'])) {
        return ['ok' => false, 'error' => 'missing tiers array'];
    }
    $encoded = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return ['ok' => false, 'error' => 'state is not serializable'];
    }
    if (strlen($encoded) > $maxBytes) {
        return ['ok' => false, 'error' => 'state too large (' . strlen($encoded) . ' bytes)'];
    }
    return ['ok' => true, 'error' => ''];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/validate_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/lib/validate.php tests/validate_test.php
git commit -m "feat: state validation helper"
```

---

## Task 5: `like.php` (atomic counter)

**Files:**
- Create: `public_html/api/like.php`
- Test: `tests/like_test.php`

**Interfaces:**
- Consumes: `read_json_body()`, `json_out()`, `db()` from `_bootstrap.php`.
- Produces: `handle_like(PDO $pdo, array $body): array` returning `[int $status, array $payload]`. Increments by `+1` unless `body['dir'] === -1`; clamps at 0; never underflows.

- [ ] **Step 1: Write the failing test**

Create `tests/like_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/like.php';

test('increment adds one', function () {
    $pdo = test_db();
    [$status, $p] = handle_like($pdo, ['dir' => 1]);
    assert_eq(200, $status, 'ok status');
    assert_eq(1, $p['likes'], 'count 1');
});

test('decrement clamps at zero', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, ['dir' => -1]);
    assert_eq(0, $p['likes'], 'stays 0');
});

test('unknown dir defaults to +1', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, ['dir' => 99]);
    assert_eq(1, $p['likes'], 'defaults +1');
});

test('decrement after increment nets zero', function () {
    $pdo = test_db();
    handle_like($pdo, ['dir' => 1]);
    [, $p] = handle_like($pdo, ['dir' => -1]);
    assert_eq(0, $p['likes'], '1 then -1 = 0');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/like_test.php`
Expected: FAIL — `like.php` not found / `handle_like` undefined.

- [ ] **Step 3: Write `like.php`**

Create `public_html/api/like.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_like(PDO $pdo, array $body): array {
    $dir = (isset($body['dir']) && (int)$body['dir'] === -1) ? -1 : 1;
    // Atomic + underflow-safe + portable (MySQL & SQLite): the WHERE guard
    // blocks the update when it would drop below zero.
    $stmt = $pdo->prepare(
        "UPDATE likes SET count = count + :inc WHERE id = 1 AND count + :chk >= 0"
    );
    $stmt->execute([':inc' => $dir, ':chk' => $dir]);
    $count = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    return [200, ['ok' => true, 'likes' => $count]];
}

if (!defined('TESTING')) {
    [$status, $payload] = handle_like(db(), read_json_body());
    json_out($payload, $status);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/like_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/like.php tests/like_test.php
git commit -m "feat: like.php atomic underflow-safe counter"
```

---

## Task 6: `state.php` (light poll)

**Files:**
- Create: `public_html/api/state.php`
- Test: `tests/state_test.php`

**Interfaces:**
- Produces: `handle_state(PDO $pdo): array` → `[200, ['rev' => int, 'likes' => int]]`.

- [ ] **Step 1: Write the failing test**

Create `tests/state_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/state.php';

test('returns rev and likes', function () {
    $pdo = test_db();
    $pdo->exec("UPDATE tierlist SET rev = 12345 WHERE id = 1");
    $pdo->exec("UPDATE likes SET count = 7 WHERE id = 1");
    [$status, $p] = handle_state($pdo);
    assert_eq(200, $status, 'ok');
    assert_eq(12345, $p['rev'], 'rev');
    assert_eq(7, $p['likes'], 'likes');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/state_test.php`
Expected: FAIL — `state.php` not found.

- [ ] **Step 3: Write `state.php`**

Create `public_html/api/state.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_state(PDO $pdo): array {
    $rev   = (int)$pdo->query("SELECT rev FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    return [200, ['rev' => $rev, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    [$status, $payload] = handle_state(db());
    json_out($payload, $status);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/state_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/state.php tests/state_test.php
git commit -m "feat: state.php light rev+likes poll"
```

---

## Task 7: `tierlist.php` (full data + rev cache)

**Files:**
- Create: `public_html/api/tierlist.php`
- Test: `tests/tierlist_test.php`

**Interfaces:**
- Produces: `handle_tierlist(PDO $pdo): array` → `[200, ['tierlist' => array|null, 'likes' => int]]`. Parses the stored JSON blob.

- [ ] **Step 1: Write the failing test**

Create `tests/tierlist_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/tierlist.php';

test('returns parsed tierlist and likes', function () {
    $pdo = test_db();
    $blob = json_encode(['tiers' => [['name' => 'S', 'items' => []]], '_rev' => 42]);
    $stmt = $pdo->prepare("UPDATE tierlist SET data = :d, rev = 42 WHERE id = 1");
    $stmt->execute([':d' => $blob]);
    $pdo->exec("UPDATE likes SET count = 3 WHERE id = 1");
    [$status, $p] = handle_tierlist($pdo);
    assert_eq(200, $status, 'ok');
    assert_eq('S', $p['tierlist']['tiers'][0]['name'], 'parsed blob');
    assert_eq(3, $p['likes'], 'likes');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/tierlist_test.php`
Expected: FAIL — `tierlist.php` not found.

- [ ] **Step 3: Write `tierlist.php`**

Create `public_html/api/tierlist.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_tierlist(PDO $pdo): array {
    $raw   = $pdo->query("SELECT data FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    $tierlist = $raw ? json_decode($raw, true) : null;
    return [200, ['tierlist' => $tierlist, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    // Each rev is immutable, so a request tagged ?rev=N can cache forever.
    if (isset($_GET['rev']) && $_GET['rev'] !== '') {
        header('Cache-Control: public, max-age=31536000, immutable');
    } else {
        header('Cache-Control: no-cache');
    }
    [$status, $payload] = handle_tierlist(db());
    json_out($payload, $status);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/tierlist_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/tierlist.php tests/tierlist_test.php
git commit -m "feat: tierlist.php full data with per-rev immutable cache"
```

---

## Task 8: Auth endpoints (`login.php`, `logout.php`, `session.php`)

**Files:**
- Create: `public_html/api/login.php`
- Create: `public_html/api/logout.php`
- Create: `public_html/api/session.php`
- Test: `tests/auth_test.php`

**Interfaces:**
- Produces: `verify_admin_password(string $password, string $hash): bool` (in `login.php`) — thin wrapper over `password_verify`. Endpoints wire it to sessions in their dispatch tails.

- [ ] **Step 1: Write the failing test**

Create `tests/auth_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/login.php';

test('correct password verifies', function () {
    $hash = password_hash('secret', PASSWORD_BCRYPT);
    assert_true(verify_admin_password('secret', $hash), 'match');
});

test('wrong password rejected', function () {
    $hash = password_hash('secret', PASSWORD_BCRYPT);
    assert_eq(false, verify_admin_password('nope', $hash), 'mismatch');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/auth_test.php`
Expected: FAIL — `login.php` not found / `verify_admin_password` undefined.

- [ ] **Step 3: Write the three endpoints**

Create `public_html/api/login.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

function verify_admin_password(string $password, string $hash): bool {
    return $hash !== '' && password_verify($password, $hash);
}

if (!defined('TESTING')) {
    start_admin_session();
    $body = read_json_body();
    $password = (string)($body['password'] ?? '');
    $cfg = app_config();
    if (verify_admin_password($password, $cfg['admin_hash'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        json_out(['ok' => true], 200);
    } else {
        usleep(400000); // ~0.4s throttle on failure
        json_out(['ok' => false], 401);
    }
}
```

Create `public_html/api/logout.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    start_admin_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true], 200);
}
```

Create `public_html/api/session.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    start_admin_session();
    json_out(['admin' => is_admin()], 200);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/auth_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/login.php public_html/api/logout.php public_html/api/session.php tests/auth_test.php
git commit -m "feat: password auth endpoints (login/logout/session)"
```

---

## Task 9: `save.php` (admin write + silent image extraction)

**Files:**
- Create: `public_html/api/save.php`
- Test: `tests/save_test.php`

**Interfaces:**
- Consumes: `validate_state()` (Task 4), `extract_embedded_images()` (Task 3).
- Produces: `handle_save(PDO $pdo, array $state, string $imagesDir, int $revMs): array` → `[int, array]`. Validates, extracts embedded images to files, stores the cleaned blob with server `rev`. Returns `[200, ['ok'=>true,'rev'=>int]]` or `[400, ['ok'=>false,'error'=>string]]`.

- [ ] **Step 1: Write the failing test**

Create `tests/save_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/images.php';
require __DIR__ . '/../public_html/api/lib/validate.php';
require __DIR__ . '/../public_html/api/save.php';

const PNG_B64_S = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir_s(): string {
    $d = sys_get_temp_dir() . '/savetest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

test('rejects invalid state', function () {
    $pdo = test_db();
    [$status, $p] = handle_save($pdo, ['no_tiers' => 1], tmp_dir_s(), 1000);
    assert_eq(400, $status, '400');
    assert_eq(false, $p['ok'], 'not ok');
});

test('saves, sets server rev, extracts embedded image', function () {
    $pdo = test_db();
    $dir = tmp_dir_s();
    $state = ['tiers' => [['items' => [['icon' => 'data:image/png;base64,' . PNG_B64_S]]]]];
    [$status, $p] = handle_save($pdo, $state, $dir, 999000);
    assert_eq(200, $status, 'ok');
    assert_eq(999000, $p['rev'], 'server rev used');

    $stored = json_decode($pdo->query("SELECT data FROM tierlist WHERE id=1")->fetchColumn(), true);
    $expectUrl = '/images/' . sha1(base64_decode(PNG_B64_S)) . '.png';
    assert_eq($expectUrl, $stored['tiers'][0]['items'][0]['icon'], 'icon rewritten to url');
    assert_eq(999000, (int)$pdo->query("SELECT rev FROM tierlist WHERE id=1")->fetchColumn(), 'rev persisted');
});

test('rejects oversize embedded image', function () {
    $pdo = test_db();
    $dir = tmp_dir_s();
    // Build a >500KB data URL by repeating (invalid image bytes but big).
    $big = 'data:image/png;base64,' . base64_encode(str_repeat('A', 600000));
    $state = ['tiers' => [['items' => [['icon' => $big]]]]];
    [$status, $p] = handle_save($pdo, $state, $dir, 1000);
    assert_eq(400, $status, 'oversize -> 400');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/save_test.php`
Expected: FAIL — `save.php` not found.

- [ ] **Step 3: Write `save.php`**

Create `public_html/api/save.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';
require_once __DIR__ . '/lib/validate.php';

function handle_save(PDO $pdo, array $state, string $imagesDir, int $revMs): array {
    $v = validate_state($state);
    if (!$v['ok']) { return [400, ['ok' => false, 'error' => $v['error']]]; }

    try {
        $state = extract_embedded_images($state, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['ok' => false, 'error' => $e->getMessage()]];
    }

    // Re-validate size AFTER extraction (blob should now be tiny).
    $v2 = validate_state($state);
    if (!$v2['ok']) { return [400, ['ok' => false, 'error' => $v2['error']]]; }

    $state['_rev'] = $revMs;
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("UPDATE tierlist SET data = :d, rev = :r WHERE id = 1");
    $stmt->execute([':d' => $json, ':r' => $revMs]);
    return [200, ['ok' => true, 'rev' => $revMs]];
}

if (!defined('TESTING')) {
    require_admin();
    $cfg = app_config();
    $revMs = (int)round(microtime(true) * 1000);
    [$status, $payload] = handle_save(db(), read_json_body(), $cfg['images_dir'], $revMs);
    json_out($payload, $status);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/save_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/save.php tests/save_test.php
git commit -m "feat: save.php admin write with silent image extraction + server rev"
```

---

## Task 10: `upload.php` (admin image upload)

**Files:**
- Create: `public_html/api/upload.php`
- Test: `tests/upload_test.php`

**Interfaces:**
- Consumes: `save_image_bytes()`, `data_url_to_bytes()` (Task 3).
- Produces: `handle_upload(string $imagesDir, ?string $dataUrl, ?array $file): array` → `[int, array]`. Accepts either a base64 data URL (`$dataUrl`) or a multipart file (`$file` = one entry of `$_FILES`). Returns `[200, ['url'=>string]]` or `[400, ['error'=>string]]`.

- [ ] **Step 1: Write the failing test**

Create `tests/upload_test.php`:

```php
<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/images.php';
require __DIR__ . '/../public_html/api/upload.php';

const PNG_B64_U = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir_u(): string {
    $d = sys_get_temp_dir() . '/uploadtest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

test('accepts data url and returns hashed url', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null);
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1(base64_decode(PNG_B64_U)) . '.png', $p['url'], 'url');
});

test('rejects missing input', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, null, null);
    assert_eq(400, $status, '400 no input');
});

test('rejects non-image data url', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:text/plain;base64,' . base64_encode('hello'), null);
    assert_eq(400, $status, '400 not image');
});

run_tests();
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/upload_test.php`
Expected: FAIL — `upload.php` not found.

- [ ] **Step 3: Write `upload.php`**

Create `public_html/api/upload.php`:

```php
<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file): array {
    $bytes = null;
    if (is_string($dataUrl) && $dataUrl !== '') {
        $bytes = data_url_to_bytes($dataUrl);
    } elseif ($file && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        $bytes = file_get_contents($file['tmp_name']);
    }
    if ($bytes === null || $bytes === false || $bytes === '') {
        return [400, ['error' => 'no image provided']];
    }
    try {
        $url = save_image_bytes($bytes, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['error' => $e->getMessage()]];
    }
    return [200, ['url' => $url]];
}

if (!defined('TESTING')) {
    require_admin();
    $cfg = app_config();
    $body = read_json_body();
    $dataUrl = is_string($body['data'] ?? null) ? $body['data'] : null;
    $file = $_FILES['image'] ?? null;
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file);
    json_out($payload, $status);
}
```

Note: `is_uploaded_file` returns false under CLI tests, so the data-url path is what the tests exercise; the multipart branch is covered by the Task 16 live smoke.

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/upload_test.php`
Expected: PASS — `0 failures`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/upload.php tests/upload_test.php
git commit -m "feat: upload.php admin image upload (data-url or multipart)"
```

---

## Task 11: `.htaccess`, images dir, config wiring

**Files:**
- Create: `public_html/.htaccess`
- Create: `public_html/images/.htaccess`
- Create: `public_html/images/.gitkeep`

**Interfaces:** none (server config). Verified manually in Task 16.

- [ ] **Step 1: Write the site `.htaccess`**

Create `public_html/.htaccess`:

```apache
# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Deny access to sensitive files
<FilesMatch "\.(sql|md|mjs)$">
  Require all denied
</FilesMatch>

# Long-cache hashed images
<IfModule mod_headers.c>
  <FilesMatch "\.(webp|png|jpg|jpeg)$">
    Header set Cache-Control "public, max-age=31536000, immutable"
  </FilesMatch>
</IfModule>
```

- [ ] **Step 2: Write the images `.htaccess` (defense-in-depth: no script execution)**

Create `public_html/images/.htaccess`:

```apache
# Uploaded files are data only — never execute anything here.
php_flag engine off
<FilesMatch "\.(php|phtml|phar|cgi|pl)$">
  Require all denied
</FilesMatch>
Options -ExecCGI
```

- [ ] **Step 3: Keep the images dir in git without its contents**

Create `public_html/images/.gitkeep` (empty file).

- [ ] **Step 4: Verify config path resolution note**

Confirm `public_html/api/_bootstrap.php` default `CONFIG_PATH` is `__DIR__ . '/../../config.php'`, i.e. repo-root `config.php` (one level above `public_html`). In production, if `config.php` sits above webroot, define `CONFIG_PATH` in a prepended file or edit the constant during deploy. Document this in the deploy step (Task 16). No code change needed here.

- [ ] **Step 5: Commit**

```bash
git add public_html/.htaccess public_html/images/.htaccess public_html/images/.gitkeep
git commit -m "chore: htaccess (force https, deny sensitive, cache images, no exec in images)"
```

---

## Task 12: One-time migration tool (`tools/migrate.mjs`)

**Files:**
- Create: `tools/migrate.mjs`
- Test: run against live Firebase data (assert output shape); no unit test file.

**Interfaces:**
- Produces: writes `tools/out/images/<sha1>.<ext>`, `tools/out/tierlist.clean.json`, `tools/out/import.sql`. Reads the current Firebase blob + likes over HTTPS.

- [ ] **Step 1: Write the migration script**

Create `tools/migrate.mjs`:

```javascript
// One-time migration: pull the current Firebase tierlist + likes, extract every
// embedded base64 image to tools/out/images/<sha1>.<ext>, rewrite values to URLs,
// and emit a cleaned JSON + an import.sql for the new MySQL DB.
//
// Run: node tools/migrate.mjs
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const FB = 'https://nexus-117f0-default-rtdb.europe-west1.firebasedatabase.app';
const OUT = join('tools', 'out');
const IMG = join(OUT, 'images');
mkdirSync(IMG, { recursive: true });

function extFromMeta(meta) {
  if (meta.includes('image/webp')) return 'webp';
  if (meta.includes('image/jpeg') || meta.includes('image/jpg')) return 'jpg';
  return 'png';
}

function saveDataUrl(dataUrl) {
  const comma = dataUrl.indexOf(',');
  const meta = dataUrl.slice(5, comma);
  const bytes = Buffer.from(dataUrl.slice(comma + 1), 'base64');
  const ext = extFromMeta(meta);
  const name = createHash('sha1').update(bytes).digest('hex') + '.' + ext;
  writeFileSync(join(IMG, name), bytes);
  return '/images/' + name;
}

function rewrite(val) {
  return (typeof val === 'string' && val.startsWith('data:')) ? saveDataUrl(val) : val;
}

const [tRes, lRes] = await Promise.all([
  fetch(`${FB}/tierlist.json`),
  fetch(`${FB}/likes.json`),
]);
const state = await tRes.json();
const likesRaw = await lRes.json();
const likes = (typeof likesRaw === 'number' && likesRaw >= 0) ? likesRaw : 0;

let imgCount = 0;
const countingRewrite = (v) => { const out = rewrite(v); if (out !== v) imgCount++; return out; };

for (const tier of (state.tiers || [])) {
  if (tier.logo) tier.logo = countingRewrite(tier.logo);
  for (const item of (tier.items || [])) {
    if (item.icon) item.icon = countingRewrite(item.icon);
  }
}
if (state.ad && state.ad.image) state.ad.image = countingRewrite(state.ad.image);

const rev = 1;
state._rev = rev;
const clean = JSON.stringify(state);
writeFileSync(join(OUT, 'tierlist.clean.json'), clean);

// Emit import.sql. Escape single quotes and backslashes for a MySQL string literal.
const esc = (s) => s.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const sql = [
  '-- Generated by tools/migrate.mjs. Run after schema.sql.',
  `UPDATE tierlist SET data = '${esc(clean)}', rev = ${rev} WHERE id = 1;`,
  `UPDATE likes SET count = ${likes} WHERE id = 1;`,
  '',
].join('\n');
writeFileSync(join(OUT, 'import.sql'), sql);

console.log(`images extracted: ${imgCount}`);
console.log(`clean json bytes: ${clean.length}`);
console.log(`likes: ${likes}`);
console.log(`outputs in ${OUT}/ (images/, tierlist.clean.json, import.sql)`);
```

- [ ] **Step 2: Run the migration against live data**

Run: `node tools/migrate.mjs`
Expected: prints `images extracted: 111` (110 icons + 1 ad, ±), `clean json bytes:` well under 100000 (target ~46 KB), `likes:` the current count. `tools/out/images/` contains ~111 files; `tools/out/import.sql` and `tools/out/tierlist.clean.json` exist.

- [ ] **Step 3: Sanity-check the cleaned JSON has no data URLs**

Run: `grep -c "data:image" tools/out/tierlist.clean.json`
Expected: `0` (all embedded images externalized).

- [ ] **Step 4: Commit the tool (not its output)**

```bash
git add tools/migrate.mjs
git commit -m "feat: one-time Firebase->files migration tool"
```

(`tools/out/` is git-ignored via the `/tools/scratch_*` sibling and the `images/*` rule; if `tools/out` is tracked, add `/tools/out/` to `.gitignore` first.)

---

## Task 13: Client — remove Firebase, wire `.php` reads/writes

**Files:**
- Modify: `js/app.js` (read/write/init sections)
- Modify: `index.html` (remove firebase scripts, bump version)
- Delete: `js/firebase-config.js`

**Interfaces:**
- Consumes: `/api/state.php`, `/api/tierlist.php?rev=`, `/api/save.php`, `/api/session.php`, `/api/login.php`, `/api/logout.php` from earlier tasks. (Login/logout UI wired in Task 14.)

- [ ] **Step 1: Point the endpoint constants at PHP**

In `js/app.js`, change:

```javascript
  const API_TIERLIST = "/api/tierlist";
  const API_STATE    = "/api/state";
```

to:

```javascript
  const API_TIERLIST = "/api/tierlist.php";
  const API_STATE    = "/api/state.php";
  const API_SAVE     = "/api/save.php";
  const API_SESSION  = "/api/session.php";
  const API_LOGIN    = "/api/login.php";
  const API_LOGOUT   = "/api/logout.php";
  const API_UPLOAD   = "/api/upload.php";
```

- [ ] **Step 2: Remove the Firebase REST fallback from `fetchState`**

In `js/app.js`, replace the whole `fetchState` function body's fallback (the block after the `/api/state` fetch that builds `fbUrl(...)`) so it becomes just:

```javascript
  async function fetchState() {
    try {
      const r = await fetch(API_STATE, { cache: "no-store" });
      if (r.ok) return await r.json();
    } catch (e) { /* offline */ }
    return null;
  }
```

- [ ] **Step 3: Remove the Firebase REST fallback from `fetchFull`**

In `js/app.js`, replace `fetchFull` so it no longer references `fbUrl`:

```javascript
  async function fetchFull(rev) {
    const q = (rev !== null && rev !== undefined && rev !== "") ? ("?rev=" + encodeURIComponent(rev)) : "";
    try {
      const r = await fetch(API_TIERLIST + q, { cache: "default" });
      if (r.ok) {
        const d = await r.json();
        if (d && d.tierlist) { handleSnapshot(d.tierlist); return true; }
      }
    } catch (e) { /* offline */ }
    return false;
  }
```

- [ ] **Step 4: Rewrite `publish()` to POST to save.php**

In `js/app.js`, replace the `publish()` function with:

```javascript
  async function publish() {
    if (!isAdmin || !dirty || saving) return;
    saving = true; renderSaveBtn();
    try {
      await compactState(); // uploads any oversized embedded images first (Task 15)
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
      render();
      const r = await fetch(API_SAVE, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(state),
      });
      const d = await r.json().catch(() => ({}));
      if (!r.ok || !d.ok) { throw new Error(d.error || ("save failed: " + r.status)); }
      state._rev = d.rev; lastRev = d.rev;
      try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
      saving = false; clearDirty(); flashSaved();
    } catch (err) {
      saving = false; renderSaveBtn();
      savedHint.textContent = "⚠ " + (err.message || "Ошибка сохранения");
    }
  }
```

- [ ] **Step 5: Replace `initFirebase()` with a plain init (session-based admin)**

In `js/app.js`, replace the entire `initFirebase` function with:

```javascript
  function initBackend() {
    startPolling();
    checkSession();
  }

  async function checkSession() {
    try {
      const r = await fetch(API_SESSION, { cache: "no-store" });
      const d = await r.json();
      setAdminMode(!!d.admin);
    } catch (e) { setAdminMode(false); }
  }
```

- [ ] **Step 6: Update the init call and remove `fbRef`/`fbUrl` usage**

In `js/app.js`:
- Change the init line `initFirebase();` to `initBackend();`.
- Delete the now-unused `fbRef` variable declaration and the `fbUrl()` helper function.
- Delete `mergeServer`'s Firebase-specific handling only if it referenced `fbRef`; otherwise leave `mergeServer`/`handleSnapshot` intact (they operate on plain data).

- [ ] **Step 7: Strip Firebase from `index.html` and bump the version**

In `index.html`:
- Delete the `firebase-*.js` CDN `<script>` tags and the `<script src="js/firebase-config.js?v=5"></script>` line.
- Bump `js/app.js?v=26` to `js/app.js?v=27`.

- [ ] **Step 8: Delete the config file**

```bash
git rm js/firebase-config.js
```

- [ ] **Step 9: Syntax-check**

Run: `node --check js/app.js`
Expected: no output (exit 0).

- [ ] **Step 10: Commit**

```bash
git add js/app.js index.html
git commit -m "feat: client reads/writes via PHP endpoints; remove Firebase"
```

---

## Task 14: Client — password login UI

**Files:**
- Modify: `index.html` (login control + modal)
- Modify: `js/app.js` (login/logout handlers)

**Interfaces:**
- Consumes: `API_LOGIN`, `API_LOGOUT`, `checkSession` from Task 13.

- [ ] **Step 1: Ensure login/logout buttons exist in `index.html`**

Confirm `#btnLogin` and `#btnLogout` exist (they did in the Firebase version). If the Firebase removal deleted them, re-add near the header controls:

```html
<button id="btnLogin" class="btn small ghost">Войти</button>
<button id="btnLogout" class="btn small ghost" style="display:none">Выйти</button>
```

- [ ] **Step 2: Wire the login handler in `js/app.js`**

Add, in the init area (replacing any old Google-popup handlers):

```javascript
  const btnLogin  = $("#btnLogin");
  const btnLogout = $("#btnLogout");

  if (btnLogin) btnLogin.addEventListener("click", async () => {
    const pw = window.prompt("Пароль администратора:");
    if (!pw) return;
    try {
      const r = await fetch(API_LOGIN, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ password: pw }),
      });
      const d = await r.json().catch(() => ({}));
      if (r.ok && d.ok) { setAdminMode(true); fetchSnapshot(); }
      else { alert("Неверный пароль"); }
    } catch (e) { alert("Ошибка входа"); }
  });

  if (btnLogout) btnLogout.addEventListener("click", async () => {
    try { await fetch(API_LOGOUT, { method: "POST" }); } catch (e) {}
    setAdminMode(false);
  });
```

- [ ] **Step 3: Make `setAdminMode` toggle the buttons**

Confirm `setAdminMode(on)` shows/hides `#btnLogin`/`#btnLogout` and toggles edit controls. If it only handled edit mode before, add:

```javascript
    if (btnLogin)  btnLogin.style.display  = on ? "none" : "";
    if (btnLogout) btnLogout.style.display = on ? "" : "none";
```

- [ ] **Step 4: Syntax-check**

Run: `node --check js/app.js`
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add index.html js/app.js
git commit -m "feat: password login/logout UI"
```

---

## Task 15: Client — image upload flow

**Files:**
- Modify: `js/app.js` (image pick handlers + `compactState`)

**Interfaces:**
- Consumes: `API_UPLOAD` from Task 13.
- Produces: `uploadDataUrl(dataUrl): Promise<string>` — POSTs a data URL, returns the stored `/images/...` URL (or the original data URL on failure, so editing never hard-breaks offline).

- [ ] **Step 1: Add the upload helper in `js/app.js`**

```javascript
  // Upload a (shrunk) data URL to the server; return the stored file URL.
  async function uploadDataUrl(dataUrl) {
    if (typeof dataUrl !== "string" || dataUrl.indexOf("data:") !== 0) return dataUrl;
    try {
      const r = await fetch(API_UPLOAD, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ data: dataUrl }),
      });
      const d = await r.json().catch(() => ({}));
      if (r.ok && d.url) return d.url;
    } catch (e) { /* fall through */ }
    return dataUrl; // server-side extract_embedded_images is the backstop on save
  }
```

- [ ] **Step 2: Upload on image pick instead of embedding**

Find each place a picked image is stored (search `fileToSmallDataURL` / `shrinkDataURL` result assignments to `.icon`, `.logo`, `.ad.image`). After obtaining the shrunk data URL `du`, replace the direct assignment with an upload. Example for an item icon:

```javascript
    const du = await fileToSmallDataURL(file, 160, 0.85);
    it.icon = await uploadDataUrl(du);
    save(); render();
```

Apply the same `await uploadDataUrl(...)` wrapping for tier logo (`t.logo`) and the ad image (`state.ad.image`, using its existing max size).

- [ ] **Step 3: Repurpose `compactState` to upload leftovers before save**

Replace `compactState` with:

```javascript
  // Before publishing, upload any still-embedded (data:) images so the saved
  // blob carries only URLs. Server also extracts as a backstop.
  async function compactState() {
    for (const t of state.tiers) {
      if (typeof t.logo === "string" && t.logo.indexOf("data:") === 0) {
        t.logo = await uploadDataUrl(t.logo);
      }
      for (const it of t.items) {
        if (typeof it.icon === "string" && it.icon.indexOf("data:") === 0) {
          it.icon = await uploadDataUrl(it.icon);
        }
      }
    }
    if (state.ad && typeof state.ad.image === "string" && state.ad.image.indexOf("data:") === 0) {
      state.ad.image = await uploadDataUrl(state.ad.image);
    }
  }
```

- [ ] **Step 4: Syntax-check**

Run: `node --check js/app.js`
Expected: exit 0.

- [ ] **Step 5: Commit**

```bash
git add js/app.js
git commit -m "feat: upload picked images to files instead of embedding base64"
```

---

## Task 16: Local integration smoke (XAMPP / php -S)

**Files:**
- Create: `tests/run_all.sh` (convenience runner)
- Create: `docs/superpowers/DEPLOY.md` (deploy steps)

**Interfaces:** none — this task validates the whole system end-to-end locally.

- [ ] **Step 1: Add a runner for all unit tests**

Create `tests/run_all.sh`:

```bash
#!/usr/bin/env bash
set -e
for t in tests/*_test.php tests/harness_selfcheck.php; do
  echo "== $t =="
  php "$t"
done
echo "ALL UNIT TESTS PASSED"
```

- [ ] **Step 2: Run the full unit suite**

Run: `bash tests/run_all.sh`
Expected: every file prints `0 failures`; final line `ALL UNIT TESTS PASSED`.

- [ ] **Step 3: Create a local config + DB**

- Copy `config.sample.php` to `config.php`; set `dsn` to a local SQLite file for a quick smoke OR a local MySQL from XAMPP. For MySQL: create DB `nexus` in phpMyAdmin, run `schema.sql`.
- Generate an admin hash: `php -r "echo password_hash('test123', PASSWORD_BCRYPT), PHP_EOL;"` and paste into `config.php`.
- Set `images_dir` to the absolute path of `public_html/images`.

- [ ] **Step 4: Import migrated data**

- Ensure `tools/out/` exists (run `node tools/migrate.mjs` if not).
- Copy `tools/out/images/*` into `public_html/images/`.
- Load `tools/out/import.sql` into the MySQL DB (phpMyAdmin → Import, or `mysql nexus < tools/out/import.sql`).

- [ ] **Step 5: Serve and smoke-test the endpoints**

Start XAMPP Apache (docroot → `public_html`) OR run the PHP dev server:

Run: `php -S 127.0.0.1:8080 -t public_html`

Then in another shell:

```bash
# tierlist loads and is small
curl -s "http://127.0.0.1:8080/api/tierlist.php" | head -c 200; echo
# state
curl -s "http://127.0.0.1:8080/api/state.php"; echo
# like increments
curl -s -X POST "http://127.0.0.1:8080/api/like.php" -H "Content-Type: application/json" -d '{"dir":1}'; echo
# admin-gated save is blocked when logged out
curl -s -o /dev/null -w "%{http_code}\n" -X POST "http://127.0.0.1:8080/api/save.php" -H "Content-Type: application/json" -d '{"tiers":[]}'
```

Expected: tierlist JSON prints (no `data:image` substrings); `state.php` returns `{"rev":...,"likes":...}`; like returns `{"ok":true,"likes":N}` with N incrementing on repeat; save returns `401`.

- [ ] **Step 6: Browser verification**

Open `http://127.0.0.1:8080/` in the preview browser. Verify:
- Tier list renders with images loading from `/images/...` (check the Network tab: image requests, not base64).
- Console has no errors.
- Click "Войти", enter `test123` → admin controls appear.
- Edit something, Save → succeeds; a reload shows the change.
- Pick a new icon image → it uploads (Network shows POST `/api/upload.php` returning a `/images/...` url) and renders.

- [ ] **Step 7: Write the deploy doc**

Create `docs/superpowers/DEPLOY.md`:

```markdown
# Deploy to shared hosting (cPanel)

1. Create a MySQL database + user in cPanel; grant all privileges.
2. In phpMyAdmin, run `schema.sql`.
3. Upload the contents of `public_html/` to the host's web root.
4. Place `config.php` ABOVE the web root (e.g. one level up). Set production
   `dsn`, `db_user`, `db_pass`, a fresh `admin_hash`, and `images_dir` (absolute
   path to the uploaded `images/`). In `public_html/api/_bootstrap.php`, set
   `CONFIG_PATH` to that above-webroot path (or add an `auto_prepend_file` that
   `define()`s it).
5. Ensure `public_html/images/` is writable (755) by the web user.
6. Upload the migrated images: copy `tools/out/images/*` into `images/`.
7. Import data: run `tools/out/import.sql` in phpMyAdmin.
8. Point the free domain at the host; enable SSL (cPanel → SSL/TLS).
9. Smoke-test: load the site, verify images load from /images, test like,
   login, save, and an image upload.
```

- [ ] **Step 8: Commit**

```bash
git add tests/run_all.sh docs/superpowers/DEPLOY.md
git commit -m "test: full-suite runner + local smoke + deploy doc"
```

---

## Self-Review

**Spec coverage:**
- Target architecture / file layout → Tasks 2–11, File Structure section. ✓
- MySQL schema (2 singleton tables) → Task 1 (`schema.sql`), `test_db()`. ✓
- `state.php`, `tierlist.php` (rev cache), `like.php` (atomic), `save.php` (silent extract + server rev), `upload.php`, `login/logout/session.php` → Tasks 5–10. ✓
- Silent server-side image extraction on save → Task 9 (`handle_save` + `extract_embedded_images`). ✓
- Images as static files, content-hash dedup → Task 3, Task 11 caching, Task 12 migration. ✓
- Client: remove Firebase, `.php` endpoints, password auth, upload flow → Tasks 13–15. ✓
- Security: config above webroot, `.htaccess` (HTTPS, deny, no-exec images), prepared statements, secure session cookies, same-origin → Tasks 2, 8, 11; DEPLOY.md. ✓
- One-time migration from Firebase blob → Task 12. ✓
- Local XAMPP dev + testing plan + deploy steps → Task 16. ✓

**Placeholder scan:** No TBD/TODO; every code step contains full code; commands have expected output. ✓

**Type consistency:** `handle_like/handle_state/handle_tierlist/handle_save/handle_upload` all return `[int, array]`; `save_image_bytes`→URL string; `extract_embedded_images(array,string)→array`; `data_url_to_bytes(string)→?string`; `validate_state($state,int)→['ok'=>bool,'error'=>string]`; client `API_*` constants defined in Task 13 and consumed in 13–15; `uploadDataUrl`/`compactState`/`checkSession` defined before use. ✓
