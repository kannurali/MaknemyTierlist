<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';

// Вебхук бота — сюда Telegram присылает всё, что люди пишут боту.
// Адрес и секрет сообщает Telegram кнопка на /admin/support (api/tg_setup.php).
//
// Без верного секрета в заголовке X-Telegram-Bot-Api-Secret-Token запрос
// отвергается: иначе любой, кто знает адрес, прислал бы «/start <код>» от
// имени произвольного chat_id.
//
// Отвечаем всегда 200 и сразу: на любой другой код Telegram повторяет
// доставку, и одна ошибка базы превращалась бы в очередь повторов. Ответ на
// сообщение уходит прямо в теле — Telegram выполняет его как вызов API.

function tg_webhook_authorized(array $tg, array $server): bool {
    if (!tg_enabled($tg)) { return false; }
    $got = $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    return is_string($got) && $got !== '' && hash_equals(tg_webhook_secret($tg['token']), $got);
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();

    $tg = tg_config(app_config());
    if (!tg_webhook_authorized($tg, $_SERVER)) {
        json_out(['ok' => false], 403);
        exit;
    }

    $reply = null;
    try {
        $reply = tg_handle_update(db(), read_json_body(), time());
    } catch (Throwable $e) {
        error_log('tg webhook: ' . $e->getMessage());
    }
    json_out($reply ?? ['ok' => true]);
}
