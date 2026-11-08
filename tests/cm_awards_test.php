<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Награды (этап 2): api/lib/cm/awards.php.

function aw_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM cm_awards')->fetchColumn();
}

test('+награда: степень, причина, срок «на 30 дней»; сообщение постоянное', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+награда 5 @petya Лучший трейдер');
    assert_eq('🥇 Петя получает награду 5-й степени: Лучший трейдер', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    cm_do($ctx, '+награда 2 @petya на 30 дней Помощь новичкам');
    assert_eq('🏅 Петя получает награду 2-й степени: Помощь новичкам (до ' . cm_date(CM_NOW + 2592000) . ' МСК)', cm_last_text($log));
    cm_do($ctx, '+награда 1 @petya на память');
    assert_eq('🎗 Петя получает награду 1-й степени: на память', cm_last_text($log), '«на» без срока — часть причины');
    $r = $pdo->query('SELECT level, reason, by_id, until_at FROM cm_awards ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[5, 'Лучший трейдер', CM_OWNER, 0], [2, 'Помощь новичкам', CM_OWNER, CM_NOW + 2592000], [1, 'на память', CM_OWNER, 0]],
        array_map(function ($x) { return [(int)$x[0], (string)$x[1], (int)$x[2], (int)$x[3]]; }, $r));
});

test('+награда ответом; подсказки и отказы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $reply), '+награда 8 Легенда чата');
    assert_eq('👑 Петя получает награду 8-й степени: Легенда чата', cm_last_text($log));
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', $reply);
    foreach (['+награда 9 причина', '+награда 3', '+награда причина', '+награда 3 ' . str_repeat('я', 101)] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('award_usage'), cm_last_text($log), $bad);
    }
    cm_do($ctx, '+награда 3 на 400 дней причина');
    assert_eq(cm_t('term_bad'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+награда 3 причина');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => CM_OWNER, 'first_name' => 'Owner']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $self), '+награда 3 себе');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 72, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $bot), '+награда 3 боту');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    assert_eq(1, aw_count($pdo));
});

test('не больше 20 действующих наград у человека', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $ins = $pdo->prepare("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at, until_at, removed_at)
        VALUES (:c, 20, 1, 'x', 1, 0, :u, :r)");
    for ($i = 0; $i < 19; $i++) { $ins->execute([':c' => CM_CHAT, ':u' => 0, ':r' => 0]); }
    $ins->execute([':c' => CM_CHAT, ':u' => CM_NOW - 1, ':r' => 0]);   // истекла — не считается
    $ins->execute([':c' => CM_CHAT, ':u' => 0, ':r' => CM_NOW - 1]);   // снята — не считается
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+награда 1 @petya двадцатая');
    assert_eq(0, strpos(cm_last_text($log), '🎗 Петя получает'));
    cm_do($ctx, '+награда 1 @petya двадцать первая');
    assert_eq(cm_t('award_full', ['name' => 'Петя', 'max' => 20]), cm_last_text($log));
});

test('награды: свои и чужие, по убыванию степени, с номерами; истёкшая не видна', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 2 @petya Помощь новичкам');
    cm_do($o, '+награда 5 @petya на 1 день Лучший трейдер');
    cm_do($o, '+награда 3 @petya Старожил');
    cm_do(cm_ctx_for($pdo, $log, 7), 'награды @petya');
    assert_eq("🏅 Награды Петя:\n#2 🥇 Лучший трейдер (до " . cm_date(CM_NOW + 86400) . " МСК)\n#3 🥉 Старожил\n#1 🏅 Помощь новичкам",
        cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], CM_NOW + 86400), 'награды');
    assert_eq("🏅 Награды U20:\n#3 🥉 Старожил\n#1 🏅 Помощь новичкам", cm_last_text($log), 'через сутки срочная пропала');
    cm_do(cm_ctx_for($pdo, $log, 7), 'награды');
    assert_eq(cm_t('awards_none', ['name' => 'U7']), cm_last_text($log));
});

test('-награда N снимает; чужой номер и повтор — «нет такой»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 4 @petya Серебро');
    $pdo->exec("INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at) VALUES (-999, 20, 1, 'чужая', 1, 0)");
    cm_do($o, '-награда 1');
    assert_eq(cm_t('award_removed', ['id' => 1]), cm_last_text($log));
    assert_eq(CM_NOW, (int)$pdo->query('SELECT removed_at FROM cm_awards WHERE id = 1')->fetchColumn());
    cm_do($o, '-награда #1');
    assert_eq(cm_t('award_missing', ['id' => 1]), cm_last_text($log));
    cm_do($o, '-награда 2');
    assert_eq(cm_t('award_missing', ['id' => 2]), cm_last_text($log), 'награда другого чата');
    assert_eq([], cm_awards_active($pdo, CM_CHAT, 20, CM_NOW));
});

test('кратко для профиля: пять старших и «и ещё N»', function () {
    $list = [];
    foreach ([8, 7, 6, 5, 4, 3, 2] as $i => $lvl) { $list[] = ['id' => $i + 1, 'level' => $lvl, 'reason' => 'н' . $lvl, 'until_at' => 0]; }
    assert_eq('👑 н8, 🏆 н7, 🎖 н6, 🥇 н5, 🥈 н4 и ещё 2', cm_awards_brief($list));
    assert_eq('🥉 н3', cm_awards_brief([$list[5]]));
});

test('ранг: выдают с 3, «дк награды 2» — с 2; смотрят все и без префикса', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_lvl($pdo, 10, 2);
    $run = function (int $actor, string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, $actor, $text, $extra), cm_parse($text, CM_BOT));
    };
    $run(10, '!+награда 3 @petya рано');
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк награды 2');
    $run(10, '+награда 3 @petya вовремя');
    assert_eq('🥉 Петя получает награду 3-й степени: вовремя', cm_last_text($log));
    $run(10, '!-награда 1');
    assert_eq(cm_t('award_removed', ['id' => 1]), cm_last_text($log), 'снимает тот же ранг, что выдаёт');
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    assert_eq(true, $run(7, 'награды', $reply));
    assert_eq(false, $run(7, 'награды раздали'));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк награды 6');
    assert_eq(true, $run(7, 'награды'), 'выключили выдачу — смотреть можно');
});

test('нет таблиц этапа 2 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'награды');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
