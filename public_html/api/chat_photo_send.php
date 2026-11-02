<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/trade.php';
require_once __DIR__ . '/lib/telegram.php';

// Отправка фото — POST /api/chat_photo_send.php, multipart: thread, photo[, offer].
//
// Multipart, а не JSON с base64: снимок и так весит мегабайт, а base64 добавил бы
// к нему треть. Подделать такую форму с чужого сайта нельзя: кука сессии
// SameSite=Lax на межсайтовый POST не идёт.
//
// offer — как в chat_send.php: объявление, из карточки которого открыли чат.

// Байты из $_FILES или код ошибки. is_uploaded_file — не верим пути из запроса.
function chat_photo_upload_bytes(?array $file, array $server): array {
    if (!$file) {
        // Тело больше post_max_size: PHP молча выбросил и файл, и все поля.
        return (int)($server['CONTENT_LENGTH'] ?? 0) > 0 && empty($_POST)
            ? [null, 'too_big'] : [null, 'no_photo'];
    }
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { return [null, 'too_big']; }
    if ($err !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'no_photo'];
    }
    if ((int)filesize($file['tmp_name']) > CHAT_PHOTO_MAX_BYTES) { return [null, 'too_big']; }
    $bytes = file_get_contents($file['tmp_name']);
    return $bytes === false || $bytes === '' ? [null, 'no_photo'] : [$bytes, ''];
}

if (!defined('TESTING')) {
    require_post();
    header('Cache-Control: no-store');
    start_site_session();

    $me = chat_me($_SESSION);
    if ($me === '') {
        json_out(['ok' => false, 'error' => 'not_logged_in'], 401);
        exit;
    }
    // Суточный лимит считает только дошедшие фото, этот — ещё и отказы: каждая
    // попытка декодирует картинку, а ядро на тарифе одно.
    if (!rate_limit_allow('chat_photo', $me, 60, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    [$bytes, $err] = chat_photo_upload_bytes(
        isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : null,
        $_SERVER
    );
    if ($bytes === null) {
        json_out(['ok' => false, 'error' => $err], 400);
        exit;
    }

    $digits = function (string $key): int {
        $raw = $_POST[$key] ?? '';
        return is_string($raw) && $raw !== '' && ctype_digit($raw) ? (int)$raw : 0;
    };
    $thread = $digits('thread');
    $offer  = $digits('offer');

    [$status, $payload] = chat_photo_send(db(), $me, $thread, $bytes, chat_photo_dir(app_config()), time());
    if ($status === 200 && $offer > 0) {
        trade_note_reply(db(), $me, $thread, $offer, time());
    }
    json_out($payload, $status);

    if ($status === 200) {
        tg_after_chat_send(db(), app_config(), $me, $thread, (int)$payload['message']['id'], time());
    }
}
