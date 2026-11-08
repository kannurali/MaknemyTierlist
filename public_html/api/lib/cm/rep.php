<?php
// Репутация в чате (этап 2): ответ «+», «-», «спасибо» на чужое сообщение
// или команды +реп / -реп. Одному человеку — раз в 24 часа, всего — не больше
// 20 голосов за 24 часа от одного человека в чате.

const CM_REP_PAIR_GAP = 86400;
const CM_REP_DAY_MAX  = 20;
// Первая строка ответа целиком (после cm_norm и без «!») → голос.
const CM_REP_WORDS    = ['+' => 1, '-' => -1, 'спасибо' => 1, 'спс' => 1, 'благодарю' => 1];
// Служебный аккаунт Telegram: от его имени приходят автопересылки из канала.
const CM_TG_SERVICE_ID = 777000;

function cm_rep_score(PDO $pdo, int $chatId, int $userId): int {
    $st = $pdo->prepare('SELECT score FROM cm_rep WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    return (int)$st->fetchColumn();
}

// Записать голос автора контекста. '' — записан, иначе ключ текста отказа.
function cm_rep_vote(array $ctx, int $toId, bool $toBot, int $delta): string {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $from = (int)$ctx['actor'];
    $now = (int)$ctx['now'];
    if ($toBot || $toId === CM_TG_SERVICE_ID) { return 'target_bot'; }
    if ($toId <= 0 || $toId === $from) { return 'target_self'; }
    $since = $now - CM_REP_PAIR_GAP;
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_rep_log WHERE chat_id = :c AND from_id = :f AND to_id = :t AND at > :s');
    $st->execute([':c' => $chatId, ':f' => $from, ':t' => $toId, ':s' => $since]);
    if ((int)$st->fetchColumn() > 0) { return 'rep_pair_wait'; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_rep_log WHERE chat_id = :c AND from_id = :f AND at > :s');
    $st->execute([':c' => $chatId, ':f' => $from, ':s' => $since]);
    if ((int)$st->fetchColumn() >= CM_REP_DAY_MAX) { return 'rep_day_full'; }
    $pdo->prepare('INSERT INTO cm_rep_log (chat_id, from_id, to_id, delta, at) VALUES (:c, :f, :t, :d, :a)')
        ->execute([':c' => $chatId, ':f' => $from, ':t' => $toId, ':d' => $delta, ':a' => $now]);
    $p = [':c' => $chatId, ':u' => $toId, ':d' => $delta];
    $up = $pdo->prepare('UPDATE cm_rep SET score = score + :d WHERE chat_id = :c AND user_id = :u');
    $up->execute($p);
    if ($up->rowCount() > 0) { return ''; }
    try {
        $pdo->prepare('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (:c, :u, :d)')->execute($p);
    } catch (PDOException $e) {
        $up->execute($p);   // параллельный голос уже завёл строку
    }
    return '';
}

// Временный ответ: «👍 Вася → Петя: +1 (репутация 15)».
function cm_rep_done(array $ctx, string $toName, int $toId, int $delta): void {
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_reply($ctx, cm_t($delta > 0 ? 'rep_up' : 'rep_down', ['from' => cm_display_name($from), 'to' => $toName,
        'score' => cm_rep_score($ctx['pdo'], (int)$ctx['chat']['chat_id'], $toId)]));
}

/**
 * Голос ответом. Вызывает роутер до разбора команд. true — это было
 * голосование, даже отклонённое: сообщение дальше не идёт. Отказ — молча:
 * это реплика в живом разговоре. false — обычное сообщение (не ответ, лишние
 * слова, раздел выключен или ранга мало, нет таблиц этапа 2).
 */
function cm_rep_try(array $ctx): bool {
    $msg = $ctx['msg'];
    $reply = cm_reply_of($msg);
    if ($reply === null || !is_array($reply['from'] ?? null)) { return false; }
    $first = explode("\n", (string)($msg['text'] ?? ''))[0];
    $word = cm_norm(trim(str_replace('!', '', $first)));
    if (!isset(CM_REP_WORDS[$word])) { return false; }
    $need = cm_need_level($ctx['chat'], 'репутация', cm_section_default('репутация'));
    if ($need >= 6 || (int)$ctx['rank'] < $need || !cm_ready2($ctx['pdo'])) { return false; }
    $f = $reply['from'];
    $delta = CM_REP_WORDS[$word];
    if (cm_rep_vote($ctx, (int)($f['id'] ?? 0), !empty($f['is_bot']), $delta) === '') {
        cm_rep_done($ctx, cm_display_name($f), (int)$f['id'], $delta);
    }
    return true;
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_rep_plus(array $ctx, array $cmd): void { cm_rep_cmd($ctx, $cmd, 1); }
function cm_cmd_rep_minus(array $ctx, array $cmd): void { cm_rep_cmd($ctx, $cmd, -1); }

// Командой отказ поясняется: человек сам попросил.
function cm_rep_cmd(array $ctx, array $cmd, int $delta): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_rep_vote($ctx, (int)$t['id'], !empty($t['is_bot']), $delta);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err, ['max' => CM_REP_DAY_MAX]));
        return;
    }
    cm_rep_done($ctx, (string)$t['name'], (int)$t['id'], $delta);
}

function cm_cmd_rep_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    cm_reply($ctx, cm_t('rep_show', ['name' => $t['name'],
        'score' => cm_rep_score($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'])]));
}

// 10 лучших с ненулевой репутацией, кто не покинул чат.
function cm_cmd_rep_top(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT r.user_id, r.score FROM cm_rep r
        LEFT JOIN cm_members m ON m.chat_id = r.chat_id AND m.user_id = r.user_id
        WHERE r.chat_id = :c AND r.score <> 0 AND (m.left_at IS NULL OR m.left_at = 0)
        ORDER BY r.score DESC, r.user_id LIMIT 10');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('rep_top_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, (int)$r['user_id']), 'score' => (int)$r['score']]);
    }
    cm_reply($ctx, $lines ? cm_t('rep_top_head') . "\n" . implode("\n", $lines) : cm_t('rep_top_empty'), false);
}
