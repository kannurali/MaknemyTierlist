<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

test('read_json_body parses injected raw body', function () {
    $GLOBALS['__RAW_BODY__'] = '{"dir":-1}';
    assert_eq(['dir' => -1], read_json_body(), 'parses JSON');
});

test('read_json_body returns [] on garbage', function () {
    $GLOBALS['__RAW_BODY__'] = 'not json';
    assert_eq([], read_json_body(), 'garbage -> []');
});

test('is_admin false without session flag', function () {
    $_SESSION = [];
    assert_eq(false, is_admin(), 'no flag -> not admin');
});

run_tests();
