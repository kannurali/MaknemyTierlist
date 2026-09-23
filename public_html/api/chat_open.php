<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Открыть диалог с человеком — POST /api/chat_open.php {"to":"<roblox_id>"}
//
// POST, а не GET с параметром: ручка ЗАВОДИТ строку в базе, а состояние не
// должно меняться от того, что чужая страница подсунула браузеру ссылку.
// Отсюда же и require_post().

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Предел по ВОШЕДШЕМУ, а не по адресу: за одним IP сидит целый
    // интернет-клуб. Порог низкий — открыть диалог это разовое действие, а не
    // то, что делают сотнями.
    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('chat_open', $me, 30, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    header('Cache-Control: no-store');
    $body = read_json_body();
    $to   = isset($body['to']) && (is_string($body['to']) || is_int($body['to']))
        ? (string)$body['to'] : '';

    [$status, $payload] = chat_open(db(), $me, $to, time());
    json_out($payload, $status);
}
