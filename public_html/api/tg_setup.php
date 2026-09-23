<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';

// Кнопка «Подключить вебхук» на /admin/support — обычная HTML-форма, как и
// отметка обращений. Сообщает Telegram адрес api/tg_webhook.php и секрет.
// Нужна один раз после того, как токен бота лёг в config.php, и после смены
// токена.

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $tg = tg_config(app_config());
    $ok = tg_enabled($tg) && tg_setup_webhook(tg_http($tg['token']), $tg['token']);
    header('Cache-Control: no-store');
    header('Location: /admin/support?tg=' . ($ok ? 'ok' : 'fail'), true, 303);
}
