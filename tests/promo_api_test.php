<?php
// The campaign document endpoint. Run: php tests/promo_api_test.php
// GD is not needed here - nothing in these paths decodes an image.
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/promo.php';

function tmp_dir_p(): string {
    $d = sys_get_temp_dir() . '/promotest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

function one_campaign(array $over = []): array {
    return ['campaigns' => [array_merge([
        'id' => 'c_playerok', 'name' => 'Playerok', 'advertiser' => 'Playerok',
        'enabled' => true, 'weight' => 3, 'start' => '2026-08-20', 'end' => '2026-09-20',
        'href' => 'https://playerok.com/', 'text' => 'buy safely', 'cta' => 'Go',
        'slots' => ['strip'],
        'creatives' => ['strip' => ['src' => '/images/abc.webp', 'w' => 1200, 'h' => 300, 'anim' => false]],
        'notes' => 'paid until 20.09, 15000 RUB, contact @someone',
    ], $over)]];
}

// --------------------------------------------------------------- promo_load

test('promo_load reports an empty document when nothing was ever saved', function () {
    $doc = promo_load(test_db());
    assert_eq(1, $doc['v'], 'version');
    assert_eq(0, $doc['rev'], 'rev');
    assert_eq([], $doc['campaigns'], 'no campaigns');
});

test('promo_load survives a missing table and unparseable data', function () {
    // schema.sql is run by hand on production; the site must not 500 before
    // someone gets around to it.
    $bare = new PDO('sqlite::memory:');
    $bare->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    assert_eq(PROMO_EMPTY, promo_load($bare), 'no promo table at all');
    assert_eq(0, promo_rev($bare), 'rev without a table');

    $pdo = test_db();
    $pdo->exec("INSERT INTO promo (id, data, rev) VALUES (1, 'not json', 5)");
    assert_eq([], promo_load($pdo)['campaigns'], 'garbage data');
});

// --------------------------------------------------------- save / round trip

test('handle_promo_save inserts on first save and updates afterwards', function () {
    $pdo = test_db();
    $dir = tmp_dir_p();

    [$s1, $p1] = handle_promo_save($pdo, one_campaign(), $dir, 1000);
    assert_eq(200, $s1, 'first save ok');
    assert_eq(1000, $p1['rev'], 'rev returned');
    assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM promo")->fetchColumn(), 'row inserted');

    [$s2, $p2] = handle_promo_save($pdo, one_campaign(['name' => 'Playerok v2']), $dir, 2000);
    assert_eq(200, $s2, 'second save ok');
    assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM promo")->fetchColumn(), 'still one row');
    assert_eq(2000, promo_rev($pdo), 'rev advanced');
    assert_eq('Playerok v2', promo_load($pdo)['campaigns'][0]['name'], 'content replaced');
});

test('saving campaigns never touches the tier list blob', function () {
    // The whole point of the separate document. If this ever fails, ads have
    // been moved back into `state` and the 256 px crusher is back with them.
    $pdo = test_db();
    $pdo->exec("UPDATE tierlist SET data = '{\"tiers\":[{\"id\":\"S\"}]}', rev = 777 WHERE id = 1");
    $before = $pdo->query("SELECT data, rev FROM tierlist WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

    handle_promo_save($pdo, one_campaign(), tmp_dir_p(), 1000);

    $after = $pdo->query("SELECT data, rev FROM tierlist WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    assert_eq($before, $after, 'tier list byte-identical');
});

test('a saved campaign round-trips through get unchanged', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign(), tmp_dir_p(), 4242);
    [$status, $doc] = handle_promo_get($pdo, true);
    assert_eq(200, $status, 'ok');
    assert_eq(4242, $doc['rev'], 'rev');
    assert_eq('c_playerok', $doc['campaigns'][0]['id'], 'id');
    assert_eq(3, $doc['campaigns'][0]['weight'], 'weight');
    assert_eq('2026-09-20', $doc['campaigns'][0]['end'], 'end date');
    assert_eq('/images/abc.webp', $doc['campaigns'][0]['creatives']['strip']['src'], 'creative');
});

// ----------------------------------------------------------- the notes split

test('the public view hides the commercial notes, the admin view keeps them', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign(), tmp_dir_p(), 1000);

    [, $adminDoc]  = handle_promo_get($pdo, true);
    [, $publicDoc] = handle_promo_get($pdo, false);

    assert_true(isset($adminDoc['campaigns'][0]['notes']), 'admin sees notes');
    assert_eq(false, isset($publicDoc['campaigns'][0]['notes']), 'public does not');
    // Everything a visitor actually needs is still there.
    assert_eq('https://playerok.com/', $publicDoc['campaigns'][0]['href'], 'href kept');
    assert_eq('buy safely', $publicDoc['campaigns'][0]['text'], 'text kept');
});

// The ?rev= response is served with a year-long `public, immutable` header. If
// an admin session could widen its body, the owner loading his own site would
// park his commercial terms in a cache entry meant for everybody.
test('the cacheable ?rev= response never carries the admin document', function () {
    assert_eq(false, promo_admin_view_allowed(true, true),  'admin + ?rev= stays public');
    assert_eq(true,  promo_admin_view_allowed(true, false), 'admin without ?rev= sees notes');
    assert_eq(false, promo_admin_view_allowed(false, false), 'visitor never does');
    assert_eq(false, promo_admin_view_allowed(false, true),  'visitor with ?rev= neither');
});

// ------------------------------------------------------------ normalization

test('a dangerous href is neutralised before it reaches the database', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign(['href' => 'javascript:alert(1)']), tmp_dir_p(), 1000);
    assert_eq('', promo_load($pdo)['campaigns'][0]['href'], 'stored empty');
});

test('promo_safe_href matches the client-side whitelist', function () {
    assert_eq('', promo_safe_href('javascript:alert(1)'), 'javascript');
    assert_eq('', promo_safe_href('data:text/html,x'), 'data');
    assert_eq('', promo_safe_href('https://'), 'placeholder');
    assert_eq('', promo_safe_href('https://a.com/ b'), 'whitespace');
    assert_eq('https://playerok.com', promo_safe_href('playerok.com'), 'bare domain');
    assert_eq('https://playerok.com', promo_safe_href('//playerok.com'), 'protocol relative');
    assert_eq('mailto:a@b.c', promo_safe_href('mailto:a@b.c'), 'mailto');
});

test('out-of-range values are clamped rather than rejected', function () {
    $pdo = test_db();
    [$status] = handle_promo_save($pdo, one_campaign([
        'weight' => 999,
        'popup' => ['delayMs' => 10, 'capHours' => 0, 'maxPerWeek' => 9999],
    ]), tmp_dir_p(), 1000);
    assert_eq(200, $status, 'saved, not refused');

    $c = promo_load($pdo)['campaigns'][0];
    assert_eq(100, $c['weight'], 'weight clamped');
    assert_eq(5000, $c['popup']['delayMs'], 'delay clamped up');
    assert_eq(1, $c['popup']['capHours'], 'cap clamped up');
    assert_eq(50, $c['popup']['maxPerWeek'], 'quota clamped down');
});

test('unknown slots and empty creatives are dropped', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign([
        'slots' => ['strip', 'sidebar', 'strip', 'popup'],
        'creatives' => [
            'strip'   => ['src' => '/images/a.webp'],
            'sidebar' => ['src' => '/images/b.webp'],
            'popup'   => ['src' => '   '],
        ],
    ]), tmp_dir_p(), 1000);

    $c = promo_load($pdo)['campaigns'][0];
    assert_eq(['strip', 'popup'], $c['slots'], 'slots filtered and de-duplicated');
    assert_eq(['strip'], array_keys($c['creatives']), 'creatives filtered');
});

test('an animated creative always ends up with a poster', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign([
        'creatives' => ['strip' => ['src' => '/images/anim.gif', 'anim' => true, 'poster' => '']],
    ]), tmp_dir_p(), 1000);
    assert_eq('/images/anim.gif', promo_load($pdo)['campaigns'][0]['creatives']['strip']['poster'], 'falls back to itself');
});

test('malformed dates are blanked, not treated as a schedule', function () {
    $pdo = test_db();
    handle_promo_save($pdo, one_campaign(['start' => '20.08.2026', 'end' => '2026-09-20']), tmp_dir_p(), 1000);
    $c = promo_load($pdo)['campaigns'][0];
    assert_eq('', $c['start'], 'bad start blanked');
    assert_eq('2026-09-20', $c['end'], 'good end kept');
});

// -------------------------------------------------------------- hard errors

test('campaign ids must be present, well-formed and unique', function () {
    $pdo = test_db();
    $dir = tmp_dir_p();

    [$s1] = handle_promo_save($pdo, ['campaigns' => [['name' => 'no id']]], $dir, 1000);
    assert_eq(400, $s1, 'missing id');

    [$s2] = handle_promo_save($pdo, ['campaigns' => [['id' => 'has space']]], $dir, 1000);
    assert_eq(400, $s2, 'malformed id');

    [$s3, $p3] = handle_promo_save($pdo, ['campaigns' => [['id' => 'a'], ['id' => 'a']]], $dir, 1000);
    assert_eq(400, $s3, 'duplicate id');
    assert_true(strpos($p3['error'], 'duplicate') !== false, 'error names the problem');

    // Nothing was written by any of the three.
    assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM promo")->fetchColumn(), 'nothing stored');
});

test('an oversized document is refused with its actual size', function () {
    $pdo = test_db();
    $campaigns = [];
    for ($i = 0; $i < 400; $i++) {
        $campaigns[] = ['id' => 'c' . $i, 'name' => str_repeat('x', 200), 'notes' => str_repeat('y', 200)];
    }
    [$status, $p] = handle_promo_save($pdo, ['campaigns' => $campaigns], tmp_dir_p(), 1000);
    assert_eq(400, $status, 'refused');
    assert_true(strpos($p['error'], 'too large') !== false, 'error explains');
    assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM promo")->fetchColumn(), 'nothing stored');
});

test('an empty document is a valid state, not an error', function () {
    $pdo = test_db();
    [$status] = handle_promo_save($pdo, ['campaigns' => []], tmp_dir_p(), 1000);
    assert_eq(200, $status, 'clearing all ads is allowed');
    assert_eq([], promo_load($pdo)['campaigns'], 'stored empty');
});

// ------------------------------------------------------ optimistic locking

test('a save against a stale revision is refused instead of clobbering', function () {
    $pdo = test_db();
    $dir = tmp_dir_p();
    handle_promo_save($pdo, one_campaign(), $dir, 1000);

    // Another tab saved in the meantime; this client still holds rev 1000.
    handle_promo_save($pdo, one_campaign(['name' => 'from the other tab']), $dir, 2000);

    [$status, $p] = handle_promo_save($pdo, one_campaign(['name' => 'stale']), $dir, 3000, 1000);
    assert_eq(409, $status, 'conflict');
    assert_eq(2000, $p['rev'], 'reports the current revision');
    assert_eq('from the other tab', promo_load($pdo)['campaigns'][0]['name'], 'winner kept');
});

test('a save with the current revision goes through', function () {
    $pdo = test_db();
    $dir = tmp_dir_p();
    handle_promo_save($pdo, one_campaign(), $dir, 1000);
    [$status] = handle_promo_save($pdo, one_campaign(['name' => 'next']), $dir, 2000, 1000);
    assert_eq(200, $status, 'accepted');

    // A first save against an empty table expects rev 0.
    $fresh = test_db();
    [$s0] = handle_promo_save($fresh, one_campaign(), $dir, 1000, 0);
    assert_eq(200, $s0, 'first save expects rev 0');
});

// --------------------------------------------------- inline creative upload

test('an inline data URL is extracted to /images/ and measured', function () {
    $pdo = test_db();
    $dir = tmp_dir_p();
    // 24x8 single-frame GIF, built by hand so this test needs no GD.
    $gif = "GIF89a" . pack('v', 24) . pack('v', 8) . chr(0x80) . chr(0) . chr(0)
        . "\x00\x00\x00\xFF\xFF\xFF"
        . "\x2C" . pack('v', 0) . pack('v', 0) . pack('v', 24) . pack('v', 8) . chr(0)
        . "\x02\x03\x4C\x01\x00\x00\x3B";

    handle_promo_save($pdo, one_campaign([
        'creatives' => ['strip' => ['src' => 'data:image/gif;base64,' . base64_encode($gif)]],
    ]), $dir, 1000);

    $cre = promo_load($pdo)['campaigns'][0]['creatives']['strip'];
    assert_eq('/images/' . sha1($gif) . '.gif', $cre['src'], 'rewritten to a stored url');
    assert_eq(24, $cre['w'], 'width measured server-side');
    assert_eq(8, $cre['h'], 'height measured server-side');
    assert_true(is_file($dir . '/' . sha1($gif) . '.gif'), 'written to disk');
});

test('an unusable inline creative fails the save loudly', function () {
    $pdo = test_db();
    [$status, $p] = handle_promo_save($pdo, one_campaign([
        'creatives' => ['strip' => ['src' => 'data:text/plain;base64,' . base64_encode('hello')]],
    ]), tmp_dir_p(), 1000);
    assert_eq(400, $status, 'refused');
    assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM promo")->fetchColumn(), 'nothing stored');
});

run_tests();
