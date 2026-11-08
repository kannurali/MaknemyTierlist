<?php
// Чат-менеджер: таблицы cm_* (docs/migrations/2026-11-07-chat-manager.sql).
//
// Upsert везде «UPDATE, а если ноль строк — INSERT»: ON DUPLICATE KEY и
// ON CONFLICT непереносимы, а тесты идут на SQLite. rowCount у MySQL считает
// только изменённые строки, поэтому INSERT может упереться в ключ — это не
// ошибка, её глушим.

const CM_TZ         = 'Europe/Moscow';
const CM_MSG_KEEP   = 172800;   // 48 ч: старше Telegram не даёт боту удалять
const CM_ADMINS_TTL = 3600;     // список админов Telegram перечитывается раз в час

const CM_RANK_NAMES = ['участник', 'младший модератор', 'старший модератор',
                       'младший администратор', 'старший администратор', 'создатель'];

// Сам, без команды модератора, бот трогает только ссылки, спамеров из базы и
// служебные сообщения (вход, выход, «… удалил(а) @ник», закреп — с 08.11.2026
// по просьбе владельца).
const CM_DEFAULTS = [
    'filters'       => ['spam' => true, 'links' => true, 'forwards' => false, 'mat' => false, 'flood' => false,
                        'caps' => false, 'stickers' => false, 'gifs' => false, 'voice' => false, 'service' => true],
    'flood'         => [5, 10],
    'domains'       => [],
    'triggers'      => [],
    'access'        => [],
    'warns'         => ['limit' => 5, 'ttl' => 2592000],
    'welcome'       => '',
    'rules'         => ['text' => '', 'entities' => []],
    'autodelete'    => true,
    'report_code'   => null,
    'raid_greet_at' => 0,
    // последний «созвать админов» от участника (reports.php)
    'call_at'       => 0,
    // этап 3: исход проигравшему в дуэли и кубах — действия в формате
    // триггеров; [] — проигрыш без наказания, исход включает модератор
    'duel_outcome'  => [],
    'dice_outcome'  => [],
    // чат Макнеми: только в таких бот работает (cm_ours ниже)
    'maknemy'       => false,
    // чат Макнеми вне сети (тестовый): бот работает, но настройки, ранги,
    // наказания и статистика у него свои (cm_net ниже)
    'solo'          => false,
];

function cm_enabled(array $cfg): bool {
    return !array_key_exists('cm_enabled', $cfg) || $cfg['cm_enabled'] !== false;
}

// Миграция выполняется руками: пока таблиц нет, модуль молчит.
function cm_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_chats LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Сохранённые настройки поверх умолчаний. Чужие ключи и значения не того типа
// отбрасываются: настройки пишет только бот, но JSON в базе мог устареть.
function cm_settings(array $raw): array {
    $out = CM_DEFAULTS;
    foreach ($raw as $k => $v) {
        if (!array_key_exists($k, $out)) { continue; }
        if (($k === 'filters' || $k === 'warns') && is_array($v)) {
            $out[$k] = array_merge($out[$k], array_intersect_key($v, $out[$k]));
            continue;
        }
        if ($out[$k] !== null && gettype($v) !== gettype($out[$k])) { continue; }
        $out[$k] = $v;
    }
    return $out;
}

function cm_chat_decode(array $r): array {
    $ints = ['chat_id', 'active', 'owner_id', 'tg_owner_id', 'admins_at', 'report_chat_id',
             'rights_warned_at', 'sent_window', 'sent_count', 'added_at', 'updated_at'];
    foreach ($ints as $k) { $r[$k] = (int)($r[$k] ?? 0); }
    $r['title']    = (string)($r['title'] ?? '');
    $r['username'] = (string)($r['username'] ?? '');
    $s = is_string($r['settings'] ?? null) ? json_decode($r['settings'], true) : null;
    $r['settings'] = cm_settings(is_array($s) ? $s : []);
    $a = is_string($r['admins'] ?? null) ? json_decode($r['admins'], true) : null;
    $r['admins']   = is_array($a) ? array_values(array_map('intval', $a)) : [];
    $r['private']  = false;
    return $r;
}

function cm_chat_row(PDO $pdo, int $chatId): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_chats WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_chat_decode($r) : null;
}

// Строка чата по объекту Chat из Telegram: завести, обновить название, оживить.
function cm_chat_ensure(PDO $pdo, array $tgChat, int $now): array {
    $id    = (int)($tgChat['id'] ?? 0);
    $title = mb_substr((string)($tgChat['title'] ?? ''), 0, 255);
    $user  = strtolower(mb_substr((string)($tgChat['username'] ?? ''), 0, 64));
    $row = cm_chat_row($pdo, $id);
    if ($row !== null) {
        if ($row['title'] !== $title || $row['username'] !== $user || $row['active'] !== 1) {
            $pdo->prepare('UPDATE cm_chats SET title = :t, username = :u, active = 1, updated_at = :n WHERE chat_id = :c')
                ->execute([':t' => $title, ':u' => $user, ':n' => $now, ':c' => $id]);
            $row['title'] = $title;
            $row['username'] = $user;
            $row['active'] = 1;
        }
        return $row;
    }
    try {
        $pdo->prepare('INSERT INTO cm_chats (chat_id, title, username, active, settings, added_at, updated_at)
                       VALUES (:c, :t, :u, 1, :s, :n, :n)')
            ->execute([':c' => $id, ':t' => $title, ':u' => $user, ':s' => '{}', ':n' => $now]);
    } catch (PDOException $e) {
        // параллельный апдейт уже завёл строку
    }
    return cm_chat_row($pdo, $id) ?? cm_chat_decode(['chat_id' => $id, 'title' => $title, 'username' => $user, 'active' => 1]);
}

// Личка с ботом: строки в cm_chats нет, настройки — умолчания.
function cm_private_chat(int $chatId): array {
    $c = cm_chat_decode(['chat_id' => $chatId, 'active' => 1, 'admins_at' => PHP_INT_MAX]);
    $c['private'] = true;
    return $c;
}

// Ключи настроек, которые у каждого чата свои. Всё остальное (фильтры,
// триггеры, приветствие, правила, «дк», варны…) в чатах сети общее —
// решение владельца 08.11.2026. Исходы дуэлей и кубов — игровые, по чату.
const CM_LOCAL_SETTINGS = ['report_code', 'raid_greet_at', 'call_at', 'duel_outcome', 'dice_outcome', 'maknemy', 'solo'];

function cm_settings_shared(array $s): array {
    return array_diff_key($s, array_flip(CM_LOCAL_SETTINGS));
}

// Сохранить настройки. В чате сети общие ключи переписываются во всех её
// чатах — только если они изменились: отметка созыва или рейда не должна
// затирать свежую правку соседа своей старой копией. $share = false —
// только этот чат (вход в сеть: общие настройки он берёт у сети).
function cm_settings_save(PDO $pdo, array &$chat, array $settings, int $now, bool $share = true): void {
    $before = cm_settings_shared($chat['settings'] ?? []);
    $chat['settings'] = cm_settings($settings);
    $pdo->prepare('UPDATE cm_chats SET settings = :s, updated_at = :n WHERE chat_id = :c')
        ->execute([':s' => json_encode($chat['settings'], JSON_UNESCAPED_UNICODE), ':n' => $now, ':c' => (int)$chat['chat_id']]);
    $shared = cm_settings_shared($chat['settings']);
    if (!$share || !empty($chat['private']) || !cm_is_ours($chat) || $shared === $before) { return; }
    $own = (int)$chat['chat_id'];
    foreach (cm_net($pdo, $own) as $id) {
        if ($id === $own) { continue; }
        $row = cm_chat_row($pdo, $id);
        if ($row === null) { continue; }
        $pdo->prepare('UPDATE cm_chats SET settings = :s, updated_at = :n WHERE chat_id = :c')
            ->execute([':s' => json_encode(cm_settings(array_merge($row['settings'], $shared)), JSON_UNESCAPED_UNICODE),
                       ':n' => $now, ':c' => $id]);
    }
}

// --------------------------------------------------------------------------
//  Чаты Макнеми
// --------------------------------------------------------------------------
// Бот работает только в чатах Макнеми (решение владельца 08.11.2026). Чат
// наш, если бота в него добавил или повысил сотрудник сайта (cm_is_staff,
// spam.php) либо создатель группы в Telegram — сотрудник. Отметка
// settings.maknemy не снимается. В чужой группе бот молчит, но не выходит:
// там он может проверять подписку для условий розыгрыша.
//
// Чаты Макнеми — одна сеть (решение владельца 08.11.2026): общие настройки,
// ранги, наказания, заметки, таймеры, жалобы, награды, пары, браки и кланы;
// сообщения и репутация складываются по всем (7 в трейд-чате и 9 в
// главном — 16). По чату — только игры (дуэли, кубы, шахматы, РП, «кто …»)
// и создатель (ранг 5). Тестовый чат держат вне сети отметкой solo.

function cm_is_ours(array $chat): bool {
    return !empty($chat['settings']['maknemy']);
}

// Новый чат сети берёт её общие настройки и чат жалоб у последнего
// изменённого чата сети, свои ключи (CM_LOCAL_SETTINGS) оставляет.
function cm_mark_ours(PDO $pdo, array &$chat, int $now): void {
    if (cm_is_ours($chat) || !empty($chat['private'])) { return; }
    $s = $chat['settings'];
    $s['maknemy'] = true;
    $src = cm_net_source($pdo, (int)$chat['chat_id']);
    if ($src !== null) {
        $s = array_merge($s, cm_settings_shared($src['settings']));
        $pdo->prepare('UPDATE cm_chats SET report_chat_id = :r WHERE chat_id = :c')
            ->execute([':r' => $src['report_chat_id'], ':c' => (int)$chat['chat_id']]);
        $chat['report_chat_id'] = $src['report_chat_id'];
    }
    cm_settings_save($pdo, $chat, $s, $now, false);
}

function cm_net_source(PDO $pdo, int $chatId): ?array {
    $best = null;
    foreach (cm_net($pdo, $chatId) as $id) {
        if ($id === $chatId) { continue; }
        $row = cm_chat_row($pdo, $id);
        if ($row !== null && ($best === null || $row['updated_at'] > $best['updated_at'])) { $best = $row; }
    }
    return $best;
}

// Наш ли чат контекста. Создатель берётся из последнего cm_admins_refresh.
function cm_ours(array &$ctx): bool {
    if (cm_is_ours($ctx['chat'])) { return true; }
    $owner = (int)($ctx['chat']['tg_owner_id'] ?? 0);
    if (!empty($ctx['chat']['private']) || $owner <= 0 || !cm_is_staff($ctx['pdo'], $ctx['cfg'], $owner)) { return false; }
    cm_mark_ours($ctx['pdo'], $ctx['chat'], (int)$ctx['now']);
    return true;
}

// Все чаты сети: наши, кроме solo. $chatId (чат, откуда спросили) — в списке
// всегда; если он сам solo — только он.
function cm_net(PDO $pdo, int $chatId): array {
    $ids = [$chatId];
    foreach ($pdo->query('SELECT chat_id, settings FROM cm_chats')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $s = is_string($r['settings'] ?? null) ? json_decode($r['settings'], true) : null;
        if (!is_array($s) || ($s['maknemy'] ?? false) !== true) { continue; }
        if (($s['solo'] ?? false) === true) {
            if ((int)$r['chat_id'] === $chatId) { return [$chatId]; }
            continue;
        }
        $ids[] = (int)$r['chat_id'];
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

// Чаты, на которые действует команда в $chat: вся сеть для чата Макнеми,
// иначе только он сам (личка, чужой чат; solo — см. cm_net).
function cm_chat_scope(PDO $pdo, array $chat): array {
    $chatId = (int)$chat['chat_id'];
    if (!empty($chat['private']) || !cm_is_ours($chat)) { return [$chatId]; }
    return cm_net($pdo, $chatId);
}

function cm_scope(array $ctx): array {
    return cm_chat_scope($ctx['pdo'], $ctx['chat']);
}

// « во всех чатах Maknemy» — в ответах о том, что действует на всю сеть;
// в одиночном чате пусто.
function cm_net_word(array $ctx): string {
    return count(cm_scope($ctx)) > 1 ? cm_t('net_word') : '';
}

// Чат или список чатов → «-100…,-100…» для IN (…). Числа — сразу в строке:
// список переменной длины, а id — целые.
function cm_in($chats): string {
    $ids = array_map('intval', is_array($chats) ? $chats : [$chats]);
    return $ids ? implode(',', $ids) : '0';
}

// --------------------------------------------------------------------------
//  Люди
// --------------------------------------------------------------------------

function cm_display_name(array $u): string {
    $name = trim((string)($u['first_name'] ?? '') . ' ' . (string)($u['last_name'] ?? ''));
    if ($name === '' && isset($u['title'])) { $name = trim((string)$u['title']); }
    if ($name === '' && !empty($u['username'])) { $name = '@' . $u['username']; }
    if ($name === '') { $name = 'id' . (int)($u['id'] ?? 0); }
    return mb_substr($name, 0, 64);
}

// Bot API не ищет людей по нику, поэтому ник → id помним сами.
function cm_user_touch(PDO $pdo, array $from, int $now): void {
    $id = (int)($from['id'] ?? 0);
    if ($id <= 0 || !empty($from['is_bot'])) { return; }
    $p = [':id' => $id, ':u' => strtolower(mb_substr((string)($from['username'] ?? ''), 0, 64)),
          ':nm' => cm_display_name($from), ':n' => $now];
    $up = $pdo->prepare('UPDATE cm_users SET username = :u, name = :nm, updated_at = :n WHERE user_id = :id');
    $up->execute($p);
    if ($up->rowCount() > 0) { return; }
    try {
        $pdo->prepare('INSERT INTO cm_users (user_id, username, name, updated_at) VALUES (:id, :u, :nm, :n)')->execute($p);
    } catch (PDOException $e) {
        // та же секунда и те же данные: строка уже есть
    }
}

function cm_user_find(PDO $pdo, string $username): ?array {
    $st = $pdo->prepare('SELECT user_id, name FROM cm_users WHERE username = :u ORDER BY updated_at DESC LIMIT 1');
    $st->execute([':u' => strtolower(ltrim($username, '@'))]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['user_id' => (int)$r['user_id'], 'name' => (string)$r['name']] : null;
}

function cm_user_name(PDO $pdo, int $id): string {
    $st = $pdo->prepare('SELECT name FROM cm_users WHERE user_id = :id');
    $st->execute([':id' => $id]);
    $n = $st->fetchColumn();
    return is_string($n) && $n !== '' ? $n : 'id' . $id;
}

// --------------------------------------------------------------------------
//  Участники чата
// --------------------------------------------------------------------------

const CM_MEMBER_FIELDS = ['lvl', 'joined_at', 'last_seen', 'greeted_at', 'left_at'];

function cm_member(PDO $pdo, int $chatId, int $userId): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_members WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return null; }
    foreach (array_merge(['chat_id', 'user_id'], CM_MEMBER_FIELDS) as $k) { $r[$k] = (int)$r[$k]; }
    return $r;
}

// Поменять поля участника; строки нет — завести с нулями в остальных.
function cm_member_set(PDO $pdo, int $chatId, int $userId, array $fields): void {
    $fields = array_intersect_key($fields, array_flip(CM_MEMBER_FIELDS));
    if (!$fields) { return; }
    $set = [];
    $p = [':c' => $chatId, ':u' => $userId];
    foreach ($fields as $k => $v) {
        $set[] = "$k = :$k";
        $p[":$k"] = (int)$v;
    }
    $up = $pdo->prepare('UPDATE cm_members SET ' . implode(', ', $set) . ' WHERE chat_id = :c AND user_id = :u');
    $up->execute($p);
    if ($up->rowCount() > 0 || cm_member($pdo, $chatId, $userId) !== null) { return; }
    $row = array_merge(array_fill_keys(CM_MEMBER_FIELDS, 0), $fields);
    $ins = [':c' => $chatId, ':u' => $userId];
    foreach ($row as $k => $v) { $ins[":$k"] = (int)$v; }
    try {
        $pdo->prepare('INSERT INTO cm_members (chat_id, user_id, lvl, joined_at, last_seen, greeted_at, left_at)
                       VALUES (:c, :u, :lvl, :joined_at, :last_seen, :greeted_at, :left_at)')->execute($ins);
    } catch (PDOException $e) {
        // параллельная вставка
    }
}

function cm_member_touch(PDO $pdo, int $chatId, int $userId, int $now, bool $joined = false): void {
    $f = ['last_seen' => $now, 'left_at' => 0];
    if ($joined) { $f['joined_at'] = $now; }
    cm_member_set($pdo, $chatId, $userId, $f);
}

// --------------------------------------------------------------------------
//  Сообщения (48 часов)
// --------------------------------------------------------------------------

function cm_text_hash(string $text): string {
    $t = trim(mb_strtolower($text));
    return $t === '' ? '' : substr(sha1($t), 0, 16);
}

function cm_msg_log(PDO $pdo, int $chatId, int $msgId, int $userId, string $text, int $now): void {
    try {
        $pdo->prepare('INSERT INTO cm_msgs (chat_id, message_id, user_id, text_hash, at) VALUES (:c, :m, :u, :h, :a)')
            ->execute([':c' => $chatId, ':m' => $msgId, ':u' => $userId, ':h' => cm_text_hash($text), ':a' => $now]);
    } catch (PDOException $e) {
        // повтор апдейта от Telegram
    }
}

// --------------------------------------------------------------------------
//  Группа стала супергруппой: у неё новый id
// --------------------------------------------------------------------------
// Telegram шлёт migrate_to_chat_id в старый чат и migrate_from_chat_id в
// новый; второй вызов ничего не находит. Строки нового id, успевшие появиться
// раньше переноса, уступают место старым.

const CM_CHAT_TABLES_PK   = ['cm_members', 'cm_msgs', 'cm_words', 'cm_notes', 'cm_queue'];
const CM_CHAT_TABLES_ROWS = ['cm_punishments', 'cm_rank_log', 'cm_reports', 'cm_timers'];
// Таблицы этапа 2 переезжают, только если его миграция уже выполнена.
const CM_CHAT_TABLES2_PK   = ['cm_stats', 'cm_rep'];
const CM_CHAT_TABLES2_ROWS = ['cm_rep_log', 'cm_awards'];
// Таблицы этапа 3 — так же, если выполнена его миграция. У cm_clans имя
// уникально в чате, поэтому она уступает место, как таблицы с ключом по чату.
const CM_CHAT_TABLES3_PK   = ['cm_game_stats', 'cm_clans', 'cm_clan_members'];
const CM_CHAT_TABLES3_ROWS = ['cm_offers', 'cm_games', 'cm_couples', 'cm_marriages'];

function cm_migrate(PDO $pdo, int $old, int $new): void {
    if ($old === 0 || $new === 0 || $old === $new || cm_chat_row($pdo, $old) === null) { return; }
    $pk = CM_CHAT_TABLES_PK;
    $rows = CM_CHAT_TABLES_ROWS;
    if (cm_ready2($pdo)) {
        $pk = array_merge($pk, CM_CHAT_TABLES2_PK);
        $rows = array_merge($rows, CM_CHAT_TABLES2_ROWS);
    }
    if (cm_ready3($pdo)) {
        $pk = array_merge($pk, CM_CHAT_TABLES3_PK);
        $rows = array_merge($rows, CM_CHAT_TABLES3_ROWS);
    }
    $pdo->beginTransaction();
    try {
        foreach (array_merge(['cm_chats'], $pk) as $t) {
            $pdo->prepare("DELETE FROM $t WHERE chat_id = :n")->execute([':n' => $new]);
        }
        foreach (array_merge(['cm_chats'], $pk, $rows) as $t) {
            $pdo->prepare("UPDATE $t SET chat_id = :n WHERE chat_id = :o")->execute([':n' => $new, ':o' => $old]);
        }
        $pdo->prepare('UPDATE cm_chats SET report_chat_id = :n WHERE report_chat_id = :o')->execute([':n' => $new, ':o' => $old]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}
