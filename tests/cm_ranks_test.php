<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Команды рангов и «дк»: api/lib/cm/ranks.php.

test('+модер ставит ранг и пишет журнал', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+модер 2 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq(true, strpos(cm_last_text($log), 'старший модератор') !== false);
    $row = $pdo->query('SELECT user_id, by_id, old_lvl, new_lvl FROM cm_rank_log')->fetch(PDO::FETCH_NUM);
    assert_eq([20, CM_OWNER, 0, 2], array_map('intval', $row));
    cm_do($ctx, '+модер @vasya 3');
    assert_eq(3, cm_member($pdo, CM_CHAT, 20)['lvl'], 'ранг и после цели');
});

test('назначать можно только ниже своего ранга, 5 — только передачей', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 3);
    cm_lvl($pdo, 11, 3);
    cm_person($pdo, 20, 'vasya');
    cm_person($pdo, 11, 'petya');
    $ctx = cm_ctx_for($pdo, $log, 10);
    cm_do($ctx, '+модер 3 @vasya');
    assert_eq(null, cm_member($pdo, CM_CHAT, 20), 'равный своему — нельзя');
    assert_eq(cm_t('rank_too_high'), cm_last_text($log));
    cm_do($ctx, '+модер 2 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, '-модер @petya');
    assert_eq(3, cm_member($pdo, CM_CHAT, 11)['lvl'], 'равного не снять');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+модер 5 @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    assert_eq(cm_t('rank_need_level'), cm_last_text($log));
});

test('повысить, понизить, снять', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'повысить @vasya');
    cm_do($ctx, 'повысить @vasya');
    assert_eq(2, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'понизить @vasya');
    assert_eq(1, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'снять модера @vasya');
    assert_eq(0, cm_member($pdo, CM_CHAT, 20)['lvl']);
    cm_do($ctx, 'понизить @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'уже участник') !== false);
});

test('модеры и журнал рангов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+модер 2 @vasya');
    cm_do($ctx, 'модеры');
    assert_eq(cm_t('ranks_head') . "\n5 · Owner\n2 · Вася", cm_last_text($log));
    cm_do($ctx, 'журнал рангов');
    assert_eq(true, strpos(cm_last_text($log), 'Owner: Вася 0 → 2') !== false);
    cm_do($ctx, 'журнал рангов @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'Вася 0 → 2') !== false);
});

test('передача создателя — по кнопке у нового создателя', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'передать создателя @vasya');
    $btn = cm_calls($log, 'sendMessage')[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    assert_eq('cm:o:1:20', $btn);
    $q = ['id' => 'q1', 'from' => ['id' => 20], 'data' => $btn,
          'message' => ['message_id' => 777, 'chat' => ['id' => CM_CHAT], 'text' => 'x']];
    assert_eq(cm_t('not_yours'), cm_owner_callback(cm_ctx_for($pdo, $log, 99), $q, 1, 20));
    $tgt = cm_ctx_for($pdo, $log, 20);
    cm_owner_callback($tgt, $q, 1, 20);
    assert_eq(20, cm_chat_row($pdo, CM_CHAT)['owner_id']);
    $after = cm_ctx_for($pdo, $log, 20);
    assert_eq([5, 4], [cm_rank($after, 20), cm_rank($after, CM_OWNER)]);
    assert_eq(1, count(cm_calls($log, 'editMessageText')));
    assert_eq(cm_t('offer_stale'), cm_owner_callback($tgt, $q, 1, 20), 'повторное нажатие');
});

test('вернуть создателя может только владелец группы в Telegram', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Т'], CM_NOW);
    $pdo->exec('UPDATE cm_chats SET owner_id = 20');
    $v = cm_ctx_for($pdo, $log, 20);
    cm_do($v, 'вернуть создателя');
    assert_eq(cm_t('owner_tg_only'), cm_last_text($log));
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'вернуть создателя');
    assert_eq(CM_OWNER, cm_chat_row($pdo, CM_CHAT)['owner_id']);
    $after = cm_ctx_for($pdo, $log, CM_OWNER);
    assert_eq(4, cm_rank($after, 20));
});

test('дк: таблица, установка, выключение, сброс', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'дк бан 2');
    assert_eq(2, cm_chat_row($pdo, CM_CHAT)['settings']['access']['бан']);
    cm_do($ctx, 'дк мут 6');
    assert_eq(6, cm_chat_row($pdo, CM_CHAT)['settings']['access']['мут']);
    cm_do($ctx, 'дк дк 0');
    assert_eq(true, strpos(cm_last_text($log), 'Разделы:') !== false, '«дк» не настраивается');
    $c = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($c, 'дк');
    assert_eq(true, strpos(cm_last_text($log), "бан: 2") !== false);
    assert_eq(true, strpos(cm_last_text($log), "кик: 2") !== false);
    cm_do($c, 'дк сброс');
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['access']);
});

run_tests();
