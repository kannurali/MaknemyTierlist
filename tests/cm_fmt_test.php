<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Разметка ответов чат-менеджера (send.php: cm_fmt и метки): ники-ссылки,
// жирный, ссылки, цитата; повышение сразу до ранга (ranks.php).

test('метки → entities со смещениями в UTF-16, вложенность', function () {
    $t = '📣 ' . cm_bold('Глава ' . cm_who(7, 'Вася')) . ' и ' . cm_link('https://t.me/petya', 'Петя');
    [$text, $e] = cm_fmt($t);
    assert_eq('📣 Глава Вася и Петя', $text);
    assert_eq([
        ['type' => 'bold', 'offset' => 3, 'length' => 10],
        ['type' => 'text_mention', 'offset' => 9, 'length' => 4, 'user' => ['id' => 7, 'is_bot' => false, 'first_name' => 'Вася']],
        ['type' => 'text_link', 'offset' => 16, 'length' => 4, 'url' => 'https://t.me/petya'],
    ], $e);
    assert_eq(['x', []], cm_fmt('x'), 'без меток — как есть');
    assert_eq('', cm_who(7, '') . cm_bold(''), 'пустое — без метки');
    assert_eq('Вася', cm_who(0, 'Вася'), 'без id — просто имя');
});

test('чужой текст метку не подделает', function () {
    $fake = "\u{E000}00000000m666\u{E001}Жми\u{E002}00000000\u{E003}";
    [$text, $e] = cm_fmt(cm_t('ban_done', ['name' => cm_who(20, $fake), 'term' => 'навсегда', 'reason' => '', 'net' => '']));
    assert_eq('⛔ ' . $fake . ': бан навсегда.', $text);
    assert_eq(1, count($e));
    assert_eq(20, $e[0]['user']['id'], 'упоминание только настоящее');
});

test('готовая разметка (правила) сдвигается на вырезанные метки', function () {
    $head = cm_who(7, 'Вася') . ":\n";
    $ents = [['type' => 'bold', 'offset' => cm_utf16_len($head), 'length' => 5]];
    [$text, $e] = cm_fmt($head . 'Важно: без спама', $ents);
    assert_eq("Вася:\nВажно: без спама", $text);
    assert_eq(['type' => 'bold', 'offset' => 6, 'length' => 5], $e[1]);
});

test('длинный текст режется до 4096, разметка — по нему', function () {
    $p = cm_text_params(str_repeat('а', 4094) . cm_who(7, 'Вася') . ' ' . cm_bold('конец'));
    assert_eq(4096, mb_strlen($p['text']));
    assert_eq([['type' => 'text_mention', 'offset' => 4094, 'length' => 2,
        'user' => ['id' => 7, 'is_bot' => false, 'first_name' => 'Вася']]], $p['entities']);
});

test('ник: упоминание для событий, тихая ссылка t.me для списков', function () {
    $pdo = cm_test_pdo();
    cm_person($pdo, 20, 'vasya', 'Вася');
    cm_user_touch($pdo, ['id' => 21, 'first_name' => 'Петя'], CM_NOW);
    assert_eq(['Вася', [['type' => 'text_mention', 'offset' => 0, 'length' => 4,
        'user' => ['id' => 20, 'is_bot' => false, 'first_name' => 'Вася']]]], cm_fmt(cm_user_who($pdo, 20)));
    assert_eq(['Вася', [['type' => 'text_link', 'offset' => 0, 'length' => 4, 'url' => 'https://t.me/vasya']]],
        cm_fmt(cm_user_who($pdo, 20, true)));
    assert_eq('Петя', cm_user_who($pdo, 21, true), 'без ника — просто имя');
    assert_eq('Вася', cm_plain(cm_user_who($pdo, 20)));
});

test('Telegram не принял упоминание — то же сообщение без него', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], ['sendMessage' => function ($p) {
        return !empty($p['entities']) && $p['entities'][0]['type'] === 'text_mention'
            ? ['ok' => false, 'description' => 'Bad Request: invalid user_id specified']
            : ['ok' => true, 'result' => ['message_id' => 77]];
    }]);
    assert_eq(77, cm_say($ctx, CM_CHAT, cm_who(20, 'Вася') . ' ' . cm_bold('тут')));
    $sent = cm_calls($log, 'sendMessage');
    assert_eq(2, count($sent));
    assert_eq([['type' => 'bold', 'offset' => 5, 'length' => 3]], $sent[1][1]['entities'], 'жирный остался');
});

test('повысить 3 — сразу ранг 3; понизить 1 — сразу 1; в обратную сторону — «уже»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'повысить @vasya 3');
    assert_eq(3, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq('🎖 Вася теперь младший администратор (3).', cm_last_text($log));
    cm_do($ctx, 'повысить 1 @vasya');
    assert_eq(3, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq('Вася уже младший администратор.', cm_last_text($log));
    cm_do($ctx, 'понизить @vasya 1');
    assert_eq(1, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'повысить @vasya 5');
    assert_eq(cm_t('rank_need_level'), cm_last_text($log));
    $r = cm_ctx_for($pdo, $log, CM_OWNER, '', ['reply_to_message' => ['message_id' => 9, 'from' => ['id' => 20, 'first_name' => 'Вася']]]);
    cm_do($r, 'Повысить 3');
    assert_eq(3, cm_member($pdo, CM_CHAT, 20)['lvl'], 'ответом, как в чате');
});

test('ответ на кнопку — без меток', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq('Петя отказывается.', cm_press($pdo, $log, 20, 'cm:p:1:n'));
    $e = cm_calls($log, 'editMessageText')[0][1];
    assert_eq('text_mention', $e['entities'][0]['type'], 'в чате ник — ссылка');
});

run_tests();
