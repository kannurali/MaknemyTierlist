<?php
// Чат-менеджер раз в минуту: таймеры и автоудаление ответов бота
// (api/lib/cm/cron.php). Cron в cPanel:
//
//   * * * * * /usr/local/bin/php /home/maknemyt/public_html/api/cm_cron.php >/dev/null 2>&1
//
// Только из командной строки. Из браузера — 404 (его же отдаёт .htaccess).
// Проверяется наличие веб-запроса, а не PHP_SAPI === 'cli': в cPanel
// /usr/local/bin/php бывает CGI-сборкой (как в api/stock_pull.php).
// Ключ -v — напечатать итог прохода.

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/telegram.php';
require_once __DIR__ . '/lib/cm/cm.php';

$lock = @fopen(sys_get_temp_dir() . '/maknemy-cm-cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$cfg = app_config();
$tg = tg_config($cfg);
$res = ['ok' => false];
if (tg_enabled($tg) && cm_enabled($cfg) && cm_ready(db())) {
    try {
        $res = ['ok' => true] + cm_cron_tick(db(), $cfg, tg_http($tg['token']), time());
    } catch (Throwable $e) {
        error_log('cm cron: ' . $e->getMessage());
    }
}
if (in_array('-v', $argv ?? [], true)) {
    echo json_encode($res, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
flock($lock, LOCK_UN);
