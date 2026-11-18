<?php
// Второй сайт на том же коде: какой набор страниц он открывает, какие адреса
// собирает и куда публикуется деплой. См. public_html/api/lib/site.php.
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/og.php';
require __DIR__ . '/../public_html/api/deploy.php';
require __DIR__ . '/../public_html/api/site_meta.php';

const VOX = [
    'origin' => 'https://voxseas.fun',
    'name'   => 'Vox Seas Tier List',
    'skin'   => 'vx',
    'pages'  => ['tierlist', 'news'],
];

function with_site(?array $site, callable $fn): void {
    $GLOBALS['__SITE__'] = $site;
    try { $fn(); } finally { unset($GLOBALS['__SITE__']); }
}

// --- основной сайт --------------------------------------------------------

test('без ключа site это maknemy.com со всеми страницами', function () {
    $site = site_normalize([]);
    assert_eq('https://maknemy.com', $site['origin'], 'origin');
    assert_eq('Maknemy Tier List', $site['name'], 'name');
    assert_eq(null, $site['skin'], 'skin');
    assert_eq(null, $site['pages'], 'pages');
});

test('основной сайт пропускает любой скрипт', function () {
    $site = site_normalize([]);
    foreach (['home.php', 'chat.php', 'api/chat_send.php', 'api/tg_webhook.php', 'tg-app.php'] as $s) {
        assert_eq(['allow' => true], site_gate_decision($s, $site), $s);
    }
});

test('адреса основного сайта не изменились', function () {
    with_site(null, function () {
        assert_eq('https://maknemy.com/tierlist', i18n_url('/tierlist', 'ru'), 'ru');
        assert_eq('https://maknemy.com/en/news', i18n_url('/news', 'en'), 'en');
        assert_eq('https://maknemy.com/assets/og-card.jpg?v=1', og_brand_card()['image'], 'og');
        assert_true(site_has('chat'), 'чат есть');
    });
});

// --- разбор конфига -------------------------------------------------------

test('мусор в конфиге не ломает сайт', function () {
    $site = site_normalize(['origin' => 'javascript:alert(1)', 'name' => '  ', 'skin' => '../etc', 'pages' => 'news']);
    assert_eq('https://maknemy.com', $site['origin'], 'origin откатился');
    assert_eq('Maknemy Tier List', $site['name'], 'name откатился');
    assert_eq(null, $site['skin'], 'чужой скин не подключается');
    assert_eq(null, $site['pages'], 'pages не массив');
});

test('слэш в конце origin снимается, неизвестные страницы отбрасываются', function () {
    $site = site_normalize(['origin' => 'https://voxseas.fun/', 'pages' => ['news', 'chat', 'tierlist']]);
    assert_eq('https://voxseas.fun', $site['origin'], 'origin');
    assert_eq(['tierlist', 'news'], $site['pages'], 'только известные разделы');
});

// --- второй сайт ----------------------------------------------------------

test('второй сайт открывает тирлист, новости, вход и админку к ним', function () {
    $site = site_normalize(VOX);
    $open = [
        'index.php', 'news.php', 'admin.php', 'admin-news.php', 'admin-logout.php',
        'api/state.php', 'api/tierlist.php', 'api/save.php', 'api/upload.php', 'api/like.php',
        'api/news.php', 'api/news_save.php', 'api/news_delete.php', 'api/news_like.php', 'api/promo.php',
        'api/session.php', 'api/logout.php', 'api/roblox_start.php', 'api/roblox_callback.php',
        'api/deploy.php', 'api/site_meta.php', 'privacy.php', 'terms.php',
    ];
    foreach ($open as $s) {
        assert_eq(['allow' => true], site_gate_decision($s, $site), $s);
    }
});

test('всего остального на втором сайте нет', function () {
    $site = site_normalize(VOX);
    $closed = [
        'chat.php', 'trading.php', 'trade-new.php', 'calculator.php', 'stock.php', 'giveaways.php',
        'halloween.php', 'promote.php', 'profile.php', 'support.php', 'bot.php', 'tg-app.php',
        'admin-promo.php', 'admin-giveaways.php', 'admin-bans.php',
        'api/chat_send.php', 'api/giveaways.php', 'api/hunt.php', 'api/tg_webhook.php',
        'api/trade_create.php', 'api/stock.php', 'api/og-tierlist.php', 'api/og-news.php', '',
    ];
    foreach ($closed as $s) {
        assert_eq(['allow' => false], site_gate_decision($s, $site), $s === '' ? '(пусто)' : $s);
    }
});

test('каждый публичный скрипт сайта либо открыт, либо закрыт осознанно', function () {
    // Новый файл в public_html на втором сайте по умолчанию закрыт. Тест
    // ловит обратное: имя в списках, которому не соответствует файл.
    $pub = dirname(__DIR__) . '/public_html/';
    $listed = SITE_CORE_SCRIPTS;
    foreach (SITE_PAGE_SCRIPTS as $scripts) { $listed = array_merge($listed, $scripts); }
    foreach (array_unique($listed) as $s) {
        assert_true(is_file($pub . $s), "файл существует: $s");
    }
});

test('главной на втором сайте нет: «/» ведёт на первую страницу', function () {
    $site = site_normalize(VOX);
    assert_eq(['allow' => false, 'redirect' => '/tierlist'], site_gate_decision('home.php', $site), 'tierlist первым');
    $newsOnly = site_normalize(['pages' => ['news']]);
    assert_eq(['allow' => false, 'redirect' => '/news'], site_gate_decision('home.php', $newsOnly), 'только новости');
});

test('обратные слэши и ведущий слэш в пути не обходят проверку', function () {
    $site = site_normalize(VOX);
    assert_eq(['allow' => true], site_gate_decision('/api\\state.php', $site), 'windows-путь');
    assert_eq(['allow' => false], site_gate_decision('api/../chat.php', $site), 'обход каталога');
});

test('адреса второго сайта собираются от его домена', function () {
    with_site(VOX, function () {
        assert_eq('https://voxseas.fun/tierlist', i18n_url('/tierlist', 'ru'), 'ru');
        assert_eq('https://voxseas.fun/en/news/7', i18n_url('/news/7', 'en'), 'en');
        assert_true(strpos(i18n_alternates('/news'), 'https://maknemy.com') === false, 'hreflang без чужого домена');
        assert_eq('https://voxseas.fun/assets/vx/og-card.jpg?v=1', og_brand_card()['image'], 'og');
        assert_true(site_has('news') && site_has('tierlist'), 'свои разделы');
        assert_true(!site_has('chat') && !site_has('giveaways'), 'чужих разделов нет');
        assert_eq('vx', site_skin(), 'skin');
    });
});

test('шаблоны скина лежат там, откуда их подключают страницы', function () {
    $pub = dirname(__DIR__) . '/public_html/';
    foreach (SITE_SKINS as $skin) {
        assert_true(is_file($pub . $skin . '/tierlist.php'), "$skin/tierlist.php");
        assert_true(is_file($pub . $skin . '/news.php'), "$skin/news.php");
        assert_true(is_file($pub . $skin . '/privacy.php'), "$skin/privacy.php");
        assert_true(is_file($pub . $skin . '/terms.php'), "$skin/terms.php");
    }
    $index = file_get_contents($pub . 'index.php');
    $news = file_get_contents($pub . 'news.php');
    assert_true(strpos($index, "require __DIR__ . '/' . site_skin() . '/tierlist.php'") !== false, 'index.php');
    assert_true(strpos($news, "require __DIR__ . '/' . site_skin() . '/news.php'") !== false, 'news.php');
    foreach (['privacy', 'terms'] as $doc) {
        $src = file_get_contents($pub . $doc . '.php');
        assert_true(strpos($src, "require __DIR__ . '/' . site_skin() . '/$doc.php'") !== false, "$doc.php");
    }
});

test('юридические документы второго сайта не про maknemy.com', function () {
    $pub = dirname(__DIR__) . '/public_html/vx/';
    foreach (['privacy.php', 'terms.php', 'legal.php'] as $f) {
        $src = file_get_contents($pub . $f);
        assert_true(stripos($src, 'maknemy.com') === false, "$f без maknemy.com");
        assert_true(stripos($src, 'Blox Fruits') === false, "$f без Blox Fruits");
        assert_true(stripos($src, 'Google Analytics') === false, "$f без Google Analytics");
    }
});

// --- robots.txt и sitemap.xml ---------------------------------------------

test('robots и sitemap второго сайта не упоминают основной', function () {
    $site = site_normalize(VOX);
    $robots = site_meta_robots($site);
    $map = site_meta_sitemap($site);
    assert_true(strpos($robots, 'Sitemap: https://voxseas.fun/sitemap.xml') !== false, 'ссылка на свою карту');
    assert_true(strpos($robots . $map, 'maknemy') === false, 'чужого домена нет');
    assert_true(strpos($map, '<loc>https://voxseas.fun/tierlist</loc>') !== false, 'тирлист');
    assert_true(strpos($map, '<loc>https://voxseas.fun/en/news</loc>') !== false, 'новости на английском');
    assert_eq(2 * count(I18N_LANGS) + 2, substr_count($map, '<loc>'), 'по адресу на язык и раздел плюс два документа');
    assert_true(strpos($map, '<loc>https://voxseas.fun/privacy</loc>') !== false, 'политика');
    assert_true(strpos($map, '<loc>https://voxseas.fun/terms</loc>') !== false, 'условия');
});

// --- IndexNow --------------------------------------------------------------

test('ключ IndexNow: 32 hex, адрес файла ловит правило .htaccess', function () {
    assert_true(preg_match('~^[0-9a-f]{32}$~', INDEXNOW_KEY) === 1, 'формат ключа');
    assert_eq('/' . INDEXNOW_KEY . '.txt', indexnow_key_path(), 'путь файла');
    $ht = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/public_html/.htaccess'));
    assert_true(strpos($ht, "RewriteRule ^[0-9a-f]{32}\\.txt$ /api/site_meta.php [L]") !== false, 'правило без условия на домен');
    assert_true(preg_match('~^[0-9a-f]{32}\.txt$~', ltrim(indexnow_key_path(), '/')) === 1, 'путь совпадает с шаблоном правила');
});

test('IndexNow шлёт все адреса из своей карты сайта', function () {
    $site = site_normalize(VOX);
    $urls = indexnow_sitemap_urls(site_meta_sitemap($site));
    assert_eq(2 * count(I18N_LANGS) + 2, count($urls), 'все loc');
    $p = indexnow_payload('https://voxseas.fun/', $urls);
    assert_eq('voxseas.fun', $p['host'], 'host без схемы');
    assert_eq('https://voxseas.fun/' . INDEXNOW_KEY . '.txt', $p['keyLocation'], 'keyLocation на своём домене');
    assert_eq(INDEXNOW_KEY, $p['key'], 'ключ');
    assert_eq($urls, $p['urlList'], 'список без потерь');
});

test('IndexNow отсеивает чужие хосты и повторы', function () {
    $p = indexnow_payload('https://voxseas.fun', [
        'https://voxseas.fun/tierlist',
        'https://maknemy.com/tierlist',
        'https://www.voxseas.fun/news',
        'https://VOXSEAS.fun/news',
        'https://voxseas.fun/tierlist',
        42,
    ]);
    assert_eq(['https://voxseas.fun/tierlist', 'https://VOXSEAS.fun/news'], $p['urlList'], 'только свой хост, без дублей');
    assert_eq(['https://voxseas.fun/a?x=1&y=2'], indexnow_sitemap_urls("<loc> https://voxseas.fun/a?x=1&amp;y=2 </loc>"), 'сущности XML раскрыты');
});

test('корень второго сайта уводит на первую страницу постоянным редиректом', function () {
    $src = file_get_contents(dirname(__DIR__) . '/public_html/api/lib/site.php');
    assert_true(strpos($src, "header('Location: ' . u(\$verdict['redirect']), true, 301);") !== false, '301, не 302');
});

// --- .htaccess -------------------------------------------------------------

test('.htaccess не уводит второй домен на основной', function () {
    $ht = str_replace("\r\n", "\n", file_get_contents(dirname(__DIR__) . '/public_html/.htaccess'));
    $rule = "RewriteCond %{HTTP_HOST} !^maknemy\\.com(:[0-9]+)?$ [NC]\n"
        . "RewriteCond %{HTTP_HOST} !^voxseas\\.fun(:[0-9]+)?$ [NC]\n"
        . "RewriteRule ^ https://maknemy.com%{REQUEST_URI} [L,R=301]";
    assert_true(strpos($ht, $rule) !== false, 'исключение в каноническом редиректе');
    assert_true(strpos($ht, 'RewriteRule ^ https://voxseas.fun%{REQUEST_URI} [L,R=301]') !== false, 'www сводится к своему домену');
    assert_true(strpos($ht, 'RewriteRule ^(robots\\.txt|sitemap\\.xml)$ /api/site_meta.php [L]') !== false, 'robots и sitemap');
    assert_true(strpos($ht, 'RewriteRule ^vx/ - [R=404,L]') !== false, 'шаблоны закрыты');
});

// --- деплой ----------------------------------------------------------------

test('без дополнительных каталогов деплой идёт только в основной', function () {
    $plan = deploy_targets(['deploy_repo' => '/home/u/repositories/R', 'deploy_path' => '/home/u/public_html']);
    assert_eq(['targets' => ['/home/u/public_html'], 'skipped' => []], $plan, 'один каталог');
});

test('дополнительные каталоги публикуются после основного', function () {
    $plan = deploy_targets([
        'deploy_repo' => '/home/u/repositories/R',
        'deploy_path' => '/home/u/public_html',
        'deploy_extra_paths' => ['/home/u/voxseas/public_html'],
    ]);
    assert_eq(['/home/u/public_html', '/home/u/voxseas/public_html'], $plan['targets'], 'порядок');
    assert_eq([], $plan['skipped'], 'ничего не отброшено');
});

test('негодный дополнительный каталог не отменяет деплой основного', function () {
    $plan = deploy_targets([
        'deploy_repo' => '/home/u/repositories/R',
        'deploy_path' => '/home/u/public_html',
        'deploy_extra_paths' => ['', '/home/u/repositories/R/public_html', 42, '/home/u/public_html/', '/home/u/vox'],
    ]);
    assert_eq(['/home/u/public_html', '/home/u/vox'], $plan['targets'], 'остались годные, повтор основного снят');
    assert_eq(['', '/home/u/repositories/R/public_html', ''], $plan['skipped'], 'пустой, внутри репозитория, не строка');
});

test('негодный основной каталог выключает деплой целиком', function () {
    assert_eq(null, deploy_targets(['deploy_repo' => '/r', 'deploy_path' => '']), 'пустой');
    assert_eq(null, deploy_targets(['deploy_repo' => '/r', 'deploy_path' => '/r/x', 'deploy_extra_paths' => ['/ok']]), 'внутри репозитория');
});

run_tests();
