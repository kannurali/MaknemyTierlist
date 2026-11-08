<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';

// /bot — инструкция чат-менеджера (public_html/bot.php). На неё ссылается сам
// бот (CM_GUIDE_URL), поэтому страница обязана существовать по этому адресу и
// знать каждую команду бота.

$PUB = __DIR__ . '/../public_html';

function bp_read(string $path): string {
    $s = file_get_contents($path);
    if ($s === false) { throw new RuntimeException("нет файла $path"); }
    return $s;
}

function bp_render(string $file): string {
    ob_start();
    require $file;
    return (string)ob_get_clean();
}

$HTML = bp_render($PUB . '/bot.php');
$TEXT = str_replace('ё', 'е', mb_strtolower(html_entity_decode(strip_tags($HTML), ENT_QUOTES, 'UTF-8')));

test('бот ссылается на /bot, и этот адрес ведёт на страницу', function () use ($PUB) {
    assert_eq('https://maknemy.com/bot', CM_GUIDE_URL);
    $ht = bp_read($PUB . '/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^bot$ /bot.php [L]') !== false, 'внутренний рероут /bot → /bot.php');
    assert_true(strpos($ht, 'RewriteRule ^bot/$ /bot [L,R=301]') !== false, 'слэш на конце — 301');
    $at = strpos($ht, 'RewriteRule ^bot\\.php$ /bot [L,R=301]');
    assert_true($at !== false, '301 с /bot.php');
    assert_true(strpos(substr($ht, max(0, $at - 120), 120), 'RewriteCond %{ENV:REDIRECT_STATUS} ^$') !== false,
        'перед 301 с /bot.php — условие REDIRECT_STATUS, иначе бесконечный редирект');
    assert_true(strpos($ht, 'RewriteRule ^(en|de|fr|es|it|pt|pl|tr|uk)/bot$ /bot [L,R=301]') !== false, 'языковые адреса — на русскую');
    assert_true(strpos(bp_read($PUB . '/sitemap.xml'), '<loc>https://maknemy.com/bot</loc>') !== false, 'в sitemap');
});

test('страница: canonical, индексируется, стили документа, защита', function () use ($HTML) {
    assert_true(strpos($HTML, '<link rel="canonical" href="https://maknemy.com/bot" />') !== false);
    assert_true((bool)preg_match('/<title>.+\| Maknemy Tier List<\/title>/u', $HTML));
    assert_true(strpos($HTML, 'content="index, follow"') !== false);
    assert_true(strpos($HTML, 'css/legal.css') !== false);
    assert_true(strpos($HTML, '/js/protect.js') !== false);
    assert_eq(1, substr_count($HTML, '<h1>'));
});

test('в разметке страницы нет HTML-комментариев: public_html отдаётся как есть', function () use ($PUB) {
    $src = bp_read($PUB . '/bot.php');
    $html = substr($src, strpos($src, '?>'));
    assert_eq(0, substr_count($html, '<!--'));
});

test('на странице есть каждая команда бота, кроме слов 18+', function () use ($TEXT) {
    $miss = [];
    $adult = [];
    foreach (CM_COMMANDS as $phrase => $def) {
        if ($def[1] === CM_ADULT) {
            if (preg_match('~(?<!\p{L})' . preg_quote($phrase, '~') . '(?!\p{L})~u', $TEXT)) { $adult[] = $phrase; }
            continue;
        }
        if (strpos($TEXT, $phrase) === false) { $miss[] = $phrase; }
    }
    assert_eq([], $miss, 'команды из CM_COMMANDS без описания на /bot');
    assert_eq([], $adult, 'слова 18+ на публичной странице не пишем: её видят дети и поисковики');
    assert_true(strpos($TEXT, 'дк 18+ 0') !== false, 'но как включить 18+ — написано');
    foreach (array_keys(CM_FILTERS) as $f) {
        assert_true(strpos($TEXT, $f) !== false, "фильтр «{$f}»");
    }
    foreach (CM_SECTIONS as $s) {
        assert_true(strpos($TEXT, $s) !== false, "раздел «{$s}»");
    }
    foreach (CM_RANK_NAMES as $r) {
        assert_true(strpos($TEXT, $r) !== false, "ранг «{$r}»");
    }
});

run_tests();
