<?php
// Начало входа через Roblox: сюда ведёт кнопка «Войти» в шапке. Эндпоинт
// ничего не показывает — кладёт в сессию state и PKCE-verifier и уводит
// браузер на страницу согласия Roblox. Обратно человек вернётся уже в
// api/roblox_callback.php.
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/roblox_oauth.php';

if (!defined('TESTING')) {
    // Только GET: это переход по ссылке из шапки. POST сюда не приходит
    // ниоткуда, и разрешать его — лишняя поверхность.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_out(['error' => 'method_not_allowed'], 405);
        exit;
    }

    $cfg   = app_config();
    $oauth = roblox_oauth_config($cfg);
    if ($oauth === []) {
        // Приложение в Roblox ещё не заведено (нет client_id/secret в
        // config.php). Отвечаем 503, а не 500: это не поломка, а выключенная
        // возможность — см. README, раздел «Вход через Roblox».
        json_out(['error' => 'roblox_login_disabled'], 503);
        exit;
    }

    // Частота, а не неудачи: каждый заход сюда создаёт запись в сессии и
    // отправляет человека на Roblox. Потолок нарочно щедрый — за одним IP у
    // операторов мобильной связи и школьных сетей сидят сотни игроков, и
    // лимит уровня «десяток в час» отрезал бы их всех. 60 в час душит только
    // скрипт, дёргающий вход по кругу.
    $key = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!rate_limit_allow('roblox_start', $key, 60, 3600, time())) {
        json_out(['error' => 'rate_limited'], 429);
        exit;
    }

    start_site_session();

    $state    = roblox_random_token();
    $verifier = roblox_random_token();

    // Пара state+verifier живёт в серверной сессии, а не в куке: значение,
    // лежащее у клиента, атакующий подменил бы вместе с параметром в адресе,
    // и проверка на возврате перестала бы что-либо значить.
    $_SESSION['roblox_oauth'] = [
        'state'    => $state,
        'verifier' => $verifier,
        'return'   => roblox_safe_return($_GET['return'] ?? '/'),
        'at'       => time(),
    ];

    header('Cache-Control: no-store');
    header('Location: ' . roblox_authorize_url($oauth, $state, $verifier), true, 302);
    exit;
}
