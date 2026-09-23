<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/trade.php';

// Закрытие объявления — POST /api/trade_close.php {"id":N,"result":"done"|"cancel"|"remove"}
// done/cancel — автор, remove — администратор (модерация).

if (!defined('TESTING')) {
    require_post();
    start_site_session();
    $body   = read_json_body();
    $result = isset($body['result']) && is_string($body['result']) ? $body['result'] : '';
    [$status, $payload] = trade_close(db(), trade_me($_SESSION), is_admin(), read_row_id($body), $result, time());
    json_out($payload, $status);
}
