<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/news.php';
require __DIR__ . '/../public_html/api/news_like.php';

// Кладёт пост напрямую в БД — тот же приём, что seed_post() в news_test.php:
// тесты лайка не должны зависеть от эндпоинта записи поста.
function seed_like_post(PDO $pdo, int $publishedAt = 1000): int {
    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, published_at)
         VALUES ('game', 'заголовок', '', 'тело', '', '', :pa)"
    );
    $stmt->execute([':pa' => $publishedAt]);
    return (int)$pdo->lastInsertId();
}

// ---------- инкремент/декремент ----------

test('increment adds one to the post likes', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    [$status, $p] = handle_news_like($pdo, ['id' => $id, 'dir' => 1]);
    assert_eq(200, $status, 'ok status');
    assert_eq(1, $p['likes'], 'count 1');
    assert_true($p['ok'], 'ok flag');
});

test('decrement subtracts one', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    handle_news_like($pdo, ['id' => $id, 'dir' => 1]);
    [, $p] = handle_news_like($pdo, ['id' => $id, 'dir' => -1]);
    assert_eq(0, $p['likes'], '1 then -1 = 0');
});

test('unknown dir defaults to +1', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    [, $p] = handle_news_like($pdo, ['id' => $id, 'dir' => 99]);
    assert_eq(1, $p['likes'], 'defaults +1');
});

// ---------- никогда не уходит в минус ----------

test('decrement on a fresh post clamps at zero, not negative', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    [$status, $p] = handle_news_like($pdo, ['id' => $id, 'dir' => -1]);
    assert_eq(200, $status, 'still ok, just clamped');
    assert_eq(0, $p['likes'], 'stays 0');
});

test('repeated decrements never push likes below zero', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    handle_news_like($pdo, ['id' => $id, 'dir' => 1]);
    handle_news_like($pdo, ['id' => $id, 'dir' => -1]);
    [, $p] = handle_news_like($pdo, ['id' => $id, 'dir' => -1]);
    assert_eq(0, $p['likes'], 'still 0, not -1');
});

// ---------- ошибочный/отсутствующий id: ничего не создаётся и не трогается ----------

test('a missing id is a 400 and touches no row', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    [$status, $p] = handle_news_like($pdo, ['dir' => 1]);
    assert_eq(400, $status, 'rejected');
    assert_eq('bad id', $p['error'], 'reason');
    [, $feed] = handle_news($pdo);
    assert_eq(0, $feed['posts'][0]['likes'], 'untouched post unaffected');
});

// (int)"1abc" === 1 — то же приведение типов, от которого read_row_id()
// защищает news_delete.php/news_save.php; здесь тот же мусор не должен
// лайкнуть чужой пост.
test('a junk id is a 400 and does not touch any row — (int) cast bug', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    $cases = [
        ['1abc', 'digits followed by letters'],
        ['5.7', 'float-looking string'],
        [true, 'boolean true'],
        [[1, 2, 3], 'non-empty array'],
        [-5, 'negative int'],
    ];
    foreach ($cases as [$junk, $label]) {
        [$status, $p] = handle_news_like($pdo, ['id' => $junk, 'dir' => 1]);
        assert_eq(400, $status, "status for $label");
        assert_eq('bad id', $p['error'] ?? null, "reason for $label");
    }
    [, $feed] = handle_news($pdo);
    assert_eq(0, $feed['posts'][0]['likes'], 'post untouched by any junk id');
});

// ---------- несуществующий id: не создаёт строку и не отвечает успехом ----------

test('a well-formed but non-existent id is a 404, not a fake success', function () {
    $pdo = test_db();
    seed_like_post($pdo); // одна реальная строка, id заведомо не 999
    [$status, $p] = handle_news_like($pdo, ['id' => 999, 'dir' => 1]);
    assert_eq(404, $status, 'not found');
    assert_eq('not found', $p['error'], 'reason');
    assert_true(!isset($p['ok']) || $p['ok'] === false, 'never reports ok:true');
});

test('a non-existent id creates no row', function () {
    $pdo = test_db();
    seed_like_post($pdo);
    handle_news_like($pdo, ['id' => 999, 'dir' => 1]);
    [, $feed] = handle_news($pdo);
    assert_eq(1, count($feed['posts']), 'still exactly one post, none created');
});

// ---------- лайки разных постов независимы ----------

test('liking one post does not touch another', function () {
    $pdo = test_db();
    $a = seed_like_post($pdo, 1000);
    $b = seed_like_post($pdo, 2000);
    handle_news_like($pdo, ['id' => $a, 'dir' => 1]);
    [, $feed] = handle_news($pdo);
    $rowA = array_values(array_filter($feed['posts'], fn($p) => $p['id'] === $a))[0];
    $rowB = array_values(array_filter($feed['posts'], fn($p) => $p['id'] === $b))[0];
    assert_eq(1, $rowA['likes'], 'liked post incremented');
    assert_eq(0, $rowB['likes'], 'sibling post untouched');
});

// ---------- лента отдаёт likes как int ----------

test('the feed returns likes as an int, not a string', function () {
    $pdo = test_db();
    $id = seed_like_post($pdo);
    handle_news_like($pdo, ['id' => $id, 'dir' => 1]);
    [, $feed] = handle_news($pdo);
    assert_true($feed['posts'][0]['likes'] === 1, 'strict int comparison');
});

test('a freshly seeded post with no likes yet also comes back as int 0', function () {
    $pdo = test_db();
    seed_like_post($pdo);
    [, $feed] = handle_news($pdo);
    assert_true($feed['posts'][0]['likes'] === 0, 'strict int 0, not "0" or null');
});

// ---------- лимит частоты (тот же механизм, что у /api/like.php) ----------

test('news_like rate limit allows up to max then blocks', function () {
    $key = 'test-' . uniqid();
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) {
        assert_true(rate_limit_allow('news_like', $key, 3, 3600, $now), "hit $i allowed");
    }
    assert_eq(false, rate_limit_allow('news_like', $key, 3, 3600, $now), '4th blocked');
    @unlink(rate_file('news_like', $key));
});

run_tests();
