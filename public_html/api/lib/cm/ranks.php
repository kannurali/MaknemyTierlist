<?php
// Ранги 0–5 и доступ к командам. Ранг бота и админ Telegram — разные вещи:
// админ Telegram без ранга — 0, но фильтры его не трогают и наказать его бот
// не может (не даёт Telegram). Ранг 5 — owner_id; сначала это создатель
// группы в Telegram, дальше — через «передать создателя».

const CM_SECTIONS = ['ранги', 'варны', 'мут', 'кик', 'бан', 'фильтры', 'триггеры', 'приветствие',
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры', 'статистика',
                     'репутация', 'награды', 'анкета', 'игры', 'рп', 'отношения'];

// Перечитать админов Telegram, если кешу больше часа. Не вышло — повтор тоже
// через час: иначе каждое сообщение группы стучалось бы в Telegram.
function cm_admins_refresh(array &$ctx, bool $force = false): void {
    $chat = $ctx['chat'];
    if (!empty($chat['private'])) { return; }
    if (!$force && $chat['admins_at'] > (int)$ctx['now'] - CM_ADMINS_TTL) { return; }
    $res = ($ctx['send'])('getChatAdministrators', ['chat_id' => $chat['chat_id']]);
    $admins = $chat['admins'];
    $creator = $chat['tg_owner_id'];
    if (cm_ok($res) && is_array($res['result'] ?? null)) {
        $admins = [];
        foreach ($res['result'] as $m) {
            $u = is_array($m['user'] ?? null) ? $m['user'] : [];
            $id = (int)($u['id'] ?? 0);
            if ($id <= 0 || !empty($u['is_bot'])) { continue; }
            $admins[] = $id;
            cm_user_touch($ctx['pdo'], $u, (int)$ctx['now']);
            if (($m['status'] ?? '') === 'creator') { $creator = $id; }
        }
    }
    $owner = $chat['owner_id'] > 0 ? $chat['owner_id'] : $creator;
    $ctx['pdo']->prepare('UPDATE cm_chats SET admins = :a, admins_at = :n, tg_owner_id = :t, owner_id = :o WHERE chat_id = :c')
        ->execute([':a' => json_encode($admins), ':n' => $ctx['now'], ':t' => $creator, ':o' => $owner, ':c' => $chat['chat_id']]);
    $ctx['chat']['admins'] = $admins;
    $ctx['chat']['admins_at'] = (int)$ctx['now'];
    $ctx['chat']['tg_owner_id'] = $creator;
    $ctx['chat']['owner_id'] = $owner;
}

function cm_rank(array $ctx, int $userId): int {
    if ($userId <= 0) { return 0; }
    if ($userId === (int)$ctx['chat']['owner_id']) { return 5; }
    if (!empty($ctx['chat']['private'])) { return 0; }
    $m = cm_member($ctx['pdo'], (int)$ctx['chat']['chat_id'], $userId);
    return $m ? max(0, min(4, $m['lvl'])) : 0;
}

function cm_is_tg_admin(array $ctx, int $userId): bool {
    return $userId > 0 && ($userId === (int)$ctx['chat']['tg_owner_id'] || in_array($userId, $ctx['chat']['admins'], true));
}

// Кого не трогают фильтры.
function cm_exempt(array $ctx, int $userId): bool {
    return cm_rank($ctx, $userId) >= 1 || cm_is_tg_admin($ctx, $userId);
}

// Можно ли наказать/понизить цель. '' — можно, иначе ключ текста.
function cm_can_touch(array $ctx, array $t): string {
    if (!empty($t['is_bot'])) { return 'target_bot'; }
    if ((int)$t['id'] === (int)$ctx['actor']) { return 'target_self'; }
    if (cm_is_tg_admin($ctx, (int)$t['id'])) { return 'target_admin'; }
    if (cm_rank($ctx, (int)$t['id']) >= (int)$ctx['rank']) { return 'target_rank'; }
    return '';
}

// Ранг раздела по умолчанию — младший из рангов его команд: «дк» меняет
// уровень всего раздела сразу.
function cm_section_default(string $section): int {
    $min = 6;
    foreach (CM_COMMANDS as $def) {
        if ($def[1] === $section) { $min = min($min, $def[2]); }
    }
    return $min;
}

// Нужный ранг. 6 — команда выключена.
function cm_need_level(array $chat, ?string $section, int $default): int {
    if ($section === null) { return $default; }
    $a = $chat['settings']['access'][$section] ?? null;
    return (is_int($a) && $a >= 0 && $a <= 6) ? $a : $default;
}

function cm_rank_store(PDO $pdo, int $chatId, int $userId, int $old, int $new, int $byId, int $now): void {
    cm_member_set($pdo, $chatId, $userId, ['lvl' => $new]);
    $pdo->prepare('INSERT INTO cm_rank_log (chat_id, user_id, by_id, old_lvl, new_lvl, at) VALUES (:c, :u, :b, :o, :n, :a)')
        ->execute([':c' => $chatId, ':u' => $userId, ':b' => $byId, ':o' => $old, ':n' => $new, ':a' => $now]);
}

// Цель команды или подсказка, как её указать.
function cm_need_target(array $ctx, string &$args): ?array {
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t === null) { cm_reply($ctx, cm_t($why === 'unknown' ? 'target_unknown' : 'need_target')); }
    return $t;
}

// --------------------------------------------------------------------------
//  Команды рангов
// --------------------------------------------------------------------------

function cm_rank_change(array $ctx, array $t, int $new): void {
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    if ($new < 0 || $new > 4) {
        cm_reply($ctx, cm_t('rank_need_level'));
        return;
    }
    $old = cm_rank($ctx, (int)$t['id']);
    if ($old >= (int)$ctx['rank'] || $new >= (int)$ctx['rank']) {
        cm_reply($ctx, cm_t('rank_too_high'));
        return;
    }
    if ($new === $old) {
        cm_reply($ctx, cm_t('rank_same', ['name' => $t['name'], 'rank' => CM_RANK_NAMES[$old]]));
        return;
    }
    cm_rank_store($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$t['id'], $old, $new, (int)$ctx['actor'], (int)$ctx['now']);
    cm_reply($ctx, cm_t('rank_done', ['name' => $t['name'], 'rank' => CM_RANK_NAMES[$new], 'n' => $new]), false);
}

function cm_cmd_rank_set(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!preg_match('~^(\d)(?!\d)~', trim($args), $m)) {
        cm_reply($ctx, cm_t('rank_need_level'));
        return;
    }
    cm_rank_change($ctx, $t, (int)$m[1]);
}

function cm_cmd_rank_up(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, min(4, cm_rank($ctx, (int)$t['id']) + 1)); }
}

function cm_cmd_rank_down(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, max(0, cm_rank($ctx, (int)$t['id']) - 1)); }
}

function cm_cmd_rank_clear(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t !== null) { cm_rank_change($ctx, $t, 0); }
}

function cm_cmd_rank_list(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $owner = (int)$ctx['chat']['owner_id'];
    $lines = [];
    if ($owner > 0) { $lines[] = '5 · ' . cm_user_name($pdo, $owner); }
    $st = $pdo->prepare('SELECT user_id, lvl FROM cm_members WHERE chat_id = :c AND lvl > 0 AND user_id <> :o ORDER BY lvl DESC, user_id');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':o' => $owner]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = min(4, (int)$r['lvl']) . ' · ' . cm_user_name($pdo, (int)$r['user_id']);
    }
    cm_reply($ctx, $lines ? cm_t('ranks_head') . "\n" . implode("\n", $lines) : cm_t('ranks_empty'), false);
}

function cm_cmd_rank_log(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $args = $cmd['args'];
    $t = cm_target($pdo, $ctx['msg'], $args);
    $sql = 'SELECT user_id, by_id, old_lvl, new_lvl, at FROM cm_rank_log WHERE chat_id = :c'
         . ($t ? ' AND user_id = :u' : '') . ' ORDER BY id DESC LIMIT 15';
    $p = [':c' => $ctx['chat']['chat_id']];
    if ($t) { $p[':u'] = $t['id']; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lines[] = cm_t('ranklog_line', ['date' => cm_date((int)$r['at']), 'by' => cm_user_name($pdo, (int)$r['by_id']),
            'name' => cm_user_name($pdo, (int)$r['user_id']), 'old' => (int)$r['old_lvl'], 'new' => (int)$r['new_lvl']]);
    }
    cm_reply($ctx, $lines ? cm_t('ranklog_head') . "\n" . implode("\n", $lines) : cm_t('ranklog_empty'), false);
}

// --------------------------------------------------------------------------
//  Создатель
// --------------------------------------------------------------------------

function cm_cmd_owner_pass(array $ctx, array $cmd): void {
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot']) || (int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_bad'));
        return;
    }
    $data = 'cm:o:' . (int)$ctx['actor'] . ':' . (int)$t['id'];
    cm_reply($ctx, cm_t('owner_offer', ['name' => $t['name'], 'owner' => cm_user_name($ctx['pdo'], (int)$ctx['actor'])]), false,
        ['buttons' => [[['text' => cm_t('btn_accept'), 'callback_data' => $data]]]]);
}

// Кнопку жмёт тот, кому передают. Строка чата перечитывается: предложение
// могло устареть, пока висело.
function cm_owner_callback(array $ctx, array $q, int $from, int $to): string {
    if ((int)$ctx['actor'] !== $to) { return cm_t('not_yours'); }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $chat = cm_chat_row($pdo, $chatId);
    if ($chat === null || $chat['owner_id'] !== $from) { return cm_t('offer_stale'); }
    $now = (int)$ctx['now'];
    $pdo->prepare('UPDATE cm_chats SET owner_id = :o WHERE chat_id = :c')->execute([':o' => $to, ':c' => $chatId]);
    cm_rank_store($pdo, $chatId, $from, 5, 4, $from, $now);
    $was = cm_member($pdo, $chatId, $to);
    cm_rank_store($pdo, $chatId, $to, $was ? min(4, $was['lvl']) : 0, 5, $from, $now);
    $text = cm_t('owner_done', ['name' => cm_user_name($pdo, $to)]);
    $qm = is_array($q['message'] ?? null) ? $q['message'] : [];
    ($ctx['send'])('editMessageText', ['chat_id' => (int)($qm['chat']['id'] ?? $chatId),
        'message_id' => (int)($qm['message_id'] ?? 0), 'text' => $text]);
    return $text;
}

function cm_cmd_owner_restore(array $ctx, array $cmd): void {
    cm_admins_refresh($ctx, true);
    $me = (int)$ctx['actor'];
    if ($me !== (int)$ctx['chat']['tg_owner_id']) {
        cm_reply($ctx, cm_t('owner_tg_only'));
        return;
    }
    $prev = (int)$ctx['chat']['owner_id'];
    if ($prev === $me) {
        cm_reply($ctx, cm_t('owner_already'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $pdo->prepare('UPDATE cm_chats SET owner_id = :o WHERE chat_id = :c')->execute([':o' => $me, ':c' => $chatId]);
    if ($prev > 0) { cm_rank_store($pdo, $chatId, $prev, 5, 4, $me, (int)$ctx['now']); }
    $was = cm_member($pdo, $chatId, $me);
    cm_rank_store($pdo, $chatId, $me, $was ? min(4, $was['lvl']) : 0, 5, $me, (int)$ctx['now']);
    cm_reply($ctx, cm_t('owner_restored', ['name' => cm_user_name($pdo, $me)]), false);
}

// --------------------------------------------------------------------------
//  Доступ: «дк»
// --------------------------------------------------------------------------

function cm_cmd_access(array $ctx, array $cmd): void {
    $args = cm_norm(trim($cmd['args']));
    $s = $ctx['chat']['settings'];
    if ($args === '') {
        $lines = [];
        foreach (CM_SECTIONS as $sec) {
            $lines[] = $sec . ': ' . cm_need_level($ctx['chat'], $sec, cm_section_default($sec));
        }
        cm_reply($ctx, cm_t('access_head') . "\n" . implode("\n", $lines), false);
        return;
    }
    if ($args === 'сброс') {
        $s['access'] = [];
        cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
        cm_reply($ctx, cm_t('access_reset'), false);
        return;
    }
    if (!preg_match('~^(\S+)\s+([0-6])$~u', $args, $m) || !in_array($m[1], CM_SECTIONS, true)) {
        cm_reply($ctx, cm_t('access_usage', ['sections' => implode(', ', CM_SECTIONS)]));
        return;
    }
    $s['access'][$m[1]] = (int)$m[2];
    cm_settings_save($ctx['pdo'], $ctx['chat'], $s, (int)$ctx['now']);
    cm_reply($ctx, cm_t('access_done', ['section' => $m[1], 'level' => (int)$m[2]]), false);
}
