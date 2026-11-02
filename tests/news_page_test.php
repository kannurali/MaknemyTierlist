<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/og.php';
require __DIR__ . '/../public_html/news.php';

// С TESTING определена — require выше не печатает ни байта HTML (весь шаблон
// обёрнут в `if (!defined('TESTING'))`, см. news.php) и не трогает БД: он
// только объявляет функции и один раз молча вычисляет пустые $og/$postId и
// т. п. из отсутствующего $_GET. Ниже тестируются только чистые части —
// разбор id, решение о 404 и вывод версии превью конкретного поста;
// GD/PDO-смесь handle_og_news() в api/og-news.php остаётся непокрытой юнит-
// тестами по той же причине, что и остальной рендеринг og-* (см.
// tests/og_test.php) — она проверяется curl'ом и просмотром PNG, а не здесь.

// --------------------------------------------------------------------------
//  news_parse_post_id — только цифры, только положительное значение.
// --------------------------------------------------------------------------

test('news_parse_post_id accepts a plain digit string', function () {
    assert_eq(42, news_parse_post_id('42'));
});

test('news_parse_post_id accepts a positive int', function () {
    assert_eq(7, news_parse_post_id(7));
});

test('news_parse_post_id rejects zero', function () {
    assert_eq(null, news_parse_post_id(0));
    assert_eq(null, news_parse_post_id('0'));
});

test('news_parse_post_id rejects negative values', function () {
    assert_eq(null, news_parse_post_id(-5));
    assert_eq(null, news_parse_post_id('-5'));
});

test('news_parse_post_id rejects non-numeric and hostile input', function () {
    // (int)"1abc" тихо стало бы 1 — здесь всё значение должно отклоняться
    // целиком, тот же принцип, что у og_parse_version()/read_row_id().
    assert_eq(null, news_parse_post_id('1abc'));
    assert_eq(null, news_parse_post_id('1;drop table news'));
    assert_eq(null, news_parse_post_id('../../etc/passwd'));
    assert_eq(null, news_parse_post_id('1.5'));
    assert_eq(null, news_parse_post_id(''));
    assert_eq(null, news_parse_post_id(' 1'));
    assert_eq(null, news_parse_post_id(null));
    assert_eq(null, news_parse_post_id([1]));
    assert_eq(null, news_parse_post_id(true));
});

// --------------------------------------------------------------------------
//  news_post_by_id — решение об 404: пост либо есть, либо null и ничего
//  больше (не молчаливый фолбэк на "покажи ленту как обычно").
// --------------------------------------------------------------------------

function seed_page_post(PDO $pdo, string $title, int $publishedAt, string $cat = 'game'): int {
    $stmt = $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, image_url, published_at)
         VALUES (:c, :t, '', 'тело', '', '', :pa)"
    );
    $stmt->execute([':c' => $cat, ':t' => $title, ':pa' => $publishedAt]);
    return (int)$pdo->lastInsertId();
}

test('news_post_by_id returns null for a missing row — the 404 decision', function () {
    $pdo = test_db();
    assert_eq(null, news_post_by_id($pdo, 999), 'no such post: null, not an empty array or false');
});

test('news_post_by_id returns the row for a real id, whichever post it is', function () {
    $pdo = test_db();
    seed_page_post($pdo, 'Старый', 1000);
    $freshId = seed_page_post($pdo, 'Свежий', 5000);

    // Не обязательно самый свежий — тот самый смысл постоянной ссылки, в
    // отличие от news_og_data()/og-news.php без &id=, которые всегда берут
    // последний пост.
    $row = news_post_by_id($pdo, $freshId);
    assert_true($row !== null, 'row found');
    assert_eq('Свежий', $row['title_ru']);
    // (int) — не косметика: pdo_sqlite и pdo_mysql отдают целые колонки
    // СТРОКАМИ вплоть до PHP 8.1, и без приведения строгий assert_eq
    // сравнивал бы 2 с '2' и падал на боевой версии PHP (сейчас 7.4).
    // Продовый код относится к полю ровно так же — см. (int)$row['id'] в
    // news_post_og_data() и og_news_summary().
    assert_eq($freshId, (int)$row['id']);
});

// --------------------------------------------------------------------------
//  news_post_og_data — версия по СВОЕМУ id+published_at конкретного поста,
//  а не по самому свежему; откат на баннер, когда строка непригодна для
//  превью (og_news_summary() вернула null).
// --------------------------------------------------------------------------

test("news_post_og_data derives the image URL's id and version from that post's own row", function () {
    $row = ['id' => 7, 'category' => 'game', 'title_ru' => 'Заголовок', 'body_ru' => 'Тело', 'image_url' => '', 'published_at' => 1000];
    $data = news_post_og_data($row);
    assert_eq('https://maknemy.com/api/og-news.php?id=7&v=71000', $data['image']);
    assert_eq('Заголовок', $data['title']);
});

test('news_post_og_data version follows the post being viewed, not the newest one', function () {
    $old = news_post_og_data(['id' => 1, 'category' => 'game', 'title_ru' => 'A', 'body_ru' => '', 'image_url' => '', 'published_at' => 1000]);
    $new = news_post_og_data(['id' => 2, 'category' => 'game', 'title_ru' => 'B', 'body_ru' => '', 'image_url' => '', 'published_at' => 9000]);
    assert_true($old['image'] !== $new['image'], 'two different posts get two different preview URLs');
});

test('news_post_og_data falls back to the generic banner when the row cannot produce a summary', function () {
    // Заголовок пустой — og_news_summary() отдаёт null (см. tests/og_test.php).
    $data = news_post_og_data(['id' => 1, 'category' => 'game', 'title_ru' => '   ', 'body_ru' => '', 'image_url' => '', 'published_at' => 1000]);
    assert_eq(news_og_fallback()['image'], $data['image']);
    assert_eq(news_og_fallback()['title'], $data['title']);
});

// --------------------------------------------------------------------------
//  Превью поста на языке страницы.
// --------------------------------------------------------------------------

function og_post_row(PDO $pdo): array {
    $blocks = [['t' => 'p', 'ru' => [['s' => 'Текст поста']], 'en' => [['s' => 'Post text']]]];
    $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, body_json, image_url, published_at)
         VALUES ('game', 'Заголовок', 'Title', 'Текст поста', 'Post text', :j, '', 1000)"
    )->execute([':j' => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE)]);
    return news_post_by_id($pdo, (int)$pdo->lastInsertId());
}

test('the preview of a post on a German page uses the translation, then EN', function () {
    $pdo = test_db();
    $row = og_post_row($pdo);
    i18n_put($pdo, 'news', (string)$row['id'], 'title', 'de', i18n_hash('Заголовок'), 'Überschrift', 1);
    $og = news_post_og_data(news_og_localize($pdo, $row, 'de'));
    assert_eq('Überschrift', $og['title']);
    assert_eq('Post text', $og['description'], 'no German body yet — EN');
});

test('the Russian preview is the row as it was', function () {
    $pdo = test_db();
    $row = og_post_row($pdo);
    assert_eq($row, news_og_localize($pdo, $row, 'ru'));
    assert_eq('Заголовок', news_post_og_data($row)['title']);
});

test('the English preview takes the EN columns, before the migration too', function () {
    $pdo = test_db();
    $pdo->exec('DROP TABLE i18n_texts');
    $row = og_post_row($pdo);
    $og = news_post_og_data(news_og_localize($pdo, $row, 'en'));
    assert_eq(['Title', 'Post text'], [$og['title'], $og['description']]);
    $og = news_post_og_data(news_og_localize($pdo, $row, 'it'));
    assert_eq('Title', $og['title']);
});

run_tests();
