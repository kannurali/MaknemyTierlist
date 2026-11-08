<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Мини-приложение розыгрыша (/tg-app) — участие прямо в Telegram.
// POST /api/gw_tg_app.php {"init": "<Telegram.WebApp.initData>"}
//   → {"ok", "state", "lang", "text", "note", "gw", "missing"} — см. gw_tg_app_join()
//     в api/lib/gw_tg.php. 403 — initData не прошли проверку подписи или
//     устарели; 429 — слишком часто.
//
// Кто участвует, сервер берёт только из initData: их подписывает Telegram
// токеном бота (gw_tg_app_auth()). Какой розыгрыш — тоже оттуда
// (start_param из ссылки кнопки под постом).
//
// Каждая попытка — до пяти запросов к Telegram (по одному на канал), поэтому
// попытки считаются, как нажатия на сайте (api/giveaway_enter.php).

const GW_APP_PER_HOUR = 30;

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();

    $cfg = app_config();
    $tg  = tg_config($cfg);
    $body = read_json_body();
    $init = is_string($body['init'] ?? null) ? $body['init'] : '';
    $auth = tg_enabled($tg) ? gw_tg_app_auth($init, $tg['token'], time()) : null;
    if ($auth === null) {
        json_out(gw_tg_app_fail('auth', 'ru'), 403);
        exit;
    }
    $lang = tg_lang_from_telegram($auth['user']['language_code'] ?? '');
    if (!rate_limit_allow('gw_app', (string)$auth['user']['id'], GW_APP_PER_HOUR, 3600, time())) {
        json_out(gw_tg_app_fail('rate', $lang), 429);
        exit;
    }
    $result = gw_tg_app_join(db(), $cfg, tg_http($tg['token']), $auth, time());
    // Новый участник меняет число на сайте — кеш выдачи розыгрышей сбрасывается.
    if (!empty($result[2])) { header('X-LiteSpeed-Purge: tag=giveaways'); }
    json_out($result[1], $result[0]);
    // После ответа: «вы участвуете» в личку (аккаунт сайта), число на кнопке
    // под постом, напоминания «осталось N мест», итоги, если приём закрыт.
    gw_tg_after(db(), $cfg, $result[2], time());
}
