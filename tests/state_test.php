<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/state.php';

test('returns rev and likes', function () {
    $pdo = test_db();
    $pdo->exec("UPDATE tierlist SET rev = 12345 WHERE id = 1");
    $pdo->exec("UPDATE likes SET count = 7 WHERE id = 1");
    [$status, $p] = handle_state($pdo);
    assert_eq(200, $status, 'ok');
    assert_eq(12345, $p['rev'], 'rev');
    assert_eq(7, $p['likes'], 'likes');
});

run_tests();
