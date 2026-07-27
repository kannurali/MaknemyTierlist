<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/login.php';

test('correct password verifies', function () {
    $hash = password_hash('secret', PASSWORD_BCRYPT);
    assert_true(verify_admin_password('secret', $hash), 'match');
});

test('wrong password rejected', function () {
    $hash = password_hash('secret', PASSWORD_BCRYPT);
    assert_eq(false, verify_admin_password('nope', $hash), 'mismatch');
});

// --- login throttle -------------------------------------------------------
// Unique key per test run so parallel/rerun temp files never collide.
function thr_key(string $suffix): string {
    return 'test-' . bin2hex(random_bytes(4)) . '-' . $suffix;
}

test('fresh key is not throttled', function () {
    assert_eq(0, throttle_retry_after(thr_key('fresh'), 1000), 'no wait');
});

test('lock engages on the 5th failure', function () {
    $k = thr_key('lock');
    for ($i = 1; $i <= 4; $i++) {
        assert_eq($i, throttle_register_failure($k, 1000), "failure $i counted");
        assert_eq(0, throttle_retry_after($k, 1000), "still open after $i");
    }
    assert_eq(5, throttle_register_failure($k, 1000), '5th failure');
    assert_eq(300, throttle_retry_after($k, 1000), 'locked 300s');
    throttle_clear($k);
});

test('success clears the counter', function () {
    $k = thr_key('clear');
    throttle_register_failure($k, 1000);
    throttle_clear($k);
    assert_eq(0, throttle_retry_after($k, 1000), 'cleared');
});

test('expired lock resets the counter instead of instantly re-locking', function () {
    $k = thr_key('expire');
    for ($i = 1; $i <= 5; $i++) { throttle_register_failure($k, 1000); }
    // 1000 + 300 = lock until 1300; a failure after that starts over at 1.
    assert_eq(1, throttle_register_failure($k, 2000), 'counter reset');
    assert_eq(0, throttle_retry_after($k, 2000), 'not re-locked');
    throttle_clear($k);
});

run_tests();
