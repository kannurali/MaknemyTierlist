<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/telegram.php';
require __DIR__ . '/../public_html/api/chat.php';
require __DIR__ . '/../public_html/api/tg_link.php';
require __DIR__ . '/../public_html/api/tg_webhook.php';

// Уведомления в Telegram — api/lib/telegram.php, api/tg_link.php,
// api/tg_webhook.php и отметка прочитанного в api/chat.php.
//
// В сеть тесты не ходят: отправка идёт через подставной $send, который
// записывает, что и кому ушло.
//
// Главные обещания, которые здесь держатся:
//  - в уведомлении только КТО написал, текста сообщения там нет;
//  - на пачку непрочитанного — одно уведомление, и ни одного, пока человек
//    сидит в этом диалоге;
//  - привязать чужой аккаунт нельзя: код одноразовый, хранится хешем, а
//    chat_id приходит только от Telegram с верным секретом;
//  - без таблиц и без токена чат работает как раньше.

const TG_NOW   = 1790000000;
const TG_TOKEN = '123456789:AAHfakeTokenForTestsOnly_abcdefghij';

function tg_db(bool $withTg = true): PDO {
    $pdo = test_db();
    $pdo->exec('CREATE TABLE chat_threads (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        a_id    INTEGER NOT NULL,
        b_id    INTEGER NOT NULL,
        last_at INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE chat_messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id  INTEGER NOT NULL,
        sender_id  INTEGER NOT NULL,
        body       TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE chat_reviews (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id  INTEGER NOT NULL,
        author_id  INTEGER NOT NULL,
        target_id  INTEGER NOT NULL,
        stars      INTEGER NOT NULL,
        body       TEXT,
        created_at INTEGER NOT NULL,
        UNIQUE (thread_id, author_id)
    )');
    if ($withTg) {
        // Зеркалит docs/migrations/2026-09-23-telegram.sql.
        $pdo->exec("CREATE TABLE tg_links (
            user_id   INTEGER NOT NULL PRIMARY KEY,
            chat_id   INTEGER NOT NULL,
            tg_name   TEXT NOT NULL DEFAULT '',
            lang      TEXT NOT NULL DEFAULT 'ru',
            linked_at INTEGER NOT NULL
        )");
        $pdo->exec("CREATE TABLE tg_codes (
            code_hash  TEXT NOT NULL PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            lang       TEXT NOT NULL DEFAULT 'ru',
            expires_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE TABLE chat_reads (
            thread_id    INTEGER NOT NULL,
            user_id      INTEGER NOT NULL,
            last_read_id INTEGER NOT NULL DEFAULT 0,
            seen_at      INTEGER NOT NULL DEFAULT 0,
            notified_id  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (thread_id, user_id)
        )');
    }
    return $pdo;
}

function tg_user(PDO $p, string $id, string $username, string $display = ''): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, $username, $display, '', TG_NOW - 86400, TG_NOW - 3600, TG_NOW - 3600]);
    return $id;
}

function tg_thread(PDO $p, string $x, string $y): int {
    [$a, $b] = chat_pair($x, $y);
    $p->prepare('INSERT INTO chat_threads (a_id, b_id, last_at) VALUES (?, ?, 0)')->execute([$a, $b]);
    return (int)$p->lastInsertId();
}

function tg_link_row(PDO $p, string $user, int $chatId, string $lang = 'ru'): void {
    $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, lang, linked_at) VALUES (?, ?, ?, ?, ?)')
      ->execute([$user, $chatId, '@someone', $lang, TG_NOW]);
}

// Сообщение так, как его пишет настоящий chat_send. Возвращает id.
function tg_say(PDO $p, string $from, int $thread, string $body, int $at): int {
    [$code, $out] = chat_send($p, $from, $thread, $body, $at);
    assert_eq(200, $code, 'сообщение отправилось');
    return (int)$out['message']['id'];
}

function tg_cfg(array $extra = []): array {
    return tg_config(array_merge(['tg_bot_token' => TG_TOKEN, 'tg_bot_name' => 'MaknemyBot'], $extra));
}

// Подставной транспорт: копит вызовы, отвечает тем, что задано.
function tg_recorder(array &$log, ?array $answer = null): callable {
    return function (string $method, array $params) use (&$log, $answer): ?array {
        $log[] = [$method, $params];
        return $answer ?? ['ok' => true, 'result' => []];
    };
}

// Код из ссылки t.me/<бот>?start=<код>.
function tg_code_from(string $url): string {
    $q = parse_url($url, PHP_URL_QUERY);
    parse_str((string)$q, $args);
    return (string)($args['start'] ?? '');
}

function tg_start_update(int $chatId, string $text, array $from = []): array {
    return ['update_id' => 1, 'message' => [
        'message_id' => 1,
        'chat' => ['id' => $chatId, 'type' => 'private'],
        'from' => $from + ['id' => $chatId, 'first_name' => 'Ann', 'language_code' => 'ru'],
        'text' => $text,
    ]];
}

// --------------------------------------------------------------------------
//  Настройки
// --------------------------------------------------------------------------

test('без токена или имени бота уведомления выключены', function () {
    assert_eq(false, tg_enabled(tg_config([])), 'пустой конфиг');
    assert_eq(false, tg_enabled(tg_config(['tg_bot_token' => TG_TOKEN])), 'нет имени');
    assert_eq(false, tg_enabled(tg_config(['tg_bot_name' => 'MaknemyBot'])), 'нет токена');
    assert_eq(false, tg_enabled(tg_config(['tg_bot_token' => 'мусор', 'tg_bot_name' => 'MaknemyBot'])), 'кривой токен');
    assert_eq(false, tg_enabled(tg_config(['tg_bot_token' => TG_TOKEN, 'tg_bot_name' => 'x y'])), 'кривое имя');
    assert_eq(true, tg_enabled(tg_cfg()), 'всё на месте');
    assert_eq('MaknemyBot', tg_config(['tg_bot_token' => TG_TOKEN, 'tg_bot_name' => '@MaknemyBot'])['name'], '@ в начале срезается');
});

test('модераторы — только id из цифр, без повторов', function () {
    $tg = tg_cfg(['moderator_ids' => ['101', 102, ' 103 ', '101', 'abc', '', '0', '007', ['x'], true]]);
    assert_eq(['101', '102', '103', '7'], $tg['moderators']);
    assert_eq([], tg_cfg(['moderator_ids' => 'не список'])['moderators'], 'не массив — пусто');
});

test('секрет вебхука выводится из токена и от него зависит', function () {
    $a = tg_webhook_secret(TG_TOKEN);
    assert_eq($a, tg_webhook_secret(TG_TOKEN), 'повторяется');
    assert_true($a !== tg_webhook_secret(TG_TOKEN . 'x'), 'другой токен — другой секрет');
    assert_eq(1, preg_match('/^[a-f0-9]{64}$/', $a), 'подходит под правила Telegram');
});

test('вебхук принимает только запрос с верным секретом', function () {
    $tg = tg_cfg();
    $ok = ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => tg_webhook_secret(TG_TOKEN)];
    assert_eq(true, tg_webhook_authorized($tg, $ok), 'верный');
    assert_eq(false, tg_webhook_authorized($tg, []), 'без заголовка');
    assert_eq(false, tg_webhook_authorized($tg, ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'nope']), 'чужой');
    assert_eq(false, tg_webhook_authorized($tg, ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => ['x']]), 'не строка');
    assert_eq(false, tg_webhook_authorized(tg_config([]), $ok), 'бот выключен');
});

// --------------------------------------------------------------------------
//  Привязка
// --------------------------------------------------------------------------

test('ссылку получает только вошедший, и только когда всё включено', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    assert_eq(401, tg_link_start($p, tg_cfg(), '', 'ru', TG_NOW)[0], 'аноним');
    assert_eq(401, tg_link_start($p, tg_cfg(), '999', 'ru', TG_NOW)[0], 'нет в users');
    assert_eq(503, tg_link_start($p, tg_config([]), '101', 'ru', TG_NOW)[0], 'бот выключен');
    assert_eq(503, tg_link_start(tg_db(false), tg_cfg(), '101', 'ru', TG_NOW)[0], 'таблиц нет');

    [$code, $out] = tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW);
    assert_eq(200, $code);
    assert_eq(1, preg_match('~^https://t\.me/MaknemyBot\?start=[A-Za-z0-9_-]{32}$~', $out['url']), 'ссылка на бота: ' . $out['url']);
});

test('код хранится хешем, и живой код у человека один', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    $first  = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
    $second = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
    assert_true($first !== $second, 'коды разные');

    $rows = $p->query('SELECT code_hash FROM tg_codes')->fetchAll(PDO::FETCH_COLUMN);
    assert_eq([hash('sha256', $second)], $rows, 'остался только последний, и не открытым текстом');
});

test('/start с кодом привязывает аккаунт и отвечает в тот же чат', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    $code = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'en', TG_NOW)[1]['url']);

    $reply = tg_handle_update($p, tg_start_update(555, '/start ' . $code, ['username' => 'ann_tg']), TG_NOW + 60);
    assert_eq('sendMessage', $reply['method']);
    assert_eq(555, $reply['chat_id'], 'ответ туда, откуда написали');
    assert_eq(tg_text('linked', 'en'), $reply['text'], 'язык — тот, что выбран на сайте');

    $row = $p->query('SELECT user_id, chat_id, tg_name, lang FROM tg_links')->fetch(PDO::FETCH_ASSOC);
    assert_eq(['101', '555', '@ann_tg', 'en'], array_map('strval', array_values($row)));
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_codes')->fetchColumn(), 'код погашен');
});

test('код одноразовый и с ограниченным сроком', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    $code = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
    tg_handle_update($p, tg_start_update(555, '/start ' . $code), TG_NOW);

    $again = tg_handle_update($p, tg_start_update(666, '/start ' . $code), TG_NOW);
    assert_eq(tg_text('expired', 'ru'), $again['text'], 'второй раз — «устарела»');
    assert_eq(555, (int)$p->query('SELECT chat_id FROM tg_links WHERE user_id = 101')->fetchColumn(), 'чужой чат не перехватил');

    $late = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
    $r = tg_handle_update($p, tg_start_update(777, '/start ' . $late), TG_NOW + TG_CODE_TTL + 1);
    assert_eq(tg_text('expired', 'ru'), $r['text'], 'просроченный');
    assert_eq(555, (int)$p->query('SELECT chat_id FROM tg_links WHERE user_id = 101')->fetchColumn(), 'привязка прежняя');
});

test('повторная привязка переезжает в новый чат, а не множится', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    foreach ([555, 556] as $chat) {
        $code = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
        tg_handle_update($p, tg_start_update($chat, '/start ' . $code), TG_NOW);
    }
    assert_eq(['101:556'],
        $p->query("SELECT user_id || ':' || chat_id FROM tg_links")->fetchAll(PDO::FETCH_COLUMN));
});

test('группы, каналы и не-сообщения бот не привязывает', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    $code = tg_code_from(tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW)[1]['url']);
    $group = tg_start_update(-100200, '/start ' . $code);
    $group['message']['chat']['type'] = 'group';
    assert_eq(null, tg_handle_update($p, $group, TG_NOW), 'группа');
    assert_eq(null, tg_handle_update($p, ['update_id' => 2, 'edited_message' => []], TG_NOW), 'не сообщение');
    assert_eq(null, tg_handle_update($p, [], TG_NOW), 'пусто');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn(), 'ничего не привязано');
});

test('/stop отвязывает, а любой другой текст получает подсказку', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_link_row($p, '101', 555);

    $hello = tg_handle_update($p, tg_start_update(555, 'привет', ['language_code' => 'en']), TG_NOW);
    assert_eq(tg_text('hello', 'en'), $hello['text'], 'подсказка на языке Telegram');
    assert_eq('https://maknemy.com/chat', $hello['reply_markup']['inline_keyboard'][0][0]['url'], 'кнопка на чат');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn(), 'привязка на месте');

    $stop = tg_handle_update($p, tg_start_update(555, '/stop'), TG_NOW);
    assert_eq(tg_text('stopped', 'ru'), $stop['text']);
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn(), 'отвязан');
});

test('отключение с сайта снимает привязку и живые коды', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_link_row($p, '101', 555);
    tg_link_start($p, tg_cfg(), '101', 'ru', TG_NOW);
    assert_eq(401, tg_unlink($p, '')[0], 'аноним');
    assert_eq(200, tg_unlink($p, '101')[0]);
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn());
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_codes')->fetchColumn());
});

test('эндпоинт колокольчика различает действия', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    $cfg = ['tg_bot_token' => TG_TOKEN, 'tg_bot_name' => 'MaknemyBot'];
    assert_eq(200, handle_tg_link($p, $cfg, '101', ['action' => 'link', 'lang' => 'en'], TG_NOW)[0], 'link');
    assert_eq('en', $p->query('SELECT lang FROM tg_codes')->fetchColumn(), 'язык запомнен');
    assert_eq(200, handle_tg_link($p, $cfg, '101', ['action' => 'unlink'], TG_NOW)[0], 'unlink');
    assert_eq(400, handle_tg_link($p, $cfg, '101', ['action' => 'drop'], TG_NOW)[0], 'неизвестное');
    assert_eq(400, handle_tg_link($p, $cfg, '101', ['action' => ['link']], TG_NOW)[0], 'не строка');
    assert_eq(401, handle_tg_link($p, $cfg, '', ['action' => 'link'], TG_NOW)[0], 'аноним');
});

// --------------------------------------------------------------------------
//  Уведомления о сообщениях
// --------------------------------------------------------------------------

test('в уведомлении только кто написал, без текста сообщения', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann', 'Ann');
    tg_user($p, '202', 'bob', 'Bobby');
    tg_link_row($p, '101', 555);
    $t = tg_thread($p, '101', '202');
    $m = tg_say($p, '202', $t, 'секретная сделка: Kitsune за Dragon', TG_NOW);

    $log = [];
    assert_eq(true, tg_notify_chat($p, tg_cfg(), tg_recorder($log), '202', $t, $m, TG_NOW));
    assert_eq(1, count($log), 'ушло одно');
    [$method, $params] = $log[0];
    assert_eq('sendMessage', $method);
    assert_eq(555, $params['chat_id'], 'в чат получателя');
    assert_eq('💬 Новое сообщение от Bobby (@bob)', $params['text']);
    assert_eq(false, strpos(json_encode($params, JSON_UNESCAPED_UNICODE), 'Kitsune'), 'текста сообщения нет нигде');
    assert_eq('https://maknemy.com/chat?to=202', $params['reply_markup']['inline_keyboard'][0][0]['url'], 'кнопка ведёт в диалог');
    assert_eq(false, isset($params['parse_mode']), 'простой текст — ник не надо экранировать');
});

test('логин не повторяется, если совпадает с ником', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'Bob', 'bob');
    tg_user($p, '303', 'carl', '');
    assert_eq('bob', tg_user_label($p, '202'));
    assert_eq('carl', tg_user_label($p, '303'), 'без ника — логин');
});

test('отправителю о своих сообщениях не пишем, без привязки не пишем', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob');
    tg_link_row($p, '202', 777);
    $t = tg_thread($p, '101', '202');
    $m = tg_say($p, '202', $t, 'привет', TG_NOW);

    $log = [];
    assert_eq(false, tg_notify_chat($p, tg_cfg(), tg_recorder($log), '202', $t, $m, TG_NOW), 'у 101 нет Telegram');
    assert_eq([], $log, 'ничего не ушло — и уж точно не самому отправителю');
});

test('на пачку непрочитанного — одно уведомление, после прочтения — снова', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob');
    tg_link_row($p, '101', 555);
    $t   = tg_thread($p, '101', '202');
    $log = [];
    $send = tg_recorder($log);

    $m1 = tg_say($p, '202', $t, 'раз', TG_NOW);
    $m2 = tg_say($p, '202', $t, 'два', TG_NOW + 5);
    assert_eq(true,  tg_notify_chat($p, tg_cfg(), $send, '202', $t, $m1, TG_NOW), 'первое зовёт');
    assert_eq(false, tg_notify_chat($p, tg_cfg(), $send, '202', $t, $m2, TG_NOW + 5), 'второе молчит');
    assert_eq(1, count($log));

    // 101 открыл чат и ушёл.
    handle_chat($p, ['user_id' => '101'], (string)$t, TG_NOW + 100, tg_cfg());

    $m3 = tg_say($p, '202', $t, 'три', TG_NOW + 1000);
    assert_eq(true, tg_notify_chat($p, tg_cfg(), $send, '202', $t, $m3, TG_NOW + 1000), 'после прочтения зовёт снова');
    assert_eq(2, count($log));
});

test('кто сидит в диалоге, тому бот не пишет', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob');
    tg_link_row($p, '101', 555);
    $t = tg_thread($p, '101', '202');
    $log = [];

    // Вкладка 101 перечитывает чат: отметка десять секунд назад.
    handle_chat($p, ['user_id' => '101'], (string)$t, TG_NOW - 10, tg_cfg());
    $m = tg_say($p, '202', $t, 'ты тут?', TG_NOW);
    assert_eq(false, tg_notify_chat($p, tg_cfg(), tg_recorder($log), '202', $t, $m, TG_NOW), 'в диалоге');
    assert_eq([], $log);

    // А если он в соседнем диалоге — это не тот диалог.
    tg_user($p, '303', 'carl');
    $other = tg_thread($p, '101', '303');
    $m2 = tg_say($p, '303', $other, 'эй', TG_NOW);
    assert_eq(true, tg_notify_chat($p, tg_cfg(), tg_recorder($log), '303', $other, $m2, TG_NOW), 'соседняя ветка зовёт');
});

test('открытая страница без выбранной ветки ничего не отмечает', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    handle_chat($p, ['user_id' => '101'], null, TG_NOW, tg_cfg());
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM chat_reads')->fetchColumn());
});

test('прочитанное назад не откатывается', function () {
    $p = tg_db();
    chat_mark_read($p, 1, '101', 50, TG_NOW);
    chat_mark_read($p, 1, '101', 30, TG_NOW + 10);
    chat_mark_read($p, 1, '101', 30, TG_NOW + 10);
    $r = $p->query('SELECT last_read_id, seen_at FROM chat_reads')->fetch(PDO::FETCH_ASSOC);
    assert_eq([50, TG_NOW + 10], array_map('intval', array_values($r)));
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM chat_reads')->fetchColumn(), 'строка одна');
});

test('заблокировал бота — привязка снимается сама', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob');
    tg_link_row($p, '101', 555);
    $t = tg_thread($p, '101', '202');
    $m = tg_say($p, '202', $t, 'привет', TG_NOW);

    $log = [];
    $blocked = tg_recorder($log, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']);
    assert_eq(false, tg_notify_chat($p, tg_cfg(), $blocked, '202', $t, $m, TG_NOW));
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn(), 'отвязан');

    // Сеть упала — это не повод отвязывать.
    tg_link_row($p, '101', 555);
    $down = function (string $method, array $params): ?array { return null; };
    tg_notify_chat($p, tg_cfg(), $down, '202', $t, tg_say($p, '202', $t, 'ещё', TG_NOW), TG_NOW);
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM tg_links')->fetchColumn(), 'привязка на месте');
});

test('уведомление на языке, выбранном при подключении', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob', 'Bob');
    tg_link_row($p, '101', 555, 'en');
    $t = tg_thread($p, '101', '202');
    $log = [];
    tg_notify_chat($p, tg_cfg(), tg_recorder($log), '202', $t, tg_say($p, '202', $t, 'hi', TG_NOW), TG_NOW);
    assert_eq('💬 New message from Bob', $log[0][1]['text']);
    assert_eq('Open chat', $log[0][1]['reply_markup']['inline_keyboard'][0][0]['text']);
});

// --------------------------------------------------------------------------
//  Модераторы
// --------------------------------------------------------------------------

test('о новом обращении узнают подключившиеся модераторы, и только они', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann_trader', 'Ann');
    tg_user($p, '901', 'mod1');
    tg_user($p, '902', 'mod2');
    tg_user($p, '903', 'user');
    tg_link_row($p, '901', 9001);
    tg_link_row($p, '903', 9003);

    $log = [];
    $sent = tg_notify_support($p, tg_cfg(['moderator_ids' => ['901', '902']]), tg_recorder($log), '101');
    assert_eq(1, $sent, 'mod2 без Telegram, user не модератор');
    assert_eq(9001, $log[0][1]['chat_id']);
    assert_eq('🆘 Новое обращение в поддержку от Ann (@ann_trader)', $log[0][1]['text']);
    assert_eq('https://maknemy.com/admin/support', $log[0][1]['reply_markup']['inline_keyboard'][0][0]['url']);
});

test('модератору о его собственном обращении не пишем', function () {
    $p = tg_db();
    tg_user($p, '901', 'mod1');
    tg_link_row($p, '901', 9001);
    $log = [];
    assert_eq(0, tg_notify_support($p, tg_cfg(['moderator_ids' => ['901']]), tg_recorder($log), '901'));
    assert_eq([], $log);
});

test('без модераторов в конфиге обращения никуда не уходят', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_link_row($p, '101', 555);
    $log = [];
    assert_eq(0, tg_notify_support($p, tg_cfg(), tg_recorder($log), '202'));
    assert_eq([], $log);
});

// --------------------------------------------------------------------------
//  Колокольчик и отсутствие таблиц
// --------------------------------------------------------------------------

test('чат сообщает колокольчику, подключён ли человек', function () {
    $p = tg_db();
    tg_user($p, '101', 'ann');
    tg_user($p, '901', 'mod1');
    $cfg = tg_cfg(['moderator_ids' => ['901']]);

    [, $anon] = handle_chat($p, [], null, TG_NOW, $cfg);
    assert_eq(false, $anon['tg']['on'], 'аноним колокольчика не видит');

    [, $off] = handle_chat($p, ['user_id' => '101'], null, TG_NOW, tg_config([]));
    assert_eq(false, $off['tg']['on'], 'бот не настроен');

    [, $out] = handle_chat($p, ['user_id' => '101'], null, TG_NOW, $cfg);
    assert_eq(['on' => true, 'linked' => false, 'name' => '', 'mod' => false], $out['tg']);

    tg_link_row($p, '901', 9001);
    [, $mod] = handle_chat($p, ['user_id' => '901'], null, TG_NOW, $cfg);
    assert_eq(['on' => true, 'linked' => true, 'name' => '@someone', 'mod' => true], $mod['tg']);
});

test('без таблиц Telegram чат работает как раньше', function () {
    $p = tg_db(false);
    tg_user($p, '101', 'ann');
    tg_user($p, '202', 'bob');
    $t = tg_thread($p, '101', '202');
    $m = tg_say($p, '202', $t, 'привет', TG_NOW);

    [$code, $out] = handle_chat($p, ['user_id' => '101'], (string)$t, TG_NOW, tg_cfg());
    assert_eq(200, $code);
    assert_eq(1, count($out['messages']), 'переписка на месте');
    assert_eq(false, $out['tg']['on'], 'колокольчик скрыт');

    $log = [];
    assert_eq(false, tg_notify_chat($p, tg_cfg(), tg_recorder($log), '202', $t, $m, TG_NOW));
    assert_eq(0, tg_notify_support($p, tg_cfg(['moderator_ids' => ['101']]), tg_recorder($log), '202'));
    assert_eq([], $log);
});

test('установка вебхука отдаёт адрес сайта и секрет', function () {
    $log = [];
    assert_eq(true, tg_setup_webhook(tg_recorder($log), TG_TOKEN));
    assert_eq('setWebhook', $log[0][0]);
    assert_eq('https://maknemy.com/api/tg_webhook.php', $log[0][1]['url']);
    assert_eq(tg_webhook_secret(TG_TOKEN), $log[0][1]['secret_token']);
    assert_eq(['message'], $log[0][1]['allowed_updates']);

    $fail = [];
    assert_eq(false, tg_setup_webhook(tg_recorder($fail, ['ok' => false, 'error_code' => 401]), TG_TOKEN), 'чужой токен');
});

run_tests();
