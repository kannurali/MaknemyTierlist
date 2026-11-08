<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Репутация (этап 2): api/lib/cm/rep.php.

// Ответ на сообщение человека $to с именем $name.
function rp_reply(int $to, string $name = 'Петя', array $from = []): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => $from + ['id' => $to, 'is_bot' => false, 'first_name' => $name]]];
}

function rp_score(PDO $pdo, int $userId): int {
    return cm_rep_score($pdo, CM_CHAT, $userId);
}

test('ответ «+» и «спасибо» — голос, ответ бота временный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    assert_eq(1, rp_score($pdo, 20));
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'удалится через минуту');
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 8, 'Спасибо!', rp_reply(20))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 9, "спс\nвыручил", rp_reply(20))));
    assert_eq(3, rp_score($pdo, 20));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 10, '-', rp_reply(20))));
    assert_eq('👎 U10 → Петя: -1 (репутация 2)', cm_last_text($log));
});

test('не голос: без ответа, лишние слова в первой строке', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+')));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, 'спасибо большое', rp_reply(20))));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+ за помощь', rp_reply(20))));
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+реп', rp_reply(20))));
    assert_eq(0, rp_score($pdo, 20));
    assert_eq([], cm_calls($log, 'sendMessage'));
});

test('себе, ботам и служебному Telegram — молча, но это голос', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(7, 'U7'))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(555, 'Бот', ['is_bot' => true]))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(777000, 'Telegram'))));
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_rep_log')->fetchColumn());
});

test('одному человеку — раз в 24 часа, лишний голос молча', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20)));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 86399)));
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
    assert_eq(1, rp_score($pdo, 20));
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 86401));
    assert_eq(2, rp_score($pdo, 20), 'через сутки — снова можно');
});

test('не больше 20 голосов за 24 часа от одного человека', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ins = $pdo->prepare('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (:c, 7, :t, 1, :a)');
    for ($i = 0; $i < 20; $i++) { $ins->execute([':c' => CM_CHAT, ':t' => 100 + $i, ':a' => CM_NOW - 3600]); }
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    assert_eq(0, rp_score($pdo, 20));
    assert_eq([], cm_calls($log, 'sendMessage'), 'ответом — молча');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_do(cm_ctx_for($pdo, $log, 7), '+реп @petya');
    assert_eq(cm_t('rep_day_full', ['max' => 20]), cm_last_text($log), 'командой — с пояснением');
    cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20), [], CM_NOW + 82801));
    assert_eq(1, rp_score($pdo, 20), 'старые голоса вышли из суток');
});

test('+реп и -реп: цель, отказы с пояснением', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+реп @petya');
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    cm_do($ctx, '-реп @petya');
    assert_eq(cm_t('rep_pair_wait'), cm_last_text($log));
    cm_do($ctx, '+реп');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', rp_reply(7, 'U7')), '+реп');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', rp_reply(555, 'Бот', ['is_bot' => true])), '-реп');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('репа: своя, чужая; топ репы без нулей и ушедших', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'user7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    $ins = $pdo->prepare('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (:c, :u, :s)');
    foreach ([7 => 3, 20 => 15, 21 => -2, 22 => 0, 23 => 40] as $u => $s) { $ins->execute([':c' => CM_CHAT, ':u' => $u, ':s' => $s]); }
    cm_member_set($pdo, CM_CHAT, 23, ['left_at' => CM_NOW]);
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, 'репа');
    assert_eq('⭐ U7: репутация 3', cm_last_text($log));
    cm_do($ctx, 'репа @petya');
    assert_eq('⭐ Петя: репутация 15', cm_last_text($log));
    cm_do($ctx, 'топ репы');
    assert_eq("⭐ Лучшая репутация:\n1. Петя — 15\n2. U7 — 3\n3. Коля — -2", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'топ репы');
    assert_eq(cm_t('rep_top_empty'), cm_last_text($log));
});

test('без префикса: «репа» ответом — команда, «репа сварилась» — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'репа', rp_reply(20));
    assert_eq(true, cm_run_command($ctx, cm_parse('репа', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, 'репа сварилась');
    assert_eq(false, cm_run_command($ctx, cm_parse('репа сварилась', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, '+реп');
    assert_eq(false, cm_run_command($ctx, cm_parse('+реп', CM_BOT)), 'без цели и префикса — речь');
});

test('дк репутация 6: «+» — обычное сообщение, команды выключены', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк репутация 6');
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    $ctx = cm_ctx_for($pdo, $log, 7, '!репа');
    cm_run_command($ctx, cm_parse('!репа', CM_BOT));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк репутация 2');
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))), 'ранг ниже нужного — не голос');
    cm_lvl($pdo, 7, 2);
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
});

test('нет таблиц этапа 2: «+» — обычное сообщение, команды — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    assert_eq(false, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    cm_do(cm_ctx_for($pdo, $log, 7), 'топ репы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

test('сеть: репутация одна на все чаты Макнеми, пределы — тоже', function () {
    $pdo = cm_test_pdo();
    $trade = -1005550001;
    cm_seed_chat($pdo, $trade, true);
    cm_seed_chat($pdo, -1005550002, false);
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (-1005550002, 20, 50)');   // чужой чат
    cm_person($pdo, 20, 'petya', 'Петя');
    $tr = ['chat' => ['id' => $trade, 'type' => 'supergroup', 'title' => 'Трейд']];
    $log = [];
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20))));
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 7, '+', rp_reply(20) + $tr)));
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'тот же человек в другом чате — раз в сутки');
    assert_eq(true, cm_rep_try(cm_ctx_for($pdo, $log, 8, '+', rp_reply(20) + $tr)));
    assert_eq('👍 U8 → Петя: +1 (репутация 2)', cm_last_text($log));
    assert_eq(1, rp_score($pdo, 20), 'в каждом чате — свой голос');
    $ctx = cm_ctx_for($pdo, $log, 7, '', $tr);
    cm_do($ctx, 'репа @petya');
    assert_eq(true, strpos(cm_last_text($log), 'репутация 2') !== false);
    cm_do($ctx, 'топ репы');
    assert_eq(cm_t('rep_top_head') . "\n1. Петя — 2", cm_last_text($log));
});

run_tests();
