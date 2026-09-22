<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/trade.php';

// Лента объявлений — GET /api/trades.php[?q=строка][&before=N]
//
// Ленту видят все, не только вошедшие: объявление — это то, что человек сам
// решил показать, и доска, которую нельзя посмотреть до входа, никого не
// приведёт. Профили при этом остаются закрытыми (profile.php): из ленты
// видно ник и аватар, а статистика и «о себе» — только вошедшим.
//
// Ответ зависит от сессии (флаг mine и отдельный список своих), поэтому
// никакого кеша — ни браузерного, ни LiteSpeed.

function handle_trades(PDO $pdo, array $session, array $get, int $now): array {
    $me     = trade_me($session);
    $q      = trade_clean_query($get['q'] ?? '');
    $before = (isset($get['before']) && is_string($get['before']) && ctype_digit($get['before']))
        ? (int)$get['before'] : 0;
    $feed = trade_feed($pdo, $me, $q, $before, $now);
    $feed['authed'] = $me !== '';
    $feed['admin']  = !empty($session['admin']);
    return [200, $feed];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    lscache_off();
    $session = resume_site_session() ? $_SESSION : [];
    [$status, $payload] = handle_trades(db(), $session, $_GET, time());
    json_out($payload, $status);
}
