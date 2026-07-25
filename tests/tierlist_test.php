<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/tierlist.php';

test('returns parsed tierlist and likes', function () {
    $pdo = test_db();
    $blob = json_encode(['tiers' => [['name' => 'S', 'items' => []]], '_rev' => 42]);
    $stmt = $pdo->prepare("UPDATE tierlist SET data = :d, rev = 42 WHERE id = 1");
    $stmt->execute([':d' => $blob]);
    $pdo->exec("UPDATE likes SET count = 3 WHERE id = 1");
    [$status, $p] = handle_tierlist($pdo);
    assert_eq(200, $status, 'ok');
    assert_eq('S', $p['tierlist']['tiers'][0]['name'], 'parsed blob');
    assert_eq(3, $p['likes'], 'likes');
});

run_tests();
