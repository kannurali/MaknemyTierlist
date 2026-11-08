<?php
// Разбор апдейта и запуск команд. Вызывается из api/tg_webhook.php уже после
// ответа Telegram: 200 ушёл сразу, вызовы Telegram идут следом.

// В личке бот понимает только команды базы спамеров (для сотрудников сайта),
// остальное в личке — уведомления, как раньше (tg_handle_update).
const CM_PRIVATE_HANDLERS = ['spam_add', 'spam_del', 'spam_check'];

function cm_wants_update(array $update, string $botName): bool {
    foreach (['message', 'edited_message'] as $k) {
        $m = $update[$k] ?? null;
        if (!is_array($m)) { continue; }
        $type = (string)($m['chat']['type'] ?? '');
        if ($type === 'group' || $type === 'supergroup') { return true; }
        if ($k === 'message' && $type === 'private') {
            $cmd = cm_parse((string)($m['text'] ?? ''), $botName);
            return $cmd !== null && in_array($cmd['handler'], CM_PRIVATE_HANDLERS, true);
        }
        return false;
    }
    $q = $update['callback_query'] ?? null;
    return is_array($q) && strncmp((string)($q['data'] ?? ''), 'cm:', 3) === 0;
}

function cm_handle_update(PDO $pdo, array $cfg, callable $send, array $update, int $now): void {
    if (!cm_enabled($cfg) || !cm_ready($pdo)) { return; }
    if (is_array($update['callback_query'] ?? null)) {
        cm_on_callback($pdo, $cfg, $send, $update['callback_query'], $now);
        return;
    }
    $edited = !is_array($update['message'] ?? null);
    $msg = $edited ? ($update['edited_message'] ?? null) : $update['message'];
    if (!is_array($msg) || !is_array($msg['chat'] ?? null)) { return; }
    if (($msg['chat']['type'] ?? '') === 'private') {
        if (!$edited) { cm_on_private($pdo, $cfg, $send, $msg, $now); }
        return;
    }
    cm_on_message($pdo, $cfg, $send, $msg, $edited, $now);
}

/**
 * Сообщение группы:
 *  -. чат не Макнеми (cm_ours, db.php) — молчим целиком;
 *  0. от имени этой группы или автопересылка своего канала — не трогаем;
 *     от имени чужого канала — только удаляем (канал не замутить);
 *  1. запомнить автора;
 *  2. спамер из базы — бан;
 *  3. +1 сообщение в статистику (этап 2, stats.php);
 *  4. ответ «+» / «спасибо» — голос репутации (этап 2, rep.php);
 *  5. команда — выполнить;
 *  6. фильтры по старшинству;
 *  7. «#имя» — заметка.
 * Правку проверяют только фильтры: команду и голос из правки не выполняем,
 * в статистику правка не идёт.
 */
function cm_on_message(PDO $pdo, array $cfg, callable $send, array $msg, bool $edited, int $now): void {
    $chatId = (int)($msg['chat']['id'] ?? 0);
    if (isset($msg['migrate_to_chat_id'])) {
        cm_migrate($pdo, $chatId, (int)$msg['migrate_to_chat_id']);
        return;
    }
    if (isset($msg['migrate_from_chat_id'])) {
        cm_migrate($pdo, (int)$msg['migrate_from_chat_id'], $chatId);
        return;
    }
    $chat = cm_chat_ensure($pdo, $msg['chat'], $now);
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
    $ctx['edited'] = $edited;
    cm_admins_refresh($ctx);
    if (!cm_ours($ctx)) { return; }
    $chat = $ctx['chat'];
    if (!empty($msg['new_chat_members'])) {
        cm_on_join($ctx, (array)$msg['new_chat_members']);
        return;
    }
    if (is_array($msg['left_chat_member'] ?? null)) {
        cm_on_left($ctx, $msg['left_chat_member']);
        return;
    }
    $sender = $msg['sender_chat'] ?? null;
    if (is_array($sender)) {
        if ((int)($sender['id'] ?? 0) === $chatId || !empty($msg['is_automatic_forward'])) { return; }
        if (cm_filter_hit($ctx, $msg) !== '') {
            $send('deleteMessage', ['chat_id' => $chatId, 'message_id' => (int)($msg['message_id'] ?? 0)]);
        }
        return;
    }
    $from = $msg['from'] ?? null;
    if (!is_array($from) || !empty($from['is_bot'])) { return; }
    $uid = (int)($from['id'] ?? 0);
    $ctx['rank'] = cm_rank($ctx, $uid);
    if (!$edited) {
        cm_user_touch($pdo, $from, $now);
        cm_member_touch($pdo, $chatId, $uid, $now);
        cm_msg_log($pdo, $chatId, (int)($msg['message_id'] ?? 0), $uid, cm_text_of($msg), $now);
    }
    if (!empty($chat['settings']['filters']['spam']) && !cm_exempt($ctx, $uid) && cm_spam_has($pdo, $uid)) {
        cm_apply_actions($ctx, $uid, (int)($msg['message_id'] ?? 0), cm_trigger_actions($chat, 'spam'), cm_t('reason_spam'), 0);
        return;
    }
    if (!$edited) { cm_stats_hit($pdo, $chatId, $uid, $now); }
    if (!$edited && isset($msg['text'])) {
        if (cm_rep_try($ctx)) { return; }
        $cmd = cm_parse((string)$msg['text'], $ctx['bot']);
        if ($cmd !== null && cm_run_command($ctx, $cmd)) { return; }
    }
    if (!cm_exempt($ctx, $uid)) {
        $hit = cm_filter_hit($ctx, $msg);
        if ($hit !== '') {
            cm_filter_act($ctx, $hit);
            return;
        }
    }
    if (!$edited) { cm_note_try($ctx); }
}

/**
 * Выполнить команду с проверкой доступа. false — сообщение идёт дальше в
 * фильтры: это не команда (без префикса и не подходит под правила) или на
 * неё не хватило ранга (иначе «!бан @ник ссылка» проносил бы ссылку мимо
 * фильтра).
 *  - команда уровня 0 без префикса — только если строка ровно команда;
 *  - команда с целью без префикса — только если цель нашлась;
 *  - без ранга: с префиксом, с найденной целью или ровно командой —
 *    ответ «нужен ранг», иначе молча, как обычная речь. Так «бан» ответом
 *    без точки не пропадает в тишине;
 *  - выключенная «дк … 6» — ответ только с префиксом: раздел выключили
 *    нарочно, и «монетка» без точки не должна каждый раз это повторять;
 *  - команда из CM_BARE_ARGS без префикса — только если остаток строки без
 *    цели подходит под её шаблон (cm_bare_ok, parse.php);
 *  - команда из CM_PREFIX_ONLY без префикса — не команда («!кто»).
 */
function cm_run_command(array $ctx, array $cmd): bool {
    if (!$cmd['prefixed'] && in_array($cmd['handler'], CM_PREFIX_ONLY, true)) { return false; }
    if (!$cmd['prefixed'] && !cm_bare_ok($ctx, $cmd)) { return false; }
    if (!$cmd['prefixed'] && $cmd['exact'] && ($cmd['args'] !== '' || trim($cmd['body']) !== '')) { return false; }
    if (!$cmd['prefixed'] && $cmd['target']) {
        $a = $cmd['args'];
        if (cm_target($ctx['pdo'], $ctx['msg'], $a) === null) { return false; }
    }
    $fn = 'cm_cmd_' . $cmd['handler'];
    if (!function_exists($fn)) { return false; }
    $need = cm_need_level($ctx['chat'], $cmd['section'], (int)$cmd['level']);
    $loud = $cmd['prefixed'] || $cmd['target'] || $cmd['exact'];
    if ($need >= 6) {
        if ($cmd['prefixed']) { cm_reply($ctx, cm_t('cmd_off')); }
        return false;
    }
    if ((int)$ctx['rank'] < $need) {
        if ($loud) { cm_reply($ctx, cm_t('no_rank', ['need' => $need])); }
        return false;
    }
    if (!empty($ctx['timer']) && $cmd['handler'] === 'timer_add') { return true; }
    $fn($ctx, $cmd);
    return true;
}

function cm_on_private(PDO $pdo, array $cfg, callable $send, array $msg, int $now): void {
    $cmd = cm_parse((string)($msg['text'] ?? ''), ltrim((string)($cfg['tg_bot_name'] ?? ''), '@'));
    if ($cmd === null || !in_array($cmd['handler'], CM_PRIVATE_HANDLERS, true)) { return; }
    $ctx = cm_ctx($pdo, $cfg, $send, cm_private_chat((int)$msg['chat']['id']), $msg, $now);
    $fn = 'cm_cmd_' . $cmd['handler'];
    $fn($ctx, $cmd);
}

// Кнопки «cm:»: r — репорт, k — массовый кик, o — передача создателя,
// p — приглашение, d — ход в дуэли (этап 3: offers.php, duel.php).
// answerCallbackQuery уходит всегда, иначе у человека крутятся часики. В
// чужом чате кнопки ничего не делают.
function cm_on_callback(PDO $pdo, array $cfg, callable $send, array $q, int $now): void {
    $answer = '';
    $qm = is_array($q['message'] ?? null) ? $q['message'] : null;
    if ($qm !== null && is_array($qm['chat'] ?? null) && (int)($qm['chat']['id'] ?? 0) < 0) {
        $chat = cm_chat_ensure($pdo, $qm['chat'], $now);
        $ctx = cm_ctx($pdo, $cfg, $send, $chat, $qm, $now);
        $from = is_array($q['from'] ?? null) ? $q['from'] : [];
        $ctx['actor'] = (int)($from['id'] ?? 0);
        cm_user_touch($pdo, $from, $now);
        cm_admins_refresh($ctx);
        $ctx['rank'] = cm_rank($ctx, $ctx['actor']);
        $p = explode(':', (string)($q['data'] ?? ''));
        $kind = cm_ours($ctx) ? ($p[1] ?? '') : '';
        if ($kind === 'r' && count($p) === 4) {
            $answer = cm_report_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        } elseif ($kind === 'k' && count($p) === 5) {
            $answer = cm_mass_callback($ctx, $q, (string)$p[2], (int)$p[3], (int)$p[4]);
        } elseif ($kind === 'o' && count($p) === 4) {
            $answer = cm_owner_callback($ctx, $q, (int)$p[2], (int)$p[3]);
        } elseif ($kind === 'p' && count($p) === 4) {
            $answer = cm_offer_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        } elseif ($kind === 'd' && count($p) === 4) {
            $answer = cm_duel_callback($ctx, $q, (int)$p[2], (string)$p[3]);
        }
    }
    $send('answerCallbackQuery', ['callback_query_id' => (string)($q['id'] ?? ''), 'text' => mb_substr(cm_plain($answer), 0, 200)]);
}

// --------------------------------------------------------------------------
//  Общие команды
// --------------------------------------------------------------------------

// Помощь: заголовок, разделы под раскрывающейся цитатой (чат не заливает
// простынёй) и ссылка на полную инструкцию. Как писать команды — на /bot.
function cm_cmd_help(array $ctx, array $cmd): void {
    cm_reply($ctx, cm_help_text(), false);
}

function cm_help_text(): string {
    $rows = [];
    foreach (CM_HELP as [$icon, $title, $cmds]) { $rows[] = $icon . ' ' . cm_bold($title) . ' — ' . $cmds; }
    return cm_bold(cm_t('help_title')) . "\n\n" . cm_mark('q', '', implode("\n", $rows)) . "\n\n" . cm_guide_line();
}

// «📖 Все команды с примерами: maknemy.com/bot» — адрес ссылкой.
function cm_guide_line(): string {
    return cm_t('help_more') . cm_link(CM_GUIDE_URL, CM_GUIDE_TEXT);
}

// «кто я» — свой профиль (profile.php). Пока нет таблиц этапа 2 — ранг и
// варны, как в этапе 1.
function cm_cmd_whoami(array $ctx, array $cmd): void {
    if (cm_ready2($ctx['pdo'])) {
        $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
        cm_reply($ctx, implode("\n", cm_profile_lines($ctx, (int)$ctx['actor'], cm_user_tag($ctx['pdo'], (int)$ctx['actor'], cm_display_name($from)))), false);
        return;
    }
    $r = (int)$ctx['rank'];
    cm_reply($ctx, cm_t('whoami', ['n' => $r, 'rank' => CM_RANK_NAMES[$r], 'warns' => cm_warns_active($ctx, (int)$ctx['actor']),
        'limit' => (int)$ctx['chat']['settings']['warns']['limit']]));
}

function cm_cmd_id(array $ctx, array $cmd): void {
    $text = cm_t('id_text', ['chat' => (int)$ctx['chat']['chat_id'], 'me' => (int)$ctx['actor']]);
    $r = cm_reply_of($ctx['msg']);
    if ($r !== null && is_array($r['from'] ?? null)) {
        $text .= "\n" . cm_t('id_target', ['name' => cm_user_tag($ctx['pdo'], (int)($r['from']['id'] ?? 0), cm_display_name($r['from'])), 'id' => (int)($r['from']['id'] ?? 0)]);
    }
    cm_reply($ctx, $text);
}
