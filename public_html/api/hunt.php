<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/hunt.php';

// Ивент Spooky Tokens для страницы /halloween и счётчика на всех страницах —
// GET /api/hunt.php → {"hunt": {…}, "winners": […]} или {"hunt": null}.
//
// Ответ одинаков для всех, поэтому лежит в кеше LiteSpeed: гостю счётчик
// спрашивает его на каждой странице сайта. Параметров нет — любой хвост
// .htaccess отбивает 403. Тег hunt сбрасывает админка. Личное (кошелёк,
// календарь, билеты) — api/hunt_me.php, без кеша.

const HUNT_PUBLIC_CACHE = 30;

function handle_hunt(callable $pdo, int $now, array $cfg = []): array {
    try {
        return hunt_public($pdo(), $now, $cfg);
    } catch (Throwable $e) {
        error_log('hunt: ' . $e->getMessage());
        return ['hunt' => null];
    }
}

if (!defined('TESTING')) {
    header('Cache-Control: no-cache, must-revalidate');
    header('X-LiteSpeed-Tag: hunt');
    lscache_public(HUNT_PUBLIC_CACHE);
    json_out(handle_hunt('db', time(), app_config()));
}
