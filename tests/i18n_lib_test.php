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
    assert_eq('de', i18n_lang_from_uri('/de/tierlist'));
    assert_eq('uk', i18n_lang_from_uri('/uk/news/3'));
    assert_eq('ru', i18n_lang_from_uri('/xx/tierlist'), 'неизвестный язык');
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

test('тег словаря: язык страницы и номер по содержимому', function () use ($PUB) {
    $ver = substr(md5_file("$PUB/js/i18n/en.js"), 0, 10);
    assert_eq('<script src="js/i18n/en.js?v=' . $ver . '" fetchpriority="high"></script>' . "\n", i18n_dict_tag('en'));
    assert_eq(0, strpos(i18n_dict_tag('ru', true), '<script src="/js/i18n/ru.js?v='), 'абсолютный путь для админки');
    assert_true(strpos(i18n_dict_tag('xx'), 'js/i18n/ru.js') !== false, 'неизвестный язык — русский');
    assert_true(strpos(with_uri('/en/stock', 'i18n_dict_tag'), 'js/i18n/en.js') !== false, 'по умолчанию — язык адреса');
});

test('собранные словари свежие', function () {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/i18n_build.php') . ' --check';
    exec($cmd, $out, $code);
    assert_eq(0, $code, 'php tools/i18n_build.php --check: ' . implode(' ', $out));
});

test('список языков в js/i18n.js совпадает с I18N_LANGS', function () use ($PUB) {
    preg_match('~var LANGS = \[([^\]]*)\];~', (string)file_get_contents("$PUB/js/i18n.js"), $m);
    assert_true(!empty($m), 'var LANGS найден');
    $js = array_map(function ($s) { return trim($s, " \"'"); }, explode(',', $m[1] ?? ''));
    assert_eq(I18N_LANGS, $js);
});

test('каждая страница с i18n.js подключает словарь прямо перед ним', function () use ($PUB) {
    foreach (['home', 'index', 'calculator', 'news', 'trading', 'trade-new', 'stock', 'giveaways',
              'profile', 'chat', 'support', 'promote'] as $p) {
        $s = str_replace("\r\n", "\n", (string)file_get_contents("$PUB/$p.php"));
        assert_true((bool)preg_match('~<\?= i18n_dict_tag\(\) \?>\n\s*<script src="js/i18n\.js\?v=~', $s), "$p.php");
    }
    $adm = (string)file_get_contents("$PUB/admin-giveaways.php");
    assert_true(strpos($adm, '{$i18nDictTag}<script src="/js/i18n.js?v=') !== false, 'admin-giveaways.php');
    assert_true(strpos($adm, "\$i18nDictTag = i18n_dict_tag('ru', true);") !== false, 'admin-giveaways.php: тег собран');
});

test('i18n_path снимает приставку и сохраняет query', function () {
    assert_eq('/news/92?x=1', with_uri('/en/news/92?x=1', 'i18n_path'));
    assert_eq('/', with_uri('/en', 'i18n_path'));
    assert_eq('/?x', with_uri('/en?x', 'i18n_path'));
    assert_eq('/tierlist', with_uri('/tierlist', 'i18n_path'));
    assert_eq('/', with_uri(null, 'i18n_path'));
});

test('меню «Язык»: все языки, ссылки на ту же страницу, текущий отмечен', function () {
    $m = with_uri('/en/news/92', 'i18n_lang_menu');
    assert_eq(count(I18N_LANGS), substr_count($m, 'class="mk-lang-item"'), 'пункт на каждый язык');
    assert_true(strpos($m, 'href="/news/92" hreflang="ru" lang="ru" data-lang-go="ru">Русский</a>') !== false, 'русский без приставки');
    assert_true(strpos($m, 'href="/en/news/92" hreflang="en" lang="en" data-lang-go="en" aria-current="true">English</a>') !== false, 'текущий');
    assert_eq(1, substr_count($m, 'aria-current'), 'текущий один');
    assert_true(strpos($m, '<span class="mk-lang-label" data-i18n="lang.menu">Language</span>') !== false, 'подпись на языке страницы');
    assert_true(strpos($m, 'aria-expanded="false" aria-controls="mkLangList"') !== false, 'кнопка списка');
    assert_true(strpos($m, '<ul class="mk-lang-list" id="mkLangList" hidden>') !== false, 'список закрыт');
    assert_eq(false, strpos($m, 'data-lang="'), 'старый атрибут не используется');
    foreach (I18N_LANGS as $l) { assert_true(isset(I18N_NAMES[$l]), "название $l"); }
});

test('меню стоит в листе разделов каждой страницы, старых чипов нет', function () use ($PUB) {
    foreach (['home', 'index', 'calculator', 'news', 'trading', 'trade-new', 'stock', 'giveaways',
              'profile', 'chat', 'support', 'promote'] as $p) {
        $s = str_replace("\r\n", "\n", (string)file_get_contents("$PUB/$p.php"));
        $nav = strpos($s, '<ul class="mk-nav">');
        $menu = strpos($s, '<li class="mk-nav-lang"><?= i18n_lang_menu() ?></li>');
        $end = $nav === false ? false : strpos($s, '</ul>', $nav);
        assert_true($nav !== false && $menu !== false && $menu > $nav && $menu < $end, "$p.php: меню в ul.mk-nav");
        assert_eq(false, strpos($s, 'class="mk-top-lang'), "$p.php: старых чипов нет");
    }
});

run_tests();
