<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/chat.php';

// Фильтр чата (api/lib/chat_filter.php): ссылки режутся все, кроме своего
// сайта, слова — по списку из панели. Текст в базе остаётся исходным.

const NOW = 1790000000;

function cut(string $text, array $words = []): string {
    return chat_filter_apply($text, chat_filter_compile($words))['text'];
}

function cf_db(): PDO {
    $pdo = test_db();
    $pdo->exec('CREATE TABLE chat_threads (id INTEGER PRIMARY KEY AUTOINCREMENT, a_id INTEGER NOT NULL,
        b_id INTEGER NOT NULL, last_at INTEGER NOT NULL DEFAULT 0, UNIQUE (a_id, b_id))');
    $pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL, body TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE chat_reviews (id INTEGER PRIMARY KEY AUTOINCREMENT, thread_id INTEGER NOT NULL,
        author_id INTEGER NOT NULL, target_id INTEGER NOT NULL, stars INTEGER NOT NULL, body TEXT, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE chat_clears (thread_id INTEGER NOT NULL, user_id INTEGER NOT NULL, cleared_id INTEGER NOT NULL DEFAULT 0,
        cleared_at INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (thread_id, user_id))');
    // Зеркалит docs/migrations/2026-11-02-chat-filter.sql.
    $pdo->exec('CREATE TABLE chat_filter_words (word TEXT NOT NULL PRIMARY KEY, created_at INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE chat_filter_hits (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id INTEGER NOT NULL,
        sender_id INTEGER NOT NULL, kinds TEXT NOT NULL, created_at INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                VALUES (5, 'alice', 'Alice', '', 0, 0, 0), (9, 'scammer', 'Scam', '', 0, 0, 0)");
    $pdo->exec('INSERT INTO chat_threads (a_id, b_id) VALUES (5, 9)');
    return $pdo;
}

// --- ссылки ------------------------------------------------------------------

test('ссылка на Roblox режется, как и любая другая: через неё ловят на фишинг', function () {
    foreach ([
        'https://www.roblox.com/share?code=abc',
        'roblox.com/games/2753915549',
        'www.roblox.com',
        'web.roblox.com',
        'roblox.com.evil.ru',
        'rblx.gg/abc',
        'roblox://placeId=1',
        'roblox-player:1+launchmode:play',
    ] as $link) {
        assert_eq('[link]', cut($link), $link);
    }
});

test('обычные адреса', function () {
    foreach ([
        'http://evil.ru', 'https://evil.ru/a?b=1#c', 'HTTPS://EVIL.RU', 'evil.ru', 'sub.evil.co.uk/path',
        'evil.xyz', 't.me/vasya', 'discord.gg/abc', 'bit.ly/x', 'clck.ru/3', 'vk.cc/a',
        'free-robux.top', 'привет.рф', 'сайт.рус', 'evil.com:8080/x', '1.2.3.4', 'http://1.2.3.4/x',
        'evil.com.hello', 'evil.ru-', '123.com',
    ] as $link) {
        assert_eq('[link]', cut($link), $link);
    }
});

test('ссылка посреди фразы: режется только она, препинание остаётся', function () {
    assert_eq('заходи на [link] брат', cut('заходи на evil.ru/free брат'));
    assert_eq('вот [link].', cut('вот https://evil.ru/x.'));
    assert_eq('([link])', cut('(https://evil.ru)'));
    assert_eq('a [link] b [link] c', cut('a evil.ru b t.me/x c'));
    assert_eq('user@[link]', cut('user@mail.ru'));
});

test('свой сайт разрешён, а похожие на него — нет', function () {
    foreach (['maknemy.com', 'https://maknemy.com', 'https://www.maknemy.com/trading',
              'maknemy.com/profile?id=123', 'MAKNEMY.COM/news'] as $ok) {
        assert_eq($ok, cut($ok), $ok);
    }
    assert_eq('смотри maknemy.com/trading, там', cut('смотри maknemy.com/trading, там'));
    assert_eq('[link]', cut('maknemy.com.evil.ru'));
    assert_eq('[link]', cut('evil.ru/maknemy.com'));
    assert_eq('[link]', cut('https://evil.ru/maknemy.com'));
    assert_eq('[link]', cut('mаknemy.com'), 'кириллическая а');
    assert_eq('[link]', cut('https://maknemy.com@evil.ru'));
    assert_eq('[link]', cut('maknemy.com/?go=evil.ru'), 'чужой адрес за своим');
    assert_eq('[link]', cut('maknemy.co'));
});

test('обходы', function () {
    foreach ([
        'evil . com', 'evil .com', 'evil. com', 'evil . ru', 'evil [.] com', 'evil(.)ru', 'evil{.}xyz',
        'evil [dot] com', 'evil dot gg', 'evil точка ру', 'evil (точка) com', 'evil,com', 'evil тчк ru',
        'evil.c o m', 'discord gg/abc', 't me/abc', 'hxxps://evil.ru', 'h t t p s : / / evil.ru',
        'https//evil.ru', 'h**ps://evil.ru',
        "ev\u{200B}il.com", "evil\u{200B}.com", "evil.c\u{00AD}om", "evil\u{200D}.com",
        'ｅｖｉｌ．ｃｏｍ', 'evil。com',
        'еvil.соm', 'rоblох.соm', 'т.ме/vasya', 'ехаре.сом', 'роблокс.ру', 'evil.ком',
    ] as $link) {
        $got = cut($link);
        assert_true(strpos($got, '[link]') !== false, "$link → $got");
        assert_true(strpos($got, 'com') === false && strpos($got, 'ru') === false, "$link → $got");
    }
});

test('обычный текст не трогает', function () {
    foreach ([
        'привет, как дела?', 'Gas.Dough', 'T-Rex за Dough', 'Buddha.Yeti', 'Control.Love',
        'т.е. обмен', 'и т.д.', 'т.к. дешевле', 'г.Москва', '1.5m', '5.5к', '2.5k', 'v1.2.3', 'x2',
        'Ну ладно.то есть нет', 'Да.Но', 'Привет.Как дела', 'ещё.то', '1.st', 'at 10.pm',
        'хорошо. Top фрукт', 'good game. gg', 'it is good, net profit', 'photo.png', 'Да, ru сервер',
        '👨‍👩‍👧 семья', '1️⃣ раз', 'http', 'tg', 'роблокс лучший', 'roblox: blox fruits',
    ] as $text) {
        assert_eq($text, cut($text), $text);
    }
});

test('стикер не фильтруется, даже если id в списке слов', function () {
    assert_eq('[sticker:gas]', cut('[sticker:gas]', ['gas', 'sticker']));
});

test('текст без находок остаётся прежним до байта', function () {
    $wide = 'ｈｅｌｌｏ' . "\u{200B}" . ' мир';
    assert_eq($wide, cut($wide));
    $r = chat_filter_apply($wide, chat_filter_compile([]));
    assert_eq(false, $r['link']);
    assert_eq(false, $r['word']);
});

test('длинная строка не роняет разбор', function () {
    $long = str_repeat('a.', 1000);
    assert_true(is_string(cut($long)));
    $dots = str_repeat('a . ', 500);
    assert_true(is_string(cut($dots)));
    assert_eq(str_repeat('сло ', 500), cut(str_repeat('сло ', 500), ['*кот*']));
});

// --- слова -------------------------------------------------------------------

test('слово целиком, начало, конец, любая часть', function () {
    assert_eq('ты ***', cut('ты сука', ['сука']));
    assert_eq('сукин сын', cut('сукин сын', ['сука']), 'без * только целиком');
    assert_eq('*** сын', cut('сукин сын', ['сук*']));
    assert_eq('ну ***', cut('ну пошёлнах', ['*нах']));
    assert_eq('вот ***', cut('вот нахрен', ['*нах*']));
    assert_eq('мазок', cut('мазок', ['маз']));
});

test('обходы слов: регистр, двойники, повторы, вразрядку, замены', function () {
    foreach (['СУКА', 'cyka', 'сyка', 'суууука', 'с у к а', 'с.у.к.а', 'с-у-к-а', 'cyк@', 'сук@'] as $bad) {
        assert_eq('***', cut($bad, ['сука']), $bad);
    }
    assert_eq('***', cut('хуй', ['хуи']), 'й и и — одна запись');
    assert_eq('***', cut('скam', ['скам']), 'смешанные алфавиты');
    assert_eq('***', cut('sc4m', ['sc4m']));
});

test('двойная буква в записи не задевает другое слово', function () {
    assert_eq('as is', cut('as is', ['ass']));
    assert_eq('***', cut('asss', ['ass']));
});

test('слово и ссылка вместе; метка ссылки не маскируется', function () {
    $r = chat_filter_apply('сука evil.ru', chat_filter_compile(['сука', 'link']));
    assert_eq('*** [link]', $r['text']);
    assert_eq(true, $r['link']);
    assert_eq(true, $r['word']);
});

// --- подключение к чату --------------------------------------------------------

test('в базе исходный текст, собеседник и автор видят вырезанный', function () {
    $pdo = cf_db();
    [$code, $sent] = chat_send($pdo, '9', 1, 'заходи evil.ru', NOW);
    assert_eq(200, $code);
    assert_eq('заходи [link]', $sent['message']['body'], 'автору в ответе');
    assert_eq('заходи evil.ru', $pdo->query('SELECT body FROM chat_messages')->fetchColumn(), 'в базе');
    assert_eq('заходи [link]', chat_messages($pdo, '5', 1)[0]['body'], 'в переписке');
    assert_eq('заходи [link]', chat_messages($pdo, '9', 1)[0]['body'], 'у автора в переписке');
    assert_eq('заходи [link]', chat_threads($pdo, '5', NOW)[0]['last']['body'], 'в списке диалогов');
});

test('новое слово прячет и старые сообщения', function () {
    $pdo = cf_db();
    chat_send($pdo, '9', 1, 'ты лох', NOW);
    assert_eq('ты лох', chat_messages($pdo, '5', 1)[0]['body']);
    assert_eq('added', chat_filter_add($pdo, 'лох', NOW));
    assert_eq('ты ***', chat_messages($pdo, '5', 1)[0]['body']);
    assert_eq('removed', chat_filter_remove($pdo, 'лох'));
    assert_eq('ты лох', chat_messages($pdo, '5', 1)[0]['body']);
});

test('срабатывание попадает в журнал, чистое сообщение нет', function () {
    $pdo = cf_db();
    chat_filter_add($pdo, 'лох', NOW);
    chat_send($pdo, '9', 1, 'привет', NOW);
    assert_eq([], chat_filter_hits($pdo));
    chat_send($pdo, '9', 1, 'лох evil.ru', NOW + 1);
    $hits = chat_filter_hits($pdo);
    assert_eq(1, count($hits));
    assert_eq('9', $hits[0]['id']);
    assert_eq('Scam', $hits[0]['nick']);
    assert_eq(['link', 'word'], $hits[0]['kinds']);
    assert_eq('лох evil.ru', $hits[0]['body'], 'модератор видит исходный текст');
});

test('старый журнал вычищается', function () {
    $pdo = cf_db();
    chat_send($pdo, '9', 1, 'evil.ru', NOW);
    chat_send($pdo, '9', 1, 'evil.ru', NOW + 91 * 86400);
    assert_eq(1, count(chat_filter_hits($pdo)));
});

test('без таблиц фильтра чат работает, ссылки режутся', function () {
    $pdo = cf_db();
    $pdo->exec('DROP TABLE chat_filter_words');
    $pdo->exec('DROP TABLE chat_filter_hits');
    [$code, $sent] = chat_send($pdo, '9', 1, 'evil.ru', NOW);
    assert_eq(200, $code);
    assert_eq('[link]', $sent['message']['body']);
    assert_eq('not_ready', chat_filter_add($pdo, 'лох', NOW));
    assert_eq(false, chat_filter_ready($pdo));
});

test('страница заменяет ту же метку, что ставит сервер', function () {
    $js = file_get_contents(__DIR__ . '/../public_html/js/chat-page.js');
    assert_true(strpos($js, "var LINK_CUT = '" . CHAT_LINK_CUT . "';") !== false, 'LINK_CUT');
    assert_true(strpos($js, 'body.textContent = showBody(m.body);') !== false, 'в переписке');
    assert_true(strpos($js, 'return showBody(body);') !== false, 'в списке диалогов');
    foreach (['ru', 'en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk'] as $lang) {
        $dict = json_decode(file_get_contents(__DIR__ . '/../public_html/lang/' . $lang . '.json'), true);
        assert_true(($dict['chat.linkRemoved'] ?? '') !== '', "подпись на $lang");
    }
});

// --- панель --------------------------------------------------------------------

test('добавление: через запятую и с новой строки, повторы и регистр', function () {
    $pdo = cf_db();
    assert_eq('added', chat_filter_add($pdo, "Лох, скам*\n*мат*", NOW));
    assert_eq(['*мат*', 'лох', 'скам*'], chat_filter_word_list($pdo));
    assert_eq('added', chat_filter_add($pdo, 'лох', NOW), 'повтор не ошибка');
    assert_eq(3, count(chat_filter_word_list($pdo)));
});

test('кривая запись не добавляет ничего', function () {
    $pdo = cf_db();
    foreach (['', '  ', 'a', 'free robux', 'ск*ам', '**', '<b>'] as $bad) {
        assert_eq('bad_word', chat_filter_add($pdo, $bad, NOW), "«{$bad}»");
    }
    assert_eq('bad_word', chat_filter_add($pdo, 'хорошо, free robux', NOW));
    assert_eq([], chat_filter_word_list($pdo));
});

test('предел длины списка', function () {
    $pdo = cf_db();
    $many = [];
    for ($i = 0; $i < CHAT_FILTER_WORDS_MAX; $i++) { $many[] = 'w' . $i; }
    assert_eq('added', chat_filter_add($pdo, implode(',', $many), NOW));
    assert_eq('too_many', chat_filter_add($pdo, 'лишнее', NOW));
    assert_eq('added', chat_filter_add($pdo, 'w1', NOW), 'повтор места не занимает');
});

run_tests();
