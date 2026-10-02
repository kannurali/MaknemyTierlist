<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/stock.php';

// Отметки «напиши, когда в стоке» — POST /api/stock_watch.php
//   {"action":"get"}                          → {"ok":true,"watch":["kitsune",…],"tg":{on, linked, name, mod}}
//   {"action":"set","fruit":"kitsune","on":true} → {"ok":true,"watch":[…]}
//
// tg в ответе на get — чтобы страница сразу знала, звать ли подключить
// Telegram: без него отметки ни к чему. Подключение идёт через
// api/tg_link.php, как колокольчик в профиле.

function handle_stock_watch(PDO $pdo, array $cfg, string $me, array $body): array {
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if ($action === 'get') {
        $watch = stock_watch_get($pdo, $me);
        if ($watch === null) { return [503, ['ok' => false, 'error' => 'not_ready']]; }
        return [200, ['ok' => true, 'watch' => $watch, 'tg' => tg_status($pdo, tg_config($cfg), $me)]];
    }
    if ($action === 'set') { return stock_watch_set($pdo, $me, $body); }
    return [400, ['ok' => false, 'error' => 'bad_action']];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    start_site_session();

    $me   = chat_me($_SESSION);
    $body = read_json_body();
    // Одна запись на нажатие звёздочки. 240 в час — это отметить и снять все
    // сорок фруктов по три раза, а таблицу перебором не раздуть: у человека
    // их не больше STOCK_WATCH_MAX.
    if ($me !== '' && ($body['action'] ?? '') === 'set' && !rate_limit_allow('stock_watch', $me, 240, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    [$status, $payload] = handle_stock_watch(db(), app_config(), $me, $body);
    json_out($payload, $status);
}
