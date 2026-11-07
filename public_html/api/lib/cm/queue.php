<?php
// Удаление пачками и очередь служебных ответов бота (удаляются через минуту,
// разбирает cron — api/cm_cron.php).

function cm_queue_add(PDO $pdo, int $chatId, int $msgId, int $at): void {
    try {
        $pdo->prepare('INSERT INTO cm_queue (chat_id, message_id, delete_at) VALUES (:c, :m, :a)')
            ->execute([':c' => $chatId, ':m' => $msgId, ':a' => $at]);
    } catch (PDOException $e) {
        // уже в очереди
    }
}

// deleteMessages берёт до 100 id; несуществующие Telegram пропускает сам.
// false — бота лишили прав на удаление.
function cm_delete_many(callable $send, int $chatId, array $ids): bool {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ok = true;
    foreach (array_chunk($ids, 100) as $chunk) {
        if (cm_tg_error($send('deleteMessages', ['chat_id' => $chatId, 'message_ids' => $chunk])) === 'no_rights') {
            $ok = false;
        }
    }
    return $ok;
}

// Удалить созревшие ответы и вычистить сообщения старше 48 часов.
function cm_queue_tick(PDO $pdo, callable $send, int $now): int {
    $st = $pdo->prepare('SELECT chat_id, message_id FROM cm_queue WHERE delete_at <= :n ORDER BY delete_at, message_id LIMIT 500');
    $st->execute([':n' => $now]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $by[(int)$r['chat_id']][] = (int)$r['message_id']; }
    $del = $pdo->prepare('DELETE FROM cm_queue WHERE chat_id = :c AND message_id = :m');
    $n = 0;
    foreach ($by as $chatId => $ids) {
        cm_delete_many($send, (int)$chatId, $ids);
        foreach ($ids as $m) {
            $del->execute([':c' => $chatId, ':m' => $m]);
            $n++;
        }
    }
    $pdo->prepare('DELETE FROM cm_msgs WHERE at < :t')->execute([':t' => $now - CM_MSG_KEEP]);
    return $n;
}
