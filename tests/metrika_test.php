<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/metrika.php';

// Счётчик статистики (Google Analytics) на публичных страницах — и его
// отсутствие на админских. Имя файла осталось от Яндекс Метрики, которую
// сняли 2026-10-20 (почему — в api/lib/metrika.php).
//
// Регресс, который этот файл ловит: счётчик стоял ТОЛЬКО в index.php. Главная,
// калькулятор и лента новостей не считались вовсе, и увидеть это по отчётам
// нельзя в принципе — раздел без счётчика выглядит там как «туда не ходят», а
// не как «там нечем считать». Проверяются поэтому не исходники страниц
// (счётчик подключается вызовом функции, и grep по ga.js в home.php ничего не
// найдёт), а РЕАЛЬНЫЙ отрендеренный вывод каждой страницы.

$PUB = dirname(__DIR__) . '/public_html';

// Публичные страницы сайта. Четыре стоят в шапке равноправными разделами,
// пятая — профиль — открывается из меню пользователя в той же шапке. Значит и
// считаться обязаны все пять: страница, на которую есть ссылка из шапки, но
// нет счётчика, выглядит в отчётах как «туда не ходят».
$PAGES = ['home.php', 'index.php', 'calculator.php', 'news.php', 'profile.php'];

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
    // Админ — Roblox id 1 во временном конфиге. Путь с прямыми слэшами, в
    // коде нет двойных кавычек: см. mt_cmd().
    $cfg = str_replace(DIRECTORY_SEPARATOR, '/', (string)tempnam(sys_get_temp_dir(), 'nxcfg'));
    file_put_contents($cfg, '<?php return ' . var_export([
        'dsn' => 'sqlite::memory:', 'db_user' => '', 'db_pass' => '', 'images_dir' => '', 'admin_ids' => ['1'],
    ], true) . ';');
    $code = 'define(' . var_export('CONFIG_PATH', true) . ', ' . var_export($cfg, true) . '); '
        . 'session_start(); $_SESSION[' . var_export('user_id', true) . '] = ' . var_export('1', true) . '; '
        . 'require $argv[1]; echo ' . var_export($marker, true) . ';';
    $out = shell_exec(mt_cmd($php, $code, $absPath));
    @unlink($cfg);
    if ($out === null || $out === false) { return null; }
    $pos = strpos($out, $marker);
    return $pos === false ? null : substr($out, 0, $pos);
}

function mt_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return i18n_flatten($s);
}

// --------------------------------------------------------------------------
//  Метрика снята целиком
// --------------------------------------------------------------------------

// Полуснятый счётчик хуже любого из двух состояний: адрес tag.js без файла
// даёт 404 на каждой странице, ym() без счётчика молча теряет цели, а
// политика конфиденциальности обещает то, чего нет (или наоборот).
test('от Метрики не осталось ни загрузки, ни вызовов', function () use ($PUB) {
    assert_true(!is_file($PUB . '/js/metrika.js'), 'js/metrika.js удалён');
    $left = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $ext = strtolower($f->getExtension());
        $name = $f->getFilename();
        if (!in_array($ext, ['php', 'js', 'html', 'json'], true) && $name !== '.htaccess') { continue; }
        $path = str_replace('\\', '/', $f->getPathname());
        $src = (string)file_get_contents($path);
        foreach (['mc.yandex.', 'mc.webvisor.', 'yastatic.net', 'metrika.js', '111127188'] as $needle) {
            if (strpos($src, $needle) !== false) { $left[] = "$path: $needle"; }
        }
        if ($ext === 'js' && substr($name, -7) !== '.min.js' && preg_match('/\bym\(/', $src)) {
            $left[] = "$path: ym(";
        }
    }
    assert_eq([], $left, 'следы Метрики в public_html');
});

// Раньше каждый скрипт звал ym(111127188, ...) сам — шесть копий номера и
// ни одной цели в GA. Теперь все цели идут через PROMO.goal(), и прямой вызов
// мимо него разъехался бы с ним при следующей смене счётчика.
test('цели шлются только через PROMO.goal()', function () use ($PUB) {
    foreach (glob($PUB . '/js/*.js') as $f) {
        $name = basename($f);
        if ($name === 'promo.js' || substr($name, -7) === '.min.js') { continue; }
        assert_eq(0, preg_match('/\bgtag\(\s*["\']event/', mt_read($f)), "$name: цель мимо PROMO.goal()");
    }
    $promo = mt_read($PUB . '/js/promo.js');
    assert_true(strpos($promo, 'root.gtag("event", name, g)') !== false, 'PROMO.goal шлёт цель в GA');
});

// --------------------------------------------------------------------------
//  Сама разметка счётчика
// --------------------------------------------------------------------------

test('номер GA — пустой (выключен) или вида G-XXXXXXXXXX', function () {
    assert_true(GA_ID === '' || (bool)preg_match('/^G-[A-Z0-9]{4,16}$/', GA_ID), 'GA_ID: ' . GA_ID);
    assert_eq('', ga_counter_html(''), 'без номера блок GA не печатается');
});

test('metrika_counter_html() печатает ровно блок GA', function () {
    assert_eq(ga_counter_html(), metrika_counter_html(), 'других счётчиков нет');
});

// Ровно с этого начиналась проблема: копия разметки жила в index.php, и
// добавить её на остальные страницы никто не вспомнил.
test('разметка счётчика лежит в одном файле, а не расползлась копиями', function () use ($PUB) {
    $copies = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $path = str_replace('\\', '/', $f->getPathname());
        if (substr($path, -strlen('api/lib/google_analytics.php')) === 'api/lib/google_analytics.php') { continue; }
        $src = mt_read($path);
        foreach (['googletagmanager.com/gtag', '/js/ga.js?v='] as $needle) {
            if (strpos($src, $needle) !== false) { $copies[] = "$path: $needle"; }
        }
    }
    assert_eq([], $copies, 'счётчик подключают вызовом metrika_counter_html(), а не копией разметки');
});

test('блок GA: внешний js/ga.js с номером в data-id, внутри своих маркеров', function () use ($PUB) {
    $html = ga_counter_html('G-TEST123');
    assert_eq(0, strpos($html, '<!-- Google Analytics -->'), 'начинается маркером');
    assert_eq('<!-- /Google Analytics -->', substr(rtrim($html), -strlen('<!-- /Google Analytics -->')), 'и маркером заканчивается');
    assert_true((bool)preg_match('~<script src="/js/ga\.js\?v=\d+" data-id="G-TEST123" async></script>~', $html), 'тег js/ga.js');
    $js = mt_read($PUB . '/js/ga.js');
    assert_true(strpos($js, 'getAttribute("data-id")') !== false, 'ga.js берёт номер из data-id');
    // Статистика только с боевого хоста: локальные прогоны и превью не считаем.
    assert_true(strpos($js, 'maknemy\\.com$/.test(w.location.hostname)') !== false, 'ga.js ограничен maknemy.com');
});

test('metrika_strip() вырезает GA и оставляет след', function () {
    $page = "<head>\n" . ga_counter_html('G-TEST123') . "</head>";
    $out = metrika_strip($page);
    assert_true(strpos($out, 'ga.js') === false && strpos($out, 'Google Analytics') === false, 'GA вырезан');
    assert_true(strpos($out, 'Счётчик вырезан') !== false, 'след на месте');
    assert_true(strpos($out, "-->\n</head>") !== false, '</head> остался на своей строке');
});

// --------------------------------------------------------------------------
//  Публичные страницы
// --------------------------------------------------------------------------

test('счётчик приезжает на все публичные страницы, по одному разу и внутри <head>', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $page) {
        $html = mt_render_public($PUB . '/' . $page);
        assert_true($html !== null, "$page: страница должна отрендериться");
        if ($html === null) { continue; }

        assert_true(strpos($html, 'mc.yandex.') === false, "$page: Метрики нет");
        assert_eq(GA_ID === '' ? 0 : 1, substr_count($html, '<!-- Google Analytics -->'),
            "$page: блок GA — ровно один при заданном GA_ID и ни одного без него");
        if (GA_ID === '') { continue; }

        // Счётчик обязан стоять в <head>: из <body> он поехал бы позже
        // разметки и терял бы часть коротких визитов.
        $head = strpos($html, '</head>');
        $ga   = strpos($html, '<!-- Google Analytics -->');
        assert_true($head !== false && $ga !== false && $ga < $head,
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
        assert_true(strpos($html, 'js/ga.js') === false, "$page: свои клики и визиты в статистике сайта — мусор");
        if (GA_ID === '') { continue; }
        assert_true(strpos($html, 'Счётчик вырезан') !== false,
            "$page: на месте счётчика должен остаться след — иначе пропажу не отличить от того, что счётчика там и не было");
    }
});

run_tests();
