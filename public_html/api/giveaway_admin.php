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
//   save {id?, …}   → сохранить черновик или правку (пустой EN переводит Google);
//                     у идущего бот сразу выпускает и правит посты в каналах
//   publish {id}    → старт; посты в каналы — до ответа, рассылка — после
//   end {id}        → закончить досрочно
//   draw {id}       → выбрать победителей и дописать их в посты каналов;
//                     победителям бот пишет после ответа
//
// Как прошли посты в каналах (save, publish), — поле post в ответе:
// {posted, edited, failed: [канал, …]}; нет постов — поля нет.
//   delete {id}     → удалить вместе с участниками
//   channel {id}    → перечитать канал у Telegram (название, права, ссылку)
//   emoji {ids}     → премиум-эмодзи бота: {место: id или ''}
//
// Всё, что меняет публичную картину, сбрасывает кеш api/giveaways.php
// (тег giveaways), иначе окно ещё полминуты показывало бы старое.

// Итог постов в каналах — в ответ админке, если постов было что делать.
function gw_admin_post_note(array $p, array $sync): array {
    if ($sync['posted'] || $sync['edited'] || $sync['failed']) { $p['post'] = $sync; }
    return $p;
}

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
            'posts'     => gw_has_posts($pdo),
            'post_last' => gw_post_last($pdo),
            'emoji'     => gw_admin_emoji($pdo),
        ], null];
    }
    if ($action === 'emoji') {
        [$s, $p] = gw_emoji_save($pdo, $body['ids'] ?? null, $now);
        return [$s, $p, null];
    }
    if ($action === 'save') {
        [$s, $p] = gw_save($pdo, $body, $now, $translate);
        // Идущему розыгрышу добавили канал — ему тоже нужна своя ссылка.
        $row = $s === 200 ? gw_load($pdo, (int)$p['id']) : null;
        if ($row && $row['status'] === 'live') {
            $p['link_failed'] = gw_ensure_links($pdo, $send, $row, $now);
            $p = gw_admin_post_note($p, gw_post_sync($pdo, $send, $row, (string)$tg['name'], $now));
        }
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
        if ($row) { $p = gw_admin_post_note($p, gw_post_sync($pdo, $send, $row, (string)$tg['name'], $now)); }
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
        if ($s !== 200) { return [$s, $p, null]; }
        $row = gw_load($pdo, $id);
        // Не дописалось (Telegram молчал) — допишет следующий проход автоитогов.
        $failed = gw_post_results($pdo, $send, $row, $now);
        if ($failed) { $p['post_failed'] = $failed; }
        return [$s, $p, ['winners', $row, $winners, $gone]];
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

    // Восемь языков сайта — после ответа, как и у новостей. EN уже
    // переведён внутри сохранения (gw_save()). Кеш выдачи при этом не
    // сбрасывается — заголовок ушёл с ответом; переводы появятся у
    // посетителей не позже чем через GW_PUBLIC_CACHE секунд.
    if ($action === 'save' && $status === 200) {
        $id = (int)$payload['id'];
        i18n_after_save(function (float $deadline) use ($id) {
            gw_i18n_job(db(), $id, 'i18n_google_for', $deadline);
        });
    }
}
