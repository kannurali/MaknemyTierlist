<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Перенос статы из Ириса: «импорт статы» в api/lib/cm/stats.php.

const IR_BOT = 777;   // сам Ирис

function ir_put(PDO $pdo, int $userId, int $day, int $msgs, int $chatId = CM_CHAT): void {
    $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)')
        ->execute([':c' => $chatId, ':u' => $userId, ':d' => $day, ':m' => $msgs]);
}

// Сообщение Ириса «стата вся»: $lines — [место, значки, ник, число, ссылка];
// ссылка — ['text_link', url], ['text_mention', user] или null. Числа — с
// неразрывным пробелом, как у Ириса; ник в ссылке, значки перед ним — нет.
function ir_top(array $lines, int $total, int $date = CM_T0001): array {
    $nb = mb_chr(0xA0);
    $text = "📊 Статистика по общительным пользователям за всё время\n";
    $ents = [];
    foreach ($lines as [$place, $badges, $nick, $msgs, $link]) {
        $text .= "\n" . $place . '. ' . $badges;
        if ($link !== null) {
            $e = ['type' => $link[0], 'offset' => cm_utf16_len($text), 'length' => cm_utf16_len($nick)];
            $e += $link[0] === 'text_link' ? ['url' => $link[1]] : ['user' => $link[1]];
            $ents[] = $e;
        }
        $text .= $nick . ' — ' . number_format($msgs, 0, '', $nb);
    }
    $text .= "\n\nВсего сообщений: " . number_format($total, 0, '', $nb);
    return ['message_id' => 60, 'date' => $date, 'text' => $text, 'entities' => $ents,
            'from' => ['id' => IR_BOT, 'is_bot' => true, 'first_name' => 'Iris | Black Diamond']];
}

function ir_link(int $id): array {
    return ['text_link', 'tg://openmessage?user_id=' . $id];
}

function ir_reply(array $msg): array {
    return ['reply_to_message' => $msg];
}

function ir_stat(PDO $pdo, int $userId, int $days): int {
    return cm_stats_msgs($pdo, cm_net($pdo, CM_CHAT), $userId, $days, CM_T0001);
}

test('разбор топа: id из ссылки, из text_mention, ник из t.me, без ссылки, «Всего»', function () {
    $msg = ir_top([
        [1, '💎 🏠 ', 'KaRinochka 🤩', 111105, ir_link(501)],
        [2, '💎 ', 'Максимка', 58488, ['text_mention', ['id' => 502, 'is_bot' => false, 'first_name' => 'Макс', 'username' => 'maks_v']]],
        [3, '', 'Dima', 45826, ['text_link', 'https://t.me/dima_x']],
        [4, '', 'тюлень 🥶', 21016, null],
        [5, '', 'R', 6855, ['text_link', 'tg://user?id=505']],
    ], 2694635);
    $total = 0;
    $rows = cm_iris_parse($msg, $total);
    assert_eq(2694635, $total);
    assert_eq(5, count($rows));
    assert_eq([1, 'KaRinochka 🤩', 111105, 501, ''], [$rows[0]['place'], $rows[0]['name'], $rows[0]['msgs'], $rows[0]['id'], $rows[0]['username']]);
    assert_eq([502, 'Макс'], [$rows[1]['id'], $rows[1]['user']['first_name']]);
    assert_eq([0, 'dima_x', 'Dima'], [$rows[2]['id'], $rows[2]['username'], $rows[2]['name']]);
    assert_eq([0, '', 'тюлень 🥶'], [$rows[3]['id'], $rows[3]['username'], $rows[3]['name']]);
    assert_eq([505, 6855], [$rows[4]['id'], $rows[4]['msgs']]);
    assert_eq(111105, cm_iris_num('111 105'));
    assert_eq(null, cm_iris_num('1 234 567 890'));
    assert_eq(null, cm_iris_num('12a'));
    assert_eq([], cm_iris_parse(['text' => 'привет'], $total));
    assert_eq(0, $total);
});

test('импорт статы: в «всё время», не в «сегодня»; имена, ник, остаток в «всего»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 503, 'dima_x', 'Дима');
    ir_put($pdo, 503, 20260922, 4);
    $top = ir_top([
        [1, '💎 🏠 ', 'KaRinochka', 111105, ir_link(501)],
        [2, '', 'Дима из Ириса', 45826, ['text_link', 'https://t.me/dima_x']],
        [3, '', 'тюлень', 21016, null],
    ], 200000);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', ir_reply($top), [], CM_T0001), 'импорт статы');
    assert_eq("📥 Стата из Ириса перенесена: 2 человека, 156 931 сообщение. Смотрите «стата всё».\n"
        . "Ещё 22 053 — у тех, кого нет в топе Ириса: эти идут только в «всего».\n"
        . 'Не узнал, кто это: 3. тюлень. Им — «импорт статы ЧИСЛО» ответом на их сообщение.', cm_last_text($log));
    assert_eq(111105, ir_stat($pdo, 501, 0));
    assert_eq(0, ir_stat($pdo, 501, 1), 'в «сегодня» перенос не идёт');
    assert_eq(0, ir_stat($pdo, 501, 30), 'и в «месяц»');
    assert_eq(45830, ir_stat($pdo, 503, 0), 'свои 4 за день Ириса не вычитаются');
    assert_eq('KaRinochka', cm_user_name($pdo, 501), 'незнакомого бот запомнил по нику из топа');
    assert_eq('Дима', cm_user_name($pdo, 503), 'знакомого не переименовал');
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], [], CM_T0001);
    cm_do($ctx, 'стата всё');
    assert_eq("📊 Активность в чатах Maknemy за всё время (всего 178 988):\n1. KaRinochka — 111 105\n2. Дима — 45 830", cm_last_text($log));
    cm_do($ctx, 'стата');
    assert_eq("📊 Активность в чатах Maknemy за сегодня (всего 4):\n1. Дима — 4", cm_last_text($log));
    assert_eq(1, cm_stats_place($pdo, cm_net($pdo, CM_CHAT), 501, 0, CM_T0001));
    assert_eq(0, cm_stats_place($pdo, cm_net($pdo, CM_CHAT), 0, 0, CM_T0001), 'остаток — не человек');
    assert_eq(0, cm_stats_first_day($pdo, CM_CHAT, 501), '«В чатах с» — по своим дням, без строки Ириса');
});

test('вычет: что бот насчитал до дня Ириса, тот тоже считал; повтор — то же самое', function () {
    $pdo = cm_test_pdo();
    $log = [];
    ir_put($pdo, 501, 20260920, 30);
    ir_put($pdo, 501, 20260921, 20);
    ir_put($pdo, 501, 20260922, 10);
    $top = ir_top([[1, '', 'K', 1000, ir_link(501)]], 1000);
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', ir_reply($top), [], CM_T0001);
    cm_do($ctx, 'импорт статы');
    assert_eq(1010, ir_stat($pdo, 501, 0), '1000 − 50 до дня Ириса + свои 60');
    cm_do($ctx, 'импорт статы');
    assert_eq(1010, ir_stat($pdo, 501, 0), 'повтор не удваивает');
    assert_eq(1010, cm_stats_total($pdo, CM_CHAT, 0, CM_T0001), 'остатка нет — топ равен «Всего»');
    $fwd = ir_top([[1, '', 'K', 1000, ir_link(501)]], 1000, CM_T0001);
    $fwd['date'] = CM_T0001 + 3 * 86400;
    $fwd['forward_origin'] = ['type' => 'user', 'date' => CM_T0001 - 86400];
    $fwd['from'] = ['id' => 9, 'is_bot' => false, 'first_name' => 'Переслал'];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', ir_reply($fwd), [], CM_T0001), 'импорт статы');
    assert_eq(1030, ir_stat($pdo, 501, 0), 'пересланный: день — когда писал Ирис (21.09), вычет 30');
});

test('кто переносит: создатель и сотрудник сайта; прочим — молча, с префиксом — отказ', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (900, 500, 0)');
    $log = [];
    $top = ir_top([[1, '', 'K', 1000, ir_link(501)]], 0);
    cm_lvl($pdo, 7, 4);
    $run = function (int $who, string $text) use ($pdo, &$log, $top): bool {
        $ctx = cm_ctx_for($pdo, $log, $who, $text, ir_reply($top), [], CM_T0001);
        return cm_run_command($ctx, cm_parse($text, CM_BOT));
    };
    $n = count(cm_calls($log, 'sendMessage'));
    assert_eq(true, $run(7, 'импорт статы'));
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'ранг 4 без префикса — тишина');
    assert_eq(0, ir_stat($pdo, 501, 0));
    $run(7, '!импорт статы');
    assert_eq(cm_t('iris_staff_only'), cm_last_text($log));
    assert_eq(0, ir_stat($pdo, 501, 0));
    $run(500, 'импорт статы');
    assert_eq(1000, ir_stat($pdo, 501, 0), 'сотрудник maknemy.com');
});

test('через вебхук: ответ «импорт статы» на сообщение Ириса в группе', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $top = ir_top([[1, '💎 ', 'K', 777000, ir_link(501)], [2, '', 'L', 500, ir_link(502)]], 777500);
    $msg = cm_gmsg(CM_OWNER, 'импорт статы', ir_reply($top) + ['date' => CM_T0001]);
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => $msg], CM_T0001);
    assert_eq('📥 Стата из Ириса перенесена: 2 человека, 777 500 сообщений. Смотрите «стата всё».', cm_last_text($log));
    assert_eq([777000, 500], [ir_stat($pdo, 501, 0), ir_stat($pdo, 502, 0)]);
    $edit = cm_rec($log);
    $n = count(cm_calls($log, 'sendMessage'));
    cm_handle_update($pdo, cm_cfg(), $edit, ['edited_message' => $msg], CM_T0001);
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'правка команду не повторяет');
});

test('один человек: ЧИСЛО ответом на его сообщение, 0 — убрать; без числа — подсказка', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 8, 'petya', 'Петя');
    ir_put($pdo, 8, 20260921, 5);
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 8, 'is_bot' => false, 'first_name' => 'Петя', 'username' => 'petya']]];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', $reply, [], CM_T0001);
    cm_do($ctx, 'импорт статы 13 033');
    assert_eq('📥 Петя: из Ириса 13 028, всего за всё время 13 033.', cm_last_text($log));
    cm_do($ctx, 'импорт статы 0');
    assert_eq(5, ir_stat($pdo, 8, 0), '0 убирает перенос');
    $owner = cm_ctx_for($pdo, $log, CM_OWNER, '', [], [], CM_T0001);
    cm_do($owner, 'импорт статы @petya 100');
    assert_eq(100, ir_stat($pdo, 8, 0), '@ник ЧИСЛО');
    cm_do($owner, 'импорт статы @nobody1 100');
    assert_eq(cm_t('target_unknown'), cm_last_text($log));
    cm_do($ctx, 'импорт статы');
    assert_eq(cm_t('iris_usage'), cm_last_text($log));
    cm_do($owner, 'импорт статы');
    assert_eq(cm_t('iris_usage'), cm_last_text($log));
    $iris = ['reply_to_message' => ['message_id' => 61, 'text' => 'Ирис: привет', 'from' => ['id' => IR_BOT, 'is_bot' => true, 'first_name' => 'Iris']]];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER, '', $iris, [], CM_T0001), 'импорт статы 5');
    assert_eq(cm_t('iris_usage'), cm_last_text($log), 'Ирису стату не пишем');
});

run_tests();
