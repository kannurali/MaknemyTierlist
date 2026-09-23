<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/support.php';
require_once __DIR__ . '/lib/telegram.php';

// Обращение в поддержку — POST /api/support.php {"body":"..."}

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Пять обращений в час на человека: этого хватает, чтобы дописать забытое,
    // и не хватает, чтобы завалить список администратора.
    $me = profile_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('support', $me, 5, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    $body = read_json_body();
    [$status, $payload] = support_submit(db(), $me, $body['body'] ?? '', time());
    json_out($payload, $status);

    // Модераторам в Telegram — после ответа, как и уведомления чата.
    if ($status === 200) {
        tg_after_support(db(), app_config(), $me);
    }
}
