<?php
require __DIR__ . '/lib.php';

test('assert_eq passes on equal', function () {
    assert_eq(1, 1, 'ints equal');
});

test('test_db seeds singleton rows', function () {
    $pdo = test_db();
    assert_eq(0, (int)$pdo->query("SELECT count FROM likes WHERE id=1")->fetchColumn(), 'likes seeded 0');
    assert_eq('{}', $pdo->query("SELECT data FROM tierlist WHERE id=1")->fetchColumn(), 'tierlist seeded');
});

run_tests();
