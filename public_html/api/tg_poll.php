<?php
// Запасной путь бота, когда Telegram не может достучаться до вебхука
// (фаервол хостинга, сбой сети): бот сам забирает апдейты через getUpdates.
// Обработка та же, что у вебхука, — tg_update_process() в
// api/lib/tg_update.php. Cron в cPanel:
//
//   * * * * * /usr/local/bin/php /home/maknemyt/public_html/api/tg_poll.php >/dev/null 2>&1
//
// Пока этот cron стоит, бот работает опросом: первый запуск сам снимает
// вебхук, очередь при этом сохраняется. Вернуться к вебхуку — убрать cron и
// нажать «Подключить вебхук» на /admin/support. Подробно —
// docs/telegram-notify.md.
//
// Только из командной строки. Из браузера — 404 (его же отдаёт .htaccess).
// Проверяется наличие веб-запроса, а не PHP_SAPI === 'cli': в cPanel
// /usr/local/bin/php бывает CGI-сборкой (как в api/stock_pull.php).
// Ключ -v — напечатать итог прохода.
//
// Два запуска одновременно не идут: Telegram отдаёт апдейты только одному
// опросу, второй получил бы 409.

if (isset($_SERVER['REQUEST_METHOD'])) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/tg_update.php';

$lock = @fopen(sys_get_temp_dir() . '/maknemy-tg-poll.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { exit(0); }
if (function_exists('set_time_limit')) { @set_time_limit(TG_POLL_BUDGET + 240); }

$tg = tg_config(app_config());
$res = ['ok' => false, 'error' => 'off'];
if (tg_enabled($tg)) {
    $send = tg_http($tg['token']);
    $respond = tg_poll_responder($send);
    $path = tg_poll_offset_path();
    $res = tg_poll_run(
        tg_poll_http($tg['token']),
        function (array $update) use ($tg, $respond): void {
            try {
                tg_update_process($tg, $update, $respond, function (): void {});
            } catch (Throwable $e) {
                error_log('tg poll: ' . $e->getMessage());
            }
        },
        function () use ($path): int { return tg_poll_offset_load($path, time()); },
        function (int $offset) use ($path): void { tg_poll_offset_save($path, $offset); },
        'time',
        function (): bool {
            try {
                db()->query('SELECT 1');
                return true;
            } catch (Throwable $e) {
                return false;
            }
        }
    );
    if (!empty($res['takeover'])) { error_log('tg poll: вебхук снят, бот работает опросом'); }
    if (empty($res['ok'])) { error_log('tg poll: ' . $res['error']); }
}
if (in_array('-v', $argv ?? [], true)) {
    echo json_encode($res, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
flock($lock, LOCK_UN);
