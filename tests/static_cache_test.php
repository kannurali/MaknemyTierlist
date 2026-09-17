<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Кеш статики. .htaccess разрешает браузеру хранить js и css год и не
// переспрашивать сервер: адрес вида app.js?v=75 никогда не меняет
// содержимого, новое содержимое приходит под новым номером. Держится это на
// одном инварианте — сайт ни разу не просит js/css без ?v=. Иначе файл,
// скачанный по голому адресу, застрял бы у посетителя на год. Тесты ниже и
// есть этот инвариант: теги на страницах, адреса, собранные в скриптах,
// @import в стилях.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

const SC_IMMUTABLE = 'public, max-age=31536000, immutable';

function sc_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// Все файлы с данными расширениями. images/ пропускается: там загрузки
// админки, на сервере их тысячи, и js/css среди них не бывает.
function sc_files(string $dir, array $exts, string $rel = ''): array {
    $out = [];
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') continue;
        $p = $dir . '/' . $n;
        if (is_dir($p)) {
            if ($rel === '' && $n === 'images') continue;
            $out = array_merge($out, sc_files($p, $exts, $rel . $n . '/'));
        } elseif (in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), $exts, true)) {
            $out[] = $rel . $n;
        }
    }
    sort($out);
    return $out;
}

function sc_is_local_asset(string $url): bool {
    if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) return false;
    return (bool)preg_match('~\.(?:js|css)(?:[?#].*)?$~i', $url);
}

function sc_has_version(string $url): bool {
    return (bool)preg_match('~[?&]v=[^&#]+~', $url);
}

// src у <script> и href у <link> в разметке. Адрес без пробелов и кавычек
// внутри: так мимо проходят заготовки для str_replace в admin.php и
// admin-news.php ('<script src="js/app.js' — это не ссылка, а начало тега,
// к которому дописывается продолжение).
function sc_tag_refs(string $html): array {
    $refs = [];
    preg_match_all('~<script\b[^>]*?\bsrc="([^"\'\s<>]*)"~i', $html, $m);
    foreach ($m[1] as $u) $refs[] = $u;
    preg_match_all('~<link\b[^>]*?\bhref="([^"\'\s<>]*)"~i', $html, $m);
    foreach ($m[1] as $u) $refs[] = $u;
    return array_values(array_filter($refs, 'sc_is_local_asset'));
}

// Строковые литералы, целиком похожие на путь к js/css: так скрипт
// подгружает файл сам (html2canvas для экспорта PNG).
function sc_literal_refs(string $js): array {
    preg_match_all('~(["\'`])([^"\'`\s]+\.(?:js|css)(?:\?[^"\'`\s]*)?)\1~i', $js, $m);
    return array_values(array_filter($m[2], 'sc_is_local_asset'));
}

// Какой Cache-Control .htaccess поставит файлу с таким именем: блоки
// <FilesMatch> по порядку, последний подошедший Header set побеждает — как
// у Apache, так и у LiteSpeed.
function sc_cache_control(string $htaccess, string $filename): ?string {
    preg_match_all('~<FilesMatch\s+"([^"]+)">(.*?)</FilesMatch>~s', $htaccess, $blocks, PREG_SET_ORDER);
    $value = null;
    foreach ($blocks as $b) {
        if (!preg_match('~' . str_replace('~', '\~', $b[1]) . '~', $filename)) continue;
        if (preg_match_all('~^\s*Header\s+set\s+Cache-Control\s+"([^"]*)"~mi', $b[2], $h)) {
            $value = end($h[1]);
        }
    }
    return $value;
}

// --------------------------------------------------------------------------
//  .htaccess
// --------------------------------------------------------------------------

test('js и css кешируются на год без переспроса', function () use ($PUB) {
    $ht = sc_read($PUB . '/.htaccess');
    foreach (['app.js', 'i18n.js', 'html2canvas.min.js', 'base.css', 'design-page.css'] as $f) {
        assert_eq(SC_IMMUTABLE, sc_cache_control($ht, $f), "Cache-Control для $f");
    }
});

test('картинки остаются на коротком кеше с перепроверкой', function () use ($PUB) {
    // Их имена рукописные и без ?v=: замена файла на месте обязана доходить
    // до посетителя.
    $ht = sc_read($PUB . '/.htaccess');
    foreach (['iphone.webp', 'logo-mk-square.png', 'og-home.jpg', 'favicon.ico'] as $f) {
        assert_eq('public, max-age=3600, must-revalidate', sc_cache_control($ht, $f), "Cache-Control для $f");
    }
});

test('годовой кеш не цепляет чужие расширения', function () use ($PUB) {
    $ht = sc_read($PUB . '/.htaccess');
    foreach (['index.php', 'state.json', 'module.mjs', 'app.js.map', 'robots.txt', 'Bootshaus-Regular.ttf',
              'oswald-latin.woff2', 'wordmark.svg', 'sitemap.xml', 'app.JS.php'] as $f) {
        assert_true(sc_cache_control($ht, $f) !== SC_IMMUTABLE, "$f не должен получать immutable");
    }
});

// --------------------------------------------------------------------------
//  Инвариант: без ?v= сайт js/css не просит
// --------------------------------------------------------------------------

test('теги на страницах подключают js/css только с ?v=', function () use ($PUB) {
    $seen = 0;
    foreach (sc_files($PUB, ['php', 'html']) as $f) {
        foreach (sc_tag_refs(sc_read("$PUB/$f")) as $url) {
            $seen++;
            assert_true(sc_has_version($url), "$f: $url без ?v=");
        }
    }
    assert_true($seen > 20, "ссылки вообще нашлись ($seen)");
});

test('скрипты подгружают js/css только с ?v=', function () use ($PUB) {
    foreach (sc_files($PUB . '/js', ['js']) as $f) {
        foreach (sc_literal_refs(sc_read("$PUB/js/$f")) as $url) {
            assert_true(sc_has_version($url), "js/$f: $url без ?v=");
        }
    }
});

test('стили не импортируют css без ?v=', function () use ($PUB) {
    foreach (sc_files($PUB, ['css']) as $f) {
        preg_match_all('~@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)~i', sc_read("$PUB/$f"), $m);
        foreach ($m[1] as $url) {
            if (!sc_is_local_asset($url)) continue;
            assert_true(sc_has_version($url), "$f: @import $url без ?v=");
        }
    }
});

test('версионные js/css лежат в css/ и js/', function () use ($PUB) {
    // Деплой выкладывает эти каталоги раньше страниц (api/deploy.php), чтобы
    // новая страница не попросила новый номер, пока на диске старый файл.
    foreach (sc_files($PUB, ['php', 'html']) as $f) {
        foreach (sc_tag_refs(sc_read("$PUB/$f")) as $url) {
            $path = ltrim(preg_replace('~[?#].*$~', '', $url), '/');
            assert_true((bool)preg_match('~^(?:css|js)/~', $path), "$f: $url вне css/ и js/");
            assert_true(is_file("$PUB/$path"), "$f: $url — файла нет");
        }
    }
});

test('один файл — один номер на всех страницах', function () use ($PUB) {
    // Страница с отставшим номером тянула бы старый код ещё год.
    $versions = [];
    foreach (sc_files($PUB, ['php', 'html']) as $f) {
        foreach (sc_tag_refs(sc_read("$PUB/$f")) as $url) {
            $path = ltrim(preg_replace('~[?#].*$~', '', $url), '/');
            preg_match('~[?&]v=([^&#]+)~', $url, $v);
            $versions[$path][$v[1] ?? ''][] = $f;
        }
    }
    foreach ($versions as $path => $byVersion) {
        $list = [];
        foreach ($byVersion as $ver => $files) $list[] = "v=$ver: " . implode(', ', array_unique($files));
        assert_eq(1, count($byVersion), "$path подключён с разными номерами — " . implode('; ', $list));
    }
});

// --------------------------------------------------------------------------
//  Самопроверка разборщиков — иначе инвариант мог бы молча ничего не ловить
// --------------------------------------------------------------------------

test('разборщики видят то, что должны', function () {
    assert_eq(['js/a.js', 'css/b.css?v=2'],
        sc_tag_refs('<script src="js/a.js" defer></script><link rel="stylesheet" href="css/b.css?v=2" />'),
        'теги');
    assert_eq([], sc_tag_refs('<script src="https://mc.yandex.ru/tag.js"></script><link href="/favicon.ico">'),
        'внешние и не js/css не считаются');
    assert_eq([], sc_tag_refs("str_replace('<script src=\"js/app.js', '...')"), 'заготовка для str_replace');
    assert_eq(['js/html2canvas.min.js'], sc_literal_refs('s.src = "js/html2canvas.min.js";'), 'литерал');
    assert_eq([], sc_literal_refs('console.warn("i18n.js не загружен")'), 'фраза с пробелами — не путь');
    assert_true(sc_has_version('js/a.js?v=3'), '?v=3');
    assert_true(sc_has_version('js/a.js?x=1&v=3'), '&v=3');
    assert_eq(false, sc_has_version('js/a.js?v='), 'пустой номер');
    assert_eq(false, sc_has_version('js/a.js?vv=3'), 'не тот параметр');
    assert_eq('b', sc_cache_control("<FilesMatch \"\\.js$\">\n Header set Cache-Control \"a\"\n</FilesMatch>\n"
        . "<FilesMatch \"^x\\.js$\">\n Header set Cache-Control \"b\"\n</FilesMatch>", 'x.js'), 'последний блок побеждает');
});

run_tests();
