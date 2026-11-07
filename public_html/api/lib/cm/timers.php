<?php
// Таймеры: «таймер через 2ч» или «таймер 25.12 18:00» (МСК), со второй
// строки — команда или текст. Срабатывают в cron (cm_timers_tick, cron.php)
// и перепроверяют ранг автора: снятый модератор не выстрелит из прошлого.

const CM_TIMERS_MAX      = 5;
const CM_TIMER_MAX_AHEAD = 31622400;

// Время запуска или -1. Дата без года, которая уже прошла, — в следующем году.
function cm_parse_when(string $args, int $now): int {
    $a = trim(cm_norm($args));
    if (preg_match('~^через\s+(.+)$~u', $a, $m)) {
        $rest = $m[1];
        $t = cm_take_term($rest);
        if ($t === null || $t <= 0 || trim($rest) !== '') { return -1; }
        $at = $now + $t;
    } elseif (preg_match('~^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?\s+(\d{1,2}):(\d{2})$~', $a, $m)) {
        $tz = new DateTimeZone(CM_TZ);
        $hasYear = isset($m[3]) && $m[3] !== '';
        $nowD = new DateTime('@' . $now);
        $nowD->setTimezone($tz);
        $year = $hasYear ? (int)$m[3] : (int)$nowD->format('Y');
        if (!checkdate((int)$m[2], (int)$m[1], $year) || (int)$m[4] > 23 || (int)$m[5] > 59) { return -1; }
        $d = new DateTime(sprintf('%04d-%02d-%02d %02d:%02d:00', $year, (int)$m[2], (int)$m[1], (int)$m[4], (int)$m[5]), $tz);
        if (!$hasYear && $d->getTimestamp() <= $now) { $d->modify('+1 year'); }
        $at = $d->getTimestamp();
    } else {
        return -1;
    }
    return ($at - $now < 60 || $at - $now > CM_TIMER_MAX_AHEAD) ? -1 : $at;
}

function cm_cmd_timer_add(array $ctx, array $cmd): void {
    $at = cm_parse_when($cmd['args'], (int)$ctx['now']);
    $body = trim($cmd['body']);
    if ($at < 0 || $body === '') {
        cm_reply($ctx, cm_t('timer_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_timers WHERE chat_id = :c AND done_at = 0');
    $st->execute([':c' => $chatId]);
    if ((int)$st->fetchColumn() >= CM_TIMERS_MAX) {
        cm_reply($ctx, cm_t('timer_full', ['max' => CM_TIMERS_MAX]));
        return;
    }
    $pdo->prepare('INSERT INTO cm_timers (chat_id, run_at, body, by_id, created_at, done_at) VALUES (:c, :r, :b, :by, :n, 0)')
        ->execute([':c' => $chatId, ':r' => $at, ':b' => mb_substr($body, 0, 4000), ':by' => (int)$ctx['actor'], ':n' => (int)$ctx['now']]);
    cm_reply($ctx, cm_t('timer_added', ['id' => (int)$pdo->lastInsertId(), 'date' => cm_date($at)]), false);
}

function cm_cmd_timer_list(array $ctx, array $cmd): void {
    $st = $ctx['pdo']->prepare('SELECT id, run_at, body FROM cm_timers WHERE chat_id = :c AND done_at = 0 ORDER BY run_at');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $first = explode("\n", (string)$r['body'])[0];
        $lines[] = '#' . (int)$r['id'] . ' · ' . cm_date((int)$r['run_at']) . ' · ' . mb_substr($first, 0, 40);
    }
    cm_reply($ctx, $lines ? cm_t('timers_head') . "\n" . implode("\n", $lines) : cm_t('timers_empty'), false);
}

function cm_cmd_timer_del(array $ctx, array $cmd): void {
    $id = preg_match('~^#?(\d{1,9})$~', trim($cmd['args']), $m) ? (int)$m[1] : 0;
    $st = $ctx['pdo']->prepare('UPDATE cm_timers SET done_at = :n WHERE id = :id AND chat_id = :c AND done_at = 0');
    $st->execute([':n' => $ctx['now'], ':id' => $id, ':c' => $ctx['chat']['chat_id']]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'timer_removed' : 'timer_missing', ['id' => $id]), $st->rowCount() === 0);
}
