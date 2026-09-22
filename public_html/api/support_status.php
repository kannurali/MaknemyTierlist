<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/support.php';

// Отметка обращения из /admin/support — обычная HTML-форма (POST id, status),
// не fetch: страница администратора работает без скриптов, и после отметки
// её достаточно перезагрузить.

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $id     = isset($_POST['id']) && is_string($_POST['id']) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
    $status = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';
    support_set_status(db(), $id, $status);
    header('Cache-Control: no-store');
    header('Location: /admin/support', true, 303);
}
