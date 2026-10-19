<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/hunt.php';

// Обезличенные шаги воронки ивента — POST /api/hunt_stat.php
// {"m": ["page_v", "invite_v", …]} → 204. Метрики — HUNT_METRICS в
// api/lib/hunt.php. Шаги вошедших (первая тыковка, первый билет, вернулся)
// считаются из кошельков и журнала, здесь — то, что видно и гостю.
//
// Ответа страница не читает, учёт не должен мешать переходу. Лимит на адрес,
// как у gw_stat.php: 600 в час.

const HUNT_STAT_PER_HOUR = 600;

function handle_hunt_stat(PDO $pdo, array $body, int $now): int {
    $m = $body['m'] ?? null;
    if (!is_array($m) || !$m || !hunt_ready($pdo)) { return 0; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt || hunt_phase($hunt, $now) === 'draft') { return 0; }
    return hunt_track($pdo, (int)$hunt['id'], array_slice(array_values($m), 0, count(HUNT_METRICS)), $now);
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    if (!rate_limit_allow('hunt_stat', client_key(), HUNT_STAT_PER_HOUR, 3600, time())) {
        http_response_code(429);
        exit;
    }
    try { handle_hunt_stat(db(), read_json_body(), time()); } catch (Throwable $e) { error_log('hunt_stat: ' . $e->getMessage()); }
    http_response_code(204);
}
