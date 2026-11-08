<?php
// Репорты: «репорт причина» ответом на сообщение нарушителя. Карточка с
// кнопками уходит в ту же группу или в привязанный чат админов. Нажимать
// кнопки может ранг из раздела «репорты» (по умолчанию 2) — в группе жалобы,
// а не в чате, где висит карточка. Здесь же «созвать админов» (раздел «созыв»).

const CM_REPORT_GAP = 300;

function cm_msg_link(array $chat, int $msgId): string {
    if ((string)$chat['username'] !== '') { return 'https://t.me/' . $chat['username'] . '/' . $msgId; }
    $id = (string)$chat['chat_id'];
    return strpos($id, '-100') === 0 ? 'https://t.me/c/' . substr($id, 4) . '/' . $msgId : '';
}

function cm_report_buttons(int $id): array {
    $d = 'cm:r:' . $id . ':';
    return [
        [['text' => cm_t('report_btn_del'), 'callback_data' => $d . 'd'], ['text' => cm_t('report_btn_mute'), 'callback_data' => $d . 'm']],
        [['text' => cm_t('report_btn_ban'), 'callback_data' => $d . 'b'], ['text' => cm_t('report_btn_no'), 'callback_data' => $d . 'x']],
    ];
}

function cm_report_card(array $r, PDO $pdo, array $src): string {
    return cm_t('report_card', ['chat' => $src['title'], 'from' => cm_user_who($pdo, (int)$r['reporter_id']),
        'to' => cm_user_who($pdo, (int)$r['target_id']), 'reason' => (string)$r['reason'] !== '' ? $r['reason'] : '—',
        'link' => cm_msg_link($src, (int)$r['message_id'])]);
}

function cm_cmd_report(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $chat = $ctx['chat'];
    $now = (int)$ctx['now'];
    $reply = cm_reply_of($ctx['msg']);
    $from = is_array($reply['from'] ?? null) ? $reply['from'] : null;
    if ($from === null || !empty($from['is_bot'])) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('report_need_reply')); }
        return;
    }
    $target = (int)($from['id'] ?? 0);
    if ($target === (int)$ctx['actor']) { return; }
    if (cm_exempt($ctx, $target)) {
        cm_reply($ctx, cm_t('report_staff'));
        return;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_reports WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ')
        AND reporter_id = :r AND created_at > :t');
    $st->execute([':r' => $ctx['actor'], ':t' => $now - CM_REPORT_GAP]);
    if ((int)$st->fetchColumn() > 0) {
        cm_reply($ctx, cm_t('report_wait'));
        return;
    }
    cm_user_touch($pdo, $from, $now);
    $row = ['reporter_id' => (int)$ctx['actor'], 'target_id' => $target, 'reason' => cm_reason($cmd['args'], $cmd['body']),
            'message_id' => (int)($reply['message_id'] ?? 0)];
    $pdo->prepare("INSERT INTO cm_reports (chat_id, message_id, reporter_id, target_id, reason, status, handled_by, created_at, handled_at)
                   VALUES (:c, :m, :r, :t, :why, 'open', 0, :n, 0)")
        ->execute([':c' => $chat['chat_id'], ':m' => $row['message_id'], ':r' => $row['reporter_id'], ':t' => $target,
                   ':why' => $row['reason'], ':n' => $now]);
    $id = (int)$pdo->lastInsertId();
    $dest = (int)$chat['report_chat_id'] !== 0 ? (int)$chat['report_chat_id'] : (int)$chat['chat_id'];
    cm_say($ctx, $dest, cm_report_card($row, $pdo, $chat), ['buttons' => cm_report_buttons($id)]);
    cm_reply($ctx, cm_t('report_sent'));
}

function cm_report_callback(array $ctx, array $q, int $id, string $act): string {
    if (!in_array($act, ['d', 'm', 'b', 'x'], true)) { return ''; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_reports WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || $r['status'] !== 'open') { return cm_t('report_closed'); }
    $src = cm_chat_row($pdo, (int)$r['chat_id']);
    if ($src === null) { return cm_t('report_closed'); }
    $sctx = $ctx;
    $sctx['chat'] = $src;
    cm_admins_refresh($sctx);
    $sctx['rank'] = cm_rank($sctx, (int)$ctx['actor']);
    $need = cm_need_level($src, 'репорты', 2);
    if ($sctx['rank'] < $need) { return cm_t('no_rank', ['need' => $need]); }
    $target = ['id' => (int)$r['target_id'], 'is_bot' => false];
    $err = '';
    if ($act === 'd') {
        $err = cm_tg_error(($ctx['send'])('deleteMessage', ['chat_id' => $src['chat_id'], 'message_id' => (int)$r['message_id']]));
    } elseif ($act === 'm' || $act === 'b') {
        $err = cm_can_touch($sctx, $target);
        if ($err === '') {
            $err = cm_punish($sctx, $target['id'], $act === 'm' ? 'mute' : 'ban', $act === 'm' ? 3600 : 0,
                cm_t('reason_report'), (int)$ctx['actor']);
        }
    }
    if ($err !== '') { return cm_t($err); }
    $pdo->prepare('UPDATE cm_reports SET status = :s, handled_by = :b, handled_at = :n WHERE id = :id')
        ->execute([':s' => $act === 'x' ? 'rejected' : 'done', ':b' => $ctx['actor'], ':n' => $ctx['now'], ':id' => $id]);
    $what = ['d' => 'удалено', 'm' => 'мут 1 ч', 'b' => 'бан', 'x' => 'отклонено'][$act];
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    // Разметка карточки (ники-ссылки) — из самого сообщения: текст карточки
    // идёт первым, её смещения не сдвигаются.
    ($ctx['send'])('editMessageText', [
        'chat_id' => (int)($qm['chat']['id'] ?? 0), 'message_id' => (int)($qm['message_id'] ?? 0),
    ] + cm_text_params((string)($qm['text'] ?? '') . "\n\n" . cm_t('report_result',
            ['who' => cm_user_who($pdo, (int)$ctx['actor'], true), 'what' => $what]), (array)($qm['entities'] ?? []))
      + ['link_preview_options' => ['is_disabled' => true]]);
    return cm_t('report_handled');
}

function cm_cmd_report_list(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->query("SELECT * FROM cm_reports WHERE chat_id IN (" . cm_in(cm_scope($ctx)) . ") AND status = 'open'
        ORDER BY id DESC LIMIT 10");
    $lines = [];
    $chats = [(int)$ctx['chat']['chat_id'] => $ctx['chat']];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['chat_id'];
        if (!array_key_exists($cid, $chats)) { $chats[$cid] = cm_chat_row($pdo, $cid); }
        $src = $chats[$cid] ?? $ctx['chat'];
        $lines[] = '#' . (int)$r['id'] . ' · ' . cm_user_who($pdo, (int)$r['target_id'], true) . ' — '
            . ((string)$r['reason'] !== '' ? $r['reason'] : '—') . ' ' . cm_msg_link($src, (int)$r['message_id']);
    }
    cm_reply($ctx, $lines ? cm_t('reports_head') . "\n" . implode("\n", $lines) : cm_t('reports_empty'), false);
}

// Привязка чата админов: код в группе, «репорты сюда <код>» — в чате админов,
// тем же человеком и за 10 минут.
function cm_cmd_report_code(array $ctx, array $cmd): void {
    $code = (string)random_int(100000, 999999);
    $s = $ctx['chat']['settings'];
    $s['report_code'] = ['code' => $code, 'by' => (int)$ctx['actor'], 'until' => (int)$ctx['now'] + 600];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('report_code', ['code' => $code]), false);
}

function cm_cmd_report_bind(array $ctx, array $cmd): void {
    if (!preg_match('~^(\d{6})$~', trim($cmd['args']), $m)) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('report_bad_code')); }
        return;
    }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_chats WHERE settings LIKE :p');
    $st->execute([':p' => '%"code":"' . $m[1] . '"%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $src = cm_chat_decode($row);
        $c = $src['settings']['report_code'];
        if (!is_array($c) || (string)($c['code'] ?? '') !== $m[1] || (int)($c['by'] ?? 0) !== (int)$ctx['actor']
            || (int)($c['until'] ?? 0) < (int)$ctx['now'] || $src['chat_id'] === (int)$ctx['chat']['chat_id']) {
            continue;
        }
        $s = $src['settings'];
        $s['report_code'] = null;
        cm_settings_save($pdo, $src, $s, (int)$ctx['now']);
        $pdo->prepare('UPDATE cm_chats SET report_chat_id = :d WHERE chat_id IN (' . cm_in(cm_chat_scope($pdo, $src)) . ')')
            ->execute([':d' => (int)$ctx['chat']['chat_id']]);
        cm_reply($ctx, cm_t('report_bound', ['chat' => $src['title']]), false);
        return;
    }
    cm_reply($ctx, cm_t('report_bad_code'));
}

function cm_cmd_report_home(array $ctx, array $cmd): void {
    $ctx['pdo']->exec('UPDATE cm_chats SET report_chat_id = 0 WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ')');
    cm_reply($ctx, cm_t('report_home'), false);
}

// --------------------------------------------------------------------------
//  «созвать админов» — позвать на помощь всех, кто может навести порядок
// --------------------------------------------------------------------------

const CM_CALL_GAP = 600;   // участникам — раз в 10 минут на чат
const CM_CALL_MAX = 30;

// Кого звать: создатель, админы Telegram (без ботов — их нет в кеше) и
// модераторы бота (ранг ≥ 1, общий по сети — ranks.php), кто ещё в этом
// чате. Без автора, без повторов.
function cm_call_ids(array $ctx): array {
    $chat = $ctx['chat'];
    $chatId = (int)$chat['chat_id'];
    $ids = array_merge([(int)$chat['owner_id'], (int)$chat['tg_owner_id']], $chat['admins']);
    $st = $ctx['pdo']->prepare('SELECT m.user_id FROM cm_members m
        JOIN (SELECT user_id, MAX(' . cm_rank_sql($chatId) . ') AS r FROM cm_members
              WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ') AND lvl > 0 GROUP BY user_id) n ON n.user_id = m.user_id
        WHERE m.chat_id = :c AND m.left_at = 0 AND n.r >= 1
        ORDER BY n.r DESC, m.user_id');
    $st->execute([':c' => $chatId]);
    $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $out = [];
    foreach ($ids as $id) {
        if ($id > 0 && $id !== (int)$ctx['actor'] && $id !== CM_TG_SERVICE_ID && !in_array($id, $out, true)) { $out[] = $id; }
    }
    return array_slice($out, 0, CM_CALL_MAX);
}

/**
 * Ники всех, кого зовём, с упоминанием — им придёт уведомление. Причина —
 * остаток строки и вторая строка. Участникам — раз в 10 минут на чат (иначе
 * это кнопка «разбудить всех админов»), модераторам и админам — без паузы.
 * Ответ постоянный: админ должен его найти.
 */
function cm_cmd_call_admins(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $now = (int)$ctx['now'];
    $ids = cm_call_ids($ctx);
    if (!$ids) {
        cm_reply($ctx, cm_t('call_empty'));
        return;
    }
    $s = $ctx['chat']['settings'];
    if (!cm_exempt($ctx, (int)$ctx['actor'])) {
        $left = (int)$s['call_at'] + CM_CALL_GAP - $now;
        if ($left > 0) {
            cm_reply($ctx, cm_t('call_wait', ['min' => max(1, intdiv($left + 59, 60))]));
            return;
        }
    }
    $s['call_at'] = $now;
    cm_settings_save($pdo, $ctx['chat'], $s, $now);
    $who = [];
    foreach ($ids as $uid) { $who[] = cm_user_who($pdo, $uid); }
    cm_reply($ctx, cm_t('call_done', ['from' => cm_actor_name($ctx)]) . cm_reason_line(cm_reason($cmd['args'], $cmd['body']))
        . "\n" . implode(', ', $who), false);
}
