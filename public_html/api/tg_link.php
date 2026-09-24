<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';

// Колокольчик в чате и в профиле — POST /api/tg_link.php
//   {"action":"link","lang":"ru"} → {"ok":true,"url":"https://t.me/<бот>?start=<код>"}
//   {"action":"unlink"}           → {"ok":true}
//   {"action":"status"}           → {"ok":true,"tg":{on, linked, name, mod, prefs}}
//   {"action":"prefs","prices":true,"news":false} → {"ok":true,"prefs":{…}}
//
// status профиль спрашивает, пока человек жмёт Start в Telegram: так
// колокольчик сам становится синим, как в чате.
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
    if ($action === 'status') {
        if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
        return [200, ['ok' => true, 'tg' => tg_profile_status($pdo, tg_config($cfg), $me)]];
    }
    if ($action === 'prefs') { return tg_prefs_set($pdo, $me, $body); }
    return [400, ['ok' => false, 'error' => 'bad_action']];
}

// Сколько раз в час можно дёрнуть действие. Каждое открытие колокольчика
// выдаёт новый код: тридцати в час хватает с запасом, а перебором эндпоинт
// таблицу кодов не раздует. Переключатели — по одной записи на нажатие.
// status не ограничен: это одно чтение, и профиль спрашивает его раз в
// несколько секунд, пока ждёт Start.
function tg_link_limit(string $action): int {
    if ($action === 'link')  { return 30; }
    if ($action === 'prefs') { return 60; }
    return 0;
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    start_site_session();

    $me     = chat_me($_SESSION);
    $body   = read_json_body();
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
    $limit  = tg_link_limit($action);
    $bucket = $action === 'link' ? 'tg_link' : 'tg_' . $action;
    if ($me !== '' && $limit > 0 && !rate_limit_allow($bucket, $me, $limit, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    [$status, $payload] = handle_tg_link(db(), app_config(), $me, $body, time());
    json_out($payload, $status);
}
