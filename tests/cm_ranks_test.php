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

// Ранги общие для чатов Макнеми (решение владельца 08.11.2026): выдал в одном —
// действует во всех. Создатель (5), варны, «дк» и админы Telegram — по чату.
const RK_TRADE = -1005550011;
const RK_ALIEN = -1005550012;

function rk_ctx(PDO $pdo, array &$log, int $actor, int $chatId): array {
    $title = $chatId === RK_TRADE ? 'Трейд' : ($chatId === RK_ALIEN ? 'Чужой' : 'Тестовый чат');
    return cm_ctx_for($pdo, $log, $actor, '', ['chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $title]]);
}

test('сеть: ранг из одного чата Макнеми действует во всех, в чужом — нет', function () {
    $pdo = cm_test_pdo();
    cm_seed_chat($pdo, RK_TRADE, true);
    cm_seed_chat($pdo, RK_ALIEN, false);
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+модер 2 @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'во всех чатах Maknemy') !== false, 'ответ говорит, что везде');
    assert_eq(2, cm_rank(rk_ctx($pdo, $log, 99, RK_TRADE), 20), 'в трейд-чате тот же ранг');
    assert_eq(true, cm_exempt(rk_ctx($pdo, $log, 99, RK_TRADE), 20), 'фильтры трейд-чата его не трогают');
    assert_eq(0, cm_rank(rk_ctx($pdo, $log, 99, RK_ALIEN), 20), 'в чужом чате ранга нет');
    cm_do(rk_ctx($pdo, $log, CM_OWNER, RK_TRADE), 'понизить @vasya');
    assert_eq(1, cm_rank(cm_ctx_for($pdo, $log, 99), 20), 'понизили в трейд-чате — ниже и в главном');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '-модер @vasya');
    assert_eq(0, cm_rank(rk_ctx($pdo, $log, 99, RK_TRADE), 20), 'сняли — нигде нет');
});

test('сеть: модер с рангом из другого чата выдаёт ранги здесь', function () {
    $pdo = cm_test_pdo();
    cm_seed_chat($pdo, RK_TRADE, true);
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    cm_person($pdo, 21, 'petya', 'Петя');
    cm_lvl($pdo, 20, 4);
    cm_do(rk_ctx($pdo, $log, 20, RK_TRADE), '+модер 3 @petya');
    assert_eq(3, cm_rank(cm_ctx_for($pdo, $log, 99), 21));
    cm_do(rk_ctx($pdo, $log, 20, RK_TRADE), '+модер 4 @petya');
    assert_eq(cm_t('rank_too_high'), cm_last_text($log), 'равный своему — по-прежнему нельзя');
});

test('сеть: модеры и журнал рангов одни на все чаты Макнеми', function () {
    $pdo = cm_test_pdo();
    cm_seed_chat($pdo, RK_TRADE, true);
    $log = [];
    cm_person($pdo, 20, 'vasya', 'Вася');
    cm_person($pdo, 21, 'petya', 'Петя');
    cm_person($pdo, 22, 'kolya', 'Коля');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+модер 3 @vasya');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+модер 3 @petya');
    cm_do(rk_ctx($pdo, $log, CM_OWNER, RK_TRADE), '+модер 1 @kolya');
    $trade = rk_ctx($pdo, $log, 99, RK_TRADE);
    cm_do($trade, 'модеры');
    assert_eq(cm_t('ranks_head') . "\n5 · Owner\n3 · Вася\n3 · Петя\n1 · Коля", cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 99), 'модеры');
    assert_eq(cm_t('ranks_head') . "\n5 · Owner\n3 · Вася\n3 · Петя\n1 · Коля", cm_last_text($log), 'из главного — тот же список');
    cm_do(rk_ctx($pdo, $log, CM_OWNER, RK_TRADE), 'журнал рангов');
    $j = cm_last_text($log);
    assert_eq(true, strpos($j, 'Вася 0 → 3') !== false && strpos($j, 'Коля 0 → 1') !== false, 'журнал из обоих чатов');
});

test('сеть: созыв и массовый кик видят ранг из другого чата', function () {
    $pdo = cm_test_pdo();
    cm_seed_chat($pdo, RK_TRADE, true);
    $log = [];
    cm_lvl($pdo, 20, 2);
    cm_member_set($pdo, RK_TRADE, 20, ['joined_at' => CM_NOW - 600, 'last_seen' => CM_NOW]);
    cm_lvl($pdo, 21, 1);
    cm_member_set($pdo, RK_TRADE, 22, ['joined_at' => CM_NOW - 600, 'last_seen' => CM_NOW]);
    $trade = rk_ctx($pdo, $log, 22, RK_TRADE);
    assert_eq([CM_OWNER, CM_ADMIN, 20], cm_call_ids($trade), 'зовёт модера сети, если он в этом чате; 21 тут нет');
    $own = rk_ctx($pdo, $log, CM_OWNER, RK_TRADE);
    assert_eq([22], cm_mass_candidates($own, 'n', 3600, 10), 'модер сети не попадает под кик новых');
});

run_tests();
