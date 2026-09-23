<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';

// Колокольчик в чате — POST /api/tg_link.php
//   {"action":"link","lang":"ru"} → {"ok":true,"url":"https://t.me/<бот>?start=<код>"}
//   {"action":"unlink"}           → {"ok":true}
//
// Код в ответе читает только наша страница: чужой сайт ответ не прочитает, а
// куку сессии (SameSite=Lax) на чужой POST браузер не пошлёт.

function handle_tg_link(PDO $pdo, array $cfg, string $me, array $body, int $now): array {
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
    if ($action === 'unlink') { return tg_unlink($pdo, $me); }
    if ($action === 'link') {
        $lang = isset($body['lang']) && is_string($body['lang']) ? $body['lang'] : 'ru';
        return tg_link_start($pdo, tg_config($cfg), $me, $lang, $now);
    }
    return [400, ['ok' => false, 'error' => 'bad_action']];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    start_site_session();

    // Каждое открытие колокольчика выдаёт новый код. Тридцати в час хватает
    // с запасом, а перебором эндпоинт таблицу кодов не раздует.
    $me = chat_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('tg_link', $me, 30, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    [$status, $payload] = handle_tg_link(db(), app_config(), $me, read_json_body(), time());
    json_out($payload, $status);
}
