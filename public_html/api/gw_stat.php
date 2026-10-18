<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Показы и переходы карточек розыгрыша (js/gw-card.js) —
// POST /api/gw_stat.php {"e": [{"id": 5, "p": "tier", "k": "v"}, …]} → 204.
//
// k: v — карточку увидели, c — по ней нажали. Карточка шлёт показы пачкой
// раз за просмотр страницы (navigator.sendBeacon), переход — сразу. Ответа
// страница не читает, поэтому тело пустое и ошибки не показываются: учёт не
// должен мешать переходу на /giveaways.
//
// Лимит на адрес — чтобы перебором не накрутить показы. 600 в час: за одним
// адресом мобильного оператора сидят сотни человек, а пачка — одна на просмотр.

const GW_STAT_PER_HOUR = 600;

function handle_gw_stat(PDO $pdo, array $body): int {
    $events = $body['e'] ?? null;
    if (!is_array($events) || !$events) { return 0; }
    return gw_card_track($pdo, array_values($events));
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    if (!rate_limit_allow('gw_stat', client_key(), GW_STAT_PER_HOUR, 3600, time())) {
        http_response_code(429);
        exit;
    }
    try { handle_gw_stat(db(), read_json_body()); } catch (Throwable $e) { error_log('gw_stat: ' . $e->getMessage()); }
    http_response_code(204);
}
