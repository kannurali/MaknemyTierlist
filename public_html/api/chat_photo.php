<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Файл фото из чата — GET /api/chat_photo.php?id=N[&t=1] (t=1 — миниатюра).
//
// Единственная дорога к файлам: каталог лежит выше веб-корня. Всем, кому нельзя,
// один и тот же 404 — по ответу нельзя узнать, существует ли фото.
//
// Кеш только в браузере. LiteSpeed кладёт к себе лишь то, что PHP пометил сам
// (lscache_public), но запрет стоит и здесь: общий кеш по этому адресу отдал бы
// фото кому угодно, не спрашивая PHP.

if (!defined('TESTING')) {
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Content-Type-Options: nosniff');

    $raw = $_GET['id'] ?? '';
    $id  = is_string($raw) && $raw !== '' && strlen($raw) <= 18 && ctype_digit($raw) ? (int)$raw : 0;

    $path = null;
    if ($id > 0 && resume_site_session()) {
        $me  = chat_me($_SESSION);
        $mod = $me !== '' && is_moderator();
        // Сессия PHP держит блокировку: пока файл уходит на медленный телефон,
        // остальные запросы этого же посетителя стояли бы в очереди.
        session_write_close();
        $path = chat_photo_path(db(), $me, $mod, $id, ($_GET['t'] ?? '') === '1',
                                chat_photo_dir(app_config()), time());
    }

    header_remove('Pragma');
    header_remove('Expires');
    if ($path === null) {
        header('Cache-Control: no-store');
        http_response_code(404);
        exit;
    }
    header('Cache-Control: private, max-age=3600');
    header('Content-Type: ' . chat_photo_mime($path));
    header('Content-Length: ' . (string)filesize($path));
    header('Content-Disposition: inline');
    readfile($path);
}
