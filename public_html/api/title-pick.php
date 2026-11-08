<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/profile.php';

// Главный титул — POST /api/title-pick.php {"kind":"login"}
//
// Своё и только своё, как «о себе» (api/profile-about.php): чей выбор, решает
// сессия. Выбрать можно только титул, который у человека есть; какой это,
// считает hunt_titles_for — с выданными в коде титулами вместе.

/**
 * Возвращает [код, тело]. Отдельной функцией, чтобы тест звал её без сети и
 * заголовков.
 */
function handle_title_pick(PDO $pdo, array $session, $kind, int $now): array {
    $me = profile_me($session);
    if ($me === '') { return [401, ['ok' => false, 'error' => 'unauthorized']]; }
    if (!is_string($kind) || $kind === '') { return [400, ['ok' => false, 'error' => 'bad_request']]; }

    try {
        $known = profile_exists($pdo, $me);
    } catch (PDOException $e) {
        $known = false;
    }
    if (!$known) { return [401, ['ok' => false, 'error' => 'unauthorized']]; }

    $res = hunt_title_pick($pdo, $me, $kind, $now);
    if ($res === 'bad_kind') { return [400, ['ok' => false, 'error' => 'bad_kind']]; }
    if ($res !== 'ok') {
        // Таблицы title_picks нет — миграция не выполнена. «ok» тут было бы
        // враньём: после перезагрузки у ника снова стоял бы прежний титул.
        return [503, ['ok' => false, 'error' => 'unavailable']];
    }
    return [200, ['ok' => true, 'kind' => $kind]];
}

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    $me = profile_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('title_pick', $me, 60, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    header('Cache-Control: no-store');
    $body = read_json_body();
    [$status, $payload] = handle_title_pick(db(), $_SESSION, $body['kind'] ?? null, time());
    json_out($payload, $status);
}
