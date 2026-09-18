<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/tierlist.php';

function tl_seed(PDO $pdo, int $rev): void {
    $blob = json_encode(['tiers' => [['name' => 'S', 'items' => []]], '_rev' => $rev]);
    $stmt = $pdo->prepare("UPDATE tierlist SET data = :d, rev = :r WHERE id = 1");
    $stmt->execute([':d' => $blob, ':r' => $rev]);
}

test('the current revision gets the parsed tierlist and likes', function () {
    $pdo = test_db();
    tl_seed($pdo, 42);
    $pdo->exec("UPDATE likes SET count = 3 WHERE id = 1");
    [$status, $p] = tierlist_route($pdo, '42');
    assert_eq(200, $status, 'ok');
    assert_eq('S', $p['tierlist']['tiers'][0]['name'], 'parsed blob');
    assert_eq(3, $p['likes'], 'likes');
});

// Раньше любой ?rev= получал полный ответ с immutable на год, и случайное
// число в адресе обходило любой кеш: PHP, база и ~21 КБ на каждый запрос.
test('a stale, bogus or missing revision is redirected to the current one', function () {
    $pdo = test_db();
    tl_seed($pdo, 42);
    foreach (['41', '43', '999999999', 'abc', '42abc', '-42', ' 42', '', null, ['42']] as $raw) {
        $what = var_export($raw, true);
        [$status, $p] = tierlist_route($pdo, $raw);
        assert_eq(302, $status, "redirect for $what");
        assert_eq('/api/tierlist.php?rev=42', $p['location'] ?? null, "to the current rev for $what");
        assert_eq(false, isset($p['tierlist']), "no payload for $what");
    }
});

test('an empty table sends everyone to rev 0, which serves no data', function () {
    $pdo = test_db();
    $pdo->exec("DELETE FROM tierlist");
    assert_eq([302, ['location' => '/api/tierlist.php?rev=0']], tierlist_route($pdo, null), 'redirect');
    [$status, $p] = tierlist_route($pdo, '0');
    assert_eq(200, $status, 'served');
    assert_eq(null, $p['tierlist'], 'no data yet');
});

run_tests();
