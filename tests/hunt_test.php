<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/hunt_schema.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/hunt.php';
require __DIR__ . '/../public_html/api/hunt.php';
require __DIR__ . '/../public_html/api/hunt_me.php';
require __DIR__ . '/../public_html/api/hunt_act.php';
require __DIR__ . '/../public_html/api/hunt_stat.php';
require __DIR__ . '/../public_html/api/session.php';

// Ивент «Тыквенная охота» — api/lib/hunt.php и его эндпоинты.
//
// В сеть тесты не ходят: Telegram подменён $send, который отвечает на
// getChatMember по таблице «кто где подписан».
//
// Главные обещания:
//  - одна и та же награда не начисляется дважды — ни из второй вкладки, ни
//    повтором запроса; у заданий одной группы — одна награда на всех;
//  - трата уменьшает баланс, но не «всего заработано»;
//  - правильный ответ не уходит в браузер до верного ответа, ошибка ничего
//    не стоит;
//  - билетов не больше лимита, повтор покупки не создаёт второй билет;
//  - в итогах один человек — один приз; аннулированные билеты и забаненные
//    не выигрывают; неявившегося заменяет следующий по очереди;
//  - ежедневное обновляется в полночь по Москве;
//  - закрытая глава не отдаётся и не засчитывается.

const HT_NOW  = 1792000000;  // 2026-10-14 08:26:40 UTC, 11:26 по Москве
const HT_DAY  = 86400;
const HT_CH   = -1003333333333;
const HT_ROOT = __DIR__ . '/..';

function ht_db(): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE tg_links (
        user_id INTEGER NOT NULL PRIMARY KEY, chat_id INTEGER NOT NULL,
        tg_name TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE tg_channels (
        chat_id INTEGER NOT NULL PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0,
        missing TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL)");
    $pdo->prepare('INSERT INTO tg_channels (chat_id, title, username, kind, is_admin, updated_at) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([HT_CH, 'Maknemy News', 'maknemy_news', 'channel', HT_NOW]);
    hunt_sqlite_tables($pdo);
    return $pdo;
}

function ht_user(PDO $p, string $id, string $name, ?int $chat = null): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                 VALUES (?, ?, ?, ?, ?, ?)')->execute([$id, $name, ucfirst($name), '', HT_NOW - HT_DAY, HT_NOW]);
    if ($chat !== null) {
        $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, linked_at) VALUES (?, ?, ?, ?)')
          ->execute([$id, $chat, '@' . $name, HT_NOW - 100]);
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
    return ht_insert($p, 'hunts', array_merge([
        'slug' => 'halloween-2026', 'status' => 'live', 'title_ru' => 'Тыквенная охота', 'title_en' => 'Pumpkin Hunt',
        'starts_at' => HT_NOW - 3600, 'sales_end_at' => HT_NOW + 7 * HT_DAY, 'draw_at' => HT_NOW + 8 * HT_DAY,
        'ticket_price' => 50, 'ticket_limit' => 3, 'claim_days' => 7,
        'prizes_json' => json_encode([['name' => 'Kitsune', 'count' => 1], ['name' => 'Dragon', 'count' => 2]]),
        'milestones_json' => json_encode([['at' => 150, 'title_ru' => 'Охотник', 'title_en' => 'Hunter'],
                                          ['at' => 50, 'title_ru' => 'Новичок', 'title_en' => 'Rookie']]),
        'created_at' => HT_NOW - HT_DAY, 'updated_at' => HT_NOW - HT_DAY,
    ], $extra));
}

function ht_quiz_cfg(): string {
    return json_encode([
        'q_ru' => 'Сколько стоит билет?', 'q_en' => 'How much is a ticket?',
        'options' => [['ru' => '10', 'en' => '10'], ['ru' => '50', 'en' => '50'], ['ru' => '300', 'en' => '300']],
        'correct' => 1,
        'hint_ru' => 'Посмотри на кнопку обмена', 'hint_en' => 'Look at the exchange button',
        'explain_ru' => 'Билет — 50 тыковок', 'explain_en' => 'A ticket is 50 tokens',
    ]);
}

function ht_task(PDO $p, int $hunt, array $extra = []): int {
    return ht_insert($p, 'hunt_tasks', array_merge([
        'hunt_id' => $hunt, 'chapter' => 1, 'kind' => 'link', 'level' => 'easy', 'period' => 'once',
        'reward' => 10, 'title_ru' => 'Задание', 'title_en' => 'Task',
        'cfg_json' => json_encode(['url' => 'https://t.me/maknemy']),
        'created_at' => HT_NOW, 'updated_at' => HT_NOW,
    ], $extra));
}

function ht_secret(PDO $p, int $hunt, array $extra = []): int {
    return ht_insert($p, 'hunt_secrets', array_merge([
        'hunt_id' => $hunt, 'chapter' => 1, 'page' => 'calc', 'spot' => 'head', 'sprite' => 'pumpkin',
        'reward' => 15, 'hint_ru' => 'Там, где считают', 'hint_en' => 'Where things are counted',
        'found_ru' => 'Нашёл! Следующая — в новостях', 'found_en' => 'Found! Next one is in the news',
        'created_at' => HT_NOW, 'updated_at' => HT_NOW,
    ], $extra));
}

// Telegram: getChatMember по таблице [tg user id => статус]; null — сеть упала.
function ht_send(?array $members): callable {
    return function (string $method, array $params) use ($members) {
        if ($members === null) { return null; }
        $status = $members[(int)$params['user_id']] ?? 'left';
        return ['ok' => true, 'result' => ['status' => $status, 'user' => ['id' => (int)$params['user_id']]]];
    };
}

function ht_no_send(): callable {
    return function () { throw new RuntimeException('Telegram must not be called'); };
}

function ht_wallet(PDO $p, int $hunt, string $uid): array {
    return hunt_wallet_out(hunt_wallet($p, $hunt, $uid));
}

function ht_give(PDO $p, int $hunt, string $uid, int $n, string $ref = 'admin:seed'): void {
    hunt_award($p, $hunt, $uid, 'admin', $ref, $n, HT_NOW);
}

// --------------------------------------------------------------------------
//  Таблицы
// --------------------------------------------------------------------------

test('миграция и schema.sql описывают таблицы ивента одинаково', function () {
    $norm = function (string $s): string { return str_replace("\r\n", "\n", $s); };
    $mig = $norm((string)file_get_contents(HT_ROOT . '/docs/migrations/2026-10-20-hunt.sql'));
    $schema = $norm((string)file_get_contents(HT_ROOT . '/schema.sql'));
    preg_match_all('~CREATE TABLE IF NOT EXISTS hunt\w* \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;~s', $mig, $m);
    assert_eq(9, count($m[0]), 'девять таблиц в миграции');
    foreach ($m[0] as $ddl) {
        preg_match('~EXISTS (\w+)~', $ddl, $name);
        assert_true(strpos($schema, $ddl) !== false, "schema.sql: $name[1] как в миграции");
        assert_eq(0, preg_match('~\bTEXT\b[^,\n]*DEFAULT~', $ddl), "$name[1]: у TEXT в MySQL не бывает DEFAULT");
    }
});

test('ключи страниц годятся для ?page= и не повторяются у мест', function () {
    foreach (HUNT_SPOTS as $page => $spots) {
        assert_eq(1, preg_match('/^[a-z]{1,16}$/', $page), "страница $page");
        foreach ($spots as $spot => $label) {
            assert_eq(1, preg_match('/^[a-z]{1,16}$/', $spot), "$page/$spot");
            assert_true($label !== '', "$page/$spot: подпись для админки");
        }
    }
});

// --------------------------------------------------------------------------
//  Сезон, день, кошелёк
// --------------------------------------------------------------------------

test('день — дата по Москве, а не по часам сервера', function () {
    $t = (new DateTimeImmutable('2026-10-25 21:30:00', new DateTimeZone('UTC')))->getTimestamp();
    assert_eq('2026-10-26', hunt_day($t), '21:30 UTC — уже 26-е в Москве');
    assert_eq('2026-10-25', hunt_day($t - 3600), '20:30 UTC — ещё 25-е');
    assert_eq((new DateTimeImmutable('2026-10-26 00:00:00', new DateTimeZone('Europe/Moscow')))->getTimestamp(),
        hunt_next_day_at($t - 3600), 'следующий день начинается в полночь по Москве');
});

test('фазы сезона', function () {
    $h = ['status' => 'live', 'starts_at' => 100, 'sales_end_at' => 200];
    assert_eq('draft', hunt_phase(['status' => 'draft'] + $h, 150));
    assert_eq('soon', hunt_phase($h, 99));
    assert_eq('open', hunt_phase($h, 100));
    assert_eq('closed', hunt_phase($h, 200));
    assert_eq('drawn', hunt_phase(['status' => 'drawn'] + $h, 150));
});

test('одна награда по одному ключу — один раз', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    assert_eq('done', hunt_award($p, $h, $u, 'task', 'task:1:once', 25, HT_NOW)['status']);
    $again = hunt_award($p, $h, $u, 'task', 'task:1:once', 25, HT_NOW + 5);
    assert_eq('already', $again['status'], 'вторая вкладка');
    assert_eq(['balance' => 25, 'earned' => 25, 'tickets' => 0], $again['wallet'], 'не удвоилось');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM hunt_ledger')->fetchColumn(), 'одна строка журнала');
});

test('дни считаются по Москве, источник пишется только первый', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    hunt_award($p, $h, $u, 'task', 'a', 5, HT_NOW, 'partner');
    hunt_award($p, $h, $u, 'task', 'b', 5, HT_NOW + 60, 'other');
    hunt_award($p, $h, $u, 'task', 'c', 5, HT_NOW + HT_DAY);
    $w = hunt_wallet($p, $h, $u);
    assert_eq(2, $w['days'], 'два разных дня');
    assert_eq('partner', $w['src'], 'первый источник');
    assert_eq(HT_NOW, $w['first_at']);
});

// --------------------------------------------------------------------------
//  Задания
// --------------------------------------------------------------------------

test('вопрос уходит на страницу без верного ответа и пояснения', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $id = ht_task($p, $h, ['kind' => 'quiz', 'cfg_json' => ht_quiz_cfg()]);
    $pub = hunt_task_public(hunt_task_load($p, $id), []);
    $json = json_encode($pub, JSON_UNESCAPED_UNICODE);
    assert_eq(false, strpos($json, 'correct'), 'нет correct');
    assert_eq(false, strpos($json, 'Билет — 50 тыковок'), 'нет пояснения');
    assert_eq(false, strpos($json, 'Посмотри на кнопку'), 'нет подсказки');
    assert_eq(['ru' => 'Сколько стоит билет?', 'en' => 'How much is a ticket?'], $pub['q']);
    assert_eq(3, count($pub['options']));
});

test('неверный ответ — подсказка и ничего не стоит, верный — награда один раз', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $id = ht_task($p, $h, ['kind' => 'quiz', 'reward' => 25, 'cfg_json' => ht_quiz_cfg()]);
    [$c, $b] = hunt_do_task($p, [], ht_no_send(), $u, $id, ['choice' => 0], HT_NOW);
    assert_eq([200, 'wrong'], [$c, $b['status']]);
    assert_eq('Посмотри на кнопку обмена', $b['hint']['ru']);
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM hunt_ledger')->fetchColumn(), 'журнал пуст');
    [, $b] = hunt_do_task($p, [], ht_no_send(), $u, $id, ['choice' => '1'], HT_NOW);
    assert_eq(['done', 25], [$b['status'], $b['wallet']['balance']]);
    assert_eq('Билет — 50 тыковок', $b['explain']['ru'], 'пояснение после верного');
    [, $b] = hunt_do_task($p, [], ht_no_send(), $u, $id, ['choice' => 0], HT_NOW + 1);
    assert_eq(['already', 25], [$b['status'], $b['wallet']['balance']], 'повтор — уже засчитано');
    [$c, $b] = hunt_do_task($p, [], ht_no_send(), $u, ht_task($p, $h, ['kind' => 'quiz', 'cfg_json' => ht_quiz_cfg()]), [], HT_NOW);
    assert_eq([400, 'bad'], [$c, $b['status']], 'без выбора');
});

test('ежедневное — снова на следующий день по Москве, разовое — нет', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $daily = ht_task($p, $h, ['period' => 'daily']);
    $once = ht_task($p, $h);
    $midnight = (new DateTimeImmutable('2026-10-15 00:00:00', new DateTimeZone('Europe/Moscow')))->getTimestamp();
    foreach ([$daily, $once] as $id) { hunt_do_task($p, [], ht_no_send(), $u, $id, [], HT_NOW); }
    assert_eq('already', hunt_do_task($p, [], ht_no_send(), $u, $daily, [], $midnight - 1)[1]['status'], '23:59 того же дня');
    assert_eq('done', hunt_do_task($p, [], ht_no_send(), $u, $daily, [], $midnight)[1]['status'], 'полночь по Москве');
    assert_eq('already', hunt_do_task($p, [], ht_no_send(), $u, $once, [], $midnight)[1]['status'], 'разовое');
});

test('задания одной группы вместе дают одну награду', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $ru = ht_task($p, $h, ['grp' => 'news', 'lang' => 'ru', 'reward' => 20]);
    $en = ht_task($p, $h, ['grp' => 'news', 'lang' => 'en', 'reward' => 20]);
    assert_eq('done', hunt_do_task($p, [], ht_no_send(), $u, $ru, [], HT_NOW)[1]['status']);
    [, $b] = hunt_do_task($p, [], ht_no_send(), $u, $en, [], HT_NOW);
    assert_eq(['already', 20], [$b['status'], $b['wallet']['balance']], 'смена языка не даёт второй награды');
    assert_eq([$ru, $en], hunt_me($p, [], $u, HT_NOW)['done'], 'оба выглядят выполненными');
});

test('гость, забаненный, закрытая глава, конец приёма', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $id = ht_task($p, $h);
    assert_eq([401, 'login'], [hunt_do_task($p, [], ht_no_send(), '', $id, [], HT_NOW)[0], 'login']);
    $b = hunt_do_task($p, ['banned_ids' => ['1']], ht_no_send(), $u, $id, [], HT_NOW);
    assert_eq([403, 'banned'], [$b[0], $b[1]['status']]);
    ht_insert($p, 'hunt_chapters', ['hunt_id' => $h, 'n' => 2, 'title_ru' => 'Глава 2', 'opens_at' => HT_NOW + HT_DAY]);
    $later = ht_task($p, $h, ['chapter' => 2]);
    assert_eq('closed', hunt_do_task($p, [], ht_no_send(), $u, $later, [], HT_NOW)[1]['status'], 'глава 2 ещё закрыта');
    assert_eq('done', hunt_do_task($p, [], ht_no_send(), $u, $later, [], HT_NOW + HT_DAY)[1]['status'], 'открылась');
    assert_eq('closed', hunt_do_task($p, [], ht_no_send(), $u, $id, [], HT_NOW + 7 * HT_DAY)[1]['status'], 'приём закрыт');
    $p->exec('UPDATE hunt_tasks SET active = 0');
    assert_eq('closed', hunt_do_task($p, [], ht_no_send(), $u, $id, [], HT_NOW)[1]['status'], 'выключено');
});

test('подписка в Telegram проверяется ботом, старые подписчики засчитываются', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $noTg = ht_user($p, '1', 'ann');
    $u = ht_user($p, '2', 'bob', 555);
    $id = ht_task($p, $h, ['kind' => 'tg', 'reward' => 30, 'cfg_json' => json_encode(['channel' => HT_CH])]);
    assert_eq('tg', hunt_do_task($p, [], ht_no_send(), $noTg, $id, [], HT_NOW)[1]['status'], 'нет Telegram');
    assert_eq('not_sub', hunt_do_task($p, [], ht_send([]), $u, $id, [], HT_NOW)[1]['status'], 'не подписан');
    [$c, $b] = hunt_do_task($p, [], ht_send(null), $u, $id, [], HT_NOW);
    assert_eq([503, 'pending'], [$c, $b['status']], 'Telegram не ответил — ждём, а не «нет»');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM hunt_ledger')->fetchColumn());
    assert_eq('done', hunt_do_task($p, [], ht_send([555 => 'member']), $u, $id, [], HT_NOW)[1]['status'], 'подписан давно — засчитано');
    assert_eq('already', hunt_do_task($p, [], ht_no_send(), $u, $id, [], HT_NOW)[1]['status'], 'повтор не ходит в Telegram');
    $pub = hunt_task_public(hunt_task_load($p, $id), hunt_channel_map($p));
    assert_eq(['title' => 'Maknemy News', 'url' => 'https://t.me/maknemy_news'], $pub['channel']);
});

test('посещение раздела засчитывается только с этого раздела', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $id = ht_task($p, $h, ['kind' => 'visit', 'cfg_json' => json_encode(['page' => 'stock'])]);
    assert_eq('bad', hunt_do_task($p, [], ht_no_send(), $u, $id, ['page' => 'calc'], HT_NOW)[1]['status']);
    assert_eq('done', hunt_do_task($p, [], ht_no_send(), $u, $id, ['page' => 'stock'], HT_NOW)[1]['status']);
});

// --------------------------------------------------------------------------
//  Секретки
// --------------------------------------------------------------------------

test('секретка: своя страница, у каждого своя находка, ежедневная — снова завтра', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $a = ht_user($p, '1', 'ann');
    $b = ht_user($p, '2', 'bob');
    $s = ht_secret($p, $h);
    $d = ht_secret($p, $h, ['page' => 'news', 'period' => 'daily']);
    assert_eq('bad', hunt_find_secret($p, [], $a, $s, 'news', HT_NOW)[1]['status'], 'чужая страница');
    [, $r] = hunt_find_secret($p, [], $a, $s, 'calc', HT_NOW);
    assert_eq(['done', 15, 'Нашёл! Следующая — в новостях'], [$r['status'], $r['wallet']['balance'], $r['found']['ru']]);
    assert_eq('already', hunt_find_secret($p, [], $a, $s, 'calc', HT_NOW)[1]['status']);
    assert_eq('done', hunt_find_secret($p, [], $b, $s, 'calc', HT_NOW)[1]['status'], 'у второго своя находка');
    hunt_find_secret($p, [], $a, $d, 'news', HT_NOW);
    assert_eq('done', hunt_find_secret($p, [], $a, $d, 'news', HT_NOW + HT_DAY)[1]['status'], 'ежедневная');
    ht_insert($p, 'hunt_chapters', ['hunt_id' => $h, 'n' => 2, 'opens_at' => HT_NOW + 2 * HT_DAY]);
    $later = ht_secret($p, $h, ['chapter' => 2]);
    assert_eq('closed', hunt_find_secret($p, [], $a, $later, 'calc', HT_NOW)[1]['status'], 'глава закрыта');
});

// --------------------------------------------------------------------------
//  Билеты
// --------------------------------------------------------------------------

test('билет: списание без потери «всего заработано», номер, даты', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 60);
    [$c, $b] = hunt_buy_ticket($p, [], $u, 1, HT_NOW);
    assert_eq([200, 'done'], [$c, $b['status']]);
    assert_eq(['balance' => 10, 'earned' => 60, 'tickets' => 1], $b['wallet'], 'шкала не откатилась');
    assert_eq('#0001', $b['ticket']['no']);
    assert_eq(1, count($b['tickets']));
    assert_eq([HT_NOW + 7 * HT_DAY, HT_NOW + 8 * HT_DAY, 3], [$b['sales_end_at'], $b['draw_at'], $b['limit']]);
    $ledger = $p->query("SELECT delta, kind FROM hunt_ledger WHERE ref = 'ticket:1'")->fetch(PDO::FETCH_ASSOC);
    assert_eq(['delta' => -50, 'kind' => 'ticket'], ['delta' => (int)$ledger['delta'], 'kind' => $ledger['kind']]);
});

test('повтор покупки не создаёт второй билет, отставшая вкладка — stale', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 200);
    hunt_buy_ticket($p, [], $u, 1, HT_NOW);
    [, $b] = hunt_buy_ticket($p, [], $u, 1, HT_NOW + 1);
    assert_eq(['already', 150, '#0001'], [$b['status'], $b['wallet']['balance'], $b['ticket']['no']], 'перезагрузка');
    assert_eq('stale', hunt_buy_ticket($p, [], $u, 3, HT_NOW)[1]['status'], 'перескок через номер');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM hunt_tickets')->fetchColumn());
});

test('не хватает тыковок, лимит, конец приёма', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 40);
    [$c, $b] = hunt_buy_ticket($p, [], $u, 1, HT_NOW);
    assert_eq([409, 'balance', 10], [$c, $b['status'], $b['need']]);
    ht_give($p, $h, $u, 500, 'admin:more');
    foreach ([1, 2, 3] as $seq) { assert_eq('done', hunt_buy_ticket($p, [], $u, $seq, HT_NOW)[1]['status'], "билет $seq"); }
    assert_eq('limit', hunt_buy_ticket($p, [], $u, 4, HT_NOW)[1]['status'], 'четвёртый при лимите 3');
    $v = ht_user($p, '2', 'bob');
    ht_give($p, $h, $v, 100);
    assert_eq('closed', hunt_buy_ticket($p, [], $v, 1, HT_NOW + 7 * HT_DAY)[1]['status'], 'приём закрыт');
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
        ht_insert($p, 'hunt_tickets', ['hunt_id' => $h, 'user_id' => $u, 'seq' => $seq[$u], 'created_at' => HT_NOW]);
    }
}

function ht_winners(PDO $p, int $h): array {
    $st = $p->prepare('SELECT user_id, prize_name, status FROM hunt_wins WHERE hunt_id = ? ORDER BY id');
    $st->execute([$h]);
    return array_map(function ($r) { return [(string)$r['user_id'], $r['prize_name'], $r['status']]; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

test('итоги только после конца приёма и только раз', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    assert_eq('not_ended', hunt_draw($p, [], $h, HT_NOW, ht_keep_order())[1]['status']);
    assert_eq(200, hunt_draw($p, [], $h, HT_NOW + 7 * HT_DAY, ht_keep_order())[0]);
    assert_eq('drawn', hunt_draw($p, [], $h, HT_NOW + 7 * HT_DAY, ht_keep_order())[1]['status']);
});

test('один человек — один приз; аннулированные и забаненные не выигрывают', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    foreach (['1' => 'ann', '2' => 'bob', '3' => 'cat', '4' => 'dan', '5' => 'eve'] as $id => $n) { ht_user($p, (string)$id, $n); }
    ht_tickets($p, $h, ['1', '1', '1', '2', '3', '4', '5']);
    $p->exec("UPDATE hunt_tickets SET void = 1 WHERE user_id = 2");
    $end = HT_NOW + 7 * HT_DAY;
    [$c, $b] = hunt_draw($p, ['banned_ids' => ['3']], $h, $end, ht_keep_order());
    assert_eq([200, 3, 5], [$c, $b['winners'], $b['tickets']]);
    assert_eq([['1', 'Kitsune', 'won'], ['4', 'Dragon', 'won'], ['5', 'Dragon', 'won']], ht_winners($p, $h));
    $deadline = (int)$p->query('SELECT deadline FROM hunt_wins LIMIT 1')->fetchColumn();
    assert_eq($end + 7 * HT_DAY, $deadline, 'срок обращения — claim_days');
    assert_eq('drawn', hunt_load($p, $h)['status']);
});

test('настоящее перемешивание раздаёт все призы разным людям', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $owners = [];
    for ($i = 1; $i <= 20; $i++) { ht_user($p, (string)$i, "u$i"); $owners[] = (string)$i; $owners[] = (string)$i; }
    ht_tickets($p, $h, $owners);
    hunt_draw($p, [], $h, HT_NOW + 7 * HT_DAY);
    $w = ht_winners($p, $h);
    assert_eq(3, count($w));
    assert_eq(3, count(array_unique(array_column($w, 0))), 'три разных человека');
});

test('просрочка и «Перевыбрать» по очереди итогов', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Kitsune', 'count' => 1]])]);
    foreach (['1' => 'ann', '2' => 'bob', '3' => 'cat'] as $id => $n) { ht_user($p, (string)$id, $n); }
    ht_tickets($p, $h, ['1', '1', '2', '3']);
    $end = HT_NOW + 7 * HT_DAY;
    hunt_draw($p, ['banned_ids' => []], $h, $end, ht_keep_order());
    $win = (int)$p->query('SELECT id FROM hunt_wins')->fetchColumn();
    assert_eq('not_expired', hunt_redraw($p, [], $win, $end + HT_DAY)[1]['status'], 'срок не вышел');
    assert_eq(1, hunt_expire_wins($p, $h, $end + 8 * HT_DAY));
    [$c, $b] = hunt_redraw($p, ['banned_ids' => ['2']], $win, $end + 8 * HT_DAY);
    assert_eq([200, '3', 'Kitsune'], [$c, $b['user_id'], $b['win']['prize']['name']], 'второй билет ann и забаненный bob пропущены');
    assert_eq([['1', 'Kitsune', 'redrawn'], ['3', 'Kitsune', 'won']], ht_winners($p, $h));
    assert_eq('not_expired', hunt_redraw($p, [], $win, $end + 8 * HT_DAY)[1]['status'], 'дважды не отдать');
    $p->exec("UPDATE hunt_wins SET status = 'expired' WHERE user_id = 3");
    $second = (int)$p->query('SELECT id FROM hunt_wins WHERE user_id = 3')->fetchColumn();
    assert_eq('none_left', hunt_redraw($p, [], $second, $end + 9 * HT_DAY)[1]['status'], 'очередь кончилась');
});

test('победитель подтверждает выигрыш с Telegram, выдачу ведёт админка', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Kitsune', 'count' => 2]])]);
    $a = ht_user($p, '1', 'ann');
    $b = ht_user($p, '2', 'bob', 777);
    ht_tickets($p, $h, ['1', '2']);
    $end = HT_NOW + 7 * HT_DAY;
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
    assert_eq('bad_move', hunt_win_set($p, $wb, 'queued', '', $end)[1]['status'], 'назад нельзя');
    assert_eq('expired', hunt_confirm_win($p, $a, $wa, $end + 8 * HT_DAY)[1]['status'], 'опоздал');
});

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

test('без сезона и с черновиком — пусто', function () {
    $p = ht_db();
    assert_eq(['hunt' => null], hunt_public($p, HT_NOW));
    ht_hunt($p, ['status' => 'draft']);
    assert_eq(['hunt' => null], hunt_public($p, HT_NOW), 'черновик снаружи не виден');
    assert_eq(['hunt' => null], hunt_public(test_db(), HT_NOW), 'таблиц нет — сайт не ломается');
});

test('публичное: закрытая глава без заданий, секретки без страниц, титулы по порядку', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    ht_insert($p, 'hunt_chapters', ['hunt_id' => $h, 'n' => 1, 'title_ru' => 'Знакомство', 'story_ru' => 'Тыквы проснулись', 'opens_at' => HT_NOW - 3600]);
    ht_insert($p, 'hunt_chapters', ['hunt_id' => $h, 'n' => 2, 'title_ru' => 'Исследование', 'story_ru' => 'Секрет', 'opens_at' => HT_NOW + HT_DAY]);
    $t1 = ht_task($p, $h);
    ht_task($p, $h, ['chapter' => 2]);
    $s1 = ht_secret($p, $h);
    ht_secret($p, $h, ['chapter' => 2]);
    ht_secret($p, $h, ['page' => 'news']);
    $out = hunt_public($p, HT_NOW, 'calc');
    assert_eq('open', $out['hunt']['phase']);
    assert_eq([$t1], array_column($out['tasks'], 'id'), 'задания только открытых глав');
    assert_eq([true, false], array_column($out['chapters'], 'open'));
    assert_eq(false, isset($out['chapters'][1]['story']), 'сюжет закрытой главы не виден');
    assert_eq(2, count($out['journal']));
    assert_eq(false, array_key_exists('page', $out['journal'][0]), 'в журнале нет страницы');
    assert_eq([['id' => $s1, 'spot' => 'head', 'sprite' => 'pumpkin']], $out['secrets'], 'секретки страницы');
    assert_eq([50, 150], array_column($out['hunt']['milestones'], 'at'));
    assert_eq([], hunt_public($p, HT_NOW + 7 * HT_DAY, 'calc')['secrets'], 'после конца приёма секреток нет');
});

test('после итогов страница показывает победителей без просроченных', function () {
    $p = ht_db();
    $h = ht_hunt($p, ['prizes_json' => json_encode([['name' => 'Kitsune', 'count' => 2]])]);
    ht_user($p, '1', 'ann');
    ht_user($p, '2', 'bob');
    ht_tickets($p, $h, ['1', '2']);
    hunt_draw($p, [], $h, HT_NOW + 7 * HT_DAY, ht_keep_order());
    $p->exec("UPDATE hunt_wins SET status = 'expired' WHERE user_id = 2");
    $out = hunt_public($p, HT_NOW + 7 * HT_DAY);
    assert_eq('drawn', $out['hunt']['phase']);
    assert_eq([['ann', 'Kitsune']], array_map(function ($w) { return [$w['user']['username'], $w['prize']['name']]; }, $out['winners']));
});

test('моё: выполненное, находки с текстом, билеты, выигрыши, Telegram', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann', 555);
    $t = ht_task($p, $h, ['reward' => 60]);
    ht_task($p, $h);
    $s = ht_secret($p, $h);
    hunt_do_task($p, [], ht_no_send(), $u, $t, [], HT_NOW);
    hunt_find_secret($p, [], $u, $s, 'calc', HT_NOW);
    hunt_buy_ticket($p, [], $u, 1, HT_NOW);
    $me = hunt_me($p, [], $u, HT_NOW);
    assert_eq($h, $me['hunt_id']);
    assert_eq(['balance' => 25, 'earned' => 75, 'tickets' => 1], $me['wallet']);
    assert_eq([$t], $me['done']);
    assert_eq([['id' => $s, 'page' => 'calc', 'spot' => 'head',
                'found' => ['ru' => 'Нашёл! Следующая — в новостях', 'en' => 'Found! Next one is in the news']]], $me['found']);
    assert_eq(['#0001'], array_column($me['tickets'], 'no'));
    assert_eq(true, $me['tg']);
    assert_eq('2026-10-14', $me['day']);
});

test('ближайшее действие по важности', function () {
    $hunt = ['status' => 'live', 'starts_at' => 0, 'sales_end_at' => HT_NOW + HT_DAY, 'ticket_price' => 50, 'ticket_limit' => 3];
    $w = ['balance' => 0, 'earned' => 0, 'tickets' => 0, 'first_at' => 0, 'last_at' => 0, 'last_day' => ''];
    $today = hunt_day(HT_NOW);
    assert_eq('join', hunt_next($hunt, $w, [], [], HT_NOW));
    $w = ['first_at' => 1, 'last_at' => HT_NOW - 60, 'last_day' => $today] + $w;
    assert_eq(null, hunt_next($hunt, $w, [], [], HT_NOW), 'сегодня уже всё получено');
    assert_eq('daily', hunt_next($hunt, ['last_day' => '2026-10-13'] + $w, [], [], HT_NOW));
    $ch = [['n' => 2, 'opens_at' => HT_NOW - 30]];
    assert_eq('chapter', hunt_next($hunt, $w, [], $ch, HT_NOW), 'глава открылась после визита');
    assert_eq('ticket', hunt_next($hunt, ['balance' => 50] + $w, [], $ch, HT_NOW), 'билет важнее главы');
    assert_eq(null, hunt_next($hunt, ['balance' => 50, 'tickets' => 3] + $w, [], [], HT_NOW), 'все билеты куплены');
    $won = [['status' => 'won', 'deadline' => HT_NOW + 10]];
    assert_eq('won', hunt_next($hunt, ['balance' => 50] + $w, $won, $ch, HT_NOW), 'выигрыш важнее всего');
    assert_eq('won', hunt_next(['status' => 'drawn'] + $hunt, $w, $won, [], HT_NOW), 'и после итогов');
    assert_eq(null, hunt_next(['status' => 'drawn'] + $hunt, $w, [['status' => 'won', 'deadline' => HT_NOW - 1]], [], HT_NOW), 'срок вышел');
});

// --------------------------------------------------------------------------
//  Эндпоинты
// --------------------------------------------------------------------------

test('hunt_act раздаёт действия, неизвестное — bad', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    $q = ht_task($p, $h, ['kind' => 'quiz', 'reward' => 30, 'cfg_json' => ht_quiz_cfg()]);
    $s = ht_secret($p, $h, ['reward' => 30]);
    assert_eq('done', handle_hunt_act($p, [], ht_no_send(), $u, ['act' => 'answer', 'id' => $q, 'choice' => 1, 'src' => 'Partner'], HT_NOW)[1]['status']);
    assert_eq('done', handle_hunt_act($p, [], ht_no_send(), $u, ['act' => 'secret', 'id' => $s, 'page' => 'calc', 'src' => 'other'], HT_NOW)[1]['status']);
    assert_eq('partner', hunt_wallet($p, $h, $u)['src'], 'источник — с первым действием, позже не меняется');
    [, $b] = handle_hunt_act($p, [], ht_no_send(), $u, ['act' => 'ticket', 'seq' => 1], HT_NOW);
    assert_eq(['done', 10], [$b['status'], $b['wallet']['balance']]);
    assert_eq('stale', handle_hunt_act($p, [], ht_no_send(), $u, ['act' => 'ticket', 'seq' => '2'], HT_NOW)[1]['status'], 'seq только числом');
    assert_eq([400, 'bad'], [handle_hunt_act($p, [], ht_no_send(), $u, ['act' => 'nope'], HT_NOW)[0], 'bad']);
});

test('hunt_me гостю — login, вошедшему — его данные', function () {
    $p = ht_db();
    ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    assert_eq([401, 'login'], [handle_hunt_me($p, [], '', HT_NOW)[0], handle_hunt_me($p, [], '', HT_NOW)[1]['status']]);
    [$c, $b] = handle_hunt_me($p, [], $u, HT_NOW);
    assert_eq([200, true, 'join'], [$c, $b['ok'], $b['next']]);
});

test('hunt.php ловит падение базы и принимает только известные страницы', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    ht_secret($p, $h);
    $db = function () use ($p) { return $p; };
    assert_eq(1, count(handle_hunt($db, HT_NOW, 'calc')['secrets']));
    assert_eq([], handle_hunt($db, HT_NOW, 'nowhere')['secrets'], 'незнакомая страница — без секреток');
    assert_eq(['hunt' => null], handle_hunt(function () { throw new PDOException('down'); }, HT_NOW));
});

test('session.php отдаёт поле hunt', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    $u = ht_user($p, '1', 'ann');
    ht_give($p, $h, $u, 50);
    $db = function () use ($p) { return $p; };
    $s = handle_session($db, ['user_id' => $u], [], HT_NOW);
    assert_eq(['balance' => 50, 'earned' => 50, 'tickets' => 0, 'next' => 'ticket'], $s['hunt']);
    assert_eq(null, handle_session($db, [], [], HT_NOW)['hunt'], 'гостю null');
    assert_eq(null, handle_session($db, ['user_id' => $u], ['banned_ids' => ['1']], HT_NOW)['hunt'], 'забаненному null');
    assert_eq(null, hunt_session_field(test_db(), [], $u, HT_NOW), 'таблиц нет — null, а не ошибка');
    $p->exec("UPDATE hunts SET status = 'draft'");
    assert_eq(null, hunt_session_field($p, [], $u, HT_NOW), 'черновик — null');
});

test('счётчики гостей пишут только известные метрики', function () {
    $p = ht_db();
    $h = ht_hunt($p);
    assert_eq(2, handle_hunt_stat($p, ['m' => ['page_v', 'invite_v', 'invite_v', 'hack']], HT_NOW));
    handle_hunt_stat($p, ['m' => ['page_v']], HT_NOW + 60);
    $rows = $p->query('SELECT metric, n FROM hunt_stats ORDER BY metric')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_eq(['invite_v' => 1, 'page_v' => 2], array_map('intval', $rows));
    assert_eq(0, handle_hunt_stat($p, ['m' => 'page_v'], HT_NOW), 'не список');
    assert_eq(0, handle_hunt_stat(test_db(), ['m' => ['page_v']], HT_NOW), 'таблиц нет');
});

run_tests();
