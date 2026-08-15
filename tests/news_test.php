<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/news.php';
require __DIR__ . '/../public_html/api/news_save.php';
require __DIR__ . '/../public_html/api/news_delete.php';

// Кладёт пост напрямую в БД: тесты чтения не должны зависеть от эндпоинта
// записи, иначе одна ошибка в валидации завалит сразу обе группы.
function seed_post(PDO $pdo, string $cat, string $title, int $publishedAt): int {
    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, published_at)
         VALUES (:c, :t, '', 'тело', '', '', :pa)"
    );
    $stmt->execute([':c' => $cat, ':t' => $title, ':pa' => $publishedAt]);
    return (int)$pdo->lastInsertId();
}

test('an empty feed is an empty list, not an error', function () {
    $pdo = test_db();
    [$status, $p] = handle_news($pdo);
    assert_eq(200, $status, 'ok status');
    assert_eq([], $p['posts'], 'no posts');
});

test('posts come back newest first', function () {
    $pdo = test_db();
    seed_post($pdo, 'game', 'старая', 1000);
    seed_post($pdo, 'game', 'новая', 3000);
    seed_post($pdo, 'game', 'средняя', 2000);
    [, $p] = handle_news($pdo);
    assert_eq(['новая', 'средняя', 'старая'], array_column($p['posts'], 'title_ru'), 'order');
});

test('the feed is capped at 50 posts', function () {
    $pdo = test_db();
    for ($i = 1; $i <= 55; $i++) { seed_post($pdo, 'project', 'пост ' . $i, $i * 1000); }
    [, $p] = handle_news($pdo);
    assert_eq(50, count($p['posts']), 'capped');
    assert_eq('пост 55', $p['posts'][0]['title_ru'], 'newest kept');
});

test('numbers come back as numbers, not strings', function () {
    // SQLite и MySQL отдают числа строками через PDO; фронт сравнивает id и
    // сортирует по дате, поэтому приведение типов обязано жить в эндпоинте.
    $pdo = test_db();
    $id = seed_post($pdo, 'tierlist', 'заголовок', 1755300000000);
    [, $p] = handle_news($pdo);
    assert_true($p['posts'][0]['id'] === $id, 'id is int');
    assert_true($p['posts'][0]['published_at'] === 1755300000000, 'published_at is int');
});

// ---------- запись ----------

function valid_body(array $over = []): array {
    return array_merge([
        'category' => 'game',
        'title_ru' => 'Апдейт 26',
        'body_ru'  => 'Первый абзац.',
    ], $over);
}

test('a valid post is inserted and comes back in the feed', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, valid_body(), 1755300000000);
    assert_eq(200, $status, 'ok status');
    assert_true($p['id'] > 0, 'got an id');
    [, $feed] = handle_news($pdo);
    assert_eq(1, count($feed['posts']), 'one post');
    assert_eq('Апдейт 26', $feed['posts'][0]['title_ru'], 'title stored');
    assert_eq(1755300000000, $feed['posts'][0]['published_at'], 'stamped with now');
});

test('a category outside the list is rejected', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, valid_body(['category' => 'trade']), 1);
    assert_eq(400, $status, 'rejected');
    assert_eq('bad category', $p['error'], 'reason');
});

test('russian title and body are required', function () {
    $pdo = test_db();
    [$s1, ] = handle_news_save($pdo, valid_body(['title_ru' => '   ']), 1);
    assert_eq(400, $s1, 'empty title rejected');
    [$s2, ] = handle_news_save($pdo, valid_body(['body_ru' => '']), 1);
    assert_eq(400, $s2, 'empty body rejected');
});

test('over-long title and body are rejected', function () {
    $pdo = test_db();
    [$s1, ] = handle_news_save($pdo, valid_body(['title_ru' => str_repeat('я', 201)]), 1);
    assert_eq(400, $s1, 'title too long');
    [$s2, ] = handle_news_save($pdo, valid_body(['body_ru' => str_repeat('я', 20001)]), 1);
    assert_eq(400, $s2, 'body too long');
});

test('length is counted in characters, not bytes', function () {
    // Кириллица в utf8mb4 — два байта на символ. По strlen 200 русских букв
    // это 400, и валидный заголовок отлетал бы с «слишком длинный».
    $pdo = test_db();
    [$status, ] = handle_news_save($pdo, valid_body(['title_ru' => str_repeat('я', 200)]), 1);
    assert_eq(200, $status, 'accepted');
});

test('only our own image paths are accepted', function () {
    $pdo = test_db();
    $good = '/images/' . str_repeat('a', 40) . '.webp';
    [$s1, ] = handle_news_save($pdo, valid_body(['image_url' => $good]), 1);
    assert_eq(200, $s1, 'own path accepted');

    foreach ([
        'https://evil.example/x.png',
        'javascript:alert(1)',
        '/images/../config.php',
        '/images/short.png',
    ] as $bad) {
        [$s, $p] = handle_news_save($pdo, valid_body(['image_url' => $bad]), 1);
        assert_eq(400, $s, 'rejected: ' . $bad);
        assert_eq('bad image_url', $p['error'], 'reason for ' . $bad);
    }
});

test('an empty image url is allowed', function () {
    $pdo = test_db();
    [$status, ] = handle_news_save($pdo, valid_body(['image_url' => '']), 1);
    assert_eq(200, $status, 'image is optional');
});

test('a body with an id updates instead of inserting', function () {
    $pdo = test_db();
    [, $created] = handle_news_save($pdo, valid_body(), 1000);
    $id = $created['id'];
    [$status, ] = handle_news_save($pdo, valid_body([
        'id' => $id, 'title_ru' => 'Апдейт 26 — правка',
    ]), 2000);
    assert_eq(200, $status, 'ok status');
    [, $feed] = handle_news($pdo);
    assert_eq(1, count($feed['posts']), 'still one post');
    assert_eq('Апдейт 26 — правка', $feed['posts'][0]['title_ru'], 'title updated');
});

test('re-saving an unchanged post still succeeds', function () {
    // rowCount() в MySQL возвращает 0, когда UPDATE не изменил значений.
    // Если решать «нашлось ли» по нему, повторное сохранение давало бы 404.
    $pdo = test_db();
    [, $created] = handle_news_save($pdo, valid_body(), 1000);
    [$status, ] = handle_news_save($pdo, valid_body(['id' => $created['id']]), 1000);
    assert_eq(200, $status, 'no false 404');
});

test('updating a post that does not exist is a 404', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, valid_body(['id' => 777]), 1);
    assert_eq(404, $status, 'not found');
    assert_eq('not found', $p['error'], 'reason');
});

test('an explicit date overrides the server clock', function () {
    // Дата поста — контент: админ правит её и датирует задним числом.
    $pdo = test_db();
    handle_news_save($pdo, valid_body(['published_at' => 1700000000000]), 1755300000000);
    [, $feed] = handle_news($pdo);
    assert_eq(1700000000000, $feed['posts'][0]['published_at'], 'explicit date kept');
});

// ---------- удаление ----------

test('deleting a post removes it from the feed', function () {
    $pdo = test_db();
    [, $a] = handle_news_save($pdo, valid_body(['title_ru' => 'Первый']), 1000);
    handle_news_save($pdo, valid_body(['title_ru' => 'Второй']), 2000);

    [$status, $p] = handle_news_delete($pdo, ['id' => $a['id']]);
    assert_eq(200, $status, 'ok status');
    assert_true($p['ok'], 'ok flag');

    [, $feed] = handle_news($pdo);
    assert_eq(['Второй'], array_column($feed['posts'], 'title_ru'), 'only the other one left');
});

test('deleting a missing post is a 404, not a silent success', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_delete($pdo, ['id' => 999]);
    assert_eq(404, $status, 'not found');
    assert_eq('not found', $p['error'], 'reason');
});

test('a missing or junk id is a 400', function () {
    $pdo = test_db();
    [$s1, ] = handle_news_delete($pdo, []);
    assert_eq(400, $s1, 'no id');
    [$s2, ] = handle_news_delete($pdo, ['id' => 'abc']);
    assert_eq(400, $s2, 'not a number');
    [$s3, ] = handle_news_delete($pdo, ['id' => -5]);
    assert_eq(400, $s3, 'negative');
});

run_tests();
