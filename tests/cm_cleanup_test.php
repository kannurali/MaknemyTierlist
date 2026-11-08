<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Чистка: api/lib/cm/cleanup.php.

test('чистка N — последние N и сама команда; без числа и ответа — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    $cur = $ctx['msg']['message_id'];
    cm_do($ctx, 'чистка 3');
    assert_eq(range($cur - 3, $cur), cm_calls($log, 'deleteMessages')[0][1]['message_ids']);
    assert_eq(cm_t('purge_done', ['n' => 3]), cm_last_text($log));
    cm_do($ctx, 'чистка 500');
    assert_eq(cm_t('purge_usage'), cm_last_text($log));
    cm_do($ctx, 'чистка');
    assert_eq(cm_t('purge_usage'), cm_last_text($log));
});

test('чистка @ник — его сообщения за 48 часов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    cm_msg_log($pdo, CM_CHAT, 10, 20, 'a', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 11, 20, 'b', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 9, 20, 'старое', CM_NOW - CM_MSG_KEEP - 1);
    cm_msg_log($pdo, CM_CHAT, 12, 21, 'чужое', CM_NOW);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'чистка @vasya');
    $ids = cm_calls($log, 'deleteMessages')[0][1]['message_ids'];
    sort($ids);
    assert_eq([10, 11, $ctx['msg']['message_id']], $ids);
});

test('чистка ответом — от того сообщения до команды', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    $cur = $ctx['msg']['message_id'];
    $ctx['msg']['reply_to_message'] = ['message_id' => $cur - 5, 'from' => ['id' => 20]];
    cm_do($ctx, 'чистка');
    assert_eq(range($cur - 5, $cur), cm_calls($log, 'deleteMessages')[0][1]['message_ids']);
});

test('нет права удалять — сообщение о правах', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', [], ['deleteMessages' => ['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']]);
    cm_do($ctx, 'чистка 3');
    assert_eq(cm_t('need_rights'), cm_last_text($log));
});

test('кик неактивных: подтверждение, чужая кнопка, исключение', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $old = CM_NOW - 40 * 86400;
    cm_member_set($pdo, CM_CHAT, 20, ['last_seen' => $old]);
    cm_member_set($pdo, CM_CHAT, 21, ['last_seen' => CM_NOW - 86400]);
    cm_member_set($pdo, CM_CHAT, 22, ['last_seen' => $old, 'lvl' => 1]);
    cm_member_set($pdo, CM_CHAT, CM_ADMIN, ['last_seen' => $old]);
    cm_member_set($pdo, CM_CHAT, 23, ['last_seen' => $old, 'left_at' => CM_NOW - 100]);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'кик неактивных 30 дней');
    $sent = cm_calls($log, 'sendMessage');
    $data = $sent[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    assert_eq('cm:k:i:2592000:1', $data);
    assert_eq(cm_t('mass_confirm', ['n' => 1, 'max' => 200]), $sent[0][1]['text']);
    $q = ['id' => 'q', 'data' => $data, 'message' => ['message_id' => 8000, 'chat' => ['id' => CM_CHAT]]];
    assert_eq(cm_t('not_yours'), cm_mass_callback(cm_ctx_for($pdo, $log, 99), $q, 'i', 2592000, 1));
    assert_eq(cm_t('mass_done', ['n' => 1]), cm_mass_callback($ctx, $q, 'i', 2592000, 1));
    assert_eq([20], array_map(function ($c) { return $c[1]['user_id']; }, cm_calls($log, 'banChatMember')));
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 20)['left_at']);
    cm_do($ctx, 'кик неактивных 30');
    assert_eq(cm_t('mass_none'), cm_last_text($log), 'голое число — дни');
});

test('кик удалённых: только аккаунты без имени', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_member_set($pdo, CM_CHAT, 20, ['last_seen' => CM_NOW]);
    cm_member_set($pdo, CM_CHAT, 21, ['last_seen' => CM_NOW]);
    $answers = ['getChatMember' => function ($p) {
        $name = $p['user_id'] === 20 ? '' : 'Петя';
        return ['ok' => true, 'result' => ['status' => 'member', 'user' => ['id' => $p['user_id'], 'first_name' => $name]]];
    }];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', [], $answers);
    cm_do($ctx, 'кик удалённых');
    assert_eq(cm_t('mass_confirm_deleted', ['n' => 2, 'max' => 200]), cm_last_text($log));
    $q = ['id' => 'q', 'data' => 'cm:k:d:0:1', 'message' => ['message_id' => 8000, 'chat' => ['id' => CM_CHAT]]];
    assert_eq(cm_t('mass_done', ['n' => 1]), cm_mass_callback($ctx, $q, 'd', 0, 1));
    assert_eq([20], array_map(function ($c) { return $c[1]['user_id']; }, cm_calls($log, 'banChatMember')));
});

test('кик новых: по умолчанию за час', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_member_set($pdo, CM_CHAT, 20, ['joined_at' => CM_NOW - 600]);
    cm_member_set($pdo, CM_CHAT, 21, ['joined_at' => CM_NOW - 7200]);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    assert_eq([20], cm_mass_candidates($ctx, 'n', 3600, 10));
    cm_do($ctx, 'кик новых');
    assert_eq('cm:k:n:3600:1', cm_calls($log, 'sendMessage')[0][1]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    cm_do($ctx, 'кик новых 10с');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
});

run_tests();
