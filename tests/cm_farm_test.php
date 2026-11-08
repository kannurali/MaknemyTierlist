<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Ферма монет: api/lib/cm/farm.php, таблица cm_coins
// (docs/migrations/2026-11-08-cm-coins.sql).

function fm_run(PDO $pdo, array &$log, int $actor, string $text, array $extra = [], int $now = CM_NOW, ?callable $rand = null): bool {
    $ctx = cm_ctx_for($pdo, $log, $actor, $text, $extra, [], $now);
    if ($rand !== null) { $ctx['rand'] = $rand; }
    return cm_run_command($ctx, cm_parse($text, CM_BOT));
}

function fm_wallet(PDO $pdo, int $id): array {
    return array_values(cm_coins_get($pdo, $id));
}

test('урожай: от 10 до 160, края и пик на 50', function () {
    assert_eq(10, cm_farm_amount(0));
    assert_eq(160, cm_farm_amount(1000000));
    assert_eq(50, cm_farm_amount((int)round(40 / 150 * 1000000)), 'на границе двух половин — пик');
    assert_eq(10, cm_farm_amount(-5), 'вне диапазона — обрезается');
    assert_eq(160, cm_farm_amount(2000000));
    $prev = 0;
    for ($u = 0; $u <= 1000000; $u += 1000) {
        $x = cm_farm_amount($u);
        assert_true($x >= $prev, "не убывает: $u");
        $prev = $x;
    }
});

test('урожай: около 50 выпадает чаще, чем у краёв; в среднем ~73', function () {
    $hist = array_fill(10, 151, 0);
    $sum = 0;
    for ($u = 0; $u <= 1000000; $u += 10) {
        $x = cm_farm_amount($u);
        $hist[$x]++;
        $sum += $x;
    }
    $near = function (int $from, int $to) use ($hist): int {
        $s = 0;
        for ($i = $from; $i <= $to; $i++) { $s += $hist[$i]; }
        return $s;
    };
    $mid = $near(45, 55);
    assert_true($mid > 5 * $near(10, 20), 'у 50 в разы чаще, чем у 10');
    assert_true($mid > 5 * $near(150, 160), 'у 50 в разы чаще, чем у 160');
    $peak = array_search(max($hist), $hist, true);
    assert_true($peak >= 45 && $peak <= 55, "чаще всего — около 50, а не $peak");
    $avg = $sum / array_sum($hist);
    assert_true($avg > 70 && $avg < 77, "среднее $avg");
});

test('ферма: первый сбор — монеты в кошелёк, ответ постоянный; повтор раньше 4 часов — «растёт»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $half = cm_seq([(int)round(40 / 150 * 1000000)]);
    assert_eq(true, fm_run($pdo, $log, 7, 'ферма', [], CM_NOW, $half));
    assert_eq("🌾 U7 собрал(а) урожай: +50 🪙\nВ кошельке: 50 монет. Следующий сбор — через 4 ч.", cm_last_text($log));
    assert_eq([50, 1, CM_NOW], fm_wallet($pdo, 7));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    assert_eq(true, fm_run($pdo, $log, 7, 'ферма', [], CM_NOW + 4500, $half));
    assert_eq('⏳ Урожай ещё растёт: следующий сбор через 2 ч 45 мин. В кошельке: 50 монет.', cm_last_text($log));
    assert_eq([50, 1, CM_NOW], fm_wallet($pdo, 7), 'монеты не прибавились, время сбора прежнее');
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), '«растёт» удаляется через минуту');
    assert_eq(true, fm_run($pdo, $log, 7, '!ферма', [], CM_NOW + CM_FARM_EVERY - 30, $half));
    assert_eq('⏳ Урожай ещё растёт: следующий сбор через 1 мин. В кошельке: 50 монет.', cm_last_text($log));
    cm_profile_put($pdo, 7, 'gender', 'f', CM_NOW);
    assert_eq(true, fm_run($pdo, $log, 7, 'фарма', [], CM_NOW + CM_FARM_EVERY, cm_seq([1000000])));
    assert_eq("🌾 U7 собрала урожай: +160 🪙\nВ кошельке: 210 монет. Следующий сбор — через 4 ч.", cm_last_text($log));
    assert_eq([210, 2, CM_NOW + CM_FARM_EVERY], fm_wallet($pdo, 7));
});

test('ферма: кошелёк один во всех чатах Макнеми и у каждого свой', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $other = -1009999;
    cm_seed_chat($pdo, $other, true);
    $rand = cm_seq([0]);
    assert_eq(true, fm_run($pdo, $log, 7, 'ферма', [], CM_NOW, $rand));
    $m = ['chat' => ['id' => $other, 'type' => 'supergroup', 'title' => 'Трейды']];
    assert_eq(true, fm_run($pdo, $log, 7, 'ферма', $m, CM_NOW + 60, $rand));
    assert_eq(0, strpos(cm_last_text($log), '⏳ Урожай ещё растёт'), 'в другом чате — тот же таймер');
    assert_eq(true, fm_run($pdo, $log, 8, 'ферма', $m, CM_NOW + 60, $rand));
    assert_eq([10, 1, CM_NOW + 60], fm_wallet($pdo, 8));
    assert_eq([10, 1, CM_NOW], fm_wallet($pdo, 7));
});

test('монеты: свои, ответом и @ником; топ — самые богатые, ники тихие', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'vasya', 'Вася');
    cm_person($pdo, 8, 'petya', 'Петя');
    cm_person($pdo, 9, 'kolya', 'Коля');
    assert_eq(true, fm_run($pdo, $log, 7, 'монеты'));
    assert_eq('🪙 U7: 0 монет', cm_last_text($log), 'ещё не собирал — ноль');
    assert_eq(true, fm_run($pdo, $log, 7, 'топ монет'));
    assert_eq(cm_t('coins_top_empty'), cm_last_text($log));
    $pdo->exec('INSERT INTO cm_coins (user_id, coins, farms, farm_at) VALUES (7, 21, 1, 0), (8, 1342, 20, 0), (9, 3, 1, 0)');
    assert_eq(true, fm_run($pdo, $log, 7, 'баланс'));
    assert_eq('🪙 U7: 21 монета', cm_last_text($log));
    assert_eq(true, fm_run($pdo, $log, 7, 'монеты @petya'));
    assert_eq('🪙 Петя: 1 342 монеты', cm_last_text($log));
    assert_eq(true, fm_run($pdo, $log, 7, 'монеты', ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 9, 'first_name' => 'Коля']]]));
    assert_eq('🪙 Коля: 3 монеты', cm_last_text($log));
    assert_eq(true, fm_run($pdo, $log, 7, 'монеты', ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]]));
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    assert_eq(true, fm_run($pdo, $log, 7, 'топ монет'));
    assert_eq("🪙 Самые богатые:\n1. Петя — 1 342 монеты\n2. Вася — 21 монета\n3. Коля — 3 монеты", cm_last_text($log));
    $send = cm_calls($log, 'sendMessage');
    $types = array_column($send[count($send) - 1][1]['entities'] ?? [], 'type');
    assert_eq(false, in_array('text_mention', $types, true), 'топ никого не будит');
});

test('профиль показывает монеты, если они есть и ферма включена', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(true, fm_run($pdo, $log, 7, 'профиль'));
    assert_eq(false, strpos(cm_last_text($log), 'Монеты:'), 'ноль — без строки');
    $pdo->exec('INSERT INTO cm_coins (user_id, coins, farms, farm_at) VALUES (7, 74, 1, 0)');
    assert_eq(true, fm_run($pdo, $log, 7, 'профиль'));
    assert_eq(true, strpos(cm_last_text($log), 'Монеты: 74 🪙') !== false);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк ферма 6');
    assert_eq(true, fm_run($pdo, $log, 7, 'профиль'));
    assert_eq(false, strpos(cm_last_text($log), 'Монеты:'), 'ферма выключена — строки нет');
    assert_eq(false, fm_run($pdo, $log, 7, '!ферма'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
});

test('без префикса: «ферма», «монеты», «топ монет» — только ровно команда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 8, 'petya', 'Петя');
    foreach (['ферма', 'фарма', 'монеты', 'баланс', 'монеты @petya', 'топ монет'] as $text) {
        assert_eq(true, fm_run($pdo, $log, 7, $text), $text);
    }
    $n = count(cm_calls($log, 'sendMessage'));
    foreach (['ферма у бабушки', 'монеты кончились', 'баланс сил', 'топ монет сегодня', "ферма\nещё"] as $text) {
        assert_eq(false, fm_run($pdo, $log, 7, $text), $text);
    }
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'обычная речь — без ответа');
});

test('нет таблицы cm_coins — «ещё не настроено», профиль без строки', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_coins');
    $log = [];
    assert_eq(false, cm_ready_coins($pdo));
    foreach (['ферма', 'монеты', 'топ монет'] as $text) {
        assert_eq(true, fm_run($pdo, $log, 7, $text), $text);
        assert_eq(cm_t('not_ready2'), cm_last_text($log), $text);
    }
    assert_eq(true, fm_run($pdo, $log, 7, 'профиль'));
    assert_eq(false, strpos(cm_last_text($log), 'Монеты:'));
});

test('ожидание: часы и минуты', function () {
    assert_eq('4 ч', cm_wait_text(14400));
    assert_eq('2 ч 45 мин', cm_wait_text(9900));
    assert_eq('1 мин', cm_wait_text(1));
    assert_eq('59 мин', cm_wait_text(3540));
    assert_eq(cm_t('wait_now'), cm_wait_text(0));
});

run_tests();
