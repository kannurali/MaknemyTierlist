<?php
// Общая база спамеров. Вносят только сотрудники сайта: admin_ids и
// moderator_ids из config с привязанным Telegram (tg_links: Roblox id ↔ id
// лички, он же id человека в Telegram). Модераторы чужих групп в базу не
// пишут — иначе её быстро превратят в инструмент мести.

function cm_staff_roblox(PDO $pdo, int $tgUserId): string {
    try {
        $st = $pdo->prepare('SELECT user_id FROM tg_links WHERE chat_id = :c');
        $st->execute([':c' => $tgUserId]);
        $id = $st->fetchColumn();
    } catch (PDOException $e) {
        return '';
    }
    return $id === false ? '' : (string)$id;
}

function cm_is_staff(PDO $pdo, array $cfg, int $tgUserId): bool {
    $rid = cm_staff_roblox($pdo, $tgUserId);
    if ($rid === '') { return false; }
    return in_array($rid, config_id_list($cfg, 'admin_ids'), true)
        || in_array($rid, config_id_list($cfg, 'moderator_ids'), true);
}

function cm_spam_row(PDO $pdo, int $uid): ?array {
    try {
        $st = $pdo->prepare('SELECT * FROM cm_spam WHERE user_id = :u');
        $st->execute([':u' => $uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    return $r ?: null;
}

function cm_spam_has(PDO $pdo, int $uid): bool {
    return cm_spam_row($pdo, $uid) !== null;
}

// Забанить в других активных чатах, где бот этого человека видел и где фильтр
// «спамеры» включён. До 200 чатов за раз: Telegram пускает около 30 вызовов в
// секунду, а вызов идёт уже после ответа вебхуку.
function cm_spam_sweep(array $ctx, int $uid): int {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT m.chat_id FROM cm_members m JOIN cm_chats c ON c.chat_id = m.chat_id
        WHERE m.user_id = :u AND m.left_at = 0 AND c.active = 1 AND m.chat_id <> :cur LIMIT 200');
    $st->execute([':u' => $uid, ':cur' => (int)$ctx['chat']['chat_id']]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
        $chat = cm_chat_row($pdo, (int)$chatId);
        if ($chat === null || empty($chat['settings']['filters']['spam'])) { continue; }
        $c = $ctx;
        $c['chat'] = $chat;
        if (cm_punish($c, $uid, 'ban', 0, cm_t('reason_spam'), 0) === '') { $n++; }
    }
    return $n;
}

// Общая проверка: команда от сотрудника. Чужому — ответ только на префикс.
function cm_spam_staff(array $ctx, array $cmd): bool {
    if (cm_is_staff($ctx['pdo'], $ctx['cfg'], (int)$ctx['actor'])) { return true; }
    if ($cmd['prefixed']) { cm_reply($ctx, cm_t('spam_staff_only')); }
    return false;
}

function cm_cmd_spam_add(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot']) || (int)$t['id'] === (int)$ctx['actor'] || cm_is_staff($pdo, $ctx['cfg'], (int)$t['id'])) {
        cm_reply($ctx, cm_t('spam_bad_target'));
        return;
    }
    $p = [':u' => (int)$t['id'], ':r' => cm_reason($args, $cmd['body']),
          ':b' => cm_staff_roblox($pdo, (int)$ctx['actor']), ':n' => (int)$ctx['now']];
    $up = $pdo->prepare('UPDATE cm_spam SET reason = :r, added_by = :b, added_at = :n WHERE user_id = :u');
    $up->execute($p);
    if ($up->rowCount() === 0 && !cm_spam_has($pdo, (int)$t['id'])) {
        $pdo->prepare('INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (:u, :r, :b, :n)')->execute($p);
    }
    $n = 0;
    if (empty($ctx['chat']['private'])) {
        $done = cm_apply_actions($ctx, (int)$t['id'], (int)$t['msg_id'], cm_trigger_actions($ctx['chat'], 'spam'),
            cm_t('reason_spam'), (int)$ctx['actor']);
        if ($done !== '') { $n++; }
    }
    $n += cm_spam_sweep($ctx, (int)$t['id']);
    cm_reply($ctx, cm_t('spam_added', ['name' => $t['name'], 'n' => $n]), false);
}

function cm_cmd_spam_del(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $st = $ctx['pdo']->prepare('DELETE FROM cm_spam WHERE user_id = :u');
    $st->execute([':u' => (int)$t['id']]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'spam_removed' : 'spam_missing', ['name' => $t['name']]), false);
}

function cm_cmd_spam_check(array $ctx, array $cmd): void {
    if (!cm_spam_staff($ctx, $cmd)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $row = cm_spam_row($ctx['pdo'], (int)$t['id']);
    if ($row === null) {
        cm_reply($ctx, cm_t('spam_no', ['name' => $t['name']]), false);
        return;
    }
    cm_reply($ctx, cm_t('spam_yes', ['name' => $t['name'], 'date' => cm_date((int)$row['added_at']),
        'reason' => cm_reason_line((string)$row['reason'])]), false);
}
