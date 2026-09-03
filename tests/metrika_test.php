<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/metrika.php';

// Счётчик Яндекс Метрики на публичных страницах — и его отсутствие на
// админских.
//
// Регресс, который этот файл ловит: счётчик стоял ТОЛЬКО в index.php. Главная,
// калькулятор и лента новостей не считались вовсе, и увидеть это по отчётам
// Метрики нельзя в принципе — раздел без счётчика выглядит там как «туда не
// ходят», а не как «там нечем считать». Проверяются поэтому не исходники
// страниц (счётчик подключается вызовом функции, и grep по mc.yandex.ru в
// home.php ничего не найдёт), а РЕАЛЬНЫЙ отрендеренный вывод каждой страницы.

$PUB = dirname(__DIR__) . '/public_html';

// Публичные страницы сайта. Все четыре стоят в шапке равноправными разделами,
// значит и считаться обязаны все четыре.
$PAGES = ['home.php', 'index.php', 'calculator.php', 'news.php'];

// --------------------------------------------------------------------------
//  Рендер отдельным процессом — по тем же причинам, что в
//  tests/admin_page_test.php: require_once исполняет страницу один раз на
//  процесс (вторая страница в том же процессе вернула бы пустой буфер), а
//  ошибочная ветка админки заканчивается exit и убила бы весь прогон молча.
//
//  В коде дочернего php нет ни одной ДВОЙНОЙ кавычки: escapeshellarg() на
//  Windows оборачивает аргумент в двойные кавычки, а встреченные внутри
//  заменяет пробелами.
// --------------------------------------------------------------------------
function mt_cmd(string $php, string $code, string $absPath): string {
    $devnull = DIRECTORY_SEPARATOR === '/' ? '2>/dev/null' : '2>nul';
    return escapeshellarg($php) . ' -r ' . escapeshellarg($code)
        . ' ' . escapeshellarg($absPath) . ' ' . $devnull;
}

// Публичная страница исполняется БЕЗ TESTING и без NX_ADMIN_RENDER, то есть
// ровно так, как её получает посетитель. Под TESTING половина страниц свою
// разметку вообще не печатает (см. `if (!defined('TESTING')):` в news.php) —
// тест на пустом выводе был бы зелёным при любом состоянии счётчика.
function mt_render_public(string $absPath): ?string {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php    = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $marker = '___NX_METRIKA_PUBLIC_RENDER_COMPLETED___';
    $code   = "require \$argv[1]; echo " . var_export($marker, true) . ";";
    $out    = shell_exec(mt_cmd($php, $code, $absPath));
    if ($out === null || $out === false) { return null; }
    $pos = strpos($out, $marker);
    return $pos === false ? null : substr($out, 0, $pos);
}

function mt_render_admin(string $absPath): ?string {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    // Маркер печатается ПОСЛЕ require: ушла страница в exit на ветке ошибки —
    // маркера не будет, и мы это увидим, а не примем 500 за «счётчика нет».
    $marker = '___NX_METRIKA_RENDER_COMPLETED___';
    $code = "session_start(); \$_SESSION['admin'] = true; require \$argv[1]; echo "
        . var_export($marker, true) . ";";
    $out = shell_exec(mt_cmd($php, $code, $absPath));
    if ($out === null || $out === false) { return null; }
    $pos = strpos($out, $marker);
    return $pos === false ? null : substr($out, 0, $pos);
}

function mt_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// --------------------------------------------------------------------------
//  Сама разметка счётчика
// --------------------------------------------------------------------------

// app.js шлёт цели (ym(..., 'reachGoal', ...)) по клику на рекламу, и номер
// счётчика там свой литерал: общей константы у PHP и браузерного кода нет.
// Разъедутся — цели начнут уходить в несуществующий счётчик, и ошибки в
// консоли при этом не будет.
test('номер счётчика один и тот же в PHP и в js/app.js', function () use ($PUB) {
    $app = mt_read($PUB . '/js/app.js');
    assert_true(preg_match_all('/\bym\(\s*(\d+)\s*,\s*"reachGoal"/', $app, $m) > 0,
        'в app.js должны быть цели Метрики');
    foreach ($m[1] as $id) {
        assert_eq((string)METRIKA_ID, $id, 'номер счётчика в app.js должен совпадать с METRIKA_ID');
    }
});

test('блок счётчика собирается целиком, с маркерами и noscript', function () {
    $html = metrika_counter_html();
    $id = (string)METRIKA_ID;
    assert_eq(0, strpos($html, '<!-- Yandex.Metrika counter -->'),
        'блок начинается маркером — по нему его вырезает админка');
    assert_true(substr(rtrim($html), -strlen('<!-- /Yandex.Metrika counter -->'))
        === '<!-- /Yandex.Metrika counter -->', 'и закрывающим маркером заканчивается');
    assert_true(strpos($html, 'https://mc.yandex.ru/metrika/tag.js?id=' . $id) !== false,
        'загрузка tag.js с номером счётчика');
    assert_true(strpos($html, 'ym(' . $id . ", 'init'") !== false, 'инициализация счётчика');
    // Вебвизор и карта кликов включены осознанно — на них держится вся
    // аналитика поведения, а их отключение выглядит безобидной правкой.
    foreach (['webvisor:true', 'clickmap:true', 'trackLinks:true', 'accurateTrackBounce:true'] as $opt) {
        assert_true(strpos($html, $opt) !== false, "опция $opt должна остаться включённой");
    }
    // <noscript> — единственный способ посчитать посетителя с отключённым JS.
    assert_true(strpos($html, 'https://mc.yandex.ru/watch/' . $id) !== false,
        'noscript-пиксель на месте');
});

// Ровно с этого начиналась проблема: копия разметки жила в index.php, и
// добавить её на остальные страницы никто не вспомнил.
test('разметка счётчика лежит в одном файле, а не расползлась копиями', function () use ($PUB) {
    $copies = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $path = str_replace('\\', '/', $f->getPathname());
        if (substr($path, -strlen('api/lib/metrika.php')) === 'api/lib/metrika.php') { continue; }
        if (strpos(mt_read($path), 'mc.yandex.ru/metrika/tag.js') !== false) { $copies[] = $path; }
    }
    assert_eq([], $copies, 'счётчик подключают вызовом metrika_counter_html(), а не копией разметки');
});

// --------------------------------------------------------------------------
//  Публичные страницы
// --------------------------------------------------------------------------

test('счётчик приезжает на все публичные страницы, по одному разу и внутри <head>', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $page) {
        $html = mt_render_public($PUB . '/' . $page);
        assert_true($html !== null, "$page: страница должна отрендериться");
        if ($html === null) { continue; }

        assert_eq(1, substr_count($html, '<!-- Yandex.Metrika counter -->'),
            "$page: счётчик должен быть ровно один");
        assert_true(strpos($html, 'tag.js?id=' . METRIKA_ID) !== false,
            "$page: нет загрузки счётчика");
        assert_true(strpos($html, 'mc.yandex.ru/watch/' . METRIKA_ID) !== false,
            "$page: нет noscript-пикселя");

        // Счётчик обязан стоять в <head>: из <body> он поехал бы позже
        // разметки и терял бы часть коротких визитов.
        $head = strpos($html, '</head>');
        $ym   = strpos($html, '<!-- Yandex.Metrika counter -->');
        assert_true($head !== false && $ym !== false && $ym < $head,
            "$page: счётчик должен стоять до </head>");
    }
});

// --------------------------------------------------------------------------
//  Админские страницы
// --------------------------------------------------------------------------

// /admin и /admin/news исполняют публичные index.php и news.php целиком,
// поэтому счётчик приезжает к ним вместе со всей разметкой и снимается уже из
// готового HTML — см. admin_render_public_page().
test('на админских страницах счётчика нет', function () use ($PUB) {
    foreach (['admin.php' => 'id="stage"', 'admin-news.php' => 'id="feed"'] as $page => $mark) {
        $html = mt_render_admin($PUB . '/' . $page);
        assert_true($html !== null, "$page: страница должна отрендериться до конца");
        if ($html === null) { continue; }
        assert_true(strpos($html, $mark) !== false, "$page: это действительно разметка раздела");
        assert_true(strpos($html, 'mc.yandex.ru') === false,
            "$page: свои клики и вебвизор в статистике сайта — мусор");
        assert_true(strpos($html, 'Метрика вырезана') !== false,
            "$page: на месте счётчика должен остаться след — иначе пропажу не отличить от того, что счётчика там и не было");
    }
});

run_tests();
