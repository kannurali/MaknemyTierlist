<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Розыгрыши для окна (js/giveaway-pop.js) и страницы /giveaways —
// GET /api/giveaways.php → {"live": […], "past": […]}.
//
// Ответ одинаков для всех, поэтому лежит в кеше LiteSpeed: окно спрашивает
// его на каждой странице сайта, и без кеша это был бы PHP и MySQL на каждый
// просмотр. Тег giveaways сбрасывает админка (api/giveaway_admin.php), так
// что публикация и итоги видны сразу; число участников отстаёт не больше
// чем на GW_PUBLIC_CACHE секунд. Где участвует сам человек, окно узнаёт из
// api/session.php — тот не кешируется.
//
// Параметр один — ?test=1: вместе с тестовыми розыгрышами, для браузера,
// открывшего /giveaways?test. Это второй адрес в кеше, и только. Любой
// другой хвост .htaccess отбивает 403, иначе флуд случайными адресами шёл
// бы мимо кеша.

const GW_PUBLIC_CACHE = 30;

function handle_giveaways(callable $pdo, int $now, bool $withTest = false): array {
    try {
        $conn = $pdo();
        if (!gw_ready($conn)) { return ['live' => [], 'past' => []]; }
        return gw_public_list($conn, $now, $withTest);
    } catch (Throwable $e) {
        error_log('giveaways: ' . $e->getMessage());
        return ['live' => [], 'past' => []];
    }
}

if (!defined('TESTING')) {
    header('Cache-Control: no-cache, must-revalidate');
    header('X-LiteSpeed-Tag: giveaways');
    lscache_public(GW_PUBLIC_CACHE);
    json_out(handle_giveaways('db', time(), ($_GET['test'] ?? '') === '1'));
}
