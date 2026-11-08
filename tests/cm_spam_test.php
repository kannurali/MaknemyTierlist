<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Общая база спамеров: api/lib/cm/spam.php.

// Telegram 500 — Roblox 900 (admin_ids), Telegram 501 — Roblox 901 (moderator_ids).
function sp_staff(PDO $pdo): void {
    $pdo->exec('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (900, 500, 0), (901, 501, 0)');
}

test('сотрудник — admin_ids или moderator_ids с привязанным Telegram', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    assert_eq([true, true, false], [cm_is_staff($pdo, cm_cfg(), 500), cm_is_staff($pdo, cm_cfg(), 501), cm_is_staff($pdo, cm_cfg(), 502)]);
    assert_eq(false, cm_is_staff(test_db(), cm_cfg(), 500), 'без таблицы tg_links');
    assert_eq('900', cm_staff_roblox($pdo, 500));
});

test('не сотрудник базу не трогает; без префикса — молча', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'spammer');
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '!+спамер @spammer');
    assert_eq(false, cm_spam_has($pdo, 20));
    assert_eq(cm_t('spam_staff_only'), cm_last_text($log));
    $log = [];
    cm_do($ctx, '+спамер @spammer');
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('+спамер: в базу, удалить и забанить здесь и в других чатах Макнеми с фильтром', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    cm_seed_chat($pdo, -2001, true);
    cm_seed_chat($pdo, -2002, true);
    cm_seed_chat($pdo, -2003, false);
    $b = cm_chat_row($pdo, -2002);
    $s = $b['settings'];
    $s['filters']['spam'] = false;
    cm_settings_save($pdo, $b, $s, CM_NOW);
    cm_member_touch($pdo, -2001, 20, CM_NOW);
    cm_member_touch($pdo, -2002, 20, CM_NOW);
    cm_member_touch($pdo, -2003, 20, CM_NOW);
    $reply = ['message_id' => 77, 'from' => ['id' => 20, 'first_name' => 'Спамер']];
    $ctx = cm_ctx_for($pdo, $log, 500, '', ['reply_to_message' => $reply]);
    cm_do($ctx, '+спамер реклама казино');
    $row = cm_spam_row($pdo, 20);
    assert_eq(['реклама казино', '900'], [$row['reason'], $row['added_by']]);
    assert_eq(77, cm_calls($log, 'deleteMessage')[0][1]['message_id']);
    $bans = array_map(function ($c) { return $c[1]['chat_id']; }, cm_calls($log, 'banChatMember'));
    assert_eq([CM_CHAT, -2001], $bans, 'в чате с выключенным фильтром и в чужом не баним');
    assert_eq(cm_t('spam_added', ['name' => 'Спамер', 'n' => 2]), cm_last_text($log));
});

test('сотрудника и бота в базу не внести', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    cm_person($pdo, 501, 'moder');
    $ctx = cm_ctx_for($pdo, $log, 500);
    cm_do($ctx, '+спамер @moder');
    assert_eq(false, cm_spam_has($pdo, 501));
    assert_eq(cm_t('spam_bad_target'), cm_last_text($log));
});

test('в личке бота: внести по id, проверить, убрать', function () {
    $pdo = cm_test_pdo();
    sp_staff($pdo);
    $log = [];
    $msg = ['message_id' => 9, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500, 'first_name' => 'Мод'], 'text' => ''];
    $ctx = cm_ctx($pdo, cm_cfg(), cm_rec($log), cm_private_chat(500), $msg, CM_NOW);
    cm_do($ctx, '+спамер 123456789 скам');
    assert_eq(true, cm_spam_has($pdo, 123456789));
    assert_eq('скам', cm_spam_row($pdo, 123456789)['reason']);
    assert_eq(0, count(cm_calls($log, 'banChatMember')), 'в личке банить негде');
    cm_do($ctx, 'проверить 123456789');
    assert_eq(true, strpos(cm_last_text($log), 'в базе спамеров с') !== false);
    cm_do($ctx, '-спамер 123456789');
    assert_eq(false, cm_spam_has($pdo, 123456789));
    cm_do($ctx, 'проверить 123456789');
    assert_eq(cm_t('spam_no', ['name' => 'id123456789']), cm_last_text($log));
    cm_do($ctx, '-спамер 123456789');
    assert_eq(cm_t('spam_missing', ['name' => 'id123456789']), cm_last_text($log));
});

run_tests();
