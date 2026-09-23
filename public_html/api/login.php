<?php
require_once __DIR__ . '/_bootstrap.php';

// Входа в админку по паролю больше нет. Админы и модераторы входят через
// Roblox, права дают admin_ids и moderator_ids в config.php (site_role() в
// _bootstrap.php).
//
// Файл оставлен заглушкой, а не удалён: деплой на сервере ничего не удаляет
// (deploy_publish() в api/deploy.php), и без него там так и лежала бы прежняя
// версия, которая по-прежнему принимает пароль.

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    json_out(['ok' => false, 'error' => 'password_login_removed'], 410);
}
