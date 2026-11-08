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

// --------------------------------------------------------------------------
//  Разметка ответов: ник — ссылка на профиль, жирный, ссылка
// --------------------------------------------------------------------------
//
// Тексты собираются строками (cm_t), поэтому разметка едет внутри строки
// метками из символов личного пользования Unicode, а перед отправкой
// cm_fmt() превращает их в entities Telegram. В каждой метке — случайный
// ключ процесса: текст человека (ник, причина, название клана) метку не
// подделает, чужие символы остаются как есть.

function cm_mark_key(): string {
    static $k = null;
    if ($k === null) { $k = bin2hex(random_bytes(4)); }
    return $k;
}

// $type: m — упоминание (param — id), l — ссылка (param — адрес), b — жирный,
// q — раскрывающаяся цитата.
function cm_mark(string $type, string $param, string $text): string {
    if ($text === '') { return ''; }
    $k = cm_mark_key();
    return "\u{E000}" . $k . $type . $param . "\u{E001}" . $text . "\u{E002}" . $k . "\u{E003}";
}

function cm_bold(string $text): string {
    return cm_mark('b', '', $text);
}

function cm_link(string $url, string $text): string {
    return cm_mark('l', $url, $text);
}

// Ник со ссылкой на профиль: нажатие открывает человека в Telegram, сам он
// получает упоминание. Для событий о нём: наказание, ранг, РП, приглашение.
function cm_who(int $id, string $name): string {
    return $id > 0 ? cm_mark('m', (string)$id, $name) : $name;
}

/**
 * Ник человека из cm_users. $quiet — для списков и справок (топы, модеры,
 * профиль): ссылка t.me/ник без упоминания, чтобы «стата» не будила полчата;
 * без ника — просто имя.
 */
function cm_user_who(PDO $pdo, int $id, bool $quiet = false): string {
    $name = cm_user_name($pdo, $id);
    return $quiet ? cm_user_tag($pdo, $id, $name) : cm_who($id, $name);
}

// Тихая ссылка на профиль под именем $name: t.me/ник, если бот знает ник.
function cm_user_tag(PDO $pdo, int $id, string $name): string {
    $st = $pdo->prepare('SELECT username FROM cm_users WHERE user_id = :id');
    $st->execute([':id' => $id]);
    $u = (string)$st->fetchColumn();
    return preg_match('~^[a-z]\w{3,31}$~', $u) ? cm_link('https://t.me/' . $u, $name) : $name;
}

// Ник автора сообщения (from) со ссылкой на профиль.
function cm_who_from(array $u): string {
    return cm_who((int)($u['id'] ?? 0), cm_display_name($u));
}

// Текст без меток — для ответа на кнопку (там разметки нет).
function cm_plain(string $text): string {
    return cm_fmt($text)[0];
}

/**
 * Метки → entities. $ents — готовая разметка со смещениями по тексту С
 * метками (правила, заметки): смещения пересчитываются. Вложенность
 * поддерживается (жирное с ником внутри).
 */
function cm_fmt(string $text, array $ents = []): array {
    if (strpos($text, "\u{E000}") === false) { return [$text, array_values($ents)]; }
    $k = cm_mark_key();
    $re = '~\x{E000}' . $k . '([mlbq])([^\x{E001}]*)\x{E001}|\x{E002}' . $k . '\x{E003}~u';
    if (!preg_match_all($re, $text, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { return [$text, array_values($ents)]; }
    $out = '';
    $outU = 0;
    $rawU = 0;
    $pos = 0;
    $stack = [];
    $made = [];
    $spans = [];   // вырезанные метки: [начало в тексте с метками, длина] в UTF-16
    foreach ($mm as $m) {
        $chunk = substr($text, $pos, $m[0][1] - $pos);
        $out .= $chunk;
        $cu = cm_utf16_len($chunk);
        $outU += $cu;
        $rawU += $cu;
        $ml = cm_utf16_len($m[0][0]);
        $spans[] = [$rawU, $ml];
        $rawU += $ml;
        $pos = $m[0][1] + strlen($m[0][0]);
        if (isset($m[1]) && $m[1][1] >= 0) {
            $stack[] = [$m[1][0], $m[2][0], $outU, strlen($out)];
            continue;
        }
        $open = array_pop($stack);
        if ($open === null || $outU <= $open[2]) { continue; }
        [$type, $param, $from, $fromByte] = $open;
        $e = ['offset' => $from, 'length' => $outU - $from];
        if ($type === 'm') {
            $e = ['type' => 'text_mention'] + $e + ['user' => ['id' => (int)$param, 'is_bot' => false,
                  'first_name' => mb_substr(substr($out, $fromByte), 0, 64)]];
        } elseif ($type === 'l') {
            $e = ['type' => 'text_link'] + $e + ['url' => $param];
        } elseif ($type === 'q') {
            $e = ['type' => 'expandable_blockquote'] + $e;
        } else {
            $e = ['type' => 'bold'] + $e;
        }
        $made[] = $e;
    }
    $out .= substr($text, $pos);
    $shift = function (int $p) use ($spans): int {
        $cut = 0;
        foreach ($spans as [$s, $l]) {
            if ($s >= $p) { break; }
            $cut += min($l, $p - $s);
        }
        return $p - $cut;
    };
    $all = [];
    foreach ($ents as $e) {
        if (!is_array($e)) { continue; }
        $a = $shift((int)($e['offset'] ?? 0));
        $b = $shift((int)($e['offset'] ?? 0) + (int)($e['length'] ?? 0));
        if ($b <= $a) { continue; }
        $e['offset'] = $a;
        $e['length'] = $b - $a;
        $all[] = $e;
    }
    $all = array_merge($all, $made);
    usort($all, function ($x, $y) {
        return $x['offset'] <=> $y['offset'] ?: $y['length'] <=> $x['length'];
    });
    return [$out, $all];
}

// text и entities для sendMessage / editMessageText: метки сняты, текст
// урезан до 4096 символов, разметка — по урезанному.
function cm_text_params(string $text, array $ents = []): array {
    [$t, $e] = cm_fmt($text, $ents);
    $t = mb_substr($t, 0, 4096);
    $max = cm_utf16_len($t);
    $keep = [];
    foreach ($e as $x) {
        if ((int)$x['offset'] >= $max) { continue; }
        $x['length'] = min((int)$x['length'], $max - (int)$x['offset']);
        $keep[] = $x;
    }
    return $keep ? ['text' => $t, 'entities' => $keep] : ['text' => $t];
}

// Telegram не принял разметку (упоминание человека, которого он боту не
// показывает) — то же сообщение без упоминаний: ник останется текстом.
function cm_without_mentions(array $p): ?array {
    if (empty($p['entities'])) { return null; }
    $left = array_values(array_filter($p['entities'], function ($e) { return ($e['type'] ?? '') !== 'text_mention'; }));
    if (count($left) === count($p['entities'])) { return null; }
    if ($left) { $p['entities'] = $left; } else { unset($p['entities']); }
    return $p;
}

function cm_entity_error(?array $res): bool {
    return !cm_ok($res) && preg_match('~entit|mention|user~i', (string)($res['description'] ?? '')) === 1;
}

/**
 * Сообщение в чат. $o: reply_to — id сообщения, на которое ответить;
 * buttons — inline_keyboard; entities — разметка; temp — удалить через минуту
 * (если в чате не выключено автоудаление). Возвращает message_id или 0.
 */
function cm_say(array $ctx, int $chatId, string $text, array $o = []): int {
    if ($text === '' || !cm_budget_take($ctx['pdo'], $chatId, (int)$ctx['now'])) { return 0; }
    $p = ['chat_id' => $chatId] + cm_text_params($text, (array)($o['entities'] ?? []))
       + ['link_preview_options' => ['is_disabled' => true]];
    if (!empty($o['reply_to'])) {
        $p['reply_parameters'] = ['message_id' => (int)$o['reply_to'], 'allow_sending_without_reply' => true];
    } elseif (!empty($ctx['thread_id']) && $chatId === (int)$ctx['chat']['chat_id']) {
        $p['message_thread_id'] = (int)$ctx['thread_id'];
    }
    if (!empty($o['buttons'])) { $p['reply_markup'] = ['inline_keyboard' => $o['buttons']]; }
    $res = ($ctx['send'])('sendMessage', $p);
    $plain = cm_entity_error($res) ? cm_without_mentions($p) : null;
    if ($plain !== null) { $res = ($ctx['send'])('sendMessage', $plain); }
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
