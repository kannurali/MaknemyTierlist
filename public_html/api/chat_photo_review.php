<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Решение по жалобе — форма со страницы /admin/chat-photos: id, action
// (remove или keep). В ответ 303 обратно на страницу с итогом в ?done=.

if (!defined('TESTING')) {
    require_post();
    header('Cache-Control: no-store');
    require_moderator();

    $by  = is_string($_SESSION['user_id'] ?? null) ? $_SESSION['user_id'] : '';
    $raw = $_POST['id'] ?? '';
    $id  = is_string($raw) && $raw !== '' && strlen($raw) <= 18 && ctype_digit($raw) ? (int)$raw : 0;
    $act = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

    $done = chat_photo_review(db(), $by, $id, $act, chat_photo_dir(app_config()), time());
    header('Location: /admin/chat-photos?done=' . rawurlencode($done), true, 303);
}
