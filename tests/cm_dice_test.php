<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Кубы и исход проигравшему (этап 3): api/lib/cm/dice.php, offers.php.

function dc_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
}

// Ответы Telegram на sendDice: значения кубиков по очереди из $vals.
function dc_dice(array &$vals): array {
    return ['sendDice' => function (array $p) use (&$vals): array {
        return ['ok' => true, 'result' => ['message_id' => 7000, 'dice' => ['emoji' => '🎲', 'value' => array_shift($vals)]]];
    }];
}

test('кубы: оба броска ответом на приглашение, больше — победа, счёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $mid = cm_offer_get($pdo, 1)['msg_id'];
    $vals = [5, 3];
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals)));
    $dice = cm_calls($log, 'sendDice');
    assert_eq(2, count($dice));
    assert_eq(['🎲', $mid], [$dice[0][1]['emoji'], $dice[0][1]['reply_parameters']['message_id']]);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([$mid, "🎲 Кубы: U7 — 5, Петя — 3\n🏆 Победитель — U7!"], [$e[0][1]['message_id'], $e[0][1]['text']]);
    assert_eq(['wins' => 1, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'dice'));
    assert_eq(['wins' => 0, 'losses' => 1, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 20, 'dice'));
    assert_eq('yes', cm_offer_get($pdo, 1)['status']);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'итог — в том же сообщении');
});

test('кубы: ничья — без исхода, даже если он включён', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $vals = [4, 4];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    assert_eq("🎲 Кубы: U7 — 4, Петя — 4\n🤝 Ничья!", cm_calls($log, 'editMessageText')[0][1]['text']);
    assert_eq(['wins' => 0, 'losses' => 0, 'draws' => 1], cm_game_stat($pdo, CM_CHAT, 7, 'dice'));
    assert_eq(['wins' => 0, 'losses' => 0, 'draws' => 1], cm_game_stat($pdo, CM_CHAT, 20, 'dice'));
    assert_eq([], cm_calls($log, 'restrictChatMember'));
});

test('исход включён: ставка видна в приглашении, проигравшему — мут от бота', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    assert_eq('Проигравший в кубах получит: мут 10 мин.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq("🎲 Петя, U7 предлагает бросить кубы!\nСтавка: проигравшему — мут 10 мин.", cm_last_text($log));
    $vals = [2, 6];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    $r = cm_calls($log, 'restrictChatMember');
    assert_eq([1, 7, CM_NOW + 600], [count($r), $r[0][1]['user_id'], $r[0][1]['until_date']]);
    assert_eq("🎲 Кубы: U7 — 2, Петя — 6\n🏆 Победитель — Петя!\n⚖️ U7: мут 10 мин", cm_calls($log, 'editMessageText')[0][1]['text']);
    $p = $pdo->query("SELECT kind, reason, by_id FROM cm_punishments")->fetch(PDO::FETCH_NUM);
    assert_eq(['mute', 'проигрыш в кубах', 0], [$p[0], $p[1], (int)$p[2]]);
});

test('исход включён, но играет модератор или админ Telegram — игра без ставки', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_lvl($pdo, 20, 1);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кубы исход мут 10м');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq('🎲 Петя, U7 предлагает бросить кубы!', cm_last_text($log), 'без строки ставки');
    $vals = [6, 1];
    cm_press($pdo, $log, 20, 'cm:p:1:y', dc_dice($vals));
    assert_eq([], cm_calls($log, 'restrictChatMember'), 'модератор проиграл — без наказания');
    assert_eq("🎲 Кубы: U7 — 6, Петя — 1\n🏆 Победитель — U7!", cm_calls($log, 'editMessageText')[0][1]['text']);
    cm_do(cm_ctx_for($pdo, $log, CM_ADMIN), 'кубы @user7');
    assert_eq('🎲 U7, U2 предлагает бросить кубы!', cm_last_text($log), 'админ Telegram — тоже без ставки');
});

test('кубы исход: показать, включить, «ничего», ошибки, ранг 3', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'кубы исход');
    assert_eq(cm_t('outcome_off', ['game' => 'кубах']), cm_last_text($log));
    cm_do($o, 'кубы исход мут 10м, варн');
    assert_eq('Проигравший в кубах получит: мут 10 мин, варн.', cm_last_text($log));
    assert_eq([['kind' => 'mute', 'secs' => 600], ['kind' => 'warn', 'secs' => 0]],
        cm_chat_row($pdo, CM_CHAT)['settings']['dice_outcome']);
    cm_do($o, 'кубы исход');
    assert_eq('Проигравший в кубах получит: мут 10 мин, варн.', cm_last_text($log));
    foreach (['кубы исход удалить', 'кубы исход ерунда', 'кубы исход мут 400 дней'] as $bad) {
        cm_do($o, $bad);
        assert_eq(cm_t('outcome_usage'), cm_last_text($log), $bad);
    }
    cm_do($o, 'кубы исход ничего');
    assert_eq(cm_t('outcome_off', ['game' => 'кубах']), cm_last_text($log));
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['dice_outcome']);
    $ctx = cm_ctx_for($pdo, $log, 7, '!кубы исход мут 1ч');
    assert_eq(true, cm_run_command($ctx, cm_parse('!кубы исход мут 1ч', CM_BOT)));
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
});

test('Telegram не бросил кубик — приглашение закрыто, счёта нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $fail = ['sendDice' => ['ok' => false, 'description' => 'Bad Request: chat not found']];
    assert_eq(cm_t('dice_failed'), cm_press($pdo, $log, 20, 'cm:p:1:y', $fail));
    assert_eq('gone', cm_offer_get($pdo, 1)['status']);
    assert_eq(cm_t('dice_failed'), cm_calls($log, 'editMessageText')[0][1]['text']);
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_game_stats')->fetchColumn());
});

test('кубы без цели — топ: победы, поражения, ничьи; ушедших нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    $ins = $pdo->prepare("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, :d)");
    foreach ([[7, 'dice', 1, 3, 0], [20, 'dice', 3, 1, 2], [21, 'dice', 9, 0, 0], [22, 'dice', 0, 5, 1], [7, 'duel', 50, 0, 0]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':k' => $r[1], ':w' => $r[2], ':l' => $r[3], ':d' => $r[4]]);
    }
    cm_member_set($pdo, CM_CHAT, 21, ['left_at' => CM_NOW]);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы');
    assert_eq("🎲 Лучшие в кубах:\n1. Петя — 3 победы / 1 поражение / 2 ничьи\n2. U7 — 1 победа / 3 поражения / 0 ничьих",
        cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'кубы');
    assert_eq(cm_t('dice_top_empty'), cm_last_text($log));
});

test('кубы: себя, ботов и незнакомый ник — отказ; без префикса — только «кубы» и цель', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dc_people($pdo);
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'кубы');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'кубы');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), '!кубы @nobody1');
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('кубы @petya'));
    assert_eq(true, $run('кубы'));
    assert_eq(false, $run('кубы бросим?'));
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
