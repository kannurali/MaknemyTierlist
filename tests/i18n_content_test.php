<?php
// Переводы контента на языки сайта: места с текстом, одноязычная выдача,
// фоновый перевод недостающего (public_html/api/lib/i18n_content.php).
// Run: php tests/i18n_content_test.php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/i18n_content.php';

// Подделка Google: каждый абзац запроса получает приставку языка, теги <xN>
// остаются на месте. $calls копит [язык, текст].
function ic_translator(array &$calls): callable {
    return function (string $lang) use (&$calls): callable {
        return function (string $text) use ($lang, &$calls): ?string {
            $calls[] = [$lang, $text];
            $out = [];
            foreach (explode(TR_SEP, $text) as $p) { $out[] = '[' . $lang . ']' . $p; }
            return implode(TR_SEP, $out);
        };
    };
}

function ic_far(): float { return microtime(true) + 60; }
function ic_s(string $s, array $f = []): array { return ['s' => $s] + $f; }
function ic_p(string $ru, string $en = ''): array {
    return ['t' => 'p', 'ru' => [ic_s($ru)], 'en' => $en === '' ? [] : [ic_s($en)]];
}

function ic_news(PDO $pdo, string $title, array $blocks, string $titleEn = ''): int {
    $pdo->prepare(
        "INSERT INTO news (category, title_ru, title_en, body_ru, body_en, body_json, published_at)
         VALUES ('game', :t, :te, :b, '', :j, 1000)"
    )->execute([
        ':t' => $title, ':te' => $titleEn, ':b' => news_blocks_plain($blocks, 'ru'),
        ':j' => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE),
    ]);
    return (int)$pdo->lastInsertId();
}

function ic_news_update(PDO $pdo, int $id, string $title, array $blocks): void {
    $pdo->prepare("UPDATE news SET title_ru = :t, body_ru = :b, body_json = :j WHERE id = :id")->execute([
        ':t' => $title, ':b' => news_blocks_plain($blocks, 'ru'),
        ':j' => json_encode(['v' => 1, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE), ':id' => $id,
    ]);
}

function ic_rows(PDO $pdo, string $kind = 'news'): array {
    $st = $pdo->prepare("SELECT ref_id, field, lang, text FROM i18n_texts WHERE kind = ? ORDER BY ref_id, field, lang");
    $st->execute([$kind]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function ic_tierlist(PDO $pdo, array $items): void {
    $tl = ['tiers' => [['id' => 't1', 'label' => 'S', 'items' => $items]]];
    $pdo->prepare("UPDATE tierlist SET data = :d, rev = 5 WHERE id = 1")
        ->execute([':d' => json_encode($tl, JSON_UNESCAPED_UNICODE)]);
}

// ------------------------------- Места -------------------------------

test('a post is split into its title and one place per paragraph, list item and caption', function () {
    $blocks = [
        ic_p('Абзац'),
        ['t' => 'quote', 'ru' => [ic_s('Цитата')], 'en' => [], 'collapsible' => false],
        ['t' => 'list', 'ordered' => false, 'items' => [['ru' => [ic_s('Раз')], 'en' => []], ['ru' => [ic_s('Два')], 'en' => []]]],
        ['t' => 'code', 'ru' => 'x = 1', 'en' => ''],
        ['t' => 'image', 'url' => '/images/' . str_repeat('a', 40) . '.webp', 'w' => 10, 'h' => 10, 'pct' => 100,
         'align' => 'center', 'wrap' => false, 'cap_ru' => [ic_s('Подпись')], 'cap_en' => []],
        ic_p("\u{00A0}"),
    ];
    $u = i18n_units_news('Заголовок', $blocks, '');
    assert_eq(['title', 'b0', 'b1', 'b2.0', 'b2.1', 'b4'], array_keys($u), 'code and the NBSP spacer are not places');
    assert_eq(['ru' => 'Заголовок', 'spans' => false], $u['title']);
    assert_eq(['ru' => [ic_s('Подпись')], 'spans' => true], $u['b4']);
});

test('an old-format post is split into paragraphs', function () {
    $u = i18n_units_news('Т', null, "Один\n\nДва");
    assert_eq(['title', 'p0', 'p1'], array_keys($u));
    assert_eq('Два', $u['p1']['ru']);
});

test('a giveaway adds its own conditions and their buttons', function () {
    $u = i18n_units_gw('Розыгрыш', [ic_p('Текст')], [['ru' => 'Подпишись', 'btn_ru' => 'Канал'], ['ru' => 'Лайк', 'btn_ru' => '']]);
    assert_eq(['title', 'b0', 'c0', 'c0.btn', 'c1'], array_keys($u));
});

test('items give desc and terms, with a note of a manual EN', function () {
    $u = i18n_units_items(['tiers' => [['items' => [
        ['id' => 'a1', 'desc' => 'Описание', 'descEn' => 'Description', 'terms' => ''],
        ['id' => 'a2', 'desc' => ' ', 'terms' => 'Условия'],
        ['id' => '', 'desc' => 'Без id'],
        ['desc' => 'Тоже без id'],
    ]]]]);
    assert_eq(['a1', 'a2'], array_keys($u));
    assert_eq(['desc'], array_keys($u['a1']));
    assert_eq(true, $u['a1']['desc']['manual']['en']);
    assert_eq(['terms'], array_keys($u['a2']));
    assert_eq(false, $u['a2']['terms']['manual']['en']);
});

test('only the site languages pass as ?lang=', function () {
    assert_eq('de', i18n_api_lang('de'));
    assert_eq('ru', i18n_api_lang('ru'));
    foreach (['', 'DE', 'xx', 'de ', null, ['de'], 1] as $bad) {
        assert_eq(null, i18n_api_lang($bad), var_export($bad, true));
    }
});

// --------------------------- Одноязычная выдача ---------------------------

function ic_post(array $blocks, string $title = 'Заголовок', string $titleEn = ''): array {
    return [
        'id' => 7, 'title_ru' => $title, 'title_en' => $titleEn,
        'body_ru' => news_blocks_plain($blocks, 'ru'), 'body_en' => news_blocks_plain($blocks, 'en'),
        'body_json' => ['v' => 1, 'blocks' => $blocks],
    ];
}

function ic_row($ru, string $text): array { return ['hash' => i18n_hash($ru), 'text' => $text]; }

test('German: translation first, then EN, then RU, the Russian slot left empty', function () {
    $post = ic_post([ic_p('Один', 'One'), ic_p('Два', 'Two'), ic_p('Три')], 'Заголовок', 'Title');
    $rows = [
        'title' => ic_row('Заголовок', 'Titel'),
        'b0' => ic_row([ic_s('Один')], json_encode([ic_s('Eins')])),
    ];
    $out = i18n_news_localize($post, 'de', $rows);
    assert_eq(['', 'Titel'], [$out['title_ru'], $out['title_en']]);
    $b = $out['body_json']['blocks'];
    assert_eq([ic_s('Eins')], $b[0]['en'], 'translated');
    assert_eq([ic_s('Two')], $b[1]['en'], 'EN when there is no German');
    assert_eq([ic_s('Три')], $b[2]['en'], 'RU when there is no EN either');
    assert_eq([[], [], []], [$b[0]['ru'], $b[1]['ru'], $b[2]['ru']], 'one language only');
    assert_eq("Eins\n\nTwo\n\nТри", $out['body_en']);
    assert_eq('', $out['body_ru']);
    assert_eq(true, news_blocks_validate($out['body_json'])['ok'], 'still a valid document');
});

test('a translation of an older Russian text is not shown', function () {
    $post = ic_post([ic_p('Новый текст', 'New text')]);
    $out = i18n_news_localize($post, 'de', ['b0' => ic_row([ic_s('Старый текст')], json_encode([ic_s('Alter Text')]))]);
    assert_eq([ic_s('New text')], $out['body_json']['blocks'][0]['en']);
});

test('a translation with broken spans is not shown', function () {
    $post = ic_post([ic_p('Текст')]);
    $out = i18n_news_localize($post, 'de', ['b0' => ic_row([ic_s('Текст')], '[{"x":1}]')]);
    assert_eq([ic_s('Текст')], $out['body_json']['blocks'][0]['en']);
});

test('Russian: the Russian slot, falling back to EN, the English slot empty', function () {
    $post = ic_post([ic_p('Один', 'One'), ['t' => 'p', 'ru' => [], 'en' => [ic_s('Only EN')]]], 'Заголовок', 'Title');
    $out = i18n_news_localize($post, 'ru', []);
    assert_eq(['Заголовок', ''], [$out['title_ru'], $out['title_en']]);
    $b = $out['body_json']['blocks'];
    assert_eq([[ic_s('Один')], []], [$b[0]['ru'], $b[0]['en']]);
    assert_eq([[ic_s('Only EN')], []], [$b[1]['ru'], $b[1]['en']]);
});

test('English pages keep the manual EN from the columns', function () {
    $post = ic_post([ic_p('Один', 'One')], 'Заголовок', 'Title');
    $out = i18n_news_localize($post, 'en', []);
    assert_eq('Title', $out['title_en']);
    assert_eq([ic_s('One')], $out['body_json']['blocks'][0]['en']);
});

test('an old-format post shows the translation only when every paragraph has one', function () {
    $post = ['id' => 1, 'title_ru' => 'Т', 'title_en' => '', 'body_ru' => "Один\n\nДва", 'body_en' => "One\n\nTwo", 'body_json' => null];
    $all = ['p0' => ic_row('Один', 'Eins'), 'p1' => ic_row('Два', 'Zwei')];
    assert_eq("Eins\n\nZwei", i18n_news_localize($post, 'de', $all)['body_en']);
    assert_eq("One\n\nTwo", i18n_news_localize($post, 'de', ['p0' => $all['p0']])['body_en']);
    assert_eq(null, i18n_news_localize($post, 'de', $all)['body_json']);
});

test('a giveaway is localized field by field, conditions and buttons included', function () {
    $item = [
        'id' => 3, 'title' => ['ru' => 'Розыгрыш', 'en' => 'Giveaway'], 'body' => [ic_p('Текст', 'Text')],
        'conds' => [['text' => ['ru' => 'Подпишись', 'en' => 'Subscribe'], 'url' => 'https://t.me/x', 'btn' => ['ru' => 'Канал', 'en' => ''], 'chat' => '']],
        'prizes' => [['name' => 'Kitsune', 'count' => 1, 'icon' => '']],
    ];
    $rows = ['title' => ic_row('Розыгрыш', 'Gewinnspiel'), 'c0.btn' => ic_row('Канал', 'Kanal')];
    $out = i18n_gw_localize($item, 'de', $rows);
    assert_eq(['ru' => '', 'en' => 'Gewinnspiel'], $out['title']);
    assert_eq(['ru' => '', 'en' => 'Subscribe'], $out['conds'][0]['text']);
    assert_eq(['ru' => '', 'en' => 'Kanal'], $out['conds'][0]['btn']);
    assert_eq([ic_s('Text')], $out['body'][0]['en']);
    assert_eq('https://t.me/x', $out['conds'][0]['url']);
    assert_eq($item['prizes'], $out['prizes']);

    $ru = i18n_gw_localize($item, 'ru', []);
    assert_eq(['ru' => 'Розыгрыш', 'en' => ''], $ru['title']);
    assert_eq(['ru' => 'Канал', 'en' => ''], $ru['conds'][0]['btn']);
});

test('the tier list: German descriptions, EN and auto-EN as fallbacks, a flag when something is missing', function () {
    $tl = ['tiers' => [['items' => [
        ['id' => 'a', 'name' => 'A', 'desc' => 'Один', 'descEn' => 'One', 'terms' => 'Условия', 'termsEn' => '', 'tag' => 'LIMITED', 'tagEn' => 'LIMITED'],
        ['id' => 'b', 'name' => 'B', 'desc' => 'Два', 'descEn' => '', 'terms' => '', 'termsEn' => ''],
    ]]]];
    $de = ['a' => ['desc' => ic_row('Один', 'Eins'), 'terms' => ic_row('Условия', 'Bedingungen')]];
    $en = ['b' => ['desc' => ic_row('Два', 'Two (auto)')]];

    [$out, $done] = i18n_tierlist_localize($tl, 'de', $de, $en);
    $a = $out['tiers'][0]['items'][0];
    $b = $out['tiers'][0]['items'][1];
    assert_eq(['', 'Eins', '', 'Bedingungen'], [$a['desc'], $a['descEn'], $a['terms'], $a['termsEn']]);
    assert_eq(['', 'Two (auto)'], [$b['desc'], $b['descEn']], 'auto-EN when German is missing');
    assert_eq(['LIMITED', 'LIMITED'], [$a['tag'], $a['tagEn']], 'tags untouched');
    assert_eq(false, $done, 'b has no German yet');

    [$out, $done] = i18n_tierlist_localize($tl, 'de', $de + ['b' => ['desc' => ic_row('Два', 'Zwei')]], $en);
    assert_eq(true, $done);
    assert_eq('Zwei', $out['tiers'][0]['items'][1]['descEn']);

    [$out, $done] = i18n_tierlist_localize($tl, 'en', $en);
    assert_eq(['One', 'Two (auto)'], [$out['tiers'][0]['items'][0]['descEn'], $out['tiers'][0]['items'][1]['descEn']]);
    assert_eq(false, $done, 'terms of a have no EN');

    [$out, $done] = i18n_tierlist_localize($tl, 'ru', []);
    assert_eq(['Один', ''], [$out['tiers'][0]['items'][0]['desc'], $out['tiers'][0]['items'][0]['descEn']]);
    assert_eq(true, $done, 'Russian needs no translation');
});

// --------------------------- Перевод недостающего ---------------------------

test('a new post is translated into the eight languages, an unchanged one costs nothing', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Заголовок', [ic_p('Новый '), ['t' => 'p', 'ru' => [ic_s('Жирный', ['b' => true])], 'en' => []]]);
    $calls = [];
    $st = i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    assert_eq(8, count($calls), 'one batch per language');
    assert_eq(I18N_CONTENT_LANGS, array_column($calls, 0));
    assert_eq(['filled' => 24, 'reused' => 0, 'failed' => 0], $st);

    $rows = i18n_rows($pdo, 'news', 'de', [$id]);
    assert_eq('[de]Заголовок', $rows[(string)$id]['title']['text']);
    assert_eq([ic_s('[de]'), ic_s('Жирный', ['b' => true])], json_decode($rows[(string)$id]['b1']['text'], true),
        'formatting survives');

    $calls = [];
    i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    assert_eq([], $calls);
});

test('an edited paragraph is retranslated, a moved one is reused, a removed one is pruned', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Т', [ic_p('Первый'), ic_p('Второй'), ic_p('Третий')]);
    $calls = [];
    i18n_job_news($pdo, $id, ic_translator($calls), ic_far());

    ic_news_update($pdo, $id, 'Т', [ic_p('Новый'), ic_p('Первый'), ic_p('Второй исправлен')]);
    $calls = [];
    $st = i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    assert_eq(['filled' => 16, 'reused' => 8, 'failed' => 0], $st);
    foreach ($calls as [$lang, $text]) {
        assert_eq("Новый\n\nВторой исправлен", $text, "$lang: only the new texts go to Google");
    }
    $de = i18n_rows($pdo, 'news', 'de', [$id])[(string)$id];
    assert_eq([ic_s('[de]Первый')], json_decode($de['b1']['text'], true), 'moved paragraph keeps its translation');
    assert_eq([ic_s('[de]Второй исправлен')], json_decode($de['b2']['text'], true));

    ic_news_update($pdo, $id, 'Т', [ic_p('Новый')]);
    $calls = [];
    i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    assert_eq([], $calls);
    $fields = array_keys(i18n_rows($pdo, 'news', 'de', [$id])[(string)$id]);
    sort($fields);
    assert_eq(['b0', 'title'], $fields, 'rows of removed paragraphs are gone');
});

test('a translation that finished after the text changed again is not written', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Старый', [ic_p('Абзац')]);
    $calls = [];
    $tr = function (string $lang) use ($pdo, $id, &$calls): callable {
        return function (string $text) use ($lang, $pdo, $id, &$calls): ?string {
            $calls[] = $lang;
            ic_news_update($pdo, $id, 'Новый', [ic_p('Абзац')]);
            $out = [];
            foreach (explode(TR_SEP, $text) as $p) { $out[] = '[' . $lang . ']' . $p; }
            return implode(TR_SEP, $out);
        };
    };
    i18n_job_news($pdo, $id, $tr, ic_far());
    $de = i18n_rows($pdo, 'news', 'de', [$id])[(string)$id];
    assert_eq(false, isset($de['title']), 'the old title translation is not stored');
    assert_eq(true, isset($de['b0']), 'the unchanged paragraph is');
});

test('Google failing leaves nothing behind and is counted', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Заголовок', [ic_p('Абзац')]);
    $down = function (string $lang): callable { return function (string $t): ?string { return null; }; };
    $st = i18n_job_news($pdo, $id, $down, ic_far());
    assert_eq(16, $st['failed']);
    assert_eq([], ic_rows($pdo));
});

test('nothing happens before the migration', function () {
    $pdo = test_db();
    $pdo->exec('DROP TABLE i18n_texts');
    $id = ic_news($pdo, 'Заголовок', [ic_p('Абзац')]);
    $calls = [];
    $st = i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    assert_eq([], $calls);
    assert_eq(['filled' => 0, 'reused' => 0, 'failed' => 0], $st);
    assert_eq([], i18n_rows($pdo, 'news', 'de', [$id]));
    i18n_forget($pdo, 'news', (string)$id);
});

test('items: manual EN is not translated, removed items lose their rows', function () {
    $pdo = test_db();
    ic_tierlist($pdo, [
        ['id' => 'a', 'desc' => 'Один', 'descEn' => 'One'],
        ['id' => 'b', 'desc' => 'Два', 'descEn' => '', 'terms' => 'Условия'],
    ]);
    $calls = [];
    $st = i18n_job_items($pdo, ic_translator($calls), ic_far());
    assert_eq(['filled' => 2 + 3 * 8, 'reused' => 0, 'failed' => 0], $st);
    assert_eq("Два\n\nУсловия", $calls[0][1], 'EN only for b');

    ic_tierlist($pdo, [['id' => 'b', 'desc' => 'Два', 'descEn' => '', 'terms' => '']]);
    $calls = [];
    i18n_job_items($pdo, ic_translator($calls), ic_far());
    assert_eq([], $calls);
    $left = array_unique(array_map(function ($r) { return $r['ref_id'] . '/' . $r['field']; }, ic_rows($pdo, 'item')));
    assert_eq(['b/desc'], array_values($left));
});

test('the deadline stops the job between languages', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Заголовок', [ic_p('Абзац')]);
    $calls = [];
    i18n_job_news($pdo, $id, ic_translator($calls), microtime(true) - 1);
    assert_eq([], $calls);
});

test('the job after a save gets the time budget and never throws', function () {
    $got = null;
    i18n_after_save(function (float $deadline) use (&$got) { $got = $deadline; });
    assert_true($got !== null && abs($got - (microtime(true) + I18N_BG_BUDGET)) < 5, 'deadline is now + budget');
    i18n_after_save(function (float $deadline) { throw new RuntimeException('Google down'); });
    assert_true(true, 'the exception stayed inside');
});

test('forgetting a post removes all its translations', function () {
    $pdo = test_db();
    $id = ic_news($pdo, 'Заголовок', [ic_p('Абзац')]);
    $calls = [];
    i18n_job_news($pdo, $id, ic_translator($calls), ic_far());
    i18n_forget($pdo, 'news', (string)$id);
    assert_eq([], ic_rows($pdo));
});

run_tests();
