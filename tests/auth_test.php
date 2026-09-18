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

// --- login.php: порядок шагов ----------------------------------------------
// Сам диспетчер юнит-тестом не вызвать, а порядок в нём и есть защита: общий
// потолок и блокировка по адресу — до bcrypt (иначе поток неверных паролей
// съедает единственное ядро), сессия — только после верного пароля (иначе
// файл сессии на каждый запрос), и никакого сна: пауза держала процесс PHP,
// а их на тарифе двадцать.
test('login.php checks the limits before bcrypt and starts a session only after it', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/login.php');
    $retry  = strpos($src, 'throttle_retry_after(');
    $global = strpos($src, "rate_limit_allow('login_checks'");
    $verify = strpos($src, 'verify_admin_password($password');
    $sess   = strpos($src, 'start_site_session();');
    assert_true($retry !== false && $global !== false && $verify !== false && $sess !== false, 'all steps present');
    assert_true($retry < $verify && $global < $verify, 'limits before the password check');
    assert_true($verify < $sess, 'session only after a correct password');
    assert_eq(false, strpos($src, 'usleep('), 'no sleeping while holding a PHP process');
    assert_eq(false, strpos($src, 'start_admin_session'), 'no session before the check');
});

run_tests();
