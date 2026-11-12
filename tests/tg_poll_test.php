<?php
define('TESTING', 1);
// Своя пустая база в памяти: tg_update_process() берёт db() и app_config()
// лениво, как вебхук. Таблиц нет — модули бота видят «до миграции» и молчат,
// поэтому в сеть тест не ходит.
$GLOBALS['__tp_dir'] = sys_get_temp_dir() . '/tg-poll-test-' . getmypid();
@mkdir($GLOBALS['__tp_dir']);
file_put_contents($GLOBALS['__tp_dir'] . '/config.php', '<?php return ' . var_export([
    'dsn' => 'sqlite::memory:', 'db_user' => null, 'db_pass' => null,
    'tg_bot_token' => '123456789:AAHfakeTokenForTestsOnly_abcdefghij', 'tg_bot_name' => 'MaknemyBot',
], true) . ';');
define('CONFIG_PATH', $GLOBALS['__tp_dir'] . '/config.php');

require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/tg_webhook.php';

// Запасной путь бота — опрос getUpdates по cron (api/tg_poll.php), когда
// Telegram не может достучаться до вебхука. Обещания:
//  - обработка та же, что у вебхука, — одна функция tg_update_process();
//  - апдейты идут по порядку, ни один не обрабатывается дважды, а номер
//    следующего сохраняется до обработки — апдейт, роняющий процесс, не
//    стопорит бота;
//  - стоящий вебхук снимается без сброса очереди;
//  - запуск укладывается в минуту между запусками cron;
//  - из браузера скрипт недоступен.

$PUB = __DIR__ . '/../public_html';

// Подставной Telegram: отдаёт заготовленные ответы по очереди и записывает
// вызовы. Ожидание длинного опроса «проходит» на подставных часах.
function tp_api(array &$log, array $answers, int &$now): callable {
    return function (string $method, array $params, int $wait) use (&$log, &$answers, &$now): ?array {
        $log[] = [$method, $params];
        $a = $answers ? array_shift($answers) : ['ok' => true, 'result' => []];
        if ($method === 'getUpdates' && $a === ['ok' => true, 'result' => []]) { $now += $wait; }
        return $a;
    };
}

function tp_upd(int $id): array {
    return ['update_id' => $id, 'message' => ['message_id' => $id, 'chat' => ['id' => 5, 'type' => 'private'], 'text' => "m$id"]];
}

// База жива.
function tp_up(): callable {
    return function (): bool { return true; };
}

test('база пропала посреди пачки — запуск стоп, необработанное остаётся у Telegram', function () {
    $now = 1000; $log = []; $seen = []; $saved = [];
    $alive = [true, false];
    $res = tg_poll_run(
        tp_api($log, [['ok' => true, 'result' => [tp_upd(30), tp_upd(31), tp_upd(32)]]], $now),
        function (array $u) use (&$seen) { $seen[] = $u['update_id']; },
        function () { return 30; },
        function (int $o) use (&$saved) { $saved[] = $o; },
        function () use (&$now) { return $now; },
        function () use (&$alive) { return $alive ? array_shift($alive) : false; }
    );
    assert_eq([30], $seen, 'после сбоя ничего не обработано');
    assert_eq([31], $saved, 'номер стоит на первом необработанном');
    assert_eq(false, $res['ok']);
    assert_eq('db down', $res['error']);
    assert_eq(1, count($log), 'новых запросов к Telegram нет');

    // Следующий запуск — с сохранённого номера: 31 и 32 приходят снова.
    $log = []; $seen = [];
    tg_poll_run(
        tp_api($log, [['ok' => true, 'result' => [tp_upd(31), tp_upd(32)]]], $now),
        function (array $u) use (&$seen) { $seen[] = $u['update_id']; },
        function () use (&$saved) { return end($saved); },
        function (int $o) {},
        function () use (&$now) { return $now; },
        tp_up()
    );
    assert_eq(31, $log[0][1]['offset']);
    assert_eq([31, 32], $seen);
});

// --------------------------------------------------------------------------
//  Цикл опроса
// --------------------------------------------------------------------------

test('апдейты обрабатываются по порядку, номер сохраняется до обработки', function () {
    $now = 1000; $log = []; $events = []; $stored = 0;
    $res = tg_poll_run(
        tp_api($log, [['ok' => true, 'result' => [tp_upd(10), tp_upd(11)]], ['ok' => true, 'result' => [tp_upd(12)]]], $now),
        function (array $u) use (&$events) { $events[] = 'process ' . $u['update_id']; },
        function () { return 0; },
        function (int $o) use (&$events, &$stored) { $events[] = "save $o"; $stored = $o; },
        function () use (&$now) { return $now; }, tp_up()
    );
    assert_eq(['save 11', 'process 10', 'save 12', 'process 11', 'save 13', 'process 12'], $events);
    assert_eq(true, $res['ok']);
    assert_eq(3, $res['updates']);
    assert_eq(13, $stored);
    assert_eq(0, $log[0][1]['offset'], 'первый запрос — с сохранённого номера');
    assert_eq(12, $log[1][1]['offset'], 'второй подтверждает первую пачку');
    assert_eq(13, $log[2][1]['offset']);
    assert_eq(TG_POLL_UPDATES, $log[0][1]['allowed_updates']);
});

test('уже обработанный апдейт второй раз не идёт', function () {
    $now = 1000; $log = []; $seen = [];
    tg_poll_run(
        tp_api($log, [['ok' => true, 'result' => [tp_upd(20), tp_upd(21)]]], $now),
        function (array $u) use (&$seen) { $seen[] = $u['update_id']; },
        function () { return 21; },
        function (int $o) {},
        function () use (&$now) { return $now; }, tp_up()
    );
    assert_eq([21], $seen);
    assert_eq(21, $log[0][1]['offset']);
});

test('запуск укладывается в бюджет и не ждёт дольше TG_POLL_WAIT', function () {
    $now = 1000; $log = [];
    $res = tg_poll_run(tp_api($log, [], $now), function (array $u) {}, function () { return 7; },
        function (int $o) {}, function () use (&$now) { return $now; }, tp_up());
    assert_eq(true, $res['ok']);
    assert_true($now - 1000 <= TG_POLL_BUDGET, 'вышел до следующего запуска cron: ' . ($now - 1000));
    foreach ($log as $c) {
        assert_true($c[1]['timeout'] >= 0 && $c[1]['timeout'] <= TG_POLL_WAIT, 'ожидание ' . $c[1]['timeout']);
    }
    assert_true(count($log) >= 2, 'опрос продолжается, пока есть время');
});

test('стоящий вебхук снимается без сброса очереди, номер начинается заново', function () {
    $now = 1000; $log = []; $seen = []; $saved = [];
    $res = tg_poll_run(
        tp_api($log, [
            ['ok' => false, 'error_code' => 409, 'description' => "Conflict: can't use getUpdates method while webhook is active"],
            ['ok' => true],
            ['ok' => true, 'result' => [tp_upd(5)]],
        ], $now),
        function (array $u) use (&$seen) { $seen[] = $u['update_id']; },
        function () { return 999999; },
        function (int $o) use (&$saved) { $saved[] = $o; },
        function () use (&$now) { return $now; }, tp_up()
    );
    assert_eq('deleteWebhook', $log[1][0]);
    assert_eq(['drop_pending_updates' => false], $log[1][1]);
    assert_eq(0, $log[2][1]['offset'], 'старый номер забыт');
    assert_eq([5], $seen);
    assert_eq([0, 6], $saved);
    assert_eq(true, $res['takeover']);
    assert_eq(true, $res['ok']);
});

test('второй 409 подряд — ошибка, без бесконечного цикла', function () {
    $now = 1000; $log = [];
    $c409 = ['ok' => false, 'error_code' => 409, 'description' => 'Conflict'];
    $res = tg_poll_run(tp_api($log, [$c409, ['ok' => true], $c409], $now), function (array $u) {},
        function () { return 0; }, function (int $o) {}, function () use (&$now) { return $now; }, tp_up());
    assert_eq(false, $res['ok']);
    assert_eq('Conflict', $res['error']);
    assert_eq(3, count($log));
});

test('нет ответа от Telegram — запуск заканчивается, номер на месте', function () {
    $now = 1000; $log = [];
    $api = function (string $m, array $p, int $w) use (&$log) { $log[] = $m; return null; };
    $res = tg_poll_run($api, function (array $u) {}, function () { return 42; }, function (int $o) {},
        function () use (&$now) { return $now; }, tp_up());
    assert_eq(['ok' => false, 'error' => 'no answer', 'updates' => 0, 'offset' => 42, 'takeover' => false], $res);
    assert_eq(['getUpdates'], $log);
});

// --------------------------------------------------------------------------
//  Ответ на апдейт и номер в файле
// --------------------------------------------------------------------------

test('ответ, который вебхук отдал бы телом, уходит вызовом API', function () {
    $log = [];
    $send = function (string $m, array $p) use (&$log) { $log[] = [$m, $p]; return ['ok' => true]; };
    $r = tg_poll_responder($send);
    $r(['method' => 'sendMessage', 'chat_id' => 5, 'text' => 'привет'], false);
    $r(['method' => 'answerCallbackQuery', 'callback_query_id' => 'q1', 'text' => 'ок', 'show_alert' => true], true);
    $r(null, false);
    $r(['ok' => true], false);
    assert_eq([
        ['sendMessage', ['chat_id' => 5, 'text' => 'привет']],
        ['answerCallbackQuery', ['callback_query_id' => 'q1', 'text' => 'ок', 'show_alert' => true]],
    ], $log);
});

test('номер следующего апдейта переживает перезапуск, старый и мусорный — ноль', function () {
    $path = $GLOBALS['__tp_dir'] . '/tg-poll.offset';
    @unlink($path);
    assert_eq(0, tg_poll_offset_load($path, time()), 'файла нет');
    tg_poll_offset_save($path, 123456);
    assert_eq(123456, tg_poll_offset_load($path, time()));
    assert_eq(false, file_exists($path . '.tmp'), 'временный файл не остаётся');
    assert_eq(0, tg_poll_offset_load($path, time() + TG_POLL_OFFSET_TTL + 60), 'старше шести дней');
    file_put_contents($path, 'abc');
    assert_eq(0, tg_poll_offset_load($path, time()), 'мусор');
    @unlink($path);
});

test('опрос просит те же апдейты, что и вебхук', function () {
    $log = [];
    $rec = function (string $m, array $p) use (&$log) { $log[] = [$m, $p]; return ['ok' => true, 'result' => true]; };
    tg_setup_webhook($rec, '123456789:AAHfakeTokenForTestsOnly_abcdefghij');
    assert_eq('setWebhook', $log[0][0]);
    assert_eq($log[0][1]['allowed_updates'], TG_POLL_UPDATES);
});

// --------------------------------------------------------------------------
//  Общая обработка: порядок «ответить → отпустить → работать»
// --------------------------------------------------------------------------

function tp_process(array $update): array {
    $ev = [];
    tg_update_process(tg_config(app_config()), $update,
        function (?array $reply, bool $purge) use (&$ev) { $ev[] = ['respond', $reply === null ? null : ($reply['method'] ?? 'ok'), $purge]; },
        function () use (&$ev) { $ev[] = ['finish']; });
    return $ev;
}

test('сообщение группы: ответ и отпуск Telegram до работы чат-менеджера', function () {
    $ev = tp_process(['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => ['id' => -100, 'type' => 'supergroup'],
        'from' => ['id' => 7], 'text' => 'стата', 'date' => 1]]);
    assert_eq([['respond', null, false], ['finish']], $ev);
});

test('личное сообщение: ровно один ответ, без отпуска', function () {
    $ev = tp_process(['update_id' => 2, 'message' => ['message_id' => 2, 'chat' => ['id' => 7, 'type' => 'private'],
        'from' => ['id' => 7], 'text' => 'привет', 'date' => 1]]);
    assert_eq(1, count($ev));
    assert_eq('respond', $ev[0][0]);
    assert_eq(false, $ev[0][2]);
});

test('вход в канал: ответ, потом отпуск и чат-менеджер', function () {
    $ev = tp_process(['update_id' => 3, 'chat_member' => ['chat' => ['id' => -100, 'type' => 'channel'],
        'from' => ['id' => 7], 'date' => 1, 'old_chat_member' => ['status' => 'left', 'user' => ['id' => 7]],
        'new_chat_member' => ['status' => 'member', 'user' => ['id' => 7]]]]);
    assert_eq(['respond', 'finish'], array_column($ev, 0));
});

// --------------------------------------------------------------------------
//  Вебхук и cron-скрипт
// --------------------------------------------------------------------------

test('вебхук отдаёт обработку tg_update_process и сбрасывает кеш розыгрышей', function () use ($PUB) {
    $src = file_get_contents("$PUB/api/tg_webhook.php");
    assert_true(strpos($src, 'tg_update_process($tg, read_json_body(),') !== false, 'одна обработка');
    assert_true(strpos($src, "header('X-LiteSpeed-Purge: tag=giveaways')") !== false, 'сброс кеша');
    assert_true(strpos($src, "'tg_finish_response'") !== false, 'отпуск Telegram');
    assert_true(strpos($src, 'json_out($reply ?? [\'ok\' => true])') !== false, 'ответ телом');
});

test('tg_poll.php — только cron: из браузера 404', function () use ($PUB) {
    $src = file_get_contents("$PUB/api/tg_poll.php");
    $guard = strpos($src, "if (isset(\$_SERVER['REQUEST_METHOD'])) {");
    assert_true($guard !== false && $guard < strpos($src, 'require_once'), 'проверка до подключения кода');
    assert_true(strpos($src, 'flock($lock, LOCK_EX | LOCK_NB)') !== false, 'один запуск за раз');
    $ht = file_get_contents("$PUB/.htaccess");
    assert_true(strpos($ht, 'RewriteRule ^api/tg_poll\.php$ - [R=404,L]') !== false, '.htaccess');
});

run_tests();
