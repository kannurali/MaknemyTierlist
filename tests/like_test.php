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

// --- rate limiter (per-IP cap wired in front of handle_like) ---------------

test('rate limit allows up to max then blocks', function () {
    $key = 'test-' . uniqid();
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) {
        assert_true(rate_limit_allow('t', $key, 3, 3600, $now), "hit $i allowed");
    }
    assert_eq(false, rate_limit_allow('t', $key, 3, 3600, $now), '4th blocked');
    @unlink(rate_file('t', $key));
});

test('rate limit window slides — old hits expire', function () {
    $key = 'test-' . uniqid();
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) { rate_limit_allow('t', $key, 3, 3600, $now); }
    assert_eq(false, rate_limit_allow('t', $key, 3, 3600, $now + 3599), 'still blocked inside window');
    assert_true(rate_limit_allow('t', $key, 3, 3600, $now + 3601), 'allowed after window passes');
    @unlink(rate_file('t', $key));
});

test('rate limit keys are independent', function () {
    $k1 = 'test-' . uniqid() . '-a';
    $k2 = 'test-' . uniqid() . '-b';
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) { rate_limit_allow('t', $k1, 3, 3600, $now); }
    assert_eq(false, rate_limit_allow('t', $k1, 3, 3600, $now), 'k1 exhausted');
    assert_true(rate_limit_allow('t', $k2, 3, 3600, $now), 'k2 unaffected');
    @unlink(rate_file('t', $k1));
    @unlink(rate_file('t', $k2));
});

test('rate limit survives corrupt state file', function () {
    $key = 'test-' . uniqid();
    file_put_contents(rate_file('t', $key), 'not json');
    assert_true(rate_limit_allow('t', $key, 3, 3600, 1000000), 'corrupt file = fresh start');
    @unlink(rate_file('t', $key));
});

run_tests();
