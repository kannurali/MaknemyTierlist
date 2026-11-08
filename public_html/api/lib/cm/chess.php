<?php
// Шахматы (этап 3, просьба владельца 08.11.2026). «шахматы» ответом на
// сообщение или с @ником → приглашение (offers.php: вид chess, ref_id —
// минуты на партию) с отметкой обоих → «Принять» → жребий цвета, и бот
// правит то же сообщение: кто белые, кто чёрные и кнопка «Открыть доску» —
// ссылка t.me/<бот>?startapp=ch<id> на мини-приложение (tg-app.php →
// js/tg-chess.js). Ходы делают в мини-приложении (api/cm_chess.php), правила
// — api/lib/chess.php, судья — сервер. В чате бот правит сообщение только в
// начале и в конце: ходы не тратят бюджет группы и не шумят. Смотреть
// партию по той же кнопке может любой, ходят только двое.
//
// Часы (решение владельца): по CM_CHESS_DEFAULT минут каждому, «шахматы 3»
// — своё время от 1 до CM_CHESS_MAX, без добавки за ход. Первый ход каждой
// стороны часов не тратит, но на него CM_CHESS_FIRST секунд: не сходил —
// партия отменяется без счёта (как на lichess). Кончилось время — проигрыш,
// а если сопернику нечем ставить мат — ничья. Сроки ловит любой запрос
// партии из мини-приложения и cron раз в минуту (cm_chess_tick).
//
// Время партии — в миллисекундах: turn_at — начало текущего хода, due_at —
// когда у ходящего кончится время (или срок первого хода). Ход и любое
// изменение пишутся условием «rev не менялся»: два быстрых запроса не
// сделают двух ходов. Счёт — cm_game_stats, вид chess; «шахматы» без цели —
// лучшие игроки чата.

const CM_CHESS_DEFAULT = 10;
const CM_CHESS_MAX     = 30;
const CM_CHESS_FIRST   = 180;

function cm_chess_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_chess LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function cm_chess_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_chess WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (['id', 'chat_id', 'white_id', 'black_id', 'base_ms', 'white_ms', 'black_ms', 'turn_at', 'due_at',
              'msg_id', 'rev', 'created_at'] as $k) { $r[$k] = (int)$r[$k]; }
    foreach (['fen', 'draw_by', 'status', 'result', 'reason'] as $k) { $r[$k] = (string)$r[$k]; }
    $r['moves'] = (string)$r['moves'] === '' ? [] : explode(' ', (string)$r['moves']);
    $r['san'] = (string)$r['san'] === '' ? [] : explode(' ', (string)$r['san']);
    return $r;
}

// Цвет человека в партии: 'w', 'b' или '' — зритель.
function cm_chess_side(array $g, int $uid): string {
    if ($uid === $g['white_id']) { return 'w'; }
    return $uid === $g['black_id'] ? 'b' : '';
}

function cm_chess_turn(array $g): string {
    $f = explode(' ', $g['fen']);
    return ($f[1] ?? 'w') === 'b' ? 'b' : 'w';
}

// Остаток часов на момент $nowMs; run — чьи часы идут (после первых ходов обеих сторон).
function cm_chess_clock(array $g, int $nowMs): array {
    $left = ['w' => max(0, $g['white_ms']), 'b' => max(0, $g['black_ms'])];
    $run = ($g['status'] === 'on' && count($g['moves']) >= 2) ? cm_chess_turn($g) : null;
    if ($run !== null) { $left[$run] = max(0, $left[$run] - max(0, $nowMs - $g['turn_at'])); }
    return $left + ['run' => $run];
}

// Записать изменения, если партию никто не тронул с момента чтения.
function cm_chess_save(PDO $pdo, array $g, array $set): bool {
    $set['rev'] = $g['rev'] + 1;
    $cols = [];
    $p = [':id' => $g['id'], ':rev' => $g['rev']];
    foreach ($set as $k => $v) {
        $cols[] = $k . ' = :s_' . $k;
        $p[':s_' . $k] = $v;
    }
    $st = $pdo->prepare('UPDATE cm_chess SET ' . implode(', ', $cols) . " WHERE id = :id AND rev = :rev AND status = 'on'");
    $st->execute($p);
    return $st->rowCount() > 0;
}

// Поля конца партии: часы замирают на том, что осталось.
function cm_chess_end_set(array $g, string $result, string $reason, int $nowMs): array {
    $c = cm_chess_clock($g, $nowMs);
    return ['status' => 'done', 'result' => $result, 'reason' => $reason, 'draw_by' => '', 'due_at' => 0,
            'white_ms' => $c['w'], 'black_ms' => $c['b']];
}

function cm_chess_score(PDO $pdo, array $g): void {
    $r = $g['result'];
    if ($r === '1/2') {
        cm_game_score($pdo, $g['chat_id'], $g['white_id'], 'chess', 'draws');
        cm_game_score($pdo, $g['chat_id'], $g['black_id'], 'chess', 'draws');
    } elseif ($r === '1-0' || $r === '0-1') {
        cm_game_score($pdo, $g['chat_id'], $r === '1-0' ? $g['white_id'] : $g['black_id'], 'chess', 'wins');
        cm_game_score($pdo, $g['chat_id'], $r === '1-0' ? $g['black_id'] : $g['white_id'], 'chess', 'losses');
    }
}

// Срок вышел: до первых ходов — отмена, потом — время. Возвращает
// законченную партию или null — срок не вышел или её уже закрыли.
function cm_chess_due(PDO $pdo, array $g, int $nowMs): ?array {
    if ($g['status'] !== 'on' || $g['due_at'] <= 0 || $nowMs < $g['due_at']) { return null; }
    $turn = cm_chess_turn($g);
    if (count($g['moves']) < 2) {
        $set = cm_chess_end_set($g, 'abort', 'abort', $nowMs);
    } else {
        $them = chess_other($turn);
        $p = chess_from_fen($g['fen']);
        $win = $p !== null && chess_can_mate($p['b'], $them);
        $set = cm_chess_end_set($g, $win ? ($them === 'w' ? '1-0' : '0-1') : '1/2', 'time', $nowMs);
        $set[$turn === 'w' ? 'white_ms' : 'black_ms'] = 0;
    }
    if (!cm_chess_save($pdo, $g, $set)) { return null; }
    $g = cm_chess_get($pdo, $g['id']);
    cm_chess_score($pdo, $g);
    return $g;
}

/**
 * Ход игрока $uid. Возвращает [ошибка, партия, закончилась ли сейчас]:
 * ошибка '' — ход сделан; 'over' — партия уже закончена; 'turn' — не его
 * ход (или зритель, или ход опередил другой запрос); 'illegal' — так
 * ходить нельзя (превращение без фигуры тоже).
 */
function cm_chess_play(PDO $pdo, array $g, int $uid, string $uci, int $nowMs): array {
    $done = cm_chess_due($pdo, $g, $nowMs);
    if ($done !== null) { return ['over', $done, true]; }
    if ($g['status'] !== 'on') { return ['over', $g, false]; }
    $me = cm_chess_side($g, $uid);
    $p = chess_from_fen($g['fen']);
    if ($me === '' || $p === null || $p['t'] !== $me) { return ['turn', $g, false]; }
    $uci = strtolower($uci);
    $legal = chess_legal($p);
    if (!isset($legal[$uci])) { return ['illegal', $g, false]; }
    $san = chess_san($p, $uci);
    $n = chess_make($p, $legal[$uci][0], $legal[$uci][1], $legal[$uci][2]);
    $moves = $g['moves'];
    $moves[] = $uci;
    $sans = $g['san'];
    $sans[] = $san;
    $mine = $me === 'w' ? 'white_ms' : 'black_ms';
    $theirs = $me === 'w' ? 'black_ms' : 'white_ms';
    $set = ['fen' => chess_to_fen($n), 'moves' => implode(' ', $moves), 'san' => implode(' ', $sans),
            'turn_at' => $nowMs, 'draw_by' => ''];
    if (count($g['moves']) >= 2) { $set[$mine] = max(1, $g[$mine] - max(0, $nowMs - $g['turn_at'])); }
    $set['due_at'] = $nowMs + (count($moves) < 2 ? CM_CHESS_FIRST * 1000 : $g[$theirs]);
    $end = chess_outcome($n, chess_keys($moves));
    if ($end !== '') {
        $set['status'] = 'done';
        $set['result'] = $end === 'mate' ? ($me === 'w' ? '1-0' : '0-1') : '1/2';
        $set['reason'] = $end;
        $set['due_at'] = 0;
    }
    if (!cm_chess_save($pdo, $g, $set)) { return ['turn', cm_chess_get($pdo, $g['id']) ?? $g, false]; }
    $g = cm_chess_get($pdo, $g['id']);
    if ($end !== '') { cm_chess_score($pdo, $g); }
    return ['', $g, $end !== ''];
}

/**
 * Не ход: resign — сдаться (до первых ходов — отменить партию), draw —
 * предложить ничью (если соперник уже предложил — согласиться), draw_yes /
 * draw_no — ответ на предложение соперника. Ответ — как у cm_chess_play.
 */
function cm_chess_act(PDO $pdo, array $g, int $uid, string $act, int $nowMs): array {
    $done = cm_chess_due($pdo, $g, $nowMs);
    if ($done !== null) { return ['over', $done, true]; }
    if ($g['status'] !== 'on') { return ['over', $g, false]; }
    $me = cm_chess_side($g, $uid);
    if ($me === '') { return ['turn', $g, false]; }
    $them = chess_other($me);
    if ($act === 'resign') {
        $set = count($g['moves']) < 2 ? cm_chess_end_set($g, 'abort', 'abort', $nowMs)
            : cm_chess_end_set($g, $me === 'w' ? '0-1' : '1-0', 'resign', $nowMs);
    } elseif (($act === 'draw' || $act === 'draw_yes') && $g['draw_by'] === $them) {
        $set = cm_chess_end_set($g, '1/2', 'agreed', $nowMs);
    } elseif ($act === 'draw' && $g['draw_by'] === '') {
        $set = ['draw_by' => $me];
    } elseif ($act === 'draw' && $g['draw_by'] === $me) {
        return ['', $g, false];
    } elseif ($act === 'draw_no' && $g['draw_by'] === $them) {
        $set = ['draw_by' => ''];
    } else {
        return ['illegal', $g, false];
    }
    if (!cm_chess_save($pdo, $g, $set)) { return ['turn', cm_chess_get($pdo, $g['id']) ?? $g, false]; }
    $g = cm_chess_get($pdo, $g['id']);
    $ended = $g['status'] === 'done';
    if ($ended) { cm_chess_score($pdo, $g); }
    return ['', $g, $ended];
}

// Имя и ник для мини-приложения.
function cm_chess_person(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT username FROM cm_users WHERE user_id = :id');
    $st->execute([':id' => $id]);
    $u = (string)$st->fetchColumn();
    return ['name' => cm_user_name($pdo, $id), 'username' => preg_match('~^[a-z]\w{3,31}$~', $u) ? $u : null];
}

// Партия глазами $uid — контракт мини-приложения (js/tg-chess.js). legal —
// только тому, чей ход; зрителю (me = null) — пусто.
function cm_chess_view(PDO $pdo, array $g, int $uid, int $nowMs): array {
    $p = chess_from_fen($g['fen']) ?? chess_from_fen(CHESS_START);
    $turn = $p['t'];
    $me = cm_chess_side($g, $uid);
    $on = $g['status'] === 'on';
    $plies = count($g['moves']);
    $c = cm_chess_clock($g, $nowMs);
    $result = $g['result'] !== '' ? $g['result'] : null;
    return [
        'ok' => true, 'id' => $g['id'], 'rev' => $g['rev'], 'now' => $nowMs,
        'fen' => $g['fen'], 'turn' => $turn, 'moves' => $g['moves'], 'san' => $g['san'],
        'last' => $plies > 0 ? $g['moves'][$plies - 1] : null,
        'check' => chess_in_check($p) ? chess_sq_name(chess_king_sq($p['b'], $turn)) : null,
        'legal' => ($on && $me === $turn) ? array_keys(chess_legal($p)) : [],
        'me' => $me === '' ? null : $me,
        'white' => cm_chess_person($pdo, $g['white_id']),
        'black' => cm_chess_person($pdo, $g['black_id']),
        'clock' => ['base' => $g['base_ms'], 'w' => $c['w'], 'b' => $c['b'], 'run' => $c['run']],
        'first' => ($on && $plies < 2) ? ['side' => $turn, 'until' => $g['due_at'], 'ms' => CM_CHESS_FIRST * 1000] : null,
        'draw' => $g['draw_by'] !== '' ? $g['draw_by'] : null,
        'status' => $g['status'], 'result' => $result, 'reason' => $g['reason'] !== '' ? $g['reason'] : null,
        'winner' => $result === '1-0' ? 'w' : ($result === '0-1' ? 'b' : null),
    ];
}

// --------------------------------------------------------------------------
//  Сообщение в чате: начало и итог
// --------------------------------------------------------------------------

function cm_chess_link(string $bot, int $id): string {
    return 'https://t.me/' . ltrim($bot, '@') . '?startapp=ch' . $id;
}

function cm_chess_buttons(string $bot, int $id, bool $done = false): array {
    return [[['text' => cm_t($done ? 'chess_btn_view' : 'chess_btn_open'), 'url' => cm_chess_link($bot, $id)]]];
}

function cm_chess_head(PDO $pdo, array $g): string {
    return cm_t('chess_head', ['min' => intdiv($g['base_ms'], 60000), 'w' => cm_user_who($pdo, $g['white_id']),
        'b' => cm_user_who($pdo, $g['black_id'])]);
}

function cm_chess_start_text(PDO $pdo, array $g): string {
    return cm_chess_head($pdo, $g) . "\n\n" . cm_t('chess_start', ['w' => cm_user_who($pdo, $g['white_id']),
        'first' => intdiv(CM_CHESS_FIRST, 60)]);
}

function cm_chess_result_text(PDO $pdo, array $g): string {
    $r = $g['result'];
    $winner = $r === '1-0' ? $g['white_id'] : ($r === '0-1' ? $g['black_id'] : 0);
    $loser = $r === '1-0' ? $g['black_id'] : ($r === '0-1' ? $g['white_id'] : 0);
    $reason = $g['reason'];
    if ($reason === 'time') {
        $flag = $winner !== 0 ? $loser : (cm_chess_turn($g) === 'w' ? $g['white_id'] : $g['black_id']);
        $line = cm_t($winner !== 0 ? 'chess_end_time' : 'chess_end_time_draw', ['name' => cm_user_who($pdo, $flag)]);
    } elseif ($reason === 'resign') {
        $line = cm_t('chess_end_resign', ['name' => cm_user_who($pdo, $loser)]);
    } elseif ($reason === 'mate') {
        $line = cm_t('chess_end_mate', ['n' => intdiv(count($g['moves']) + 1, 2)]);
    } else {
        $line = cm_t('chess_end_' . (in_array($reason, ['stalemate', 'material', 'fifty', 'repeat', 'agreed'], true) ? $reason : 'abort'));
    }
    $text = cm_chess_head($pdo, $g) . "\n\n" . $line;
    return $winner !== 0 ? $text . "\n" . cm_t('game_won', ['name' => cm_user_who($pdo, $winner)]) : $text;
}

// Итог в сообщение партии. Бота в чате больше нет — молча.
function cm_chess_announce(PDO $pdo, array $cfg, callable $send, array $g, int $now): void {
    $ctx = cm_tick_ctx($pdo, $cfg, $send, $g['chat_id'], $now);
    if ($ctx === null || $ctx['bot'] === '') { return; }
    cm_edit($ctx, $g['msg_id'], cm_chess_result_text($pdo, $g), cm_chess_buttons($ctx['bot'], $g['id'], true));
}

// Из cm_games_tick раз в минуту: партии, где вышел срок хода, а в
// мини-приложении никто не сидит. Сколько закрыто.
function cm_chess_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    if (!cm_chess_ready($pdo)) { return 0; }
    $st = $pdo->prepare("SELECT id FROM cm_chess WHERE status = 'on' AND due_at <= :ms ORDER BY id LIMIT 50");
    $st->execute([':ms' => $now * 1000]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $g = cm_chess_get($pdo, (int)$id);
        $done = $g !== null ? cm_chess_due($pdo, $g, $now * 1000) : null;
        if ($done === null) { continue; }
        cm_chess_announce($pdo, $cfg, $send, $done, $now);
        $n++;
    }
    return $n;
}

// --------------------------------------------------------------------------
//  Команда и «Принять»
// --------------------------------------------------------------------------

// «шахматы» ответом или «шахматы @ник» — вызов; «шахматы 5 …» — по 5 минут;
// «шахматы» без цели — лучшие игроки.
function cm_cmd_chess(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    if (!cm_chess_ready($pdo)) {
        cm_reply($ctx, cm_t('not_ready2'));
        return;
    }
    $args = $cmd['args'];
    $min = 0;
    if (preg_match('~(?:^|\s)(\d{1,2})(?:\s*(?:минуты|минута|минут|мин|м))?(?=\s|$)~u', $args, $m, PREG_OFFSET_CAPTURE)) {
        $min = (int)$m[1][0];
        $args = trim(substr_replace($args, ' ', $m[0][1], strlen($m[0][0])));
    }
    $why = null;
    $t = cm_target($pdo, $ctx['msg'], $args, $why);
    if ($t === null) {
        if ($why === 'unknown') {
            cm_reply($ctx, cm_t('target_unknown'));
        } elseif ($min > 0 || trim($args) !== '') {
            cm_reply($ctx, cm_t('need_target'));
        } else {
            cm_game_top($ctx, 'chess', 'chess_top_head', 'chess_top_empty', true);
        }
        return;
    }
    if ($m && ($min < 1 || $min > CM_CHESS_MAX)) {
        cm_reply($ctx, cm_t('chess_minutes', ['max' => CM_CHESS_MAX]));
        return;
    }
    if (cm_botlike($t)) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $min = $min > 0 ? $min : CM_CHESS_DEFAULT;
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_offer_send($ctx, 'chess', (int)$t['id'], $min, cm_t('chess_offer', ['to' => $t['who'], 'from' => cm_who_from($from), 'min' => $min]));
}

// «Принять»: жребий цвета, партия, в сообщении — кто чем играет и кнопка доски.
function cm_offer_yes_chess(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    if (!cm_chess_ready($pdo)) { return 'not_ready2'; }
    if (!preg_match('/^[A-Za-z0-9_]{3,64}\z/', (string)$ctx['bot'])) { return 'chess_no_bot'; }
    $min = $o['ref_id'] >= 1 && $o['ref_id'] <= CM_CHESS_MAX ? $o['ref_id'] : CM_CHESS_DEFAULT;
    $fromWhite = cm_rand($ctx, 0, 1) === 0;
    $ms = (int)$ctx['now'] * 1000;
    $base = $min * 60000;
    $pdo->prepare("INSERT INTO cm_chess (chat_id, white_id, black_id, base_ms, white_ms, black_ms, fen, moves, san,
                   turn_at, due_at, draw_by, msg_id, status, result, reason, rev, created_at)
                   VALUES (:c, :w, :b, :b1, :b2, :b3, :fen, '', '', :t, :due, '', :m, 'on', '', '', 0, :n)")
        ->execute([':c' => $o['chat_id'], ':w' => $fromWhite ? $o['from_id'] : $o['to_id'], ':b' => $fromWhite ? $o['to_id'] : $o['from_id'],
                   ':b1' => $base, ':b2' => $base, ':b3' => $base, ':fen' => CHESS_START, ':t' => $ms, ':due' => $ms + CM_CHESS_FIRST * 1000,
                   ':m' => $o['msg_id'], ':n' => (int)$ctx['now']]);
    $g = cm_chess_get($pdo, (int)$pdo->lastInsertId());
    cm_edit($ctx, $g['msg_id'], cm_chess_start_text($pdo, $g), cm_chess_buttons((string)$ctx['bot'], $g['id']));
    return '';
}
