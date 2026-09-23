<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/news_blocks.php';
require __DIR__ . '/../public_html/api/lib/translate.php';
require __DIR__ . '/../public_html/api/news.php';
require __DIR__ . '/../public_html/api/news_save.php';

// Поддельный переводчик: сети нет, каждый абзац запроса получает приставку
// "EN:". Абзацы — через пустую строку, как их склеивает tr_translate_texts(),
// так что разбиение ответа проверяется по-настоящему. $calls копит запросы.
function fake_translator(array &$calls): callable {
    return function (string $text) use (&$calls): ?string {
        $calls[] = $text;
        $out = [];
        foreach (explode(TR_SEP, $text) as $p) { $out[] = 'EN:' . $p; }
        return implode(TR_SEP, $out);
    };
}

function far_future(): float { return microtime(true) + 60; }

function tr_p(array $ru, array $en = []): array { return ['t' => 'p', 'ru' => $ru, 'en' => $en]; }
function tr_s(string $s, array $flags = []): array { return ['s' => $s] + $flags; }
function tr_para(string $ru, string $en = ''): array {
    return ['t' => 'p', 'ru' => [['s' => $ru]], 'en' => $en === '' ? [] : [['s' => $en]]];
}
function tr_doc(array $blocks): array { return ['v' => 1, 'blocks' => $blocks]; }
function tr_img(): string { return '/images/' . str_repeat('b', 40) . '.webp'; }

function saved_row(PDO $pdo, int $id): array {
    $row = $pdo->query("SELECT * FROM news WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
    $row['blocks'] = $row['body_json'] ? json_decode($row['body_json'], true)['blocks'] : null;
    return $row;
}

// ------------------------------- Разметка -------------------------------

test('a plain paragraph goes to Google as bare text, formatted spans as numbered tags', function () {
    assert_eq('Просто текст.', tr_spans_encode([tr_s('Просто текст.')]));
    assert_eq('Новый <x1>фрукт</x1> уже в <x3>игре</x3>.', tr_spans_encode([
        tr_s('Новый '), tr_s('фрукт', ['b' => true]), tr_s(' уже в '),
        tr_s('игре', ['href' => 'https://example.com']), tr_s('.'),
    ]));
});

test('decoding puts formatting back on the translated words', function () {
    $orig = [tr_s('Новый '), tr_s('фрукт', ['b' => true]), tr_s(' уже в '),
             tr_s('игре', ['href' => 'https://example.com']), tr_s('.')];
    assert_eq([
        tr_s('The new '), tr_s('fruit', ['b' => true]), tr_s(' is already in the '),
        tr_s('game', ['href' => 'https://example.com']), tr_s('.'),
    ], tr_spans_decode('The new <x1>fruit</x1> is already in the <x3>game</x3>.', $orig));
});

test('decoding survives reordered tags, stray spaces and upper case', function () {
    $orig = [tr_s('а', ['b' => true]), tr_s(' и '), tr_s('б', ['i' => true])];
    assert_eq([tr_s('B', ['i' => true]), tr_s(' and '), tr_s('A', ['b' => true])],
        tr_spans_decode('< X2 >B</ x2 > and <x0>A</x0>', $orig));
});

test('broken markup is refused so the caller can fall back', function () {
    $orig = [tr_s('а', ['b' => true]), tr_s(' и '), tr_s('б', ['i' => true])];
    assert_eq(null, tr_spans_decode('<x7>A</x7>', $orig), 'unknown number');
    assert_eq(null, tr_spans_decode('<x1>and</x1>', $orig), 'tag on a plain span');
    assert_eq(null, tr_spans_decode('<x0>A and B', $orig), 'never closed');
    assert_eq(null, tr_spans_decode('A</x0> and B', $orig), 'closed but never opened');
    assert_eq(null, tr_spans_decode('<x0>A <x2>B</x0></x2>', $orig), 'crossed');
    assert_eq(null, tr_spans_decode('<x0> </x0>', $orig), 'nothing left to read');
});

test('adjacent pieces with the same format merge, the way the editor stores them', function () {
    $orig = [tr_s('a', ['b' => true]), tr_s(' '), tr_s('b', ['b' => true])];
    assert_eq([tr_s('A B', ['b' => true])], tr_spans_decode('<x0>A</x0><x2> B</x2>', $orig));
});

test('a space Google swallowed at the edge of a tag comes back, without doubling', function () {
    // Живой пост #38: «🎁 <b>Призы: </b>1-5 место» пришёл как «Prizes:1-5».
    $orig = [tr_s('🎁 '), tr_s('Призы: ', ['b' => true]), tr_s('1-5 место')];
    $want = [tr_s('🎁 '), tr_s('Prizes: ', ['b' => true]), tr_s('1-5 place')];
    assert_eq($want, tr_spans_decode('🎁 <x1>Prizes:</x1>1-5 place', $orig), 'swallowed');
    assert_eq($want, tr_spans_decode('🎁 <x1>Prizes:</x1> 1-5 place', $orig), 'moved outside the tag');
});

test('inline code is never translated, and never duplicated', function () {
    $orig = [tr_s('Команда '), tr_s('/spin', ['c' => true]), tr_s(' даёт шанс.')];
    assert_eq([tr_s('The '), tr_s('/spin', ['c' => true]), tr_s(' command gives a chance.')],
        tr_spans_decode('The <x1>/SPIN</x1> command gives a chance.', $orig));
    assert_eq([tr_s('/spin', ['c' => true]), tr_s(' twice')],
        tr_spans_decode('<x1>/sp</x1><x1>in</x1> twice', $orig));
});

test('the per-span fallback keeps each span its own formatting and spacing', function () {
    $orig = [tr_s('Слово '), tr_s('жирное', ['b' => true]), tr_s(' слово'), tr_s(' /spin', ['c' => true])];
    assert_eq([0 => 'Слово', 1 => 'жирное', 2 => 'слово'], tr_fallback_texts($orig));
    assert_eq([tr_s('Word '), tr_s('bold', ['b' => true]), tr_s(' word'), tr_s(' /spin', ['c' => true])],
        tr_fallback_apply($orig, [0 => 'Word', 1 => 'bold', 2 => 'word']));
    assert_eq(null, tr_fallback_apply($orig, [0 => 'Word', 1 => null, 2 => 'word']), 'one failure fails the unit');
});

test('Google hyphens become em dashes again, unless the original had its own hyphen', function () {
    assert_eq('• Z — enhanced attack', tr_fix_dashes('• Z - enhanced attack', '• Z — усиленная атака'));
    assert_eq('5 - 3 — two', tr_fix_dashes('5 - 3 — two', '5 - 3 — два'));
    assert_eq('a - b', tr_fix_dashes('a - b', 'а - б'));
});

test('span keys ignore key order and false flags', function () {
    assert_eq(tr_spans_key([['b' => true, 's' => 'x']]), tr_spans_key([['s' => 'x', 'b' => true]]));
    assert_eq(tr_spans_key([['s' => 'x', 'b' => false]]), tr_spans_key([['s' => 'x']]));
    assert_true(tr_spans_key([['s' => 'x', 'b' => true]]) !== tr_spans_key([['s' => 'x']]), 'format matters');
});

// ------------------------------ Запросы ---------------------------------

test('short texts share one request and come back in order', function () {
    $calls = [];
    $out = tr_translate_texts(['a' => 'Один', 'b' => 'Два', 'c' => 'Три'], fake_translator($calls), far_future());
    assert_eq(['a' => 'EN:Один', 'b' => 'EN:Два', 'c' => 'EN:Три'], $out);
    assert_eq(1, count($calls), 'one request');
});

test('a text with its own line break travels alone', function () {
    $calls = [];
    $out = tr_translate_texts(['a' => 'Один', 'b' => "Два\nстроки", 'c' => 'Три'], fake_translator($calls), far_future());
    assert_eq(['a' => 'EN:Один', 'b' => "EN:Два\nстроки", 'c' => 'EN:Три'], $out);
    assert_eq(3, count($calls), 'the multi-line text split the batch');
});

test('a batch over the size ceiling is split into several requests', function () {
    $calls = [];
    $long = str_repeat('я', 3000);
    $out = tr_translate_texts(['a' => $long, 'b' => $long, 'c' => 'Три'], fake_translator($calls), far_future());
    assert_eq(2, count($calls), 'two requests');
    assert_eq('EN:Три', $out['c']);
    foreach ($calls as $c) { assert_true(mb_strlen($c) <= TR_CHUNK_CHARS, 'each request under the ceiling'); }
});

test('when Google merges paragraphs, the batch is retried one text at a time', function () {
    $calls = [];
    $merge = function (string $t) use (&$calls): ?string {
        $calls[] = $t;
        return 'EN:' . str_replace(TR_SEP, ' ', $t);
    };
    $out = tr_translate_texts(['a' => 'Один', 'b' => 'Два'], $merge, far_future());
    assert_eq(['a' => 'EN:Один', 'b' => 'EN:Два'], $out);
    assert_eq(3, count($calls), 'one batch and two singles');
});

test('two failed requests in a row stop the whole run', function () {
    $calls = [];
    $down = function (string $t) use (&$calls): ?string { $calls[] = $t; return null; };
    $texts = [];
    for ($i = 0; $i < 5; $i++) { $texts[] = "Строка\n" . $i; }
    $out = tr_translate_texts($texts, $down, far_future());
    assert_eq(array_fill(0, 5, null), $out);
    assert_eq(2, count($calls), 'gave up after two failures');
});

test('an exhausted time budget sends nothing at all', function () {
    $calls = [];
    $out = tr_translate_texts(['a' => 'Один'], fake_translator($calls), microtime(true) - 1);
    assert_eq(['a' => null], $out);
    assert_eq(0, count($calls));
});

test('the Google reply is parsed into one string', function () {
    $raw = '{"sentences":[{"trans":"Hello, ","orig":"Привет, "},{"trans":"world.","orig":"мир."}],"src":"ru"}';
    assert_eq('Hello, world.', tr_google_parse($raw));
    assert_eq(null, tr_google_parse('<html><title>Sorry...</title></html>'), 'the 429 page is not a translation');
    assert_eq(null, tr_google_parse('{"sentences":[]}'), 'empty');
});

// -------------------------- Сохранение поста ----------------------------

test('a Russian-only post is published with English filled in everywhere', function () {
    $pdo = test_db();
    $calls = [];
    $blocks = [
        tr_p([tr_s('Новый '), tr_s('фрукт', ['b' => true]), tr_s(' уже в игре.')]),
        ['t' => 'quote', 'ru' => [tr_s('Цитата')], 'en' => [], 'collapsible' => false],
        ['t' => 'list', 'ordered' => false, 'items' => [
            ['ru' => [tr_s('Пункт один')], 'en' => []],
            ['ru' => [tr_s('Пункт два')], 'en' => []],
        ]],
        ['t' => 'code', 'ru' => '/spin', 'en' => ''],
        ['t' => 'image', 'url' => tr_img(), 'w' => 800, 'h' => 600, 'pct' => 100,
         'align' => 'center', 'wrap' => false, 'cap_ru' => [tr_s('Подпись')], 'cap_en' => []],
    ];
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc($blocks),
    ], 1000, fake_translator($calls));
    assert_eq(200, $status);
    assert_eq(6, $p['translated'], 'title, paragraph, quote, two items, caption');
    assert_eq(0, $p['translate_failed']);
    assert_eq(1, count($calls), 'the whole post in one request');

    $row = saved_row($pdo, $p['id']);
    assert_eq('EN:Заголовок', $row['title_en']);
    assert_eq([tr_s('EN:Новый '), tr_s('фрукт', ['b' => true]), tr_s(' уже в игре.')], $row['blocks'][0]['en'], 'bold kept');
    assert_eq([tr_s('EN:Цитата')], $row['blocks'][1]['en']);
    assert_eq([tr_s('EN:Пункт один')], $row['blocks'][2]['items'][0]['en']);
    assert_eq([tr_s('EN:Пункт два')], $row['blocks'][2]['items'][1]['en']);
    assert_eq('', $row['blocks'][3]['en'], 'code is left alone');
    assert_eq([tr_s('EN:Подпись')], $row['blocks'][4]['cap_en']);
    assert_true(strpos($row['body_en'], 'EN:Цитата') !== false, 'body_en is derived from the translated blocks');
});

test('spacer paragraphs are not sent to Google and do not break the rest of the post', function () {
    $pdo = test_db();
    $calls = [];
    $google = function (string $t) use (&$calls): ?string {
        $calls[] = $t;
        if (trim(str_replace("\u{00A0}", '', $t)) === '') { return ''; }
        $out = [];
        foreach (explode(TR_SEP, $t) as $p) { $out[] = 'EN:' . $p; }
        return implode(TR_SEP, $out);
    };
    $spacer = tr_para("\u{00A0}");
    $blocks = [tr_para('Раз'), $spacer, $spacer, tr_para("Два\nтри"), $spacer, $spacer, tr_para('Четыре')];
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'project', 'title_ru' => 'Отступы', 'title_en' => 'Spacers', 'body_json' => tr_doc($blocks),
    ], 1000, $google);
    assert_eq(200, $status);
    assert_eq(3, $p['translated'], 'three real paragraphs');
    assert_eq(0, $p['translate_failed']);
    foreach ($calls as $c) { assert_true(strpos($c, "\u{00A0}") === false, 'no spacer in a request'); }

    $row = saved_row($pdo, $p['id']);
    assert_eq([tr_s("EN:Два\nтри")], $row['blocks'][3]['en'], 'the line break survives');
    assert_eq([], $row['blocks'][1]['en'], 'a spacer keeps no English of its own');
    assert_eq([tr_s('EN:Четыре')], $row['blocks'][6]['en']);
});

test('English written by hand is never replaced', function () {
    $pdo = test_db();
    $calls = [];
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => 'My title',
        'body_json' => tr_doc([tr_para('Абзац', 'My paragraph'), tr_para('Второй')]),
    ], 1000, fake_translator($calls));
    $row = saved_row($pdo, $p['id']);
    assert_eq('My title', $row['title_en']);
    assert_eq([tr_s('My paragraph')], $row['blocks'][0]['en']);
    assert_eq([tr_s('EN:Второй')], $row['blocks'][1]['en'], 'only the empty one is filled');
    assert_eq(1, $p['translated']);
    assert_eq(['Второй'], $calls, 'nothing else was sent');
});

test('editing the Russian text re-translates the English that was left behind', function () {
    $pdo = test_db();
    $calls = [];
    $tr = fake_translator($calls);
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Старый заголовок',
        'body_json' => tr_doc([tr_para('Первый'), tr_para('Второй'), tr_para('Третий', 'Hand-made')]),
    ], 1000, $tr);
    $id = $p['id'];
    $saved = saved_row($pdo, $id);

    // Редактор шлёт документ целиком: EN берёт из открытого поста как был.
    $blocks = $saved['blocks'];
    $blocks[0]['ru'] = [tr_s('Первый, исправленный')];
    $blocks[2]['ru'] = [tr_s('Третий, исправленный')];
    $blocks[2]['en'] = [tr_s('Hand-made, fixed too')];
    $calls = [];
    [$status, $p2] = handle_news_save($pdo, [
        'id' => $id, 'category' => 'game', 'title_ru' => 'Новый заголовок', 'title_en' => $saved['title_en'],
        'body_json' => tr_doc($blocks),
    ], 1000, $tr);
    assert_eq(200, $status);
    $row = saved_row($pdo, $id);
    assert_eq('EN:Новый заголовок', $row['title_en'], 'stale title re-translated');
    assert_eq([tr_s('EN:Первый, исправленный')], $row['blocks'][0]['en'], 'stale paragraph re-translated');
    assert_eq([tr_s('EN:Второй')], $row['blocks'][1]['en'], 'untouched paragraph kept');
    assert_eq([tr_s('Hand-made, fixed too')], $row['blocks'][2]['en'], 'English edited together with Russian is kept');
    assert_eq(2, $p2['translated']);
});

test('a paragraph whose Russian was wiped does not linger in English', function () {
    $pdo = test_db();
    $calls = [];
    $tr = fake_translator($calls);
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок',
        'body_json' => tr_doc([tr_para('Остаётся'), tr_para('Стёрли')]),
    ], 1000, $tr);
    $blocks = saved_row($pdo, $p['id'])['blocks'];
    $blocks[1]['ru'] = [];
    handle_news_save($pdo, [
        'id' => $p['id'], 'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => 'EN:Заголовок',
        'body_json' => tr_doc($blocks),
    ], 1000, $tr);
    $row = saved_row($pdo, $p['id']);
    assert_eq(1, count($row['blocks']), 'the emptied paragraph is gone');
    assert_eq('EN:Остаётся', $row['body_en']);
});

test('a list item whose Russian was wiped goes, the rest of the list stays', function () {
    $pdo = test_db();
    $calls = [];
    $tr = fake_translator($calls);
    $list = ['t' => 'list', 'ordered' => true, 'items' => [
        ['ru' => [tr_s('Один')], 'en' => []], ['ru' => [tr_s('Два')], 'en' => []],
    ]];
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc([tr_para('Абзац'), $list]),
    ], 1000, $tr);
    $blocks = saved_row($pdo, $p['id'])['blocks'];
    $blocks[1]['items'][0]['ru'] = [];
    handle_news_save($pdo, [
        'id' => $p['id'], 'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => 'EN:Заголовок',
        'body_json' => tr_doc($blocks),
    ], 1000, $tr);
    $items = saved_row($pdo, $p['id'])['blocks'][1]['items'];
    assert_eq(1, count($items));
    assert_eq([tr_s('EN:Два')], $items[0]['en']);
});

test('a legacy post edited in the block editor gets its stale paragraph re-translated', function () {
    $pdo = test_db();
    $pdo->exec("INSERT INTO news (category, title_ru, title_en, body_ru, body_en, published_at)
                VALUES ('game', 'Заголовок', 'Title', 'Раз\n\nДва', 'One\n\nTwo', 1)");
    $id = (int)$pdo->lastInsertId();
    // Так пост превращает в блоки blocksFromLegacy() в js/news-editor.js.
    $blocks = [tr_para('Раз, исправлено', 'One'), tr_para('Два', 'Two')];
    $calls = [];
    handle_news_save($pdo, [
        'id' => $id, 'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => 'Title',
        'body_json' => tr_doc($blocks),
    ], 1000, fake_translator($calls));
    $row = saved_row($pdo, $id);
    assert_eq([tr_s('EN:Раз, исправлено')], $row['blocks'][0]['en']);
    assert_eq([tr_s('Two')], $row['blocks'][1]['en']);
    assert_eq('Title', $row['title_en']);
});

test('a dead translator still saves the post, just without English', function () {
    $pdo = test_db();
    $down = function (string $t): ?string { return null; };
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc([tr_para('Абзац')]),
    ], 1000, $down);
    assert_eq(200, $status);
    assert_eq(0, $p['translated']);
    assert_eq(2, $p['translate_failed']);
    $row = saved_row($pdo, $p['id']);
    assert_eq('', $row['title_en']);
    assert_eq([], $row['blocks'][0]['en']);
    assert_eq('Абзац', $row['body_en'], 'readers fall back to Russian, as before');
});

test('broken markup from Google falls back to span-by-span translation', function () {
    $pdo = test_db();
    $calls = [];
    $sloppy = function (string $t) use (&$calls): ?string {
        $calls[] = $t;
        // Первый запрос (с тегами) теряет закрывающий тег, остальные — честные.
        if (strpos($t, '<x1>') !== false) { return str_replace('</x1>', '', 'EN:' . $t); }
        return implode(TR_SEP, array_map(function ($p) { return 'EN:' . $p; }, explode(TR_SEP, $t)));
    };
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => 'Title',
        'body_json' => tr_doc([tr_p([tr_s('Слово '), tr_s('жирное', ['b' => true]), tr_s(' слово')])]),
    ], 1000, $sloppy);
    assert_eq(1, $p['translated']);
    assert_eq([tr_s('EN:Слово '), tr_s('EN:жирное', ['b' => true]), tr_s(' EN:слово')],
        saved_row($pdo, $p['id'])['blocks'][0]['en']);
});

test('a translation that would overflow the size ceiling is dropped, not a 400', function () {
    $pdo = test_db();
    $blocks = [];
    for ($i = 0; $i < 70; $i++) { $blocks[] = tr_para(str_repeat('ж', 240)); }
    $huge = function (string $t): ?string {
        return implode(TR_SEP, array_map(function ($p) { return str_repeat('W', 900); }, explode(TR_SEP, $t)));
    };
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc($blocks),
    ], 1000, $huge);
    assert_eq(200, $status, 'the post is saved');
    assert_true($p['translate_failed'] >= 1, 'and the failure is reported');
    $row = saved_row($pdo, $p['id']);
    assert_eq([], $row['blocks'][0]['en'], 'blocks stored untranslated');
});

test('a legacy post body is translated paragraph by paragraph', function () {
    $pdo = test_db();
    $calls = [];
    [, $p] = handle_news_save($pdo, ['category' => 'game', 'title_ru' => 'Апдейт 26', 'body_ru' => "Первый.\n\nВторой."], 1000, fake_translator($calls));
    $row = saved_row($pdo, $p['id']);
    assert_eq("EN:Первый.\n\nEN:Второй.", $row['body_en']);
    assert_eq('EN:Апдейт 26', $row['title_en']);
});

test('without a translator the save behaves exactly as before', function () {
    $pdo = test_db();
    [$status, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc([tr_para('Абзац')]),
    ], 1000);
    assert_eq(200, $status);
    assert_eq(['ok', 'id'], array_keys($p), 'no translation counters in the reply');
    assert_eq('', saved_row($pdo, $p['id'])['title_en']);
});

test('saving an unchanged, fully translated post sends nothing to Google', function () {
    $pdo = test_db();
    $calls = [];
    $tr = fake_translator($calls);
    [, $p] = handle_news_save($pdo, [
        'category' => 'game', 'title_ru' => 'Заголовок', 'body_json' => tr_doc([tr_para('Абзац')]),
    ], 1000, $tr);
    $row = saved_row($pdo, $p['id']);
    $calls = [];
    [$status, $p2] = handle_news_save($pdo, [
        'id' => $p['id'], 'category' => 'game', 'title_ru' => 'Заголовок', 'title_en' => $row['title_en'],
        'body_json' => tr_doc($row['blocks']),
    ], 1000, $tr);
    assert_eq(200, $status);
    assert_eq([], $calls);
    assert_eq(0, $p2['translated']);
});

run_tests();
