<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/stock.php';
require __DIR__ . '/../public_html/api/lib/hunt.php';
require __DIR__ . '/../public_html/api/tg_routes.php';
require __DIR__ . '/hunt_schema.php';

// Посты бота в каналы — api/lib/tg_routes.php и вкладка /admin/channels.
//
// Главные обещания, которые здесь держатся:
//  - без галочки в канал не уходит ничего: у бота есть и каналы рекламодателей;
//  - посты разных тем расходятся по своим каналам;
//  - сохранения тирлиста подряд правят один пост, а не плодят новые;
//  - отказ Telegram виден на вкладке и не мешает остальным каналам;
//  - cron не повторяет пост об ивенте и не объявляет старт задним числом;
//  - без таблицы всё работает как раньше.

const R_NOW  = 1790000000;
const R_A    = -1001111111111;
const R_B    = -1002222222222;
const R_CFG  = ['tg_bot_token' => '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'tg_bot_name' => 'MaknemyBot'];

function r_db(bool $withRoutes = true): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE tg_links (user_id INTEGER PRIMARY KEY, chat_id INTEGER NOT NULL, tg_name TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE tg_codes (code_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, lang TEXT NOT NULL DEFAULT 'ru', expires_at INTEGER NOT NULL)");
    $pdo->exec('CREATE TABLE chat_reads (thread_id INTEGER, user_id INTEGER, last_read_id INTEGER NOT NULL DEFAULT 0, seen_at INTEGER NOT NULL DEFAULT 0, notified_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (thread_id, user_id))');
    $pdo->exec('CREATE TABLE tg_prefs (user_id INTEGER PRIMARY KEY, prices INTEGER NOT NULL DEFAULT 1, news INTEGER NOT NULL DEFAULT 1)');
    $pdo->exec("CREATE TABLE tg_channels (chat_id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0, missing TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec('INSERT INTO tg_channels (chat_id, title, kind, is_admin) VALUES (' . R_A . ", 'Канал А', 'channel', 1)");
    $pdo->exec('INSERT INTO tg_channels (chat_id, title, kind, is_admin) VALUES (' . R_B . ", 'Чат Б', 'supergroup', 1)");
    if ($withRoutes) {
        $pdo->exec("CREATE TABLE tg_routes (chat_id INTEGER NOT NULL, topic TEXT NOT NULL, posted_at INTEGER NOT NULL DEFAULT 0, failed_at INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT '', msg_id INTEGER NOT NULL DEFAULT 0, msg_log TEXT, last_ref TEXT NOT NULL DEFAULT '', PRIMARY KEY (chat_id, topic))");
    }
    return $pdo;
}

function r_route(PDO $p, int $chat, string $topic): void {
    $p->prepare('INSERT INTO tg_routes (chat_id, topic) VALUES (?, ?)')->execute([$chat, $topic]);
}

function r_row(PDO $p, int $chat, string $topic): array {
    $st = $p->prepare('SELECT * FROM tg_routes WHERE chat_id = ? AND topic = ?');
    $st->execute([$chat, $topic]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Подставной Telegram: пишет вызовы в $log, отвечает как настоящий.
function r_tg(array &$log, ?callable $answer = null): callable {
    $next = 100;
    return function (string $m, array $params) use (&$log, $answer, &$next): ?array {
        $log[] = ['method' => $m] + $params;
        if ($answer) { return $answer($m, $params); }
        return ['ok' => true, 'result' => ['message_id' => $next++]];
    };
}

function r_quiet(): callable { return function (int $us): void {}; }

function r_change(string $id, string $name, ?string $from, string $to): array {
    return ['id' => $id, 'name' => $name, 'from' => $from, 'to' => $to];
}

function r_chats(array $log): array {
    return array_map(function (array $c) { return $c['chat_id']; }, $log);
}

test('без галочки в канал не уходит ничего', function () {
    $p = r_db();
    $log = [];
    assert_eq(0, tg_route_prices($p, r_tg($log), [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq(0, tg_route_post($p, r_tg($log), 'stock', ['text' => 'x'], R_NOW, r_quiet()));
    assert_eq([], $log);
});

test('без таблицы посты молчат, а не падают', function () {
    $p = r_db(false);
    $log = [];
    assert_eq(false, tg_routes_ready($p));
    assert_eq(0, tg_route_prices($p, r_tg($log), [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq('not_ready', tg_routes_save($p, [R_A], [R_A => ['news' => '1']]));
    assert_eq([], $log);
});

test('темы расходятся по своим каналам', function () {
    $p = r_db();
    r_route($p, R_A, 'stock');
    r_route($p, R_B, 'news');
    $log = [];
    tg_route_post($p, r_tg($log), 'stock', ['text' => 'сток'], R_NOW, r_quiet());
    assert_eq([R_A], r_chats($log), 'сток только в А');
    $log = [];
    tg_route_post($p, r_tg($log), 'news', ['text' => 'новость'], R_NOW, r_quiet());
    assert_eq([R_B], r_chats($log), 'новость только в Б');
});

test('сохранение галочек: новое добавляет, снятое убирает, оставшееся не теряет журнал', function () {
    $p = r_db();
    r_route($p, R_A, 'stock');
    r_route($p, R_A, 'news');
    $p->exec("UPDATE tg_routes SET posted_at = 5, msg_id = 7 WHERE topic = 'stock'");

    assert_eq('saved', tg_routes_save($p, [R_A, R_B], [R_A => ['stock' => '1'], R_B => ['prices' => '1']]));

    assert_eq('5', (string)r_row($p, R_A, 'stock')['posted_at'], 'оставшаяся строка не пересоздана');
    assert_eq([], r_row($p, R_A, 'news'), 'снятая галочка');
    assert_true(r_row($p, R_B, 'prices') !== [], 'новая галочка');
});

test('сохранение галочек: чужой чат и чужая тема из запроса не проходят', function () {
    $p = r_db();
    assert_eq('saved', tg_routes_save($p, [R_A], [-100999 => ['news' => '1'], R_A => ['hack' => '1'], R_B => ['news' => '1']]));
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_routes')->fetchColumn());

    assert_eq('saved', tg_routes_save($p, [R_A], 'мусор'), 'кривой ввод — всё снято, а не ошибка');
});

test('обработчик формы: чаты берёт у бота, без таблицы — not_ready', function () {
    $p = r_db();
    assert_eq('saved', handle_tg_routes($p, [R_A => ['news' => '1'], -100999 => ['news' => '1']]));
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM tg_routes')->fetchColumn());
    assert_eq('not_ready', handle_tg_routes(r_db(false), []));
});

test('пост: одна кнопка на сайт и ничего про настройки уведомлений', function () {
    $params = tg_route_prices_params([r_change('a', 'Dragon', '1k', '2k')]);
    assert_true(strpos($params['text'], '📈 Dragon: 1k → 2k') !== false, 'текст как в личке');
    assert_eq([[['text' => 'Открыть тирлист', 'url' => TG_SITE . '/tierlist']]], $params['reply_markup']['inline_keyboard']);
    assert_true(!array_key_exists('chat_id', $params), 'chat_id подставляет отправка');
});

test('цены: сохранения подряд правят один пост, накапливая изменения', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    assert_eq(1, tg_route_prices($p, $send, [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq('sendMessage', $log[0]['method']);
    assert_eq(100, (int)r_row($p, R_A, 'prices')['msg_id']);

    tg_route_prices($p, $send, [r_change('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet());
    assert_eq('editMessageText', $log[1]['method'], 'второе сохранение правит');
    assert_eq(100, $log[1]['message_id']);
    assert_true(strpos($log[1]['text'], 'Dragon') !== false && strpos($log[1]['text'], 'Kitsune') !== false, 'оба изменения');
    assert_eq(R_NOW, (int)r_row($p, R_A, 'prices')['posted_at'], 'правка не двигает окно');

    tg_route_prices($p, $send, [r_change('a', 'Dragon', '2', '3')], R_NOW + 120, r_quiet());
    assert_true(strpos($log[2]['text'], 'Dragon: 1 → 3') !== false, '«было» из первого поста');

    tg_route_prices($p, $send, [r_change('c', 'Yeti', '7', '8')], R_NOW + TG_ROUTE_MERGE + 1, r_quiet());
    assert_eq('sendMessage', $log[3]['method'], 'окно кончилось — новый пост');
    assert_true(strpos($log[3]['text'], 'Dragon') === false, 'старое в новый пост не тянем');
});

test('цены: вернули всё как было — пост удаляется', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    tg_route_prices($p, $send, [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    tg_route_prices($p, $send, [r_change('a', 'Dragon', '2', '1')], R_NOW + 60, r_quiet());
    assert_eq('deleteMessage', $log[1]['method']);
    assert_eq(100, $log[1]['message_id']);
    assert_eq(0, (int)r_row($p, R_A, 'prices')['msg_id']);

    tg_route_prices($p, $send, [r_change('a', 'Dragon', '1', '5')], R_NOW + 120, r_quiet());
    assert_eq('sendMessage', $log[2]['method'], 'следующее — снова новый пост');
});

test('цены: пост удалили руками — новый со всем накопленным', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log, function (string $m): array {
        if ($m === 'editMessageText') { return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found']; }
        return ['ok' => true, 'result' => ['message_id' => 200]];
    });
    tg_route_prices($p, $send, [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    assert_eq(1, tg_route_prices($p, $send, [r_change('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet()));
    assert_eq('sendMessage', $log[2]['method']);
    assert_true(strpos($log[2]['text'], 'Dragon') !== false && strpos($log[2]['text'], 'Kitsune') !== false);
});

test('цены: Telegram промолчал на правке — второго поста нет', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $n = 0;
    $send = r_tg($log, function (string $m) use (&$n): ?array {
        return $n++ === 0 ? ['ok' => true, 'result' => ['message_id' => 100]] : null;
    });
    tg_route_prices($p, $send, [r_change('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    assert_eq(0, tg_route_prices($p, $send, [r_change('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet()));
    assert_eq(2, count($log), 'только правка');
    assert_true(strpos((string)r_row($p, R_A, 'prices')['msg_log'], 'Kitsune') !== false, 'склейка запомнена');
});

test('цены: кривой журнал в базе не роняет пост', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $p->exec("UPDATE tg_routes SET msg_id = 5, posted_at = " . R_NOW . ", msg_log = '{мусор'");
    $log = [];
    assert_eq(1, tg_route_prices($p, r_tg($log), [r_change('a', 'Dragon', '1', '2')], R_NOW + 1, r_quiet()));
    assert_eq('editMessageText', $log[0]['method']);
    assert_eq([], tg_route_log('[1, {"id": 2}, {"id": "a", "name": "b", "to": "c", "from": 5}]'));
});

test('отказ Telegram записан, а остальные каналы получают пост', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    r_route($p, R_B, 'news');
    $log = [];
    $send = r_tg($log, function (string $m, array $params): ?array {
        if ($params['chat_id'] === R_B) { return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member']; }
        return ['ok' => true, 'result' => ['message_id' => 9]];
    });
    assert_eq(1, tg_route_post($p, $send, 'news', ['text' => 'x'], R_NOW, r_quiet()));
    $b = r_row($p, R_B, 'news');
    assert_eq(R_NOW, (int)$b['failed_at']);
    assert_eq('Forbidden: bot is not a member', $b['error']);
    assert_eq(R_NOW, (int)r_row($p, R_A, 'news')['posted_at']);

    $ok = [];
    tg_route_post($p, r_tg($ok), 'news', ['text' => 'x'], R_NOW + 5, r_quiet());
    assert_eq(0, (int)r_row($p, R_B, 'news')['failed_at'], 'вышедший пост стирает ошибку');
    assert_eq('', r_row($p, R_B, 'news')['error']);
});

test('пост не трогает личные привязки: отказ канала — не блокировка бота человеком', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, ?, 1)')->execute([R_A]);
    $log = [];
    tg_route_post($p, r_tg($log, function (): array { return ['ok' => false, 'error_code' => 403, 'description' => 'x']; }), 'news', ['text' => 'x'], R_NOW, r_quiet());
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn());
});

test('429: ждём и повторяем один раз', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $log = [];
    $n = 0;
    $slept = [];
    $send = r_tg($log, function () use (&$n): array {
        return $n++ === 0 ? ['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 3]] : ['ok' => true, 'result' => ['message_id' => 1]];
    });
    assert_eq(1, tg_route_post($p, $send, 'news', ['text' => 'x'], R_NOW, function (int $us) use (&$slept): void { $slept[] = $us; }));
    assert_eq([3000000], $slept);
    assert_eq(2, count($log));
});

test('tg_after_prices: и в канал, и в личку; без изменений — никуда', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, 555, 1)')->execute();
    $old = ['tiers' => [['items' => [['id' => 'a', 'name' => 'Dragon', 'value' => '1']]]]];
    $new = ['tiers' => [['items' => [['id' => 'a', 'name' => 'Dragon', 'value' => '2']]]]];

    $log = [];
    tg_after_prices($p, R_CFG, $old, $new, r_tg($log), r_quiet(), R_NOW);
    assert_eq([R_A, 555], r_chats($log), 'сначала канал, потом личка');
    assert_eq(1, count($log[0]['reply_markup']['inline_keyboard']), 'у канала одна кнопка');
    assert_eq(2, count($log[1]['reply_markup']['inline_keyboard']), 'в личке ещё и настройки');

    $none = [];
    tg_after_prices($p, R_CFG, $new, $new, r_tg($none), r_quiet(), R_NOW);
    assert_eq([], $none);
});

function r_news(PDO $p, int $publishedMs): int {
    $cols = [];
    foreach ($p->query('PRAGMA table_info(news)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ((int)$c['notnull'] === 1 && $c['dflt_value'] === null && (int)$c['pk'] === 0) { $cols[$c['name']] = ''; }
    }
    $cols = array_merge($cols, ['category' => 'update', 'title_ru' => 'Заголовок', 'body_ru' => 'Текст', 'published_at' => $publishedMs]);
    $names = array_keys($cols);
    $p->prepare('INSERT INTO news (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($cols));
    return (int)$p->lastInsertId();
}

test('tg_after_news: свежий пост в канал и в личку, задним числом — никуда', function () {
    $p = r_db();
    r_route($p, R_B, 'news');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, 555, 1)')->execute();
    $id = r_news($p, R_NOW * 1000);
    $log = [];
    tg_after_news($p, R_CFG, $id, R_NOW, r_tg($log), r_quiet());
    assert_eq([R_B, 555], r_chats($log));
    assert_true(strpos($log[0]['text'], 'Заголовок') !== false);
    assert_eq(TG_SITE . '/news/' . $id, $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);

    $none = [];
    tg_after_news($p, R_CFG, r_news($p, (R_NOW - TG_NEWS_FRESH - 10) * 1000), R_NOW, r_tg($none), r_quiet());
    assert_eq([], $none, 'перенос старой записи');
});

test('сток: в канал все фрукты смены, без отметок людей', function () {
    $p = r_db();
    r_route($p, R_A, 'stock');
    $stock = ['fruits' => [['key' => 'spike', 'name' => 'Spike', 'price' => 180000], ['key' => 'magma', 'name' => 'Magma', 'price' => 850000]], 'ends' => R_NOW + 3600];
    $log = [];
    assert_eq(1, stock_post($p, r_tg($log), 'normal', $stock, R_NOW, r_quiet()));
    assert_true(strpos($log[0]['text'], 'Обычный сток') !== false);
    assert_true(strpos($log[0]['text'], 'Spike') !== false && strpos($log[0]['text'], 'Magma') !== false);
    assert_eq(TG_SITE . '/stock', $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);

    $none = [];
    assert_eq(0, stock_post($p, r_tg($none), 'normal', ['fruits' => [], 'ends' => null], R_NOW, r_quiet()), 'пустой сток');
    assert_eq([], $none);
});

test('розыгрыш: новый отмечает каналы темы, до миграции — каналы прошлого', function () {
    $p = r_db();
    $p->exec('CREATE TABLE giveaway_posts (giveaway_id INTEGER, chat_id INTEGER, message_id INTEGER NOT NULL DEFAULT 0, posted_at INTEGER NOT NULL DEFAULT 0, results_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (giveaway_id, chat_id))');
    $p->exec('INSERT INTO giveaway_posts (giveaway_id, chat_id) VALUES (1, ' . R_B . ')');
    assert_eq([], gw_post_last($p), 'таблица есть, галочек нет');
    r_route($p, R_A, 'giveaways');
    r_route($p, R_A, 'news');
    assert_eq([(string)R_A], gw_post_last($p));

    $old = r_db(false);
    $old->exec('CREATE TABLE giveaway_posts (giveaway_id INTEGER, chat_id INTEGER, message_id INTEGER NOT NULL DEFAULT 0, posted_at INTEGER NOT NULL DEFAULT 0, results_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (giveaway_id, chat_id))');
    $old->exec('INSERT INTO giveaway_posts (giveaway_id, chat_id) VALUES (1, ' . R_B . ')');
    assert_eq([(string)R_B], gw_post_last($old));
});

function r_hunt(PDO $p, string $status, int $starts, int $drawnAt = 0): int {
    $prizes = json_encode([['name' => 'Hellfire Gas', 'count' => 2, 'icon' => ''], ['name' => 'Venom', 'count' => 1, 'icon' => '']], JSON_UNESCAPED_UNICODE);
    $p->prepare("INSERT INTO hunts (status, title_ru, title_en, starts_at, sales_end_at, draw_at, prizes_json, created_at, updated_at, drawn_at) VALUES (?, 'Spooky Tokens', 'Spooky Tokens', ?, ?, 0, ?, 1, 1, ?)")
        ->execute([$status, $starts, $starts + 8 * 86400, $prizes, $drawnAt]);
    return (int)$p->lastInsertId();
}

function r_hunt_db(): PDO {
    $p = r_db();
    hunt_sqlite_tables($p);
    return $p;
}

test('ивент: старт выходит один раз, в каждый канал', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    r_route($p, R_B, 'stock');
    r_hunt($p, 'live', R_NOW - 60);
    $log = [];
    $send = r_tg($log);
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, $send, r_quiet()));
    assert_eq([R_A], r_chats($log), 'только в свою тему');
    assert_true(strpos($log[0]['text'], 'Начался ивент «Spooky Tokens»') !== false);
    assert_true(strpos($log[0]['text'], 'Hellfire Gas × 2') !== false);
    assert_true(strpos($log[0]['text'], '• Venom') !== false);
    assert_eq(TG_SITE . '/halloween', $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);

    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW + 60, $send, r_quiet()), 'следующий проход cron');
    assert_eq(1, count($log));

    r_route($p, R_B, 'events');
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW + 120, $send, r_quiet()), 'новая галочка в первые сутки');
    assert_eq(R_B, $log[1]['chat_id']);
});

test('ивент: старт задним числом не объявляется, до старта — тоже', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    $id = r_hunt($p, 'live', R_NOW - HUNT_POST_FRESH - 1);
    $log = [];
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    $p->prepare('UPDATE hunts SET starts_at = ? WHERE id = ?')->execute([R_NOW + 3600, $id]);
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    $p->prepare("UPDATE hunts SET status = 'draft', starts_at = ? WHERE id = ?")->execute([R_NOW - 60, $id]);
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()), 'черновик');
    assert_eq([], $log);
});

test('ивент: итоги с победителями, без @ перед логином', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    $id = r_hunt($p, 'drawn', R_NOW - 10 * 86400, R_NOW - 60);
    $p->exec("INSERT INTO users (roblox_id, username, display_name, created_at, last_login_at) VALUES ('7', 'vasya', 'Вася', 1, 1)");
    $p->exec("INSERT INTO users (roblox_id, username, display_name, created_at, last_login_at) VALUES ('8', 'petya', '', 1, 1)");
    $win = $p->prepare("INSERT INTO hunt_wins (hunt_id, user_id, ticket_id, prize_idx, prize_name, prize_icon, status, deadline, created_at, updated_at) VALUES (?, ?, ?, ?, ?, '', ?, 0, 1, 1)");
    $win->execute([$id, '7', 1, 0, 'Hellfire Gas', 'won']);
    $win->execute([$id, '8', 2, 1, 'Venom', 'won']);
    $win->execute([$id, '9', 3, 1, 'Venom', 'expired']);
    $log = [];
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    assert_true(strpos($log[0]['text'], 'Итоги ивента «Spooky Tokens»') !== false);
    assert_true(strpos($log[0]['text'], 'Hellfire Gas — Вася') !== false);
    assert_true(strpos($log[0]['text'], 'Venom — petya') !== false);
    assert_true(strpos($log[0]['text'], '@') === false, 'иначе Telegram сделает ссылку на чужой аккаунт');
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW + 60, r_tg($log), r_quiet()), 'один раз');
});

test('ивент: после старта итоги всё равно выходят, старый старт не мешает', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    $p->exec("UPDATE tg_routes SET last_ref = 'hunt:1:start'");
    r_hunt($p, 'drawn', R_NOW - 10 * 86400, R_NOW - 60);
    $log = [];
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    assert_eq('hunt:1:results', r_row($p, R_A, 'events')['last_ref']);
});

test('ивент: Telegram промолчал — повторит следующий проход, отказал — нет', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    r_hunt($p, 'live', R_NOW - 60);
    $log = [];
    $silent = r_tg($log, function (): ?array { return null; });
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW, $silent, r_quiet()));
    assert_eq('', r_row($p, R_A, 'events')['last_ref'], 'молчание не окончательно');

    $refused = r_tg($log, function (): array { return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden']; });
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW + 60, $refused, r_quiet()));
    assert_eq('hunt:1:start', r_row($p, R_A, 'events')['last_ref'], 'отказ окончателен');
    assert_eq('Forbidden', r_row($p, R_A, 'events')['error']);
    $before = count($log);
    hunt_post_tick($p, R_CFG, R_NOW + 120, $refused, r_quiet());
    assert_eq($before, count($log), 'больше не стучимся');
});

test('ивент: без токена и без таблиц — ничего', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    r_hunt($p, 'live', R_NOW - 60);
    $log = [];
    assert_eq(0, hunt_post_tick($p, [], R_NOW, r_tg($log), r_quiet()), 'без токена');
    assert_eq(0, hunt_post_tick(r_db(), R_CFG, R_NOW, r_tg($log), r_quiet()), 'без таблиц ивента');
    assert_eq([], $log);
});

test('предупреждение вкладки: только то, что мешает постам', function () {
    assert_eq('', tg_route_warning(['is_admin' => 1, 'missing' => '']));
    assert_eq('', tg_route_warning(['is_admin' => 0, 'missing' => 'can_restrict_members']), 'нужно только розыгрышам');
    assert_true(strpos(tg_route_warning(['is_admin' => 0, 'missing' => 'can_post_messages,can_invite_users']), 'Отправка публикаций') !== false);
    assert_true(strpos(tg_route_warning(['is_admin' => 0, 'missing' => '']), 'не админ') !== false);
    assert_true(strpos(tg_route_warning(['is_admin' => 0]), 'не админ') !== false, 'до миграции колонки нет');
});

run_tests();
