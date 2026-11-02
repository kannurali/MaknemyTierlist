<?php
// Догон переводов (tools/i18n_backfill.php): когда Google отказывает, скрипт
// ждёт и продолжает с той же записи, а не бросает работу. На бою
// 2026-10-20 бесплатный эндпоинт перестал отвечать серверу после пяти
// постов, и прежний скрипт остановился с просьбой «повторите позже».
// Run: php tests/i18n_backfill_test.php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../tools/i18n_backfill.php';

// Поддельный перевод записи: очередной ответ из списка $script по имени
// записи; последний ответ повторяется. $calls копит имена.
function bf_fill(array $script, array &$calls): callable {
    $seen = [];
    return function (string $name) use ($script, &$calls, &$seen): array {
        $calls[] = $name;
        $i = $seen[$name] = ($seen[$name] ?? -1) + 1;
        $list = $script[$name];
        $st = $list[min($i, count($list) - 1)];
        return ['filled' => $st[0], 'reused' => 0, 'failed' => $st[1]];
    };
}

function bf_targets(array $names): array {
    $out = [];
    foreach ($names as $n) { $out[$n] = $n; }
    return $out;
}

function bf_quiet(): callable { return function (string $line) {}; }

test('everything translated at once: no waiting, no probing', function () {
    $calls = [];
    $probes = 0;
    $slept = [];
    $res = backfill_run(bf_targets(['#1', '#2']), bf_fill(['#1' => [[8, 0]], '#2' => [[16, 0]]], $calls),
        function () use (&$probes) { $probes++; return true; },
        function (int $s) use (&$slept) { $slept[] = $s; }, bf_quiet(), 3600);
    assert_eq(['#1', '#2'], $calls);
    assert_eq([0, []], [$probes, $slept]);
    assert_eq(['filled' => 24, 'reused' => 0, 'failed' => 0, 'aborted' => false], $res);
});

test('Google refusing: wait longer each time, then carry on from the same post', function () {
    $calls = [];
    $slept = [];
    $res = backfill_run(bf_targets(['#7', '#8', '#9']), bf_fill([
        '#7' => [[54, 2], [2, 0]],
        '#8' => [[6, 90], [0, 90], [0, 90], [90, 0]],
        '#9' => [[40, 0]],
    ], $calls), function () { return false; }, function (int $s) use (&$slept) { $slept[] = $s; }, bf_quiet(), 6 * 3600);
    assert_eq(['#7', '#7', '#8', '#8', '#8', '#8', '#9'], $calls, 'each post is retried until Google answers');
    assert_eq([300, 300, 600, 1200], $slept, 'the wait grows while Google keeps refusing');
    assert_eq(0, $res['failed']);
    assert_eq(false, $res['aborted']);
});

test('the wait starts over once Google answers again', function () {
    $calls = [];
    $slept = [];
    backfill_run(bf_targets(['#1', '#2']), bf_fill([
        '#1' => [[0, 8], [0, 8], [8, 0]],
        '#2' => [[0, 8], [8, 0]],
    ], $calls), function () { return false; }, function (int $s) use (&$slept) { $slept[] = $s; }, bf_quiet(), 6 * 3600);
    assert_eq([300, 600, 300], $slept);
});

test('a text Google cannot translate while it answers is not waited on forever', function () {
    $calls = [];
    $slept = [];
    $probes = 0;
    $res = backfill_run(bf_targets(['#5', '#6']), bf_fill(['#5' => [[0, 2]], '#6' => [[8, 0]]], $calls),
        function () use (&$probes) { $probes++; return true; },
        function (int $s) use (&$slept) { $slept[] = $s; }, bf_quiet(), 6 * 3600);
    assert_eq(['#5', '#5', '#6'], $calls, 'one more try, then the next post');
    assert_eq([], $slept);
    assert_eq(2, $res['failed'], 'what is left after the last try');
    assert_eq(false, $res['aborted']);
});

test('the run stops once the total wait would pass the limit', function () {
    $calls = [];
    $slept = [];
    $lines = [];
    $res = backfill_run(bf_targets(['#1', '#2']), bf_fill(['#1' => [[0, 8]], '#2' => [[8, 0]]], $calls),
        function () { return false; }, function (int $s) use (&$slept) { $slept[] = $s; },
        function (string $l) use (&$lines) { $lines[] = $l; }, 1000);
    assert_eq([300, 600], $slept, '300 + 600 fit into 1000 s, the next 1200 does not');
    assert_eq(['#1', '#1', '#1'], $calls);
    assert_eq(true, $res['aborted']);
    assert_eq(8, $res['failed']);
    assert_true(strpos(end($lines), 'повторите') !== false, 'the log says to rerun: ' . end($lines));
});

test('the waits are 5, 10, 20, 30 minutes, then an hour each', function () {
    assert_eq([300, 600, 1200, 1800, 3600, 3600], [
        backfill_wait(0), backfill_wait(1), backfill_wait(2), backfill_wait(3), backfill_wait(4), backfill_wait(9),
    ]);
});

run_tests();
