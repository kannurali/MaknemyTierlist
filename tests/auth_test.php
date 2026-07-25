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

run_tests();
