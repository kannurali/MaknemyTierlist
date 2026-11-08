<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Вход, приветствие, правила, статус бота: api/lib/cm/welcome.php.

function wl_greeting(PDO $pdo, string $text): void {
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), "+приветствие\n" . $text);
}

test('приветствие: один раз на человека, подстановки', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}! Это {чат}.');
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30);
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq('Привет, Аня! Это Тестовый чат.', cm_last_text($log));
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'второй раз не приветствуем');
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 40)['joined_at']);
    cm_on_join($ctx, [['id' => 41, 'is_bot' => true, 'first_name' => 'Bot']]);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'ботов не приветствуем');
});

test('без текста приветствия никого не приветствуем и не отмечаем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30);
    cm_on_join($ctx, [['id' => 40, 'first_name' => 'Аня']]);
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
    assert_eq(0, cm_member($pdo, CM_CHAT, 40)['greeted_at']);
});

test('рейд: больше трёх за минуту — одно общее приветствие в минуту', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $log = [];
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 41, 'first_name' => 'А'], ['id' => 42, 'first_name' => 'Б'], ['id' => 43, 'first_name' => 'В']]);
    assert_eq('Привет, А, Б, В!', cm_last_text($log));
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 44, 'first_name' => 'Г']]);
    assert_eq('Привет, новые участники!', cm_last_text($log));
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 45, 'first_name' => 'Д']]);
    assert_eq(2, count(cm_calls($log, 'sendMessage')));
});

test('спамер из базы при входе — бан, без приветствия', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (50, 'скам', '900', 0)");
    $log = [];
    cm_on_join(cm_ctx_for($pdo, $log, 30), [['id' => 50, 'first_name' => 'Скам']]);
    assert_eq(50, cm_calls($log, 'banChatMember')[0][1]['user_id']);
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
});

test('приветствие: показать, выключить, сбросить историю', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+приветствие');
    assert_eq(cm_t('welcome_usage'), cm_last_text($log));
    cm_do($o, "+приветствие\nЗдравствуй, {имя}");
    cm_do($o, 'приветствие');
    assert_eq('Здравствуй, {имя}', cm_last_text($log));
    cm_member_set($pdo, CM_CHAT, 40, ['greeted_at' => CM_NOW]);
    cm_do($o, 'сброс приветствий');
    assert_eq(0, cm_member($pdo, CM_CHAT, 40)['greeted_at']);
    cm_do($o, '-приветствие');
    cm_do($o, 'приветствие');
    assert_eq(cm_t('welcome_none'), cm_last_text($log));
});

test('правила: с разметкой, показывает всем, удаляются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $text = "+правила\nБез спама";
    $o = cm_ctx_for($pdo, $log, CM_OWNER, $text, ['entities' => [['type' => 'bold', 'offset' => 9, 'length' => 3]]]);
    cm_do($o, $text);
    $rules = cm_chat_row($pdo, CM_CHAT)['settings']['rules'];
    assert_eq(['Без спама', [['type' => 'bold', 'offset' => 0, 'length' => 3]]], [$rules['text'], $rules['entities']]);
    $r = cm_ctx_for($pdo, $log, 30);
    cm_do($r, 'правила');
    $sent = cm_calls($log, 'sendMessage');
    $last = $sent[count($sent) - 1][1];
    assert_eq(['Без спама', [['type' => 'bold', 'offset' => 0, 'length' => 3]]], [$last['text'], $last['entities']]);
    cm_do($o, '-правила');
    cm_do($r, 'правила');
    assert_eq(cm_t('rules_none'), cm_last_text($log));
});

function wl_bot_update(string $old, string $new, array $rights = []): array {
    return ['my_chat_member' => [
        'chat' => ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Т'], 'from' => ['id' => CM_OWNER],
        'old_chat_member' => ['status' => $old, 'user' => ['id' => 999, 'is_bot' => true]],
        'new_chat_member' => ['status' => $new, 'user' => ['id' => 999, 'is_bot' => true]] + $rights,
    ]];
}

test('бота добавили — знакомство и недостающие права; дали права — подтверждение; убрали — чат гаснет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('left', 'member'), CM_NOW);
    $t = cm_last_text($log);
    assert_eq(0, strpos($t, cm_t('intro')));
    assert_eq(true, strpos($t, 'Удаление сообщений') !== false && strpos($t, 'Блокировка пользователей') !== false);
    $rights = ['can_delete_messages' => true, 'can_restrict_members' => true];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('member', 'administrator', $rights), CM_NOW + 1);
    assert_eq(cm_t('rights_ok'), cm_last_text($log));
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), wl_bot_update('administrator', 'left'), CM_NOW + 2);
    assert_eq(0, cm_chat_row($pdo, CM_CHAT)['active']);
    $n = count($log);
    $chan = wl_bot_update('left', 'member');
    $chan['my_chat_member']['chat']['type'] = 'channel';
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $chan, CM_NOW);
    assert_eq($n, count($log), 'каналы — не наша забота');
    assert_eq(['Удаление сообщений'], cm_missing_rights(['status' => 'administrator', 'can_restrict_members' => true]));
});

test('chat_member: вход — как служебное сообщение, выход — отметка', function () {
    $pdo = cm_test_pdo();
    wl_greeting($pdo, 'Привет, {имя}!');
    $log = [];
    $upd = ['chat_member' => [
        'chat' => ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'], 'from' => ['id' => 60],
        'old_chat_member' => ['status' => 'left', 'user' => ['id' => 60, 'first_name' => 'Оля']],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 60, 'first_name' => 'Оля']],
    ]];
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $upd, CM_NOW);
    assert_eq('Привет, Оля!', cm_last_text($log));
    $upd['chat_member']['old_chat_member']['status'] = 'member';
    $upd['chat_member']['new_chat_member']['status'] = 'left';
    cm_on_membership($pdo, cm_cfg(), cm_rec($log), $upd, CM_NOW + 5);
    assert_eq(CM_NOW + 5, cm_member($pdo, CM_CHAT, 60)['left_at']);
});

run_tests();
