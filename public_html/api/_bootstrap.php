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

// State-changing endpoints must be POST. Without this a bare GET — an <img>
// tag on someone else's page, a link-preview crawler, a scanner walking the
// URLs visible in app.js — would mutate data.
function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['error' => 'method_not_allowed'], 405);
        exit;
    }
}

// ---------------------------------------------------------------------------
//  Login throttle — brute-force guard keyed by client IP.
//  State is a small JSON file in the system temp dir: no DB table, works on
//  any shared host. A flat per-request sleep alone is near-useless against a
//  parallel attack, so failures also escalate into a timed lockout.
// ---------------------------------------------------------------------------
function throttle_file(string $key): string {
    return rtrim(sys_get_temp_dir(), "/\\") . '/nexus_login_' . sha1($key) . '.json';
}

function throttle_read(string $key): array {
    $f = throttle_file($key);
    if (!is_file($f)) { return ['fails' => 0, 'until' => 0]; }
    $d = json_decode((string)file_get_contents($f), true);
    return [
        'fails' => (int)($d['fails'] ?? 0),
        'until' => (int)($d['until'] ?? 0),
    ];
}

// Seconds the caller must wait before another attempt is accepted (0 = now).
function throttle_retry_after(string $key, int $now): int {
    $s = throttle_read($key);
    return max(0, $s['until'] - $now);
}

// Record a failed attempt; locks out once $maxFails is reached. Returns the
// new failure count. A lock that has already expired resets the counter, so a
// later typo costs one attempt rather than an instant re-lock.
function throttle_register_failure(string $key, int $now, int $maxFails = 5, int $lockSeconds = 300): int {
    $s = throttle_read($key);
    $expired = $s['until'] > 0 && $s['until'] <= $now;
    $fails = $expired ? 1 : $s['fails'] + 1;
    $until = $fails >= $maxFails ? $now + $lockSeconds : 0;
    @file_put_contents(throttle_file($key), json_encode(['fails' => $fails, 'until' => $until]));
    return $fails;
}

function throttle_clear(string $key): void {
    $f = throttle_file($key);
    if (is_file($f)) { @unlink($f); }
}

// ---------------------------------------------------------------------------
//  Sliding-window rate limit — caps how often one client may hit an endpoint
//  (the login throttle above punishes FAILURES; this caps raw frequency).
//  Same storage approach: a JSON file per bucket+key in the temp dir, so it
//  needs no DB table and works on any shared host. Failing open on file I/O
//  errors is deliberate — a broken temp dir should not take the feature down.
// ---------------------------------------------------------------------------
function rate_file(string $bucket, string $key): string {
    return rtrim(sys_get_temp_dir(), "/\\") . '/nexus_rate_' . sha1($bucket . '|' . $key) . '.json';
}

// True → allowed (and the hit is recorded); false → over the limit.
function rate_limit_allow(string $bucket, string $key, int $max, int $windowSeconds, int $now): bool {
    $f = rate_file($bucket, $key);
    $hits = [];
    if (is_file($f)) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) { $hits = $d; }
    }
    // keep only hits still inside the window
    $cutoff = $now - $windowSeconds;
    $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $cutoff));
    if (count($hits) >= $max) { return false; }
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits));
    return true;
}
