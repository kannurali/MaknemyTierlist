<?php
// Бан из панели: модератор или админ банит игрока кнопкой в чате или на
// /admin/bans, не трогая config.php.
//
// Баны из панели лежат в таблице user_bans (миграция
// docs/migrations/2026-10-10-user-bans.sql). Список banned_ids в config.php
// работает как раньше: site_bans() в _bootstrap.php складывает оба источника,
// и проверка бана на сайте по-прежнему одна — site_banned().
//
// Кого можно банить из панели:
//   — модератору — только обычных игроков;
//   — админу — игроков и модераторов.
// Админа из панели не забанит никто. Бан сильнее роли, и иначе модератор
// закрыл бы владельцу вход в панель, а вернуть его можно только с сервера.
// Себя забанить тоже нельзя.
//
// Бан из config.php панель показывает, но не снимает: снимают его там же,
// где поставили.
//
// Пока таблицы нет, баны из панели не действуют (ban_store_list отдаёт
// пусто), кнопки отвечают not_ready, а сайт работает по одному config.php.

// Сроки, из которых выбирают в панели. 0 — навсегда.
const BAN_TERMS = [
    '1d'      => 86400,
    '3d'      => 259200,
    '7d'      => 604800,
    '30d'     => 2592000,
    'forever' => 0,
];

const BAN_REASON_MAX = 200;

// Сколько игроков показывает поиск по нику.
const BAN_SEARCH_MAX = 30;

function ban_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM user_bans LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Действующие баны из панели: [roblox id => конец срока, 0 — навсегда].
 * Истёкших нет. Нет таблицы — пусто: сайт живёт по одному config.php.
 */
function ban_store_list(PDO $pdo, int $now): array {
    try {
        $st = $pdo->prepare('SELECT user_id, until_at FROM user_bans WHERE until_at = 0 OR until_at > ?');
        $st->execute([$now]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            $out[(string)$row[0]] = (int)$row[1];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/** Roblox id из недоверенного ввода: только цифры, без нулей впереди; иначе ''. */
function ban_target($raw): string {
    if (is_int($raw)) { $raw = (string)$raw; }
    if (!is_string($raw) || !preg_match('/^\d{1,20}\z/', $raw)) { return ''; }
    return ltrim($raw, '0');
}

/**
 * Может ли $by с ролью $byRole банить (и разбанивать) $target с ролью
 * $targetRole. Роли — из site_role(), то есть из config.php.
 */
function ban_allowed(string $byRole, string $by, string $target, string $targetRole): bool {
    if ($by === '' || $target === '' || $by === $target) { return false; }
    if ($byRole !== 'admin' && $byRole !== 'moderator') { return false; }
    if ($targetRole === 'admin') { return false; }
    if ($targetRole === 'moderator') { return $byRole === 'admin'; }
    return true;
}

/** Причина бана: одна строка, без управляющих символов, не длиннее предела. */
function ban_reason_clean($raw): string {
    if (!is_string($raw)) { return ''; }
    $text = preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $raw);
    if ($text === null) { return ''; }
    return trim(mb_substr(trim($text), 0, BAN_REASON_MAX));
}

function ban_user_exists(PDO $pdo, string $id): bool {
    try {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Состояние бана игрока глазами $by:
 *   banned — забанен ли сейчас (панель или config.php);
 *   until  — конец срока, 0 — навсегда, null — не забанен;
 *   config — бан стоит в config.php, и из панели его не снять;
 *   can    — может ли $by банить и разбанивать этого игрока.
 */
function ban_status(PDO $pdo, array $cfg, string $by, string $target, int $now): array {
    $until = site_ban_until($target, $cfg, $now, $pdo);
    return [
        'id'     => $target,
        'banned' => $until !== null,
        'until'  => $until,
        'config' => site_ban_until($target, $cfg, $now) !== null,
        'can'    => ban_allowed(site_role(['user_id' => $by], $cfg), $by, $target,
                                site_role(['user_id' => $target], $cfg)),
    ];
}

/** Общие проверки перед баном и разбаном. null — всё в порядке. */
function ban_check(PDO $pdo, array $cfg, string $by, string $target): ?array {
    $byRole = site_role(['user_id' => $by], $cfg);
    if ($byRole === '')         { return [401, ['ok' => false, 'error' => 'unauthorized']]; }
    if ($target === '')         { return [400, ['ok' => false, 'error' => 'bad_id']]; }
    if (!ban_ready($pdo))       { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!ban_user_exists($pdo, $target)) { return [404, ['ok' => false, 'error' => 'no_user']]; }
    if (!ban_allowed($byRole, $by, $target, site_role(['user_id' => $target], $cfg))) {
        return [403, ['ok' => false, 'error' => 'forbidden']];
    }
    return null;
}

/**
 * Забанить на срок из BAN_TERMS. Повторный бан того же игрока заменяет
 * прежний: новый срок, новая причина, новый автор.
 *
 * Ключи «запомнить вход» гаснут сразу. Сессию забаненного закроет его же
 * следующий запрос (start_site_session), так что ждать он не будет.
 */
function ban_set(PDO $pdo, array $cfg, string $by, $targetRaw, $term, $reason, int $now): array {
    $target = ban_target($targetRaw);
    $fail = ban_check($pdo, $cfg, $by, $target);
    if ($fail !== null) { return $fail; }
    if (!is_string($term) || !array_key_exists($term, BAN_TERMS)) {
        return [400, ['ok' => false, 'error' => 'bad_term']];
    }

    $until = BAN_TERMS[$term] === 0 ? 0 : $now + BAN_TERMS[$term];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM user_bans WHERE user_id = ?')->execute([$target]);
        $pdo->prepare('INSERT INTO user_bans (user_id, until_at, by_id, reason, created_at)
                       VALUES (:u, :until, :by, :reason, :at)')
            ->execute([':u' => $target, ':until' => $until, ':by' => $by,
                       ':reason' => ban_reason_clean($reason), ':at' => $now]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    try {
        remember_forget_user($pdo, $target);
    } catch (PDOException $e) {
        // Нет таблицы ключей — помнить вход и так негде.
    }
    return [200, ['ok' => true] + ban_status($pdo, $cfg, $by, $target, $now)];
}

/** Снять бан из панели. Бан из config.php остаётся: его снимают там. */
function ban_lift(PDO $pdo, array $cfg, string $by, $targetRaw, int $now): array {
    $target = ban_target($targetRaw);
    $fail = ban_check($pdo, $cfg, $by, $target);
    if ($fail !== null) { return $fail; }
    $pdo->prepare('DELETE FROM user_bans WHERE user_id = ?')->execute([$target]);
    return [200, ['ok' => true] + ban_status($pdo, $cfg, $by, $target, $now)];
}

/**
 * Запрос к /api/ban.php: GET — состояние, POST — бан или разбан.
 * $in — тело POST (JSON из чата или поля формы с /admin/bans).
 */
function handle_ban(PDO $pdo, array $cfg, string $me, string $method, array $get, array $in, int $now): array {
    if (site_role(['user_id' => $me], $cfg) === '') {
        return [401, ['ok' => false, 'error' => 'unauthorized']];
    }
    if ($method === 'GET') {
        $target = ban_target($get['id'] ?? '');
        if ($target === '') { return [400, ['ok' => false, 'error' => 'bad_id']]; }
        return [200, ['ok' => true, 'ready' => ban_ready($pdo)] + ban_status($pdo, $cfg, $me, $target, $now)];
    }
    if ($method !== 'POST') {
        return [405, ['ok' => false, 'error' => 'method_not_allowed']];
    }
    if (!empty($in['unban'])) {
        return ban_lift($pdo, $cfg, $me, $in['id'] ?? '', $now);
    }
    return ban_set($pdo, $cfg, $me, $in['id'] ?? '', $in['term'] ?? '', $in['reason'] ?? '', $now);
}

/** Ник для показа: имя в Roblox, если есть, иначе логин, иначе сам id. */
function ban_nick(array $row): string {
    $nick = trim((string)($row['display_name'] ?? ''));
    if ($nick === '') { $nick = (string)($row['username'] ?? ''); }
    return $nick !== '' ? $nick : (string)($row['roblox_id'] ?? '');
}

/** Игроки по id: [id => строка users]. */
function ban_users(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($id) {
        return $id !== '';
    })));
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT roblox_id, username, display_name, avatar_url FROM users WHERE roblox_id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) { $out[(string)$row['roblox_id']] = $row; }
    return $out;
}

/**
 * Поиск игрока по нику — по логину Roblox и по отображаемому имени, без
 * учёта регистра; строка из одних цифр ищется ещё и как id. Точные
 * совпадения сверху, дальше — кто заходил недавно.
 */
function ban_search(PDO $pdo, array $cfg, string $by, $rawQuery, int $now): array {
    $q = is_string($rawQuery) ? trim(preg_replace('/\s+/u', ' ', $rawQuery) ?? '') : '';
    $q = mb_substr($q, 0, 64);
    if ($q === '') { return []; }

    $like  = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $byId  = preg_match('/^\d{1,20}\z/', $q) === 1;
    $sql = "SELECT roblox_id, username, display_name, avatar_url FROM users
             WHERE username LIKE :l1 ESCAPE '!' OR display_name LIKE :l2 ESCAPE '!'"
         . ($byId ? ' OR roblox_id = :id' : '')
         . " ORDER BY CASE WHEN LOWER(username) = LOWER(:e1) OR LOWER(display_name) = LOWER(:e2) THEN 0 ELSE 1 END,
                   last_seen_at DESC, last_login_at DESC
             LIMIT " . BAN_SEARCH_MAX;
    $params = [':l1' => $like, ':l2' => $like, ':e1' => $q, ':e2' => $q];
    if ($byId) { $params[':id'] = ltrim($q, '0') !== '' ? ltrim($q, '0') : '0'; }
    $st = $pdo->prepare($sql);
    $st->execute($params);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string)$row['roblox_id'];
        $out[] = [
            'nick'   => ban_nick($row),
            'handle' => (string)$row['username'] !== '' ? '@' . $row['username'] : '',
            'avatar' => (string)$row['avatar_url'],
            'role'   => site_role(['user_id' => $id], $cfg),
        ] + ban_status($pdo, $cfg, $by, $id, $now);
    }
    return $out;
}

/**
 * Все действующие баны для /admin/bans: из панели — свежие сверху, с автором
 * и причиной; из config.php — следом, без автора.
 */
function ban_active(PDO $pdo, array $cfg, string $by, int $now): array {
    $rows = [];
    if (ban_ready($pdo)) {
        $st = $pdo->prepare('SELECT user_id, until_at, by_id, reason, created_at FROM user_bans
                              WHERE until_at = 0 OR until_at > ? ORDER BY created_at DESC, user_id');
        $st->execute([$now]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $fromConfig = site_bans($cfg, $now);
    $ids = array_merge(array_column($rows, 'user_id'), array_column($rows, 'by_id'),
                       array_map('strval', array_keys($fromConfig)));
    $who = ban_users($pdo, $ids);

    $out  = [];
    $seen = [];
    foreach ($rows as $r) {
        $id = (string)$r['user_id'];
        $byId = (string)$r['by_id'];
        $seen[$id] = true;
        $out[] = [
            'id'     => $id,
            'nick'   => isset($who[$id]) ? ban_nick($who[$id]) : $id,
            'until'  => (int)$r['until_at'],
            'by'     => $byId,
            'byNick' => isset($who[$byId]) ? ban_nick($who[$byId]) : $byId,
            'reason' => (string)$r['reason'],
            'at'     => (int)$r['created_at'],
            'config' => array_key_exists($id, $fromConfig),
            'can'    => ban_allowed(site_role(['user_id' => $by], $cfg), $by, $id,
                                    site_role(['user_id' => $id], $cfg)),
        ];
    }
    foreach ($fromConfig as $id => $until) {
        $id = (string)$id;
        if (isset($seen[$id])) { continue; }
        $out[] = [
            'id'     => $id,
            'nick'   => isset($who[$id]) ? ban_nick($who[$id]) : $id,
            'until'  => (int)$until,
            'by'     => '',
            'byNick' => '',
            'reason' => '',
            'at'     => 0,
            'config' => true,
            'can'    => false,
        ];
    }
    return $out;
}
