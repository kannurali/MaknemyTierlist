<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Вкладка «Розыгрыши» (/admin/giveaways, js/giveaway-admin.js) —
// POST /api/giveaway_admin.php {"action": …}. Только admin_ids.
//
//   list            → розыгрыши, каналы, имя бота
//   get {id}        → розыгрыш целиком с участниками
//   report {id}     → для рекламодателя: источники участников и кто
//                     подписался на каналы по ссылке розыгрыша
//   save {id?, …}   → сохранить черновик или правку (пустой EN переводит Google)
//   publish {id}    → старт; бот пишет всем после ответа
//   end {id}        → закончить досрочно
//   draw {id}       → выбрать победителей; бот пишет им после ответа
//   delete {id}     → удалить вместе с участниками
//   channel {id}    → перечитать канал у Telegram (название, права, ссылку)
//
// Всё, что меняет публичную картину, сбрасывает кеш api/giveaways.php
// (тег giveaways), иначе окно ещё полминуты показывало бы старое.

/**
 * [код, тело, что сделать после ответа]. Третье — null или
 * ['announce', строка] / ['winners', строка, победители].
 */
function handle_gw_admin(PDO $pdo, array $cfg, callable $send, array $body, int $now, ?callable $translate = null): array {
    if (!gw_ready($pdo)) { return [503, ['ok' => false, 'error' => 'not_ready'], null]; }
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
    $tg = tg_config($cfg);

    if ($action === 'list') {
        return [200, [
            'ok'        => true,
            'giveaways' => gw_admin_list($pdo, $now),
            'channels'  => gw_admin_channels($pdo),
            'bot'       => $tg['name'],
            'tg'        => tg_enabled($tg),
            'rights'    => tg_right_names(tg_admin_rights(true), 'ru'),
            'add_url'   => tg_add_channel_url($tg['name']),
        ], null];
    }
    if ($action === 'save') {
        [$s, $p] = gw_save($pdo, $body, $now, $translate);
        // Идущему розыгрышу добавили канал — ему тоже нужна своя ссылка.
        $row = $s === 200 ? gw_load($pdo, (int)$p['id']) : null;
        if ($row && $row['status'] === 'live') { $p['link_failed'] = gw_ensure_links($pdo, $send, $row, $now); }
        return [$s, $p, null];
    }
    if ($action === 'channel') {
        $chat = gw_chat_id($body['id'] ?? null);
        if ($chat === null) { return [400, ['ok' => false, 'error' => 'bad id'], null]; }
        if (!tg_enabled($tg)) { return [503, ['ok' => false, 'error' => 'tg_off'], null]; }
        $ok = gw_channel_refresh($pdo, $tg, $send, $chat, $now);
        return [$ok ? 200 : 502, ['ok' => $ok, 'channels' => gw_admin_channels($pdo)], null];
    }

    $id = read_row_id($body);
    if ($id <= 0) { return [400, ['ok' => false, 'error' => 'bad id'], null]; }

    if ($action === 'get') {
        $row = gw_load($pdo, $id);
        if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], null]; }
        return [200, ['ok' => true, 'giveaway' => gw_admin_item($pdo, $row, $now)], null];
    }
    if ($action === 'report') {
        $row = gw_load($pdo, $id);
        if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], null]; }
        return [200, ['ok' => true, 'report' => gw_report($pdo, $row)], null];
    }
    if ($action === 'publish') {
        [$s, $p, $row] = gw_publish($pdo, $id, $now, $send, tg_enabled($tg) ? (int)strtok((string)$tg['token'], ':') : 0);
        // О тестовом бот не пишет никому.
        return [$s, $p, $row && !gw_is_test($row) ? ['announce', $row] : null];
    }
    if ($action === 'end') {
        [$s, $p] = gw_end($pdo, $id, $now);
        return [$s, $p, null];
    }
    if ($action === 'draw') {
        if (!tg_enabled($tg)) { return [503, ['ok' => false, 'error' => 'tg_off'], null]; }
        [$s, $p, $winners, $gone] = gw_draw($pdo, $cfg, $send, $id, $now);
        return [$s, $p, $s === 200 ? ['winners', gw_load($pdo, $id), $winners, $gone] : null];
    }
    if ($action === 'delete') {
        [$s, $p] = gw_delete($pdo, $id);
        if ($s === 200) { i18n_forget($pdo, 'gw', (string)$id); }
        return [$s, $p, null];
    }
    return [400, ['ok' => false, 'error' => 'bad action'], null];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    require_admin();

    $cfg  = app_config();
    $tg   = tg_config($cfg);
    $body = read_json_body();
    $send = tg_enabled($tg) ? tg_http($tg['token']) : function (string $m, array $p): ?array { return null; };
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';

    // Розыгрыш победителей ходит в Telegram за каждого кандидата.
    if ($action === 'draw') { tg_long_run(); }

    [$status, $payload, $after] = handle_gw_admin(db(), $cfg, $send, $body, time(), 'tr_google_request');
    if ($status === 200 && !in_array($action, ['list', 'get', 'channel', 'report'], true)) {
        header('X-LiteSpeed-Purge: tag=giveaways');
    }
    json_out($payload, $status);

    if ($after && $after[0] === 'announce') { gw_after_publish(db(), $cfg, $after[1]); }
    if ($after && $after[0] === 'winners' && $after[1]) { gw_after_draw(db(), $cfg, $after[1], $after[2], null, null, $after[3]); }
}
