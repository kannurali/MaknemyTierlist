<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/stock.php';
require __DIR__ . '/../public_html/api/lib/hunt.php';
require __DIR__ . '/../public_html/api/lib/tg_news_post.php';
require __DIR__ . '/../public_html/api/tg_routes.php';
require __DIR__ . '/hunt_schema.php';

// Посты бота в каналы — api/lib/tg_routes.php, tg_news_post.php и вкладка
// /admin/channels.
//
// Главные обещания, которые здесь держатся:
//  - без галочки в канал не уходит ничего: у бота есть и каналы рекламодателей;
//  - посты разных тем расходятся по своим каналам;
//  - в канале информация полная: новость целиком и с картинками, тирлист со всеми
//    изменениями цен и спроса, ивент со всеми победителями;
//  - длинное уходит несколькими сообщениями, и каждое Telegram примет;
//  - сохранения тирлиста подряд правят один пост, а не плодят новые;
//  - отказ Telegram виден на вкладке и не мешает остальным каналам;
//  - cron не повторяет пост об ивенте и не объявляет старт задним числом;
//  - без таблицы всё работает как раньше.

const R_NOW  = 1790000000;
const R_A    = -1001111111111;
const R_B    = -1002222222222;
const R_CFG  = ['tg_bot_token' => '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'tg_bot_name' => 'MaknemyBot'];
const R_IMG1 = '/images/1111111111111111111111111111111111111111.jpg';
const R_IMG2 = '/images/2222222222222222222222222222222222222222.png';
const R_WEBP = '/images/3333333333333333333333333333333333333333.webp';

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
        if ($m === 'sendMediaGroup') {
            $out = [];
            foreach ($params['media'] as $unused) { $out[] = ['message_id' => $next++]; }
            return ['ok' => true, 'result' => $out];
        }
        return ['ok' => true, 'result' => ['message_id' => $next++]];
    };
}

function r_quiet(): callable { return function (int $us): void {}; }

// Изменение цены в том виде, как его даёт tg_route_changes().
function r_price(string $id, string $name, ?string $from, string $to, string $group = 'fruits'): array {
    return ['key' => 'p:' . $id, 'kind' => 'price', 'from' => $from, 'to' => $to, 'name' => $name, 'group' => $group];
}

function r_msg(string $text): array {
    return [['sendMessage', ['text' => $text]]];
}

function r_chats(array $log): array {
    return array_map(function (array $c) { return $c['chat_id']; }, $log);
}

// Примет ли Telegram такое сообщение: длина и закрытые теги.
function r_accepts(string $html, int $limit = 4096): bool {
    if (tg_route_len($html) > $limit) { return false; }
    preg_match_all('~<(/?)([a-z-]+)[^>]*>~i', $html, $m, PREG_SET_ORDER);
    $stack = [];
    foreach ($m as $t) {
        if ($t[1] === '') { $stack[] = strtolower($t[2]); continue; }
        if (array_pop($stack) !== strtolower($t[2])) { return false; }
    }
    return !$stack;
}

test('без галочки в канал не уходит ничего', function () {
    $p = r_db();
    $log = [];
    assert_eq(0, tg_route_prices($p, r_tg($log), [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq(0, tg_route_post($p, r_tg($log), 'stock', r_msg('x'), R_NOW, r_quiet()));
    assert_eq([], $log);
});

test('без таблицы посты молчат, а не падают', function () {
    $p = r_db(false);
    $log = [];
    assert_eq(false, tg_routes_ready($p));
    assert_eq(0, tg_route_prices($p, r_tg($log), [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq('not_ready', tg_routes_save($p, [R_A], [R_A => ['news' => '1']]));
    assert_eq([], $log);
});

test('темы расходятся по своим каналам', function () {
    $p = r_db();
    r_route($p, R_A, 'stock');
    r_route($p, R_B, 'news');
    $log = [];
    tg_route_post($p, r_tg($log), 'stock', r_msg('сток'), R_NOW, r_quiet());
    assert_eq([R_A], r_chats($log), 'сток только в А');
    $log = [];
    tg_route_post($p, r_tg($log), 'news', r_msg('новость'), R_NOW, r_quiet());
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

// --------------------------------------------------------------------------
//  Длинный пост
// --------------------------------------------------------------------------

test('короткие абзацы — одно сообщение через пустую строку', function () {
    assert_eq(["один\n\nдва"], tg_route_split(['один', 'два']));
    assert_eq([], tg_route_split([]));
});

test('абзацы не рвутся, пока каждый влезает в сообщение', function () {
    $a = str_repeat('а', 60);
    $b = str_repeat('б', 60);
    $c = str_repeat('в', 60);
    assert_eq([$a . "\n\n" . $b, $c], tg_route_split([$a, $b, $c], 125));
});

test('длинный список режется по строкам и сохраняет оформление', function () {
    $lines = [];
    for ($i = 0; $i < 300; $i++) { $lines[] = "• <b>Предмет $i</b> — 100 → 200"; }
    $parts = tg_route_split(['<b>Заголовок</b>', implode("\n", $lines)]);
    assert_true(count($parts) > 1);
    foreach ($parts as $part) { assert_true(r_accepts($part), 'каждая часть принимается'); }
    $all = implode("\n", $parts);
    foreach ([0, 150, 299] as $i) {
        assert_true(strpos($all, "<b>Предмет $i</b>") !== false, "строка $i на месте с оформлением");
    }
});

test('длинная цитата и абзац теряют только разметку, а текст остаётся полностью', function () {
    $words = [];
    for ($i = 0; $i < 2000; $i++) { $words[] = "слово$i"; }
    $text = implode(' ', $words);
    foreach (["<blockquote>$text</blockquote>", "<b>$text</b>", $text] as $html) {
        $parts = tg_route_split([$html]);
        assert_true(count($parts) > 1);
        foreach ($parts as $part) { assert_true(r_accepts($part), 'часть принимается'); }
        assert_eq($text, implode(' ', $parts), 'ни слова не потеряно');
    }
});

test('спецсимволы при разрезании остаются экранированными', function () {
    $parts = tg_route_split(['<b>' . str_repeat('a &amp; b &lt; c ', 50) . '</b>'], 100);
    foreach ($parts as $part) {
        assert_true(r_accepts($part, 100));
        assert_eq(1, 1 - (int)preg_match('~<(?!/?b>)~', $part), 'голых < нет');
    }
});

test('слово длиннее сообщения режется, а не теряется и не зацикливается', function () {
    $word = str_repeat('я', 250);
    $parts = tg_route_split([$word], 100);
    assert_eq(3, count($parts));
    assert_eq($word, implode('', $parts));
});

test('вызовы текста: кнопка только под последним, превью выключено', function () {
    $calls = tg_route_text_calls([str_repeat('а', 3000), str_repeat('б', 3000)], 'Кнопка', '/x');
    assert_eq(2, count($calls));
    assert_true(!isset($calls[0][1]['reply_markup']), 'под первым кнопки нет');
    assert_eq(TG_SITE . '/x', $calls[1][1]['reply_markup']['inline_keyboard'][0][0]['url']);
    assert_eq('HTML', $calls[0][1]['parse_mode']);
    assert_eq(['is_disabled' => true], $calls[0][1]['link_preview_options']);
});

// --------------------------------------------------------------------------
//  Отправка
// --------------------------------------------------------------------------

test('многочастный пост: пауза между сообщениями, в журнале первое', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $log = [];
    $slept = [];
    $calls = array_merge(r_msg('1'), r_msg('2'), r_msg('3'));
    assert_eq(1, tg_route_post($p, r_tg($log), 'news', $calls, R_NOW, function (int $us) use (&$slept): void { $slept[] = $us; }));
    assert_eq(['1', '2', '3'], array_column($log, 'text'));
    assert_eq([TG_ROUTE_PART_GAP, TG_ROUTE_PART_GAP], $slept);
    assert_eq(100, (int)r_row($p, R_A, 'news')['msg_id']);
});

test('часть текста не вышла — дальше не идём, причина записана', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $log = [];
    $n = 0;
    $send = r_tg($log, function () use (&$n): array {
        return $n++ === 0 ? ['ok' => true, 'result' => ['message_id' => 1]] : ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request'];
    });
    assert_eq(1, tg_route_post($p, $send, 'news', array_merge(r_msg('1'), r_msg('2'), r_msg('3')), R_NOW, r_quiet()));
    assert_eq(2, count($log));
    assert_eq('Bad Request', r_row($p, R_A, 'news')['error']);
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
    assert_eq(1, tg_route_post($p, $send, 'news', r_msg('x'), R_NOW, r_quiet()));
    $b = r_row($p, R_B, 'news');
    assert_eq(R_NOW, (int)$b['failed_at']);
    assert_eq('Forbidden: bot is not a member', $b['error']);
    assert_eq(R_NOW, (int)r_row($p, R_A, 'news')['posted_at']);

    $ok = [];
    tg_route_post($p, r_tg($ok), 'news', r_msg('x'), R_NOW + 5, r_quiet());
    assert_eq(0, (int)r_row($p, R_B, 'news')['failed_at'], 'вышедший пост стирает ошибку');
    assert_eq('', r_row($p, R_B, 'news')['error']);
});

test('пост не трогает личные привязки: отказ канала — не блокировка бота человеком', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, ?, 1)')->execute([R_A]);
    $log = [];
    tg_route_post($p, r_tg($log, function (): array { return ['ok' => false, 'error_code' => 403, 'description' => 'x']; }), 'news', r_msg('x'), R_NOW, r_quiet());
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
    assert_eq(1, tg_route_post($p, $send, 'news', r_msg('x'), R_NOW, function (int $us) use (&$slept): void { $slept[] = $us; }));
    assert_eq([3000000], $slept);
    assert_eq(2, count($log));
});

test('транспорт: обычный вызов — JSON, с файлом — multipart', function () {
    $o = tg_http_options(['chat_id' => 5, 'text' => 'х', 'reply_markup' => ['a' => 1]]);
    assert_eq('{"chat_id":5,"text":"х","reply_markup":{"a":1}}', $o[CURLOPT_POSTFIELDS]);
    assert_eq(['Content-Type: application/json'], $o[CURLOPT_HTTPHEADER]);
    assert_eq(TG_HTTP_TIMEOUT, $o[CURLOPT_TIMEOUT]);

    if (!class_exists('CURLFile')) { return; }
    $o = tg_http_options(['chat_id' => -5, 'caption' => 'х', 'reply_markup' => ['a' => 1], '_files' => ['photo' => '/tmp/p.jpg']]);
    $post = $o[CURLOPT_POSTFIELDS];
    assert_eq('-5', $post['chat_id']);
    assert_eq('{"a":1}', $post['reply_markup'], 'вложенное — JSON-строкой');
    assert_true($post['photo'] instanceof CURLFile, 'файл');
    assert_eq('p.jpg', $post['photo']->getPostFilename());
    assert_true(!isset($post['_files']), 'служебное поле не уходит');
    assert_true(!isset($o[CURLOPT_HTTPHEADER]), 'заголовок multipart ставит curl');
    assert_eq(TG_UPLOAD_TIMEOUT, $o[CURLOPT_TIMEOUT]);
    assert_eq(TG_HTTP_TIMEOUT, tg_http_options(['text' => 'x', 'link_preview_options' => ['is_disabled' => true]])[CURLOPT_TIMEOUT]);
    assert_eq(TG_UPLOAD_TIMEOUT, tg_http_options(['text' => 'x', 'link_preview_options' => ['url' => 'https://x.test/a.jpg']])[CURLOPT_TIMEOUT],
        'превью Telegram забирает с сайта во время вызова');
    $keys = array_keys($o);
    assert_true(array_search(CURLOPT_POST, $keys, true) < array_search(CURLOPT_POSTFIELDS, $keys, true),
        'CURLOPT_POST раньше полей: иначе curl сбрасывает multipart и запрос уходит пустым');
});

// --------------------------------------------------------------------------
//  Тирлист
// --------------------------------------------------------------------------

function r_item(string $id, string $name, string $value, string $type = 'f', string $demand = ''): array {
    return ['id' => $id, 'name' => $name, 'value' => $value, 'type' => $type, 'demand' => $demand];
}

function r_tier(array $items): array {
    return ['tiers' => [['items' => $items]]];
}

test('изменения: цены, новые, спрос, разделы; та же цена в другой записи — молчим', function () {
    $old = r_tier([r_item('a', 'Dragon', '1000', 'p', 'green'), r_item('b', 'Yeti', '25000', 'f', 'red'), r_item('c', 'Gone', '5')]);
    $new = r_tier([r_item('a', 'Dragon', '2000', 'p', 'yellow'), r_item('b', 'Yeti', '25 000', 'f', ''), r_item('d', 'Fresh', '7', 'gp')]);
    assert_eq([
        ['key' => 'p:a', 'kind' => 'price', 'from' => '1000', 'to' => '2000', 'name' => 'Dragon', 'group' => 'perms'],
        ['key' => 'p:d', 'kind' => 'price', 'from' => null, 'to' => '7', 'name' => 'Fresh', 'group' => 'passes'],
        ['key' => 'd:a', 'kind' => 'demand', 'from' => 'green', 'to' => 'yellow', 'name' => 'Dragon', 'group' => 'perms'],
    ], tg_route_changes($old, $new));
    assert_eq([], tg_route_changes([], $new), 'самое первое сохранение');
});

test('изменения: только спрос — это тоже пост, неизвестный спрос — нет', function () {
    $old = r_tier([r_item('a', 'Dragon', '1', 'f', 'green')]);
    assert_eq(1, count(tg_route_changes($old, r_tier([r_item('a', 'Dragon', '1', 'f', 'red')]))));
    assert_eq([], tg_route_changes($old, r_tier([r_item('a', 'Dragon', '1', 'f', 'мусор')])));
});

test('пост о ценах: все изменения по разделам, спрос, ссылка, ни одного «и ещё»', function () {
    $changes = [];
    for ($i = 0; $i < 40; $i++) { $changes[] = r_price("u$i", "Up$i", '100', '200', 'perms'); }
    $changes[] = r_price('d', 'Down', '300', '100');
    $changes[] = r_price('q', 'Fix', '?', '500');
    $changes[] = r_price('n', 'New', null, '700', 'configurators');
    $changes[] = ['key' => 'd:x', 'kind' => 'demand', 'from' => 'green', 'to' => 'red', 'name' => 'Spike', 'group' => 'fruits'];

    $calls = tg_route_prices_calls($changes);
    assert_eq(1, count($calls), 'влезает в одно');
    $t = $calls[0][1]['text'];
    assert_true(r_accepts($t), 'Telegram примет');
    foreach (['Up0', 'Up39'] as $name) { assert_true(strpos($t, "• $name — 100 → 200") !== false, $name); }
    foreach (['💵 Permanent Fruits', '🍎 Фрукты', '🎨 Конфигураторы', '🔼 Выросли:', '🔽 Упали:', '🔄 Исправили цену:', '🆕 Добавили:'] as $s) {
        assert_true(strpos($t, $s) !== false, $s);
    }
    assert_true(strpos($t, '• Down — 300 → 100') !== false);
    assert_true(strpos($t, '• New — 700') !== false);
    assert_true(strpos($t, '✅ Обновили Demand:') !== false && strpos($t, '• Spike → Низкий') !== false, 'спрос');
    assert_true(strpos($t, TG_SITE . '/tierlist') !== false && strpos($t, 'Удачи вам в трейдах!') !== false, 'как у владельца');
    assert_true(strpos($t, 'и ещё') === false, 'ничего не свёрнуто');
    assert_eq([[['text' => 'Открыть тирлист', 'url' => TG_SITE . '/tierlist']]], $calls[0][1]['reply_markup']['inline_keyboard']);
});

test('огромное обновление: всё уходит несколькими сообщениями', function () {
    $changes = [];
    for ($i = 0; $i < 400; $i++) { $changes[] = r_price("u$i", "Предмет номер $i", '1 000 000', '2 000 000', $i % 2 ? 'perms' : 'fruits'); }
    $calls = tg_route_prices_calls($changes);
    assert_true(count($calls) > 1);
    $all = '';
    foreach ($calls as $c) {
        assert_true(r_accepts($c[1]['text']), 'часть принимается');
        $all .= $c[1]['text'] . "\n";
    }
    for ($i = 0; $i < 400; $i++) {
        assert_true(strpos($all, "Предмет номер $i ") !== false, "предмет $i на месте");
    }
    assert_true(!isset($calls[0][1]['reply_markup']) && isset($calls[count($calls) - 1][1]['reply_markup']), 'кнопка под последним');
});

test('цены: имя с разметкой экранируется', function () {
    $t = tg_route_prices_calls([r_price('a', '<b>x</b>&', '1', '2')])[0][1]['text'];
    assert_true(strpos($t, '&lt;b&gt;x&lt;/b&gt;&amp;') !== false);
});

test('цены: сохранения подряд правят один пост, накапливая изменения', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    assert_eq(1, tg_route_prices($p, $send, [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet()));
    assert_eq('sendMessage', $log[0]['method']);
    assert_eq(100, (int)r_row($p, R_A, 'prices')['msg_id']);

    tg_route_prices($p, $send, [r_price('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet());
    assert_eq('editMessageText', $log[1]['method'], 'второе сохранение правит');
    assert_eq(100, $log[1]['message_id']);
    assert_true(strpos($log[1]['text'], 'Dragon') !== false && strpos($log[1]['text'], 'Kitsune') !== false, 'оба изменения');
    assert_eq(R_NOW, (int)r_row($p, R_A, 'prices')['posted_at'], 'правка не двигает окно');

    tg_route_prices($p, $send, [r_price('a', 'Dragon', '2', '3')], R_NOW + 120, r_quiet());
    assert_true(strpos($log[2]['text'], 'Dragon — 1 → 3') !== false, '«было» из первого поста');

    tg_route_prices($p, $send, [r_price('c', 'Yeti', '7', '8')], R_NOW + TG_ROUTE_MERGE + 1, r_quiet());
    assert_eq('sendMessage', $log[3]['method'], 'окно кончилось — новый пост');
    assert_true(strpos($log[3]['text'], 'Dragon') === false, 'старое в новый пост не тянем');
});

test('цены: вернули всё как было — все сообщения поста удаляются', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    $big = [];
    for ($i = 0; $i < 300; $i++) { $big[] = r_price("u$i", "Предмет номер $i", '1 000 000', '2 000 000'); }
    tg_route_prices($p, $send, $big, R_NOW, r_quiet());
    $sent = count($log);
    assert_true($sent > 1);

    $back = [];
    for ($i = 0; $i < 300; $i++) { $back[] = r_price("u$i", "Предмет номер $i", '2 000 000', '1 000 000'); }
    tg_route_prices($p, $send, $back, R_NOW + 60, r_quiet());
    assert_eq($sent * 2, count($log));
    assert_eq('deleteMessage', $log[$sent]['method']);
    assert_eq(100, $log[$sent]['message_id']);
    assert_eq(0, (int)r_row($p, R_A, 'prices')['msg_id']);

    tg_route_prices($p, $send, [r_price('a', 'Dragon', '1', '5')], R_NOW + 120, r_quiet());
    assert_eq('sendMessage', $log[count($log) - 1]['method'], 'следующее — снова новый пост');
});

test('цены: пост вырос — досылается сообщение, кнопка переезжает вниз', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    tg_route_prices($p, $send, [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    assert_true(isset($log[0]['reply_markup']));

    $big = [];
    for ($i = 0; $i < 300; $i++) { $big[] = r_price("u$i", "Предмет номер $i", '1 000 000', '2 000 000'); }
    tg_route_prices($p, $send, $big, R_NOW + 60, r_quiet());
    assert_eq('editMessageText', $log[1]['method']);
    assert_true(!isset($log[1]['reply_markup']), 'с первого кнопка снята');
    $last = $log[count($log) - 1];
    assert_eq('sendMessage', $last['method'], 'хвост дослан');
    assert_true(isset($last['reply_markup']), 'кнопка под последним');
    assert_true(count(tg_route_log(r_row($p, R_A, 'prices')['msg_log'])['ids']) > 1, 'все сообщения запомнены');
});

test('цены: пост сжался — лишние сообщения удаляются', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log);
    $big = [];
    for ($i = 0; $i < 300; $i++) { $big[] = r_price("u$i", "Предмет номер $i", '1 000 000', '2 000 000'); }
    tg_route_prices($p, $send, $big, R_NOW, r_quiet());
    $sent = count($log);

    $back = [];
    for ($i = 1; $i < 300; $i++) { $back[] = r_price("u$i", "Предмет номер $i", '2 000 000', '1 000 000'); }
    tg_route_prices($p, $send, $back, R_NOW + 60, r_quiet());
    $after = array_slice($log, $sent);
    assert_eq('editMessageText', $after[0]['method']);
    assert_eq($sent - 1, count(array_filter($after, function ($c) { return $c['method'] === 'deleteMessage'; })));
    assert_eq([100], tg_route_log(r_row($p, R_A, 'prices')['msg_log'])['ids']);
});

test('цены: пост удалили руками — новый со всем накопленным', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $log = [];
    $send = r_tg($log, function (string $m): array {
        if ($m === 'editMessageText') { return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message to edit not found']; }
        return ['ok' => true, 'result' => ['message_id' => 200]];
    });
    tg_route_prices($p, $send, [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    assert_eq(1, tg_route_prices($p, $send, [r_price('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet()));
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
    tg_route_prices($p, $send, [r_price('a', 'Dragon', '1', '2')], R_NOW, r_quiet());
    assert_eq(0, tg_route_prices($p, $send, [r_price('b', 'Kitsune', '5', '6')], R_NOW + 60, r_quiet()));
    assert_eq(2, count($log), 'только правка');
    assert_true(strpos((string)r_row($p, R_A, 'prices')['msg_log'], 'Kitsune') !== false, 'склейка запомнена');
});

test('цены: кривой журнал в базе не роняет пост', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $p->exec("UPDATE tg_routes SET msg_id = 5, posted_at = " . R_NOW . ", msg_log = '{мусор'");
    $log = [];
    assert_eq(1, tg_route_prices($p, r_tg($log), [r_price('a', 'Dragon', '1', '2')], R_NOW + 1, r_quiet()));
    assert_eq('sendMessage', $log[0]['method'], 'нечего править — новый пост');

    $empty = ['ids' => [], 'changes' => []];
    assert_eq($empty, tg_route_log('[1, 2]'));
    assert_eq($empty, tg_route_log(null));
    $r = tg_route_log('{"ids":[0,"x",7],"changes":[1,{"key":"a"},{"key":"a","kind":"hack","to":"c","name":"b","group":"fruits"},{"key":"a","kind":"price","to":"c","name":"b","group":"чужой","from":5},{"key":"a","kind":"price","to":"c","name":"b","group":"чужой"}]}');
    assert_eq([7], $r['ids']);
    assert_eq([['key' => 'a', 'kind' => 'price', 'from' => null, 'to' => 'c', 'name' => 'b', 'group' => 'fruits']], $r['changes']);
});

test('tg_after_prices: в канал полный пост, в личку короткое; без изменений — никуда', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, 555, 1)')->execute();
    $items = [];
    $next = [];
    for ($i = 0; $i < 8; $i++) {
        $items[] = r_item("i$i", "Item$i", '100');
        $next[] = r_item("i$i", "Item$i", '200');
    }
    $log = [];
    tg_after_prices($p, R_CFG, r_tier($items), r_tier($next), r_tg($log), r_quiet(), R_NOW);
    assert_eq([R_A, 555], r_chats($log), 'сначала канал, потом личка');
    assert_true(strpos($log[0]['text'], 'Item7') !== false, 'в канале все восемь');
    assert_eq(1, count($log[0]['reply_markup']['inline_keyboard']), 'у канала одна кнопка');
    assert_true(strpos($log[1]['text'], 'Item7') === false && strpos($log[1]['text'], '…и ещё 3') !== false, 'в личке как раньше');
    assert_eq(2, count($log[1]['reply_markup']['inline_keyboard']), 'в личке ещё и настройки');

    $none = [];
    tg_after_prices($p, R_CFG, r_tier($next), r_tier($next), r_tg($none), r_quiet(), R_NOW);
    assert_eq([], $none);
});

test('tg_after_prices: сменился только спрос — пост в канал, личка молчит', function () {
    $p = r_db();
    r_route($p, R_A, 'prices');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, 555, 1)')->execute();
    $log = [];
    tg_after_prices($p, R_CFG, r_tier([r_item('a', 'Dragon', '1', 'f', 'green')]), r_tier([r_item('a', 'Dragon', '1', 'f', 'red')]), r_tg($log), r_quiet(), R_NOW);
    assert_eq([R_A], r_chats($log));
    assert_true(strpos($log[0]['text'], '• Dragon → Низкий') !== false);
});

// --------------------------------------------------------------------------
//  Новости
// --------------------------------------------------------------------------

function r_spans(string $s, array $flags = []): array {
    return [['s' => $s] + $flags];
}

function r_p(string $s, array $flags = []): array {
    return ['t' => 'p', 'ru' => r_spans($s, $flags), 'en' => []];
}

function r_img(string $url, string $cap = ''): array {
    return ['t' => 'image', 'url' => $url, 'w' => 100, 'h' => 100, 'pct' => 100, 'align' => 'center', 'wrap' => false,
        'cap_ru' => $cap !== '' ? r_spans($cap) : [], 'cap_en' => []];
}

function r_album(array $urls): array {
    $items = [];
    foreach ($urls as $u) { $items[] = ['url' => $u, 'w' => 100, 'h' => 100]; }
    return ['t' => 'album', 'items' => $items, 'cap_ru' => [], 'cap_en' => []];
}

// Строка новости: $blocks — тело из блоков, null — старый пост с body_ru.
function r_news(PDO $p, int $publishedMs, ?array $blocks = null, string $body = 'Текст', string $image = ''): int {
    $cols = [];
    foreach ($p->query('PRAGMA table_info(news)')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ((int)$c['notnull'] === 1 && $c['dflt_value'] === null && (int)$c['pk'] === 0) { $cols[$c['name']] = ''; }
    }
    $cols = array_merge($cols, [
        'category' => 'update', 'title_ru' => 'Заголовок', 'body_ru' => $body, 'published_at' => $publishedMs,
        'image_url' => $image, 'body_json' => $blocks === null ? null : json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE),
    ]);
    $names = array_keys($cols);
    $p->prepare('INSERT INTO news (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')')
        ->execute(array_values($cols));
    return (int)$p->lastInsertId();
}

// public_html понарошку: каталог с картинками новости.
function r_root(array $urls = []): string {
    $root = sys_get_temp_dir() . '/nx-tg-root-' . bin2hex(random_bytes(6));
    mkdir($root . '/images', 0777, true);
    foreach ($urls as $u) { file_put_contents($root . $u, 'x'); }
    return $root;
}

function r_root_drop(string $root): void {
    foreach (glob($root . '/images/*') ?: [] as $f) { @unlink($f); }
    @rmdir($root . '/images');
    @rmdir($root);
}

function r_news_post(PDO $p, int $id, ?string $root = null): array {
    r_route($p, R_A, 'news');
    $log = [];
    tg_route_news($p, r_tg($log), $id, R_NOW, r_quiet(), $root ?? '/нет-такого-каталога');
    return $log;
}

test('новость без картинки: весь текст с оформлением и кнопка', function () {
    $p = r_db();
    $long = str_repeat('Длинное предложение новости. ', 20);
    $id = r_news($p, R_NOW * 1000, [
        r_p($long),
        r_p('Жирный', ['b' => true]),
        ['t' => 'quote', 'ru' => r_spans('Цитата'), 'en' => [], 'collapsible' => true],
        ['t' => 'list', 'ordered' => false, 'items' => [['ru' => r_spans('Раз'), 'en' => []], ['ru' => r_spans('Два'), 'en' => []]]],
        ['t' => 'code', 'ru' => 'a<b', 'en' => ''],
        ['t' => 'p', 'ru' => [['s' => 'сайт', 'href' => 'https://x.test/']], 'en' => []],
    ]);
    $log = r_news_post($p, $id);
    assert_eq(1, count($log));
    assert_eq('sendMessage', $log[0]['method']);
    $t = $log[0]['text'];
    assert_true(strpos($t, '📰 <b>Заголовок</b>') === 0, 'заголовок');
    assert_true(strpos($t, trim($long)) !== false, 'текст не обрезан');
    assert_true(strpos($t, '<b>Жирный</b>') !== false, 'жирный');
    assert_true(strpos($t, '<blockquote expandable>Цитата</blockquote>') !== false, 'цитата');
    assert_true(strpos($t, "• Раз\n• Два") !== false, 'список');
    assert_true(strpos($t, '<pre>a&lt;b</pre>') !== false, 'код');
    assert_true(strpos($t, '<a href="https://x.test/">сайт</a>') !== false, 'ссылка');
    assert_true(r_accepts($t), 'Telegram примет');
    assert_eq(TG_SITE . '/news/' . $id, $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);
    assert_eq(['is_disabled' => true], $log[0]['link_preview_options']);
});

test('новость: пустые абзацы-отступы не попадают в пост', function () {
    $p = r_db();
    $id = r_news($p, R_NOW * 1000, [r_p('Раз'), r_p("\u{00A0}"), ['t' => 'p', 'ru' => [], 'en' => []], r_p('Два')]);
    $log = r_news_post($p, $id);
    assert_eq("📰 <b>Заголовок</b>\n\nРаз\n\nДва", $log[0]['text']);
});

test('новость: нет русского текста блока — английский', function () {
    $p = r_db();
    $id = r_news($p, R_NOW * 1000, [['t' => 'p', 'ru' => [], 'en' => r_spans('Only english')]]);
    $log = r_news_post($p, $id);
    assert_true(strpos($log[0]['text'], 'Only english') !== false);
});

test('новость с картинкой и коротким текстом: фото с подписью и кнопкой, файлом', function () {
    $p = r_db();
    $root = r_root([R_IMG1]);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p('Короткий текст', ['b' => true])], '', R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_eq(1, count($log), 'одно сообщение');
    assert_eq('sendPhoto', $log[0]['method']);
    assert_eq($root . R_IMG1, $log[0]['_files']['photo'], 'картинка файлом');
    assert_eq("📰 <b>Заголовок</b>\n\n<b>Короткий текст</b>", $log[0]['caption']);
    assert_eq('HTML', $log[0]['parse_mode']);
    assert_eq(TG_SITE . '/news/' . $id, $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);
    r_root_drop($root);
});

function r_preview(string $url): array {
    return ['url' => TG_SITE . $url, 'prefer_large_media' => true, 'show_above_text' => true];
}

test('новость с картинкой и длинным текстом: одно сообщение, картинка над текстом', function () {
    $p = r_db();
    $root = r_root([R_IMG1]);
    $long = str_repeat('Длинное предложение новости. ', 60);
    $id = r_news($p, R_NOW * 1000, [r_p('Начало'), r_img(R_IMG1, 'Подпись к картинке'), r_p($long)], '', R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendMessage'], array_column($log, 'method'), 'картинка и текст не разделены');
    assert_eq(r_preview(R_IMG1), $log[0]['link_preview_options']);
    assert_true(strpos($log[0]['text'], trim($long)) !== false, 'текст целиком');
    assert_true(strpos($log[0]['text'], "Начало\n\n<i>Подпись к картинке</i>\n\nДлинное") !== false, 'подпись на своём месте');
    assert_eq(TG_SITE . '/news/' . $id, $log[0]['reply_markup']['inline_keyboard'][0][0]['url'], 'кнопка под текстом');
    assert_eq('', r_row($p, R_A, 'news')['error']);
    r_root_drop($root);
});

test('новость: текст длиннее сообщения — картинка над первой частью, кнопка под последней', function () {
    $p = r_db();
    $root = r_root([R_IMG1]);
    $blocks = [r_img(R_IMG1)];
    for ($i = 0; $i < 60; $i++) { $blocks[] = r_p("Абзац $i. " . str_repeat('слово ', 50)); }
    $id = r_news($p, R_NOW * 1000, $blocks, '', R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_true(count($log) > 1, 'частей несколько');
    assert_eq(array_fill(0, count($log), 'sendMessage'), array_column($log, 'method'));
    assert_eq(r_preview(R_IMG1), $log[0]['link_preview_options']);
    assert_eq(['is_disabled' => true], $log[1]['link_preview_options'], 'картинка не повторяется');
    assert_true(!isset($log[0]['reply_markup']) && isset($log[count($log) - 1]['reply_markup']));
    $all = implode("\n", array_column($log, 'text'));
    for ($i = 0; $i < 60; $i++) { assert_true(strpos($all, "Абзац $i.") !== false, "абзац $i"); }
    r_root_drop($root);
});

test('новость: длинный текст и несколько картинок — первая над текстом, остальные следом', function () {
    $p = r_db();
    $root = r_root([R_IMG1, R_IMG2]);
    $long = str_repeat('Длинное предложение новости. ', 60);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p($long), r_img(R_IMG2)], '', R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendMessage', 'sendPhoto'], array_column($log, 'method'));
    assert_eq(r_preview(R_IMG1), $log[0]['link_preview_options']);
    assert_true(isset($log[0]['reply_markup']), 'кнопка у текста');
    assert_eq($root . R_IMG2, $log[1]['_files']['photo'], 'вторая картинка файлом');
    r_root_drop($root);

    $urls = [];
    for ($i = 0; $i < 3; $i++) { $urls[] = '/images/' . str_pad((string)$i, 40, 'b') . '.jpg'; }
    $p = r_db();
    $root = r_root($urls);
    $id = r_news($p, R_NOW * 1000, [r_album($urls), r_p($long)], '', $urls[0]);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendMessage', 'sendMediaGroup'], array_column($log, 'method'));
    assert_eq(2, count($log[1]['media']), 'первой в альбоме нет: она над текстом');
    assert_true(!isset($log[1]['media'][0]['caption']), 'подпись не нужна: текст уже вышел');
    r_root_drop($root);
});

test('новость: длинный текст, картинки нет на диске — фото адресом и текст, превью не обещаем', function () {
    $p = r_db();
    $long = str_repeat('Длинное предложение новости. ', 60);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p($long)], '', R_IMG1);
    $log = r_news_post($p, $id);
    assert_eq(['sendPhoto', 'sendMessage'], array_column($log, 'method'));
    assert_eq(TG_SITE . R_IMG1, $log[0]['photo']);
    assert_eq(['is_disabled' => true], $log[1]['link_preview_options']);
});

test('новость: сообщение с превью отвергнуто — картинка файлом перед тем же текстом, причина видна', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $root = r_root([R_IMG1]);
    $long = str_repeat('Длинное предложение новости. ', 60);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p($long)], '', R_IMG1);
    $log = [];
    $n = 70;
    $send = r_tg($log, function (string $m, array $params) use (&$n): array {
        if (isset($params['link_preview_options']['url'])) {
            return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: WEBPAGE_CURL_FAILED'];
        }
        return ['ok' => true, 'result' => ['message_id' => $n++]];
    });
    assert_eq(1, tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root));
    assert_eq(['sendMessage', 'sendPhoto', 'sendMessage'], array_column($log, 'method'));
    assert_eq($root . R_IMG1, $log[1]['_files']['photo']);
    assert_eq(['is_disabled' => true], $log[2]['link_preview_options']);
    assert_true(strpos($log[2]['text'], trim($long)) !== false, 'текст целиком');
    assert_true(isset($log[2]['reply_markup']), 'кнопка на месте');
    $row = r_row($p, R_A, 'news');
    assert_eq(70, (int)$row['msg_id']);
    assert_eq('Картинка не вышла: Bad Request: WEBPAGE_CURL_FAILED', $row['error']);
    r_root_drop($root);
});

test('новость: Telegram не ответил на сообщение с превью — второй раз не шлём, пост мог выйти', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $root = r_root([R_IMG1]);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p(str_repeat('слово ', 400))], '', R_IMG1);
    $log = [];
    $send = r_tg($log, function (): ?array { return null; });
    assert_eq(0, tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root));
    assert_eq(['sendMessage'], array_column($log, 'method'));
    assert_true((int)r_row($p, R_A, 'news')['failed_at'] > 0, 'сбой виден');
    r_root_drop($root);
});

test('новость: бот без прав и превью — причина в тексте, а не в картинке', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $root = r_root([R_IMG1]);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p(str_repeat('слово ', 400))], '', R_IMG1);
    $log = [];
    $send = r_tg($log, function (): array { return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden']; });
    assert_eq(0, tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root));
    assert_eq(['sendMessage', 'sendPhoto', 'sendMessage'], array_column($log, 'method'));
    assert_eq('Forbidden', r_row($p, R_A, 'news')['error']);
    r_root_drop($root);
});

test('новость: WebP в превью — JPEG-копия рядом, она же при повторе; копии не вышло — фото перед текстом', function () {
    if (!function_exists('imagewebp')) { return; }
    $jpg = substr(R_WEBP, 0, -5) . '.jpg';
    $p = r_db();
    $root = r_root();
    $im = imagecreatetruecolor(4, 4);
    imagewebp($im, $root . R_WEBP);
    imagedestroy($im);
    $long = str_repeat('Длинное предложение новости. ', 60);
    $id = r_news($p, R_NOW * 1000, [r_img(R_WEBP), r_p($long)], '', R_WEBP);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendMessage'], array_column($log, 'method'));
    assert_eq(r_preview($jpg), $log[0]['link_preview_options']);
    assert_eq('image/jpeg', getimagesize($root . $jpg)['mime'], 'на сайте лежит JPEG');
    file_put_contents($root . $jpg, 'уже есть');
    assert_eq($jpg, tg_route_preview_url(R_WEBP, $root));
    assert_eq('уже есть', file_get_contents($root . $jpg), 'готовая копия не пересоздаётся');
    r_root_drop($root);

    $p = r_db();
    $root = r_root([R_WEBP]);
    $id = r_news($p, R_NOW * 1000, [r_img(R_WEBP), r_p($long)], '', R_WEBP);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendPhoto', 'sendMessage'], array_column($log, 'method'), 'файл не читается как картинка');
    assert_true(!is_file($root . $jpg), 'пустой копии нет');
    r_root_drop($root);
});

test('новость с альбомом и коротким текстом: один пост, подпись у альбома, ссылка вместо кнопки', function () {
    $p = r_db();
    $root = r_root([R_IMG1, R_IMG2]);
    $id = r_news($p, R_NOW * 1000, [r_album([R_IMG1, R_IMG2]), r_p('Текст')], '', R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_eq(['sendMediaGroup'], array_column($log, 'method'), 'картинки и текст не разделены');
    $caption = "📰 <b>Заголовок</b>\n\nТекст\n\n" . '<a href="' . TG_SITE . '/news/' . $id . '">Читать на сайте</a>';
    assert_eq([
        ['type' => 'photo', 'media' => 'attach://p0', 'caption' => $caption, 'parse_mode' => 'HTML'],
        ['type' => 'photo', 'media' => 'attach://p1'],
    ], $log[0]['media']);
    assert_eq(['p0' => $root . R_IMG1, 'p1' => $root . R_IMG2], $log[0]['_files']);
    assert_eq(100, (int)r_row($p, R_A, 'news')['msg_id'], 'альбом отвечает списком сообщений');
    r_root_drop($root);
});

test('новость: альбом с подписью отвергнут — текст с кнопкой всё равно выходит', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $root = r_root([R_IMG1, R_IMG2]);
    $id = r_news($p, R_NOW * 1000, [r_album([R_IMG1, R_IMG2]), r_p('Текст')], '', R_IMG1);
    $log = [];
    $send = r_tg($log, function (string $m): array {
        if ($m === 'sendMediaGroup') { return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: IMAGE_PROCESS_FAILED']; }
        return ['ok' => true, 'result' => ['message_id' => 56]];
    });
    assert_eq(1, tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root));
    assert_eq(['sendMediaGroup', 'sendMessage'], array_column($log, 'method'));
    assert_true(strpos($log[1]['text'], 'Текст') !== false);
    assert_true(isset($log[1]['reply_markup']));
    assert_eq('Картинка не вышла: Bad Request: IMAGE_PROCESS_FAILED', r_row($p, R_A, 'news')['error']);
    r_root_drop($root);
});

test('новость: ссылка на сайт считается в пределе подписи альбома', function () {
    $linkLen = mb_strlen("\n\nЧитать на сайте");
    $head = mb_strlen("📰 Заголовок\n\n");
    $fits = TG_ROUTE_CAPTION_MAX - $linkLen - $head;

    $p = r_db();
    $root = r_root([R_IMG1, R_IMG2]);
    $id = r_news($p, R_NOW * 1000, [r_album([R_IMG1, R_IMG2]), r_p(str_repeat('я', $fits))], '', R_IMG1);
    assert_eq(['sendMediaGroup'], array_column(r_news_post($p, $id, $root), 'method'), 'ровно в предел');

    $p = r_db();
    $id = r_news($p, R_NOW * 1000, [r_album([R_IMG1, R_IMG2]), r_p(str_repeat('я', $fits + 1))], '', R_IMG1);
    assert_eq(['sendMessage', 'sendPhoto'], array_column(r_news_post($p, $id, $root), 'method'), 'на знак больше');
    r_root_drop($root);
});

test('новость: много картинок — альбомы по 10, одиночный остаток фото; дубли не повторяются', function () {
    $urls = [];
    for ($i = 0; $i < 11; $i++) { $urls[] = '/images/' . str_pad((string)$i, 40, 'a') . '.jpg'; }
    $p = r_db();
    $id = r_news($p, R_NOW * 1000, [r_album(array_slice($urls, 0, 10)), r_img($urls[10]), r_img($urls[0]), r_p('Текст')], '', $urls[0]);
    $log = r_news_post($p, $id);
    assert_eq(['sendMediaGroup', 'sendPhoto'], array_column($log, 'method'));
    assert_eq(10, count($log[0]['media']));
    assert_true(strpos($log[0]['media'][0]['caption'], 'Текст') !== false, 'текст подписью к первому альбому');
    assert_eq(TG_SITE . $urls[10], $log[1]['photo'], 'файла нет — адрес на сайте');
});

test('новость: картинка, которой нет на диске, уходит адресом', function () {
    $p = r_db();
    $id = r_news($p, R_NOW * 1000, [r_album([R_IMG1, R_IMG2])], '', R_IMG1);
    $log = r_news_post($p, $id);
    assert_eq(TG_SITE . R_IMG1, $log[0]['media'][0]['media']);
    assert_true(!isset($log[0]['_files']));
});

test('старая новость без блоков: абзацы из body_ru и image_url, разметка экранируется', function () {
    $p = r_db();
    $root = r_root([R_IMG1]);
    $id = r_news($p, R_NOW * 1000, null, "Первый <абзац>\n\nВторой", R_IMG1);
    $log = r_news_post($p, $id, $root);
    assert_eq('sendPhoto', $log[0]['method']);
    assert_eq("📰 <b>Заголовок</b>\n\nПервый &lt;абзац&gt;\n\nВторой", $log[0]['caption']);
    r_root_drop($root);
});

test('новость: кривой body_json не теряет пост, чужая картинка не уходит', function () {
    $p = r_db();
    $id = r_news($p, R_NOW * 1000, null, 'Текст', 'https://evil.test/x.jpg');
    $p->prepare('UPDATE news SET body_json = ? WHERE id = ?')->execute(['{мусор', $id]);
    $log = r_news_post($p, $id);
    assert_eq(['sendMessage'], array_column($log, 'method'));
    assert_true(strpos($log[0]['text'], 'Текст') !== false);
});

test('новость: очень длинная разбивается, ничего не потеряно', function () {
    $p = r_db();
    $blocks = [];
    for ($i = 0; $i < 60; $i++) { $blocks[] = r_p("Абзац $i. " . str_repeat('слово ', 50)); }
    $id = r_news($p, R_NOW * 1000, $blocks);
    $log = r_news_post($p, $id);
    assert_true(count($log) > 1);
    $all = implode("\n", array_column($log, 'text'));
    for ($i = 0; $i < 60; $i++) { assert_true(strpos($all, "Абзац $i.") !== false, "абзац $i"); }
    foreach ($log as $c) { assert_true(r_accepts($c['text']), 'часть принимается'); }
    assert_true(!isset($log[0]['reply_markup']) && isset($log[count($log) - 1]['reply_markup']), 'кнопка под последним');
});

test('новость: фото с подписью отвергнуто — текст всё равно выходит, причина видна', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $root = r_root([R_IMG1]);
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p('Текст')], '', R_IMG1);
    $log = [];
    $send = r_tg($log, function (string $m): array {
        if ($m === 'sendPhoto') { return ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: IMAGE_PROCESS_FAILED']; }
        return ['ok' => true, 'result' => ['message_id' => 55]];
    });
    assert_eq(1, tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root));
    assert_eq(['sendPhoto', 'sendMessage'], array_column($log, 'method'));
    assert_true(strpos($log[1]['text'], 'Текст') !== false);
    assert_true(isset($log[1]['reply_markup']));
    $row = r_row($p, R_A, 'news');
    assert_eq(55, (int)$row['msg_id']);
    assert_eq('Картинка не вышла: Bad Request: IMAGE_PROCESS_FAILED', $row['error']);
    r_root_drop($root);
});

test('новость: бот без прав — один отказ на картинке и один на тексте, не больше', function () {
    $p = r_db();
    r_route($p, R_A, 'news');
    $id = r_news($p, R_NOW * 1000, [r_img(R_IMG1), r_p(str_repeat('слово ', 400))], '', R_IMG1);
    $log = [];
    $send = r_tg($log, function (): array { return ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden']; });
    assert_eq(0, tg_route_news($p, $send, $id, R_NOW, r_quiet(), '/нет'));
    assert_eq(2, count($log));
    assert_eq('Forbidden', r_row($p, R_A, 'news')['error']);
});

test('новость: WebP уходит JPEG-ом, временный файл убирается', function () {
    if (!function_exists('imagewebp')) { return; }
    $p = r_db();
    $root = r_root();
    $im = imagecreatetruecolor(4, 4);
    imagewebp($im, $root . R_WEBP);
    imagedestroy($im);
    r_route($p, R_A, 'news');
    $id = r_news($p, R_NOW * 1000, [r_img(R_WEBP), r_p('Текст')], '', R_WEBP);
    $type = null;
    $path = null;
    $log = [];
    $inner = r_tg($log);
    $send = function (string $m, array $params) use (&$type, &$path, $inner): ?array {
        if ($m === 'sendPhoto') {
            $path = $params['_files']['photo'];
            $type = @getimagesize($path)['mime'] ?? null;
        }
        return $inner($m, $params);
    };
    tg_route_news($p, $send, $id, R_NOW, r_quiet(), $root);
    assert_eq('image/jpeg', $type, 'в момент отправки это JPEG');
    assert_true(substr((string)$path, -4) === '.jpg');
    assert_true(!is_file((string)$path), 'временный файл убран');
    r_root_drop($root);
});

test('новость: файл не читается как картинка — уходит адресом', function () {
    if (!function_exists('imagecreatefromstring')) { return; }
    $root = r_root([R_WEBP]);
    $temp = [];
    assert_eq(['url' => TG_SITE . R_WEBP], tg_route_news_photo(R_WEBP, $root, $temp));
    assert_eq([], $temp);
    r_root_drop($root);
});

test('tg_after_news: свежий пост в канал и в личку, задним числом — никуда', function () {
    $p = r_db();
    r_route($p, R_B, 'news');
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (1, 555, 1)')->execute();
    $long = str_repeat('Длинный текст новости. ', 20);
    $id = r_news($p, R_NOW * 1000, null, $long);
    $log = [];
    tg_after_news($p, R_CFG, $id, R_NOW, r_tg($log), r_quiet());
    assert_eq([R_B, 555], r_chats($log));
    assert_true(strpos($log[0]['text'], trim($long)) !== false, 'в канале целиком');
    assert_true(mb_strlen($log[1]['text']) < 120, 'в личке коротко, как раньше');
    assert_eq(TG_SITE . '/news/' . $id, $log[0]['reply_markup']['inline_keyboard'][0][0]['url']);

    $none = [];
    tg_after_news($p, R_CFG, r_news($p, (R_NOW - TG_NEWS_FRESH - 10) * 1000), R_NOW, r_tg($none), r_quiet());
    assert_eq([], $none, 'перенос старой записи');
});

// news_save.php не грузит розыгрыши: сборку поста tg_after_news() подключает сама.
// Ошибка здесь не видна на сайте, её глотает try: новость просто не вышла бы.
test('tg_after_news: в окружении news_save.php сборка поста подгружается сама', function () {
    $dir = str_replace('\\', '/', __DIR__);
    $script = sys_get_temp_dir() . '/nx-tg-lazy-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($script, <<<PHP
<?php
define('TESTING', 1);
require '$dir/lib.php';
require '$dir/../public_html/api/news_save.php';
\$before = function_exists('tg_route_news');
\$p = test_db();
\$p->exec("CREATE TABLE tg_routes (chat_id INTEGER, topic TEXT, posted_at INTEGER DEFAULT 0, failed_at INTEGER DEFAULT 0, error TEXT DEFAULT '', msg_id INTEGER DEFAULT 0, msg_log TEXT, last_ref TEXT DEFAULT '')");
\$p->exec("INSERT INTO tg_routes (chat_id, topic) VALUES (-100, 'news')");
\$p->exec("INSERT INTO news (category, title_ru, body_ru, body_en, published_at) VALUES ('game', 'Заголовок', 'Весь текст', '', 1790000000000)");
\$sent = [];
\$send = function (string \$m, array \$a) use (&\$sent): array { \$sent[] = \$a['text'] ?? ''; return ['ok' => true, 'result' => ['message_id' => 5]]; };
tg_after_news(\$p, ['tg_bot_token' => '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'tg_bot_name' => 'MaknemyBot'], 1, 1790000000, \$send, function (int \$us): void {});
echo json_encode(['before' => \$before, 'after' => function_exists('tg_route_news'), 'sent' => \$sent], JSON_UNESCAPED_UNICODE);
PHP);
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
    @unlink($script);
    $d = json_decode((string)$out, true);
    assert_true(is_array($d), 'скрипт отработал: ' . $out);
    if (!is_array($d)) { return; }
    assert_eq(false, $d['before'], 'до этого сборка не загружена');
    assert_eq(true, $d['after'], 'подгрузилась');
    assert_eq(["📰 <b>Заголовок</b>\n\nВесь текст"], $d['sent']);
});

// --------------------------------------------------------------------------
//  Сток и розыгрыши
// --------------------------------------------------------------------------

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

// --------------------------------------------------------------------------
//  Ивенты
// --------------------------------------------------------------------------

function r_hunt(PDO $p, string $status, int $starts, int $drawnAt = 0, string $rules = ''): int {
    $prizes = json_encode([['name' => 'Hellfire Gas', 'count' => 2, 'icon' => ''], ['name' => 'Venom', 'count' => 1, 'icon' => '']], JSON_UNESCAPED_UNICODE);
    $p->prepare("INSERT INTO hunts (status, title_ru, title_en, rules_ru, starts_at, sales_end_at, draw_at, login_json, hours_json, prizes_json, created_at, updated_at, drawn_at) VALUES (?, 'Spooky Tokens', 'Spooky Tokens', ?, ?, ?, ?, '[10,15,20,25,30,35,40]', '[15,20]', ?, 1, 1, ?)")
        ->execute([$status, $rules, $starts, $starts + 8 * 86400, $starts + 9 * 86400, $prizes, $drawnAt]);
    return (int)$p->lastInsertId();
}

function r_hunt_db(): PDO {
    $p = r_db();
    hunt_sqlite_tables($p);
    return $p;
}

test('ивент: старт с правилами, призами и сроками, один раз в каждый канал', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    r_route($p, R_B, 'stock');
    r_hunt($p, 'live', R_NOW - 60, 0, "Своё правило\n\nЕщё <правило>");
    $log = [];
    $send = r_tg($log);
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, $send, r_quiet()));
    assert_eq([R_A], r_chats($log), 'только в свою тему');
    $t = $log[0]['text'];
    assert_true(strpos($t, '🎉 <b>Начался ивент «Spooky Tokens»</b>') !== false, 'заголовок');
    assert_true(strpos($t, 'от 10 в первый день до 40 в последний') !== false, 'награды за вход');
    assert_true(strpos($t, 'час на сайте приносит ещё 15, два часа — ещё 20') !== false, 'награды за время');
    assert_true(strpos($t, '• Своё правило') !== false && strpos($t, '• Ещё &lt;правило&gt;') !== false, 'свои правила');
    assert_true(strpos($t, 'Hellfire Gas × 2') !== false, 'призы');
    assert_true(strpos($t, '• Venom') !== false, 'один приз без множителя');
    assert_true(strpos($t, '🎟 Билеты — до') !== false && strpos($t, '🏆 Итоги —') !== false, 'сроки');
    assert_true(r_accepts($t), 'Telegram примет');
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

function r_win(PDO $p, int $hunt, string $uid, int $ticket, string $prize, string $status = 'won'): void {
    $p->prepare("INSERT INTO hunt_wins (hunt_id, user_id, ticket_id, prize_idx, prize_name, prize_icon, status, deadline, created_at, updated_at) VALUES (?, ?, ?, 0, ?, '', ?, 0, 1, 1)")
        ->execute([$hunt, $uid, $ticket, $prize, $status]);
}

test('ивент: итоги с победителями, без @ перед логином', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    $id = r_hunt($p, 'drawn', R_NOW - 10 * 86400, R_NOW - 60);
    $p->exec("INSERT INTO users (roblox_id, username, display_name, created_at, last_login_at) VALUES ('7', 'vasya', 'Вася', 1, 1)");
    $p->exec("INSERT INTO users (roblox_id, username, display_name, created_at, last_login_at) VALUES ('8', 'petya', '', 1, 1)");
    r_win($p, $id, '7', 1, 'Hellfire Gas');
    r_win($p, $id, '8', 2, 'Venom');
    r_win($p, $id, '9', 3, 'Venom', 'expired');
    $log = [];
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    assert_true(strpos($log[0]['text'], 'Итоги ивента «Spooky Tokens»') !== false);
    assert_true(strpos($log[0]['text'], 'Hellfire Gas — Вася') !== false);
    assert_true(strpos($log[0]['text'], 'Venom — petya') !== false);
    assert_true(strpos($log[0]['text'], '@') === false, 'иначе Telegram сделает ссылку на чужой аккаунт');
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW + 60, r_tg($log), r_quiet()), 'один раз');
});

test('ивент: сотни победителей — все в посте, несколькими сообщениями', function () {
    $p = r_hunt_db();
    r_route($p, R_A, 'events');
    $id = r_hunt($p, 'drawn', R_NOW - 10 * 86400, R_NOW - 60);
    $add = $p->prepare("INSERT INTO users (roblox_id, username, display_name, created_at, last_login_at) VALUES (?, ?, '', 1, 1)");
    for ($i = 0; $i < 300; $i++) {
        $add->execute([1000 + $i, "player_number_$i"]);
        r_win($p, $id, (string)(1000 + $i), $i + 1, 'Очень длинное название приза');
    }
    $log = [];
    assert_eq(1, hunt_post_tick($p, R_CFG, R_NOW, r_tg($log), r_quiet()));
    assert_true(count($log) > 1);
    $all = implode("\n", array_column($log, 'text'));
    foreach ([0, 150, 299] as $i) { assert_true(strpos($all, "player_number_$i") !== false, "победитель $i"); }
    assert_eq(0, hunt_post_tick($p, R_CFG, R_NOW + 60, r_tg($log), r_quiet()), 'весь пост — один раз');
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
