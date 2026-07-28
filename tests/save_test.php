<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/images.php';
require __DIR__ . '/../public_html/api/lib/validate.php';
require __DIR__ . '/../public_html/api/save.php';

const PNG_B64_S = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir_s(): string {
    $d = sys_get_temp_dir() . '/savetest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

test('rejects invalid state', function () {
    $pdo = test_db();
    [$status, $p] = handle_save($pdo, ['no_tiers' => 1], tmp_dir_s(), 1000);
    assert_eq(400, $status, '400');
    assert_eq(false, $p['ok'], 'not ok');
});

test('saves, sets server rev, extracts embedded image', function () {
    $pdo = test_db();
    $dir = tmp_dir_s();
    $state = ['tiers' => [['items' => [['icon' => 'data:image/png;base64,' . PNG_B64_S]]]]];
    [$status, $p] = handle_save($pdo, $state, $dir, 999000);
    assert_eq(200, $status, 'ok');
    assert_eq(999000, $p['rev'], 'server rev used');

    $stored = json_decode($pdo->query("SELECT data FROM tierlist WHERE id=1")->fetchColumn(), true);
    $expectUrl = '/images/' . sha1(base64_decode(PNG_B64_S)) . '.png';
    assert_eq($expectUrl, $stored['tiers'][0]['items'][0]['icon'], 'icon rewritten to url');
    assert_eq(999000, (int)$pdo->query("SELECT rev FROM tierlist WHERE id=1")->fetchColumn(), 'rev persisted');
});

test('rejects oversize embedded image', function () {
    $pdo = test_db();
    $dir = tmp_dir_s();
    // Build a >500KB data URL by repeating (invalid image bytes but big).
    $big = 'data:image/png;base64,' . base64_encode(str_repeat('A', 600000));
    $state = ['tiers' => [['items' => [['icon' => $big]]]]];
    [$status, $p] = handle_save($pdo, $state, $dir, 1000);
    assert_eq(400, $status, 'oversize -> 400');
});

run_tests();
