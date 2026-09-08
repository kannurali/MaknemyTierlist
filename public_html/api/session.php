<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/roblox_oauth.php';

/**
 * Кто сейчас на сайте. Одна ручка на всё состояние входа:
 *   admin  — админская сессия (пароль, api/login.php);
 *   user   — вошедший через Roblox или null;
 *   roblox — заведено ли вообще приложение в Roblox. По нему шапка решает,
 *            показывать кнопку входа или оставить прежнюю заглушку: пока
 *            client_id не прописан, кнопка вела бы на 503.
 *
 * База открывается через $pdo() и только когда в сессии есть вошедший.
 * Ручку теперь дёргает шапка на каждой странице сайта, а не одна админка,
 * и платить соединением с MySQL за анонимного посетителя незачем.
 */
function handle_session(callable $pdo, array $session, array $cfg): array {
    $user = null;
    $uid  = (string)($session['user_id'] ?? '');
    if ($uid !== '') {
        try {
            $user = roblox_load_user($pdo(), $uid);
        } catch (PDOException $e) {
            // Таблицы users нет (не выполнен schema.sql) — считаем, что никто
            // не вошёл. Сайт от этого не ломается, шапка просто покажет
            // кнопку входа.
            $user = null;
        }
    }
    return [
        'admin'  => !empty($session['admin']),
        'user'   => $user,
        'roblox' => roblox_oauth_enabled($cfg),
    ];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    start_site_session();
    json_out(handle_session('db', $_SESSION, app_config()), 200);
}
