<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/trade.php';
require __DIR__ . '/../public_html/api/lib/support.php';
require __DIR__ . '/../public_html/api/trades.php';
require __DIR__ . '/../public_html/api/profile-stats.php';

// Трейдинг (/trading) и центр обращений (/support): лента, публикация,
// закрытие, журнал профиля, поиск, обращения. Базы — SQLite в памяти со
// схемой из docs/migrations/2026-09-23-trading.sql.

const NOW = 1790000000;   // 2026-09-21

function trade_db(bool $withTables = true): PDO {
    $pdo = test_db();
    $doc = ['tiers' => [
        ['items' => [
            ['id' => 'idDragon', 'name' => 'Permanent Dragon (West + East)', 'value' => '14250'],
            ['id' => 'idKitsune', 'name' => 'Permanent Kitsune', 'value' => '12500'],
            ['id' => 'idDark', 'name' => 'Dark', 'value' => '0.3'],
        ]],
        ['items' => [
            ['id' => 'idBoat', 'name' => 'Fast Boat', 'value' => '570'],
            ['id' => 'idJunk', 'name' => 'Junk', 'value' => 'n/a'],
        ]],
    ]];
    $pdo->prepare('UPDATE tierlist SET data = :d WHERE id = 1')->execute([':d' => json_encode($doc)]);
    $ins = $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at, likes, dislikes)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $ins->execute([101, 'alice', 'Alice', 'https://tr.rbxcdn.com/a.png', NOW - 999, NOW - 999, NOW - 60, 7, 1]);
    $ins->execute([202, 'bob_99', 'Боб', 'https://evil.example/b.png', NOW - 999, NOW - 999, NOW - 9999, 0, 2]);
    $ins->execute([303, 'carol', '', '', NOW - 999, NOW - 999, 0, 0, 0]);
    if ($withTables) {
        $pdo->exec("CREATE TABLE trade_offers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            give TEXT NOT NULL,
            want TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            created_at INTEGER NOT NULL,
            closed_at INTEGER NULL
        )");
        $pdo->exec("CREATE TABLE profile_trades (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            status TEXT NOT NULL,
            value INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE support_tickets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'new',
            created_at INTEGER NOT NULL
        )");
    }
    return $pdo;
}

function offer(PDO $pdo, string $me, array $give, array $want = [], int $at = NOW): int {
    [$code, $body] = trade_create($pdo, $me, $give, $want, $at);
    if ($code !== 200) { throw new RuntimeException('trade_create: ' . json_encode($body)); }
    return $body['id'];
}

// --------------------------------------------------------------------------
//  Каталог и стороны
// --------------------------------------------------------------------------

test('каталог читается из тирлиста, нечисловая цена — ноль', function () {
    $cat = trade_catalog(trade_db());
    assert_eq(5, count($cat), 'все предметы с id');
    assert_eq(14250.0, $cat['idDragon']['value'], 'целая цена');
    assert_eq(0.3, $cat['idDark']['value'], 'дробная цена');
    assert_eq(0.0, $cat['idJunk']['value'], '"n/a" — ноль, как CALC.itemValue');
});

test('сторона: только известные id, не больше четырёх, повторы можно', function () {
    $cat = trade_catalog(trade_db());
    assert_eq(['idDark', 'idDark'], trade_clean_side(['idDark', 'idDark'], $cat), 'повтор');
    assert_eq([], trade_clean_side([], $cat), 'пустая сторона — список');
    assert_eq(null, trade_clean_side(['idNope'], $cat), 'предмета нет в тирлисте');
    assert_eq(null, trade_clean_side(['idDark', 'idDark', 'idDark', 'idDark', 'idDark'], $cat), 'пятый предмет');
    assert_eq(null, trade_clean_side('idDark', $cat), 'строка вместо списка');
    assert_eq(null, trade_clean_side([['idDark']], $cat), 'вложенный массив');
    assert_eq(null, trade_clean_side(['%"'], $cat), 'знаки LIKE и кавычки');
    assert_eq(['idBoat'], trade_clean_side(['k' => 'idBoat'], $cat), 'ключи объекта не мешают');
});

// --------------------------------------------------------------------------
//  Публикация
// --------------------------------------------------------------------------

test('публикация: аноним, пустая «отдаю», мусор, неготовая база', function () {
    $pdo = trade_db();
    assert_eq(401, trade_create($pdo, '', ['idDark'], [], NOW)[0], 'аноним');
    assert_eq(401, trade_create($pdo, '999', ['idDark'], [], NOW)[0], 'сессия без строки в users');
    assert_eq(400, trade_create($pdo, '101', [], ['idDark'], NOW)[0], 'отдавать нечего');
    assert_eq('empty_give', trade_create($pdo, '101', [], ['idDark'], NOW)[1]['error'], 'и это названо');
    assert_eq(400, trade_create($pdo, '101', ['idNope'], [], NOW)[0], 'чужой id');
    assert_eq(400, trade_create($pdo, '101', ['idDark'], 'idBoat', NOW)[0], 'хочу — строкой');
    assert_eq(503, trade_create(trade_db(false), '101', ['idDark'], [], NOW)[0], 'миграции нет — 503');
});

test('публикация: «хочу» можно оставить пустым — это «жду предложений»', function () {
    $pdo = trade_db();
    [$code, $body] = trade_create($pdo, '101', ['idDragon'], null, NOW);
    assert_eq(200, $code, 'принято');
    $row = $pdo->query('SELECT give, want, status FROM trade_offers WHERE id = ' . (int)$body['id'])->fetch(PDO::FETCH_ASSOC);
    assert_eq('["idDragon"]', $row['give'], 'отдаю');
    assert_eq('[]', $row['want'], 'хочу — пустой список');
    assert_eq('open', $row['status'], 'открыто');
});

test('открытых объявлений не больше пяти, истёкшие не считаются', function () {
    $pdo = trade_db();
    offer($pdo, '101', ['idDark'], [], NOW - TRADE_TTL - 10);   // истекло
    for ($i = 0; $i < TRADE_OPEN_MAX; $i++) { offer($pdo, '101', ['idDark']); }
    [$code, $body] = trade_create($pdo, '101', ['idDark'], [], NOW);
    assert_eq(409, $code, 'шестое не принимается');
    assert_eq('too_many', $body['error'], 'и это названо');
    assert_eq(200, trade_create($pdo, '202', ['idDark'], [], NOW)[0], 'у другого человека свой счёт');
});

// --------------------------------------------------------------------------
//  Закрытие и журнал профиля
// --------------------------------------------------------------------------

test('«сделка состоялась» пишет в журнал успех на сумму отданного', function () {
    $pdo = trade_db();
    $id = offer($pdo, '101', ['idDragon', 'idDark', 'idBoat'], ['idKitsune']);
    [$code, $body] = trade_close($pdo, '101', false, $id, 'done', NOW + 5);
    assert_eq(200, $code, 'закрыто');
    assert_eq('done', $body['status'], 'статус');
    $j = $pdo->query('SELECT user_id, day, status, value FROM profile_trades')->fetchAll(PDO::FETCH_ASSOC);
    assert_eq(1, count($j), 'одна запись');
    assert_eq('ok', $j[0]['status'], 'успех');
    assert_eq(14820, (int)$j[0]['value'], '14250 + 0.3 + 570, округлено');
    assert_eq(date('Y-m-d', NOW + 5), $j[0]['day'], 'день закрытия');
});

test('«отменить» пишет отказ без суммы; повтор и чужое — отказ', function () {
    $pdo = trade_db();
    $id = offer($pdo, '101', ['idDragon']);
    assert_eq(403, trade_close($pdo, '202', false, $id, 'cancel', NOW)[0], 'чужое не закрыть');
    assert_eq(403, trade_close($pdo, '202', false, $id, 'remove', NOW)[0], 'снять может только администратор');
    assert_eq(200, trade_close($pdo, '101', false, $id, 'cancel', NOW)[0], 'своё — можно');
    assert_eq(409, trade_close($pdo, '101', false, $id, 'done', NOW)[0], 'закрытое второй раз не закрыть');
    $j = $pdo->query('SELECT status, value FROM profile_trades')->fetchAll(PDO::FETCH_ASSOC);
    assert_eq(1, count($j), 'в журнале ровно одна запись');
    assert_eq('declined', $j[0]['status'], 'отказ');
    assert_eq(0, (int)$j[0]['value'], 'без суммы');
    assert_eq(404, trade_close($pdo, '101', false, 9999, 'done', NOW)[0], 'нет такого');
    assert_eq(400, trade_close($pdo, '101', false, $id, 'delete', NOW)[0], 'неизвестный исход');
    assert_eq(401, trade_close($pdo, '', false, $id, 'done', NOW)[0], 'аноним');
});

test('администратор снимает чужое, и в журнал автора это не идёт', function () {
    $pdo = trade_db();
    $id = offer($pdo, '101', ['idDragon']);
    [$code, $body] = trade_close($pdo, '', true, $id, 'remove', NOW);
    assert_eq(200, $code, 'снято');
    assert_eq('removed', $body['status'], 'статус модерации');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM profile_trades')->fetchColumn(), 'журнал пуст');
    assert_eq(0, trade_created_count($pdo, '101'), 'снятое модератором не считается созданным');
});

test('закрытие переживает отсутствие журнала', function () {
    $pdo = trade_db();
    $pdo->exec('DROP TABLE profile_trades');
    $id = offer($pdo, '101', ['idDragon']);
    assert_eq(200, trade_close($pdo, '101', false, $id, 'done', NOW)[0], 'объявление закрыто');
    assert_eq('done', $pdo->query("SELECT status FROM trade_offers WHERE id = $id")->fetchColumn(), 'статус записан');
});

// --------------------------------------------------------------------------
//  Лента
// --------------------------------------------------------------------------

test('лента: открытые и живые, свежие сверху, автор с репутацией и статусом', function () {
    $pdo = trade_db();
    $old  = offer($pdo, '202', ['idBoat'], [], NOW - TRADE_TTL - 1);
    $a    = offer($pdo, '101', ['idDragon'], ['idKitsune', 'idKitsune']);
    $b    = offer($pdo, '202', ['idDark']);
    $done = offer($pdo, '202', ['idBoat']);
    trade_close($pdo, '202', false, $done, 'done', NOW);

    $feed = trade_feed($pdo, '', '', 0, NOW);
    assert_eq(true, $feed['ready'], 'таблица есть');
    assert_eq([$b, $a], array_column($feed['offers'], 'id'), 'без истёкших и закрытых, новые сверху');
    $first = $feed['offers'][1];
    assert_eq(['idKitsune', 'idKitsune'], $first['want'], 'стороны — списки id');
    assert_eq('Alice', $first['author']['nick'], 'ник');
    assert_eq('@alice', $first['author']['handle'], '@ник');
    assert_eq('online', $first['author']['status'], 'была минуту назад');
    assert_eq(7, $first['author']['likes'], 'репутация +');
    assert_eq(1, $first['author']['dislikes'], 'репутация −');
    assert_eq('offline', $feed['offers'][0]['author']['status'], 'Боб давно не заходил');
    assert_eq('', $feed['offers'][0]['author']['avatar'], 'чужой хост аватара не отдаётся');
    assert_eq(false, $first['mine'], 'аноним — ничего своего');
    assert_eq([], $feed['mine'], 'и отдельного списка нет');
});

test('лента: свои едут отдельно на первой странице и помечены', function () {
    $pdo = trade_db();
    $mine = offer($pdo, '101', ['idDragon']);
    offer($pdo, '202', ['idDark']);
    $feed = trade_feed($pdo, '101', '', 0, NOW);
    assert_eq([$mine], array_column($feed['mine'], 'id'), 'свой список');
    assert_eq(true, $feed['mine'][0]['mine'], 'помечено');
    assert_eq(2, count($feed['offers']), 'из общей ленты своё не пропадает');
    assert_eq([], trade_feed($pdo, '101', '', 5, NOW)['mine'], 'на следующих страницах своего нет');
    assert_eq([], trade_feed($pdo, '101', 'dark', 0, NOW)['mine'], 'при поиске тоже');
});

test('лента: страницы по id', function () {
    $pdo = trade_db();
    // Через trade_create столько не завести (пять открытых на человека),
    // поэтому строки кладутся напрямую.
    $ins = $pdo->prepare("INSERT INTO trade_offers (user_id, give, want, status, created_at) VALUES (101, '[\"idDark\"]', '[]', 'open', ?)");
    for ($i = 0; $i < TRADE_PAGE_SIZE + 3; $i++) { $ins->execute([NOW - $i]); }
    $p1 = trade_feed($pdo, '', '', 0, NOW);
    assert_eq(TRADE_PAGE_SIZE, count($p1['offers']), 'первая страница полная');
    assert_eq(true, $p1['more'], 'есть ещё');
    $last = end($p1['offers'])['id'];
    $p2 = trade_feed($pdo, '', '', $last, NOW);
    assert_eq(3, count($p2['offers']), 'остаток');
    assert_eq(false, $p2['more'], 'дальше пусто');
    assert_true(max(array_column($p2['offers'], 'id')) < $last, 'вторая страница — строго старше');
});

test('поиск: по названию предмета на любой стороне и по нику', function () {
    $pdo = trade_db();
    $a = offer($pdo, '101', ['idDragon']);
    $b = offer($pdo, '202', ['idDark'], ['idKitsune']);
    $c = offer($pdo, '303', ['idBoat']);
    assert_eq([$a], array_column(trade_feed($pdo, '', 'dragon', 0, NOW)['offers'], 'id'), 'предмет «отдаю»');
    assert_eq([$b], array_column(trade_feed($pdo, '', 'KITSUNE', 0, NOW)['offers'], 'id'), '«хочу», без учёта регистра');
    assert_eq([$b, $a], array_column(trade_feed($pdo, '', 'permanent', 0, NOW)['offers'], 'id'), 'совпало два предмета');
    assert_eq([$b], array_column(trade_feed($pdo, '', 'bob', 0, NOW)['offers'], 'id'), 'по @нику');
    assert_eq([$c], array_column(trade_feed($pdo, '', 'carol', 0, NOW)['offers'], 'id'), 'ник без отображаемого имени');
    assert_eq([], trade_feed($pdo, '', 'zzz', 0, NOW)['offers'], 'ничего');
    assert_eq([$b], array_column(trade_feed($pdo, '', 'b_9', 0, NOW)['offers'], 'id'), '_ в запросе — буква');
    assert_eq([], trade_feed($pdo, '', '%', 0, NOW)['offers'], '% в запросе — буква, а не «всё»');
});

test('поисковая строка чистится и режется', function () {
    assert_eq('', trade_clean_query(['x']), 'массив');
    assert_eq('a b', trade_clean_query("  a \n\t b "), 'пробелы схлопнуты');
    assert_eq(TRADE_QUERY_MAX, mb_strlen(trade_clean_query(str_repeat('я', 100))), 'предел в символах');
});

test('лента без миграции — пустая, но не ошибка', function () {
    $feed = trade_feed(trade_db(false), '101', '', 0, NOW);
    assert_eq(false, $feed['ready'], 'не готова');
    assert_eq([], $feed['offers'], 'пусто');
});

test('ответ ленты говорит, вошёл ли человек и админ ли он', function () {
    $pdo = trade_db();
    [$code, $body] = handle_trades($pdo, ['user_id' => '101'], ['q' => ['x'], 'before' => 'abc'], NOW);
    assert_eq(200, $code, '200');
    assert_eq(true, $body['authed'], 'вошёл');
    assert_eq(false, $body['admin'], 'не админ');
    [, $anon] = handle_trades($pdo, ['admin' => true], [], NOW);
    assert_eq(false, $anon['authed'], 'админ по паролю — не вошедший игрок');
    assert_eq(true, $anon['admin'], 'но админ');
});

// --------------------------------------------------------------------------
//  Профиль: «созданные» считают объявления
// --------------------------------------------------------------------------

test('профиль: созданные — это объявления, включая открытые', function () {
    $pdo = trade_db();
    $x = offer($pdo, '101', ['idDragon']);
    offer($pdo, '101', ['idDark']);
    offer($pdo, '101', ['idBoat']);
    trade_close($pdo, '101', false, $x, 'done', NOW);
    [, $body] = handle_profile_stats($pdo, ['user_id' => '101'], [], null, date('Y-m-d', NOW), NOW);
    assert_eq(true, $body['available'], 'журнал есть');
    assert_eq(1, $body['lifetime']['ok'], 'сделок — одна');
    assert_eq(0, $body['lifetime']['declined'], 'отмен нет');
    assert_eq(3, $body['lifetime']['total'], 'создано три');
    assert_eq(14250, $body['totals']['sum'], 'оборот месяца — отданное');
});

// --------------------------------------------------------------------------
//  Центр обращений
// --------------------------------------------------------------------------

test('обращение: только вошедший, длина в пределах, текст чистится', function () {
    $pdo = trade_db();
    assert_eq(401, support_submit($pdo, '', 'Помогите пожалуйста', NOW)[0], 'аноним');
    assert_eq(400, support_submit($pdo, '101', 'коротко', NOW)[0], 'слишком коротко');
    assert_eq(400, support_submit($pdo, '101', str_repeat('я', SUPPORT_BODY_MAX + 1), NOW)[0], 'слишком длинно');
    assert_eq(400, support_submit($pdo, '101', ['x'], NOW)[0], 'не строка');
    assert_eq(503, support_submit(trade_db(false), '101', 'Помогите пожалуйста', NOW)[0], 'миграции нет');
    [$code, $body] = support_submit($pdo, '101', "  Трейдер @bob\x07 не отдал фрукт\r\n\r\n\r\n\r\nпосле сделки  ", NOW);
    assert_eq(200, $code, 'принято');
    $saved = $pdo->query('SELECT body FROM support_tickets WHERE id = ' . (int)$body['id'])->fetchColumn();
    assert_eq("Трейдер @bob не отдал фрукт\n\nпосле сделки", $saved, 'управляющие вон, пустые строки схлопнуты');
});

test('обращения для администратора: новые сверху, с ником; отметка', function () {
    $pdo = trade_db();
    $one = support_submit($pdo, '101', 'Первое обращение', NOW)[1]['id'];
    $two = support_submit($pdo, '202', 'Второе обращение', NOW)[1]['id'];
    assert_true(support_set_status($pdo, $two, 'done'), 'второе решено');
    $list = support_list($pdo);
    assert_eq([$one, $two], array_column($list, 'id'), 'нерешённое выше');
    assert_eq('Alice', $list[0]['nick'], 'ник');
    assert_eq('@alice', $list[0]['handle'], '@ник');
    assert_eq('done', $list[1]['status'], 'статус');
    assert_eq(false, support_set_status($pdo, $one, 'deleted'), 'неизвестный статус');
    assert_eq([], support_list(trade_db(false)), 'без таблицы — пусто');
});

run_tests();
