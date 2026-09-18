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
 *   roblox_public — открыт ли вход всем. Пока нет, кнопку видят только
 *            пришедшие по ссылке с ?signin (см. roblox_login_public()).
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
        'admin'         => !empty($session['admin']),
        'user'          => $user,
        'roblox'        => roblox_oauth_enabled($cfg),
        'roblox_public' => roblox_login_public($cfg),
    ];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    // Анонимному посетителю сессия не заводится: раньше каждый первый заход
    // на любую страницу оставлял на сервере файл сессии и получал куку, хотя
    // хранить в ней было нечего. Сессию заводят вход через Roblox и вход в
    // админку.
    $session = resume_site_session() ? $_SESSION : [];
    json_out(handle_session('db', $session, app_config()), 200);
}
