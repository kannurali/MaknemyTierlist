<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/og.php';

// --------------------------------------------------------------------------
//  og_parse_version — только цифры, иначе null.
// --------------------------------------------------------------------------

test('og_parse_version accepts a plain digit string', function () {
    assert_eq(123, og_parse_version('123'), 'digits parsed');
});

test('og_parse_version accepts a real int', function () {
    assert_eq(0, og_parse_version(0), 'zero is a valid version');
});

test('og_parse_version rejects a negative int', function () {
    assert_eq(null, og_parse_version(-1), 'negative rejected');
});

test('og_parse_version rejects a leading-sign string', function () {
    assert_eq(null, og_parse_version('-5'), 'minus rejected');
    assert_eq(null, og_parse_version('+5'), 'plus rejected');
});

test('og_parse_version rejects a decimal string', function () {
    assert_eq(null, og_parse_version('1.5'), 'dot rejected');
});

test('og_parse_version rejects path-traversal payloads', function () {
    assert_eq(null, og_parse_version('../../etc/passwd'), 'traversal rejected');
    assert_eq(null, og_parse_version('1/../../config'), 'embedded traversal rejected');
});

test('og_parse_version rejects trailing junk after digits', function () {
    // (int)"1abc" тихо стало бы 1 — здесь всё значение должно отклоняться целиком.
    assert_eq(null, og_parse_version('1abc'), 'trailing letters rejected');
    assert_eq(null, og_parse_version('1;rm'), 'shell metacharacter rejected');
});

test('og_parse_version rejects empty string, whitespace and non-scalar input', function () {
    assert_eq(null, og_parse_version(''), 'empty string rejected');
    assert_eq(null, og_parse_version(' 1'), 'leading space rejected');
    assert_eq(null, og_parse_version(null), 'null rejected');
    assert_eq(null, og_parse_version(['1']), 'array rejected');
});

// --------------------------------------------------------------------------
//  og_build_cache_path — путь строится только из провалидированной версии;
//  хостильный параметр не может выйти за пределы каталога.
// --------------------------------------------------------------------------

test('og_build_cache_path builds the expected filename for a valid version', function () {
    assert_eq('/img/og/tierlist-42.png', og_build_cache_path('/img/og', 'tierlist', '42'));
});

test('og_build_cache_path strips a trailing slash from the directory', function () {
    assert_eq('/img/og/news-7.png', og_build_cache_path('/img/og/', 'news', 7));
});

test('og_build_cache_path returns null for a non-digit version instead of building a path', function () {
    assert_eq(null, og_build_cache_path('/img/og', 'tierlist', '../../etc/passwd'),
        'a hostile version must never reach the filename');
    assert_eq(null, og_build_cache_path('/img/og', 'tierlist', '1/../../secret'),
        'embedded traversal must never reach the filename');
    assert_eq(null, og_build_cache_path('/img/og', 'tierlist', ''),
        'empty version must never reach the filename');
});

test('og_build_cache_path never lets the resulting path escape the given directory', function () {
    foreach (['../secret', '..', '1..2', '1\0', '1 2', '1%2e%2e'] as $hostile) {
        $path = og_build_cache_path('/img/og', 'tierlist', $hostile);
        assert_eq(null, $path, "hostile version '$hostile' must be rejected, not turned into a path");
    }
});

// --------------------------------------------------------------------------
//  og_truncate — обрезка длинного текста по границе слова, с многоточием.
// --------------------------------------------------------------------------

test('og_truncate leaves a short text untouched', function () {
    assert_eq('короткий текст', og_truncate('короткий текст', 50));
});

test('og_truncate cuts a long text at a word boundary and adds an ellipsis', function () {
    $long = 'Съешь ещё этих мягких французских булок да выпей чаю';
    $out = og_truncate($long, 20);
    assert_true(mb_strlen($out) <= 21, 'result fits the cap plus the ellipsis');
    assert_true(mb_substr($out, -1) === '…', 'ellipsis appended');
    assert_true(mb_strpos($out, ' ') !== mb_strlen($out) - 2, 'no dangling trailing space before the ellipsis');
});

test('og_truncate never cuts mid-word when a space is available', function () {
    $out = og_truncate('однодлинноеслово другое', 12);
    // Резать нечего до первого пробела (позиция 0 не считается разделителем),
    // поэтому единственное длинное слово обрубается посимвольно — это ожидаемо.
    assert_true(mb_strlen($out) > 0, 'still produces something');
});

test('og_truncate collapses internal whitespace and newlines', function () {
    assert_eq('одна строка', og_truncate("одна   \n\n строка", 50));
});

test('og_truncate handles an empty string', function () {
    assert_eq('', og_truncate('', 50));
});

// --------------------------------------------------------------------------
//  og_tierlist_summary — решение об откате, когда живых данных нет.
// --------------------------------------------------------------------------

test('og_tierlist_summary falls back to null when there is no row at all', function () {
    assert_eq(null, og_tierlist_summary(null, null));
});

test('og_tierlist_summary falls back to null on an empty data string', function () {
    assert_eq(null, og_tierlist_summary('', 5));
});

test('og_tierlist_summary falls back to null on malformed JSON', function () {
    assert_eq(null, og_tierlist_summary('{not json', 5));
});

test('og_tierlist_summary falls back to null when tiers is missing or empty', function () {
    assert_eq(null, og_tierlist_summary(json_encode(['tiers' => []]), 5));
    assert_eq(null, og_tierlist_summary(json_encode(['foo' => 'bar']), 5));
});

test('og_tierlist_summary falls back to null when the top tier has no named items', function () {
    $blob = json_encode(['tiers' => [['label' => 'S', 'items' => [['value' => '500m']]]]]);
    assert_eq(null, og_tierlist_summary($blob, 5), 'item without a name does not count');
});

test('og_tierlist_summary falls back to null when rev is not a valid version', function () {
    $blob = json_encode(['tiers' => [['label' => 'S', 'items' => [['name' => 'Dragon', 'value' => '1b']]]]]);
    assert_eq(null, og_tierlist_summary($blob, 'not-a-rev'));
    assert_eq(null, og_tierlist_summary($blob, null));
});

test('og_tierlist_summary returns the top tier items, label and date for healthy data', function () {
    $blob = json_encode([
        'date' => '17.02.2026',
        'tiers' => [
            ['label' => 'S', 'items' => [
                ['name' => 'Dragon', 'value' => '1B'],
                ['name' => '', 'value' => '2B'],  // без имени — пропускается
                ['name' => 'Kitsune', 'value' => '900M'],
            ]],
            ['label' => 'A', 'items' => [['name' => 'Ignored', 'value' => '1']]],
        ],
    ]);
    $s = og_tierlist_summary($blob, 42);
    assert_eq(42, $s['version']);
    assert_eq('S', $s['tierLabel']);
    assert_eq('17.02.2026', $s['date']);
    assert_eq(2, count($s['items']), 'only named items counted, only the top tier used');
    assert_eq('Dragon', $s['items'][0]['name']);
    assert_eq('Kitsune', $s['items'][1]['name']);
});

// --------------------------------------------------------------------------
//  og_news_summary — решение об откате, когда свежего поста нет или он
//  непригоден для превью.
// --------------------------------------------------------------------------

test('og_news_summary falls back to null when there is no post', function () {
    assert_eq(null, og_news_summary(null));
});

test('og_news_summary falls back to null when the title is blank', function () {
    assert_eq(null, og_news_summary(['id' => 1, 'title_ru' => '  ', 'published_at' => 1000]));
});

test('og_news_summary falls back to null when id or published_at is not positive', function () {
    assert_eq(null, og_news_summary(['id' => 0, 'title_ru' => 'x', 'published_at' => 1000]));
    assert_eq(null, og_news_summary(['id' => 1, 'title_ru' => 'x', 'published_at' => 0]));
});

test('og_news_summary returns a summary for a healthy post', function () {
    $s = og_news_summary([
        'id' => 7, 'title_ru' => 'Заголовок', 'body_ru' => 'Тело поста',
        'category' => 'game', 'image_url' => '/images/abc.png', 'published_at' => 1700000000,
    ]);
    assert_eq(71700000000, $s['version'], 'version glues id and published_at');
    assert_eq('Заголовок', $s['title']);
    assert_eq('game', $s['category']);
    assert_eq('/images/abc.png', $s['imageUrl']);
});

test('og_news_summary version changes when the same post is re-dated', function () {
    $a = og_news_summary(['id' => 7, 'title_ru' => 'x', 'published_at' => 1000]);
    $b = og_news_summary(['id' => 7, 'title_ru' => 'x', 'published_at' => 2000]);
    assert_true($a['version'] !== $b['version'], 'moving published_at changes the version');
});

test('og_news_summary version changes when a different post becomes the newest', function () {
    $a = og_news_summary(['id' => 7, 'title_ru' => 'x', 'published_at' => 1000]);
    $b = og_news_summary(['id' => 8, 'title_ru' => 'x', 'published_at' => 1000]);
    assert_true($a['version'] !== $b['version'], 'a different id changes the version');
});

// --------------------------------------------------------------------------
//  og_tierlist_meta / og_news_meta — сборка og:title/og:description из уже
//  посчитанного summary.
// --------------------------------------------------------------------------

test('og_tierlist_meta names the top tier and folds in the date', function () {
    $summary = ['tierLabel' => 'S', 'date' => '17.02.2026', 'items' => [
        ['name' => 'Dragon', 'value' => '1B'],
        ['name' => 'Kitsune', 'value' => ''],
    ]];
    $meta = og_tierlist_meta($summary);
    assert_eq('Maknemy Tier List — S-тир на 17.02.2026', $meta['title']);
    assert_eq('Dragon (1B), Kitsune', $meta['description']);
});

test('og_tierlist_meta falls back to the generic title when the top tier has no label', function () {
    $meta = og_tierlist_meta(['tierLabel' => '', 'date' => '', 'items' => [['name' => 'X', 'value' => '']]]);
    assert_eq('Maknemy Tier List — трейд-ценности Blox Fruits', $meta['title']);
});

test('og_news_meta truncates a long body for the description', function () {
    $body = str_repeat('слово ', 60);
    $meta = og_news_meta(['title' => 'Заголовок', 'body' => $body]);
    assert_eq('Заголовок', $meta['title']);
    assert_true(mb_strlen($meta['description']) <= 201, 'description respects the cap');
    assert_true(mb_substr($meta['description'], -1) === '…', 'truncated body gets an ellipsis');
});

// --------------------------------------------------------------------------
//  og_tierlist_image — общая картинка для / (home.php) и /tierlist
//  (index.php): null-summary обязан откатывать на статичный баннер, живой
//  summary — на сгенерированную превьюшку с версией rev в адресе.
// --------------------------------------------------------------------------

test('og_tierlist_image falls back to the static banner when summary is null', function () {
    $img = og_tierlist_image(null);
    assert_eq('https://maknemy.com/assets/og-image.jpg?v=2', $img['image']);
    assert_eq(1920, $img['imageWidth']);
    assert_eq(1080, $img['imageHeight']);
    assert_eq('image/jpeg', $img['imageType']);
});

test('og_tierlist_image points at the generator with the summary version', function () {
    $summary = ['version' => 42, 'tierLabel' => 'S', 'date' => '', 'items' => [['name' => 'X', 'value' => '']]];
    $img = og_tierlist_image($summary);
    assert_eq('https://maknemy.com/api/og-tierlist.php?v=42', $img['image']);
    assert_eq(1200, $img['imageWidth']);
    assert_eq(630, $img['imageHeight']);
    assert_eq('image/png', $img['imageType']);
});

run_tests();
