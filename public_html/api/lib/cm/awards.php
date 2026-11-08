<?php
// Награды (этап 2): степени 1–8, причина обязательна, срок — по желанию
// («на 30 дней»). Выдают и снимают с ранга 3: раздел «награды», «дк награды 2»
// пускает с ранга 2 — отдельных «награждающих» нет. Смотрят все: команда
// «награды» вне раздела, как «заметки» и «правила» в этапе 1.

const CM_AWARD_ICONS      = [1 => '🎗', 2 => '🏅', 3 => '🥉', 4 => '🥈', 5 => '🥇', 6 => '🎖', 7 => '🏆', 8 => '👑'];
const CM_AWARDS_MAX       = 20;
const CM_AWARD_REASON_MAX = 100;
const CM_AWARDS_BRIEF     = 5;

// Действующие награды (не сняты, не истекли): старшие раньше, при равной
// степени — новые раньше.
// $chats — чат или список чатов сети (cm_scope): награды общие.
function cm_awards_active(PDO $pdo, $chats, int $userId, int $now): array {
    $st = $pdo->prepare('SELECT id, level, reason, until_at FROM cm_awards WHERE chat_id IN (' . cm_in($chats) . ') AND user_id = :u
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n) ORDER BY level DESC, id DESC');
    $st->execute([':u' => $userId, ':n' => $now]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'level' => (int)$r['level'], 'reason' => (string)$r['reason'], 'until_at' => (int)$r['until_at']];
    }
    return $out;
}

// «🥇 Лучший трейдер»; в списке — с номером и сроком.
function cm_award_line(array $a, bool $full): string {
    $s = (CM_AWARD_ICONS[$a['level']] ?? '🏅') . ' ' . $a['reason'];
    if (!$full) { return $s; }
    return '#' . $a['id'] . ' ' . $s . ($a['until_at'] > 0 ? cm_t('award_until', ['date' => cm_date($a['until_at'])]) : '');
}

// Для профиля: «🥇 Лучший трейдер, 🏅 Помощь новичкам и ещё 2».
function cm_awards_brief(array $list): string {
    $shown = array_map(function ($a) { return cm_award_line($a, false); }, array_slice($list, 0, CM_AWARDS_BRIEF));
    $more = count($list) - count($shown);
    return implode(', ', $shown) . ($more > 0 ? cm_t('awards_more', ['n' => $more]) : '');
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// Цель для выдачи: не бот и не сам себе. null — ответ уже ушёл.
function cm_award_target(array $ctx, string &$args): ?array {
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return null; }
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return null;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return null;
    }
    return $t;
}

// Срок «на 30 дней» в начале $rest съедается; ответ — секунды, 0 — без срока
// (или «навсегда»), -1 — срок вне пределов Telegram. Срок — только после
// «на»: иначе причина «100 побед» стала бы сроком в 100 минут, а «на
// память» без срока остаётся причиной.
function cm_award_take_term(string &$rest): int {
    if (!preg_match('~^на\s+~u', cm_norm($rest), $nm)) { return 0; }
    $after = substr($rest, strlen($nm[0]));
    $term = cm_take_term($after);
    if ($term === null) { return 0; }
    if ($term !== -1) { $rest = $after; }
    return $term;
}

// «+награда 5 @ник на 30 дней причина» — всё одной строкой, степень
// обязательна.
function cm_cmd_award_add(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_award_target($ctx, $args);
    if ($t === null) { return; }
    if (!preg_match('~^([1-8])(?![0-9])\s*(.*)$~su', trim($args), $m)) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    $rest = $m[2];
    $term = cm_award_take_term($rest);
    if ($term === -1) {
        cm_reply($ctx, cm_t('term_bad'));
        return;
    }
    $reason = trim($rest);
    if ($reason === '' || mb_strlen($reason) > CM_AWARD_REASON_MAX) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    cm_award_insert($ctx, $t, (int)$m[1], $term, $reason);
}

// «Наградить» (как у Ириса): ответом или с @ником, причина — строкой ниже.
// В первой строке по желанию степень и срок: «наградить 5 @ник на 30 дней»;
// без степени — 1-я. Второй строки нет — причина берётся из остатка первой,
// а есть — в первой после цели, степени и срока ничего не остаётся. Строки
// причины сливаются в одну: список наград построчный.
function cm_cmd_award_give(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_award_target($ctx, $args);
    if ($t === null) { return; }
    $rest = trim($args);
    $level = 1;
    if (preg_match('~^([1-8])(?![0-9])\s*(.*)$~su', $rest, $m)) {
        $level = (int)$m[1];
        $rest = $m[2];
    }
    $term = cm_award_take_term($rest);
    if ($term === -1) {
        cm_reply($ctx, cm_t('term_bad'));
        return;
    }
    $rest = trim($rest);
    $body = trim((string)preg_replace('~\s+~u', ' ', $cmd['body']));
    $reason = $body === '' ? $rest : ($rest === '' ? $body : '');
    if ($reason === '' || mb_strlen($reason) > CM_AWARD_REASON_MAX) {
        cm_reply($ctx, cm_t('award_give_usage'));
        return;
    }
    cm_award_insert($ctx, $t, $level, $term, $reason);
}

// Записать награду и объявить; $term — секунды срока, 0 — бессрочно.
function cm_award_insert(array $ctx, array $t, int $level, int $term, string $reason): void {
    $until = $term > 0 ? (int)$ctx['now'] + $term : 0;
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    if (count(cm_awards_active($pdo, cm_scope($ctx), (int)$t['id'], (int)$ctx['now'])) >= CM_AWARDS_MAX) {
        cm_reply($ctx, cm_t('award_full', ['name' => $t['tag'], 'max' => CM_AWARDS_MAX]));
        return;
    }
    $pdo->prepare('INSERT INTO cm_awards (chat_id, user_id, level, reason, by_id, created_at, until_at, removed_at)
                   VALUES (:c, :u, :l, :r, :b, :n, :until, 0)')
        ->execute([':c' => $chatId, ':u' => (int)$t['id'], ':l' => $level, ':r' => $reason, ':b' => (int)$ctx['actor'],
                   ':n' => (int)$ctx['now'], ':until' => $until]);
    cm_reply($ctx, cm_t('award_done', ['icon' => CM_AWARD_ICONS[$level], 'name' => $t['who'], 'level' => $level, 'reason' => $reason,
        'until' => $until > 0 ? cm_t('award_until', ['date' => cm_date($until)]) : '']), false);
}

function cm_cmd_award_del(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $id = preg_match('~^#?([0-9]{1,9})$~', trim($cmd['args']), $m) ? (int)$m[1] : 0;
    $now = (int)$ctx['now'];
    $st = $ctx['pdo']->prepare('UPDATE cm_awards SET removed_at = :n WHERE id = :id AND chat_id IN (' . cm_in(cm_scope($ctx)) . ')
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n2)');
    $st->execute([':n' => $now, ':id' => $id, ':n2' => $now]);
    $done = $st->rowCount() > 0;
    cm_reply($ctx, cm_t($done ? 'award_removed' : 'award_missing', ['id' => $id]), !$done);
}

function cm_cmd_awards_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $list = cm_awards_active($ctx['pdo'], cm_scope($ctx), (int)$t['id'], (int)$ctx['now']);
    if (!$list) {
        cm_reply($ctx, cm_t('awards_none', ['name' => $t['tag']]));
        return;
    }
    $lines = [cm_t('awards_head', ['name' => $t['tag']])];
    foreach ($list as $a) { $lines[] = cm_award_line($a, true); }
    cm_reply($ctx, implode("\n", $lines), false);
}
