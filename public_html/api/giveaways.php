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
//
// Этот же запрос подводит итоги: есть закончившийся розыгрыш без итогов —
// ответ уходит некешированным (иначе победители появились бы на полминуты
// позже), а после него PHP разыгрывает призы и поздравляет победителей
// (gw_after_due() в api/lib/giveaway.php). Отдельного cron для итогов нет.
// Тем же ходом уходят напоминания «до итогов осталось…» тем, кто их выбрал
// в настройках уведомлений (gw_after_remind()), и понемногу перепроверяется
// подписка участников — отписавшимся бот пишет, что участие снято
// (gw_after_sweep()).

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
    $now = time();
    $due = false;
    try { $due = gw_has_due(db(), $now); } catch (Throwable $e) { $due = false; }
    if ($due) { lscache_off(); } else { lscache_public(GW_PUBLIC_CACHE); }
    json_out(handle_giveaways('db', $now, ($_GET['test'] ?? '') === '1'));
    if ($due) { gw_after_due(db(), app_config(), $now); }
    try { $remind = gw_has_remind(db(), time()); } catch (Throwable $e) { $remind = false; }
    if ($remind) { gw_after_remind(db(), app_config(), time()); }
    gw_after_sweep(db(), app_config(), time());
}
