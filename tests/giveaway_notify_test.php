<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/giveaway.php';
require __DIR__ . '/../public_html/api/tg_link.php';

// Подписка на розыгрыши и напоминания бота — api/lib/gw_prefs.php, раздел
// «Напоминания подписчикам» в api/lib/giveaway.php и action gw_prefs в
// api/tg_link.php.
//
// Главные обещания:
//  - строки нет — человек подписан, без напоминаний; до миграции настроек нет,
//    а о старте бот пишет всем, как раньше;
//  - отписавшийся не получает ни рассылки о старте, ни напоминаний;
//  - напоминание каждого вида — одно на человека и розыгрыш;
//  - «по времени» — только если его момент после публикации и после того, как
//    человек сохранил настройку; «по местам» — только при лимите, пороге ниже
//    лимита и не только что вступившему;
//  - тестовый розыгрыш напоминает только своим участникам.

const GN_NOW   = 1790000000;
const GN_TOKEN = '123456789:AAHfakeTokenForTestsOnly_abcdefghij';

function gn_db(bool $prefs = true): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE tg_links (
        user_id INTEGER NOT NULL PRIMARY KEY, chat_id INTEGER NOT NULL,
        tg_name TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE tg_codes (
        code_hash TEXT NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL,
        lang TEXT NOT NULL DEFAULT 'ru', expires_at INTEGER NOT NULL)");
    $pdo->exec('CREATE TABLE chat_reads (
        thread_id INTEGER NOT NULL, user_id INTEGER NOT NULL, last_read_id INTEGER NOT NULL DEFAULT 0,
        seen_at INTEGER NOT NULL DEFAULT 0, notified_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (thread_id, user_id))');
    $pdo->exec('CREATE TABLE tg_prefs (
        user_id INTEGER NOT NULL PRIMARY KEY, prices INTEGER NOT NULL DEFAULT 1, news INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft', test INTEGER NOT NULL DEFAULT 0,
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL, conds_json TEXT NULL,
        max_entries INTEGER NOT NULL DEFAULT 0, ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE giveaway_entries (
        giveaway_id INTEGER NOT NULL, user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL,
        joined_at INTEGER NOT NULL, prize INTEGER NULL, src TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (giveaway_id, user_id), UNIQUE (giveaway_id, chat_id))");
    $pdo->exec('CREATE TABLE giveaway_links (
        giveaway_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, invite_url TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY (giveaway_id, channel_id), UNIQUE (invite_url))');
    $pdo->exec("CREATE TABLE tg_channels (
        chat_id INTEGER NOT NULL PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0,
        missing TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL)");
    if ($prefs) {
        // Зеркалит docs/migrations/2026-10-18-giveaway-notify.sql.
        $pdo->exec('CREATE TABLE gw_prefs (
            user_id INTEGER NOT NULL PRIMARY KEY, subscribed INTEGER NOT NULL DEFAULT 1,
            time_left INTEGER NOT NULL DEFAULT 0, places_left INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE gw_reminders (
            giveaway_id INTEGER NOT NULL, user_id INTEGER NOT NULL, kind TEXT NOT NULL, sent_at INTEGER NOT NULL,
            PRIMARY KEY (giveaway_id, user_id, kind))');
    }
    return $pdo;
}

function gn_cfg(): array {
    return ['tg_bot_token' => GN_TOKEN, 'tg_bot_name' => 'MaknemyBot'];
}

function gn_user(PDO $p, string $id, ?int $chat = null, string $lang = 'ru'): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, 'u' . $id, 'U' . $id, '', GN_NOW - 86400, GN_NOW - 3600, GN_NOW - 3600]);
    if ($chat !== null) {
        $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, lang, linked_at) VALUES (?, ?, ?, ?, ?)')
          ->execute([$id, $chat, '@u' . $id, $lang, GN_NOW - 100]);
    }
    return $id;
}

// Идущий розыгрыш напрямую в базе: published — когда опубликован.
function gn_live(PDO $p, int $endsAt, int $published, int $max = 0, bool $test = false): int {
    $p->prepare("INSERT INTO giveaways (status, test, title_ru, title_en, prizes_json, channels_json, max_entries, ends_at, created_at, updated_at, published_at)
                 VALUES ('live', ?, 'Розыгрыш Magnet', 'Magnet giveaway', ?, '[]', ?, ?, ?, ?, ?)")
      ->execute([$test ? 1 : 0, json_encode([['name' => 'Magnet', 'count' => 1]]), $max, $endsAt, $published, $published, $published]);
    return (int)$p->lastInsertId();
}

function gn_prefs(PDO $p, string $user, array $set, int $at): void {
    [$s] = gw_prefs_set($p, $user, $set, $at);
    assert_eq(200, $s, "настройки $user сохранены");
}

function gn_entry(PDO $p, int $id, string $user, int $chat, int $at): void {
    $p->prepare('INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at, src) VALUES (?, ?, ?, ?, ?)')
      ->execute([$id, $user, $chat, $at, 'page']);
}

function gn_tg(array &$log): callable {
    return function (string $method, array $params) use (&$log): ?array {
        $log[] = [$method, $params];
        return ['ok' => true, 'result' => []];
    };
}

function gn_chats(array $log): array {
    return array_map(function ($c) { return $c[1]['chat_id']; }, $log);
}

function gn_nosleep(): callable { return function (int $us): void {}; }

// --------------------------------------------------------------------------
//  Настройки
// --------------------------------------------------------------------------

test('строки нет — подписан, без напоминаний; до миграции — null', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    assert_eq(['subscribed' => true, 'time_left' => 0, 'places_left' => 0], gw_prefs_get($p, '1'), 'по умолчанию');
    assert_eq(null, gw_prefs_get(gn_db(false), '1'), 'таблицы нет');
    [$s, $out] = gw_prefs_set(gn_db(false), '1', ['subscribed' => false], GN_NOW);
    assert_eq([503, 'not_ready'], [$s, $out['error']], 'сохранять некуда');
});

test('сохраняется только пришедшее и только правильное', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    [$s, $out] = gw_prefs_set($p, '1', ['time_left' => 3600], GN_NOW);
    assert_eq(200, $s, 'час');
    assert_eq(['subscribed' => true, 'time_left' => 3600, 'places_left' => 0], $out['gw'], 'остальное как было');
    gn_prefs($p, '1', ['places_left' => 10, 'subscribed' => false], GN_NOW + 1);
    assert_eq(['subscribed' => false, 'time_left' => 3600, 'places_left' => 10], gw_prefs_get($p, '1'), 'перечитано из базы');

    foreach ([
        ['subscribed' => 0], ['subscribed' => 'нет'], ['time_left' => '3600'], ['time_left' => 60],
        ['time_left' => GW_TIME_LEFT_MAX + 1], ['time_left' => -5], ['places_left' => 1.5],
        ['places_left' => GW_PLACES_LEFT_MAX + 1], ['places_left' => -1],
    ] as $bad) {
        [$s, $out] = gw_prefs_set($p, '1', $bad, GN_NOW + 2);
        assert_eq([400, 'bad_prefs'], [$s, $out['error'] ?? ''], 'отбито: ' . json_encode($bad));
    }
    assert_eq(['subscribed' => false, 'time_left' => 3600, 'places_left' => 10], gw_prefs_get($p, '1'), 'кривое ничего не поменяло');
    gn_prefs($p, '1', ['time_left' => 0, 'places_left' => 0], GN_NOW + 3);
    assert_eq(['subscribed' => false, 'time_left' => 0, 'places_left' => 0], gw_prefs_get($p, '1'), '0 выключает');
    gn_prefs($p, '1', ['time_left' => GW_TIME_LEFT_MIN, 'places_left' => GW_PLACES_LEFT_MAX], GN_NOW + 4);
    assert_eq(GW_TIME_LEFT_MIN, gw_prefs_get($p, '1')['time_left'], 'границы включены');
});

test('гостю и неизвестному аккаунту — not_logged_in', function () {
    $p = gn_db();
    assert_eq(401, gw_prefs_set($p, '', ['subscribed' => true], GN_NOW)[0], 'гость');
    assert_eq(401, gw_prefs_set($p, '999', ['subscribed' => true], GN_NOW)[0], 'аккаунта нет');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM gw_prefs')->fetchColumn(), 'строк не появилось');
});

test('tg_link.php: status отдаёт gw, gw_prefs сохраняет, лимит как у prefs', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    [$s, $out] = handle_tg_link($p, gn_cfg(), '1', ['action' => 'status'], GN_NOW);
    assert_eq(200, $s, 'status');
    assert_eq(['subscribed' => true, 'time_left' => 0, 'places_left' => 0], $out['tg']['gw'], 'gw в status');
    assert_true($out['tg']['linked'], 'остальное на месте');
    [$s, $out] = handle_tg_link($p, gn_cfg(), '1', ['action' => 'gw_prefs', 'time_left' => 7200], GN_NOW);
    assert_eq([200, 7200], [$s, $out['gw']['time_left']], 'gw_prefs');
    [, $out] = handle_tg_link(gn_db(false), gn_cfg(), '1', ['action' => 'status'], GN_NOW);
    assert_true(array_key_exists('gw', $out['tg']) && $out['tg']['gw'] === null, 'до миграции gw: null');
    assert_eq(60, tg_link_limit('gw_prefs'), 'лимит');
});

// --------------------------------------------------------------------------
//  Рассылка о старте
// --------------------------------------------------------------------------

test('о старте — всем подключившим, кроме отписавшихся; до миграции — всем', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 502);
    gn_user($p, '3', 503);
    gn_prefs($p, '2', ['subscribed' => false], GN_NOW);
    gn_prefs($p, '3', ['time_left' => 3600], GN_NOW);
    $id = gn_live($p, GN_NOW + 86400, GN_NOW);
    $log = [];
    gw_announce($p, gn_tg($log), gw_load($p, $id), gn_nosleep());
    assert_eq([501, 503], gn_chats($log), 'отписавшемуся не пишем');

    $p = gn_db(false);
    gn_user($p, '1', 501);
    gn_user($p, '2', 502);
    $id = gn_live($p, GN_NOW + 86400, GN_NOW);
    $log = [];
    gw_announce($p, gn_tg($log), gw_load($p, $id), gn_nosleep());
    assert_eq([501, 502], gn_chats($log), 'таблицы нет — всем');
});

// --------------------------------------------------------------------------
//  Напоминание по времени
// --------------------------------------------------------------------------

test('по времени: в свой момент, один раз, с участием и без', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 502, 'en');
    gn_user($p, '3', 503);
    gn_prefs($p, '1', ['time_left' => 3600], GN_NOW - 7200);
    gn_prefs($p, '2', ['time_left' => 2 * 3600], GN_NOW - 7200);
    gn_prefs($p, '3', ['time_left' => 3600, 'subscribed' => false], GN_NOW - 7200);
    $id = gn_live($p, GN_NOW + 3 * 3600, GN_NOW - 86400);
    gn_entry($p, $id, '1', 501, GN_NOW - 3600);

    assert_eq(false, gw_has_remind($p, GN_NOW), 'за 3 часа никому');
    assert_true(gw_has_remind($p, GN_NOW + 3600), 'за 2 часа — второму');
    $log = [];
    $res = gw_after_remind($p, gn_cfg(), GN_NOW + 3600, gn_tg($log), sys_get_temp_dir() . '/gn-remind-' . getmypid() . '.lock', gn_nosleep());
    assert_eq(['sent' => 1], $res, 'ушло одно');
    assert_eq([502], gn_chats($log), 'второму');
    assert_true(strpos($log[0][1]['text'], 'ends in 2 hours') !== false, 'по-английски и сколько осталось: ' . $log[0][1]['text']);
    assert_true(strpos($log[0][1]['text'], "haven't entered") !== false, 'не участвует — «успейте»');
    assert_eq('https://maknemy.com/giveaways#g' . $id . '-tg', $log[0][1]['reply_markup']['inline_keyboard'][0][0]['url'],
        'кнопка с пометкой бота');
    assert_eq(true, $log[0][1]['link_preview_options']['is_disabled'], 'без превью');

    $log = [];
    gw_remind_send($p, gn_tg($log), 'time', gw_remind_time_due($p, GN_NOW + 3600), GN_NOW + 3600, '', gn_nosleep());
    assert_eq([], $log, 'второй раз не шлёт');

    $log = [];
    gw_remind_send($p, gn_tg($log), 'time', gw_remind_time_due($p, GN_NOW + 2 * 3600 + 30), GN_NOW + 2 * 3600 + 30, '', gn_nosleep());
    assert_eq([501], gn_chats($log), 'за час — первому; отписавшемуся никогда');
    assert_true(strpos($log[0][1]['text'], '— 1 час.') !== false, 'округлено до минут: ' . $log[0][1]['text']);
    assert_true(strpos($log[0][1]['text'], 'Вы участвуете') !== false, 'участнику — «вы участвуете»');
    assert_eq('https://maknemy.com/giveaways#g' . $id, $log[0][1]['reply_markup']['inline_keyboard'][0][0]['url'],
        'участнику — просто открыть');
    assert_eq(false, gw_has_remind($p, GN_NOW + 3 * 3600), 'после конца — никому');
});

test('по времени: не раньше публикации и не раньше сохранения настройки', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 502);
    gn_prefs($p, '1', ['time_left' => 3 * 86400], GN_NOW - 86400);
    gn_prefs($p, '2', ['time_left' => 86400], GN_NOW);
    $id = gn_live($p, GN_NOW + 2 * 86400, GN_NOW - 100);
    assert_eq([], gw_remind_time_due($p, GN_NOW), 'розыгрыш на 2 дня, «за 3 дня» — момент до публикации');
    assert_eq(['2'], array_column(gw_remind_time_due($p, GN_NOW + 86400), 'user_id'), '«за день» — в свой момент');

    $p = gn_db();
    gn_user($p, '1', 501);
    $id = gn_live($p, GN_NOW + 3600, GN_NOW - 86400);
    gn_prefs($p, '1', ['time_left' => 86400], GN_NOW);
    assert_eq([], gw_remind_time_due($p, GN_NOW + 60), 'настроил, когда момент уже прошёл, — молчим');
});

test('по времени: розыгрыш «по числу участников» и черновик не напоминают', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_prefs($p, '1', ['time_left' => GW_TIME_LEFT_MAX], GN_NOW - 86400);
    gn_live($p, GW_NO_DEADLINE, GN_NOW - 100, 50);
    $p->exec("INSERT INTO giveaways (status, title_ru, prizes_json, ends_at, created_at, updated_at) VALUES ('draft', 'Ч', '[]', " . (GN_NOW + 3600) . ', 1, 1)');
    assert_eq([], gw_remind_time_due($p, GN_NOW), 'никому');
});

test('тестовый розыгрыш напоминает только участникам', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 502);
    gn_prefs($p, '1', ['time_left' => 3600, 'places_left' => 5], GN_NOW - 86400);
    gn_prefs($p, '2', ['time_left' => 3600, 'places_left' => 5], GN_NOW - 86400);
    $id = gn_live($p, GN_NOW + 1800, GN_NOW - 86400, 0, true);
    gn_entry($p, $id, '1', 501, GN_NOW - 100);
    assert_eq(['1'], array_column(gw_remind_time_due($p, GN_NOW), 'user_id'), 'по времени — участнику');

    $id = gn_live($p, GN_NOW + 86400, GN_NOW - 86400, 10, true);
    for ($i = 0; $i < 6; $i++) { gn_entry($p, $id, (string)(100 + $i), 9000 + $i, GN_NOW - 50); }
    gn_entry($p, $id, '1', 501, GN_NOW - 40);
    assert_eq(['1'], array_column(gw_remind_places_due($p, gw_load($p, $id), GN_NOW), 'user_id'), 'по местам — участнику');
});

// --------------------------------------------------------------------------
//  Напоминание по местам
// --------------------------------------------------------------------------

test('по местам: порог ниже лимита, вступивший сам — молча, один раз', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 502);
    gn_user($p, '3', 503);
    gn_user($p, '4', 504);
    gn_prefs($p, '1', ['places_left' => 5], GN_NOW - 86400);
    gn_prefs($p, '2', ['places_left' => 3], GN_NOW - 86400);
    gn_prefs($p, '3', ['places_left' => 50], GN_NOW - 86400);
    gn_prefs($p, '4', ['places_left' => 5], GN_NOW - 86400);
    $id = gn_live($p, GN_NOW + 86400, GN_NOW - 86400, 20);
    for ($i = 0; $i < 14; $i++) { gn_entry($p, $id, (string)(100 + $i), 9000 + $i, GN_NOW - 50); }
    assert_eq([], gw_remind_places_due($p, gw_load($p, $id), GN_NOW), 'осталось 6 — рано');

    gn_entry($p, $id, '4', 504, GN_NOW);
    $note = ['row' => gw_load($p, $id), 'user' => '4', 'chat_id' => 504, 'lang' => 'ru', 'last' => false];
    $log = [];
    assert_eq(1, gw_after_places($p, gn_cfg(), $note, GN_NOW, gn_tg($log), gn_nosleep()), 'ушло одно');
    assert_eq([501], gn_chats($log), 'осталось 5: первому; порог 50 при лимите 20 не считается; вступившему — нет');
    assert_true(strpos($log[0][1]['text'], 'осталось 5 мест из 20') !== false, $log[0][1]['text']);
    assert_eq(1, (int)$p->query("SELECT COUNT(*) FROM gw_reminders WHERE user_id = 4 AND kind = 'places'")->fetchColumn(),
        'вступившему застолблено');

    gn_entry($p, $id, '200', 9200, GN_NOW + 1);
    $log = [];
    gw_after_places($p, gn_cfg(), ['row' => gw_load($p, $id), 'user' => '200'], GN_NOW + 1, gn_tg($log), gn_nosleep());
    assert_eq([], $log, 'осталось 4 — новых нет, повторов нет');

    gn_entry($p, $id, '201', 9201, GN_NOW + 2);
    $log = [];
    gw_after_places($p, gn_cfg(), ['row' => gw_load($p, $id), 'user' => '201'], GN_NOW + 2, gn_tg($log), gn_nosleep());
    assert_eq([502], gn_chats($log), 'осталось 3 — второму');
    assert_true(strpos($log[0][1]['text'], 'осталось 3 места из 20') !== false, $log[0][1]['text']);
});

test('по местам: без лимита и после закрытия приёма — никому', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_prefs($p, '1', ['places_left' => 100], GN_NOW - 86400);
    $id = gn_live($p, GN_NOW + 86400, GN_NOW - 86400);
    assert_eq([], gw_remind_places_due($p, gw_load($p, $id), GN_NOW), 'лимита нет');
    $id = gn_live($p, GN_NOW, GN_NOW - 86400, 2);
    assert_eq([], gw_remind_places_due($p, gw_load($p, $id), GN_NOW), 'приём закрыт');
});

test('два аккаунта на одном Telegram — одно напоминание', function () {
    $p = gn_db();
    gn_user($p, '1', 501);
    gn_user($p, '2', 501);
    gn_prefs($p, '1', ['time_left' => 3600], GN_NOW - 86400);
    gn_prefs($p, '2', ['time_left' => 3600], GN_NOW - 86400);
    gn_live($p, GN_NOW + 600, GN_NOW - 86400);
    $log = [];
    gw_remind_send($p, gn_tg($log), 'time', gw_remind_time_due($p, GN_NOW), GN_NOW, '', gn_nosleep());
    assert_eq([501], gn_chats($log), 'одно сообщение');
});

test('без миграции напоминаний нет и ничего не падает', function () {
    $p = gn_db(false);
    gn_user($p, '1', 501);
    $id = gn_live($p, GN_NOW + 600, GN_NOW - 86400, 5);
    assert_eq(false, gw_has_remind($p, GN_NOW), 'по времени');
    assert_eq([], gw_remind_places_due($p, gw_load($p, $id), GN_NOW), 'по местам');
});

test('сколько осталось — словами, две старшие единицы', function () {
    assert_eq('1 час', gw_span(3599, 'ru'), 'округление');
    assert_eq('3 часа 20 минут', gw_span(3 * 3600 + 1200, 'ru'), 'часы и минуты');
    assert_eq('1 день 4 часа', gw_span(86400 + 4 * 3600 + 59, 'ru'), 'дни и часы');
    assert_eq('2 дня', gw_span(2 * 86400, 'ru'), 'нули не пишутся');
    assert_eq('21 минута', gw_span(21 * 60, 'ru'), '21 — минута');
    assert_eq('11 минут', gw_span(11 * 60, 'ru'), '11 — минут');
    assert_eq('1 минута', gw_span(5, 'ru'), 'меньше минуты — минута');
    assert_eq('5 days 1 hour', gw_span(5 * 86400 + 3600, 'en'), 'по-английски');
});

test('эндпоинты зовут напоминания после ответа', function () {
    $pub = __DIR__ . '/../public_html/api';
    $list = file_get_contents("$pub/giveaways.php");
    assert_true(strpos($list, 'if ($remind) { gw_after_remind(db(), app_config(), time()); }') !== false, 'giveaways.php');
    assert_true(strpos($list, 'json_out(handle_giveaways(') < strpos($list, 'if ($remind) {'), 'после ответа');
    $enter = file_get_contents("$pub/giveaway_enter.php");
    assert_true(strpos($enter, "if (!empty(\$result[2]) && empty(\$result[2]['last'])) { gw_after_places(db(), \$cfg, \$result[2], time()); }") !== false,
        'giveaway_enter.php');
});

run_tests();
