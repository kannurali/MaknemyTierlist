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

test('reports the campaign revision alongside the tier list one', function () {
    // The client polls this every 30 s and refetches only what changed, so a
    // creative swap must not invalidate the immutable-cached tier list.
    $pdo = test_db();
    assert_eq(0, handle_state($pdo)[1]['promoRev'], 'zero before any campaign exists');

    $pdo->exec("INSERT INTO promo (id, data, rev) VALUES (1, '{}', 998877)");
    [$status, $p] = handle_state($pdo);
    assert_eq(200, $status, 'ok');
    assert_eq(998877, $p['promoRev'], 'promo rev');
    assert_eq(0, $p['rev'], 'tier list rev untouched');
});

test('a missing promo table reads as "no campaigns", not an error', function () {
    $bare = new PDO('sqlite::memory:');
    $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $bare->exec("CREATE TABLE tierlist (id INTEGER PRIMARY KEY, data TEXT NOT NULL, rev INTEGER NOT NULL)");
    $bare->exec("CREATE TABLE likes (id INTEGER PRIMARY KEY, count INTEGER NOT NULL DEFAULT 0)");
    $bare->exec("INSERT INTO tierlist (id, data, rev) VALUES (1, '{}', 5)");
    $bare->exec("INSERT INTO likes (id, count) VALUES (1, 0)");

    [$status, $p] = handle_state($bare);
    assert_eq(200, $status, 'still serves');
    assert_eq(0, $p['promoRev'], 'promoRev zero');
});

run_tests();
