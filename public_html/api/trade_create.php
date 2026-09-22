<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/trade.php';

// Публикация объявления — POST /api/trade_create.php {"give":[id…],"want":[id…]}

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Предел по ВОШЕДШЕМУ, а не по адресу — как у чата: за одним IP сидит
    // целый интернет-клуб. Потолок открытых объявлений держит trade_create,
    // этот — частоту: снять и выложить заново сто раз подряд тоже спам.
    $me = trade_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('trade_create', $me, 20, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    $body = read_json_body();
    [$status, $payload] = trade_create(db(), $me, $body['give'] ?? null, $body['want'] ?? null, time());
    json_out($payload, $status);
}
