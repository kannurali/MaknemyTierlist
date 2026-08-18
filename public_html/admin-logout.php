<?php
// Выход из админки. Форма, а не fetch: кнопка одна и та же на обеих панелях,
// а app.js грузится только на одной из них.
require_once __DIR__ . '/api/lib/admin_page.php';

// GET сюда прилететь может — префетч ссылки, сканер, чужая картинка. Сессию
// по GET не рвём.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /admin', true, 303);
    exit;
}

start_admin_session();
$_SESSION = [];
// session_destroy() чистит хранилище, но куку оставляет: браузер продолжит
// слать мёртвый идентификатор до закрытия вкладки. Гасим её явно.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

header('Cache-Control: no-store');
header('Location: /', true, 303);
