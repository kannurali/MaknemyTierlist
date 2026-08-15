<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/news.php';

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

run_tests();
