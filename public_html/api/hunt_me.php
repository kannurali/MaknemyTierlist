<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/hunt.php';

// Моё в ивенте — GET /api/hunt_me.php → {"ok":true, "hunt_id", "wallet",
// "done", "found", "tickets", "wins", "tg", "next", "day", "next_day_at"}.
// Гостю — 401 {"status":"login"}. Не кешируется: ответ у каждого свой.

function handle_hunt_me(PDO $pdo, array $cfg, string $me, int $now): array {
    if ($me === '') { return hunt_fail(401, 'login'); }
    return [200, ['ok' => true] + hunt_me($pdo, $cfg, $me, $now)];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    $session = resume_site_session() ? $_SESSION : [];
    $me = chat_me($session);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    try {
        [$code, $body] = handle_hunt_me(db(), app_config(), $me, time());
    } catch (Throwable $e) {
        error_log('hunt_me: ' . $e->getMessage());
        [$code, $body] = hunt_fail(503, 'closed');
    }
    json_out($body, $code);
}
