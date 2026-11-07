<?php
// Ранги 0–5 и доступ к командам. Ранг бота и админ Telegram — разные вещи:
// админ Telegram без ранга — 0, но фильтры его не трогают и наказать его бот
// не может (не даёт Telegram). Ранг 5 — owner_id; сначала это создатель
// группы в Telegram, дальше — через «передать создателя».

const CM_SECTIONS = ['ранги', 'варны', 'мут', 'кик', 'бан', 'фильтры', 'триггеры', 'приветствие',
                     'правила', 'репорты', 'чистка', 'заметки', 'таймеры'];

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
