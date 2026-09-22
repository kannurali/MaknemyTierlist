<?php
// Центр обращений: страница /support (Figma «центр обращений», нода 272:2454)
// и список для администратора на /admin/support.
//
// Писать может только вошедший через Roblox. Ответ приходит в личный чат
// сайта (/chat?to=<автор>), а у анонима ни чата, ни адреса нет — его
// обращение некуда было бы вернуть. Для тех, кто не хочет входить, на
// странице есть «написать лично» — ссылка на Telegram.

require_once __DIR__ . '/profile.php';

const SUPPORT_BODY_MIN = 10;
const SUPPORT_BODY_MAX = 1000;

// Сколько обращений отдаёт список администратора. Старые закрытые не нужны
// на одном экране, новые — всегда сверху.
const SUPPORT_LIST_MAX = 200;

function support_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM support_tickets LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Текст обращения: управляющие символы вон (кроме перевода строки — человек
 * описывает проблему по пунктам), пробелы по краям срезаны.
 */
function support_clean(string $raw): string {
    $text = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
    if ($text === null) { return ''; }
    $text = preg_replace('/\R{3,}/u', "\n\n", str_replace("\r\n", "\n", $text));
    return trim((string)$text);
}

function support_submit(PDO $pdo, string $me, $raw, int $now): array {
    if ($me === '')           { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!support_ready($pdo)) { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!profile_exists($pdo, $me)) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $body = support_clean(is_string($raw) ? $raw : '');
    $len  = mb_strlen($body);
    if ($len < SUPPORT_BODY_MIN) { return [400, ['ok' => false, 'error' => 'too_short', 'min' => SUPPORT_BODY_MIN]]; }
    if ($len > SUPPORT_BODY_MAX) { return [400, ['ok' => false, 'error' => 'too_long', 'max' => SUPPORT_BODY_MAX]]; }

    $pdo->prepare("INSERT INTO support_tickets (user_id, body, status, created_at) VALUES (:u, :b, 'new', :at)")
        ->execute([':u' => $me, ':b' => $body, ':at' => $now]);
    return [200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]];
}

/** Обращения для администратора: новые сверху, с ником автора. */
function support_list(PDO $pdo): array {
    if (!support_ready($pdo)) { return []; }
    $rows = $pdo->query("SELECT id, user_id, body, status, created_at FROM support_tickets
                          ORDER BY CASE WHEN status = 'new' THEN 0 ELSE 1 END, id DESC
                          LIMIT " . SUPPORT_LIST_MAX)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { return []; }

    $ids = array_values(array_unique(array_map('strval', array_column($rows, 'user_id'))));
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("SELECT roblox_id, username, display_name FROM users WHERE roblox_id IN ($in)");
    $st->execute($ids);
    $who = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $nick = trim((string)$u['display_name']);
        $who[(string)$u['roblox_id']] = [
            'nick'   => $nick !== '' ? $nick : (string)$u['username'],
            'handle' => (string)$u['username'] !== '' ? '@' . $u['username'] : '',
        ];
    }

    $out = [];
    foreach ($rows as $r) {
        $uid = (string)$r['user_id'];
        $out[] = [
            'id'     => (int)$r['id'],
            'user'   => $uid,
            'nick'   => $who[$uid]['nick'] ?? $uid,
            'handle' => $who[$uid]['handle'] ?? '',
            'body'   => (string)$r['body'],
            'status' => (string)$r['status'] === 'done' ? 'done' : 'new',
            'at'     => (int)$r['created_at'],
        ];
    }
    return $out;
}

/** Отметить обращение решённым (или вернуть в новые). */
function support_set_status(PDO $pdo, int $id, string $status): bool {
    if (!support_ready($pdo) || $id <= 0 || !in_array($status, ['new', 'done'], true)) { return false; }
    $st = $pdo->prepare('UPDATE support_tickets SET status = :s WHERE id = :id');
    $st->execute([':s' => $status, ':id' => $id]);
    return $st->rowCount() === 1;
}
