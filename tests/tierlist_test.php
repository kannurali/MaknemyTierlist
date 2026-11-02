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

// ------------------------------ Язык страницы ------------------------------

function tl_seed_items(PDO $pdo, int $rev): void {
    $blob = json_encode(['tiers' => [['label' => 'S', 'items' => [
        ['id' => 'a', 'name' => 'Kitsune', 'desc' => 'Описание', 'descEn' => 'Description', 'terms' => '', 'termsEn' => ''],
    ]]], '_rev' => $rev], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("UPDATE tierlist SET data = :d, rev = :r WHERE id = 1")->execute([':d' => $blob, ':r' => $rev]);
}

test('the redirect to the current revision keeps the language', function () {
    $pdo = test_db();
    tl_seed_items($pdo, 42);
    assert_eq([302, ['location' => '/api/tierlist.php?rev=42&lang=de']], tierlist_route($pdo, '41', 'de'));
    assert_eq([302, ['location' => '/api/tierlist.php?rev=42&lang=de']], tierlist_route($pdo, null, 'de'));
});

test('an unknown language is redirected to the two-language address', function () {
    $pdo = test_db();
    tl_seed_items($pdo, 42);
    foreach (['', 'xx', 'DE', ['de']] as $bad) {
        assert_eq([302, ['location' => '/api/tierlist.php?rev=42']], tierlist_route($pdo, '42', $bad), var_export($bad, true));
    }
});

test('without a language the data is the stored two-language document, cached for good', function () {
    $pdo = test_db();
    tl_seed_items($pdo, 42);
    [$status, $p, $final] = tierlist_route($pdo, '42');
    assert_eq(200, $status);
    assert_eq(['Описание', 'Description'], [$p['tierlist']['tiers'][0]['items'][0]['desc'], $p['tierlist']['tiers'][0]['items'][0]['descEn']]);
    assert_eq(true, $final);
});

test('German: the translation once it is there, until then EN with a short cache', function () {
    $pdo = test_db();
    tl_seed_items($pdo, 42);
    [$status, $p, $final] = tierlist_route($pdo, '42', 'de');
    $it = $p['tierlist']['tiers'][0]['items'][0];
    assert_eq(['', 'Description'], [$it['desc'], $it['descEn']], 'EN fallback in the English slot');
    assert_eq(false, $final, 'not cached for good');

    i18n_put($pdo, 'item', 'a', 'desc', 'de', i18n_hash('Описание'), 'Beschreibung', 1);
    [, $p, $final] = tierlist_route($pdo, '42', 'de');
    assert_eq('Beschreibung', $p['tierlist']['tiers'][0]['items'][0]['descEn']);
    assert_eq(true, $final);
});

test('Russian needs no translation and is cached for good', function () {
    $pdo = test_db();
    tl_seed_items($pdo, 42);
    [, $p, $final] = tierlist_route($pdo, '42', 'ru');
    $it = $p['tierlist']['tiers'][0]['items'][0];
    assert_eq(['Описание', ''], [$it['desc'], $it['descEn']]);
    assert_eq(true, $final);
});

test('before the migration German falls back to EN', function () {
    $pdo = test_db();
    $pdo->exec('DROP TABLE i18n_texts');
    tl_seed_items($pdo, 42);
    [$status, $p, $final] = tierlist_route($pdo, '42', 'de');
    assert_eq(200, $status);
    assert_eq('Description', $p['tierlist']['tiers'][0]['items'][0]['descEn']);
    assert_eq(false, $final);
});

run_tests();
