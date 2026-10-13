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
    return $s;
}

// --------------------------------------------------------------------------
//  Сама разметка счётчика
// --------------------------------------------------------------------------

// Цели по клику на рекламу шлёт PROMO.goal() в js/promo.js, и номер счётчика
// там свой литерал: общей константы у PHP и браузерного кода нет. Разъедутся —
// цели начнут уходить в несуществующий счётчик, и ошибки в консоли при этом
// не будет.
test('номер счётчика один и тот же в PHP и в js/promo.js', function () use ($PUB) {
    $promo = mt_read($PUB . '/js/promo.js');
    assert_true((bool)preg_match('/\bvar METRIKA_ID = (\d+);/', $promo, $m), 'в promo.js должен быть METRIKA_ID');
    assert_eq((string)METRIKA_ID, $m[1] ?? '', 'номер счётчика в promo.js должен совпадать с METRIKA_ID');
    assert_true(strpos($promo, 'root.ym(METRIKA_ID, "reachGoal", name, p)') !== false, 'PROMO.goal шлёт цель в Метрику');
});

// Раньше каждый скрипт звал ym(111127188, ...) сам — шесть копий номера и
// ни одной цели в GA. Теперь все цели идут через PROMO.goal(), и прямой вызов
// мимо него снова оставил бы GA без цели.
test('цели шлются только через PROMO.goal(), прямых вызовов ym нет', function () use ($PUB) {
    foreach (glob($PUB . '/js/*.js') as $f) {
        $name = basename($f);
        if ($name === 'metrika.js' || $name === 'promo.js' || substr($name, -7) === '.min.js') { continue; }
        assert_eq(0, preg_match('/\bym\(|\bgtag\(\s*["\']event/', mt_read($f)), "$name: цель мимо PROMO.goal()");
    }
});

test('номер GA — пустой (выключен) или вида G-XXXXXXXXXX', function () {
    assert_true(GA_ID === '' || (bool)preg_match('/^G-[A-Z0-9]{4,16}$/', GA_ID), 'GA_ID: ' . GA_ID);
    assert_eq('', ga_counter_html(''), 'без номера блок GA не печатается');
});

test('блок счётчика собирается целиком, с маркерами и noscript', function () use ($PUB) {
    $html = metrika_counter_html();
    $id = (string)METRIKA_ID;
    $close = '<!-- /Yandex.Metrika counter -->';
    assert_eq(0, strpos($html, '<!-- Yandex.Metrika counter -->'),
        'блок начинается маркером — по нему его вырезает админка');
    $end = strpos($html, $close);
    assert_true($end !== false, 'и закрывается маркером');
    // Сразу за Метрикой — блок GA (или ничего, пока GA_ID пуст).
    assert_eq("\n" . ga_counter_html(), substr($html, $end + strlen($close)), 'за Метрикой — только блок GA');
    // Сам код счётчика — во внешнем js/metrika.js: CSP сайта не пускает
    // встроенные скрипты (см. tests/csp_test.php).
    assert_true((bool)preg_match('~<script src="/js/metrika\.js\?v=\d+" async></script>~', $html),
        'блок подключает js/metrika.js');
    $js = mt_read($PUB . '/js/metrika.js');
    assert_true(strpos($js, 'https://mc.yandex.ru/metrika/tag.js?id=' . $id) !== false,
        'загрузка tag.js с номером счётчика');
    assert_true(strpos($js, 'ym(' . $id . ", 'init'") !== false, 'инициализация счётчика');
    // Вебвизор и карта кликов включены осознанно — на них держится вся
    // аналитика поведения, а их отключение выглядит безобидной правкой.
    foreach (['webvisor:true', 'clickmap:true', 'trackLinks:true', 'accurateTrackBounce:true'] as $opt) {
        assert_true(strpos($js, $opt) !== false, "опция $opt должна остаться включённой");
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
        if (substr($path, -strlen('api/lib/google_analytics.php')) === 'api/lib/google_analytics.php') { continue; }
        $src = mt_read($path);
        foreach (['mc.yandex.ru/metrika/tag.js', 'js/metrika.js', 'googletagmanager.com/gtag', '/js/ga.js?v='] as $needle) {
            if (strpos($src, $needle) !== false) { $copies[] = "$path: $needle"; }
        }
    }
    assert_eq([], $copies, 'счётчики подключают вызовом metrika_counter_html(), а не копией разметки');
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

test('metrika_strip() вырезает и Метрику, и GA', function () {
    $page = "<head>\n" . "<!-- Yandex.Metrika counter -->\n<script src=\"/js/metrika.js?v=1\" async></script>\n<!-- /Yandex.Metrika counter -->\n"
        . ga_counter_html('G-TEST123') . "</head>";
    $out = metrika_strip($page);
    assert_true(strpos($out, 'metrika.js') === false, 'Метрика вырезана');
    assert_true(strpos($out, 'ga.js') === false && strpos($out, 'Google Analytics') === false, 'GA вырезан');
    assert_true(strpos($out, 'Метрика вырезана') !== false, 'след на месте');
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
        assert_true((bool)preg_match('~<script src="/js/metrika\.js\?v=\d+" async></script>~', $html),
            "$page: нет загрузки счётчика");
        assert_true(strpos($html, 'mc.yandex.ru/watch/' . METRIKA_ID) !== false,
            "$page: нет noscript-пикселя");
        assert_eq(GA_ID === '' ? 0 : 1, substr_count($html, '<!-- Google Analytics -->'),
            "$page: блок GA — ровно один при заданном GA_ID и ни одного без него");

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
        assert_true(strpos($html, 'js/ga.js') === false, "$page: и в GA тоже");
        assert_true(strpos($html, 'Метрика вырезана') !== false,
            "$page: на месте счётчика должен остаться след — иначе пропажу не отличить от того, что счётчика там и не было");
    }
});

run_tests();
