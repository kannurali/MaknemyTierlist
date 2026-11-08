<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Дуэль (этап 3): api/lib/cm/duel.php. Шанс выстрела и жребий — через
// подставную случайность (cm_seq), ход — нажатием кнопки (cm_press).

function dl_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
}

// Вызов от 7 к Пете и «Принять» в момент $now; $first — жребий (0 — первым
// ходит вызвавший). Возвращает id дуэли.
function dl_start(PDO $pdo, array &$log, int $first = 0, int $now = CM_NOW): int {
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $now), 'дуэль @petya');
    $offer = (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn();
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 20, 'cm:p:' . $offer . ':y', [], $now, cm_seq([$first])));
    return (int)$pdo->query('SELECT MAX(id) FROM cm_games')->fetchColumn();
}

function dl_last_edit(array $log): array {
    $e = cm_calls($log, 'editMessageText');
    return $e[count($e) - 1][1];
}

test('шанс выстрела: 30% + 20% за прицел, потолок 90%', function () {
    assert_eq([30, 50, 70, 90, 90], array_map('cm_duel_chance', [0, 1, 2, 3, 4]));
});

test('дуэль: вызов, жребий, кнопки ходов в том же сообщении', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    $id = dl_start($pdo, $log);
    assert_eq('⚔️ Петя, U7 вызывает вас на дуэль!', cm_texts($log)[0]);
    $e = dl_last_edit($log);
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 0\nЖребий: первым ходит U7.\nХодит: U7. На ход — 2 минуты.", $e['text']);
    assert_eq([[['text' => '🔫 Выстрел', 'callback_data' => 'cm:d:1:s']],
               [['text' => '🎯 Прицелиться', 'callback_data' => 'cm:d:1:a'], ['text' => '💨 Сбить прицел', 'callback_data' => 'cm:d:1:k']]],
        $e['reply_markup']['inline_keyboard']);
    $g = cm_duel_get($pdo, $id);
    assert_eq([7, 20, 7, 'on', cm_offer_get($pdo, 1)['msg_id']], [$g['a_id'], $g['b_id'], $g['turn_id'], $g['status'], $g['msg_id']]);
    assert_eq($g['msg_id'], $e['message_id']);
    assert_eq(1, count(cm_calls($log, 'sendMessage')), 'новых сообщений нет');
    $id2 = dl_start($pdo, $log, 1);
    assert_eq(20, cm_duel_get($pdo, $id2)['turn_id'], 'жребий: первым — Петя');
});

test('ходит только тот, чей ход; чужим — «не для вас»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    $edits = count(cm_calls($log, 'editMessageText'));
    assert_eq(cm_t('duel_not_turn'), cm_press($pdo, $log, 20, 'cm:d:1:s'));
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 30, 'cm:d:1:a'));
    assert_eq('', cm_press($pdo, $log, 7, 'cm:d:1:z'), 'неизвестная буква');
    assert_eq($edits, count(cm_calls($log, 'editMessageText')));
});

test('прицел, сбить прицел; больше трёх прицелов нельзя, ход не переходит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    assert_eq(cm_t('duel_ans_ok'), cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 5));
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 1, Петя — 0\n🎯 U7 целится.\nХодит: Петя. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    cm_press($pdo, $log, 20, 'cm:d:1:k', [], CM_NOW + 10);
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 0\n💨 Петя сбивает прицел U7.\nХодит: U7. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    foreach ([7, 20, 7, 20, 7, 20] as $i => $who) { cm_press($pdo, $log, $who, 'cm:d:1:a', [], CM_NOW + 20 + $i); }
    $g = cm_duel_get($pdo, 1);
    assert_eq([['a' => 3, 'b' => 3], 7, CM_NOW + 25], [$g['aims'], $g['turn_id'], $g['updated_at']]);
    $edits = count(cm_calls($log, 'editMessageText'));
    assert_eq(cm_t('duel_aim_max'), cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 30));
    assert_eq([7, CM_NOW + 25], [cm_duel_get($pdo, 1)['turn_id'], cm_duel_get($pdo, 1)['updated_at']]);
    assert_eq($edits, count(cm_calls($log, 'editMessageText')));
});

test('выстрел: промах обнуляет свои прицелы, попадание — победа и счёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    cm_press($pdo, $log, 7, 'cm:d:1:a', [], CM_NOW + 5);
    cm_press($pdo, $log, 20, 'cm:d:1:a', [], CM_NOW + 10);
    assert_eq(cm_t('duel_ans_miss'), cm_press($pdo, $log, 7, 'cm:d:1:s', [], CM_NOW + 15, cm_seq([51])));
    assert_eq("⚔️ Дуэль: U7 против Петя\n🎯 Прицелы: U7 — 0, Петя — 1\n💥 U7 стреляет (50%) — мимо!\nХодит: Петя. На ход — 2 минуты.",
        dl_last_edit($log)['text']);
    assert_eq(cm_t('duel_ans_hit'), cm_press($pdo, $log, 20, 'cm:d:1:s', [], CM_NOW + 20, cm_seq([50])));
    $e = dl_last_edit($log);
    assert_eq("⚔️ Дуэль: U7 против Петя\n💥 Петя стреляет (50%) — попал!\n🏆 Победитель — Петя!", $e['text']);
    assert_eq(false, isset($e['reply_markup']), 'кнопки сняты');
    $g = cm_duel_get($pdo, 1);
    assert_eq(['done', 20], [$g['status'], $g['winner_id']]);
    assert_eq(['wins' => 1, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 20, 'duel'));
    assert_eq(['wins' => 0, 'losses' => 1, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'duel'));
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 7, 'cm:d:1:s', [], CM_NOW + 25));
});

test('просрочка хода: cron отдаёт победу сопернику, нажатие после 2 минут — тоже', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    dl_start($pdo, $log);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 119));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 120));
    assert_eq("⚔️ Дуэль: U7 против Петя\n⌛ U7: ход просрочен.\n🏆 Победитель — Петя!", dl_last_edit($log)['text']);
    assert_eq(['done', 20], [cm_duel_get($pdo, 1)['status'], cm_duel_get($pdo, 1)['winner_id']]);
    assert_eq(1, cm_game_stat($pdo, CM_CHAT, 20, 'duel')['wins']);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 300), 'второй раз не трогает');
    $id = dl_start($pdo, $log, 0, CM_NOW + 1000);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':a', [], CM_NOW + 1010);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:d:' . $id . ':s', [], CM_NOW + 1130));
    assert_eq(['done', 7], [cm_duel_get($pdo, $id)['status'], cm_duel_get($pdo, $id)['winner_id']]);
    assert_eq("⚔️ Дуэль: U7 против Петя\n⌛ Петя: ход просрочен.\n🏆 Победитель — U7!", dl_last_edit($log)['text']);
});

test('исход: проигравшему — мут от бота; «ничего» — без него; модератор — игра без ставки', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход мут 10м');
    assert_eq('Проигравший в дуэли получит: мут 10 мин.', cm_last_text($log));
    $id = dl_start($pdo, $log);
    assert_eq("⚔️ Петя, U7 вызывает вас на дуэль!\nСтавка: проигравшему — мут 10 мин.", cm_texts($log)[1]);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    $r = cm_calls($log, 'restrictChatMember');
    assert_eq([1, 20, CM_NOW + 605], [count($r), $r[0][1]['user_id'], $r[0][1]['until_date']], 'мут 10 минут от выстрела');
    assert_eq("⚔️ Дуэль: U7 против Петя\n💥 U7 стреляет (30%) — попал!\n🏆 Победитель — U7!\n⚖️ Петя: мут 10 мин",
        dl_last_edit($log)['text']);
    assert_eq('проигрыш в дуэли', $pdo->query('SELECT reason FROM cm_punishments')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход ничего');
    $id = dl_start($pdo, $log);
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'исход выключен');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дуэли исход мут 10м');
    cm_lvl($pdo, 20, 2);
    $id = dl_start($pdo, $log);
    assert_eq('⚔️ Петя, U7 вызывает вас на дуэль!', cm_texts($log)[count(cm_texts($log)) - 1], 'без строки ставки');
    cm_press($pdo, $log, 7, 'cm:d:' . $id . ':s', [], CM_NOW + 5, cm_seq([1]));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'проигравший с рангом — без наказания');
});

test('дуэли — топ побед; без префикса «дуэль» — только с целью; отказы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    dl_people($pdo);
    $ins = $pdo->prepare("INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, 0)");
    foreach ([[7, 'duel', 1, 0], [20, 'duel', 5, 2], [21, 'dice', 9, 0]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':k' => $r[1], ':w' => $r[2], ':l' => $r[3]]);
    }
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэли');
    assert_eq("⚔️ Лучшие дуэлянты:\n1. Петя — 5 побед / 2 поражения\n2. U7 — 1 победа / 0 поражений", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'дуэли');
    assert_eq(cm_t('duel_top_empty'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'дуэль');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэль');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('дуэль @petya'));
    assert_eq(true, $run('дуэли'));
    assert_eq(false, $run('дуэль бы'));
    assert_eq(false, $run('дуэли были'));
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'дуэли');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 7, 'cm:d:1:s'));
});

run_tests();
