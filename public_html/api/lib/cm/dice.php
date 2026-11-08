<?php
// Кубы (этап 3): «кубы @ник» → приглашение (offers.php) → бот бросает 🎲 за
// обоих ответом на приглашение (sendDice), значения берёт из ответа Telegram.
// Больше — победа, поровну — ничья. Итог — в том же сообщении сразу:
// анимация в Telegram доиграет сама. «кубы» без цели — топ. Исход
// проигравшему — dice_outcome («кубы исход мут 10м»), у ничьей исхода нет.

// Бросить кубик ответом на сообщение $replyTo. 0 — бюджет минуты кончился
// или Telegram не бросил.
function cm_dice_throw(array $ctx, int $replyTo): int {
    $chatId = (int)$ctx['chat']['chat_id'];
    if (!cm_budget_take($ctx['pdo'], $chatId, (int)$ctx['now'])) { return 0; }
    $res = ($ctx['send'])('sendDice', ['chat_id' => $chatId, 'emoji' => '🎲',
        'reply_parameters' => ['message_id' => $replyTo, 'allow_sending_without_reply' => true]]);
    $v = (int)($res['result']['dice']['value'] ?? 0);
    return ($v >= 1 && $v <= 6) ? $v : 0;
}

function cm_cmd_dice(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t !== null) {
        cm_game_invite($ctx, 'dice', $t, 'dice_offer', 'dice_outcome');
        return;
    }
    if ($why === 'unknown') {
        cm_reply($ctx, cm_t('target_unknown'));
        return;
    }
    cm_game_top($ctx, 'dice', 'dice_top_head', 'dice_top_empty', true);
}

function cm_cmd_dice_outcome(array $ctx, array $cmd): void {
    cm_outcome_cmd($ctx, $cmd, 'dice_outcome');
}

// «Принять»: первым бросает тот, кто позвал.
function cm_offer_yes_dice(array $ctx, array $o): string {
    $va = cm_dice_throw($ctx, $o['msg_id']);
    $vb = $va > 0 ? cm_dice_throw($ctx, $o['msg_id']) : 0;
    if ($vb === 0) { return 'dice_failed'; }
    $pdo = $ctx['pdo'];
    $chatId = $o['chat_id'];
    $a = $o['from_id'];
    $b = $o['to_id'];
    $text = cm_t('dice_result', ['a' => cm_user_name($pdo, $a), 'va' => $va, 'b' => cm_user_name($pdo, $b), 'vb' => $vb]);
    if ($va === $vb) {
        cm_game_score($pdo, $chatId, $a, 'dice', 'draws');
        cm_game_score($pdo, $chatId, $b, 'dice', 'draws');
        cm_edit($ctx, $o['msg_id'], $text . "\n" . cm_t('dice_draw'));
        return '';
    }
    [$win, $lose] = $va > $vb ? [$a, $b] : [$b, $a];
    cm_game_score($pdo, $chatId, $win, 'dice', 'wins');
    cm_game_score($pdo, $chatId, $lose, 'dice', 'losses');
    $out = cm_game_outcome($ctx, 'dice_outcome', $a, $b, $lose, cm_t('reason_dice'));
    cm_edit($ctx, $o['msg_id'], $text . "\n" . cm_t('game_won', ['name' => cm_user_name($pdo, $win)]) . $out);
    return '';
}
