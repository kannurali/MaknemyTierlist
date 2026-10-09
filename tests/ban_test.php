<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

// Бан из панели (api/lib/ban.php): модераторы и админы банят кнопкой в чате и
// на /admin/bans, без правки config.php. Таблица user_bans складывается со
// списком banned_ids, и проверка бана на сайте остаётся одной.

const NOW = 1790000000;

// Роли как на бою: 1 — админ, 2 — модератор, 3 — второй модератор,
// 100–102 — игроки.
const BAN_CFG = ['admin_ids' => ['1'], 'moderator_ids' => ['2', '3']];

function ban_db_for_test(bool $withBans = true): PDO {
    $pdo = test_db();
    if ($withBans) {
        // Зеркалит schema.sql и docs/migrations/2026-10-10-user-bans.sql.
        $pdo->exec("CREATE TABLE user_bans (
            user_id INTEGER NOT NULL PRIMARY KEY,
            until_at INTEGER NOT NULL DEFAULT 0,
            by_id INTEGER NOT NULL,
            reason TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
    }
    $pdo->exec("CREATE TABLE login_tokens (selector TEXT PRIMARY KEY, token_hash TEXT NOT NULL,
        user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)");
    $ins = $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                          VALUES (?, ?, ?, ?, 0, 0, ?)');
    foreach ([
        ['1', 'owner', 'Maknemy', '', 50],
        ['2', 'mod_two', 'Модератор', '', 40],
        ['3', 'mod_three', '', '', 30],
        ['100', 'bob', 'Bob', 'https://tr.rbxcdn.com/bob.png', 10],
        ['101', 'bobby_x', 'Robert', '', 20],
        ['102', 'alice', '100% Alice', '', 5],
    ] as $u) { $ins->execute($u); }
    return $pdo;
}

// --- откуда берётся бан ----------------------------------------------------------

test('site_bans складывает config.php и таблицу по тем же правилам', function () {
    $pdo = ban_db_for_test();
    $pdo->exec('INSERT INTO user_bans VALUES (100, 0, 2, \'\', 1)');
    $pdo->exec('INSERT INTO user_bans VALUES (101, ' . (NOW + 60) . ', 2, \'\', 1)');
    $pdo->exec('INSERT INTO user_bans VALUES (102, ' . (NOW - 1) . ', 2, \'\', 1)');
    $pdo->exec('INSERT INTO user_bans VALUES (555, ' . (NOW + 60) . ', 2, \'\', 1)');
    $cfg = ['banned_ids' => ['555', '101' => '2099-01-01']];

    $bans = site_bans($cfg, NOW, $pdo);
    assert_eq(0, $bans['100'] ?? 'нет', 'из панели навсегда');
    assert_eq(false, array_key_exists('102', $bans), 'истёкший не действует');
    assert_eq(0, $bans['555'] ?? 'нет', '«навсегда» из конфига сильнее срока из панели');
    assert_true(($bans['101'] ?? 0) > NOW + 60, 'из двух сроков — дальний');
    assert_eq(['555', '101', '100'], site_banned_ids($cfg, NOW, $pdo), 'строками, конфиг первым');

    assert_eq(['555', '101'], site_banned_ids($cfg, NOW), 'без базы — один config.php');
    assert_eq(true, site_banned('100', [], NOW, $pdo), 'бан из панели без списка в конфиге');
    assert_eq(false, site_banned('100', [], NOW), 'а без базы его не видно');
});

test('нет таблицы — работает один config.php, без ошибок', function () {
    $pdo = ban_db_for_test(false);
    assert_eq([], ban_store_list($pdo, NOW), 'пусто');
    assert_eq(['7'], site_banned_ids(['banned_ids' => ['7']], NOW, $pdo), 'конфиг на месте');
    assert_eq(false, ban_ready($pdo), 'not ready');
});

// --- кто кого может банить ---------------------------------------------------------

test('модератор банит игроков, админ — ещё и модераторов, админа — никто', function () {
    assert_eq(true, ban_allowed('moderator', '2', '100', ''), 'модератор → игрок');
    assert_eq(false, ban_allowed('moderator', '2', '3', 'moderator'), 'модератор → модератор');
    assert_eq(false, ban_allowed('moderator', '2', '1', 'admin'), 'модератор → админ');
    assert_eq(true, ban_allowed('admin', '1', '100', ''), 'админ → игрок');
    assert_eq(true, ban_allowed('admin', '1', '2', 'moderator'), 'админ → модератор');
    assert_eq(false, ban_allowed('admin', '1', '9', 'admin'), 'админ → админ');
    assert_eq(false, ban_allowed('admin', '1', '1', 'admin'), 'себя');
    assert_eq(false, ban_allowed('moderator', '2', '2', 'moderator'), 'себя модератором');
    assert_eq(false, ban_allowed('', '100', '101', ''), 'игрок — никого');
    assert_eq(false, ban_allowed('admin', '1', '', ''), 'пустой id');
});

// --- бан и разбан -----------------------------------------------------------------

test('модератор банит игрока на срок: строка в базе, ключи входа погашены', function () {
    $pdo = ban_db_for_test();
    $pdo->exec("INSERT INTO login_tokens VALUES ('s1', 'h', 100, 1, 9999999999), ('s2', 'h', 101, 1, 9999999999)");

    [$code, $out] = ban_set($pdo, BAN_CFG, '2', '100', '7d', "  спам\nв чате  ", NOW);
    assert_eq(200, $code);
    assert_eq(true, $out['ok'] && $out['banned'], 'забанен');
    assert_eq(NOW + 7 * 86400, $out['until'], 'срок — неделя');
    assert_eq(false, $out['config'], 'не из конфига');
    assert_eq(true, $out['can'], 'и снять он может');

    $row = $pdo->query('SELECT user_id, until_at, by_id, reason, created_at FROM user_bans')->fetch(PDO::FETCH_NUM);
    assert_eq(['100', (string)(NOW + 604800), '2', 'спам в чате', (string)NOW], array_map('strval', $row), 'строка');
    assert_eq(true, site_banned('100', BAN_CFG, NOW, $pdo), 'единая проверка его видит');
    assert_eq(false, site_banned('100', BAN_CFG, NOW + 604800, $pdo), 'срок вышел — снят сам');
    assert_eq(['101'], array_map('strval', $pdo->query('SELECT user_id FROM login_tokens')->fetchAll(PDO::FETCH_COLUMN)),
        'ключи «запомнить вход» забаненного погашены, чужие на месте');
});

test('повторный бан заменяет прежний, навсегда — until 0', function () {
    $pdo = ban_db_for_test();
    ban_set($pdo, BAN_CFG, '2', '100', '1d', 'раз', NOW);
    [, $out] = ban_set($pdo, BAN_CFG, '1', '100', 'forever', 'два', NOW + 5);
    assert_eq(0, $out['until'], 'навсегда');
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM user_bans')->fetchColumn(), 'одна строка');
    assert_eq(['1', 'два'], array_map('strval', $pdo->query('SELECT by_id, reason FROM user_bans')->fetch(PDO::FETCH_NUM)),
        'автор и причина — новые');
});

test('бан отказывает понятной ошибкой', function () {
    $pdo = ban_db_for_test();
    $cases = [
        ['100', '101', '7d', 401, 'unauthorized', 'игрок без роли'],
        ['2',   'abc', '7d', 400, 'bad_id', 'id не число'],
        ['2',   ['1'], '7d', 400, 'bad_id', 'id массивом'],
        ['2',   '100', '2y', 400, 'bad_term', 'срока нет в списке'],
        ['2',   '100', null, 400, 'bad_term', 'срок не строкой'],
        ['2',   '999', '7d', 404, 'no_user', 'такого игрока нет'],
        ['2',   '1',   '7d', 403, 'forbidden', 'админа'],
        ['2',   '3',   '7d', 403, 'forbidden', 'модератора модератором'],
        ['2',   '2',   '7d', 403, 'forbidden', 'себя'],
    ];
    foreach ($cases as [$by, $target, $term, $code, $error, $why]) {
        [$c, $out] = ban_set($pdo, BAN_CFG, $by, $target, $term, '', NOW);
        assert_eq([$code, $error], [$c, $out['error'] ?? null], $why);
    }
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM user_bans')->fetchColumn(), 'никто не забанен');

    [$c, $out] = ban_set($pdo, BAN_CFG, '1', '3', '1d', '', NOW);
    assert_eq([200, true], [$c, $out['banned']], 'а админ модератора — может');

    [$c, $out] = ban_set(ban_db_for_test(false), BAN_CFG, '2', '100', '7d', '', NOW);
    assert_eq([503, 'not_ready'], [$c, $out['error']], 'нет таблицы');
});

test('разбан снимает бан из панели, бан из config.php остаётся', function () {
    $pdo = ban_db_for_test();
    ban_set($pdo, BAN_CFG, '2', '100', '30d', '', NOW);
    [$c, $out] = ban_lift($pdo, BAN_CFG, '2', '100', NOW);
    assert_eq([200, false], [$c, $out['banned']], 'снят');
    assert_eq(false, site_banned('100', BAN_CFG, NOW, $pdo), 'и на сайте тоже');

    $cfg = BAN_CFG + ['banned_ids' => ['101']];
    ban_set($pdo, $cfg, '2', '101', '1d', '', NOW);
    [, $out] = ban_lift($pdo, $cfg, '2', '101', NOW);
    assert_eq([true, true, 0], [$out['banned'], $out['config'], $out['until']], 'из конфига — по-прежнему навсегда');

    [$c, $out] = ban_lift($pdo, BAN_CFG, '3', '1', NOW);
    assert_eq([403, 'forbidden'], [$c, $out['error']], 'разбан — по тем же правам');
});

test('handle_ban: GET — состояние, POST — бан и разбан', function () {
    $pdo = ban_db_for_test();
    [$c, $s] = handle_ban($pdo, BAN_CFG, '2', 'GET', ['id' => '0100'], [], NOW);
    assert_eq(200, $c);
    assert_eq(['100', false, null, false, true, true],
        [$s['id'], $s['banned'], $s['until'], $s['config'], $s['can'], $s['ready']], 'не забанен, можно');

    [$c, $s] = handle_ban($pdo, BAN_CFG, '2', 'POST', [], ['id' => 100, 'term' => '3d'], NOW);
    assert_eq([200, NOW + 3 * 86400], [$c, $s['until']], 'id числом из JSON тоже годится');
    [, $s] = handle_ban($pdo, BAN_CFG, '2', 'POST', [], ['id' => '100', 'unban' => '1'], NOW);
    assert_eq(false, $s['banned'], 'unban из формы');

    [$c] = handle_ban($pdo, BAN_CFG, '100', 'GET', ['id' => '101'], [], NOW);
    assert_eq(401, $c, 'игроку — нет');
    [$c] = handle_ban($pdo, BAN_CFG, '2', 'GET', [], [], NOW);
    assert_eq(400, $c, 'без id');
    [$c] = handle_ban($pdo, BAN_CFG, '2', 'DELETE', [], [], NOW);
    assert_eq(405, $c, 'другой метод');
    [, $s] = handle_ban($pdo, BAN_CFG, '2', 'GET', ['id' => '1'], [], NOW);
    assert_eq(false, $s['can'], 'админа банить нельзя — кнопки не будет');
});

// --- поиск и список ----------------------------------------------------------------

test('поиск по нику: логин и имя, без регистра, точное сверху, по id', function () {
    $pdo = ban_db_for_test();
    $ids = function (array $rows) { return array_column($rows, 'id'); };

    assert_eq(['100', '101'], $ids(ban_search($pdo, BAN_CFG, '2', 'BOB', NOW)), 'точное совпадение первым, дальше по активности');
    assert_eq(['101'], $ids(ban_search($pdo, BAN_CFG, '2', 'rober', NOW)), 'по отображаемому имени');
    assert_eq(['102'], $ids(ban_search($pdo, BAN_CFG, '2', '100%', NOW)), '% — просто символ');
    assert_eq(['101'], $ids(ban_search($pdo, BAN_CFG, '2', 'by_', NOW)), '_ — тоже');
    assert_eq(['100', '102'], $ids(ban_search($pdo, BAN_CFG, '2', '100', NOW)), 'цифры — ещё и id');
    assert_eq([], ban_search($pdo, BAN_CFG, '2', '   ', NOW), 'пустой запрос');
    assert_eq([], ban_search($pdo, BAN_CFG, '2', ['bob'], NOW), 'не строка');

    $bob = ban_search($pdo, BAN_CFG, '2', 'bob', NOW)[0];
    assert_eq(['Bob', '@bob', 'https://tr.rbxcdn.com/bob.png', '', true],
        [$bob['nick'], $bob['handle'], $bob['avatar'], $bob['role'], $bob['can']], 'карточка');
    $mod = ban_search($pdo, BAN_CFG, '2', 'mod_three', NOW)[0];
    assert_eq(['mod_three', 'moderator', false], [$mod['nick'], $mod['role'], $mod['can']], 'без имени — логин, роль видна');
});

test('список забаненных: из панели с автором и причиной, из конфига следом', function () {
    $pdo = ban_db_for_test();
    ban_set($pdo, BAN_CFG, '2', '100', '7d', 'спам', NOW - 10);
    ban_set($pdo, BAN_CFG, '1', '101', 'forever', '', NOW);
    $cfg = BAN_CFG + ['banned_ids' => ['102', '777']];

    $list = ban_active($pdo, $cfg, '2', NOW);
    assert_eq(['101', '100', '102', '777'], array_column($list, 'id'), 'свежие сверху, конфиг в конце');
    assert_eq(['Robert', 'Maknemy', 0, true], [$list[0]['nick'], $list[0]['byNick'], $list[0]['until'], $list[0]['can']], 'бан админа');
    assert_eq(['Модератор', 'спам'], [$list[1]['byNick'], $list[1]['reason']], 'автор и причина');
    assert_eq(['100% Alice', true, false], [$list[2]['nick'], $list[2]['config'], $list[2]['can']], 'из конфига снять нельзя');
    assert_eq('777', $list[3]['nick'], 'неизвестный — по id');
    assert_eq([], ban_active(ban_db_for_test(false), [], '2', NOW), 'нет таблицы и списка — пусто');
});

// --- кто ещё смотрит на бан ---------------------------------------------------------

test('бан из панели видят сессия, вход и лента', function () {
    $pub = __DIR__ . '/../public_html';
    $boot = file_get_contents("$pub/api/_bootstrap.php");
    assert_true(strpos($boot, 'site_banned($uid, app_config(), null, ban_db())') !== false, 'сессия');
    $cb = file_get_contents("$pub/api/roblox_callback.php");
    assert_true(strpos($cb, "site_ban_until((string)\$profile['roblox_id'], \$cfg, null, ban_db())") !== false, 'вход');
    $tr = file_get_contents("$pub/api/trades.php");
    assert_true(strpos($tr, 'site_banned_ids($cfg, $now, $pdo)') !== false, 'лента и профиль');
});

test('кнопка бана в чате и страница /admin/bans', function () {
    $pub = __DIR__ . '/../public_html';
    $page = file_get_contents("$pub/chat.php");
    assert_true((bool)preg_match('/class="ct-ban" id="ctBan" hidden/', $page), 'кнопка скрыта до ответа сервера');
    foreach (array_keys(BAN_TERMS) as $term) {
        assert_true(strpos($page, '<option value="' . $term . '"') !== false, "срок $term в списке");
    }
    assert_true(strpos($page, 'maxlength="' . BAN_REASON_MAX . '"') !== false, 'предел причины как на сервере');
    $api = file_get_contents("$pub/api/chat.php");
    assert_true(strpos($api, "\$payload['mod'] = !empty(\$payload['authed']) && is_moderator();") !== false, 'чат знает, кто модератор');
    $js = file_get_contents("$pub/js/chat-page.js");
    assert_true(strpos($js, "'/api/ban.php?id=' + encodeURIComponent(peer.id)") !== false, 'состояние спрашивается');
    assert_true(strpos($js, "post('/api/ban.php', payload)") !== false, 'бан отправляется');

    $i18n = file_get_contents("$pub/js/i18n.js");
    foreach (['chat.ban', 'chat.banAsk', 'chat.banGo', 'chat.unban', 'chat.bannedUntil', 'chat.bannedForever',
              'chat.banConfig', 'chat.banNo', 'chat.banNotReady', 'chat.banFailed', 'chat.banLoading'] as $k) {
        assert_eq(2, substr_count($i18n, "\"$k\":"), "$k: ru и en");
    }

    $ht = file_get_contents("$pub/.htaccess");
    assert_true(strpos($ht, 'RewriteRule ^admin/bans/?$  /admin-bans.php     [L]') !== false, 'маршрут /admin/bans');
    $admin = file_get_contents("$pub/admin-bans.php");
    assert_true(strpos($admin, "admin_page_guard('moderator');") !== false, 'страница модераторам и админам');
    assert_true(strpos($admin, 'action="/api/ban.php"') !== false, 'бан — формой');
    $ep = file_get_contents("$pub/api/ban.php");
    assert_true(strpos($ep, 'require_moderator();') !== false, 'API — модераторам и админам');
    $nav = file_get_contents("$pub/api/lib/admin_page.php");
    assert_eq(2, substr_count($nav, "'bans'    => ['/admin/bans', 'Баны']"), 'вкладка и у админа, и у модератора');
});

test('миграция и schema.sql заводят одну и ту же таблицу', function () {
    $mig = file_get_contents(__DIR__ . '/../docs/migrations/2026-10-10-user-bans.sql');
    $schema = file_get_contents(__DIR__ . '/../schema.sql');
    foreach ([$mig, $schema] as $sql) {
        assert_true(strpos($sql, 'CREATE TABLE IF NOT EXISTS user_bans (') !== false, 'таблица');
        foreach (['user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY', 'until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0',
                  'by_id      BIGINT UNSIGNED NOT NULL', "reason     VARCHAR(200)    NOT NULL DEFAULT ''",
                  'created_at BIGINT UNSIGNED NOT NULL'] as $col) {
            assert_true(strpos($sql, $col) !== false, "колонка: $col");
        }
    }
    assert_eq(200, BAN_REASON_MAX, 'VARCHAR(200) = BAN_REASON_MAX');
});

run_tests();
