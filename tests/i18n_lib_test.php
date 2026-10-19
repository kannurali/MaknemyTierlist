<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';

$PUB = dirname(__DIR__) . '/public_html';

function with_uri(?string $uri, callable $fn) {
    $had = array_key_exists('REQUEST_URI', $_SERVER);
    $old = $had ? $_SERVER['REQUEST_URI'] : null;
    if ($uri === null) { unset($_SERVER['REQUEST_URI']); } else { $_SERVER['REQUEST_URI'] = $uri; }
    try { return $fn(); }
    finally { if ($had) { $_SERVER['REQUEST_URI'] = $old; } else { unset($_SERVER['REQUEST_URI']); } }
}

test('язык берётся из приставки адреса', function () {
    assert_eq('ru', i18n_lang_from_uri('/'));
    assert_eq('ru', i18n_lang_from_uri('/tierlist'));
    assert_eq('en', i18n_lang_from_uri('/en'));
    assert_eq('en', i18n_lang_from_uri('/en/tierlist?x=1'));
    assert_eq('en', i18n_lang_from_uri('/en/news/12'));
    assert_eq('ru', i18n_lang_from_uri('/ru/tierlist'), 'у русского приставки нет');
    assert_eq('ru', i18n_lang_from_uri('/de/tierlist'), 'неизвестный язык');
    assert_eq('ru', i18n_lang_from_uri('/english'), 'en только целым сегментом');
    assert_eq('ru', i18n_lang_from_uri('/api/news.php'));
    assert_eq('ru', i18n_lang_from_uri('//'), 'мусор в адресе');
});

test('без запроса язык русский, с /en/ — английский', function () {
    assert_eq('ru', with_uri(null, 'i18n_lang'));
    assert_eq('en', with_uri('/en/calculator', 'i18n_lang'));
});

test('t: язык, подстановки, откат на ключ', function () {
    assert_eq('Все', t('filters.all', [], 'ru'));
    assert_eq('All', t('filters.all', [], 'en'));
    assert_eq('nope.key', t('nope.key', [], 'en'));
    assert_eq('All', with_uri('/en/tierlist', function () { return t('filters.all'); }));
    $s = t('msg.confirmDeleteTier', ['tier' => 'S', 'count' => 3], 'en');
    assert_true(strpos($s, 'S') !== false && strpos($s, '3') !== false && strpos($s, '{') === false, $s);
    assert_true(strpos(t('msg.confirmDeleteTier', [], 'ru'), '{tier}') !== false, 'неизвестная подстановка видна');
    assert_eq('constructor', t('constructor', [], 'en'));
});

test('словарь языка дополняется английским и русским', function () {
    assert_eq([], array_values(array_diff(array_keys(i18n_dict('ru')), array_keys(i18n_dict('en')))),
        'в английском словаре есть все русские ключи');
    assert_true(count(i18n_dict('ru')) > 500, 'словарь прочитан');
    assert_eq(i18n_dict('ru'), i18n_dict('xx'), 'неизвестный язык — русский словарь');
});

test('te экранирует только текст, ta — и кавычки', function () {
    $nick = '<b>"x"</b>';
    $te = te('chat.deleteConfirm', ['nick' => $nick]);
    $ta = ta('chat.deleteConfirm', ['nick' => $nick]);
    assert_true(strpos($te, '&lt;b&gt;"x"&lt;/b&gt;') !== false, $te);
    assert_true(strpos($ta, '&lt;b&gt;&quot;x&quot;&lt;/b&gt;') !== false, $ta);
});

test('u и i18n_url добавляют приставку', function () {
    assert_eq('/tierlist', u('/tierlist', 'ru'));
    assert_eq('/en/tierlist', u('/tierlist', 'en'));
    assert_eq('/en', u('/', 'en'));
    assert_eq('/en/ads#pm-card', u('/ads#pm-card', 'en'));
    assert_eq('/en/profile?id=5', u('/profile?id=5', 'en'));
    assert_eq('/en?x=1', u('/?x=1', 'en'));
    assert_eq('/tierlist', u('/tierlist', 'xx'));
    assert_eq('/en/news', with_uri('/en/tierlist', function () { return u('/news'); }));
    assert_eq('https://maknemy.com/', i18n_url('/', 'ru'));
    assert_eq('https://maknemy.com/en', i18n_url('/', 'en'));
    assert_eq('https://maknemy.com/en/news/7', i18n_url('/news/7', 'en'));
});

test('og:locale по языку', function () {
    assert_eq('ru_RU', i18n_og_locale('ru'));
    assert_eq('en_US', i18n_og_locale('en'));
    assert_eq('ru_RU', i18n_og_locale('xx'));
});

test('hreflang: все языки и x-default на русский', function () {
    $h = with_uri('/en/tierlist', function () { return i18n_alternates('/tierlist'); });
    assert_true(strpos($h, '<link rel="alternate" hreflang="ru" href="https://maknemy.com/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<link rel="alternate" hreflang="en" href="https://maknemy.com/en/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<link rel="alternate" hreflang="x-default" href="https://maknemy.com/tierlist" />') !== false, $h);
    assert_true(strpos($h, '<meta property="og:locale:alternate" content="ru_RU" />') !== false, 'русская альтернатива');
    assert_eq(false, strpos($h, 'content="en_US"'), 'себя в альтернативы не пишет');
    assert_eq("\n", substr($h, -1), 'кончается переводом строки: PHP съедает тот, что после ?>');
    $root = with_uri('/', function () { return i18n_alternates('/'); });
    assert_true(strpos($root, 'hreflang="en" href="https://maknemy.com/en" />') !== false, $root);
});

test('словари закрыты от прямых запросов', function () use ($PUB) {
    assert_eq("Require all denied", trim((string)file_get_contents("$PUB/lang/.htaccess")));
});

test('bootstrap подключает i18n', function () use ($PUB) {
    assert_true(strpos((string)file_get_contents("$PUB/api/_bootstrap.php"),
        "require_once __DIR__ . '/lib/i18n.php';") !== false);
});

run_tests();
