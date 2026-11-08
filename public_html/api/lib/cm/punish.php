<?php
// Наказания: варн, мут, кик, бан — в Telegram и в cm_punishments. Сроки
// мута и бана снимает сам Telegram (until_date), cron для них не нужен.

function cm_mute_perms(bool $can): array {
    return array_fill_keys(['can_send_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos',
        'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls',
        'can_send_other_messages', 'can_add_web_page_previews'], $can);
}

/**
 * Наказать и записать. Кик — бан и сразу разбан: человек может вернуться.
 * Варн — только запись. '' — вышло, иначе ключ ошибки (cm_tg_error).
 */
function cm_punish(array $ctx, int $userId, string $kind, int $secs, string $reason, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $send = $ctx['send'];
    $until = $secs > 0 ? $now + $secs : 0;
    $res = ['ok' => true];
    if ($kind === 'mute') {
        $res = $send('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'permissions' => cm_mute_perms(false), 'use_independent_chat_permissions' => false, 'until_date' => $until]);
    } elseif ($kind === 'ban' || $kind === 'kick') {
        $res = $send('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'until_date' => $kind === 'ban' ? $until : 0, 'revoke_messages' => false]);
        if ($kind === 'kick' && cm_ok($res)) {
            $send('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
        }
    }
    $err = cm_tg_error($res);
    if ($err !== '') { return $err; }
    $ctx['pdo']->prepare('INSERT INTO cm_punishments (chat_id, user_id, kind, reason, by_id, created_at, until_at, lifted_at, lifted_by)
                          VALUES (:c, :u, :k, :r, :b, :n, :t, 0, 0)')
        ->execute([':c' => $chatId, ':u' => $userId, ':k' => $kind, ':r' => mb_substr($reason, 0, 500), ':b' => $byId,
                   ':n' => $now, ':t' => ($kind === 'mute' || $kind === 'ban') ? $until : 0]);
    if ($kind === 'ban' || $kind === 'kick') { cm_member_set($ctx['pdo'], $chatId, $userId, ['left_at' => $now]); }
    return '';
}

function cm_lift(array $ctx, int $userId, string $kind, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    if ($kind === 'mute') {
        $res = ($ctx['send'])('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'permissions' => cm_mute_perms(true), 'use_independent_chat_permissions' => false]);
    } else {
        $res = ($ctx['send'])('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
    }
    $err = cm_tg_error($res);
    if ($err !== '') { return $err; }
    $ctx['pdo']->prepare('UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b
                          WHERE chat_id = :c AND user_id = :u AND kind = :k AND lifted_at = 0')
        ->execute([':n' => $ctx['now'], ':b' => $byId, ':c' => $chatId, ':u' => $userId, ':k' => $kind]);
    return '';
}

// --------------------------------------------------------------------------
//  Варны
// --------------------------------------------------------------------------

function cm_warns_since(array $ctx): int {
    $ttl = (int)$ctx['chat']['settings']['warns']['ttl'];
    return $ttl > 0 ? (int)$ctx['now'] - $ttl : 0;
}

function cm_warns_active(array $ctx, int $userId): int {
    $st = $ctx['pdo']->prepare("SELECT COUNT(*) FROM cm_punishments
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0 AND created_at > :s");
    $st->execute([':c' => $ctx['chat']['chat_id'], ':u' => $userId, ':s' => cm_warns_since($ctx)]);
    return (int)$st->fetchColumn();
}

// Варн. Набран лимит — наказание из триггера «варны», активные варны снимаются.
function cm_warn(array $ctx, int $userId, string $reason, int $byId): array {
    $err = cm_punish($ctx, $userId, 'warn', 0, $reason, $byId);
    if ($err !== '') { return [$err, 0, 0, '']; }
    $limit = max(1, (int)$ctx['chat']['settings']['warns']['limit']);
    $n = cm_warns_active($ctx, $userId);
    if ($n < $limit) { return ['', $n, $limit, '']; }
    $ctx['pdo']->prepare("UPDATE cm_punishments SET lifted_at = :n, lifted_by = 0
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':c' => $ctx['chat']['chat_id'], ':u' => $userId]);
    $actions = array_values(array_filter(cm_trigger_actions($ctx['chat'], 'warns'), function ($a) { return $a['kind'] !== 'warn'; }));
    return ['', $n, $limit, cm_apply_actions($ctx, $userId, 0, $actions, cm_t('reason_warn_limit'), 0)];
}

// --------------------------------------------------------------------------
//  Действия триггеров
// --------------------------------------------------------------------------

function cm_trigger_actions(array $chat, string $key): array {
    $t = $chat['settings']['triggers'][$key] ?? null;
    if (is_array($t) && $t) { return $t; }
    if ($key === 'spam') { return [['kind' => 'delete', 'secs' => 0], ['kind' => 'ban', 'secs' => 0]]; }
    if ($key === 'warns') { return [['kind' => 'ban', 'secs' => 604800]]; }
    return [['kind' => 'delete', 'secs' => 0]];
}

// «удалить, мут 1ч» → действия; null — не разобралось. Для триггера «варны»
// варн запрещён: он бы зациклился.
function cm_parse_actions(string $s, bool $forWarns): ?array {
    $names = ['удалить' => 'delete', 'варн' => 'warn', 'мут' => 'mute', 'кик' => 'kick', 'бан' => 'ban'];
    $out = [];
    foreach (preg_split('~\s*,\s*~u', trim($s)) as $part) {
        $p = cm_norm(trim((string)$part));
        if ($p === '') { continue; }
        if (!preg_match('~^(удалить|варн|мут|кик|бан)(?:\s+(.+))?$~u', $p, $m)) { return null; }
        $kind = $names[$m[1]];
        $rest = $m[2] ?? '';
        $secs = 0;
        if ($kind === 'mute' || $kind === 'ban') {
            $term = $rest === '' ? null : cm_take_term($rest);
            if ($term === -1 || trim($rest) !== '') { return null; }
            $secs = $term ?? ($kind === 'mute' ? 3600 : 0);
        } elseif ($rest !== '') {
            return null;
        }
        if ($forWarns && $kind === 'warn') { return null; }
        $out[] = ['kind' => $kind, 'secs' => $secs];
    }
    return ($out && count($out) <= 3) ? $out : null;
}

function cm_action_text(array $a): string {
    $names = ['delete' => 'удалить', 'warn' => 'варн', 'mute' => 'мут', 'kick' => 'кик', 'ban' => 'бан'];
    $k = (string)$a['kind'];
    $s = $names[$k] ?? $k;
    if ($k === 'mute' || $k === 'ban') { $s .= ' ' . cm_term_text((int)$a['secs']); }
    return $s;
}

function cm_actions_text(array $actions): string {
    return implode(', ', array_map('cm_action_text', $actions));
}

// Выполнить действия. Удаление первым: нарушение не должно висеть, пока идут
// остальные вызовы. Возвращает, что сделано, без «удалить».
function cm_apply_actions(array $ctx, int $userId, int $msgId, array $actions, string $reason, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    foreach ($actions as $a) {
        if ($a['kind'] !== 'delete' || $msgId <= 0) { continue; }
        $res = ($ctx['send'])('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
        if (cm_tg_error($res) === 'no_rights') { cm_rights_warn($ctx); }
    }
    $done = [];
    foreach ($actions as $a) {
        if ($a['kind'] === 'delete') { continue; }
        if ($a['kind'] === 'warn') {
            [$err, $n, $limit, $after] = cm_warn($ctx, $userId, $reason, $byId);
            if ($err === '') { $done[] = cm_t('done_warn', ['n' => $n, 'limit' => $limit]) . ($after !== '' ? ' → ' . $after : ''); }
            continue;
        }
        $err = cm_punish($ctx, $userId, (string)$a['kind'], (int)$a['secs'], $reason, $byId);
        if ($err === 'no_rights') { cm_rights_warn($ctx); }
        if ($err === '') { $done[] = cm_action_text($a); }
    }
    return implode(', ', $done);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_warn(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'warn'); }
function cm_cmd_mute(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'mute'); }
function cm_cmd_kick(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'kick'); }
function cm_cmd_ban(array $ctx, array $cmd): void { cm_punish_cmd($ctx, $cmd, 'ban'); }

function cm_punish_cmd(array $ctx, array $cmd, string $kind): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $why = cm_can_touch($ctx, $t);
    if ($why !== '') {
        cm_reply($ctx, cm_t($why));
        return;
    }
    $secs = 0;
    if ($kind === 'mute' || $kind === 'ban') {
        $term = cm_take_term($args);
        if ($term === -1) {
            cm_reply($ctx, cm_t('term_bad'));
            return;
        }
        $secs = $term ?? ($kind === 'mute' ? 3600 : 0);
    }
    $reason = cm_reason($args, $cmd['body']);
    $vars = ['name' => $t['who'], 'term' => cm_term_text($secs), 'reason' => cm_reason_line($reason)];
    if ($kind === 'warn') {
        [$err, $n, $limit, $after] = cm_warn($ctx, (int)$t['id'], $reason, (int)$ctx['actor']);
        if ($err !== '') {
            cm_reply_err($ctx, $err);
            return;
        }
        cm_reply($ctx, cm_t($after === '' ? 'warn_done' : 'warn_limit_hit', $vars + ['n' => $n, 'limit' => $limit, 'after' => $after]), false);
        return;
    }
    $err = cm_punish($ctx, (int)$t['id'], $kind, $secs, $reason, (int)$ctx['actor']);
    if ($err !== '') {
        cm_reply_err($ctx, $err);
        return;
    }
    cm_reply($ctx, cm_t($kind . '_done', $vars), false);
}

function cm_cmd_unwarn(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare("SELECT id FROM cm_punishments WHERE chat_id = :c AND user_id = :u AND kind = 'warn'
        AND lifted_at = 0 AND created_at > :s ORDER BY id DESC LIMIT 1");
    $st->execute([':c' => $ctx['chat']['chat_id'], ':u' => $t['id'], ':s' => cm_warns_since($ctx)]);
    $id = $st->fetchColumn();
    if ($id === false) {
        cm_reply($ctx, cm_t('unwarn_none', ['name' => $t['tag']]));
        return;
    }
    $pdo->prepare('UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b WHERE id = :id')
        ->execute([':n' => $ctx['now'], ':b' => $ctx['actor'], ':id' => (int)$id]);
    cm_reply($ctx, cm_t('unwarn_done', ['name' => $t['who'], 'n' => cm_warns_active($ctx, (int)$t['id']),
        'limit' => (int)$ctx['chat']['settings']['warns']['limit']]), false);
}

function cm_cmd_unwarn_all(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $n = cm_warns_active($ctx, (int)$t['id']);
    if ($n === 0) {
        cm_reply($ctx, cm_t('unwarn_none', ['name' => $t['tag']]));
        return;
    }
    $ctx['pdo']->prepare("UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b
        WHERE chat_id = :c AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':b' => $ctx['actor'], ':c' => $ctx['chat']['chat_id'], ':u' => $t['id']]);
    cm_reply($ctx, cm_t('unwarn_all_done', ['name' => $t['who'], 'n' => $n]), false);
}

function cm_cmd_warns(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_target($pdo, $ctx['msg'], $args);
    $limit = (int)$ctx['chat']['settings']['warns']['limit'];
    $p = [':c' => $ctx['chat']['chat_id'], ':s' => cm_warns_since($ctx)];
    $lines = [];
    if ($t !== null) {
        $st = $pdo->prepare("SELECT reason, created_at FROM cm_punishments WHERE chat_id = :c AND user_id = :u
            AND kind = 'warn' AND lifted_at = 0 AND created_at > :s ORDER BY id");
        $st->execute($p + [':u' => $t['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = '• ' . cm_date((int)$r['created_at']) . ' — ' . ((string)$r['reason'] !== '' ? $r['reason'] : '—');
        }
        $head = cm_t('warns_user_head', ['name' => $t['tag'], 'n' => count($lines), 'limit' => $limit]);
    } else {
        $st = $pdo->prepare("SELECT user_id, COUNT(*) AS n FROM cm_punishments WHERE chat_id = :c AND kind = 'warn'
            AND lifted_at = 0 AND created_at > :s GROUP BY user_id ORDER BY n DESC, user_id LIMIT 20");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = '• ' . cm_user_who($pdo, (int)$r['user_id'], true) . ' — ' . (int)$r['n'] . '/' . $limit;
        }
        $head = cm_t('warns_head', ['limit' => $limit]);
    }
    cm_reply($ctx, $lines ? $head . "\n" . implode("\n", $lines) : cm_t('warns_none'), false);
}

function cm_cmd_unmute(array $ctx, array $cmd): void { cm_lift_cmd($ctx, $cmd, 'mute'); }
function cm_cmd_unban(array $ctx, array $cmd): void { cm_lift_cmd($ctx, $cmd, 'ban'); }

function cm_lift_cmd(array $ctx, array $cmd, string $kind): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_lift($ctx, (int)$t['id'], $kind, (int)$ctx['actor']);
    if ($err !== '') {
        cm_reply_err($ctx, $err);
        return;
    }
    cm_reply($ctx, cm_t($kind === 'mute' ? 'unmute_done' : 'unban_done', ['name' => $t['who']]), false);
}

function cm_cmd_mutes(array $ctx, array $cmd): void { cm_active_list($ctx, 'mute', 'mutes_head'); }
function cm_cmd_bans(array $ctx, array $cmd): void { cm_active_list($ctx, 'ban', 'bans_head'); }

function cm_active_list(array $ctx, string $kind, string $head): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT user_id, until_at FROM cm_punishments WHERE chat_id = :c AND kind = :k AND lifted_at = 0
        AND (until_at = 0 OR until_at > :n) ORDER BY id DESC LIMIT 40');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':k' => $kind, ':n' => $ctx['now']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        if (isset($lines[$uid]) || count($lines) >= 20) { continue; }
        $until = (int)$r['until_at'];
        $lines[$uid] = '• ' . cm_user_who($pdo, $uid, true) . ' — '
            . ($until === 0 ? cm_t('until_forever') : cm_t('until_date', ['date' => cm_date($until)]));
    }
    cm_reply($ctx, cm_t($head) . "\n" . ($lines ? implode("\n", $lines) : cm_t('list_empty')), false);
}

function cm_cmd_warn_limit(array $ctx, array $cmd): void {
    if (!preg_match('~^(\d{1,2})$~', trim($cmd['args']), $m) || (int)$m[1] < 1 || (int)$m[1] > 20) {
        cm_reply($ctx, cm_t('warn_limit_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['warns']['limit'] = (int)$m[1];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('warn_limit_done', ['n' => (int)$m[1]]), false);
}

function cm_cmd_warn_ttl(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $term = cm_take_term($args);
    if ($term === null || $term === -1 || trim($args) !== '') {
        cm_reply($ctx, cm_t('warn_ttl_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['warns']['ttl'] = $term;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('warn_ttl_done', ['term' => cm_term_text($term)]), false);
}

function cm_cmd_warn_action(array $ctx, array $cmd): void {
    $acts = cm_parse_actions($cmd['args'], true);
    if ($acts === null) {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['triggers']['warns'] = $acts;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('trigger_done', ['name' => 'варны', 'actions' => cm_actions_text($acts)]), false);
}
