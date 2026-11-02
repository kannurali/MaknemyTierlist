<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/hunt.php';

// Моё в ивенте — GET /api/hunt_me.php → {"ok":true, "hunt_id", "wallet",
// "day", "today", "days", "tickets", "wins", "titles", "tg", "next_day_at"}.
// Гостю — 401 {"status":"login"}, забаненному — 403 {"status":"banned"}:
// страница пишет «аккаунт заблокирован» вместо кнопок. Не кешируется: ответ у
// каждого свой.

function handle_hunt_me(PDO $pdo, array $cfg, string $me, int $now): array {
    if ($me === '') { return hunt_fail(401, 'login'); }
    if (site_banned($me, $cfg, $now, $pdo)) { return hunt_fail(403, 'banned'); }
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
