<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/trade.php';
require_once __DIR__ . '/lib/telegram.php';

// Отправка сообщения — POST /api/chat_send.php {"thread":N,"body":"..."[,"offer":N]}
//
// Стикер уходит тем же путём: body = "[sticker:<id предмета>]" (CHAT_STICKER_RE
// в api/lib/chat.php), и предмет обязан быть в текущем тирлисте.
//
// offer — объявление с /trading, из карточки которого открыли этот чат. Первое
// сообщение по нему засчитывается как отклик (trade_note_reply), и объявление
// не уходит из ленты через четыре дня.

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

    $offer  = isset($body['offer']) && is_int($body['offer']) ? $body['offer'] : 0;

    [$status, $payload] = chat_send(db(), $me, $thread, $text, time());
    if ($status === 200 && $offer > 0) {
        trade_note_reply(db(), $me, $thread, $offer, time());
    }
    json_out($payload, $status);

    // Уведомление собеседнику в Telegram — уже после ответа: отправитель
    // своё сообщение видит сразу, сколько бы ни думал Telegram.
    if ($status === 200) {
        tg_after_chat_send(db(), app_config(), $me, $thread, (int)$payload['message']['id'], time());
    }
}
