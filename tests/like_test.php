<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/like.php';

test('increment adds one', function () {
    $pdo = test_db();
    [$status, $p] = handle_like($pdo, ['dir' => 1]);
    assert_eq(200, $status, 'ok status');
    assert_eq(1, $p['likes'], 'count 1');
});

test('decrement clamps at zero', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, ['dir' => -1]);
    assert_eq(0, $p['likes'], 'stays 0');
});

test('unknown dir defaults to +1', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, ['dir' => 99]);
    assert_eq(1, $p['likes'], 'defaults +1');
});

test('decrement after increment nets zero', function () {
    $pdo = test_db();
    handle_like($pdo, ['dir' => 1]);
    [, $p] = handle_like($pdo, ['dir' => -1]);
    assert_eq(0, $p['likes'], '1 then -1 = 0');
});

run_tests();
