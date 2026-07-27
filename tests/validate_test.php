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

// validate_structure is what save.php runs BEFORE image extraction: it must
// judge shape only, never size, or an inline image would 400 a save that
// extraction is about to shrink.
test('validate_structure accepts an oversize state', function () {
    $big = ['tiers' => [['items' => [['icon' => 'data:image/png;base64,' . str_repeat('A', 800000)]]]]];
    $r = validate_structure($big);
    assert_true($r['ok'], 'no size cap in structure check');
});

test('validate_structure rejects missing tiers', function () {
    assert_eq(false, validate_structure(['nope' => 1])['ok'], 'no tiers -> invalid');
});

test('validate_structure rejects non-array', function () {
    assert_eq(false, validate_structure('a string')['ok'], 'string -> invalid');
});

run_tests();
