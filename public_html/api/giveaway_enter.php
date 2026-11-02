<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Кнопка «Участвовать» в окне и на странице розыгрышей —
// POST /api/giveaway_enter.php {"id": 12, "src": "tg"|"pop"|"page"}
//   → {"ok":true,"joined":true}
//   → {"ok":false,"error":"login"|"banned"|"closed"|"full"|"tg"|"tg_used"|"channels"|"tg_down", "missing":[…]}
// Что значит каждая ошибка — см. gw_enter() в api/lib/giveaway.php.
//
// Каждое нажатие — до пяти запросов к Telegram (по одному на канал), поэтому
// нажатия считаются: тридцати в час хватает и на «подписался — проверить —
// забыл ещё канал — проверить».

const GW_ENTER_PER_HOUR = 30;

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();

    $session = resume_site_session() ? $_SESSION : [];
    $me = chat_me($session);
    if ($me !== '' && !rate_limit_allow('gw_enter', $me, GW_ENTER_PER_HOUR, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

    $cfg = app_config();
    $tg  = tg_config($cfg);
    if (!tg_enabled($tg)) {
        json_out(['ok' => false, 'error' => 'closed'], 503);
        exit;
    }
    $body = read_json_body();
    $result = gw_enter(db(), $cfg, tg_http($tg['token']), $me, read_row_id($body), time(), gw_src($body['src'] ?? ''));
    // Последнее место занято — приём закрыт, окно и страница должны узнать
    // сразу, а не через полминуты кеша.
    if (!empty($result[2]['last'])) { header('X-LiteSpeed-Purge: tag=giveaways'); }
    json_out($result[1], $result[0]);
    // Только что вступил — бот подтверждает уже после ответа окну, а
    // подписчикам, ждавшим «осталось N мест», уходит напоминание. Занял
    // последнее место — тут же подводятся итоги.
    if (!empty($result[2])) { gw_after_join(db(), $cfg, $result[2]); }
    // Число участников на кнопке под постом в канале.
    if (!empty($result[2])) { gw_after_counts(db(), $cfg, time(), (int)$result[2]['row']['id']); }
    if (!empty($result[2]) && empty($result[2]['last'])) { gw_after_places(db(), $cfg, $result[2], time()); }
    if (!empty($result[2]['last'])) { gw_after_due(db(), $cfg, time()); }
}
