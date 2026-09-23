<?php
// Защита от флуда на уровне конфигурации и связей между файлами: кеш
// LiteSpeed, правила .htaccess против обхода кеша, сессии только по куке,
// лимиты по настоящему адресу. Сами функции проверяет bootstrap_test.php.
// Run: php tests/dos_guard_test.php
define('TESTING', 1);
require __DIR__ . '/lib.php';

$PUB = realpath(__DIR__ . '/../public_html');

function dg_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) { throw new RuntimeException("cannot read $path"); }
    return str_replace("\r\n", "\n", $s);
}

// Последнее условие на строку запроса перед правилом. Шаблон дальше
// проверяется на живых строках — так тест ловит ошибку в самом регулярном
// выражении, а не только его наличие.
function dg_cond_before(string $ht, string $rule): ?string {
    $pos = strpos($ht, $rule);
    if ($pos === false) { return null; }
    if (!preg_match_all('~^RewriteCond %\{QUERY_STRING\} (\S+)[ \t]*$~m', substr($ht, 0, $pos), $m)) { return null; }
    return end($m[1]);
}

// --------------------------------------------------------------------------
//  .htaccess
// --------------------------------------------------------------------------

test('.htaccess turns on the LiteSpeed cache lookup', function () use ($PUB) {
    $ht = dg_read("$PUB/.htaccess");
    assert_true((bool)preg_match('~<IfModule LiteSpeed>\s*CacheLookup public on\s*</IfModule>~', $ht),
        'CacheLookup inside <IfModule LiteSpeed>');
});

test('state.php takes no query string at all', function () use ($PUB) {
    $ht = dg_read("$PUB/.htaccess");
    assert_eq('.', dg_cond_before($ht, 'RewriteRule ^api/state\.php$ - [F,L]'), 'any query string is refused');
});

test('tierlist.php and promo.php take nothing but ?rev=<digits>', function () use ($PUB) {
    $ht = dg_read("$PUB/.htaccess");
    $cond = dg_cond_before($ht, 'RewriteRule ^api/(tierlist|promo)\.php$ - [F,L]');
    assert_true($cond !== null && $cond[0] === '!', 'negated condition in front of the rule');
    $re = '~' . substr((string)$cond, 1) . '~';
    foreach (['', 'rev=0', 'rev=1726000000000'] as $ok) {
        assert_eq(1, preg_match($re, $ok), "allowed: '$ok'");
    }
    foreach (['rev=', 'rev=1&x=2', 'x=1&rev=1', 'rev=abc', 'rev=-1', 'rev=1x', 'rev=' . str_repeat('9', 19), 't=123'] as $bad) {
        assert_eq(0, preg_match($re, $bad), "refused: '$bad'");
    }
});

test('a path tail after .php is refused', function () use ($PUB) {
    $ht = dg_read("$PUB/.htaccess");
    assert_true(strpos($ht, 'RewriteRule \.php/ - [F,L]') !== false, 'rule present');
});

// Правила отказа — после канонического хоста и HTTPS (иначе чужой хост
// получил бы 403 вместо 301) и до внутренних рероутов страниц.
test('the refusal rules sit between the redirects and the page rewrites', function () use ($PUB) {
    $ht = dg_read("$PUB/.htaccess");
    $https = strpos($ht, 'RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]');
    $state = strpos($ht, 'RewriteRule ^api/state\.php$ - [F,L]');
    $pages = strpos($ht, 'RewriteRule ^tierlist$ /index.php [L]');
    assert_true($https !== false && $state !== false && $pages !== false, 'all rules found');
    assert_true($https < $state && $state < $pages, 'order');
});

// --------------------------------------------------------------------------
//  Кто кладёт себя в кеш LiteSpeed
// --------------------------------------------------------------------------

test('public pages ask LiteSpeed to cache them', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php', 'api/lib/legal_page.php'] as $f) {
        assert_true(strpos(dg_read("$PUB/$f"), 'page_lscache();') !== false, $f);
    }
});

// Всё, что зависит от сессии или меняет данные, в общий кеш попасть не
// должно: следующий посетитель получил бы чужой ответ.
test('session-bound and writing endpoints never ask for the cache', function () use ($PUB) {
    foreach (['admin.php', 'admin-news.php', 'admin-promo.php', 'admin-logout.php', 'api/lib/admin_page.php',
              'api/session.php', 'api/login.php', 'api/logout.php', 'api/save.php', 'api/upload.php',
              'api/news_save.php', 'api/news_delete.php', 'api/like.php', 'api/news_like.php',
              'api/roblox_start.php', 'api/roblox_callback.php',
              'api/tg_link.php', 'api/tg_webhook.php', 'api/tg_setup.php'] as $f) {
        $src = dg_read("$PUB/$f");
        assert_eq(false, strpos($src, 'page_lscache('), "$f: page_lscache");
        assert_eq(false, strpos($src, 'lscache_public('), "$f: lscache_public");
    }
});

// Страницы живут в кеше минуту. Без сброса после деплоя новый посетитель в
// эту минуту получал бы старый HTML со старыми ?v=, а под теми же адресами —
// уже новые js и css.
test('a successful deploy purges the LiteSpeed cache', function () use ($PUB) {
    $src = dg_read("$PUB/api/deploy.php");
    $publish = strpos($src, '$published = deploy_publish(');
    $purge   = strpos($src, "header('X-LiteSpeed-Purge: *');");
    $done    = strpos($src, "json_out(['ok' => true, 'deployed' => true");
    assert_true($publish !== false && $purge !== false && $done !== false, 'all steps present');
    assert_true($publish < $purge && $purge < $done, 'purge after publishing, before answering');
});

test('the polled endpoints are cacheable', function () use ($PUB) {
    assert_true(strpos(dg_read("$PUB/api/state.php"), 'lscache_public(5)') !== false, 'state.php for 5 s');
    foreach (['tierlist.php', 'promo.php', 'og-tierlist.php', 'og-news.php'] as $f) {
        assert_true(strpos(dg_read("$PUB/api/$f"), 'lscache_public(86400)') !== false, $f);
    }
});

// --------------------------------------------------------------------------
//  Сессии и адреса
// --------------------------------------------------------------------------

test('anonymous requests never open a session', function () use ($PUB) {
    $promo = dg_read("$PUB/api/promo.php");
    assert_eq(false, strpos($promo, 'start_admin_session'), 'promo.php');
    assert_eq(false, strpos($promo, 'start_site_session'), 'promo.php');
    $session = dg_read("$PUB/api/session.php");
    assert_true(strpos($session, 'resume_site_session()') !== false, 'session.php only resumes');
    assert_eq(false, strpos($session, 'start_site_session'), 'session.php does not start one');
    foreach (['api/lib/admin_page.php', 'admin-logout.php', 'api/logout.php'] as $f) {
        assert_eq(false, strpos(dg_read("$PUB/$f"), 'start_admin_session'), $f);
    }
});

test('rate limits key on client_key(), never on raw REMOTE_ADDR', function () use ($PUB) {
    foreach (['like.php', 'login.php', 'news.php', 'news_like.php', 'upload.php', 'promo.php', 'roblox_start.php'] as $f) {
        $src = dg_read("$PUB/api/$f");
        assert_eq(false, strpos($src, 'REMOTE_ADDR'), "$f reads REMOTE_ADDR directly");
        assert_true(strpos($src, 'client_key()') !== false, "$f uses client_key()");
    }
});

run_tests();
