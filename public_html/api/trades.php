<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/trade.php';

// Лента объявлений — GET /api/trades.php[?q=строка][&before=N]
// Свои объявления для профиля — GET /api/trades.php?view=mine
// Сколько ещё можно опубликовать — GET /api/trades.php?view=quota
// Живые объявления игрока для его профиля — GET /api/trades.php?view=user&id=<roblox_id>
//
// view=user отвечает только вошедшему, как и сам профиль (profile.php): лента
// показывает объявления вразброс, а список «всё, что есть у этого человека»
// по номеру — это уже справка о нём, и анониму её не выдаём.
//
// Ленту видят все, не только вошедшие: объявление — это то, что человек сам
// решил показать, и доска, которую нельзя посмотреть до входа, никого не
// приведёт. Профили при этом остаются закрытыми (profile.php): из ленты
// видно ник и аватар, а статистика и «о себе» — только вошедшим.
//
// Ответ зависит от сессии (флаг mine и отдельный список своих), поэтому
// никакого кеша — ни браузерного, ни LiteSpeed.

function handle_trades(PDO $pdo, array $session, array $get, int $now, array $cfg = []): array {
    $me   = trade_me($session);
    $view = isset($get['view']) && is_string($get['view']) ? $get['view'] : '';

    if ($view === 'mine' || $view === 'quota') {
        $out = [
            'ok'     => true,
            'ready'  => trade_ready($pdo),
            'authed' => $me !== '',
            'quota'  => null,
        ];
        if ($me !== '' && $out['ready']) {
            $out['quota'] = trade_quota($pdo, $me, $now);
            if ($view === 'mine') { $out['offers'] = trade_mine_all($pdo, $me, $now); }
        }
        if ($view === 'mine' && !isset($out['offers'])) { $out['offers'] = []; }
        return [200, $out];
    }

    if ($view === 'user') {
        $raw = isset($get['id']) && is_string($get['id']) ? $get['id'] : '';
        $who = preg_match('/^\d{1,20}\z/', $raw) === 1 ? ltrim($raw, '0') : '';
        $out = ['ok' => true, 'ready' => trade_ready($pdo), 'authed' => $me !== '', 'offers' => []];
        if ($me !== '' && $out['ready'] && $who !== '') {
            $out['offers'] = trade_user_live($pdo, $who, $me, $now);
        }
        return [200, $out];
    }

    $q      = trade_clean_query($get['q'] ?? '');
    $before = (isset($get['before']) && is_string($get['before']) && ctype_digit($get['before']))
        ? (int)$get['before'] : 0;
    $feed = trade_feed($pdo, $me, $q, $before, $now);
    $feed['authed'] = $me !== '';
    $feed['admin']  = site_role($session, $cfg) === 'admin';
    return [200, $feed];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    lscache_off();
    $session = resume_site_session() ? $_SESSION : [];
    [$status, $payload] = handle_trades(db(), $session, $_GET, time(), app_config());
    json_out($payload, $status);
}
