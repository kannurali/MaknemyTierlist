<?php
// Вход участников, приветствие и правила, статус самого бота в группе.
// Приветствие — простой текст: подстановка {имя} сдвигала бы разметку
// Telegram. {имя} — ники новичков со ссылкой на профиль (cm_who). У правил
// разметка сохраняется.

const CM_RAID_JOINS = 3;   // больше стольких входов за минуту — рейд

function cm_on_join(array $ctx, array $users): void {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $tpl = trim((string)$ctx['chat']['settings']['welcome']);
    $names = [];
    foreach ($users as $u) {
        if (!is_array($u) || !empty($u['is_bot'])) { continue; }
        $id = (int)($u['id'] ?? 0);
        if ($id <= 0) { continue; }
        cm_user_touch($pdo, $u, $now);
        cm_member_touch($pdo, $chatId, $id, $now, true);
        if (!empty($ctx['chat']['settings']['filters']['spam']) && cm_spam_has($pdo, $id)) {
            cm_apply_actions($ctx, $id, 0, cm_trigger_actions($ctx['chat'], 'spam'), cm_t('reason_spam'), 0);
            continue;
        }
        if ($tpl === '') { continue; }
        $st = $pdo->prepare('UPDATE cm_members SET greeted_at = :n WHERE chat_id = :c AND user_id = :u AND greeted_at = 0');
        $st->execute([':n' => $now, ':c' => $chatId, ':u' => $id]);
        if ($st->rowCount() > 0) { $names[] = cm_who_from($u); }
    }
    if (!$names) { return; }
    $who = implode(', ', $names);
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_members WHERE chat_id = :c AND joined_at > :t');
    $st->execute([':c' => $chatId, ':t' => $now - 60]);
    if ((int)$st->fetchColumn() > CM_RAID_JOINS) {
        $s = $ctx['chat']['settings'];
        if ((int)$s['raid_greet_at'] > $now - 60) { return; }
        $s['raid_greet_at'] = $now;
        cm_settings_save($pdo, $ctx['chat'], $s, $now);
        $who = cm_t('welcome_many');
    }
    cm_say($ctx, $chatId, strtr($tpl, ['{имя}' => $who, '{чат}' => (string)$ctx['chat']['title']]));
}

function cm_on_left(array $ctx, array $user): void {
    $id = (int)($user['id'] ?? 0);
    if ($id > 0) { cm_member_set($ctx['pdo'], (int)$ctx['chat']['chat_id'], $id, ['left_at' => (int)$ctx['now']]); }
}

// Каких прав админа боту не хватает. Не админ — не хватает всех.
function cm_missing_rights(array $member): array {
    $out = [];
    $admin = ($member['status'] ?? '') === 'administrator';
    if (!$admin || empty($member['can_delete_messages'])) { $out[] = 'Удаление сообщений'; }
    if (!$admin || empty($member['can_restrict_members'])) { $out[] = 'Блокировка пользователей'; }
    return $out;
}

/**
 * my_chat_member — бота добавили, повысили или убрали; chat_member — человек
 * вошёл или вышел (на бою этот апдейт доходит не всегда, поэтому главный
 * источник входов — служебное сообщение, router.php). Только группы.
 */
function cm_on_membership(PDO $pdo, array $cfg, callable $send, array $update, int $now): void {
    if (!cm_enabled($cfg) || !cm_ready($pdo)) { return; }
    $bot = is_array($update['my_chat_member'] ?? null);
    $m = $bot ? $update['my_chat_member'] : ($update['chat_member'] ?? null);
    if (!is_array($m) || !is_array($m['chat'] ?? null)) { return; }
    if (!in_array((string)($m['chat']['type'] ?? ''), ['group', 'supergroup'], true)) { return; }
    $chatId = (int)$m['chat']['id'];
    $new = is_array($m['new_chat_member'] ?? null) ? $m['new_chat_member'] : [];
    $old = is_array($m['old_chat_member'] ?? null) ? $m['old_chat_member'] : [];
    $ns = (string)($new['status'] ?? '');
    $os = (string)($old['status'] ?? '');
    $gone = in_array($ns, ['left', 'kicked'], true);
    $wasOut = in_array($os, ['left', 'kicked', ''], true);
    if ($bot && $gone) {
        $pdo->prepare('UPDATE cm_chats SET active = 0, updated_at = :n WHERE chat_id = :c')->execute([':n' => $now, ':c' => $chatId]);
        return;
    }
    $chat = cm_chat_ensure($pdo, $m['chat'], $now);
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, ['chat' => $m['chat'], 'from' => is_array($m['from'] ?? null) ? $m['from'] : []], $now);
    if ($bot) {
        cm_admins_refresh($ctx, true);
        $missing = cm_missing_rights($new);
        if ($wasOut) {
            $text = cm_bold(cm_t('intro_title')) . "\n" . cm_t('intro') . "\n\n" . cm_guide_line();
            if ($missing) { $text .= "\n\n" . cm_t('need_rights_list', ['rights' => implode("\n• ", $missing)]); }
            cm_say($ctx, $chatId, $text);
        } elseif ($ns === 'administrator' && !$missing && $os !== 'administrator') {
            cm_say($ctx, $chatId, cm_t('rights_ok'));
        }
        return;
    }
    $user = is_array($new['user'] ?? null) ? $new['user'] : [];
    if ($gone) {
        cm_on_left($ctx, $user);
        return;
    }
    if ($wasOut && in_array($ns, ['member', 'restricted'], true)) { cm_on_join($ctx, [$user]); }
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_welcome_set(array $ctx, array $cmd): void {
    $body = trim($cmd['body']);
    if ($body === '' || mb_strlen($body) > 2000) {
        cm_reply($ctx, cm_t('welcome_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['welcome'] = $body;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('welcome_done'), false);
}

function cm_cmd_welcome_del(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $s['welcome'] = '';
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('welcome_removed'), false);
}

function cm_cmd_welcome_show(array $ctx, array $cmd): void {
    $w = (string)$ctx['chat']['settings']['welcome'];
    cm_reply($ctx, $w !== '' ? $w : cm_t('welcome_none'), $w === '');
}

function cm_cmd_greet_reset(array $ctx, array $cmd): void {
    $ctx['pdo']->prepare('UPDATE cm_members SET greeted_at = 0 WHERE chat_id = :c')->execute([':c' => $ctx['chat']['chat_id']]);
    cm_reply($ctx, cm_t('greet_reset_done'), false);
}

function cm_cmd_rules_set(array $ctx, array $cmd): void {
    $body = $cmd['body'];
    if (trim($body) === '' || mb_strlen($body) > 4000) {
        cm_reply($ctx, cm_t('rules_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['rules'] = ['text' => $body, 'entities' => cm_body_entities($ctx['msg'])];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('rules_done'), false);
}

function cm_cmd_rules_del(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $s['rules'] = ['text' => '', 'entities' => []];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('rules_removed'), false);
}

function cm_cmd_rules_show(array $ctx, array $cmd): void {
    $r = $ctx['chat']['settings']['rules'];
    $text = is_array($r) ? (string)($r['text'] ?? '') : '';
    if (trim($text) === '') {
        cm_reply($ctx, cm_t('rules_none'));
        return;
    }
    $ents = is_array($r['entities'] ?? null) ? $r['entities'] : [];
    cm_reply($ctx, $text, false, $ents ? ['entities' => $ents] : []);
}
