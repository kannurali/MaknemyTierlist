<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/validate.php';

test('valid minimal state', function () {
    $r = validate_state(['tiers' => []]);
    assert_true($r['ok'], 'tiers array ok');
});

test('missing tiers rejected', function () {
    $r = validate_state(['nope' => 1]);
    assert_eq(false, $r['ok'], 'no tiers -> invalid');
});

test('non-array rejected', function () {
    $r = validate_state('a string');
    assert_eq(false, $r['ok'], 'string -> invalid');
});

test('oversize rejected', function () {
    $big = ['tiers' => [['items' => [['name' => str_repeat('x', 2000)]]]]];
    $r = validate_state($big, 100); // 100-byte cap
    assert_eq(false, $r['ok'], 'over cap -> invalid');
});

run_tests();
