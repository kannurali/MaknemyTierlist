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
// Параметры: ?test=1 — вместе с тестовыми розыгрышами, для браузера,
// открывшего /giveaways?test; ?lang=<язык> — тексты на одном языке страницы
// (api/lib/i18n_content.php), всегда последним: test=1&lang=de. Без lang —
// прежний ответ на двух языках. Любой другой хвост .htaccess отбивает 403,
// иначе флуд случайными адресами шёл бы мимо кеша.
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

function handle_giveaways(callable $pdo, int $now, bool $withTest = false, ?string $lang = null): array {
    try {
        $conn = $pdo();
        if (!gw_ready($conn)) { return ['live' => [], 'past' => []]; }
        $list = gw_public_list($conn, $now, $withTest);
        return $lang === null ? $list : gw_localize_list($conn, $list, $lang);
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
    // Итоги, не дописанные в пост канала (Telegram молчал), — тем же проходом.
    try { $due = gw_has_due(db(), $now) || gw_has_post_due(db()); } catch (Throwable $e) { $due = false; }
    if ($due) { lscache_off(); } else { lscache_public(GW_PUBLIC_CACHE); }
    json_out(handle_giveaways('db', $now, ($_GET['test'] ?? '') === '1', i18n_api_lang($_GET['lang'] ?? null)));
    if ($due) { gw_after_due(db(), app_config(), $now); }
    try { $remind = gw_has_remind(db(), time()); } catch (Throwable $e) { $remind = false; }
    if ($remind) { gw_after_remind(db(), app_config(), time()); }
    gw_after_sweep(db(), app_config(), time());
}
