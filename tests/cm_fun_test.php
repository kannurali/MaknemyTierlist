<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Случайные команды и РП (этап 3): api/lib/cm/fun.php, CM_PREFIX_ONLY для
// «!кто» (parse.php, router.php), переезд группы с таблицами этапа 3.

// Сообщение через проверку команды, как в роутере: true — это команда.
function fn_run(PDO $pdo, array &$log, int $actor, string $text, array $extra = []): bool {
    return cm_run_command(cm_ctx_for($pdo, $log, $actor, $text, $extra), cm_parse($text, CM_BOT));
}

test('таблицы этапа 3: есть — готово, нет — «ещё не настроено», этап 2 жив', function () {
    assert_eq(true, cm_ready3(cm_test_pdo()));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    assert_eq(false, cm_ready3($pdo));
    assert_eq(true, cm_ready2($pdo));
    $log = [];
    assert_eq(false, cm_need3(cm_ctx_for($pdo, $log, 7)));
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

test('слово при числе', function () {
    $f = ['победа', 'победы', 'побед'];
    $got = [];
    foreach ([0, 1, 2, 4, 5, 11, 12, 14, 21, 22, 25, 101, 111] as $n) { $got[] = $n . ' ' . cm_plural($n, $f); }
    assert_eq(['0 побед', '1 победа', '2 победы', '4 победы', '5 побед', '11 побед', '12 побед', '14 побед',
        '21 победа', '22 победы', '25 побед', '101 победа', '111 побед'], $got);
});

test('рандом: 1–100, одно число, два в любом порядке, пределы; ответ временный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $calls = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max) use (&$calls): int {
        $calls[] = [$min, $max];
        return $max;
    };
    cm_do($ctx, 'рандом');
    assert_eq('🎲 Число от 1 до 100: 100', cm_last_text($log));
    cm_do($ctx, 'рандом 50');
    cm_do($ctx, 'рандом 20 10');
    cm_do($ctx, 'рандом -5 5');
    assert_eq('🎲 Число от -5 до 5: 5', cm_last_text($log));
    cm_do($ctx, 'рандом 1000000000');
    assert_eq('🎲 Число от 1 до 1 000 000 000: 1 000 000 000', cm_last_text($log));
    assert_eq([[1, 100], [1, 50], [10, 20], [-5, 5], [1, 1000000000]], $calls);
    foreach (['рандом 1000000001', 'рандом много', 'рандом 1 2 3'] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('rand_usage'), cm_last_text($log), $bad);
    }
    assert_eq(8, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'все 8 ответов удалятся через минуту');
});

test('монетка и шар — по подставной случайности', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = cm_seq([0, 1, 0, 9]);
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_heads'), cm_last_text($log));
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_tails'), cm_last_text($log));
    cm_do($ctx, 'шар сдам экзамен?');
    assert_eq('🎱 Да.', cm_last_text($log));
    cm_do($ctx, 'шар а завтра?');
    assert_eq('🎱 ' . CM_BALL[9], cm_last_text($log));
    assert_eq(10, count(CM_BALL));
    cm_do($ctx, 'шар');
    assert_eq(cm_t('ball_usage'), cm_last_text($log));
});

test('выбери: «или» и запятые, от 2 до 10 вариантов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max): int { return $max; };
    cm_do($ctx, 'выбери чай или кофе?');
    assert_eq('🤔 Выбираю: кофе', cm_last_text($log));
    cm_do($ctx, 'выбери Москва, Питер ИЛИ Казань');
    assert_eq('🤔 Выбираю: Казань', cm_last_text($log));
    assert_eq(['a', 'b', 'c d'], cm_choose_options('a, b или c d'));
    foreach (['выбери чай', 'выбери ' . implode(', ', range(1, 11)), 'выбери , или'] as $bad) {
        cm_do($ctx, $bad);
        assert_eq(cm_t('choose_usage'), cm_last_text($log), $bad);
    }
});

test('инфа и шанс: один текст в один день — одно число, назавтра — своё', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $want = crc32('завтра будет дождь|20260921') % 101;
    $next = crc32('завтра будет дождь|20260922') % 101;
    assert_eq(true, $want !== $next, 'пример различает дни');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], CM_T2359), 'инфа Завтра  будет дождь');
    assert_eq('🔮 Вероятность: ' . $want . '%', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 8, '', [], [], CM_NOW), 'шанс завтра будет дождь');
    assert_eq('🔮 Вероятность: ' . $want . '%', cm_last_text($log), 'другой человек, тот же день');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], CM_T0001), 'инфа завтра будет дождь');
    assert_eq('🔮 Вероятность: ' . $next . '%', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'инфа');
    assert_eq(cm_t('chance_usage'), cm_last_text($log));
});

test('!кто: из писавших за неделю, без ушедших; ответ постоянный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 7, 'vasya', 'Вася');
    cm_person($pdo, 8, 'petya', 'Петя');
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    $ins->execute([':c' => CM_CHAT, ':u' => 7, ':d' => 20260921, ':m' => 5]);
    $ins->execute([':c' => CM_CHAT, ':u' => 8, ':d' => 20260915, ':m' => 9]);
    $ins->execute([':c' => CM_CHAT, ':u' => 9, ':d' => 20260921, ':m' => 50]);
    $ins->execute([':c' => CM_CHAT, ':u' => 10, ':d' => 20260914, ':m' => 99]);   // 8-й день — уже не неделя
    cm_member_set($pdo, CM_CHAT, 9, ['left_at' => CM_NOW]);
    $asked = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = function (int $min, int $max) use (&$asked): int {
        $asked[] = [$min, $max];
        return 0;
    };
    cm_do($ctx, '!кто сегодня дежурит?');
    assert_eq('🎯 Кто сегодня дежурит? Думаю, Петя.', cm_last_text($log));
    assert_eq([[0, 1]], $asked, 'двое: Петя (9) и Вася (5)');
    cm_do($ctx, '!кто');
    assert_eq('🎯 Думаю, Петя.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), '!кто');
    assert_eq(cm_t('who_empty'), cm_last_text($log));
});

test('без префикса: «кто знает» — речь, «!кто» — команда; «кто я» и «кто ты» — как раньше', function () {
    $pdo = cm_test_pdo();
    $log = [];
    assert_eq(false, fn_run($pdo, $log, 7, 'кто знает, где ключи'));
    assert_eq(false, fn_run($pdo, $log, 7, 'кто'));
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq(true, fn_run($pdo, $log, 7, '!кто знает'));
    assert_eq(cm_t('who_empty'), cm_last_text($log));
    assert_eq(true, fn_run($pdo, $log, 7, 'кто я'));
    assert_eq(0, strpos(cm_last_text($log), "👤 U7\n"));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    assert_eq(true, fn_run($pdo, $log, 7, 'кто ты', $reply));
    assert_eq(0, strpos(cm_last_text($log), "👤 Петя\n"));
});

test('без префикса: рандом, монетка, выбери, инфа, шар — по шаблону, иначе речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    foreach (['рандом', 'рандом 1 100', 'монетка', 'выбери чай или кофе', 'выбери чай, кофе', 'инфа он прав',
              'шанс есть', 'шар сдам?'] as $text) {
        assert_eq(true, fn_run($pdo, $log, 7, $text), $text);
    }
    $n = count(cm_calls($log, 'sendMessage'));
    foreach (['рандом число', 'монетка упала', 'выбери уже что-нибудь', 'инфа', 'шар', "инфа\nвторая строка"] as $text) {
        assert_eq(false, fn_run($pdo, $log, 7, $text), $text);
    }
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'обычная речь — без ответа');
});

test('дк игры 6 выключает случайные команды', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк игры 6');
    assert_eq(cm_t('access_done', ['section' => 'игры', 'level' => 6]), cm_last_text($log));
    assert_eq(false, fn_run($pdo, $log, 7, '!монетка'), 'выключено — дальше в фильтры');
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(false, fn_run($pdo, $log, 7, 'монетка'));
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'без префикса — молча');
});

test('случайным командам таблицы этапа 3 не нужны', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    $ctx['rand'] = cm_seq([1]);
    cm_do($ctx, 'монетка');
    assert_eq(cm_t('coin_tails'), cm_last_text($log));
});

test('переезд группы уносит таблицы этапа 3, старые строки главнее', function () {
    $pdo = cm_test_pdo();
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $c = CM_CHAT;
    $pdo->exec("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status) VALUES (-500, 'duel', 7, 8, 0, 1, 0, 'open')");
    $pdo->exec("INSERT INTO cm_games (chat_id, kind, a_id, b_id, turn_id, state, msg_id, status, winner_id, updated_at) VALUES (-500, 'duel', 7, 8, 7, '{}', 1, 'on', 0, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (-500, 7, 'duel', 3, 0, 0)");
    $pdo->exec("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES ($c, 7, 'duel', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES (-500, 7, 8, 0, 5, 0, 0, 0)");
    $pdo->exec("INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES (-500, 7, 8, 0, 0, 0)");
    $pdo->exec("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at) VALUES (-500, 'Волки', 'волки', 7, '', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at) VALUES ($c, 'ВОЛКИ', 'волки', 9, '', 1, 0, 0)");
    $pdo->exec("INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (-500, 7, 1, 0)");
    cm_migrate($pdo, -500, CM_CHAT);
    foreach (['cm_offers', 'cm_games', 'cm_game_stats', 'cm_couples', 'cm_marriages', 'cm_clans', 'cm_clan_members'] as $t) {
        assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = $c")->fetchColumn(), $t);
        assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE chat_id = -500")->fetchColumn(), $t . ' под старым id');
    }
    assert_eq(3, (int)$pdo->query("SELECT wins FROM cm_game_stats WHERE chat_id = $c")->fetchColumn());
    assert_eq(7, (int)$pdo->query("SELECT owner_id FROM cm_clans WHERE chat_id = $c")->fetchColumn());
});

test('переезд без таблиц этапа 3 — как раньше', function () {
    $pdo = cm_test_pdo();
    foreach (['cm_offers', 'cm_games', 'cm_game_stats', 'cm_couples', 'cm_marriages', 'cm_clans', 'cm_clan_members'] as $t) {
        $pdo->exec("DROP TABLE $t");
    }
    cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (-500, 7, 4)');
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(4, cm_rep_score($pdo, CM_CHAT, 7));
});

// Ответ на сообщение человека $to (по умолчанию Петя, id 20).
function fn_reply(int $to = 20, string $name = 'Петя', array $from = []): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => $from + ['id' => $to, 'is_bot' => false, 'first_name' => $name]]];
}

test('РП: ответом и @ником, форма глагола по полу из анкеты; ответ постоянный', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'обнять');
    assert_eq('🤗 U7 обнял(а) Петя', cm_last_text($log));
    cm_profile_put($pdo, 7, 'gender', 'f', CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'пожать руку @petya');
    assert_eq('🤝 U7 пожала руку Петя', cm_last_text($log));
    cm_profile_put($pdo, 7, 'gender', 'm', CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'убить');
    assert_eq('🔪 U7 убил Петя', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    assert_eq(14, count(CM_RP));
    foreach (array_keys(CM_RP) as $p) { assert_eq('rp', CM_COMMANDS[$p][0], $p); }
});

test('РП: себе нельзя, ботам — только обнять и похвалить, без цели — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(7, 'U7')), 'обнять');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = fn_reply(555, 'Бот', ['is_bot' => true]);
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'ударить');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'похвалить');
    assert_eq('👏 U7 похвалил(а) Бот', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(777000, 'Telegram')), 'поцеловать');
    assert_eq(cm_t('target_bot'), cm_last_text($log), 'служебный Telegram — как бот');
    cm_do(cm_ctx_for($pdo, $log, 7), 'обнять');
    assert_eq(cm_t('need_target'), cm_last_text($log));
});

test('опыт пары: +1 за РП друг другу, не больше 20 в сутки по Москве', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec('INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES (' . CM_CHAT . ', 20, 7, 0, 0, 0, 0, 0)');
    $xp = function () use ($pdo): array {
        return array_map('intval', $pdo->query('SELECT xp, xp_day, xp_today FROM cm_couples')->fetch(PDO::FETCH_NUM));
    };
    for ($i = 0; $i < 25; $i++) { cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(), [], CM_T2359), 'обнять'); }
    assert_eq([20, 20260921, 20], $xp());
    cm_do(cm_ctx_for($pdo, $log, 20, '', fn_reply(7, 'U7'), [], CM_T0001), 'погладить');
    assert_eq([21, 20260922, 1], $xp(), 'новые сутки, и в обе стороны');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(30, 'Коля'), [], CM_T0001), 'обнять');
    assert_eq([21, 20260922, 1], $xp(), 'не пара — без опыта');
    $pdo->exec('UPDATE cm_couples SET ended_at = 1');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply(), [], CM_T0001), 'обнять');
    assert_eq(21, $xp()[0], 'расставшимся — без опыта');
});

test('РП без префикса: ровно команда с целью, лишние слова — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    assert_eq(true, fn_run($pdo, $log, 7, 'обнять', fn_reply()));
    assert_eq(true, fn_run($pdo, $log, 7, 'обнять @petya'));
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(false, fn_run($pdo, $log, 7, 'обнять'));
    assert_eq(false, fn_run($pdo, $log, 7, 'ударить бы его', fn_reply()));
    assert_eq(false, fn_run($pdo, $log, 7, 'убить время'));
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('дк рп 6 выключает РП; без таблиц этапа 3 — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк рп 6');
    assert_eq(false, fn_run($pdo, $log, 7, '!обнять', fn_reply()));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7, '', fn_reply()), 'обнять');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
