<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/hunt_schema.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/hunt.php';
require __DIR__ . '/../public_html/api/hunt.php';
require __DIR__ . '/../public_html/api/hunt_me.php';
require __DIR__ . '/../public_html/api/hunt_act.php';
require __DIR__ . '/../public_html/api/hunt_admin.php';
require __DIR__ . '/../public_html/api/session.php';
require_once __DIR__ . '/../public_html/api/lib/chat.php';

// Ивент Spooky Tokens — api/lib/hunt.php и его эндпоинты.
//
// Главные обещания:
//  - одна и та же награда не начисляется дважды — ни из второй вкладки, ни
//    повтором запроса;
//  - вход награждает только за сегодняшний день, пропущенный не догоняется;
//  - время на сайте не больше прошедшего, две вкладки не дают двух минут;
//  - день — сутки по Москве;
//  - цена билета — половина всех токенов, билетов не больше двух, трата не
//    уменьшает «собрано»;
//  - титулы — за все 7 входов и за все 14 часовых наград;
//  - в итогах один человек — один приз; аннулированные и забаненные не
//    выигрывают; неявившегося заменяет следующий по очереди;
//  - после старта админка не меняет дату и награды.

const HT_DAY  = 86400;
const HT_ROOT = __DIR__ . '/..';

// Начало сезона — полночь 31 октября 2026 по Москве (30.10 21:00 UTC).
function ht_start(): int {
    return hunt_date_ts('2026-10-31');
}

// Момент внутри дня N ивента: полдень по Москве.
function ht_at(int $n, int $plus = 0): int {
    return ht_start() + ($n - 1) * HT_DAY + 12 * 3600 + $plus;
}

function ht_db(): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE tg_links (
        user_id INTEGER NOT NULL PRIMARY KEY, chat_id INTEGER NOT NULL,
        tg_name TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
    hunt_sqlite_tables($pdo);
    return $pdo;
}

function ht_user(PDO $p, string $id, string $name, ?int $chat = null): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                 VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $name, ucfirst($name), '', ht_start() - HT_DAY, ht_start()]);
    if ($chat !== null) {
        $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, linked_at) VALUES (?, ?, ?, ?)')
          ->execute([$id, $chat, '@' . $name, ht_start()]);
    }
    return $id;
}

function ht_insert(PDO $p, string $table, array $row): int {
    $cols = array_keys($row);
    $p->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
      ->execute(array_values($row));
    return (int)$p->lastInsertId();
}

function ht_hunt(PDO $p, array $extra = []): int {
    $start = ht_start();
    return ht_insert($p, 'hunts', array_merge([
        'slug' => 'halloween-2026', 'status' => 'live', 'title_ru' => 'Spooky Tokens', 'title_en' => 'Spooky Tokens',
        'starts_at' => $start, 'sales_end_at' => $start + 8 * HT_DAY, 'draw_at' => $start + 8 * HT_DAY + 3600,
        'login_json' => json_encode(HUNT_LOGIN_DEFAULT), 'hours_json' => json_encode(HUNT_HOURS_DEFAULT),
        'claim_days' => 7,
        'prizes_json' => json_encode([['name' => 'Gas', 'count' => 1], ['name' => 'Chromatic Box', 'count' => 2]]),
        'titles_json' => json_encode(['login' => ['ru' => 'Ночной дозор', 'en' => 'Night Watch'],
                                      'hours' => ['ru' => 'Хранитель склепа', 'en' => 'Crypt Keeper']]),
        'created_at' => $start - HT_DAY, 'updated_at' => $start - HT_DAY,
    ], $extra));
}

function ht_wallet(PDO $p, int $hunt, string $uid): array {
    return hunt_wallet_out(hunt_wallet($p, $hunt, $uid));
}

function ht_give(PDO $p, int $hunt, string $uid, int $n, string $ref = 'admin:seed'): void {
    hunt_award($p, $hunt, $uid, 'admin', $ref, $n, ht_at(1));
}

function ht_secs(PDO $p, int $hunt, string $uid, int $day): int {
    $row = hunt_time_row($p, $hunt, $uid, $day);
    return $row ? $row['secs'] : -1;
}

// --------------------------------------------------------------------------
//  Таблицы, дни, фазы, числа
// --------------------------------------------------------------------------

test('миграция и schema.sql описывают таблицы ивента одинаково', function () {
    $norm = function (string $s): string { return str_replace("\r\n", "\n", $s); };
    $mig = $norm((string)file_get_contents(HT_ROOT . '/docs/migrations/2026-10-30-hunt.sql'));
    $schema = $norm((string)file_get_contents(HT_ROOT . '/schema.sql'));
    preg_match_all('~CREATE TABLE IF NOT EXISTS hunt\w* \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;~s', $mig, $m);
    assert_eq(7, count($m[0]), 'семь таблиц в миграции');
    foreach ($m[0] as $ddl) {
        preg_match('~EXISTS (\w+)~', $ddl, $name);
        assert_true(strpos($schema, $ddl) !== false, "schema.sql: $name[1] как в миграции");
    }
    assert_true(hunt_ready(ht_db()), 'SQLite-копия схемы полная');
    assert_eq(false, hunt_ready(test_db()), 'без таблиц — не готово');
});

test('день ивента — сутки по Москве', function () {
    $h = ['status' => 'live', 'starts_at' => ht_start(), 'sales_end_at' => ht_start() + 8 * HT_DAY];
    assert_eq(1793394000, ht_start(), '31.10 00:00 МСК = 30.10 21:00 UTC');
    assert_eq(0, hunt_day_n($h, ht_start() - 1));
    assert_eq(1, hunt_day_n($h, ht_start()));
    assert_eq(1, hunt_day_n($h, ht_start() + HT_DAY - 1), '23:59:59 по Москве — ещё день 1');
    assert_eq(2, hunt_day_n($h, ht_start() + HT_DAY));
    assert_eq(7, hunt_day_n($h, ht_start() + 7 * HT_DAY - 1));
    assert_eq(0, hunt_day_n($h, ht_start() + 7 * HT_DAY), 'восьмого дня нет');
    assert_eq(ht_start() + HT_DAY, hunt_next_day_at(ht_at(1)));
    assert_eq(null, hunt_date_ts('2026-02-30'));
    assert_eq('2026-10-31', hunt_ts_date(ht_start()));
});

test('фазы сезона', function () {
    $h = ['status' => 'live', 'starts_at' => ht_start(), 'sales_end_at' => ht_start() + 8 * HT_DAY];
    assert_eq('draft', hunt_phase(['status' => 'draft'] + $h, ht_at(1)));
    assert_eq('soon', hunt_phase($h, ht_start() - 1));
    assert_eq('open', hunt_phase($h, ht_at(7)));
    assert_eq('sales', hunt_phase($h, ht_start() + 7 * HT_DAY));
    assert_eq('closed', hunt_phase($h, ht_start() + 8 * HT_DAY));
    assert_eq('drawn', hunt_phase(['status' => 'drawn'] + $h, ht_at(1)));
});

test('потолок — все награды, билет — половина потолка', function () {
    $h = ['login_json' => json_encode(HUNT_LOGIN_DEFAULT), 'hours_json' => json_encode(HUNT_HOURS_DEFAULT)];
    assert_eq(420, hunt_cap($h), '175 за вход + 7 × 35 за часы');
    assert_eq(210, hunt_price($h));
    $odd = ['login_json' => json_encode([1, 1, 1, 1, 1, 1, 1]), 'hours_json' => json_encode([0, 0])];
    assert_eq(3, hunt_price($odd), 'нечётный потолок — вниз: два билета не дороже всего собранного');
    assert_eq(HUNT_LOGIN_DEFAULT, hunt_login_rewards(['login_json' => '[1,2]']), 'кривой список — по умолчанию');
    assert_eq(HUNT_HOURS_DEFAULT, hunt_hour_rewards(['hours_json' => '["a","b"]']));
});

// --------------------------------------------------------------------------
//  Награда за вход
// --------------------------------------------------------------------------

test('вход: сегодняшняя награда один раз, пропущенный день не догоняется', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $hunt = hunt_load($p, $h);
    $u = ht_user($p, '1', 'ann');
    $a = hunt_login_award($p, $hunt, $u, ht_at(1));
    assert_eq([['kind' => 'login', 'n' => 1, 'delta' => 10]], $a['gains']);
    assert_eq([], hunt_login_award($p, $hunt, $u, ht_at(1, 3600))['gains'], 'второй заход в тот же день');
    assert_eq([['kind' => 'login', 'n' => 3, 'delta' => 20]], hunt_login_award($p, $hunt, $u, ht_at(3))['gains'],
        'день 3 после пропуска дня 2 — награда дня 3');
    assert_eq(['balance' => 30, 'earned' => 30, 'tickets' => 0], ht_wallet($p, $h, $u));
    assert_eq(0, (int)$p->query("SELECT COUNT(*) FROM hunt_ledger WHERE ref = 'login:2'")->fetchColumn(), 'день 2 не выдан');
    $cal = hunt_calendar($hunt, hunt_refs($p, $h, $u), [], ht_at(3));
    assert_eq(['got', 'missed', 'got', 'future'], array_map(function ($d) { return $d['login']['state']; }, array_slice($cal, 0, 4)));
    assert_eq([10, 15, 20, 25, 30, 35, 40], array_map(function ($d) { return $d['login']['reward']; }, $cal));
});

test('вход вне семи дней и до старта ничего не даёт', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $hunt = hunt_load($p, $h);
    $u = ht_user($p, '1', 'ann');
    assert_eq([], hunt_login_award($p, $hunt, $u, ht_start() - 60)['gains'], 'скоро');
    assert_eq([], hunt_login_award($p, $hunt, $u, ht_start() + 7 * HT_DAY + 60)['gains'], 'продажа билетов');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM hunt_ledger')->fetchColumn());
});

test('все 7 входов дают титул ровно один раз', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $hunt = hunt_load($p, $h);
    $u = ht_user($p, '1', 'ann');
    for ($n = 1; $n <= 6; $n++) {
        assert_eq([], hunt_login_award($p, $hunt, $u, ht_at($n))['titles_new'], "день $n — титула ещё нет");
    }
    $a = hunt_login_award($p, $hunt, $u, ht_at(7));
    assert_eq([['kind' => 'login', 'title' => ['ru' => 'Ночной дозор', 'en' => 'Night Watch']]], $a['titles_new']);
    assert_eq(175, ht_wallet($p, $h, $u)['earned']);
    assert_eq([['kind' => 'login', 'title' => ['ru' => 'Ночной дозор', 'en' => 'Night Watch']]], hunt_my_titles($p, $u));
    assert_eq([], hunt_login_award($p, $hunt, $u, ht_at(7, 60))['titles_new'], 'повтор не даёт титул снова');
});

// --------------------------------------------------------------------------
//  Время на сайте
// --------------------------------------------------------------------------

test('пульс: первый ставит отметку, дальше — время с прошлого, не больше прошедшего', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $t = ht_at(2);
    [$c, $b] = hunt_beat($p, [], $u, $t);
    assert_eq([200, 'done', 2, 0, false], [$c, $b['status'], $b['day'], $b['secs'], $b['full']]);
    assert_eq(60, hunt_beat($p, [], $u, $t + 60)[1]['secs']);
    assert_eq(90, hunt_beat($p, [], $u, $t + 90)[1]['secs'], 'полминуты — полминуты');
    assert_eq(90, hunt_beat($p, [], $u, $t + 90)[1]['secs'], 'повтор в ту же секунду ничего не добавляет');
    assert_eq(155, hunt_beat($p, [], $u, $t + 200)[1]['secs'], 'пропуск 110 с — засчитано не больше 65');
    assert_eq(155, hunt_beat($p, [], $u, $t + 500)[1]['secs'], 'перерыв дольше 2 минут — только отметка');
    assert_eq(215, hunt_beat($p, [], $u, $t + 560)[1]['secs']);
});

test('две вкладки не дают двух минут за минуту', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $t = ht_at(1);
    hunt_beat($p, [], $u, $t);
    for ($i = 1; $i <= 20; $i++) {
        hunt_beat($p, [], $u, $t + 30 * $i);
        hunt_beat($p, [], $u, $t + 30 * $i);
    }
    assert_eq(600, ht_secs($p, $h, $u, 1), '20 пульсов по две вкладки за 10 минут — 10 минут');
});

test('час и два часа за сутки — награды, потом full', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $t = ht_at(3);
    ht_insert($p, 'hunt_time', ['hunt_id' => $h, 'user_id' => $u, 'day' => 3, 'secs' => 3580, 'beat_at' => $t]);
    [, $b] = hunt_beat($p, [], $u, $t + 30);
    assert_eq([3610, [['kind' => 'hour', 'n' => 3, 'k' => 1, 'delta' => 15]]], [$b['secs'], $b['gains']]);
    assert_eq(15, $b['wallet']['earned']);
    assert_eq([], hunt_beat($p, [], $u, $t + 60)[1]['gains'], 'вторая награда за первый час не приходит');
    $p->exec("UPDATE hunt_time SET secs = 7190 WHERE day = 3");
    [, $b] = hunt_beat($p, [], $u, $t + 120);
    assert_eq(['full', 7200, true, 20], [$b['status'], $b['secs'], $b['full'], $b['gains'][0]['delta']]);
    assert_eq(['full', 7200, []], [hunt_beat($p, [], $u, $t + 180)[1]['status'], ht_secs($p, $h, $u, 3), hunt_beat($p, [], $u, $t + 240)[1]['gains']]);
    assert_eq(35, ht_wallet($p, $h, $u)['earned']);
    [, $b] = hunt_beat($p, [], $u, ht_at(4));
    assert_eq(['done', 4, 0], [$b['status'], $b['day'], $b['secs']], 'новые сутки по Москве — заново');
});

test('все 14 часовых наград дают второй титул', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    for ($n = 1; $n <= 7; $n++) {
        foreach ([1, 2] as $k) {
            if ($n === 7 && $k === 2) { continue; }
            ht_insert($p, 'hunt_ledger', ['hunt_id' => $h, 'user_id' => $u, 'delta' => 1, 'kind' => 'hour',
                'ref' => "hour:$n:$k", 'created_at' => ht_at($n)]);
        }
    }
    ht_insert($p, 'hunt_time', ['hunt_id' => $h, 'user_id' => $u, 'day' => 7, 'secs' => 7170, 'beat_at' => ht_at(7)]);
    [, $b] = hunt_beat($p, [], $u, ht_at(7, 30));
    assert_eq([['kind' => 'hours', 'title' => ['ru' => 'Хранитель склепа', 'en' => 'Crypt Keeper']]], $b['titles_new']);
    assert_eq(['hours'], array_column(hunt_titles_for($p, [$u, '999'])[$u], 'kind'));
    assert_eq(false, isset(hunt_titles_for($p, [$u, '999'])['999']), 'у кого нет титулов — ключа нет');
});

test('пульс: гость, забаненный, вне семи дней', function () {
    $p = ht_db();
    ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    assert_eq([401, 'login'], [hunt_beat($p, [], '', ht_at(1))[0], hunt_beat($p, [], '', ht_at(1))[1]['status']]);
    assert_eq('banned', hunt_beat($p, ['banned_ids' => ['1']], $u, ht_at(1))[1]['status']);
    assert_eq('closed', hunt_beat($p, [], $u, ht_start() - 1)[1]['status'], 'до старта');
    assert_eq('closed', hunt_beat($p, [], $u, ht_start() + 7 * HT_DAY)[1]['status'], 'после семи дней');
    assert_eq('closed', hunt_beat(test_db(), [], $u, ht_at(1))[1]['status'], 'таблиц нет');
});

test('титулы едут с человеком в чате и трейдинге; без таблиц — пусто', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['titles_json' => json_encode(['login' => ['ru' => 'Дозорный', 'en' => 'Watcher']])]);
    $a = ht_user($p, '1', 'ann');
    $b = ht_user($p, '2', 'bob');
    ht_insert($p, 'hunt_titles', ['user_id' => $a, 'hunt_id' => $h, 'kind' => 'login', 'created_at' => ht_at(7)]);
    ht_insert($p, 'hunt_titles', ['user_id' => $a, 'hunt_id' => $h, 'kind' => 'hours', 'created_at' => ht_at(7, 1)]);
    $chat = chat_users_by_id($p, [$a, $b], ht_at(7));
    assert_eq([['kind' => 'login', 'title' => ['ru' => 'Дозорный', 'en' => 'Watcher']],
               ['kind' => 'hours', 'title' => HUNT_TITLES_DEFAULT['hours']]], $chat[$a]['titles'], 'пустой текст — по умолчанию');
    assert_eq([], $chat[$b]['titles']);
    assert_eq(2, count(trade_authors($p, [$a], ht_at(7))[$a]['titles']));
    $bare = test_db();
    $bare->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at) VALUES (?, ?, ?, ?, ?, ?)')
         ->execute(['1', 'ann', 'Ann', '', 1, 1]);
    assert_eq([], chat_users_by_id($bare, ['1'], ht_at(7))['1']['titles'], 'миграция не выполнена — чат не падает');
    assert_eq('Watcher', hunt_title_text(['ru' => 'Дозорный', 'en' => 'Watcher'], 'de'));
});

// --------------------------------------------------------------------------
//  Билеты
// --------------------------------------------------------------------------

test('билет: списание без потери «собрано», номер, даты', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 230);
    [$c, $b] = hunt_buy_ticket($p, [], $u, 1, ht_at(2));
    assert_eq([200, 'done'], [$c, $b['status']]);
    assert_eq(['balance' => 20, 'earned' => 230, 'tickets' => 1], $b['wallet'], 'счётчик не откатился');
    assert_eq('#0001', $b['ticket']['no']);
    assert_eq([210, 2, ht_start() + 8 * HT_DAY], [$b['price'], $b['limit'], $b['sales_end_at']]);
    $ledger = $p->query("SELECT delta, kind FROM hunt_ledger WHERE ref = 'ticket:1'")->fetch(PDO::FETCH_ASSOC);
    assert_eq(['delta' => -210, 'kind' => 'ticket'], ['delta' => (int)$ledger['delta'], 'kind' => $ledger['kind']]);
});

test('повтор покупки не создаёт второй билет, отставшая вкладка — stale', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 420);
    hunt_buy_ticket($p, [], $u, 1, ht_at(2));
    [, $b] = hunt_buy_ticket($p, [], $u, 1, ht_at(2, 1));
    assert_eq(['already', 210, '#0001'], [$b['status'], $b['wallet']['balance'], $b['ticket']['no']], 'перезагрузка');
    assert_eq('stale', hunt_buy_ticket($p, [], $u, 3, ht_at(2))[1]['status'], 'перескок через номер');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM hunt_tickets')->fetchColumn());
});

test('не хватает токенов, лимит 2, продажа после семи дней, конец продажи', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 200);
    [$c, $b] = hunt_buy_ticket($p, [], $u, 1, ht_at(2));
    assert_eq([409, 'balance', 10], [$c, $b['status'], $b['need']]);
    ht_give($p, $h, $u, 500, 'admin:more');
    assert_eq('done', hunt_buy_ticket($p, [], $u, 1, ht_at(2))[1]['status']);
    assert_eq('done', hunt_buy_ticket($p, [], $u, 2, ht_start() + 7 * HT_DAY + 60)[1]['status'], 'в фазе sales билеты продаются');
    assert_eq('limit', hunt_buy_ticket($p, [], $u, 3, ht_at(3))[1]['status'], 'третий при лимите 2');
    $v = ht_user($p, '2', 'bob');
    ht_give($p, $h, $v, 300);
    assert_eq('closed', hunt_buy_ticket($p, [], $v, 1, ht_start() + 8 * HT_DAY)[1]['status'], 'продажа закрыта');
    assert_eq('banned', hunt_buy_ticket($p, ['banned_ids' => ['2']], $v, 1, ht_at(2))[1]['status']);
});

// --------------------------------------------------------------------------
//  Итоги и выдача
// --------------------------------------------------------------------------

// Без перемешивания: $rand всегда отвечает «последний», и очередь остаётся
// в порядке id билетов — так тест знает, кто за кем.
function ht_keep_order(): callable {
    return function (int $n): int { return $n - 1; };
}

function ht_tickets(PDO $p, int $h, array $owners): void {
    $seq = [];
    foreach ($owners as $u) {
        $seq[$u] = ($seq[$u] ?? 0) + 1;
        ht_insert($p, 'hunt_tickets', ['hunt_id' => $h, 'user_id' => $u, 'seq' => $seq[$u], 'created_at' => ht_at(3)]);
    }
}

function ht_winners(PDO $p, int $h): array {
    $st = $p->prepare('SELECT user_id, prize_name, status FROM hunt_wins WHERE hunt_id = ? ORDER BY id');
    $st->execute([$h]);
    return array_map(function ($r) { return [(string)$r['user_id'], $r['prize_name'], $r['status']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

test('итоги только после конца продажи и только раз', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $end = ht_start() + 8 * HT_DAY;
    assert_eq('not_ended', hunt_draw($p, [], $h, $end - 1, ht_keep_order())[1]['status']);
    assert_eq(200, hunt_draw($p, [], $h, $end, ht_keep_order())[0]);
    assert_eq('drawn', hunt_draw($p, [], $h, $end, ht_keep_order())[1]['status']);
});

test('один человек — один приз; аннулированные и забаненные не выигрывают', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    foreach (['1' => 'ann', '2' => 'bob', '3' => 'cat', '4' => 'dan', '5' => 'eve'] as $id => $n) { ht_user($p, (string)$id, $n); }
    ht_tickets($p, $h, ['1', '1', '2', '3', '4', '5']);
    $p->exec("UPDATE hunt_tickets SET void = 1 WHERE user_id = 2");
    $end = ht_start() + 8 * HT_DAY;
    [$c, $b] = hunt_draw($p, ['banned_ids' => ['3']], $h, $end, ht_keep_order());
    assert_eq([200, 3, 4], [$c, $b['winners'], $b['tickets']]);
    assert_eq([['1', 'Gas', 'won'], ['4', 'Chromatic Box', 'won'], ['5', 'Chromatic Box', 'won']], ht_winners($p, $h));
    assert_eq($end + 7 * HT_DAY, (int)$p->query('SELECT deadline FROM hunt_wins LIMIT 1')->fetchColumn(), 'срок — claim_days');
    assert_eq('drawn', hunt_load($p, $h)['status']);
});

test('настоящее перемешивание раздаёт все призы разным людям', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $owners = [];
    for ($i = 1; $i <= 20; $i++) { ht_user($p, (string)$i, "u$i"); $owners[] = (string)$i; $owners[] = (string)$i; }
    ht_tickets($p, $h, $owners);
    hunt_draw($p, [], $h, ht_start() + 8 * HT_DAY);
    $w = ht_winners($p, $h);
    assert_eq(3, count($w));
    assert_eq(3, count(array_unique(array_column($w, 0))), 'три разных человека');
});

test('просрочка и «Перевыбрать» по очереди итогов', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Gas', 'count' => 1]])]);
    foreach (['1' => 'ann', '2' => 'bob', '3' => 'cat'] as $id => $n) { ht_user($p, (string)$id, $n); }
    ht_tickets($p, $h, ['1', '1', '2', '3']);
    $end = ht_start() + 8 * HT_DAY;
    hunt_draw($p, ['banned_ids' => []], $h, $end, ht_keep_order());
    $win = (int)$p->query('SELECT id FROM hunt_wins')->fetchColumn();
    assert_eq('not_expired', hunt_redraw($p, [], $win, $end + HT_DAY)[1]['status'], 'срок не вышел');
    assert_eq(1, hunt_expire_wins($p, $h, $end + 8 * HT_DAY));
    [$c, $b] = hunt_redraw($p, ['banned_ids' => ['2']], $win, $end + 8 * HT_DAY);
    assert_eq([200, '3', 'Gas'], [$c, $b['user_id'], $b['win']['prize']['name']], 'второй билет ann и забаненный bob пропущены');
    assert_eq([['1', 'Gas', 'redrawn'], ['3', 'Gas', 'won']], ht_winners($p, $h));
    assert_eq('not_expired', hunt_redraw($p, [], $win, $end + 8 * HT_DAY)[1]['status'], 'дважды не отдать');
});

test('победитель подтверждает выигрыш с Telegram, выдачу ведёт админка', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Gas', 'count' => 2]])]);
    $a = ht_user($p, '1', 'ann');
    $b = ht_user($p, '2', 'bob', 777);
    ht_tickets($p, $h, ['1', '2']);
    $end = ht_start() + 8 * HT_DAY;
    hunt_draw($p, [], $h, $end, ht_keep_order());
    $wa = (int)$p->query('SELECT id FROM hunt_wins WHERE user_id = 1')->fetchColumn();
    $wb = (int)$p->query('SELECT id FROM hunt_wins WHERE user_id = 2')->fetchColumn();
    assert_eq('bad', hunt_confirm_win($p, $b, $wa, $end)[1]['status'], 'чужой выигрыш');
    assert_eq('tg', hunt_confirm_win($p, $a, $wa, $end)[1]['status'], 'без Telegram не связаться');
    assert_eq('done', hunt_confirm_win($p, $b, $wb, $end)[1]['status']);
    assert_eq('already', hunt_confirm_win($p, $b, $wb, $end)[1]['status']);
    assert_eq('bad_move', hunt_win_set($p, $wb, 'given', '', $end)[1]['status'], 'через ступеньку нельзя');
    assert_eq('queued', hunt_win_set($p, $wb, 'queued', 'трейд в субботу', $end)[1]['win']['status']);
    assert_eq('given', hunt_win_set($p, $wb, 'given', 'выдан', $end)[1]['win']['status']);
    assert_eq('expired', hunt_confirm_win($p, $a, $wa, $end + 8 * HT_DAY)[1]['status'], 'опоздал');
});

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

test('публичное: числа, фазы, титулы; без сезона и с черновиком — null', function () {
    $p = ht_db();
    assert_eq(['hunt' => null], hunt_public($p, ht_at(1)));
    $h = ht_hunt($p, ['status' => 'draft']);
    assert_eq(['hunt' => null], hunt_public($p, ht_at(1)), 'черновик не виден');
    $p->exec("UPDATE hunts SET status = 'live'");
    $pub = hunt_public($p, ht_at(3))['hunt'];
    assert_eq(['open', 3, 7, 420, 210, 2], [$pub['phase'], $pub['day'], $pub['days'], $pub['cap'], $pub['price'], $pub['limit']]);
    assert_eq([HUNT_LOGIN_DEFAULT, HUNT_HOURS_DEFAULT], [$pub['login'], $pub['hours']]);
    assert_eq(ht_start() + 7 * HT_DAY, $pub['ends_at']);
    assert_eq('Crypt Keeper', $pub['titles']['hours']['en']);
    assert_eq([['name' => 'Gas', 'count' => 1, 'icon' => ''], ['name' => 'Chromatic Box', 'count' => 2, 'icon' => '']], $pub['prizes']);
    assert_eq(['hunt' => null], handle_hunt(function () { throw new PDOException('down'); }, ht_at(1)));
});

test('после итогов публичное показывает победителей без просроченных', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Gas', 'count' => 2]])]);
    ht_user($p, '1', 'ann');
    ht_user($p, '2', 'bob');
    ht_tickets($p, $h, ['1', '2']);
    hunt_draw($p, [], $h, ht_start() + 8 * HT_DAY, ht_keep_order());
    $p->exec("UPDATE hunt_wins SET status = 'expired' WHERE user_id = 2");
    $pub = hunt_public($p, ht_start() + 9 * HT_DAY);
    assert_eq('drawn', $pub['hunt']['phase']);
    assert_eq([['1', 'ann', 'Gas']], array_map(function ($w) { return [$w['user']['id'], $w['user']['username'], $w['prize']['name']]; }, $pub['winners']));
});

test('моё: кошелёк, календарь, сегодняшнее время, билеты, титулы, Telegram', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann', 555);
    $hunt = hunt_load($p, $h);
    hunt_login_award($p, $hunt, $u, ht_at(1));
    ht_insert($p, 'hunt_time', ['hunt_id' => $h, 'user_id' => $u, 'day' => 2, 'secs' => 1800, 'beat_at' => ht_at(2)]);
    $me = hunt_me($p, [], $u, ht_at(2, 30));
    assert_eq([$h, 2, ['secs' => 1800, 'full' => false], true], [$me['hunt_id'], $me['day'], $me['today'], $me['tg']]);
    assert_eq(['balance' => 10, 'earned' => 10, 'tickets' => 0], $me['wallet']);
    assert_eq(['got', 'today', 'future'], array_map(function ($d) { return $d['login']['state']; }, array_slice($me['days'], 0, 3)),
        'день 2 без входа — сегодня');
    assert_eq(1800, $me['days'][1]['secs']);
    assert_eq([['need' => 3600, 'reward' => 15, 'got' => false], ['need' => 7200, 'reward' => 20, 'got' => false]], $me['days'][1]['hours']);
    assert_eq(ht_start() + 2 * HT_DAY, $me['next_day_at']);
    assert_eq(['missed', 'missed'], array_map(function ($d) { return $d['login']['state']; },
        array_slice(hunt_me($p, [], $u, ht_start() + 7 * HT_DAY + 5)['days'], 5, 2)), 'после семи дней неполученное — пропущено');
});

test('session.php: поле hunt, награда за вход, гость и забаненный — null', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $db = function () use ($p) { return $p; };
    $s = handle_session($db, ['user_id' => $u], [], ht_at(1))['hunt'];
    assert_eq(['open', 1, 10, 10, 0, 420, 210, 2, 0, false], [$s['phase'], $s['day'], $s['earned'], $s['balance'], $s['tickets'],
        $s['cap'], $s['price'], $s['limit'], $s['secs'], $s['full']]);
    assert_eq([['kind' => 'login', 'n' => 1, 'delta' => 10]], $s['gains']);
    assert_eq([], handle_session($db, ['user_id' => $u], [], ht_at(1, 60))['hunt']['gains'], 'второй запрос — без награды');
    ht_give($p, $h, $u, 300);
    assert_eq('ticket', handle_session($db, ['user_id' => $u], [], ht_at(1, 90))['hunt']['next']);
    assert_eq(null, handle_session($db, [], [], ht_at(1))['hunt'], 'гостю null');
    assert_eq(null, handle_session($db, ['user_id' => $u], ['banned_ids' => ['1']], ht_at(1))['hunt'], 'забаненному null');
    assert_eq(null, hunt_session_field(test_db(), [], $u, ht_at(1)), 'таблиц нет — null, а не ошибка');
    $p->exec("UPDATE hunts SET status = 'draft'");
    assert_eq(null, hunt_session_field($p, [], $u, ht_at(1)), 'черновик — null');
});

test('hunt_act раздаёт действия, hunt_me гостю — login', function () {
    $p = ht_db();
    ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    assert_eq('done', handle_hunt_act($p, [], $u, ['act' => 'beat'], ht_at(1))[1]['status']);
    assert_eq('balance', handle_hunt_act($p, [], $u, ['act' => 'ticket', 'seq' => 1], ht_at(1))[1]['status']);
    assert_eq('stale', handle_hunt_act($p, [], $u, ['act' => 'ticket', 'seq' => '1'], ht_at(1))[1]['status'], 'seq строкой — не номер');
    assert_eq('bad', handle_hunt_act($p, [], $u, ['act' => 'confirm_win', 'id' => 5], ht_at(1))[1]['status']);
    assert_eq([400, 'bad'], [handle_hunt_act($p, [], $u, ['act' => 'nope'], ht_at(1))[0], handle_hunt_act($p, [], $u, [], ht_at(1))[1]['status']]);
    assert_eq([401, 'login'], [handle_hunt_me($p, [], '', ht_at(1))[0], handle_hunt_me($p, [], '', ht_at(1))[1]['status']]);
    assert_eq(true, handle_hunt_me($p, [], $u, ht_at(1))[1]['ok']);
    assert_eq([403, 'banned'], [handle_hunt_me($p, ['banned_ids' => ['1']], $u, ht_at(1))[0],
        handle_hunt_me($p, ['banned_ids' => ['1']], $u, ht_at(1))[1]['status']]);
});

// --------------------------------------------------------------------------
//  Админка
// --------------------------------------------------------------------------

function ht_form(array $extra = []): array {
    return array_merge([
        'title_ru' => 'Spooky Tokens', 'title_en' => 'Spooky Tokens', 'rules_ru' => 'Правила', 'rules_en' => 'Rules',
        'start_date' => '2026-10-31', 'sales_end_at' => ht_start() + 8 * HT_DAY, 'draw_at' => ht_start() + 8 * HT_DAY + 3600,
        'login' => HUNT_LOGIN_DEFAULT, 'hours' => HUNT_HOURS_DEFAULT,
        'prizes' => [['name' => 'Gas', 'count' => 3, 'icon' => '']], 'claim_days' => 7,
        'titles' => HUNT_TITLES_DEFAULT, 'status' => 'live',
    ], $extra);
}

test('админка: пустая форма, сохранение, проверка полей', function () {
    $p = ht_db();
    $st = hunt_admin_state($p, ht_start() - HT_DAY);
    assert_eq([true, null], [$st['ok'], $st['hunt']]);
    assert_eq(HUNT_LOGIN_DEFAULT, $st['defaults']['login']);
    foreach ([
        ['start_date' => '2026-13-01'], ['login' => [1, 2, 3]], ['hours' => [1, -1]],
        ['sales_end_at' => ht_start() + 6 * HT_DAY], ['draw_at' => ht_start()], ['prizes' => []],
        ['prizes' => [['name' => '', 'count' => 1]]], ['claim_days' => 0], ['status' => 'drawn'],
        ['titles' => ['login' => ['ru' => '', 'en' => 'x'], 'hours' => HUNT_TITLES_DEFAULT['hours']]],
    ] as $bad) {
        assert_eq(400, hunt_admin_save($p, ht_form($bad), ht_start() - HT_DAY)[0], 'отказ: ' . json_encode($bad));
    }
    [$c, $b] = hunt_admin_save($p, ht_form(), ht_start() - HT_DAY);
    assert_eq([200, 'soon', ht_start(), 420, 210], [$c, $b['hunt']['phase'], $b['hunt']['starts_at'], $b['hunt']['cap'], $b['hunt']['price']]);
    [$c, $b] = hunt_admin_save($p, ht_form(['login' => [5, 5, 5, 5, 5, 5, 5], 'start_date' => '2026-11-01']), ht_start() - HT_DAY);
    assert_eq([200, 35 + 245, ht_start() + HT_DAY], [$c, $b['hunt']['cap'], $b['hunt']['starts_at']], 'до старта числа и дата правятся');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM hunts')->fetchColumn(), 'сезон один');
});

test('админка: после старта дата и награды заперты, разыгранный не правится', function () {
    $p = ht_db();
    hunt_admin_save($p, ht_form(), ht_start() - HT_DAY);
    $now = ht_at(2);
    assert_eq('locked', hunt_admin_save($p, ht_form(['hours' => [10, 10]]), $now)[1]['status']);
    assert_eq('locked', hunt_admin_save($p, ht_form(['start_date' => '2026-11-01']), $now)[1]['status']);
    assert_eq('locked', hunt_admin_save($p, ht_form(['status' => 'draft']), $now)[1]['status'], 'идущий не снять в черновик');
    [$c, $b] = hunt_admin_save($p, ht_form(['rules_ru' => 'Новые правила', 'prizes' => [['name' => 'Gas', 'count' => 5]]]), $now);
    assert_eq([200, 'Новые правила', 5], [$c, $b['hunt']['rules_ru'], $b['hunt']['prizes'][0]['count']], 'тексты и призы правятся');
    $p->exec("UPDATE hunts SET status = 'drawn'");
    assert_eq('drawn', hunt_admin_save($p, ht_form(), $now)[1]['status']);
});

test('админка: сводка, участники с билетами, аннулирование, действия', function () {
    $p = ht_db();
    hunt_admin_save($p, ht_form(), ht_start() - HT_DAY);
    $h = (int)hunt_admin_current($p)['id'];
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 420);
    hunt_buy_ticket($p, [], $u, 1, ht_at(1));
    hunt_login_award($p, hunt_load($p, $h), $u, ht_at(1));
    $st = hunt_admin_state($p, ht_at(1));
    assert_eq(['hunters' => 1, 'earned' => 430, 'tickets' => 1, 'titles' => ['login' => 0, 'hours' => 0], 'day' => 1, 'logins_today' => 1], $st['stats']);
    assert_eq(['1', 'ann', 220, 1], [$st['people'][0]['id'], $st['people'][0]['username'], $st['people'][0]['balance'], count($st['people'][0]['tickets'])]);
    $tid = $st['people'][0]['tickets'][0]['id'];
    assert_eq(200, handle_hunt_admin($p, [], ['action' => 'void', 'id' => $tid, 'reason' => 'мультиаккаунт'], ht_at(1))[0]);
    assert_eq(404, handle_hunt_admin($p, [], ['action' => 'void', 'id' => $tid], ht_at(1))[0], 'дважды не аннулировать');
    assert_eq(0, hunt_admin_state($p, ht_at(1))['stats']['tickets']);
    assert_eq('not_ended', handle_hunt_admin($p, [], ['action' => 'draw'], ht_at(1))[1]['status']);
    assert_eq(200, handle_hunt_admin($p, [], ['action' => 'draw'], ht_start() + 8 * HT_DAY)[0]);
    assert_eq([400, 'bad'], [handle_hunt_admin($p, [], ['action' => 'zzz'], ht_at(1))[0], handle_hunt_admin($p, [], ['action' => 'zzz'], ht_at(1))[1]['status']]);
    assert_eq(503, handle_hunt_admin(test_db(), [], ['action' => 'get'], ht_at(1))[0], 'таблиц нет');
});

run_tests();
