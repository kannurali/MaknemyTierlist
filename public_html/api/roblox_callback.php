<?php
// Возврат с Roblox. Этот адрес прописан в настройках приложения на
// create.roblox.com как Redirect URI и должен совпадать с ним посимвольно —
// Roblox сверяет строку целиком и на несовпадении не отдаёт код вовсе.
//
// Здесь заканчивается вход: сверяем state, меняем code на токен, забираем
// профиль, запоминаем пользователя и возвращаем его на ту страницу, с
// которой он нажал «Войти».
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/roblox_oauth.php';

/** Единственный выход из этого файла: увести браузер обратно на сайт. */
function roblox_finish(string $path): void {
    header('Cache-Control: no-store');
    header('Location: ' . $path, true, 302);
    exit;
}

if (!defined('TESTING')) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_out(['error' => 'method_not_allowed'], 405);
        exit;
    }

    $cfg   = app_config();
    $oauth = roblox_oauth_config($cfg);
    if ($oauth === []) {
        json_out(['error' => 'roblox_login_disabled'], 503);
        exit;
    }

    start_site_session();

    $pending = $_SESSION['roblox_oauth'] ?? null;
    // Заявку гасим сразу, ещё до проверок: она одноразовая. Оставь её жить —
    // и перехваченный code можно было бы принести повторно, пока сессия
    // открыта, а неудачная попытка тянула бы за собой свой старый state.
    unset($_SESSION['roblox_oauth']);

    $return = roblox_safe_return($pending['return'] ?? '/');

    // Человек нажал «Отмена» на странице согласия Roblox — это не ошибка,
    // возвращаем молча.
    if (isset($_GET['error'])) {
        roblox_finish(roblox_with_flag($return, 'cancelled'));
    }

    $code  = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');

    // Заявки нет (прямой заход по ссылке, чужая вкладка, протухшая сессия)
    // либо state не тот. hash_equals, а не ===: сравнение секрета в
    // постоянное время, как и везде, где сверяют токен.
    $expected = (string)($pending['state'] ?? '');
    if ($code === '' || $expected === '' || !hash_equals($expected, $state)) {
        roblox_finish(roblox_with_flag($return, 'error'));
    }

    // 10 минут на весь вход. Дольше живущая заявка — это чаще всего вкладка,
    // забытая открытой на странице Roblox, и code к ней всё равно уже мёртв.
    if (time() - (int)($pending['at'] ?? 0) > 600) {
        roblox_finish(roblox_with_flag($return, 'expired'));
    }

    $token = roblox_exchange_code($oauth, $code, (string)($pending['verifier'] ?? ''));
    if ($token === null) {
        roblox_finish(roblox_with_flag($return, 'error'));
    }

    $profile = roblox_fetch_profile($token);
    if ($profile === null) {
        roblox_finish(roblox_with_flag($return, 'error'));
    }

    try {
        roblox_touch_user(db(), $profile, time());
    } catch (PDOException $e) {
        // Таблицы нет — не выполнен schema.sql. Не пускаем внутрь: сессия без
        // строки в базе рассыпалась бы на первом же запросе профиля.
        roblox_finish(roblox_with_flag($return, 'error'));
    }

    // Новый идентификатор сессии на смене прав: иначе id, выданный до входа
    // (а его мог подсунуть кто угодно ссылкой с ?PHPSESSID=), после входа
    // стал бы идентификатором уже авторизованного пользователя.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $profile['roblox_id'];

    roblox_finish(roblox_with_flag($return, 'ok'));
}
