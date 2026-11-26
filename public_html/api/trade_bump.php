<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/trade.php';

// Поднять своё объявление наверх ленты — POST /api/trade_bump.php {"id":N}
// Каждое — не чаще раза в TRADE_BUMP_EVERY (api/lib/trade.php, trade_bump).

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Пауза между подъёмами держится на каждом объявлении, этот предел — на
    // частоту запросов вообще: кнопку, зажатую скриптом, база не почувствует.
    $me = trade_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('trade_bump', $me, 60, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    $body = read_json_body();
    [$status, $payload] = trade_bump(db(), $me, read_row_id($body), time());
    json_out($payload, $status);
}
