<?php
require_once __DIR__ . '/_bootstrap.php';

// Бан из панели — /api/ban.php (логика в api/lib/ban.php)
//
//   GET  ?id=N                              — забанен ли, до когда, можно ли
//   POST {"id":N,"term":"7d","reason":"…"}  — забанить (term — ключ BAN_TERMS)
//   POST {"id":N,"unban":true}              — снять бан из панели
//
// JSON шлёт кнопка в чате (js/chat-page.js). Страница /admin/bans работает без
// скриптов: те же поля приходят обычной формой, и в ответ — 303 обратно на
// неё с итогом в адресе (done=…). Чужой сайт такую форму не отправит: кука
// сессии SameSite=Lax на межсайтовый POST не идёт, и запрос придёт анонимным.
//
// Только модераторам и админам; кого из игроков можно банить — ban_allowed().

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') { require_post(); }
    require_moderator();

    $me   = is_string($_SESSION['user_id'] ?? null) ? $_SESSION['user_id'] : '';
    $form = $method === 'POST' && !empty($_POST);
    $in   = $form ? $_POST : ($method === 'POST' ? read_json_body() : []);
    [$status, $payload] = handle_ban(db(), app_config(), $me, $method, $_GET, $in, time());

    if ($form) {
        $q    = isset($in['q']) && is_string($in['q']) ? $in['q'] : '';
        $done = !empty($payload['ok']) ? (!empty($in['unban']) ? 'unbanned' : 'banned')
                                       : (string)($payload['error'] ?? 'error');
        $back = ['done' => $done];
        if ($q !== '') { $back['q'] = $q; }
        header('Location: /admin/bans?' . http_build_query($back), true, 303);
        exit;
    }
    json_out($payload, $status);
}
