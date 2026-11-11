<?php
// Наказания: варн, мут, кик, бан — в Telegram и в cm_punishments. Сроки
// мута и бана снимает сам Telegram (until_date), cron для них не нужен.
// В сети Макнеми (решение владельца 08.11.2026) мут и бан ставятся и
// снимаются во всех её чатах (строка cm_punishments на каждый чат), варны
// считаются по всей сети. Кик — только из чата, где он дан: это не
// наказание со сроком, человек может вернуться.

function cm_mute_perms(bool $can): array {
    return array_fill_keys(['can_send_messages', 'can_send_audios', 'can_send_documents', 'can_send_photos',
        'can_send_videos', 'can_send_video_notes', 'can_send_voice_notes', 'can_send_polls',
        'can_send_other_messages', 'can_add_web_page_previews'], $can);
}

/**
 * Наказать и записать. Кик — бан и сразу разбан: человек может вернуться.
 * Варн — только запись; со сроком ($secs) он сам перестаёт считаться
 * (until_at, CM_WARN_LIVE). '' — вышло, иначе ключ ошибки (cm_tg_error).
 */
function cm_punish_tg(callable $send, int $chatId, int $userId, string $kind, int $until): ?array {
    if ($kind === 'mute') {
        return $send('restrictChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'permissions' => cm_mute_perms(false), 'use_independent_chat_permissions' => false, 'until_date' => $until]);
    }
    if ($kind === 'ban' || $kind === 'kick') {
        $res = $send('banChatMember', ['chat_id' => $chatId, 'user_id' => $userId,
            'until_date' => $kind === 'ban' ? $until : 0, 'revoke_messages' => false]);
        if ($kind === 'kick' && cm_ok($res)) {
            $send('unbanChatMember', ['chat_id' => $chatId, 'user_id' => $userId, 'only_if_banned' => true]);
        }
        return $res;
    }
    return ['ok' => true];
}

// Свой чат — первым: его ошибка и есть ответ команды. Остальные чаты сети —
// молча (там человека может не быть, у бота может не хватать прав).
function cm_scope_own_first(array $ctx): array {
    $chatId = (int)$ctx['chat']['chat_id'];
    return array_merge([$chatId], array_values(array_diff(cm_scope($ctx), [$chatId])));
}

function cm_punish(array $ctx, int $userId, string $kind, int $secs, string $reason, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $until = $secs > 0 ? $now + $secs : 0;
    $chats = ($kind === 'mute' || $kind === 'ban') ? cm_scope_own_first($ctx) : [$chatId];
    foreach ($chats as $cid) {
        $err = cm_tg_error(cm_punish_tg($ctx['send'], $cid, $userId, $kind, $until));
        if ($err !== '') {
            if ($cid === $chatId) { return $err; }
            continue;
        }
        $ctx['pdo']->prepare('INSERT INTO cm_punishments (chat_id, user_id, kind, reason, by_id, created_at, until_at, lifted_at, lifted_by)
                              VALUES (:c, :u, :k, :r, :b, :n, :t, 0, 0)')
            ->execute([':c' => $cid, ':u' => $userId, ':k' => $kind, ':r' => mb_substr($reason, 0, 500), ':b' => $byId,
                       ':n' => $now, ':t' => in_array($kind, ['mute', 'ban', 'warn'], true) ? $until : 0]);
        if ($kind === 'ban' || $kind === 'kick') { cm_member_set($ctx['pdo'], $cid, $userId, ['left_at' => $now]); }
    }
    return '';
}

// Снять мут или бан во всех чатах сети. Записи снимаются везде, даже где
// Telegram ответил ошибкой (человека там нет): иначе они висели бы в списках.
function cm_lift(array $ctx, int $userId, string $kind, int $byId): string {
    $chatId = (int)$ctx['chat']['chat_id'];
    $chats = cm_scope_own_first($ctx);
    foreach ($chats as $cid) {
        if ($kind === 'mute') {
            $res = ($ctx['send'])('restrictChatMember', ['chat_id' => $cid, 'user_id' => $userId,
                'permissions' => cm_mute_perms(true), 'use_independent_chat_permissions' => false]);
        } else {
            $res = ($ctx['send'])('unbanChatMember', ['chat_id' => $cid, 'user_id' => $userId, 'only_if_banned' => true]);
        }
        $err = cm_tg_error($res);
        if ($err !== '' && $cid === $chatId) { return $err; }
    }
    $ctx['pdo']->prepare('UPDATE cm_punishments SET lifted_at = :n, lifted_by = :b
                          WHERE chat_id IN (' . cm_in($chats) . ') AND user_id = :u AND kind = :k AND lifted_at = 0')
        ->execute([':n' => $ctx['now'], ':b' => $byId, ':u' => $userId, ':k' => $kind]);
    return '';
}

// --------------------------------------------------------------------------
//  Варны
// --------------------------------------------------------------------------

// Действующий варн: не снят и не истёк. Варн со сроком из команды
// («варн 3 дня») истекает по своему until_at, и только по нему — так решил
// модератор. Без срока (until_at = 0) варн живёт «варны срок» чата (по
// умолчанию месяц) от выдачи. Снимать по cron не нужно: истёкший просто
// перестаёт считаться. Параметры — cm_warn_params().
const CM_WARN_LIVE = "kind = 'warn' AND lifted_at = 0 AND ((until_at = 0 AND created_at > :s) OR until_at > :n)";

function cm_warns_since(array $ctx): int {
    $ttl = (int)$ctx['chat']['settings']['warns']['ttl'];
    return $ttl > 0 ? (int)$ctx['now'] - $ttl : 0;
}

function cm_warn_params(array $ctx): array {
    return [':s' => cm_warns_since($ctx), ':n' => (int)$ctx['now']];
}

// «на 3 дн.» — сколько проживёт варн: свой срок или срок чата.
function cm_warn_term_text(array $ctx, int $secs): string {
    $ttl = (int)$ctx['chat']['settings']['warns']['ttl'];
    $t = $secs > 0 ? $secs : $ttl;
    return $t > 0 ? 'на ' . cm_term_text($t) : cm_t('until_forever');
}

function cm_warns_active(array $ctx, int $userId): int {
    $st = $ctx['pdo']->prepare('SELECT COUNT(*) FROM cm_punishments WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ')
        AND user_id = :u AND ' . CM_WARN_LIVE);
    $st->execute([':u' => $userId] + cm_warn_params($ctx));
    return (int)$st->fetchColumn();
}

// Варн; $secs > 0 — со своим сроком. Набран лимит — наказание из триггера
// «варны», активные варны снимаются.
function cm_warn(array $ctx, int $userId, string $reason, int $byId, int $secs = 0): array {
    $err = cm_punish($ctx, $userId, 'warn', $secs, $reason, $byId);
    if ($err !== '') { return [$err, 0, 0, '']; }
    $limit = max(1, (int)$ctx['chat']['settings']['warns']['limit']);
    $n = cm_warns_active($ctx, $userId);
    if ($n < $limit) { return ['', $n, $limit, '']; }
    $ctx['pdo']->prepare("UPDATE cm_punishments SET lifted_at = :n, lifted_by = 0
        WHERE chat_id IN (" . cm_in(cm_scope($ctx)) . ") AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':u' => $userId]);
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
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t === null) {
        if ($kind === 'ban' && $why === 'unknown') {
            cm_ban_later($ctx, $cmd);
            return;
        }
        cm_reply($ctx, cm_t($why === 'unknown' ? 'target_unknown' : 'need_target'));
        return;
    }
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
    // Срок варна — только с единицей: «варн 2 раза просили» — причина, а не
    // две минуты. «навсегда» у варна не бывает: без срока — срок чата.
    if ($kind === 'warn') {
        $term = cm_take_term($args, true);
        if ($term === -1 || $term === 0) {
            cm_reply($ctx, cm_t('warn_term_bad', ['term' => cm_warn_term_text($ctx, 0)]));
            return;
        }
        $secs = (int)$term;
    }
    $reason = cm_reason($args, $cmd['body']);
    $vars = ['name' => $t['who'], 'term' => cm_term_text($secs), 'reason' => cm_reason_line($reason),
             'net' => ($kind === 'mute' || $kind === 'ban') ? cm_net_word($ctx) : ''];
    if ($kind === 'warn') {
        $vars['wterm'] = cm_warn_term_text($ctx, $secs);
        [$err, $n, $limit, $after] = cm_warn($ctx, (int)$t['id'], $reason, (int)$ctx['actor'], $secs);
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

// --------------------------------------------------------------------------
//  Бан по нику
// --------------------------------------------------------------------------
// Bot API не ищет людей по нику, а banChatMember нужен id. Ник, которого бот
// ещё не видел (не писал в чатах, не жал кнопки бота), ждёт в настройках
// сети (ban_nicks): зайдёт или напишет — бан во всех чатах сети (решение
// владельца 11.11.2026: «бан, даже если человек не в чате»). Срок — от
// команды: «бан 1 день» через неделю уже не встанет. Сменит ник — не встанет
// вовсе, поэтому ответом или по id надёжнее.

const CM_BAN_NICKS_MAX = 100;

// Ждущие записи без истёкших и битых.
function cm_ban_nicks(array $chat, int $now): array {
    $out = [];
    foreach ((array)($chat['settings']['ban_nicks'] ?? []) as $b) {
        if (!is_array($b) || !preg_match('~^[a-z]\w{3,31}$~', (string)($b['nick'] ?? ''))) { continue; }
        $until = (int)($b['until'] ?? 0);
        if ($until > 0 && $until - $now < CM_MIN_TERM) { continue; }
        $out[] = ['nick' => (string)$b['nick'], 'until' => $until, 'reason' => (string)($b['reason'] ?? ''),
                  'by' => (int)($b['by'] ?? 0)];
    }
    return $out;
}

function cm_ban_nicks_save(array &$ctx, array $list): void {
    $s = $ctx['chat']['settings'];
    $s['ban_nicks'] = array_slice(array_values($list), -CM_BAN_NICKS_MAX);
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
}

function cm_until_text(int $until): string {
    return $until > 0 ? cm_t('until_date', ['date' => cm_date($until)]) : cm_t('until_forever');
}

// «бан 1 месяц @ник причина» на незнакомый ник — в список ждущих.
function cm_ban_later(array $ctx, array $cmd): void {
    $tok = cm_target_token($cmd['args']);
    $nick = strtolower((string)($tok['nick'] ?? ''));
    if ($nick === '') {
        cm_reply($ctx, cm_t('need_target'));
        return;
    }
    $args = $tok['args'];
    $term = cm_take_term($args);
    if ($term === -1) {
        cm_reply($ctx, cm_t('term_bad'));
        return;
    }
    $secs = (int)$term;
    $reason = cm_reason($args, $cmd['body']);
    $now = (int)$ctx['now'];
    $list = array_filter(cm_ban_nicks($ctx['chat'], $now), function ($b) use ($nick) { return $b['nick'] !== $nick; });
    $list[] = ['nick' => $nick, 'until' => $secs > 0 ? $now + $secs : 0, 'reason' => $reason, 'by' => (int)$ctx['actor']];
    cm_ban_nicks_save($ctx, $list);
    cm_reply($ctx, cm_t('ban_later', ['nick' => '@' . $nick, 'term' => cm_term_text($secs), 'net' => cm_net_word($ctx),
        'reason' => cm_reason_line($reason)]), false);
}

/**
 * Человек $u (автор сообщения или новичок) с ником из списка ждущих — бан во
 * всех чатах сети, запись уходит. Модератора и админа Telegram бот не банит —
 * запись тоже уходит. Отказ Telegram (нет прав) запись оставляет: встанет,
 * когда права дадут. true — забанен.
 */
function cm_ban_nick_hit(array &$ctx, array $u): bool {
    $nick = strtolower((string)($u['username'] ?? ''));
    $id = (int)($u['id'] ?? 0);
    if ($nick === '' || $id <= 0 || empty($ctx['chat']['settings']['ban_nicks'])) { return false; }
    $now = (int)$ctx['now'];
    $hit = null;
    $rest = [];
    foreach (cm_ban_nicks($ctx['chat'], $now) as $b) {
        if ($hit === null && $b['nick'] === $nick) {
            $hit = $b;
        } else {
            $rest[] = $b;
        }
    }
    if ($hit === null) { return false; }
    if (cm_exempt($ctx, $id)) {
        cm_ban_nicks_save($ctx, $rest);
        return false;
    }
    $err = cm_punish($ctx, $id, 'ban', $hit['until'] > 0 ? $hit['until'] - $now : 0, $hit['reason'], $hit['by']);
    if ($err !== '') {
        if ($err === 'no_rights') { cm_rights_warn($ctx); }
        return false;
    }
    cm_ban_nicks_save($ctx, $rest);
    cm_say($ctx, (int)$ctx['chat']['chat_id'], cm_t('ban_later_done', ['name' => cm_who_from($u), 'nick' => '@' . $nick,
        'until' => cm_until_text($hit['until']), 'net' => cm_net_word($ctx), 'reason' => cm_reason_line($hit['reason'])]));
    return true;
}

// Убрать ник из ждущих («разбан @ник»). '' — его там не было, иначе «@ник».
function cm_ban_nick_drop(array &$ctx, string $args): string {
    $tok = cm_target_token($args);
    $nick = strtolower((string)($tok['nick'] ?? ''));
    if ($nick === '') { return ''; }
    $list = cm_ban_nicks($ctx['chat'], (int)$ctx['now']);
    $rest = array_filter($list, function ($b) use ($nick) { return $b['nick'] !== $nick; });
    if (count($rest) === count($list)) { return ''; }
    cm_ban_nicks_save($ctx, $rest);
    return '@' . $nick;
}

function cm_cmd_unwarn(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT id FROM cm_punishments WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ') AND user_id = :u AND '
        . CM_WARN_LIVE . ' ORDER BY id DESC LIMIT 1');
    $st->execute([':u' => $t['id']] + cm_warn_params($ctx));
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
        WHERE chat_id IN (" . cm_in(cm_scope($ctx)) . ") AND user_id = :u AND kind = 'warn' AND lifted_at = 0")
        ->execute([':n' => $ctx['now'], ':b' => $ctx['actor'], ':u' => $t['id']]);
    cm_reply($ctx, cm_t('unwarn_all_done', ['name' => $t['who'], 'n' => $n]), false);
}

function cm_cmd_warns(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_target($pdo, $ctx['msg'], $args);
    $limit = (int)$ctx['chat']['settings']['warns']['limit'];
    $ttl = (int)$ctx['chat']['settings']['warns']['ttl'];
    $in = cm_in(cm_scope($ctx));
    $p = cm_warn_params($ctx);
    $lines = [];
    if ($t !== null) {
        $st = $pdo->prepare("SELECT reason, created_at, until_at FROM cm_punishments WHERE chat_id IN ($in) AND user_id = :u AND "
            . CM_WARN_LIVE . ' ORDER BY id');
        $st->execute($p + [':u' => $t['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $end = (int)$r['until_at'] > 0 ? (int)$r['until_at'] : ($ttl > 0 ? (int)$r['created_at'] + $ttl : 0);
            $lines[] = cm_t('warns_user_line', ['date' => cm_date((int)$r['created_at']),
                'reason' => (string)$r['reason'] !== '' ? $r['reason'] : '—',
                'until' => $end > 0 ? cm_t('until_date', ['date' => cm_date($end)]) : cm_t('until_forever')]);
        }
        $head = cm_t('warns_user_head', ['name' => $t['tag'], 'n' => count($lines), 'limit' => $limit]);
    } else {
        $st = $pdo->prepare("SELECT user_id, COUNT(*) AS n FROM cm_punishments WHERE chat_id IN ($in) AND " . CM_WARN_LIVE
            . ' GROUP BY user_id ORDER BY n DESC, user_id LIMIT 20');
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
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    $later = $kind === 'ban' ? cm_ban_nick_drop($ctx, $cmd['args']) : '';
    if ($t === null) {
        if ($later !== '') {
            cm_reply($ctx, cm_t('unban_later_done', ['nick' => $later]), false);
            return;
        }
        cm_reply($ctx, cm_t($why === 'unknown' ? 'target_unknown' : 'need_target'));
        return;
    }
    $err = cm_lift($ctx, (int)$t['id'], $kind, (int)$ctx['actor']);
    if ($err !== '') {
        cm_reply_err($ctx, $err);
        return;
    }
    cm_reply($ctx, cm_t($kind === 'mute' ? 'unmute_done' : 'unban_done', ['name' => $t['who'], 'net' => cm_net_word($ctx)]), false);
}

function cm_cmd_mutes(array $ctx, array $cmd): void { cm_active_list($ctx, 'mute', 'mutes_head'); }
function cm_cmd_bans(array $ctx, array $cmd): void { cm_active_list($ctx, 'ban', 'bans_head'); }

function cm_active_list(array $ctx, string $kind, string $head): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT user_id, until_at FROM cm_punishments WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ')
        AND kind = :k AND lifted_at = 0 AND (until_at = 0 OR until_at > :n) ORDER BY id DESC LIMIT 120');
    $st->execute([':k' => $kind, ':n' => $ctx['now']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $uid = (int)$r['user_id'];
        if (isset($lines[$uid]) || count($lines) >= 20) { continue; }
        $until = (int)$r['until_at'];
        $lines[$uid] = '• ' . cm_user_who($pdo, $uid, true) . ' — ' . cm_until_text($until);
    }
    if ($kind === 'ban') {
        foreach (cm_ban_nicks($ctx['chat'], (int)$ctx['now']) as $b) {
            if (count($lines) >= 20) { break; }
            $lines[] = cm_t('ban_later_line', ['nick' => '@' . $b['nick'], 'until' => cm_until_text($b['until'])]);
        }
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
