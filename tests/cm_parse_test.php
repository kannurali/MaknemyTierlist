<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Разбор команд чат-менеджера: api/lib/cm/parse.php.

test('префикс необязателен, регистр и ё не важны', function () {
    foreach (['мут', '!мут', '.Мут', '/МУТ', '  !мут  '] as $t) {
        assert_eq('mute', cm_parse($t, CM_BOT)['handler'] ?? null, $t);
    }
    assert_eq(false, cm_parse('мут', CM_BOT)['prefixed']);
    assert_eq(true, cm_parse('!мут', CM_BOT)['prefixed']);
    assert_eq('kick_deleted', cm_parse('кик удалённых', CM_BOT)['handler']);
});

test('слово команды целиком: муты — не мут, мутный — не команда', function () {
    assert_eq('mutes', cm_parse('муты', CM_BOT)['handler']);
    assert_eq(null, cm_parse('мутный тип', CM_BOT));
    assert_eq('warn_limit', cm_parse('варны лимит 5', CM_BOT)['handler']);
    assert_eq('5', cm_parse('варны лимит 5', CM_BOT)['args']);
    assert_eq('trigger_set', cm_parse('триггер мат: удалить', CM_BOT)['handler']);
    assert_eq('мат: удалить', cm_parse('триггер мат: удалить', CM_BOT)['args']);
    assert_eq(null, cm_parse('', CM_BOT));
    assert_eq(null, cm_parse("\nмут", CM_BOT));
    assert_eq(null, cm_parse('/start abc', CM_BOT));
});

test('/команда@бот — только наш бот, @ник в аргументах не трогаем', function () {
    assert_eq('ban', cm_parse('/бан@MaknemyBot 1д', CM_BOT)['handler']);
    assert_eq('1д', cm_parse('/бан@maknemybot 1д', CM_BOT)['args']);
    assert_eq(null, cm_parse('/бан@OtherBot', CM_BOT));
    assert_eq('@vasya 1ч', cm_parse('мут @vasya 1ч', CM_BOT)['args']);
});

test('у команды есть флаги для роутера', function () {
    $c = cm_parse('правила', CM_BOT);
    assert_eq([null, 0, true, false], [$c['section'], $c['level'], $c['exact'], $c['target']]);
    $c = cm_parse('бан', CM_BOT);
    assert_eq(['бан', 3, false, true], [$c['section'], $c['level'], $c['exact'], $c['target']]);
});

test('тело команды — со второй строки как есть', function () {
    $c = cm_parse("+правила\n1. Не спамить\n2. Не ругаться", CM_BOT);
    assert_eq('rules_set', $c['handler']);
    assert_eq('', $c['args']);
    assert_eq("1. Не спамить\n2. Не ругаться", $c['body']);
});

test('сроки: единицы, голое число — минуты, «навсегда»', function () {
    $cases = ['10м' => 600, '10 минут' => 600, '2ч' => 7200, '2 часа' => 7200, '3д' => 259200,
              '3 дня' => 259200, 'неделя' => 604800, '2 недели' => 1209600, 'месяц' => 2592000,
              'год' => 31536000, 'навсегда' => 0, '30с' => 30, '15' => 900, 'час' => 3600];
    foreach ($cases as $in => $want) {
        $r = $in . ' флуд';
        assert_eq($want, cm_take_term($r), $in);
        assert_eq('флуд', $r, "остаток после «{$in}»");
    }
    foreach (['10с', '0м', '400 дней', '2 года'] as $bad) {
        $r = $bad;
        assert_eq(-1, cm_take_term($r), $bad);
    }
    foreach (['спам', 'м', 'за флуд', ''] as $none) {
        $r = $none;
        assert_eq(null, cm_take_term($r), "«{$none}»");
        assert_eq($none, $r, 'без срока строка не меняется');
    }
});

test('срок словами', function () {
    assert_eq('навсегда', cm_term_text(0));
    assert_eq('7 дней', cm_term_text(604800));
    assert_eq('1 день', cm_term_text(86400));
    assert_eq('3 дня', cm_term_text(259200));
    assert_eq('2 ч', cm_term_text(7200));
    assert_eq('10 мин', cm_term_text(600));
    assert_eq('45 с', cm_term_text(45));
});

test('цель: ответ, @ник, ссылка, id', function () {
    $pdo = cm_test_pdo();
    cm_person($pdo, 55, 'vasya', 'Вася');
    $reply = ['message_id' => 7, 'from' => ['id' => 66, 'first_name' => 'Петя']];
    $a = '1ч спам';
    $t = cm_target($pdo, cm_gmsg(1, 'мут 1ч спам', ['reply_to_message' => $reply]), $a);
    assert_eq([66, 'Петя', 7, false], [$t['id'], $t['name'], $t['msg_id'], $t['is_bot']]);
    assert_eq('1ч спам', $a, 'ответ аргументы не ест');
    foreach (['@vasya 1ч', 't.me/vasya 1ч', 'https://t.me/Vasya 1ч'] as $in) {
        $a = $in;
        $t = cm_target($pdo, cm_gmsg(1, 'мут ' . $in), $a);
        assert_eq(55, $t['id'] ?? null, $in);
        assert_eq('1ч', $a, $in);
    }
    $a = '123456789 1ч';
    $t = cm_target($pdo, cm_gmsg(1, 'x'), $a);
    assert_eq([123456789, 'id123456789'], [$t['id'], $t['name']]);
    assert_eq('1ч', $a);
});

test('незнакомый ник и отсутствие цели', function () {
    $pdo = cm_test_pdo();
    $a = '@ghost 1ч';
    $why = null;
    assert_eq(null, cm_target($pdo, cm_gmsg(1, 'мут @ghost 1ч'), $a, $why));
    assert_eq('unknown', $why);
    assert_eq('@ghost 1ч', $a, 'неудача аргументы не портит');
    $a = '1ч';
    $why = null;
    assert_eq(null, cm_target($pdo, cm_gmsg(1, 'мут 1ч'), $a, $why));
    assert_eq(null, $why);
});

test('упоминание без ника и ранг перед целью', function () {
    $pdo = cm_test_pdo();
    $msg = cm_gmsg(1, 'мут Иван 1ч', ['entities' => [
        ['type' => 'text_mention', 'offset' => 4, 'length' => 4, 'user' => ['id' => 77, 'first_name' => 'Иван']],
    ]]);
    $a = 'Иван 1ч';
    $t = cm_target($pdo, $msg, $a);
    assert_eq(77, $t['id']);
    assert_eq('1ч', $a);
    cm_person($pdo, 55, 'vasya');
    $a = '2 @vasya';
    $t = cm_target($pdo, cm_gmsg(1, '+модер 2 @vasya'), $a);
    assert_eq(55, $t['id']);
    assert_eq('2', $a, 'ранг остаётся в аргументах');
});

test('ответ на корень темы форума — не ответ', function () {
    $msg = cm_gmsg(1, 'мут', ['is_topic_message' => true, 'message_thread_id' => 50,
        'reply_to_message' => ['message_id' => 50, 'from' => ['id' => 9], 'forum_topic_created' => ['name' => 'x']]]);
    assert_eq(null, cm_reply_of($msg));
    $msg['reply_to_message'] = ['message_id' => 60, 'from' => ['id' => 9]];
    assert_eq(60, cm_reply_of($msg)['message_id']);
});

test('причина: остаток строки и тело, до 500 знаков', function () {
    assert_eq("спам\nи реклама", cm_reason(' спам ', "и реклама\n"));
    assert_eq('спам', cm_reason('спам', ''));
    assert_eq(500, mb_strlen(cm_reason(str_repeat('я', 600), '')));
});

test('разметка тела сдвигается на первую строку', function () {
    $msg = cm_gmsg(1, "+заметка правила\nЖирный текст", ['entities' => [
        ['type' => 'bold', 'offset' => 0, 'length' => 8],
        ['type' => 'bold', 'offset' => 17, 'length' => 6],
    ]]);
    assert_eq([['type' => 'bold', 'offset' => 0, 'length' => 6]], cm_body_entities($msg));
    assert_eq(2, cm_utf16_len('😀'));
    assert_eq('мир', cm_utf16_substr('😀 мир', 3, 3));
    assert_eq('', cm_utf16_substr('мир', 10, 3));
});

run_tests();
