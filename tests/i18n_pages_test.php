<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';
require_once dirname(__DIR__) . '/tools/lib_render.php';

$PUB = dirname(__DIR__) . '/public_html';

// путь => [файл, $_GET, индексируется ли, ключ страницы, есть ли JSON-LD]
const IP_PAGES = [
    '/'                      => ['home.php',       [],                       true,  'home',       true],
    '/tierlist'              => ['index.php',      [],                       true,  'tierlist',   true],
    '/calculator'            => ['calculator.php', [],                       true,  'calculator', true],
    '/news'                  => ['news.php',       [],                       true,  'news',       false],
    '/trading'               => ['trading.php',    [],                       true,  'trading',    true],
    '/stock'                 => ['stock.php',      [],                       true,  'stock',      true],
    '/giveaways'             => ['giveaways.php',  [],                       true,  'giveaways',  false],
    '/ads'                   => ['promote.php',    [],                       true,  'promote',    false],
    '/trading/new'           => ['trade-new.php',  [],                       false, 'tradeNew',   false],
    '/chat'                  => ['chat.php',       [],                       false, 'chat',       false],
    '/support'               => ['support.php',    [],                       false, 'support',    false],
    '/profile?id=2841062255' => ['profile.php',    ['id' => '2841062255'],   false, 'profile',    false],
];

function ip_uri(string $path, string $lang): string {
    return $lang === 'ru' ? $path : '/' . $lang . ($path === '/' ? '' : $path);
}

test('у каждой публичной страницы base href="/"', function () use ($PUB) {
    foreach (IP_PAGES as $path => $p) {
        assert_true(strpos((string)file_get_contents("$PUB/{$p[0]}"), '<base href="/" />') !== false, $p[0]);
    }
});

foreach (I18N_LANGS as $lang) {
    test("страницы на $lang: язык, заголовок, canonical, hreflang, словарь", function () use ($lang) {
        foreach (IP_PAGES as $path => $p) {
            [$file, $get, $indexable, $key, $ld] = $p;
            $html = nx_render_page($file, ip_uri($path, $lang), $get);
            $tag = "$lang $path";
            assert_true(strlen($html) > 1000, "$tag: страница отрисовалась");
            assert_true(strpos($html, '<html lang="' . $lang . '">') !== false, "$tag: html lang");
            assert_true(strpos($html, 'js/i18n/' . $lang . '.js?v=') !== false, "$tag: словарь своего языка");
            if ($file !== 'profile.php') {
                $canon = i18n_url($path, $lang);
                assert_true(strpos($html, '<link rel="canonical" href="' . $canon . '" />') !== false, "$tag: canonical $canon");
                assert_true(strpos($html, '<title>' . htmlspecialchars(t("meta.$key.title", [], $lang), ENT_NOQUOTES, 'UTF-8') . '</title>') !== false,
                    "$tag: title");
            }
            assert_eq($indexable, strpos($html, 'hreflang="x-default"') !== false, "$tag: hreflang");
            assert_eq($ld, strpos($html, 'application/ld+json') !== false, "$tag: JSON-LD на всех языках");
            if ($ld) {
                preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $html, $m);
                $data = json_decode($m[1] ?? '', true);
                assert_true(is_array($data), "$tag: JSON-LD разбирается");
                $node = isset($data['@graph']) ? $data['@graph'][0] : $data;
                assert_eq($lang, $node['inLanguage'] ?? null, "$tag: inLanguage");
            }
        }
    });
}

test('шапка английской страницы ведёт на английские разделы', function () {
    $html = nx_render_page('index.php', '/en/tierlist');
    foreach (['/en/calculator', '/en/news', '/en/trading', '/en/giveaways', '/en/stock', '/en/ads'] as $href) {
        assert_true(strpos($html, 'href="' . $href . '"') !== false, $href);
    }
    assert_eq(false, strpos($html, 'href="/calculator"'), 'русских ссылок на разделы нет');
    assert_true(strpos($html, 'href="/privacy"') !== false, 'правовые страницы — без приставки');
});

test('og:locale и альтернатива', function () {
    $ru = nx_render_page('calculator.php', '/calculator');
    $en = nx_render_page('calculator.php', '/en/calculator');
    assert_true(strpos($ru, '<meta property="og:locale" content="ru_RU" />') !== false, 'ru');
    assert_true(strpos($ru, '<meta property="og:locale:alternate" content="en_US" />') !== false, 'ru → en');
    assert_true(strpos($en, '<meta property="og:locale" content="en_US" />') !== false, 'en');
    assert_true(strpos($en, '<meta property="og:url" content="https://maknemy.com/en/calculator" />') !== false, 'og:url');
});

test('профиль: заголовок и canonical на своём языке', function () {
    $en = nx_render_page('profile.php', '/en/profile?id=2841062255', ['id' => '2841062255']);
    assert_true(strpos($en, 'https://maknemy.com/en/profile') !== false, 'canonical');
    assert_true(stripos($en, 'player profile | Maknemy Tier List</title>') !== false, 'title (с ником или без)');
});

test('sitemap: все языковые версии и hreflang', function () use ($PUB) {
    $map = (string)file_get_contents("$PUB/sitemap.xml");
    assert_true(strpos($map, 'xmlns:xhtml="http://www.w3.org/1999/xhtml"') !== false, 'пространство xhtml');
    foreach (['/', '/tierlist', '/news', '/calculator', '/trading', '/stock', '/giveaways', '/ads'] as $p) {
        foreach (I18N_LANGS as $l) {
            assert_true(strpos($map, '<loc>' . i18n_url($p, $l) . '</loc>') !== false, "$l $p");
        }
        assert_true(strpos($map, '<xhtml:link rel="alternate" hreflang="x-default" href="' . i18n_url($p, 'ru') . '" />') !== false, "x-default $p");
    }
    assert_true(strpos($map, '<loc>https://maknemy.com/privacy</loc>') !== false, '/privacy');
    assert_eq(false, strpos($map, '/en/privacy'), 'правовые без языковых версий');
});

test('/ads переведена: на английской нет русского текста и noindex', function () {
    $en = nx_render_page('promote.php', '/en/ads');
    $body = preg_replace('~<script\b[^>]*>.*?</script>~s', '', $en);
    assert_eq(0, preg_match('~[А-Яа-яЁё]~u', $body), 'русского текста нет');
    assert_eq(false, strpos($en, 'noindex'), 'индексируется');
    assert_true(strpos($en, 'Advertising on maknemy.com and in Telegram') !== false, 'заголовок по-английски');
});

test('шапка подписана на языке страницы', function () {
    $en = nx_render_page('trading.php', '/en/trading');
    assert_true(strpos($en, 'aria-label="Site sections"') !== false, 'разделы');
    assert_true(strpos($en, '<button class="mk-avatar" type="button" aria-label="Profile" data-soon') !== false, 'аватар');
});

run_tests();
