<?php
// Репутация (этап 2): ответ «+», «-», «спасибо» на чужое сообщение или
// команды +реп / -реп. Одна на все чаты Макнеми (cm_net, db.php): голос
// пишется в чат, где его отдали, а счёт и пределы — по всем. Одному человеку —
// раз в 24 часа, всего — не больше 20 голосов за 24 часа от одного человека.

const CM_REP_PAIR_GAP = 86400;
const CM_REP_DAY_MAX  = 20;
// Первая строка ответа целиком (после cm_norm и без «!») → голос.
const CM_REP_WORDS    = ['+' => 1, '-' => -1, 'спасибо' => 1, 'спс' => 1, 'благодарю' => 1];
// Служебный аккаунт Telegram: от его имени приходят автопересылки из канала.
const CM_TG_SERVICE_ID = 777000;

// Репутация человека — сумма по $chats (чат или список чатов).
function cm_rep_score(PDO $pdo, $chats, int $userId): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(score), 0) FROM cm_rep WHERE chat_id IN (' . cm_in($chats) . ') AND user_id = :u');
    $st->execute([':u' => $userId]);
    return (int)$st->fetchColumn();
}

// Записать голос автора контекста. '' — записан, иначе ключ текста отказа.
// Пределы — по всем чатам Макнеми: иначе тому же человеку можно было бы
// голосовать в каждом чате.
function cm_rep_vote(array $ctx, int $toId, bool $toBot, int $delta): string {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $from = (int)$ctx['actor'];
    $now = (int)$ctx['now'];
    if ($toBot || $toId === CM_TG_SERVICE_ID) { return 'target_bot'; }
    if ($toId <= 0 || $toId === $from) { return 'target_self'; }
    $in = cm_in(cm_net($pdo, $chatId));
    $since = $now - CM_REP_PAIR_GAP;
    $st = $pdo->prepare("SELECT COUNT(*) FROM cm_rep_log WHERE chat_id IN ($in) AND from_id = :f AND to_id = :t AND at > :s");
    $st->execute([':f' => $from, ':t' => $toId, ':s' => $since]);
    if ((int)$st->fetchColumn() > 0) { return 'rep_pair_wait'; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM cm_rep_log WHERE chat_id IN ($in) AND from_id = :f AND at > :s");
    $st->execute([':f' => $from, ':s' => $since]);
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
    cm_reply($ctx, cm_t($delta > 0 ? 'rep_up' : 'rep_down', ['from' => cm_who_from($from), 'to' => $toName,
        'score' => cm_rep_score($ctx['pdo'], cm_net($ctx['pdo'], (int)$ctx['chat']['chat_id']), $toId)]));
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
        cm_rep_done($ctx, cm_who_from($f), (int)$f['id'], $delta);
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
    cm_rep_done($ctx, (string)$t['who'], (int)$t['id'], $delta);
}

function cm_cmd_rep_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    cm_reply($ctx, cm_t('rep_show', ['name' => $t['tag'],
        'score' => cm_rep_score($ctx['pdo'], cm_net($ctx['pdo'], (int)$ctx['chat']['chat_id']), (int)$t['id'])]));
}

// 10 лучших с ненулевой репутацией по всем чатам Макнеми, кто не ушёл из них
// всех (cm_gone_sql, stats.php).
function cm_cmd_rep_top(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $in = cm_in(cm_net($pdo, (int)$ctx['chat']['chat_id']));
    $st = $pdo->query("SELECT r.user_id AS user_id, SUM(r.score) AS total FROM cm_rep r
        WHERE r.chat_id IN ($in) AND r.user_id NOT IN (" . cm_gone_sql($in) . ')
        GROUP BY r.user_id HAVING SUM(r.score) <> 0
        ORDER BY total DESC, r.user_id LIMIT 10');
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('rep_top_line', ['n' => $i + 1, 'name' => cm_user_who($pdo, (int)$r['user_id'], true), 'score' => (int)$r['total']]);
    }
    cm_reply($ctx, $lines ? cm_t('rep_top_head') . "\n" . implode("\n", $lines) : cm_t('rep_top_empty'), false);
}
