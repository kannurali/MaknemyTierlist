<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require_once dirname(__DIR__) . '/public_html/api/lib/i18n.php';

$HT = str_replace("\r\n", "\n", (string)file_get_contents(dirname(__DIR__) . '/public_html/.htaccess'));
$EXTRA = implode('|', array_values(array_diff(I18N_LANGS, [I18N_DEFAULT])));
const RT_SECTIONS = 'tierlist|calculator|profile|chat|trading|trading/new|stock|support|giveaways|ads|news|news/[0-9]+';
const RT_FILES = [
    'tierlist'       => '/index.php',
    'calculator'     => '/calculator.php',
    'profile'        => '/profile.php',
    'chat'           => '/chat.php',
    'trading'        => '/trading.php',
    'trading/new'    => '/trade-new.php',
    'stock'          => '/stock.php',
    'support'        => '/support.php',
    'giveaways'      => '/giveaways.php',
    'ads'            => '/promote.php',
    'news'           => '/news.php',
    'news/([0-9]+)'  => '/news.php?id=$2',
];

test('приставки всех языков снимаются рероутом', function () use ($HT, $EXTRA) {
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)$ /home.php [L]") !== false, 'главная');
    // Сразу на PHP-файл: LiteSpeed не прогоняет .htaccess второй раз после
    // рероута, и /en/tierlist → /tierlist там отдавал 404.
    foreach (RT_FILES as $section => $target) {
        assert_true(strpos($HT, "RewriteRule ^($EXTRA)/$section$ $target [L]\n") !== false, "раздел $section → $target");
    }
    assert_eq(false, strpos($HT, '$ /$2 [L]'), 'нет рероута на адрес раздела');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/(privacy|terms)$ /$2 [L,R=301]") !== false, 'правовые — на адрес без приставки');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/$ /$1 [L,R=301]") !== false, 'слэш у главной');
    assert_true(strpos($HT, "RewriteRule ^($EXTRA)/(.+)/$ /$1/$2 [L,R=301]") !== false, 'слэш у разделов');
});

test('выбор языка: кука, языки СНГ, остальные — английский', function () use ($HT) {
    assert_true(strpos($HT, 'RewriteCond %{HTTP_COOKIE} (^|;\s*)nx_lang=(' . implode('|', I18N_LANGS) . ')(;|$)') !== false, 'кука');
    assert_true(strpos($HT, 'RewriteCond %{HTTP:Accept-Language} ^\s*(ru|kk|uz|ky|be|az|hy|ka|tg|tk)([-_,;\s]|$) [NC]') !== false, 'СНГ');
    assert_true(strpos($HT, 'RewriteRule ^ - [E=NX_PICK:en]') !== false, 'остальные — en');
});

test('перенаправление: только внешний GET/HEAD без ботов, 302, на главную и разделы', function () use ($HT, $EXTRA) {
    $guard = "RewriteCond %{ENV:REDIRECT_STATUS} ^$\n"
           . "RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$\n"
           . "RewriteCond %{HTTP_USER_AGENT} !(bot|crawl|spider|slurp|preview|facebookexternalhit|vkshare|whatsapp|telegram|discord|skype|lighthouse) [NC]\n"
           . "RewriteCond %{ENV:NX_PICK} ^($EXTRA)$\n";
    assert_true(strpos($HT, $guard . 'RewriteRule ^$ /%1 [R=302,L]') !== false, 'главная');
    assert_true(strpos($HT, $guard . 'RewriteRule ^(' . RT_SECTIONS . ')$ /%1/$1 [R=302,L]') !== false, 'разделы');
});

test('блок языков стоит до разделов и до DirectoryIndex', function () use ($HT) {
    $pick = strpos($HT, 'E=NX_PICK');
    assert_true($pick !== false, 'блок на месте');
    assert_true($pick < strpos($HT, 'DirectoryIndex home.php'), 'раньше DirectoryIndex');
    assert_true($pick < strpos($HT, 'RewriteRule ^tierlist/$ /tierlist [L,R=301]'), 'раньше разделов');
    assert_true($pick > strpos($HT, 'RewriteRule \.php/ - [F,L]'), 'после защиты API');
});

run_tests();
