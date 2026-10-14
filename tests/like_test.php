<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/like.php';

test('increment adds one', function () {
    $pdo = test_db();
    [$status, $p] = handle_like($pdo, '101', ['dir' => 1], 1000);
    assert_eq(200, $status, 'ok status');
    assert_eq(1, $p['likes'], 'count 1');
});

test('decrement clamps at zero', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, '101', ['dir' => -1], 1000);
    assert_eq(0, $p['likes'], 'stays 0');
});

test('unknown dir defaults to +1', function () {
    $pdo = test_db();
    [, $p] = handle_like($pdo, '101', ['dir' => 99], 1000);
    assert_eq(1, $p['likes'], 'defaults +1');
});

test('decrement after increment nets zero', function () {
    $pdo = test_db();
    handle_like($pdo, '101', ['dir' => 1], 1000);
    [, $p] = handle_like($pdo, '101', ['dir' => -1], 1000);
    assert_eq(0, $p['likes'], '1 then -1 = 0');
});

// --- только вошедшим, один голос на аккаунт (api/lib/likes.php) -------------

test('аноним лайкнуть не может, счётчик не трогается', function () {
    $pdo = test_db();
    [$status, $p] = handle_like($pdo, '', ['dir' => 1], 1000);
    assert_eq(401, $status, '401');
    assert_eq('not_logged_in', $p['error'], 'и это названо');
    assert_eq(0, (int)$pdo->query('SELECT count FROM likes WHERE id = 1')->fetchColumn(), 'счётчик на месте');
});

test('второй лайк того же аккаунта не считается', function () {
    $pdo = test_db();
    handle_like($pdo, '101', ['dir' => 1], 1000);
    [$status, $p] = handle_like($pdo, '101', ['dir' => 1], 1001);
    assert_eq(200, $status, 'ответ обычный');
    assert_eq(1, $p['likes'], 'один аккаунт — один лайк');
    assert_eq(true, $p['liked'], 'лайк стоит');
    [, $p] = handle_like($pdo, '202', ['dir' => 1], 1002);
    assert_eq(2, $p['likes'], 'другой аккаунт — ещё один');
});

test('снять можно только свой лайк', function () {
    $pdo = test_db();
    handle_like($pdo, '101', ['dir' => 1], 1000);
    [, $p] = handle_like($pdo, '202', ['dir' => -1], 1001);
    assert_eq(1, $p['likes'], 'чужой «минус» лайк 101 не уводит');
    assert_eq(false, $p['liked'], 'у 202 лайка нет');
    [, $p] = handle_like($pdo, '101', ['dir' => -1], 1002);
    assert_eq(0, $p['likes'], 'свой снимается');
    [, $p] = handle_like($pdo, '101', ['dir' => 1], 1003);
    assert_eq(1, $p['likes'], 'и ставится заново');
});

test('прежние лайки сохраняются, новые голоса идут поверх', function () {
    $pdo = test_db();
    $pdo->exec('UPDATE likes SET count = 7158 WHERE id = 1');
    [, $p] = handle_like($pdo, '101', ['dir' => 1], 1000);
    assert_eq(7159, $p['likes'], 'старый счёт плюс новый голос');
});

test('без таблицы голосов — 503, счётчик не тронут', function () {
    $pdo = test_db();
    $pdo->exec('DROP TABLE like_votes');
    [$status, $p] = handle_like($pdo, '101', ['dir' => 1], 1000);
    assert_eq(503, $status, 'миграции нет — 503');
    assert_eq('not_ready', $p['error'], 'и это названо');
    assert_eq(0, (int)$pdo->query('SELECT count FROM likes WHERE id = 1')->fetchColumn(), 'счётчик на месте');
});

test('like_mine: что человек уже лайкнул', function () {
    $pdo = test_db();
    assert_eq(['tierlist' => false, 'news' => []], like_mine($pdo, '101'), 'ничего');
    handle_like($pdo, '101', ['dir' => 1], 1000);
    like_vote($pdo, '101', like_news_target(7), 1, 1000);
    like_vote($pdo, '101', like_news_target(12), 1, 1000);
    like_vote($pdo, '202', like_news_target(9), 1, 1000);
    $m = like_mine($pdo, '101');
    assert_eq(true, $m['tierlist'], 'тирлист');
    sort($m['news']);
    assert_eq([7, 12], $m['news'], 'свои посты, чужие не видны');
    assert_eq(['tierlist' => false, 'news' => []], like_mine($pdo, ''), 'аноним — пусто');
    $pdo->exec('DROP TABLE like_votes');
    assert_eq(['tierlist' => false, 'news' => []], like_mine($pdo, '101'), 'без таблицы — пусто, не ошибка');
});

// --- rate limiter (per-IP cap wired in front of handle_like) ---------------

test('rate limit allows up to max then blocks', function () {
    $key = 'test-' . uniqid();
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) {
        assert_true(rate_limit_allow('t', $key, 3, 3600, $now), "hit $i allowed");
    }
    assert_eq(false, rate_limit_allow('t', $key, 3, 3600, $now), '4th blocked');
    @unlink(rate_file('t', $key));
});

test('rate limit window slides — old hits expire', function () {
    $key = 'test-' . uniqid();
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) { rate_limit_allow('t', $key, 3, 3600, $now); }
    assert_eq(false, rate_limit_allow('t', $key, 3, 3600, $now + 3599), 'still blocked inside window');
    assert_true(rate_limit_allow('t', $key, 3, 3600, $now + 3601), 'allowed after window passes');
    @unlink(rate_file('t', $key));
});

test('rate limit keys are independent', function () {
    $k1 = 'test-' . uniqid() . '-a';
    $k2 = 'test-' . uniqid() . '-b';
    $now = 1000000;
    for ($i = 0; $i < 3; $i++) { rate_limit_allow('t', $k1, 3, 3600, $now); }
    assert_eq(false, rate_limit_allow('t', $k1, 3, 3600, $now), 'k1 exhausted');
    assert_true(rate_limit_allow('t', $k2, 3, 3600, $now), 'k2 unaffected');
    @unlink(rate_file('t', $k1));
    @unlink(rate_file('t', $k2));
});

test('rate limit survives corrupt state file', function () {
    $key = 'test-' . uniqid();
    file_put_contents(rate_file('t', $key), 'not json');
    assert_true(rate_limit_allow('t', $key, 3, 3600, 1000000), 'corrupt file = fresh start');
    @unlink(rate_file('t', $key));
});

run_tests();
