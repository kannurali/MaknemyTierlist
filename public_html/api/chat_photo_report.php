<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/telegram.php';

// Жалоба на фото — POST /api/chat_photo_report.php {"id":N}. Модераторам уходит
// сигнал в Telegram: сама картинка в бота не попадает, только ссылка на панель.

if (!defined('TESTING')) {
    require_post();
    header('Cache-Control: no-store');
    start_site_session();

    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('chat_photo_report', $me, 30, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    [$status, $payload] = chat_photo_report(db(), $me, read_row_id(read_json_body()), time());
    json_out($payload, $status);

    if ($status === 200 && !empty($payload['first'])) {
        tg_after_photo_report(db(), app_config(), $me);
    }
}
