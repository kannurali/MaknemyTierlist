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
function cm_awards_active(PDO $pdo, int $chatId, int $userId, int $now): array {
    $st = $pdo->prepare('SELECT id, level, reason, until_at FROM cm_awards WHERE chat_id = :c AND user_id = :u
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n) ORDER BY level DESC, id DESC');
    $st->execute([':c' => $chatId, ':u' => $userId, ':n' => $now]);
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

// «+награда 5 @ник на 30 дней причина». Срок — только после «на»: иначе
// причина «100 побед» стала бы сроком в 100 минут.
function cm_cmd_award_add(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    if (!preg_match('~^([1-8])(?![0-9])\s*(.*)$~su', trim($args), $m)) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    $level = (int)$m[1];
    $rest = $m[2];
    $until = 0;
    if (preg_match('~^на\s+~u', cm_norm($rest), $nm)) {
        $after = substr($rest, strlen($nm[0]));
        $term = cm_take_term($after);
        if ($term === -1) {
            cm_reply($ctx, cm_t('term_bad'));
            return;
        }
        if ($term !== null) {
            $rest = $after;
            $until = $term > 0 ? (int)$ctx['now'] + $term : 0;
        }
    }
    $reason = trim($rest);
    if ($reason === '' || mb_strlen($reason) > CM_AWARD_REASON_MAX) {
        cm_reply($ctx, cm_t('award_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    if (count(cm_awards_active($pdo, $chatId, (int)$t['id'], (int)$ctx['now'])) >= CM_AWARDS_MAX) {
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
    $st = $ctx['pdo']->prepare('UPDATE cm_awards SET removed_at = :n WHERE id = :id AND chat_id = :c
        AND removed_at = 0 AND (until_at = 0 OR until_at > :n2)');
    $st->execute([':n' => $now, ':id' => $id, ':c' => $ctx['chat']['chat_id'], ':n2' => $now]);
    $done = $st->rowCount() > 0;
    cm_reply($ctx, cm_t($done ? 'award_removed' : 'award_missing', ['id' => $id]), !$done);
}

function cm_cmd_awards_show(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $list = cm_awards_active($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'], (int)$ctx['now']);
    if (!$list) {
        cm_reply($ctx, cm_t('awards_none', ['name' => $t['tag']]));
        return;
    }
    $lines = [cm_t('awards_head', ['name' => $t['tag']])];
    foreach ($list as $a) { $lines[] = cm_award_line($a, true); }
    cm_reply($ctx, implode("\n", $lines), false);
}
