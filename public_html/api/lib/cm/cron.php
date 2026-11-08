<?php
// Работа раз в минуту (api/cm_cron.php): таймеры и автоудаление ответов.

// Таймер выполняется от имени автора с его ТЕКУЩИМ рангом. Строка
// помечается выполненной до запуска: второй cron, начатый внахлёст, её не
// возьмёт.
function cm_timers_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    $st = $pdo->prepare('SELECT * FROM cm_timers WHERE done_at = 0 AND run_at <= :n ORDER BY run_at, id LIMIT 50');
    $st->execute([':n' => $now]);
    $claim = $pdo->prepare('UPDATE cm_timers SET done_at = :n WHERE id = :id AND done_at = 0');
    $botName = ltrim((string)($cfg['tg_bot_name'] ?? ''), '@');
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $claim->execute([':n' => $now, ':id' => (int)$t['id']]);
        if ($claim->rowCount() === 0) { continue; }
        $chat = cm_chat_row($pdo, (int)$t['chat_id']);
        if ($chat === null || $chat['active'] !== 1) { continue; }
        $by = (int)$t['by_id'];
        $body = (string)$t['body'];
        $msg = ['message_id' => 0, 'chat' => ['id' => $chat['chat_id'], 'type' => 'supergroup', 'title' => $chat['title']],
                'from' => ['id' => $by, 'first_name' => cm_user_name($pdo, $by)], 'text' => $body];
        $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
        $ctx['timer'] = true;
        cm_admins_refresh($ctx);
        $ctx['rank'] = cm_rank($ctx, $by);
        $cmd = cm_parse($body, $botName);
        if ($cmd !== null) {
            $cmd['prefixed'] = true;
            cm_run_command($ctx, $cmd);
        } else {
            cm_say($ctx, (int)$chat['chat_id'], $body);
        }
        $n++;
    }
    return $n;
}

// games — закрытые приглашения и ходы дуэлей этапа 3 (offers.php).
function cm_cron_tick(PDO $pdo, array $cfg, callable $send, int $now): array {
    return ['timers' => cm_timers_tick($pdo, $cfg, $send, $now), 'deleted' => cm_queue_tick($pdo, $send, $now),
            'games' => cm_games_tick($pdo, $cfg, $send, $now)];
}
