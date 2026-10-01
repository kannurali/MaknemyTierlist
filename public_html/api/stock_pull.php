<?php
// Сбор стока из Discord — запускает cron в cPanel раз в минуту:
//
//   * * * * * /usr/local/bin/php /home/maknemyt/public_html/api/stock_pull.php >/dev/null 2>&1
//
// Только из командной строки. Из браузера — 404 (его же отдаёт и .htaccess):
// иначе любой мог бы гонять запросы к Discord от имени нашего бота и упереться
// в его лимиты. Проверяется наличие веб-запроса, а не PHP_SAPI === 'cli': в
// cPanel /usr/local/bin/php бывает CGI-сборкой, и из cron она запускается с
// PHP_SAPI = 'cgi-fcgi', но без REQUEST_METHOD.
//
// Два прохода одновременно не идут: если прошлый ещё ждёт Discord или
// Telegram (рассылка на сотни человек тянется дольше минуты), новый сразу
// выходит.

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/stock.php';

$lock = @fopen(sys_get_temp_dir() . '/maknemy-stock-pull.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }

$res = stock_pull_and_notify(db(), app_config(), time());
if (empty($res['ok']) && ($res['error'] ?? '') !== 'off') {
    error_log('stock pull: ' . ($res['error'] ?? 'unknown'));
}
if (in_array('-v', $argv ?? [], true)) {
    echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
}
flock($lock, LOCK_UN);
