<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Отправка сообщения — POST /api/chat_send.php {"thread":N,"body":"..."}

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Предел на частоту считаем по ВОШЕДШЕМУ, а не по адресу: за одним IP
    // сидит целый интернет-клуб, и общий счётчик наказывал бы соседей за
    // чужую активность. Не вошёл — до предела дело не дойдёт, chat_send
    // ответит 401.
    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('chat_send', $me, 120, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    $body   = read_json_body();
    $thread = isset($body['thread']) ? (int)$body['thread'] : 0;
    $text   = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';

    [$status, $payload] = chat_send(db(), $me, $thread, $text, time());
    json_out($payload, $status);
}
