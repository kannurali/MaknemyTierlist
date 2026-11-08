<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Шахматы чат-менеджера: api/lib/cm/chess.php. Вызов и «Принять» — как у
// дуэли (cm_do, cm_press), ходы — как из мини-приложения (cm_chess_play с
// временем в миллисекундах). Жребий: 0 — белыми играет вызвавший (U7).

const CHS_T0 = CM_NOW * 1000;

function chs_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
}

function chs_start(PDO $pdo, array &$log, string $text = 'шахматы @petya', int $first = 0): int {
    cm_do(cm_ctx_for($pdo, $log, 7), $text);
    $offer = (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn();
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 20, 'cm:p:' . $offer . ':y', [], CM_NOW, cm_seq([$first])));
    return (int)$pdo->query('SELECT MAX(id) FROM cm_chess')->fetchColumn();
}

function chs_play(PDO $pdo, int $id, int $uid, string $uci, int $ms): array {
    return cm_chess_play($pdo, cm_chess_get($pdo, $id), $uid, $uci, $ms);
}

function chs_act(PDO $pdo, int $id, int $uid, string $act, int $ms): array {
    return cm_chess_act($pdo, cm_chess_get($pdo, $id), $uid, $act, $ms);
}

// Ходы по очереди белые (7) / чёрные (20), по секунде на ход.
function chs_moves(PDO $pdo, int $id, array $moves, int $from = CHS_T0): int {
    $t = $from;
    foreach ($moves as $i => $m) {
        $t += 1000;
        [$err] = chs_play($pdo, $id, $i % 2 === 0 ? 7 : 20, $m, $t);
        assert_eq('', $err, 'ход ' . $m);
    }
    return $t;
}

function chs_last_edit(array $log): array {
    $e = cm_calls($log, 'editMessageText');
    return $e[count($e) - 1][1];
}

test('вызов: отмечены оба, кнопки «Принять» / «Отказаться», по 10 минут', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы @petya');
    assert_eq('♟ Петя, U7 вызывает вас на партию в шахматы — по 10 мин на каждого!', cm_last_text($log));
    $m = cm_calls($log, 'sendMessage')[0][1];
    assert_eq(['text_mention', 'text_mention'], array_column($m['entities'], 'type'));
    assert_eq([20, 7], array_map(function ($e) { return $e['user']['id']; }, $m['entities']));
    $o = cm_offer_get($pdo, 1);
    assert_eq(['chess', 7, 20, 10], [$o['kind'], $o['from_id'], $o['to_id'], $o['ref_id']]);
});

test('вызов: своё время, неверное время, себя, без цели — лучшие', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы 5 @petya');
    assert_eq(5, cm_offer_get($pdo, 1)['ref_id']);
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы @petya 3 мин');
    assert_eq(3, cm_offer_get($pdo, 2)['ref_id']);
    cm_do(cm_ctx_for($pdo, $log, 7, '', ['reply_to_message' => cm_gmsg(20, 'го')]), 'шахматы 15');
    assert_eq(15, cm_offer_get($pdo, 3)['ref_id'], 'ответом на сообщение');
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы 45 @petya');
    assert_eq(cm_t('chess_minutes', ['max' => 30]), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы @user7');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы 5');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы');
    assert_eq(cm_t('chess_top_empty'), cm_last_text($log));
});

test('без префикса: ответом или с ником и временем — команда, просто речь — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $ctx = cm_ctx_for($pdo, $log, 7, 'шахматы 5', ['reply_to_message' => cm_gmsg(20, 'го')]);
    assert_eq(true, cm_bare_ok($ctx, cm_parse('шахматы 5', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, 'шахматы 5 @petya');
    assert_eq(true, cm_bare_ok($ctx, cm_parse('шахматы 5 @petya', CM_BOT)));
    $ctx = cm_ctx_for($pdo, $log, 7, 'шахматы это скучно');
    assert_eq(false, cm_bare_ok($ctx, cm_parse('шахматы это скучно', CM_BOT)));
});

test('«Принять»: жребий цвета, сообщение со ссылкой на доску', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    $g = cm_chess_get($pdo, $id);
    assert_eq([7, 20, 600000, CHESS_START, 'on', CHS_T0 + 180000], [$g['white_id'], $g['black_id'], $g['base_ms'], $g['fen'], $g['status'], $g['due_at']]);
    assert_eq(cm_offer_get($pdo, 1)['msg_id'], $g['msg_id']);
    $e = chs_last_edit($log);
    assert_eq("♟ Шахматы · по 10 мин\n⚪ Белые — U7\n⚫ Чёрные — Петя\n\nПервый ход за белыми — U7. Откройте доску: на первый ход 3 минуты.", $e['text']);
    assert_eq([[['text' => '♟ Открыть доску', 'url' => 'https://t.me/MaknemyBot?startapp=ch' . $id]]], $e['reply_markup']['inline_keyboard']);
    $id2 = chs_start($pdo, $log, 'шахматы 3 @petya', 1);
    $g2 = cm_chess_get($pdo, $id2);
    assert_eq([20, 7, 180000], [$g2['white_id'], $g2['black_id'], $g2['base_ms']], 'жребий: белыми — Петя');
});

test('состояние: разрешённые ходы только ходящему, зритель только смотрит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    $g = cm_chess_get($pdo, $id);
    $w = cm_chess_view($pdo, $g, 7, CHS_T0 + 500);
    assert_eq(['w', 'w', 20, null, null, ['side' => 'w', 'until' => CHS_T0 + 180000]],
        [$w['me'], $w['turn'], count($w['legal']), $w['last'], $w['clock']['run'], $w['first']]);
    assert_eq(['name' => 'U7', 'username' => 'user7'], $w['white']);
    assert_eq(['name' => 'Петя', 'username' => 'petya'], $w['black']);
    $b = cm_chess_view($pdo, $g, 20, CHS_T0);
    assert_eq(['b', []], [$b['me'], $b['legal']]);
    $s = cm_chess_view($pdo, $g, 999, CHS_T0);
    assert_eq([null, []], [$s['me'], $s['legal']]);
});

test('ходы: чужой ход, неверный ход, запись ходов', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    assert_eq('turn', chs_play($pdo, $id, 20, 'e7e5', CHS_T0 + 1000)[0]);
    assert_eq('turn', chs_play($pdo, $id, 999, 'e2e4', CHS_T0 + 1000)[0]);
    assert_eq('illegal', chs_play($pdo, $id, 7, 'e2e5', CHS_T0 + 1000)[0]);
    [$err, $g, $ended] = chs_play($pdo, $id, 7, 'E2E4', CHS_T0 + 1000);
    assert_eq(['', false, ['e2e4'], ['e4'], 1, 'b'], [$err, $ended, $g['moves'], $g['san'], $g['rev'], cm_chess_turn($g)]);
    assert_eq(CHS_T0 + 1000 + 180000, $g['due_at'], 'у чёрных свой срок первого хода');
    assert_eq('turn', chs_play($pdo, $id, 7, 'd2d4', CHS_T0 + 2000)[0]);
});

test('часы: идут после первых ходов обеих сторон, без добавки', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    chs_play($pdo, $id, 7, 'e2e4', CHS_T0 + 50000);
    chs_play($pdo, $id, 20, 'e7e5', CHS_T0 + 90000);
    $g = cm_chess_get($pdo, $id);
    assert_eq([600000, 600000, CHS_T0 + 90000 + 600000], [$g['white_ms'], $g['black_ms'], $g['due_at']], 'первые ходы часов не тратят');
    $v = cm_chess_view($pdo, $g, 7, CHS_T0 + 95000);
    assert_eq(['w', 595000, 600000, null], [$v['clock']['run'], $v['clock']['w'], $v['clock']['b'], $v['first']]);
    [, $g] = chs_play($pdo, $id, 7, 'g1f3', CHS_T0 + 100000);
    assert_eq([590000, 600000, CHS_T0 + 100000 + 600000], [$g['white_ms'], $g['black_ms'], $g['due_at']]);
    [, $g] = chs_play($pdo, $id, 20, 'b8c6', CHS_T0 + 130000);
    assert_eq([590000, 570000, CHS_T0 + 130000 + 590000], [$g['white_ms'], $g['black_ms'], $g['due_at']]);
});

test('мат: итог, счёт и сообщение в чате', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    chs_moves($pdo, $id, ['f2f3', 'e7e5', 'g2g4']);
    [$err, $g, $ended] = chs_play($pdo, $id, 20, 'd8h4', CHS_T0 + 9000);
    assert_eq(['', true, 'done', '0-1', 'mate', 0], [$err, $ended, $g['status'], $g['result'], $g['reason'], $g['due_at']]);
    assert_eq('Qh4#', $g['san'][3]);
    $v = cm_chess_view($pdo, $g, 7, CHS_T0 + 9000);
    assert_eq(['b', 'e1', [], null], [$v['winner'], $v['check'], $v['legal'], $v['clock']['run']]);
    assert_eq(['wins' => 1, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 20, 'chess'));
    assert_eq(['wins' => 0, 'losses' => 1, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'chess'));
    cm_chess_announce($pdo, cm_cfg(), cm_rec($log), $g, CM_NOW + 9);
    $e = chs_last_edit($log);
    assert_eq("♟ Шахматы · по 10 мин\n⚪ Белые — U7\n⚫ Чёрные — Петя\n\n♚ Мат на 2-м ходу.\n🏆 Победитель — Петя!", $e['text']);
    assert_eq('♟ Посмотреть партию', $e['reply_markup']['inline_keyboard'][0][0]['text']);
    assert_eq('over', chs_play($pdo, $id, 7, 'e2e4', CHS_T0 + 10000)[0]);
});

test('ничьи по позиции: повторение', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    chs_moves($pdo, $id, ['g1f3', 'g8f6', 'f3g1', 'f6g8', 'g1f3', 'g8f6', 'f3g1']);
    [$err, $g, $ended] = chs_play($pdo, $id, 20, 'f6g8', CHS_T0 + 20000);
    assert_eq(['', true, '1/2', 'repeat'], [$err, $ended, $g['result'], $g['reason']]);
    assert_eq(1, cm_game_stat($pdo, CM_CHAT, 7, 'chess')['draws']);
    assert_eq(1, cm_game_stat($pdo, CM_CHAT, 20, 'chess')['draws']);
    assert_eq("♟ Шахматы · по 10 мин\n⚪ Белые — U7\n⚫ Чёрные — Петя\n\n🤝 Ничья: позиция повторилась трижды.",
        cm_plain(cm_chess_result_text($pdo, $g)));
});

test('первый ход не сделан — партия отменена без счёта (cron)', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 179));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 180));
    $g = cm_chess_get($pdo, $id);
    assert_eq(['done', 'abort', 'abort'], [$g['status'], $g['result'], $g['reason']]);
    assert_eq(['wins' => 0, 'losses' => 0, 'draws' => 0], cm_game_stat($pdo, CM_CHAT, 7, 'chess'));
    assert_eq("♟ Шахматы · по 10 мин\n⚪ Белые — U7\n⚫ Чёрные — Петя\n\n⌛ Партия отменена: первые ходы так и не сделаны.", chs_last_edit($log)['text']);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 300), 'второй раз не трогает');
});

test('время вышло: проигрыш, а против голого короля — ничья', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    $t = chs_moves($pdo, $id, ['e2e4', 'e7e5']);
    [$err, $g, $ended] = cm_chess_act($pdo, cm_chess_get($pdo, $id), 20, 'draw', $t + 600000);
    assert_eq(['over', true, '0-1', 'time', 0], [$err, $ended, $g['result'], $g['reason'], $g['white_ms']], 'запрос после срока закрывает партию');
    assert_eq("⏱ У U7 кончилось время.\n🏆 Победитель — Петя!", implode("\n", array_slice(explode("\n", cm_plain(cm_chess_result_text($pdo, $g))), 4)));

    $id = chs_start($pdo, $log);
    $t = chs_moves($pdo, $id, ['e2e4', 'e7e5']);
    $pdo->prepare('UPDATE cm_chess SET fen = :f WHERE id = :id')->execute([':f' => '7k/8/8/8/8/8/8/QK6 w - - 0 2', ':id' => $id]);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), intdiv($t + 599000, 1000)));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), intdiv($t + 600000, 1000) + 1));
    $g = cm_chess_get($pdo, $id);
    assert_eq(['1/2', 'time'], [$g['result'], $g['reason']]);
    assert_eq('⏱ У U7 кончилось время, но сопернику нечем ставить мат — ничья.', explode("\n", cm_plain(chs_last_edit($log)['text']))[4]);
});

test('ничья по согласию: предложить, отклонить, ход снимает предложение, принять', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    $t = chs_moves($pdo, $id, ['e2e4', 'e7e5']);
    assert_eq('illegal', chs_act($pdo, $id, 20, 'draw_yes', $t)[0], 'принимать нечего');
    assert_eq('illegal', chs_act($pdo, $id, 20, 'dance', $t)[0]);
    assert_eq('turn', chs_act($pdo, $id, 999, 'draw', $t)[0], 'зритель');
    [$err, $g] = chs_act($pdo, $id, 7, 'draw', $t);
    assert_eq(['', 'w'], [$err, $g['draw_by']]);
    assert_eq('w', cm_chess_view($pdo, $g, 20, $t)['draw']);
    [$err, $g] = chs_act($pdo, $id, 7, 'draw', $t);
    assert_eq(['', 'w', 3], [$err, $g['draw_by'], $g['rev']], 'повтор — без изменений');
    [$err, $g] = chs_act($pdo, $id, 20, 'draw_no', $t);
    assert_eq(['', ''], [$err, $g['draw_by']]);
    chs_act($pdo, $id, 20, 'draw', $t);
    [, $g] = chs_play($pdo, $id, 7, 'g1f3', $t + 1000);
    assert_eq('', $g['draw_by'], 'ход снимает предложение');
    chs_act($pdo, $id, 7, 'draw', $t + 2000);
    [$err, $g, $ended] = chs_act($pdo, $id, 20, 'draw_yes', $t + 3000);
    assert_eq(['', true, '1/2', 'agreed'], [$err, $ended, $g['result'], $g['reason']]);
});

test('сдаться: до первых ходов — отмена, потом — проигрыш', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    chs_moves($pdo, $id, ['e2e4']);
    [, $g] = chs_act($pdo, $id, 20, 'resign', CHS_T0 + 5000);
    assert_eq(['abort', 'abort'], [$g['result'], $g['reason']]);
    $id = chs_start($pdo, $log);
    $t = chs_moves($pdo, $id, ['e2e4', 'e7e5']);
    [$err, $g, $ended] = chs_act($pdo, $id, 20, 'resign', $t + 4000);
    assert_eq(['', true, '1-0', 'resign'], [$err, $ended, $g['result'], $g['reason']]);
    assert_eq([596000, 600000], [$g['white_ms'], $g['black_ms']], 'часы замерли: у белых шли 4 с');
    assert_eq("🏳 Петя сдаётся.\n🏆 Победитель — U7!", implode("\n", array_slice(explode("\n", cm_plain(cm_chess_result_text($pdo, $g))), 4)));
});

test('два запроса с одним rev: второй ход не проходит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    $stale = cm_chess_get($pdo, $id);
    assert_eq('', cm_chess_play($pdo, $stale, 7, 'e2e4', CHS_T0 + 1000)[0]);
    [$err, $g] = cm_chess_play($pdo, $stale, 7, 'd2d4', CHS_T0 + 1000);
    assert_eq(['turn', ['e2e4']], [$err, $g['moves']]);
});

test('профиль и лучшие шахматисты', function () {
    $pdo = cm_test_pdo();
    $log = [];
    chs_people($pdo);
    $id = chs_start($pdo, $log);
    chs_moves($pdo, $id, ['f2f3', 'e7e5', 'g2g4', 'd8h4']);
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы');
    assert_eq("♟ Лучшие шахматисты:\n1. Петя — 1 победа / 0 поражений / 0 ничьих", cm_plain(cm_last_text($log)));
    $lines = cm_profile_lines(cm_ctx_for($pdo, $log, 20), 20, 'Петя');
    assert_eq(true, in_array('Шахматы: 1 победа / 0 поражений / 0 ничьих', $lines, true), implode(' | ', $lines));
});

test('нет таблицы партий — «не настроено», без падений', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_chess');
    $log = [];
    chs_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'шахматы @petya');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
    assert_eq(0, cm_chess_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
});

run_tests();
