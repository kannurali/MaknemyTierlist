<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/hunt.php';

// Ивент «Тыквенная охота» для страницы /halloween и индикатора на всех
// страницах — GET /api/hunt.php[?page=<ключ>] → {"hunt": …, "chapters",
// "tasks", "journal", "secrets", "winners"} или {"hunt": null}.
//
// Ответ одинаков для всех, поэтому лежит в кеше LiteSpeed: индикатор
// спрашивает его на каждой странице сайта. ?page= — секретки этой страницы,
// ключи из HUNT_SPOTS; это по адресу в кеше на страницу, и только. Любой
// другой хвост .htaccess отбивает 403. Тег hunt сбрасывает админка.
// Личное (кошелёк, выполненное, билеты) — api/hunt_me.php, без кеша.

const HUNT_PUBLIC_CACHE = 30;

function handle_hunt(callable $pdo, int $now, string $page = ''): array {
    try {
        return hunt_public($pdo(), $now, isset(HUNT_SPOTS[$page]) ? $page : '');
    } catch (Throwable $e) {
        error_log('hunt: ' . $e->getMessage());
        return ['hunt' => null];
    }
}

if (!defined('TESTING')) {
    header('Cache-Control: no-cache, must-revalidate');
    header('X-LiteSpeed-Tag: hunt');
    lscache_public(HUNT_PUBLIC_CACHE);
    $page = $_GET['page'] ?? '';
    json_out(handle_hunt('db', time(), is_string($page) ? $page : ''));
}
