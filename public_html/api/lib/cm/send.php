<?php
// Отправка от имени чат-менеджера: бюджет сообщений, ответы, ошибки Telegram.

// Telegram пускает боту 20 сообщений в минуту в одну группу; 15 оставляют
// запас. Кончился бюджет — бот молчит, но удаляет и ограничивает дальше.
const CM_BUDGET    = 15;
const CM_TEMP_SECS = 60;

function cm_ctx(PDO $pdo, array $cfg, callable $send, array $chat, array $msg, int $now): array {
    return [
        'pdo' => $pdo, 'cfg' => $cfg, 'send' => $send, 'chat' => $chat, 'msg' => $msg, 'now' => $now,
        'actor' => (int)($msg['from']['id'] ?? 0), 'rank' => 0, 'edited' => false, 'timer' => false,
        'bot' => ltrim((string)($cfg['tg_bot_name'] ?? ''), '@'),
        'thread_id' => !empty($msg['is_topic_message']) ? (int)($msg['message_thread_id'] ?? 0) : 0,
    ];
}

function cm_ok(?array $res): bool {
    return is_array($res) && !empty($res['ok']);
}

// Отказ Telegram → ключ текста. Описания ошибок у Telegram английские и
// стабильные годами; неизвестное — общий отказ.
function cm_tg_error(?array $res): string {
    if (cm_ok($res)) { return ''; }
    $d = strtolower((string)($res['description'] ?? ''));
    if (strpos($d, 'administrator') !== false || strpos($d, 'chat owner') !== false) { return 'target_admin'; }
    if (strpos($d, 'not enough rights') !== false || strpos($d, 'admin_required') !== false
        || strpos($d, 'have no rights') !== false || strpos($d, 'need administrator rights') !== false) { return 'no_rights'; }
    if (strpos($d, 'only for supergroups') !== false) { return 'need_supergroup'; }
    if (strpos($d, 'user not found') !== false || strpos($d, 'participant_id_invalid') !== false) { return 'not_member'; }
    return 'tg_failed';
}

// Одно сообщение из бюджета минуты. В личке бюджета нет.
function cm_budget_take(PDO $pdo, int $chatId, int $now): bool {
    if ($chatId > 0) { return true; }
    $w = intdiv($now, 60);
    $st = $pdo->prepare('UPDATE cm_chats
        SET sent_count = CASE WHEN sent_window = :w THEN sent_count + 1 ELSE 1 END, sent_window = :w
        WHERE chat_id = :c AND (sent_window <> :w OR sent_count < :max)');
    $st->execute([':w' => $w, ':c' => $chatId, ':max' => CM_BUDGET]);
    return $st->rowCount() > 0;
}

/**
 * Сообщение в чат. $o: reply_to — id сообщения, на которое ответить;
 * buttons — inline_keyboard; entities — разметка; temp — удалить через минуту
 * (если в чате не выключено автоудаление). Возвращает message_id или 0.
 */
function cm_say(array $ctx, int $chatId, string $text, array $o = []): int {
    if ($text === '' || !cm_budget_take($ctx['pdo'], $chatId, (int)$ctx['now'])) { return 0; }
    $p = ['chat_id' => $chatId, 'text' => mb_substr($text, 0, 4096), 'link_preview_options' => ['is_disabled' => true]];
    if (!empty($o['reply_to'])) {
        $p['reply_parameters'] = ['message_id' => (int)$o['reply_to'], 'allow_sending_without_reply' => true];
    } elseif (!empty($ctx['thread_id']) && $chatId === (int)$ctx['chat']['chat_id']) {
        $p['message_thread_id'] = (int)$ctx['thread_id'];
    }
    if (!empty($o['entities'])) { $p['entities'] = array_values($o['entities']); }
    if (!empty($o['buttons'])) { $p['reply_markup'] = ['inline_keyboard' => $o['buttons']]; }
    $res = ($ctx['send'])('sendMessage', $p);
    $id = (int)($res['result']['message_id'] ?? 0);
    if ($id > 0 && !empty($o['temp']) && $chatId < 0) {
        $chat = $chatId === (int)$ctx['chat']['chat_id'] ? $ctx['chat'] : cm_chat_row($ctx['pdo'], $chatId);
        if ($chat !== null && !empty($chat['settings']['autodelete'])) {
            cm_queue_add($ctx['pdo'], $chatId, $id, (int)$ctx['now'] + CM_TEMP_SECS);
        }
    }
    return $id;
}

// Ответ в чат контекста на сообщение, вызвавшее команду.
function cm_reply(array $ctx, string $text, bool $temp = true, array $o = []): int {
    $mid = (int)($ctx['msg']['message_id'] ?? 0);
    if ($mid > 0) { $o['reply_to'] = $mid; }
    $o['temp'] = $temp;
    return cm_say($ctx, (int)$ctx['chat']['chat_id'], $text, $o);
}

function cm_reply_err(array $ctx, string $err): void {
    if ($err === 'no_rights') {
        cm_rights_warn($ctx, true);
        return;
    }
    cm_reply($ctx, cm_t($err));
}

// Боту не дали прав. Само по себе (на срабатывании фильтра) — раз в сутки,
// иначе каждое нарушение писало бы одно и то же; на команду — сразу.
function cm_rights_warn(array $ctx, bool $force = false): void {
    $chatId = (int)$ctx['chat']['chat_id'];
    if ($chatId > 0) { return; }
    if (!$force) {
        $st = $ctx['pdo']->prepare('UPDATE cm_chats SET rights_warned_at = :n WHERE chat_id = :c AND rights_warned_at < :d');
        $st->execute([':n' => $ctx['now'], ':c' => $chatId, ':d' => (int)$ctx['now'] - 86400]);
        if ($st->rowCount() === 0) { return; }
    }
    cm_say($ctx, $chatId, cm_t('need_rights'));
}
