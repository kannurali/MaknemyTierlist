<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Статистика (этап 2): api/lib/cm/stats.php, команды без префикса
// (CM_BARE_ARGS в parse.php), переезд группы вместе с таблицами этапа 2.

function st_put(PDO $pdo, int $userId, int $day, int $msgs, int $chatId = CM_CHAT): void {
    $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)')
        ->execute([':c' => $chatId, ':u' => $userId, ':d' => $day, ':m' => $msgs]);
}

test('таблицы этапа 2: есть — готово, нет — нет, этап 1 при этом жив', function () {
    assert_eq(true, cm_ready2(cm_test_pdo()));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    assert_eq(false, cm_ready2($pdo));
    assert_eq(true, cm_ready($pdo));
    cm_stats_hit($pdo, CM_CHAT, 7, CM_NOW);
    assert_eq(false, cm_ready2($pdo), 'счётчик без таблиц молчит, без исключения');
});

test('сутки по Москве: 23:59 и 00:01 — разные дни', function () {
    $msk = function (int $ts): string {
        $d = new DateTime('@' . $ts);
        $d->setTimezone(new DateTimeZone('Europe/Moscow'));
        return $d->format('Y-m-d H:i');
    };
    assert_eq('2026-09-21 23:59', $msk(CM_T2359));
    assert_eq('2026-09-22 00:01', $msk(CM_T0001));
    assert_eq(20260921, cm_day(CM_T2359));
    assert_eq(20260922, cm_day(CM_T0001));
    $pdo = cm_test_pdo();
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T2359);
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T2359);
    assert_eq(2, cm_stats_msgs($pdo, CM_CHAT, 7, 1, CM_T2359), 'сегодня до полуночи');
    cm_stats_hit($pdo, CM_CHAT, 7, CM_T0001);
    assert_eq(1, cm_stats_msgs($pdo, CM_CHAT, 7, 1, CM_T0001), 'сегодня после полуночи');
    $rows = $pdo->query('SELECT day, msgs FROM cm_stats ORDER BY day')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[20260921, 2], [20260922, 1]], array_map(function ($r) { return [(int)$r[0], (int)$r[1]]; }, $rows));
    assert_eq(3, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
});

test('неделя и месяц — сегодня включительно, границы', function () {
    assert_eq(20260922, cm_day_from(CM_T0001, 1));
    assert_eq(20260916, cm_day_from(CM_T0001, 7));
    assert_eq(20260824, cm_day_from(CM_T0001, 30));
    assert_eq(20260915, cm_day_from(CM_T2359, 7));
    assert_eq(0, cm_day_from(CM_T0001, 0));
    $pdo = cm_test_pdo();
    st_put($pdo, 7, 20260922, 1);
    st_put($pdo, 7, 20260916, 10);     // 7-й день — ещё неделя
    st_put($pdo, 7, 20260915, 100);    // 8-й — уже нет
    st_put($pdo, 7, 20260824, 1000);   // 30-й — ещё месяц
    st_put($pdo, 7, 20260823, 10000);  // 31-й — только «всё»
    assert_eq(11, cm_stats_msgs($pdo, CM_CHAT, 7, 7, CM_T0001));
    assert_eq(1111, cm_stats_msgs($pdo, CM_CHAT, 7, 30, CM_T0001));
    assert_eq(11111, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
    assert_eq(20260823, cm_stats_first_day($pdo, CM_CHAT, 7));
    assert_eq(0, cm_stats_first_day($pdo, CM_CHAT, 8));
    assert_eq('23.08.2026', cm_day_text(20260823));
    assert_eq('1 234 567', cm_num(1234567));
});

test('топ: ушедших нет, при равенстве — меньший id; место', function () {
    $pdo = cm_test_pdo();
    st_put($pdo, 7, 20260922, 5);
    st_put($pdo, 8, 20260922, 9);
    st_put($pdo, 9, 20260922, 5);
    st_put($pdo, 10, 20260922, 50);
    cm_member_set($pdo, CM_CHAT, 10, ['left_at' => CM_NOW]);
    assert_eq([['user_id' => 8, 'msgs' => 9], ['user_id' => 7, 'msgs' => 5], ['user_id' => 9, 'msgs' => 5]],
        cm_stats_top($pdo, CM_CHAT, 1, CM_T0001, 10));
    assert_eq(2, count(cm_stats_top($pdo, CM_CHAT, 1, CM_T0001, 2)));
    assert_eq(69, cm_stats_total($pdo, CM_CHAT, 1, CM_T0001), 'всего — вместе с ушедшими');
    assert_eq(1, cm_stats_place($pdo, CM_CHAT, 8, 0, CM_T0001));
    assert_eq(3, cm_stats_place($pdo, CM_CHAT, 9, 0, CM_T0001));
    assert_eq(0, cm_stats_place($pdo, CM_CHAT, 10, 0, CM_T0001), 'ушёл — без места');
    assert_eq(0, cm_stats_place($pdo, CM_CHAT, 11, 0, CM_T0001), 'не писал — без места');
    assert_eq([], cm_stats_top($pdo, -999, 0, CM_T0001, 10), 'чужой чат');
});

test('переезд группы уносит статистику, репутацию и награды', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    st_put($pdo, 7, 20260921, 3, -500);
    st_put($pdo, 7, 20260921, 1);   // успело появиться у нового id — уступает старому
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (-500, 7, 4)');
    $pdo->exec('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (-500, 8, 7, 1, 0)');
    $pdo->exec("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at) VALUES (-500, 7, 5, 'за дело', 1, 0)");
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(3, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_T0001));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_stats WHERE chat_id = -500')->fetchColumn());
    foreach (['cm_rep', 'cm_rep_log', 'cm_awards'] as $t) {
        assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = " . CM_CHAT)->fetchColumn(), $t);
    }
});

test('переезд без таблиц этапа 2 — как раньше', function () {
    $pdo = cm_test_pdo();
    foreach (['cm_stats', 'cm_rep', 'cm_rep_log', 'cm_awards'] as $t) { $pdo->exec("DROP TABLE $t"); }
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    cm_member_set($pdo, -500, 7, ['lvl' => 2]);
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(2, cm_member($pdo, CM_CHAT, 7)['lvl']);
});

test('стата: разбор числа и периода', function () {
    assert_eq([10, 1], cm_stats_args(''));
    assert_eq([20, 1], cm_stats_args('20'));
    assert_eq([10, 7], cm_stats_args('неделя'));
    assert_eq([15, 30], cm_stats_args('15 месяц'));
    assert_eq([15, 30], cm_stats_args('месяц 15'));
    assert_eq([10, 0], cm_stats_args('всё'));
    assert_eq([10, 0], cm_stats_args('ВСЕ'));
    assert_eq(null, cm_stats_args('0'));
    assert_eq(null, cm_stats_args('51'));
    assert_eq(null, cm_stats_args('норм'));
    assert_eq(null, cm_stats_args('за неделю'));
});

test('стата: топ за период, общее число, пусто', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'vasya', 'Вася');
    cm_person($pdo, 8, 'petya', 'Петя');
    st_put($pdo, 7, 20260922, 1200);
    st_put($pdo, 8, 20260916, 34);
    st_put($pdo, 8, 20260915, 1000);   // за пределами недели
    $ctx = cm_ctx_for($pdo, $log, 30, '', [], [], CM_T0001);
    cm_do($ctx, 'стата неделя');
    assert_eq("📊 Активность за неделю (всего 1 234):\n1. Вася — 1 200\n2. Петя — 34", cm_last_text($log));
    cm_do($ctx, 'стата 1');
    assert_eq("📊 Активность за сегодня (всего 1 200):\n1. Вася — 1 200", cm_last_text($log));
    cm_do($ctx, 'стата всё');
    assert_eq("📊 Активность за всё время (всего 2 234):\n1. Вася — 1 200\n2. Петя — 1 034", cm_last_text($log));
    cm_do($ctx, '!стата норм');
    assert_eq(cm_t('stats_usage'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 30, '', [], [], CM_T0001 + 86400), 'стата');
    assert_eq(cm_t('stats_empty', ['period' => 'сегодня']), cm_last_text($log));
});

test('моя стата: своя, чужая ответом, незнакомый ник', function () {
    $pdo = cm_test_pdo();
    $log = [];
    st_put($pdo, 7, 20260922, 15);
    st_put($pdo, 7, 20260916, 5);
    st_put($pdo, 7, 20260901, 80);
    st_put($pdo, 7, 20260101, 900);
    st_put($pdo, 8, 20260922, 2000);
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], [], CM_T0001);
    cm_do($ctx, 'моя стата');
    assert_eq("📊 U7\nСегодня: 15 · неделя: 20 · месяц: 100 · всего: 1 000\nМесто в топе за всё время: 2", cm_last_text($log));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 8, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply, [], CM_T0001), 'моя стата');
    assert_eq(0, strpos(cm_last_text($log), "📊 Петя\nСегодня: 2 000"));
    cm_do($ctx, '!моя стата @nobody1');
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
});

test('без префикса: «стата неделя» и «моя стата» ответом — команды, «стата норм» — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        $ctx = cm_ctx_for($pdo, $log, 7, $text, $extra, [], CM_T0001);
        return cm_run_command($ctx, cm_parse($text, CM_BOT));
    };
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 8, 'first_name' => 'Петя']]];
    assert_eq(true, $run('стата неделя'));
    assert_eq(true, $run('стата 15 месяц'));
    assert_eq(true, $run('моя стата', $reply));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(false, $run('стата норм'));
    assert_eq(false, $run('моя стата лучше твоей'));
    assert_eq(false, $run("стата\nи ещё строка"));
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'обычная речь — без ответа');
    assert_eq(true, $run('!стата норм'), 'с префиксом — команда, в ответ подсказка');
});

test('дк статистика 6 выключает стату', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк статистика 6');
    assert_eq(cm_t('access_done', ['section' => 'статистика', 'level' => 6]), cm_last_text($log));
    $ctx = cm_ctx_for($pdo, $log, 7, '!стата');
    assert_eq(true, cm_run_command($ctx, cm_parse('!стата', CM_BOT)));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
});

test('нет таблиц этапа 2 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'стата');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'моя стата');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
