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
    assert_eq("⚠️ Вася: варн 1/5 на 30 дней.\nПричина: спам", cm_last_text($log), 'без срока — срок чата, месяц; лимит по умолчанию 5');
    cm_do($ctx, 'варн @vasya');
    cm_do($ctx, 'варн @vasya');
    cm_do($ctx, 'варн @vasya');
    assert_eq('⚠️ Вася: варн 4/5 на 30 дней.', cm_last_text($log));
    assert_eq(0, count(cm_calls($log, 'banChatMember')), 'до лимита не банит');
    cm_do($ctx, 'варн @vasya');
    $ban = cm_calls($log, 'banChatMember')[0][1];
    assert_eq(CM_NOW + 604800, $ban['until_date']);
    assert_eq('⚠️ Вася: варн 5/5 — лимит: бан 7 дней', cm_last_text($log));
    assert_eq(0, cm_warns_active($ctx, 20));
});

test('временный варн: свой срок решает, сам перестаёт считаться', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya 3 дня флуд');
    assert_eq("⚠️ Вася: варн 1/5 на 3 дня.\nПричина: флуд", cm_last_text($log));
    $row = $pdo->query("SELECT until_at, reason FROM cm_punishments WHERE kind = 'warn'")->fetch(PDO::FETCH_ASSOC);
    assert_eq([CM_NOW + 3 * 86400, 'флуд'], [(int)$row['until_at'], $row['reason']]);
    cm_do($ctx, 'варн @vasya 2ч');
    assert_eq('⚠️ Вася: варн 2/5 на 2 ч.', cm_last_text($log));
    cm_do($ctx, 'варн @vasya 2 раза просили');
    assert_eq("⚠️ Вася: варн 3/5 на 30 дней.\nПричина: 2 раза просили", cm_last_text($log), 'голое число — причина, не срок');

    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варн @vasya 1ч');
    cm_do($ctx, 'варн @vasya');
    assert_eq(2, cm_warns_active($ctx, 20));
    $later = cm_ctx_for($pdo, $log, 10, '', [], [], CM_NOW + 3601);
    assert_eq(1, cm_warns_active($later, 20), 'час прошёл — временный сам снялся');
    cm_do($later, 'варны @vasya');
    assert_eq(1, substr_count(cm_last_text($log), '•'), 'в списке — только действующий');
    assert_eq(true, strpos(cm_last_text($log), '(до ') !== false, 'и до какого числа');
    $month = cm_ctx_for($pdo, $log, 10, '', [], [], CM_NOW + 2592001);
    assert_eq(0, cm_warns_active($month, 20), 'обычный — через месяц');

    $pdo = cm_test_pdo();
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, 'варны срок 1 день');
    cm_do(pn_mod($pdo, $log), 'варн @vasya 10 дней');
    assert_eq(1, cm_warns_active(cm_ctx_for($pdo, $log, 10, '', [], [], CM_NOW + 5 * 86400), 20), 'срок модератора длиннее срока чата — решает он');
    foreach (['варн @vasya навсегда', 'варн @vasya 400 дней', 'варн @vasya 10с'] as $bad) {
        cm_do(pn_mod($pdo, $log), $bad);
        assert_eq(cm_t('warn_term_bad', ['term' => 'на 1 день']), cm_last_text($log), $bad);
    }
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
    assert_eq(cm_t('warns_head', ['limit' => 5]) . "\n• Вася — 2/5", cm_last_text($log));
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

test('срок перед ником, ссылкой или id; причина строкой ниже; без точки — тоже команда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, "Бан 1 месяц https://t.me/Vasya\nНет революции");
    $b = cm_calls($log, 'banChatMember')[0][1];
    assert_eq([20, CM_NOW + 2592000], [$b['user_id'], $b['until_date']]);
    assert_eq("⛔ Вася: бан 30 дней.\nПричина: Нет революции", cm_last_text($log));
    cm_do($ctx, 'мут 2ч @vasya');
    assert_eq(CM_NOW + 7200, cm_calls($log, 'restrictChatMember')[0][1]['until_date']);
    cm_person($pdo, 123456789, 'creo', 'Creo');
    cm_do($ctx, 'бан навсегда 123456789 спам');
    assert_eq([123456789, 0], [cm_calls($log, 'banChatMember')[1][1]['user_id'], cm_calls($log, 'banChatMember')[1][1]['until_date']]);
    assert_eq("⛔ Creo: бан навсегда.\nПричина: спам", cm_last_text($log));
    $a = '1 месяц https://t.me/vasya';
    assert_eq(20, cm_target($pdo, [], $a)['id']);
    assert_eq('1 месяц', $a, 'срок остаётся в аргументах');
    $a = '2 @vasya';
    cm_target($pdo, [], $a);
    assert_eq('2', $a, 'ранг перед целью — как раньше');
    $text = "Бан 1 месяц https://t.me/vasya\nНет революции";
    assert_eq(true, cm_run_command(cm_ctx_for($pdo, $log, 10, $text), cm_parse($text, CM_BOT)));
});

test('незнакомый ник: бан ждёт и встаёт, когда ник зайдёт; срок — от команды', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    cm_do($ctx, "бан 1 месяц https://t.me/lKpeo\nНет революции");
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq("⏳ @lkpeo мне ещё не встречался. Бан 30 дней встанет сам, как только этот ник зайдёт или напишет в чат.\nПричина: Нет революции",
        cm_last_text($log));
    cm_do($ctx, 'баны');
    assert_eq("В бане:\n• @lkpeo — ждёт входа, до " . cm_date(CM_NOW + 2592000), cm_last_text($log));
    $log = [];
    $join = cm_gmsg(77, '', ['new_chat_members' => [['id' => 77, 'is_bot' => false, 'first_name' => 'Creo', 'username' => 'LKpeo']]]);
    unset($join['text']);
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => $join], CM_NOW + 3600);
    $b = cm_calls($log, 'banChatMember')[0][1];
    assert_eq([77, CM_NOW + 2592000], [$b['user_id'], $b['until_date']], 'срок от команды, а не от входа');
    assert_eq('⛔ Creo: бан до ' . cm_date(CM_NOW + 2592000) . " — ждал по нику @lkpeo.\nПричина: Нет революции", cm_texts($log)[0]);
    $row = $pdo->query("SELECT user_id, reason, by_id FROM cm_punishments WHERE kind = 'ban'")->fetch(PDO::FETCH_ASSOC);
    assert_eq([77, 'Нет революции', 10], [(int)$row['user_id'], $row['reason'], (int)$row['by_id']]);
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['ban_nicks'], 'запись ушла');
});

test('незнакомый ник без точки, как в живом чате: модератору — ожидание, участнику — «нужен ранг»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 3);
    $text = "Бан 1 месяц https://t.me/lKpeo\nНет революции";
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => cm_gmsg(10, $text)], CM_NOW);
    assert_eq(0, count(cm_calls($log, 'deleteMessage')), 'ссылку модератора фильтр не трогает');
    assert_eq('lkpeo', cm_chat_row($pdo, CM_CHAT)['settings']['ban_nicks'][0]['nick']);
    $log = [];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => cm_gmsg(11, 'бан @someone_new')], CM_NOW);
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    assert_eq(1, count(cm_chat_row($pdo, CM_CHAT)['settings']['ban_nicks']));
    $log = [];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => cm_gmsg(10, 'мут @someone_new')], CM_NOW);
    assert_eq([], cm_texts($log), 'мут незнакомого без точки — обычная речь, как раньше');
});

test('ждущий ник написал — бан и сообщение удалено; разбан и истёкший срок снимают ожидание', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $from = function (int $id, string $nick): array {
        return ['from' => ['id' => $id, 'is_bot' => false, 'first_name' => 'U' . $id, 'username' => $nick]];
    };
    cm_do($ctx, 'бан @creo_x спам');
    assert_eq("⏳ @creo_x мне ещё не встречался. Бан навсегда встанет сам, как только этот ник зайдёт или напишет в чат.\nПричина: спам",
        cm_last_text($log));
    $log = [];
    $m = cm_gmsg(78, 'привет', $from(78, 'Creo_X'));
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => $m], CM_NOW);
    assert_eq([78, 0], [cm_calls($log, 'banChatMember')[0][1]['user_id'], cm_calls($log, 'banChatMember')[0][1]['until_date']]);
    assert_eq($m['message_id'], cm_calls($log, 'deleteMessage')[0][1]['message_id']);
    cm_do($ctx, 'бан 1 день @ghost_one');
    cm_do($ctx, 'разбан @ghost_one');
    assert_eq('@ghost_one: бан по нику снят.', cm_last_text($log));
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['ban_nicks']);
    cm_do($ctx, 'бан 1 день @late_one');
    $log = [];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => cm_gmsg(79, 'привет', $from(79, 'late_one'))], CM_NOW + 86400 + 10);
    assert_eq(0, count(cm_calls($log, 'banChatMember')), 'срок вышел — бан не встаёт');
    cm_do($ctx, 'бан 2 года @far_one');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
    cm_do($ctx, 'мут @nobody_here');
    assert_eq(cm_t('target_unknown'), cm_last_text($log), 'ждёт только бан');
});

test('ник из лички бота (tg_people) знаком: бан сразу', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = pn_mod($pdo, $log);
    $pdo->exec("CREATE TABLE IF NOT EXISTS tg_people (chat_id INTEGER PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
        username TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', roblox_nick TEXT NOT NULL DEFAULT '',
        await_gw INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL)");
    $pdo->exec("INSERT INTO tg_people (chat_id, name, username, updated_at) VALUES (555, 'Гость', 'Ghost_Two', 1)");
    cm_do($ctx, 'бан 7 дней @ghost_two');
    assert_eq([555, CM_NOW + 604800], [cm_calls($log, 'banChatMember')[0][1]['user_id'], cm_calls($log, 'banChatMember')[0][1]['until_date']]);
    assert_eq('⛔ Гость: бан 7 дней.', cm_last_text($log));
});

run_tests();
