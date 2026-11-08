<?php
// Чистка: удаление пачкой и массовые кики. Bot API не даёт список
// участников, поэтому массовые кики — только среди тех, кого бот видел
// (cm_members). Перед киком — подтверждение кнопкой; за раз до 200 человек,
// после ответа вебхуку.

const CM_PURGE_MAX = 100;
const CM_MASS_MAX  = 200;

function cm_cmd_purge(array $ctx, array $cmd): void {
    $msg = $ctx['msg'];
    $cur = (int)($msg['message_id'] ?? 0);
    $chatId = (int)$ctx['chat']['chat_id'];
    $args = trim($cmd['args']);
    $reply = cm_reply_of($msg);
    if (preg_match('~^(\d{1,3})$~', $args, $m)) {
        $n = (int)$m[1];
        if ($n < 1 || $n > CM_PURGE_MAX) {
            cm_reply($ctx, cm_t('purge_usage'));
            return;
        }
        $ids = range(max(1, $cur - $n), $cur);
    } elseif ($args !== '') {
        $t = cm_need_target($ctx, $args);
        if ($t === null) { return; }
        $st = $ctx['pdo']->prepare('SELECT message_id FROM cm_msgs WHERE chat_id = :c AND user_id = :u AND at > :t
            ORDER BY message_id DESC LIMIT 100');
        $st->execute([':c' => $chatId, ':u' => $t['id'], ':t' => (int)$ctx['now'] - CM_MSG_KEEP]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $ids[] = $cur;
    } elseif ($reply !== null) {
        $ids = range(max((int)$reply['message_id'], $cur - CM_PURGE_MAX), $cur);
    } else {
        cm_reply($ctx, cm_t('purge_usage'));
        return;
    }
    if (!cm_delete_many($ctx['send'], $chatId, $ids)) {
        cm_rights_warn($ctx, true);
        return;
    }
    cm_say($ctx, $chatId, cm_t('purge_done', ['n' => count($ids) - 1]), ['temp' => true]);
}

function cm_mass_candidates(array $ctx, string $kind, int $param, int $limit): array {
    $now = (int)$ctx['now'];
    $where = 'chat_id = :c AND left_at = 0 AND lvl = 0 AND user_id NOT IN
        (SELECT user_id FROM cm_members WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ') AND lvl >= 1)';
    $p = [':c' => (int)$ctx['chat']['chat_id']];
    if ($kind === 'i') {
        $where .= ' AND last_seen > 0 AND last_seen < :t';
        $p[':t'] = $now - $param;
    } elseif ($kind === 'n') {
        $where .= ' AND joined_at >= :t';
        $p[':t'] = $now - $param;
    }
    $skip = array_merge($ctx['chat']['admins'], [(int)$ctx['chat']['owner_id'], (int)$ctx['chat']['tg_owner_id']]);
    $st = $ctx['pdo']->prepare("SELECT user_id FROM cm_members WHERE $where ORDER BY user_id LIMIT " . (int)($limit + count($skip)));
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $u = (int)$u;
        if (in_array($u, $skip, true)) { continue; }
        $out[] = $u;
        if (count($out) >= $limit) { break; }
    }
    return $out;
}

function cm_mass_ask(array $ctx, string $kind, int $param): void {
    $n = count(cm_mass_candidates($ctx, $kind, $param, 5000));
    if ($n === 0) {
        cm_reply($ctx, cm_t('mass_none'));
        return;
    }
    $data = 'cm:k:' . $kind . ':' . $param . ':' . (int)$ctx['actor'];
    cm_reply($ctx, cm_t($kind === 'd' ? 'mass_confirm_deleted' : 'mass_confirm', ['n' => $n, 'max' => CM_MASS_MAX]), false,
        ['buttons' => [[['text' => cm_t('btn_confirm'), 'callback_data' => $data]]]]);
}

function cm_cmd_kick_deleted(array $ctx, array $cmd): void {
    cm_mass_ask($ctx, 'd', 0);
}

// «кик неактивных 30» — голое число здесь дни, а не минуты.
function cm_cmd_kick_inactive(array $ctx, array $cmd): void {
    $args = trim($cmd['args']);
    if (preg_match('~^(\d{1,3})$~', $args, $m)) {
        $secs = (int)$m[1] * 86400;
    } else {
        $t = $args === '' ? null : cm_take_term($args);
        if ($t === -1 || $t === 0 || trim($args) !== '') {
            cm_reply($ctx, cm_t('inactive_usage'));
            return;
        }
        $secs = $t ?? 2592000;
    }
    cm_mass_ask($ctx, 'i', $secs);
}

function cm_cmd_kick_new(array $ctx, array $cmd): void {
    $args = trim($cmd['args']);
    $t = $args === '' ? null : cm_take_term($args);
    if ($t === -1) {
        cm_reply($ctx, cm_t('term_bad'));
        return;
    }
    if ($t === 0 || trim($args) !== '') {
        cm_reply($ctx, cm_t('new_usage'));
        return;
    }
    cm_mass_ask($ctx, 'n', $t ?? 3600);
}

// Кнопка «Подтвердить»: жмёт тот, кто просил, и ранг у него ещё есть.
function cm_mass_callback(array $ctx, array $q, string $kind, int $param, int $actor): string {
    if ((int)$ctx['actor'] !== $actor) { return cm_t('not_yours'); }
    $need = cm_need_level($ctx['chat'], 'чистка', 3);
    if ((int)$ctx['rank'] < $need) { return cm_t('no_rank', ['need' => $need]); }
    if (!in_array($kind, ['d', 'i', 'n'], true)) { return ''; }
    $list = cm_mass_candidates($ctx, $kind, $param, CM_MASS_MAX + 1);
    $more = count($list) > CM_MASS_MAX;
    $chatId = (int)$ctx['chat']['chat_id'];
    $done = 0;
    foreach (array_slice($list, 0, CM_MASS_MAX) as $uid) {
        if ($kind === 'd') {
            $res = ($ctx['send'])('getChatMember', ['chat_id' => $chatId, 'user_id' => $uid]);
            if (!cm_ok($res)) { continue; }
            $m = is_array($res['result'] ?? null) ? $res['result'] : [];
            if (in_array((string)($m['status'] ?? ''), ['left', 'kicked'], true)) {
                cm_member_set($ctx['pdo'], $chatId, $uid, ['left_at' => (int)$ctx['now']]);
                continue;
            }
            $fn = (string)($m['user']['first_name'] ?? '');
            if ($fn !== '' && $fn !== 'Deleted Account') { continue; }
        }
        if (cm_punish($ctx, $uid, 'kick', 0, cm_t('reason_cleanup'), $actor) === '') { $done++; }
    }
    $text = cm_t('mass_done', ['n' => $done]);
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    ($ctx['send'])('editMessageText', ['chat_id' => (int)($qm['chat']['id'] ?? $chatId), 'message_id' => (int)($qm['message_id'] ?? 0),
        'text' => $text . ($more ? "\n" . cm_t('mass_more') : '')]);
    return $text;
}
