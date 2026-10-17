<?php
// Что человек хочет знать о розыгрышах — блок «Подписаться на обновления» и
// «Настройки уведомлений» на /giveaways (js/gw-notify.js), запросы идут через
// api/tg_link.php ({"action":"gw_prefs"}).
//
//   subscribed  — писать ли о новых розыгрышах и напоминать ли о конце.
//                 Выключил — бот о розыгрышах молчит, кроме того, что касается
//                 его самого: «вы участвуете», «участие снято», победа;
//   time_left   — напомнить, когда до итогов останется столько секунд (0 — нет);
//   places_left — напомнить, когда до лимита участников останется столько мест
//                 (0 — нет).
//
// Строки нет — человек ничего не менял: подписан, без напоминаний. Так
// рассылка о старте по-прежнему уходит всем подключившим бота, пока они сами
// не отпишутся. Сами напоминания шлёт api/lib/giveaway.php (gw_remind_*).
//
// Нет таблицы (миграция 2026-10-18-giveaway-notify.sql не выполнена) —
// gw_prefs_get() отвечает null, и страница прячет настройки.
//
// Отдельный файл, а не giveaway.php: его подключает tg_link.php, которому
// незачем тянуть весь розыгрыш.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

const GW_TIME_LEFT_MIN   = 300;
const GW_TIME_LEFT_MAX   = 90 * 86400;
const GW_PLACES_LEFT_MAX = 100000;

function gw_prefs_default(): array {
    return ['subscribed' => true, 'time_left' => 0, 'places_left' => 0];
}

function gw_prefs_get(PDO $pdo, string $me): ?array {
    try {
        $st = $pdo->prepare('SELECT subscribed, time_left, places_left FROM gw_prefs WHERE user_id = :u');
        $st->execute([':u' => $me]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$row) { return gw_prefs_default(); }
    return [
        'subscribed'  => (int)$row['subscribed'] === 1,
        'time_left'   => (int)$row['time_left'],
        'places_left' => (int)$row['places_left'],
    ];
}

// Число из запроса: только настоящее целое в своих границах, 0 — «выключено».
function gw_prefs_int($v, int $min, int $max): ?int {
    if (!is_int($v)) { return null; }
    if ($v === 0) { return 0; }
    return $v >= $min && $v <= $max ? $v : null;
}

/**
 * {"subscribed": true, "time_left": 3600, "places_left": 10} — любой набор
 * ключей, не пришедший остаётся как был. Кривое значение не сохраняет ничего
 * (bad_prefs): «полчаса» строкой не должны молча выключить напоминание.
 */
function gw_prefs_set(PDO $pdo, string $me, array $body, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    $prefs = gw_prefs_get($pdo, $me);
    if ($prefs === null) { return [503, ['ok' => false, 'error' => 'not_ready']]; }

    if (array_key_exists('subscribed', $body)) {
        if (!is_bool($body['subscribed'])) { return [400, ['ok' => false, 'error' => 'bad_prefs']]; }
        $prefs['subscribed'] = $body['subscribed'];
    }
    if (array_key_exists('time_left', $body)) {
        $v = gw_prefs_int($body['time_left'], GW_TIME_LEFT_MIN, GW_TIME_LEFT_MAX);
        if ($v === null) { return [400, ['ok' => false, 'error' => 'bad_prefs']]; }
        $prefs['time_left'] = $v;
    }
    if (array_key_exists('places_left', $body)) {
        $v = gw_prefs_int($body['places_left'], 1, GW_PLACES_LEFT_MAX);
        if ($v === null) { return [400, ['ok' => false, 'error' => 'bad_prefs']]; }
        $prefs['places_left'] = $v;
    }

    $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = :u');
    $st->execute([':u' => $me]);
    if ($st->fetchColumn() === false) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $args = [
        ':u' => $me,
        ':s' => $prefs['subscribed'] ? 1 : 0,
        ':t' => $prefs['time_left'],
        ':p' => $prefs['places_left'],
        ':at' => $now,
    ];
    $has = $pdo->prepare('SELECT 1 FROM gw_prefs WHERE user_id = :u');
    $has->execute([':u' => $me]);
    if ($has->fetchColumn() === false) {
        try {
            $pdo->prepare('INSERT INTO gw_prefs (user_id, subscribed, time_left, places_left, updated_at) VALUES (:u, :s, :t, :p, :at)')
                ->execute($args);
            return [200, ['ok' => true, 'gw' => $prefs]];
        } catch (PDOException $e) {
            // Второе сохранение из другой вкладки вставило строку первым —
            // дальше обычное обновление.
        }
    }
    $pdo->prepare('UPDATE gw_prefs SET subscribed = :s, time_left = :t, places_left = :p, updated_at = :at WHERE user_id = :u')
        ->execute($args);
    return [200, ['ok' => true, 'gw' => $prefs]];
}
