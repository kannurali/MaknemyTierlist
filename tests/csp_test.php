<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/metrika.php';

// Content-Security-Policy из .htaccess и то, на чём он держится.
//
// Смысл политики — script-src без 'unsafe-inline': внедрённая разметка
// (<img onerror=…>, <script>…</script>) не исполняется, и чужой код не
// действует от имени вошедшего админа. Работает это, только пока на сайте нет
// ни одного встроенного исполняемого скрипта и обработчика on*=. Стоит
// вернуть хоть один — страница молча сломается у всех (браузер его не
// исполнит), а соблазн «разрешить unsafe-inline» уничтожит всю защиту. Поэтому
// оба условия проверяются здесь.

$PUB = dirname(__DIR__) . '/public_html';

function csp_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) { throw new RuntimeException("не читается: $path"); }
    return str_replace("\r\n", "\n", $s);
}

// Директивы политики: ['script-src' => ["'self'", ...], ...].
function csp_policy(string $htaccess): array {
    if (!preg_match_all('~^\s*Header always set Content-Security-Policy "([^"]+)"\s*$~m', $htaccess, $m)) { return []; }
    $out = [];
    foreach (explode(';', $m[1][0]) as $part) {
        $tokens = preg_split('~\s+~', trim($part), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens) { $out[strtolower(array_shift($tokens))] = $tokens; }
    }
    return $out;
}

// Все файлы сайта с нужными расширениями, кроме сторонней минифицированной
// библиотеки: html2canvas приходит как есть, и строки внутри неё — не наша
// разметка.
function csp_files(string $dir, array $exts): array {
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!in_array(strtolower($f->getExtension()), $exts, true)) { continue; }
        if (substr($f->getFilename(), -7) === '.min.js') { continue; }
        $out[] = str_replace('\\', '/', $f->getPathname());
    }
    sort($out);
    return $out;
}

$HT = csp_read($PUB . '/.htaccess');
$POLICY = csp_policy($HT);

// --------------------------------------------------------------------------
//  Сама политика
// --------------------------------------------------------------------------

test('заголовок один, ставится always и одной строкой', function () use ($HT, $POLICY) {
    assert_eq(1, preg_match_all('~Content-Security-Policy~', preg_replace('~^\s*#.*$~m', '', $HT)),
        'одна строка с CSP вне комментариев');
    assert_true($POLICY !== [], 'Header always set Content-Security-Policy "…" разбирается');
    $block = strpos($HT, '<IfModule mod_headers.c>');
    $line  = strpos($HT, 'Header always set Content-Security-Policy');
    assert_true($block !== false && $line > $block, 'внутри <IfModule mod_headers.c>');
});

test('скрипты — только свои и Метрика, без unsafe-inline и eval', function () use ($POLICY) {
    $s = $POLICY['script-src'] ?? [];
    assert_eq(["'self'", 'https://mc.yandex.ru', 'https://yastatic.net'], $s, 'script-src');
    foreach (["'unsafe-inline'", "'unsafe-eval'", "'unsafe-hashes'", '*', 'data:', 'blob:', 'https:', 'http:'] as $bad) {
        assert_eq(false, in_array($bad, $s, true), "script-src без $bad");
    }
    assert_eq(["'self'"], $POLICY['default-src'] ?? [], 'default-src');
});

test('жёсткие директивы на месте', function () use ($POLICY) {
    assert_eq(["'none'"], $POLICY['object-src'] ?? [], 'object-src');
    assert_eq(["'self'"], $POLICY['base-uri'] ?? [], 'base-uri');
    assert_eq(["'self'"], $POLICY['form-action'] ?? [], 'form-action');
    assert_eq(["'self'"], $POLICY['frame-ancestors'] ?? [], 'frame-ancestors — как X-Frame-Options');
});

test('картинки: свои, data/blob для экспорта и кропа, аватары Roblox', function () use ($POLICY, $PUB) {
    $img = $POLICY['img-src'] ?? [];
    foreach (["'self'", 'https://maknemy.com', 'data:', 'blob:'] as $src) {
        assert_true(in_array($src, $img, true), "img-src: $src");
    }
    // Те же домены, что пропускает roblox_avatar_ok(): и сам домен, и поддомены.
    $oauth = csp_read($PUB . '/api/lib/roblox_oauth.php');
    assert_true((bool)preg_match("~foreach \(\['rbxcdn\.com', 'roblox\.com'\] as \\\$suffix\)~", $oauth),
        'список доменов аватара в roblox_avatar_ok() не изменился — иначе поправить и img-src');
    foreach (['rbxcdn.com', 'roblox.com'] as $host) {
        assert_true(in_array('https://' . $host, $img, true), "img-src: https://$host");
        assert_true(in_array('https://*.' . $host, $img, true), "img-src: https://*.$host");
    }
    assert_eq(false, in_array('*', $img, true), 'img-src без *');
    assert_eq(false, in_array('https:', $img, true), 'img-src без https:');
});

test('адреса Метрики и Вебвизора — по её инструкции', function () use ($POLICY) {
    $tlds = ['ru', 'az', 'by', 'co.il', 'com', 'com.am', 'com.ge', 'com.tr', 'ee', 'fr',
             'kg', 'kz', 'lt', 'lv', 'md', 'tj', 'tm', 'uz'];
    $https = array_merge(array_map(function ($t) { return 'https://mc.yandex.' . $t; }, $tlds),
        ['https://mc.webvisor.com', 'https://mc.webvisor.org']);
    foreach ($https as $h) {
        assert_true(in_array($h, $POLICY['connect-src'] ?? [], true), "connect-src: $h");
        assert_true(in_array(str_replace('https://', 'wss://', $h), $POLICY['connect-src'] ?? [], true), "connect-src: wss для $h");
        assert_true(in_array($h, $POLICY['img-src'] ?? [], true), "img-src: $h");
    }
    foreach (['frame-src', 'child-src'] as $d) {
        assert_true(in_array('blob:', $POLICY[$d] ?? [], true), "$d: blob:");
        assert_true(in_array('https://mc.yandex.ru', $POLICY[$d] ?? [], true), "$d: https://mc.yandex.ru");
    }
});

// --------------------------------------------------------------------------
//  Разметка сайта под эту политику
// --------------------------------------------------------------------------

test('на страницах нет встроенных исполняемых <script>', function () use ($PUB) {
    foreach (csp_files($PUB, ['php', 'html']) as $f) {
        $src = csp_read($f);
        if (!preg_match_all('~<script\b([^>]*)>~i', $src, $m)) { continue; }
        foreach ($m[1] as $attrs) {
            if (preg_match('~\bsrc\s*=~i', $attrs)) { continue; }
            // Данные (JSON-LD и т. п.) браузер не исполняет, и CSP их не трогает.
            if (preg_match('~\btype\s*=\s*"application/(ld\+)?json"~i', $attrs)) { continue; }
            assert_true(false, basename($f) . ': встроенный <script' . $attrs . '> — браузер его не исполнит');
        }
    }
});

test('нет обработчиков on*= и ссылок javascript:', function () use ($PUB) {
    foreach (csp_files($PUB, ['php', 'html', 'js']) as $f) {
        $src = csp_read($f);
        assert_eq(0, preg_match('~<[a-z][^<>]*\son[a-z]+\s*=\s*["\']~i', $src), basename($f) . ': атрибут on*= в разметке');
        // Упоминать javascript: можно (news_blocks.php и news_save.php такие
        // ссылки вырезают), ставить в атрибут — нет.
        assert_eq(0, preg_match('~\b(href|src|action)\s*=\s*["\']\s*javascript:~i', $src), basename($f) . ': javascript: в атрибуте');
    }
});

test('в своих скриптах нет eval и new Function', function () use ($PUB) {
    foreach (csp_files($PUB . '/js', ['js']) as $f) {
        $src = csp_read($f);
        assert_eq(0, preg_match('~\beval\s*\(|\bnew\s+Function\s*\(|set(Timeout|Interval)\s*\(\s*["\']~', $src),
            basename($f) . ': eval/new Function/таймер со строкой');
    }
});

test('Метрика подключается внешним файлом, асинхронно', function () use ($PUB) {
    $html = metrika_counter_html();
    assert_true((bool)preg_match('~<script src="/js/metrika\.js\?v=\d+" async></script>~', $html), 'тег js/metrika.js');
    $js = csp_read($PUB . '/js/metrika.js');
    assert_true(strpos($js, "'https://mc.yandex.ru/metrika/tag.js?id=" . METRIKA_ID . "'") !== false, 'tag.js с номером счётчика');
    assert_true(strpos($js, 'ym(' . METRIKA_ID . ", 'init'") !== false, 'инициализация');
});

test('ревизия тирлиста и id поста — в <meta>, флаг админки — файлом', function () use ($PUB) {
    $idx = csp_read($PUB . '/index.php');
    assert_true(strpos($idx, '<meta name="nx-rev" content="<?= (int)$nxRev ?>" />') !== false, 'index.php: nx-rev');
    assert_true(strpos(csp_read($PUB . '/js/app.js'), "document.querySelector('meta[name=\"nx-rev\"]')") !== false, 'app.js читает nx-rev');
    $news = csp_read($PUB . '/news.php');
    assert_true(strpos($news, '<meta name="nx-post" content="<?= (int)$linkedPostId ?>" />') !== false, 'news.php: nx-post');
    assert_true(strpos(csp_read($PUB . '/js/news-page.js'), "document.querySelector('meta[name=\"nx-post\"]')") !== false, 'news-page.js читает nx-post');
    assert_eq("window.NX_ADMIN_PAGE = true;\n", csp_read($PUB . '/js/admin-flag.js'), 'js/admin-flag.js');
    foreach (['admin.php', 'admin-news.php'] as $f) {
        assert_true((bool)preg_match('~/js/admin-flag\.js\?v=\d+~', csp_read("$PUB/$f")), "$f подключает admin-flag.js");
    }
});

run_tests();
