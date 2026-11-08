<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Ядро чат-менеджера: отправка (send.php), очередь удаления (queue.php),
// ранги и доступ (ranks.php).

test('ошибки Telegram по смыслу', function () {
    assert_eq('', cm_tg_error(['ok' => true]));
    assert_eq('target_admin', cm_tg_error(['ok' => false, 'description' => 'Bad Request: user is an administrator of the chat']));
    assert_eq('target_admin', cm_tg_error(['ok' => false, 'description' => "Bad Request: can't remove chat owner"]));
    assert_eq('no_rights', cm_tg_error(['ok' => false, 'description' => 'Bad Request: not enough rights to restrict/unrestrict chat member']));
    assert_eq('no_rights', cm_tg_error(['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']));
    assert_eq('need_supergroup', cm_tg_error(['ok' => false, 'description' => 'Bad Request: method is available only for supergroups']));
    assert_eq('not_member', cm_tg_error(['ok' => false, 'description' => 'Bad Request: user not found']));
    assert_eq('tg_failed', cm_tg_error(null));
});

test('бюджет: не больше 15 сообщений в минуту на группу', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => CM_CHAT], CM_NOW);
    $ok = 0;
    for ($i = 0; $i < 20; $i++) { if (cm_budget_take($pdo, CM_CHAT, CM_NOW)) { $ok++; } }
    assert_eq(15, $ok);
    assert_eq(true, cm_budget_take($pdo, CM_CHAT, CM_NOW + 60), 'новая минута');
    assert_eq(true, cm_budget_take($pdo, 12345, CM_NOW), 'личка без бюджета');
});

test('ответ уходит ответом в тот же чат, временный — в очередь на удаление', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'кто я');
    $id = cm_reply($ctx, 'привет');
    $sent = cm_calls($log, 'sendMessage')[0][1];
    assert_eq([CM_CHAT, 'привет', $ctx['msg']['message_id'], true],
        [$sent['chat_id'], $sent['text'], $sent['reply_parameters']['message_id'], $sent['reply_parameters']['allow_sending_without_reply']]);
    $q = $pdo->query('SELECT chat_id, message_id, delete_at FROM cm_queue')->fetch(PDO::FETCH_NUM);
    assert_eq([CM_CHAT, $id, CM_NOW + 60], array_map('intval', $q));
    cm_reply($ctx, 'постоянный', false);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
});

test('без автоудаления временный ответ остаётся', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $s = $ctx['chat']['settings'];
    $s['autodelete'] = false;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    cm_reply($ctx, 'привет');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
});

test('исчерпан бюджет — бот молчит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $pdo->prepare('UPDATE cm_chats SET sent_window = :w, sent_count = 15')->execute([':w' => intdiv(CM_NOW, 60)]);
    assert_eq(0, cm_say($ctx, CM_CHAT, 'тишина'));
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('о правах — не чаще раза в сутки, по команде — сразу', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_rights_warn($ctx);
    cm_rights_warn($ctx);
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
    cm_rights_warn($ctx, true);
    assert_eq(2, count(cm_calls($log, 'sendMessage')));
    $ctx['now'] = CM_NOW + 86401;
    cm_rights_warn($ctx);
    assert_eq(3, count(cm_calls($log, 'sendMessage')));
});

test('создатель — ранг 5, админ Telegram — 0, но фильтры его не трогают', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 2);
    $ctx = cm_ctx_for($pdo, $log, 10);
    assert_eq([5, 0, 2, 0], [cm_rank($ctx, CM_OWNER), cm_rank($ctx, CM_ADMIN), cm_rank($ctx, 10), cm_rank($ctx, 11)]);
    assert_eq([true, true, false], [cm_exempt($ctx, CM_ADMIN), cm_exempt($ctx, 10), cm_exempt($ctx, 11)]);
    assert_eq('Owner', cm_user_name($pdo, CM_OWNER), 'админов запоминаем по именам');
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')));
    cm_admins_refresh($ctx);
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')), 'кеш на час');
    $ctx['now'] += 3601;
    cm_admins_refresh($ctx);
    assert_eq(2, count(cm_calls($log, 'getChatAdministrators')));
});

test('Telegram не отдал админов — повтор через час, а не на каждом сообщении', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], ['getChatAdministrators' => ['ok' => false]]);
    cm_admins_refresh($ctx);
    assert_eq(1, count(cm_calls($log, 'getChatAdministrators')));
});

test('кого можно трогать', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 2);
    cm_lvl($pdo, 11, 2);
    cm_lvl($pdo, 12, 1);
    $ctx = cm_ctx_for($pdo, $log, 10);
    assert_eq('', cm_can_touch($ctx, ['id' => 12, 'is_bot' => false]));
    assert_eq('target_rank', cm_can_touch($ctx, ['id' => 11, 'is_bot' => false]));
    assert_eq('target_self', cm_can_touch($ctx, ['id' => 10, 'is_bot' => false]));
    assert_eq('target_admin', cm_can_touch($ctx, ['id' => CM_ADMIN, 'is_bot' => false]));
    assert_eq('target_bot', cm_can_touch($ctx, ['id' => 99, 'is_bot' => true]));
});

test('уровень доступа: раздел из настроек, иначе по умолчанию', function () {
    $chat = ['settings' => cm_settings(['access' => ['бан' => 2, 'мут' => 9]])];
    assert_eq(2, cm_need_level($chat, 'бан', 3));
    assert_eq(1, cm_need_level($chat, 'мут', 1), 'мусор игнорируется');
    assert_eq(5, cm_need_level($chat, null, 5));
    assert_eq(4, cm_section_default('ранги'));
    assert_eq(2, cm_section_default('репорты'));
});

test('без цели — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 10, 'мут 1ч');
    $args = '1ч';
    assert_eq(null, cm_need_target($ctx, $args));
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $args = '@ghost';
    assert_eq(null, cm_need_target($ctx, $args));
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
});

test('очередь удаляет пачками по чатам и чистит старые сообщения', function () {
    $pdo = cm_test_pdo();
    $log = [];
    foreach ([1, 2, 3] as $m) { cm_queue_add($pdo, CM_CHAT, $m, CM_NOW - 1); }
    cm_queue_add($pdo, CM_CHAT, 4, CM_NOW + 100);
    cm_msg_log($pdo, CM_CHAT, 50, 7, 'старое', CM_NOW - CM_MSG_KEEP - 1);
    cm_msg_log($pdo, CM_CHAT, 51, 7, 'свежее', CM_NOW);
    assert_eq(3, cm_queue_tick($pdo, cm_rec($log), CM_NOW));
    $del = cm_calls($log, 'deleteMessages');
    assert_eq(1, count($del));
    assert_eq([CM_CHAT, [1, 2, 3]], [$del[0][1]['chat_id'], $del[0][1]['message_ids']]);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn());
    assert_eq([51], array_map('intval', $pdo->query('SELECT message_id FROM cm_msgs')->fetchAll(PDO::FETCH_COLUMN)));
});

test('пакетное удаление режет по 100 и сообщает об отказе в правах', function () {
    $log = [];
    assert_eq(true, cm_delete_many(cm_rec($log), CM_CHAT, range(1, 150)));
    assert_eq([100, 50], array_map(function ($c) { return count($c[1]['message_ids']); }, $log));
    $log = [];
    $deny = ['deleteMessages' => ['ok' => false, 'description' => 'Bad Request: CHAT_ADMIN_REQUIRED']];
    assert_eq(false, cm_delete_many(cm_rec($log, $deny), CM_CHAT, [1]));
});

run_tests();
