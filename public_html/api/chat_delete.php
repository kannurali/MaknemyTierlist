<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Удалить диалог у себя — POST /api/chat_delete.php {"thread":N,"upto":M}
//
// Одностороннее: переписка пропадает только у того, кто удалил, у
// собеседника она остаётся (chat_clear, chat_cleared в api/lib/chat.php).
// upto — номер последнего сообщения, которое человек видел; всё новее
// остаётся у него на месте.

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Предел по вошедшему, как у отправки: удаление — дело редкое, и сотни
    // запросов в час означают скрипт, а не человека.
    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('chat_delete', $me, 60, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    header('Cache-Control: no-store');
    $body   = read_json_body();
    $thread = isset($body['thread']) && is_int($body['thread']) ? $body['thread'] : 0;
    $upto   = isset($body['upto']) && is_int($body['upto']) ? $body['upto'] : null;

    [$status, $payload] = chat_clear(db(), $me, $thread, $upto, time());
    json_out($payload, $status);
}
