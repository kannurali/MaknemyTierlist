<?php
// Дуэль (этап 3): «дуэль @ник» → приглашение (offers.php) → жребий, кто ходит
// первым. Кнопки cm:d:<id>:s|a|k — выстрел, прицел, сбить прицел; жмёт тот,
// чей ход. Выстрел попадает с шансом 30% + 20% за каждый свой прицел (не
// больше трёх, потолок 90%); промах обнуляет свои прицелы. Прицел — +1,
// «сбить прицел» обнуляет прицелы соперника; ход переходит. Нет хода 2
// минуты — проиграл тот, чей был ход (cm_duel_tick из cron или нажатие
// позже срока). Сообщение одно — приглашение: бот правит его после каждого
// хода. Счёт — cm_game_stats, исход проигравшему — duel_outcome.

const CM_DUEL_TURN     = 120;
const CM_DUEL_AIMS_MAX = 3;

function cm_duel_chance(int $aims): int {
    return min(90, 30 + 20 * min(CM_DUEL_AIMS_MAX, max(0, $aims)));
}

// Дуэль по id; aims — прицелы ['a' => …, 'b' => …] из state.
function cm_duel_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM cm_games WHERE id = :id AND kind = 'duel'");
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (['id', 'chat_id', 'a_id', 'b_id', 'turn_id', 'msg_id', 'winner_id', 'updated_at'] as $k) { $r[$k] = (int)$r[$k]; }
    $s = json_decode((string)$r['state'], true);
    $r['aims'] = ['a' => (int)($s['a'] ?? 0), 'b' => (int)($s['b'] ?? 0)];
    $r['status'] = (string)$r['status'];
    return $r;
}

// Текст идущей дуэли: кто против кого, прицелы, последний ход, чей ход.
function cm_duel_board(PDO $pdo, array $g, string $last): string {
    $a = cm_user_who($pdo, $g['a_id']);
    $b = cm_user_who($pdo, $g['b_id']);
    return cm_t('duel_head', ['a' => $a, 'b' => $b]) . "\n"
        . cm_t('duel_aims', ['a' => $a, 'na' => $g['aims']['a'], 'b' => $b, 'nb' => $g['aims']['b']]) . "\n"
        . $last . "\n" . cm_t('duel_turn', ['name' => cm_user_who($pdo, $g['turn_id'])]);
}

function cm_duel_buttons(int $id): array {
    return [[['text' => cm_t('duel_btn_shoot'), 'callback_data' => 'cm:d:' . $id . ':s']],
            [['text' => cm_t('duel_btn_aim'), 'callback_data' => 'cm:d:' . $id . ':a'],
             ['text' => cm_t('duel_btn_knock'), 'callback_data' => 'cm:d:' . $id . ':k']]];
}

// «Принять»: жребий и доска с кнопками вместо текста приглашения.
function cm_offer_yes_duel(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $first = cm_rand($ctx, 0, 1) === 0 ? $o['from_id'] : $o['to_id'];
    $pdo->prepare("INSERT INTO cm_games (chat_id, kind, a_id, b_id, turn_id, state, msg_id, status, winner_id, updated_at)
                   VALUES (:c, 'duel', :a, :b, :t, :s, :m, 'on', 0, :n)")
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':t' => $first,
                   ':s' => json_encode(['a' => 0, 'b' => 0]), ':m' => $o['msg_id'], ':n' => (int)$ctx['now']]);
    $g = cm_duel_get($pdo, (int)$pdo->lastInsertId());
    cm_edit($ctx, $g['msg_id'], cm_duel_board($pdo, $g, cm_t('duel_first', ['name' => cm_user_who($pdo, $first)])),
        cm_duel_buttons($g['id']));
    return '';
}

/**
 * Ход кнопкой; возвращает текст для answerCallbackQuery. Ход записывается
 * условием «ход всё ещё мой»: второе быстрое нажатие его не повторит.
 */
function cm_duel_callback(array $ctx, array $q, int $id, string $act): string {
    if (!in_array($act, ['s', 'a', 'k'], true)) { return ''; }
    $pdo = $ctx['pdo'];
    $g = cm_ready3($pdo) ? cm_duel_get($pdo, $id) : null;
    if ($g === null || $g['chat_id'] !== (int)$ctx['chat']['chat_id'] || $g['status'] !== 'on') { return cm_t('offer_stale'); }
    $me = (int)$ctx['actor'];
    if ($me !== $g['a_id'] && $me !== $g['b_id']) { return cm_t('not_yours'); }
    if ($me !== $g['turn_id']) { return cm_t('duel_not_turn'); }
    $now = (int)$ctx['now'];
    if ($now - $g['updated_at'] >= CM_DUEL_TURN) {
        cm_duel_timeout($ctx, $g);
        return cm_t('offer_stale');
    }
    $mine = $me === $g['a_id'] ? 'a' : 'b';
    $their = $mine === 'a' ? 'b' : 'a';
    $other = $mine === 'a' ? $g['b_id'] : $g['a_id'];
    $name = cm_user_who($pdo, $me);
    $aims = $g['aims'];
    if ($act === 'a' && $aims[$mine] >= CM_DUEL_AIMS_MAX) { return cm_t('duel_aim_max'); }
    if ($act === 's') {
        $chance = cm_duel_chance($aims[$mine]);
        if (cm_rand($ctx, 1, 100) <= $chance) {
            $st = $pdo->prepare("UPDATE cm_games SET status = 'done', winner_id = :w, updated_at = :n
                WHERE id = :id AND status = 'on' AND turn_id = :me");
            $st->execute([':w' => $me, ':n' => $now, ':id' => $id, ':me' => $me]);
            if ($st->rowCount() === 0) { return cm_t('offer_stale'); }
            cm_duel_end($ctx, $g, $me, cm_t('duel_hit', ['name' => $name, 'chance' => $chance]));
            return cm_t('duel_ans_hit');
        }
        $aims[$mine] = 0;
        $last = cm_t('duel_miss', ['name' => $name, 'chance' => $chance]);
    } elseif ($act === 'a') {
        $aims[$mine]++;
        $last = cm_t('duel_aimed', ['name' => $name]);
    } else {
        $aims[$their] = 0;
        $last = cm_t('duel_knocked', ['name' => $name, 'other' => cm_user_who($pdo, $other)]);
    }
    $st = $pdo->prepare("UPDATE cm_games SET state = :s, turn_id = :t, updated_at = :n WHERE id = :id AND status = 'on' AND turn_id = :me");
    $st->execute([':s' => json_encode($aims), ':t' => $other, ':n' => $now, ':id' => $id, ':me' => $me]);
    if ($st->rowCount() === 0) { return cm_t('offer_stale'); }
    $g['aims'] = $aims;
    $g['turn_id'] = $other;
    cm_edit($ctx, $g['msg_id'], cm_duel_board($pdo, $g, $last), cm_duel_buttons($id));
    return cm_t($act === 's' ? 'duel_ans_miss' : 'duel_ans_ok');
}

// Конец дуэли (строка уже помечена done): счёт, исход, итог без кнопок.
function cm_duel_end(array $ctx, array $g, int $winner, string $last): void {
    $pdo = $ctx['pdo'];
    $loser = $winner === $g['a_id'] ? $g['b_id'] : $g['a_id'];
    cm_game_score($pdo, $g['chat_id'], $winner, 'duel', 'wins');
    cm_game_score($pdo, $g['chat_id'], $loser, 'duel', 'losses');
    $out = cm_game_outcome($ctx, 'duel_outcome', $g['a_id'], $g['b_id'], $loser, cm_t('reason_duel'));
    cm_edit($ctx, $g['msg_id'], cm_t('duel_head', ['a' => cm_user_who($pdo, $g['a_id']), 'b' => cm_user_who($pdo, $g['b_id'])])
        . "\n" . $last . "\n" . cm_t('game_won', ['name' => cm_user_who($pdo, $winner)]) . $out);
}

// Ход просрочен: проиграл тот, чей был ход. false — дуэль уже закончилась
// или ход сделан, пока мы смотрели.
function cm_duel_timeout(array $ctx, array $g): bool {
    $winner = $g['turn_id'] === $g['a_id'] ? $g['b_id'] : $g['a_id'];
    $st = $ctx['pdo']->prepare("UPDATE cm_games SET status = 'done', winner_id = :w
        WHERE id = :id AND status = 'on' AND turn_id = :t AND updated_at = :u");
    $st->execute([':w' => $winner, ':id' => $g['id'], ':t' => $g['turn_id'], ':u' => $g['updated_at']]);
    if ($st->rowCount() === 0) { return false; }
    cm_duel_end($ctx, $g, $winner, cm_t('duel_timeout', ['name' => cm_user_who($ctx['pdo'], $g['turn_id'])]));
    return true;
}

// Из cm_games_tick: дуэли, где ход просрочен. Бота в чате нет — дуэль
// закрывается без счёта.
function cm_duel_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    $st = $pdo->prepare("SELECT id FROM cm_games WHERE kind = 'duel' AND status = 'on' AND updated_at <= :t ORDER BY id LIMIT 50");
    $st->execute([':t' => $now - CM_DUEL_TURN]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $g = cm_duel_get($pdo, (int)$id);
        if ($g === null) { continue; }
        $ctx = cm_tick_ctx($pdo, $cfg, $send, $g['chat_id'], $now);
        if ($ctx === null) {
            $pdo->prepare("UPDATE cm_games SET status = 'done' WHERE id = :id")->execute([':id' => $g['id']]);
            continue;
        }
        if (cm_duel_timeout($ctx, $g)) { $n++; }
    }
    return $n;
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_duel(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_game_invite($ctx, 'duel', $t, 'duel_offer', 'duel_outcome'); }
}

function cm_cmd_duel_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    cm_game_top($ctx, 'duel', 'duel_top_head', 'duel_top_empty', false);
}

function cm_cmd_duel_outcome(array $ctx, array $cmd): void {
    cm_outcome_cmd($ctx, $cmd, 'duel_outcome');
}
