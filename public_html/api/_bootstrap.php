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
