<?php
// Приглашения с кнопками и общее для игр (этап 3). Приглашают на дуэль, в
// кубы, в пару, в брак, вернуть брак и в клан. «Принять» / «Отказаться»
// (cm:p:<id>:y|n, router.php) жмёт только адресат. Срок — CM_OFFER_TTL;
// просроченные закрывает cm_games_tick() из cron. От человека в чате — одно
// открытое приглашение каждого вида: новое закрывает старое. «Принять» зовёт
// cm_offer_yes_<вид>() из файла раздела — он заново проверяет условия.
// Кнопки правят то же сообщение (editMessageText), новых не шлют: так игры не
// съедают бюджет группы (send.php).

const CM_OFFER_TTL     = ['duel' => 300, 'dice' => 300, 'couple' => 600, 'marry' => 600, 'remarry' => 600, 'clan' => 300];
// Ключ настройки исхода → «в чём» проигрыш, для текстов.
const CM_OUTCOME_GAMES = ['duel_outcome' => 'дуэли', 'dice_outcome' => 'кубах'];
const CM_GAME_FIELDS   = ['wins', 'losses', 'draws'];

function cm_offer_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_offers WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (['id', 'chat_id', 'from_id', 'to_id', 'ref_id', 'msg_id', 'expires_at'] as $k) { $r[$k] = (int)$r[$k]; }
    $r['kind'] = (string)$r['kind'];
    $r['status'] = (string)$r['status'];
    return $r;
}

// Закрыть открытое приглашение. false — его уже закрыли (второе нажатие, cron).
function cm_offer_close(PDO $pdo, int $id, string $status): bool {
    $st = $pdo->prepare("UPDATE cm_offers SET status = :s WHERE id = :id AND status = 'open'");
    $st->execute([':s' => $status, ':id' => $id]);
    return $st->rowCount() > 0;
}

// Поправить сообщение бота в чате контекста. Без $buttons кнопки пропадают.
function cm_edit(array $ctx, int $msgId, string $text, array $buttons = []): void {
    if ($msgId <= 0) { return; }
    $p = ['chat_id' => (int)$ctx['chat']['chat_id'], 'message_id' => $msgId, 'text' => mb_substr($text, 0, 4096),
          'link_preview_options' => ['is_disabled' => true]];
    if ($buttons) { $p['reply_markup'] = ['inline_keyboard' => $buttons]; }
    ($ctx['send'])('editMessageText', $p);
}

function cm_offer_buttons(int $id): array {
    return [[['text' => cm_t('btn_accept'), 'callback_data' => 'cm:p:' . $id . ':y'],
             ['text' => cm_t('btn_decline'), 'callback_data' => 'cm:p:' . $id . ':n']]];
}

/**
 * Приглашение от автора контекста: прежнее открытое того же вида закрывается,
 * новое уходит ответом на команду. Возвращает id или 0 — бюджет сообщений
 * группы кончился, приглашения нет.
 */
function cm_offer_send(array $ctx, string $kind, int $toId, int $refId, string $text): int {
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $from = (int)$ctx['actor'];
    $st = $pdo->prepare("SELECT id, msg_id FROM cm_offers WHERE chat_id = :c AND from_id = :f AND kind = :k AND status = 'open'");
    $st->execute([':c' => $chatId, ':f' => $from, ':k' => $kind]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $old) {
        if (cm_offer_close($pdo, (int)$old['id'], 'gone')) { cm_edit($ctx, (int)$old['msg_id'], cm_t('offer_replaced')); }
    }
    $pdo->prepare("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status)
                   VALUES (:c, :k, :f, :t, :r, 0, :e, 'open')")
        ->execute([':c' => $chatId, ':k' => $kind, ':f' => $from, ':t' => $toId, ':r' => $refId,
                   ':e' => (int)$ctx['now'] + CM_OFFER_TTL[$kind]]);
    $id = (int)$pdo->lastInsertId();
    $mid = cm_reply($ctx, $text, false, ['buttons' => cm_offer_buttons($id)]);
    if ($mid === 0) {
        cm_offer_close($pdo, $id, 'gone');
        return 0;
    }
    $pdo->prepare('UPDATE cm_offers SET msg_id = :m WHERE id = :id')->execute([':m' => $mid, ':id' => $id]);
    return $id;
}

/**
 * Кнопка приглашения; возвращает текст для answerCallbackQuery. Чужое
 * нажатие — «не для вас»; закрытое или просроченное — «уже неактуально», без
 * сообщений в чат. «Принять» → cm_offer_yes_<вид>($ctx, $offer): '' — вышло
 * (сообщение он правит сам), иначе ключ текста: приглашение закрывается им.
 */
function cm_offer_callback(array $ctx, array $q, int $id, string $ans): string {
    if ($ans !== 'y' && $ans !== 'n') { return ''; }
    $pdo = $ctx['pdo'];
    $o = cm_ready3($pdo) ? cm_offer_get($pdo, $id) : null;
    if ($o === null || $o['chat_id'] !== (int)$ctx['chat']['chat_id'] || $o['status'] !== 'open') { return cm_t('offer_stale'); }
    if ((int)$ctx['actor'] !== $o['to_id']) { return cm_t('not_yours'); }
    if ($o['expires_at'] <= (int)$ctx['now']) {
        if (cm_offer_close($pdo, $id, 'gone')) { cm_edit($ctx, $o['msg_id'], cm_t('offer_expired')); }
        return cm_t('offer_stale');
    }
    if ($ans === 'n') {
        if (!cm_offer_close($pdo, $id, 'no')) { return cm_t('offer_stale'); }
        $text = cm_t('offer_declined', ['name' => cm_user_name($pdo, $o['to_id'])]);
        cm_edit($ctx, $o['msg_id'], $text);
        return $text;
    }
    $fn = 'cm_offer_yes_' . $o['kind'];
    if (!function_exists($fn) || !cm_offer_close($pdo, $id, 'yes')) { return cm_t('offer_stale'); }
    $err = $fn($ctx, $o);
    if ($err === '') { return cm_t('offer_accepted'); }
    $pdo->prepare("UPDATE cm_offers SET status = 'gone' WHERE id = :id")->execute([':id' => $id]);
    cm_edit($ctx, $o['msg_id'], cm_t($err));
    return cm_t($err);
}

// Контекст для cron в чате $chatId — от имени бота. null — бота в чате нет.
function cm_tick_ctx(PDO $pdo, array $cfg, callable $send, int $chatId, int $now): ?array {
    $chat = cm_chat_row($pdo, $chatId);
    if ($chat === null || $chat['active'] !== 1) { return null; }
    $msg = ['message_id' => 0, 'chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => $chat['title']]];
    $ctx = cm_ctx($pdo, $cfg, $send, $chat, $msg, $now);
    cm_admins_refresh($ctx);
    return $ctx;
}

// Раз в минуту (cron.php): закрыть просроченные приглашения. Сколько закрыто.
function cm_games_tick(PDO $pdo, array $cfg, callable $send, int $now): int {
    if (!cm_ready3($pdo)) { return 0; }
    $st = $pdo->prepare("SELECT id, chat_id, msg_id FROM cm_offers WHERE status = 'open' AND expires_at <= :n ORDER BY id LIMIT 100");
    $st->execute([':n' => $now]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if (!cm_offer_close($pdo, (int)$o['id'], 'gone')) { continue; }
        $ctx = cm_tick_ctx($pdo, $cfg, $send, (int)$o['chat_id'], $now);
        if ($ctx !== null) { cm_edit($ctx, (int)$o['msg_id'], cm_t('offer_expired')); }
        $n++;
    }
    return $n;
}

// --------------------------------------------------------------------------
//  Игры: вызов, счёт, ставка, исход
// --------------------------------------------------------------------------

// Вызов на дуэль или в кубы. Себя и ботов нельзя.
function cm_game_invite(array $ctx, string $kind, array $t, string $textKey, string $outcomeKey): void {
    if (cm_botlike($t)) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_offer_send($ctx, $kind, (int)$t['id'], 0, cm_t($textKey, ['to' => $t['name'], 'from' => cm_display_name($from),
        'stake' => cm_stake_line($ctx, $outcomeKey, (int)$ctx['actor'], (int)$t['id'])]));
}

// +1 к победам, поражениям или ничьим.
function cm_game_score(PDO $pdo, int $chatId, int $userId, string $kind, string $field): void {
    if (!in_array($field, CM_GAME_FIELDS, true)) { return; }
    $p = [':c' => $chatId, ':u' => $userId, ':k' => $kind];
    $up = $pdo->prepare("UPDATE cm_game_stats SET $field = $field + 1 WHERE chat_id = :c AND user_id = :u AND kind = :k");
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare('INSERT INTO cm_game_stats (chat_id, user_id, kind, wins, losses, draws) VALUES (:c, :u, :k, :w, :l, :d)')
            ->execute($p + [':w' => (int)($field === 'wins'), ':l' => (int)($field === 'losses'), ':d' => (int)($field === 'draws')]);
    } catch (PDOException $e) {
        $up->execute($p);   // параллельная игра уже завела строку
    }
}

function cm_game_stat(PDO $pdo, int $chatId, int $userId, string $kind): array {
    $st = $pdo->prepare('SELECT wins, losses, draws FROM cm_game_stats WHERE chat_id = :c AND user_id = :u AND kind = :k');
    $st->execute([':c' => $chatId, ':u' => $userId, ':k' => $kind]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['wins' => (int)($r['wins'] ?? 0), 'losses' => (int)($r['losses'] ?? 0), 'draws' => (int)($r['draws'] ?? 0)];
}

// «5 побед / 2 поражения», с ничьими — «… / 1 ничья».
function cm_score_text(array $s, bool $draws): string {
    $t = $s['wins'] . ' ' . cm_plural($s['wins'], ['победа', 'победы', 'побед'])
       . ' / ' . $s['losses'] . ' ' . cm_plural($s['losses'], ['поражение', 'поражения', 'поражений']);
    if ($draws) { $t .= ' / ' . $s['draws'] . ' ' . cm_plural($s['draws'], ['ничья', 'ничьи', 'ничьих']); }
    return $t;
}

// Топ-10 по победам (при равенстве — меньше поражений), без покинувших чат.
function cm_game_top(array $ctx, string $kind, string $head, string $empty, bool $draws): void {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT g.user_id, g.wins, g.losses, g.draws FROM cm_game_stats g
        LEFT JOIN cm_members m ON m.chat_id = g.chat_id AND m.user_id = g.user_id
        WHERE g.chat_id = :c AND g.kind = :k AND g.wins > 0 AND (m.left_at IS NULL OR m.left_at = 0)
        ORDER BY g.wins DESC, g.losses, g.user_id LIMIT 10');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':k' => $kind]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $s = ['wins' => (int)$r['wins'], 'losses' => (int)$r['losses'], 'draws' => (int)$r['draws']];
        $lines[] = cm_t('game_top_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, (int)$r['user_id']),
            'score' => cm_score_text($s, $draws)]);
    }
    cm_reply($ctx, $lines ? cm_t($head) . "\n" . implode("\n", $lines) : cm_t($empty), false);
}

// Ставка игры $a против $b: действия исхода, если модератор их включил и
// оба — не модераторы и не админы Telegram. Игра с ними идёт без ставки для
// обоих: исход их не наказывает, а наказывать только второго нечестно.
function cm_game_stake(array $ctx, string $key, int $a, int $b): array {
    $acts = (array)($ctx['chat']['settings'][$key] ?? []);
    if (!$acts || cm_exempt($ctx, $a) || cm_exempt($ctx, $b)) { return []; }
    return $acts;
}

// «\nСтавка: проигравшему — мут 10 мин.» или ''.
function cm_stake_line(array $ctx, string $key, int $a, int $b): string {
    $acts = cm_game_stake($ctx, $key, $a, $b);
    return $acts ? cm_t('game_stake', ['actions' => cm_actions_text($acts)]) : '';
}

// Исход проигравшему от имени бота (cm_apply_actions этапа 1). '' — исхода
// нет, иначе строка «⚖️ Вася: мут 10 мин» для итога.
function cm_game_outcome(array $ctx, string $key, int $a, int $b, int $loser, string $reason): string {
    $acts = cm_game_stake($ctx, $key, $a, $b);
    if (!$acts) { return ''; }
    $done = cm_apply_actions($ctx, $loser, 0, $acts, $reason, 0);
    return $done === '' ? '' : cm_t('game_outcome', ['name' => cm_user_name($ctx['pdo'], $loser), 'done' => $done]);
}

// «кубы исход мут 10м», «дуэли исход ничего»; без слов — что сейчас.
// «удалить» не годится: удалять нечего.
function cm_outcome_cmd(array $ctx, array $cmd, string $key): void {
    $game = CM_OUTCOME_GAMES[$key];
    $a = cm_norm(trim($cmd['args']));
    $s = $ctx['chat']['settings'];
    if ($a === '') {
        $cur = (array)$s[$key];
        cm_reply($ctx, $cur ? cm_t('outcome_set', ['game' => $game, 'actions' => cm_actions_text($cur)])
            : cm_t('outcome_off', ['game' => $game]));
        return;
    }
    $acts = $a === 'ничего' ? [] : cm_parse_actions($a, false);
    foreach ((array)$acts as $x) {
        if ($x['kind'] === 'delete') { $acts = null; }
    }
    if ($acts === null) {
        cm_reply($ctx, cm_t('outcome_usage'));
        return;
    }
    $s[$key] = $acts;
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, $acts ? cm_t('outcome_set', ['game' => $game, 'actions' => cm_actions_text($acts)])
        : cm_t('outcome_off', ['game' => $game]), false);
}
