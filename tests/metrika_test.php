<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/metrika.php';

// Счётчики статистики (Яндекс Метрика и Google Analytics) на публичных
// страницах — и их отсутствие на админских. Метрику снимали 2026-10-20 и
// вернули 2026-11-17 (история — в api/lib/metrika.php).
//
// Регресс, который этот файл ловит: счётчик стоял ТОЛЬКО в index.php. Главная,
// калькулятор и лента новостей не считались вовсе, и увидеть это по отчётам
// нельзя в принципе — раздел без счётчика выглядит там как «туда не ходят», а
// не как «там нечем считать». Проверяются поэтому не исходники страниц
// (счётчик подключается вызовом функции, и grep по mc.yandex.ru в home.php
// ничего не найдёт), а РЕАЛЬНЫЙ отрендеренный вывод каждой страницы.

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
//  Номер счётчика и цели
// --------------------------------------------------------------------------

// Номер — тот самый, с которым Метрика жила до снятия: новый счётчик начал
// бы историю и Вебвизор с нуля, а РСЯ привязывается к счётчику по номеру.
test('номер счётчика прежний', function () {
    assert_eq(111127188, METRIKA_ID, 'счётчик не менялся молча');
});

// Номер зашит литералом в трёх местах: PHP, js/metrika.js и js/promo.js —
// общей константы у PHP и браузерного кода нет. Разъедутся — хиты или цели
// начнут уходить в несуществующий счётчик, и ошибки в консоли при этом не
// будет.
test('номер счётчика один и тот же в PHP, js/metrika.js и js/promo.js', function () use ($PUB) {
    $id = (string)METRIKA_ID;
    $promo = mt_read($PUB . '/js/promo.js');
    assert_true((bool)preg_match('/\bvar METRIKA_ID = (\d+);/', $promo, $m), 'в promo.js должен быть METRIKA_ID');
    assert_eq($id, $m[1] ?? '', 'номер счётчика в promo.js должен совпадать с METRIKA_ID');
    assert_true(strpos($promo, 'root.ym(METRIKA_ID, "reachGoal", name, p)') !== false, 'PROMO.goal шлёт цель в Метрику');

    $js = mt_read($PUB . '/js/metrika.js');
    preg_match_all('/\d{6,}/', $js, $all);
    assert_eq([$id, $id], $all[0], 'в metrika.js номер встречается дважды (tag.js и init), и только этот');
});

// Раньше каждый скрипт звал ym(111127188, ...) сам — шесть копий номера и
// ни одной цели в GA. Теперь все цели идут через PROMO.goal(), и прямой вызов
// мимо него оставил бы один из счётчиков без цели.
test('цели шлются только через PROMO.goal(), прямых вызовов ym и gtag нет', function () use ($PUB) {
    foreach (glob($PUB . '/js/*.js') as $f) {
        $name = basename($f);
        if ($name === 'metrika.js' || $name === 'promo.js' || substr($name, -7) === '.min.js') { continue; }
        assert_eq(0, preg_match('/\bym\(|\bgtag\(\s*["\']event/', mt_read($f)), "$name: цель мимо PROMO.goal()");
    }
    $promo = mt_read($PUB . '/js/promo.js');
    assert_true(strpos($promo, 'root.gtag("event", name, g)') !== false, 'PROMO.goal шлёт цель в GA');
});

// --------------------------------------------------------------------------
//  Сама разметка счётчиков
// --------------------------------------------------------------------------

test('номер GA — пустой (выключен) или вида G-XXXXXXXXXX', function () {
    assert_true(GA_ID === '' || (bool)preg_match('/^G-[A-Z0-9]{4,16}$/', GA_ID), 'GA_ID: ' . GA_ID);
    assert_eq('', ga_counter_html(''), 'без номера блок GA не печатается');
});

test('блок Метрики собирается целиком, с маркерами и noscript, за ним — GA', function () use ($PUB) {
    $html = metrika_counter_html();
    $id = (string)METRIKA_ID;
    $close = '<!-- /Yandex.Metrika counter -->';
    assert_eq(0, strpos($html, '<!-- Yandex.Metrika counter -->'),
        'блок начинается маркером — по нему его вырезает админка');
    $end = strpos($html, $close);
    assert_true($end !== false, 'и закрывается маркером');
    assert_eq("\n" . ga_counter_html(), substr($html, $end + strlen($close)), 'за Метрикой — только блок GA');
    // Сам код счётчика — во внешнем js/metrika.js: CSP сайта не пускает
    // встроенные скрипты (см. tests/csp_test.php).
    assert_true((bool)preg_match('~<script src="/js/metrika\.js\?v=\d+" async></script>~', $html),
        'блок подключает js/metrika.js асинхронно');
    // <noscript> — единственный способ посчитать посетителя с отключённым JS.
    assert_true(strpos($html, 'https://mc.yandex.ru/watch/' . $id) !== false, 'noscript-пиксель на месте');
});

test('js/metrika.js: tag.js, init с Вебвизором, только боевой хост', function () use ($PUB) {
    $id = (string)METRIKA_ID;
    $js = mt_read($PUB . '/js/metrika.js');
    assert_true(strpos($js, "'https://mc.yandex.ru/metrika/tag.js?id=$id'") !== false, 'загрузка tag.js с номером счётчика');
    assert_true(strpos($js, "ym($id, 'init'") !== false, 'инициализация счётчика');
    // Вебвизор и карта кликов включены осознанно и раскрыты в /privacy; их
    // отключение выглядит безобидной правкой, а политика тогда разойдётся с
    // делом.
    foreach (['webvisor: true', 'clickmap: true', 'trackLinks: true', 'accurateTrackBounce: true'] as $opt) {
        assert_true(strpos($js, $opt) !== false, "опция $opt должна остаться включённой");
    }
    // Как и ga.js — только с боевого хоста: локальные прогоны и превью в
    // статистику не попадают, и tag.js там даже не грузится.
    assert_true(strpos($js, 'maknemy\\.com$/.test(m.location.hostname)') !== false, 'metrika.js ограничен maknemy.com');
});

// Ровно с этого начиналась проблема: копия разметки жила в index.php, и
// добавить её на остальные страницы никто не вспомнил.
test('разметка счётчиков лежит в одном месте, а не расползлась копиями', function () use ($PUB) {
    $copies = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($PUB));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
        $path = str_replace('\\', '/', $f->getPathname());
        if (substr($path, -strlen('api/lib/metrika.php')) === 'api/lib/metrika.php') { continue; }
        if (substr($path, -strlen('api/lib/google_analytics.php')) === 'api/lib/google_analytics.php') { continue; }
        $src = mt_read($path);
        foreach (['mc.yandex.ru/metrika/tag.js', 'mc.yandex.ru/watch/', 'js/metrika.js', 'googletagmanager.com/gtag', '/js/ga.js?v='] as $needle) {
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

test('metrika_strip() вырезает и Метрику, и GA и оставляет след', function () {
    $page = "<head>\n" . "<!-- Yandex.Metrika counter -->\n<script src=\"/js/metrika.js?v=2\" async></script>\n<!-- /Yandex.Metrika counter -->\n"
        . ga_counter_html('G-TEST123') . "</head>";
    $out = metrika_strip($page);
    assert_true(strpos($out, 'metrika.js') === false && strpos($out, 'Yandex.Metrika') === false, 'Метрика вырезана');
    assert_true(strpos($out, 'ga.js') === false && strpos($out, 'Google Analytics') === false, 'GA вырезан');
    assert_true(strpos($out, 'Счётчики вырезаны') !== false, 'след на месте');
    assert_true(strpos($out, "-->\n</head>") !== false, '</head> остался на своей строке');
});

// --------------------------------------------------------------------------
//  Публичные страницы
// --------------------------------------------------------------------------

test('счётчики приезжают на все публичные страницы, по одному разу и внутри <head>', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $page) {
        $html = mt_render_public($PUB . '/' . $page);
        assert_true($html !== null, "$page: страница должна отрендериться");
        if ($html === null) { continue; }

        assert_eq(1, substr_count($html, '<!-- Yandex.Metrika counter -->'), "$page: Метрика ровно одна");
        assert_true((bool)preg_match('~<script src="/js/metrika\.js\?v=\d+" async></script>~', $html),
            "$page: нет загрузки Метрики");
        assert_true(strpos($html, 'mc.yandex.ru/watch/' . METRIKA_ID) !== false, "$page: нет noscript-пикселя");
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
// поэтому счётчики приезжают к ним вместе со всей разметкой и снимаются уже из
// готового HTML — см. admin_render_public_page().
test('на админских страницах счётчиков нет', function () use ($PUB) {
    foreach (['admin.php' => 'id="stage"', 'admin-news.php' => 'id="feed"'] as $page => $mark) {
        $html = mt_render_admin($PUB . '/' . $page);
        assert_true($html !== null, "$page: страница должна отрендериться до конца");
        if ($html === null) { continue; }
        assert_true(strpos($html, $mark) !== false, "$page: это действительно разметка раздела");
        assert_true(strpos($html, 'mc.yandex.ru') === false && strpos($html, 'js/metrika.js') === false,
            "$page: свои клики и Вебвизор в статистике сайта — мусор");
        assert_true(strpos($html, 'js/ga.js') === false, "$page: и в GA тоже");
        assert_true(strpos($html, 'Счётчики вырезаны') !== false,
            "$page: на месте счётчика должен остаться след — иначе пропажу не отличить от того, что счётчика там и не было");
    }
});

run_tests();
