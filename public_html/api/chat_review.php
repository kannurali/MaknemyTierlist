<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Отзыв о собеседнике — POST /api/chat_review.php {"thread":N,"stars":1..5,"body":"..."}
//
// Форма стоит внутри чата, как в макете: репутация набирается по итогам
// переписки со сделкой, и отсюда берутся счётчики на странице профиля.

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Жёстче, чем у сообщений: отзыв в диалоге один и правится редко.
    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('chat_review', $me, 30, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    $body   = read_json_body();
    $thread = isset($body['thread']) ? (int)$body['thread'] : 0;
    $stars  = isset($body['stars']) ? (int)$body['stars'] : 0;
    $text   = isset($body['body']) && is_string($body['body']) ? $body['body'] : '';

    [$status, $payload] = chat_review(db(), $me, $thread, $stars, $text, time());
    json_out($payload, $status);
}
