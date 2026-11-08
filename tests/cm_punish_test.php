<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Наказания и варны: api/lib/cm/punish.php.

function pn_mod(PDO $pdo, array &$log, array $answers = []): array {
    cm_lvl($pdo, 10, 3);
    cm_person($pdo, 20, 'vasya', 'Вася');
    return cm_ctx_for($pdo, $log, 10, '', [], $answers);
}

test('мут: параметры Telegram и запись', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, "мут @vasya 2ч\nфлуд");
    $c = cm_calls($log, 'restrictChatMember')[0][1];
    assert_eq([CM_CHAT, 20, CM_NOW + 7200, false, false],
        [$c['chat_id'], $c['user_id'], $c['until_date'], $c['permissions']['can_send_messages'], $c['use_independent_chat_permissions']]);
    $row = $pdo->query('SELECT kind, reason, by_id, until_at FROM cm_punishments')->fetch(PDO::FETCH_ASSOC);
    assert_eq(['mute', 'флуд', 10, CM_NOW + 7200], [$row['kind'], $row['reason'], (int)$row['by_id'], (int)$row['until_at']]);
    assert_eq("🔇 Вася: мут 2 ч.\nПричина: флуд", cm_last_text($log));
});

test('без срока: мут — час, бан — навсегда; кик — бан и сразу разбан', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'мут @vasya');
    assert_eq(CM_NOW + 3600, cm_calls($log, 'restrictChatMember')[0][1]['until_date']);
    cm_do($ctx, 'бан @vasya');
    assert_eq(0, cm_calls($log, 'banChatMember')[0][1]['until_date']);
    assert_eq(CM_NOW, cm_member($pdo, CM_CHAT, 20)['left_at']);
    cm_do($ctx, 'кик @vasya');
    assert_eq(2, count(cm_calls($log, 'banChatMember')));
    assert_eq(true, cm_calls($log, 'unbanChatMember')[0][1]['only_if_banned']);
    cm_do($ctx, 'мут @vasya 10с');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
});

test('нельзя трогать равного, админа Telegram и бота', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_lvl($pdo, 30, 3);
    cm_person($pdo, 30, 'petya');
    cm_person($pdo, CM_ADMIN, 'admin');
    cm_do($ctx, 'бан @petya');
    cm_do($ctx, 'бан @admin');
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq(cm_t('target_admin'), cm_last_text($log));
});

test('варны копятся до лимита, потом наказание из триггера и обнуление', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya спам');
    assert_eq("⚠️ Вася: варн 1/3.\nПричина: спам", cm_last_text($log));
    cm_do($ctx, 'варн @vasya');
    cm_do($ctx, 'варн @vasya');
    $ban = cm_calls($log, 'banChatMember')[0][1];
    assert_eq(CM_NOW + 604800, $ban['until_date']);
    assert_eq('⚠️ Вася: варн 3/3 — лимит: бан 7 дн.', cm_last_text($log));
    assert_eq(0, cm_warns_active($ctx, 20));
});

test('истёкший варн не считается', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $pdo->prepare("INSERT INTO cm_punishments (chat_id, user_id, kind, reason, by_id, created_at) VALUES (:c, 20, 'warn', '', 10, :t)")
        ->execute([':c' => CM_CHAT, ':t' => CM_NOW - 2592001]);
    assert_eq(0, cm_warns_active($ctx, 20));
    cm_do($ctx, 'варн @vasya');
    assert_eq(1, cm_warns_active($ctx, 20));
});

test('-варн снимает последний, снять варны — все, варны показывает список', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya раз');
    cm_do($ctx, 'варн @vasya два');
    cm_do($ctx, 'варны');
    assert_eq(cm_t('warns_head', ['limit' => 3]) . "\n• Вася — 2/3", cm_last_text($log));
    cm_do($ctx, 'варны @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'два') !== false);
    cm_do($ctx, '-варн @vasya');
    assert_eq(1, cm_warns_active($ctx, 20));
    cm_do($ctx, 'снять варны @vasya');
    assert_eq(0, cm_warns_active($ctx, 20));
    cm_do($ctx, '-варн @vasya');
    assert_eq(cm_t('unwarn_none', ['name' => 'Вася']), cm_last_text($log));
});

test('размут и разбан снимают в Telegram и в записи; муты и баны — действующие', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'мут @vasya 1ч');
    cm_do($ctx, 'муты');
    assert_eq(true, strpos(cm_last_text($log), 'Вася') !== false);
    cm_do($ctx, 'размут @vasya');
    assert_eq(true, cm_calls($log, 'restrictChatMember')[1][1]['permissions']['can_send_messages']);
    cm_do($ctx, 'муты');
    assert_eq(cm_t('mutes_head') . "\n" . cm_t('list_empty'), cm_last_text($log));
    cm_do($ctx, 'бан @vasya');
    cm_do($ctx, 'разбан @vasya');
    assert_eq(1, count(cm_calls($log, 'unbanChatMember')));
    $open = (int)$pdo->query("SELECT COUNT(*) FROM cm_punishments WHERE lifted_at = 0 AND kind IN ('mute', 'ban')")->fetchColumn();
    assert_eq(0, $open);
});

test('лимит, срок и наказание варнов настраиваются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варны лимит 2');
    cm_do($ctx, 'варны срок 7 дней');
    cm_do($ctx, 'варны наказание мут 1д');
    $s = cm_chat_row($pdo, CM_CHAT)['settings'];
    assert_eq([2, 604800, [['kind' => 'mute', 'secs' => 86400]]], [$s['warns']['limit'], $s['warns']['ttl'], $s['triggers']['warns']]);
    cm_do($ctx, 'варны наказание варн');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'варны лимит 50');
    assert_eq(cm_t('warn_limit_usage'), cm_last_text($log));
});

test('разбор действий триггера', function () {
    assert_eq([['kind' => 'delete', 'secs' => 0], ['kind' => 'mute', 'secs' => 3600]], cm_parse_actions('удалить, мут', false));
    assert_eq([['kind' => 'ban', 'secs' => 604800]], cm_parse_actions('Бан 7 дней', false));
    assert_eq(null, cm_parse_actions('удалить, варн, кик, бан', false), 'больше трёх');
    assert_eq(null, cm_parse_actions('расстрел', false));
    assert_eq(null, cm_parse_actions('варн', true));
    assert_eq(null, cm_parse_actions('кик 5м', false));
    assert_eq(null, cm_parse_actions('', false));
    assert_eq('удалить, мут 1 ч', cm_actions_text(cm_parse_actions('удалить, мут', false)));
});

test('нет прав у бота — сообщение о правах, записи нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $deny = ['restrictChatMember' => ['ok' => false, 'description' => 'Bad Request: not enough rights to restrict/unrestrict chat member']];
    $ctx = pn_mod($pdo, $log, $deny);
    cm_do($ctx, 'мут @vasya');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_punishments')->fetchColumn());
    assert_eq(cm_t('need_rights'), cm_last_text($log));
});

test('действия триггера: удаление первым, итог без удаления', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $done = cm_apply_actions($ctx, 20, 555, [['kind' => 'mute', 'secs' => 600], ['kind' => 'delete', 'secs' => 0]], 'тест', 0);
    assert_eq(['deleteMessage', 'restrictChatMember'], [$log[count($log) - 2][0], $log[count($log) - 1][0]]);
    assert_eq('мут 10 мин', $done);
    assert_eq('', cm_apply_actions($ctx, 20, 556, [['kind' => 'delete', 'secs' => 0]], 'тест', 0));
});

run_tests();
