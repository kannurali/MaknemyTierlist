<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/admin_page.php';

// Буферизуем вывод всего файла целиком: без этого к моменту, когда второй
// тест ниже что-то печатает через header(), первый тест уже успел напечатать
// свою строку "ok - ..." напрямую в stdout — PHP считает заголовки
// отправленными и сыплет "headers already sent" в STDERR. Сам PHP сбрасывает
// буфер при завершении скрипта, порядок строк не меняется.
ob_start();

// Регресс, который этот файл ловит: ветку "переезд index.html/news.html на
// .php" смёржили, а admin.php/admin-news.php продолжали читать старые имена
// файлов через file_get_contents() — получали false и отвечали 500 голым
// текстом. /admin и /admin/news были полностью мертвы, и ни один тест этого
// не заметил: ни один тест не проверял реальную способность admin-страниц
// собрать разметку, только модульные функции вроде og.php.
//
// Ниже — не копия ожидаемой строки HTML (её пришлось бы вручную
// синхронизировать с каждой правкой вёрстки, и она ничего не гарантирует про
// реальный файл на диске), а прогон настоящих production-файлов: admin.php,
// admin-news.php и сама admin_render_public_page(). Если кто-то снова
// направит их на несуществующее имя, сработает та же 500-ветка, что и на
// бою, и assert-ы на длину/маркеры провалятся.

// --------------------------------------------------------------------------
//  admin_render_public_page() напрямую — безопасно вызывать в процессе теста,
//  is_file() отсекает всё до require.
// --------------------------------------------------------------------------

test('admin_render_public_page возвращает null на отсутствующий файл, а не падает и не отдаёт пустышку', function () {
    // Именно так выглядела причина бага: index.html/news.html пропали, а код
    // продолжал их искать. is_file() внутри admin_render_public_page() должен
    // отсечь это ДО require (require на несуществующий файл — необрабатываемый
    // fatal, try/catch его не ловит), и вернуть null.
    $missing = __DIR__ . '/../public_html/index.html';
    assert_true(!is_file($missing), 'проверка актуальна: index.html в public_html больше не существует');
    assert_eq(null, admin_render_public_page($missing), 'отсутствующий файл -> null');
});

// --------------------------------------------------------------------------
//  Дальше — сами /admin и /admin/news целиком, каждый в отдельном php-
//  процессе. Это не прихоть, а необходимость:
//   1) require_once внутри admin_render_public_page() исполняет index.php/
//      news.php один раз на процесс — повторный вызов в этом же процессе
//      (например, чтобы отдельно измерить длину публичной страницы) молча
//      вернул бы пустой буфер.
//   2) настоящая ошибочная ветка admin.php/admin-news.php заканчивается
//      exit — потребуй мы файл прямо в процессе теста, exit() при регрессии
//      убил бы весь тестовый прогон молча (exit без кода = успешный код
//      возврата), и сама регрессия осталась бы незамеченной вместо того,
//      чтобы провалить тест.
// --------------------------------------------------------------------------

// Запускает $absPath отдельным php-процессом с поддельной админской сессией
// и возвращает то, что он напечатал, — либо null, если процесс недоступен
// или страница не дошла до конца (упала в exit из ошибочной ветки).
// Командная строка дочернего php. Путь к странице уходит АРГУМЕНТОМ
// ($argv[1]), а не переменной окружения: префикс вида «VAR=value команда» —
// синтаксис POSIX-шелла, а shell_exec() на Windows исполняет строку через
// cmd.exe, где это не команда, а ошибка «не удаётся найти указанный путь».
// Оба теста ниже из-за этого падали на Windows, хотя admin.php и
// admin-news.php были полностью исправны — то есть красный тест означал не
// регрессию, а операционную систему разработчика.
//
// В $code у вызывающих сторон намеренно нет ни одной ДВОЙНОЙ кавычки:
// escapeshellarg() на Windows оборачивает аргумент в двойные кавычки, а
// встреченные внутри заменяет пробелами — код с "..." доехал бы до php
// покалеченным. Внутри дочернего кода поэтому только одинарные.
function admin_page_subprocess_cmd(string $php, string $code, string $absPath): string {
    // Windows определяем по разделителю пути. Сравнение с литералом
    // обратного слэша здесь требует экранирования и потому легко ломается
    // при любой правке файла — а падать этот тест будет уже по другой,
    // непонятной причине.
    $devnull = DIRECTORY_SEPARATOR === '/' ? '2>/dev/null' : '2>nul';
    return escapeshellarg($php) . ' -r ' . escapeshellarg($code)
        . ' ' . escapeshellarg($absPath) . ' ' . $devnull;
}

// Конфиг для дочернего процесса: админ — Roblox id 1, модератор — 2. Путь с
// прямыми слэшами: так он без экранирования доезжает через командную строку
// Windows.
function admin_test_config_path(): string {
    static $path = null;
    if ($path === null) {
        $path = str_replace(DIRECTORY_SEPARATOR, '/', (string)tempnam(sys_get_temp_dir(), 'nxcfg'));
        file_put_contents($path, '<?php return ' . var_export([
            'dsn' => 'sqlite::memory:', 'db_user' => '', 'db_pass' => '', 'images_dir' => '',
            'admin_ids' => ['1'], 'moderator_ids' => ['2'],
        ], true) . ';');
        $file = $path;
        register_shutdown_function(function () use ($file) { @unlink($file); });
    }
    return $path;
}

// Прогон страницы от лица $uid ('' — аноним, без сессии вовсе). Возвращает
// напечатанное и признак, дошла ли страница до конца (не ушла ли в exit).
// В коде дочернего php нет двойных кавычек — см. admin_page_subprocess_cmd().
// Вывод идёт в hex: shell_exec на Windows читает текстом и съедает CR, а 404
// сверяется байт в байт.
function admin_run_as(string $absPath, string $uid, string $uri = ''): ?array {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $marker = '___NX_ADMIN_PAGE_RENDER_COMPLETED___';
    $code = 'ob_start(function ($b) { return bin2hex($b); }); '
        . 'define(' . var_export('CONFIG_PATH', true) . ', ' . var_export(admin_test_config_path(), true) . '); ';
    if ($uri !== '') {
        $code .= '$_SERVER[' . var_export('REQUEST_URI', true) . '] = ' . var_export($uri, true) . '; '
            . 'parse_str((string)parse_url(' . var_export($uri, true) . ', PHP_URL_QUERY), $_GET); ';
    }
    if ($uid !== '') { $code .= 'session_start(); $_SESSION[' . var_export('user_id', true) . '] = ' . var_export($uid, true) . '; '; }
    $code .= 'require $argv[1]; echo ' . var_export($marker, true) . ';';
    $out = shell_exec(admin_page_subprocess_cmd($php, $code, $absPath));
    $out = is_string($out) ? (string)@hex2bin(trim($out)) : '';
    $pos = strpos($out, $marker);
    return ['html' => $pos === false ? $out : substr($out, 0, $pos), 'done' => $pos !== false];
}

function render_admin_page_in_subprocess(string $absPath): ?string {
    // Маркер печатается ПОСЛЕ require — если admin.php/admin-news.php ушли в
    // exit на ошибочной ветке, маркер в выводе не появится, и мы это увидим.
    $r = admin_run_as($absPath, '1');
    return ($r === null || !$r['done']) ? null : $r['html'];
}

// Тот же трюк для чистой публичной страницы (без сессии/шапки админа) — она
// не должна дойти до exit ни при каких условиях (см. og_tierlist_data()/
// og_news_data(): любая ошибка откатывается на статичный баннер), поэтому
// здесь длина сравнивается напрямую, без маркера.
function render_length_in_subprocess(string $absPath): ?int {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $code = "define('TESTING',1); ob_start(); require \$argv[1]; echo strlen(ob_get_clean());";
    $out = shell_exec(admin_page_subprocess_cmd($php, $code, $absPath));
    return ($out === null || $out === false || $out === '') ? null : (int)$out;
}

test('/admin (admin.php) реально отдаёт разметку тирлиста, а не 500', function () {
    $html = render_admin_page_in_subprocess(__DIR__ . '/../public_html/admin.php');
    assert_true($html !== null, 'admin.php должен дорендериться до конца, не уйти в ошибочную 500-ветку');
    if ($html === null) { return; }

    assert_true(strpos($html, 'index.php не рендерится') === false, 'страница не ушла в ветку ошибки');
    assert_true(strlen($html) > 15000, 'это вся сцена (постер/тиры/легенда/модалки), а не заглушка');
    assert_true(strpos($html, 'id="stage"') !== false, 'сцена тирлиста присутствует');
    assert_true(strpos($html, 'id="modal"') !== false, 'модалка редактирования предмета присутствует');
    assert_true(strpos($html, 'window.NX_ADMIN_PAGE = true') !== false, 'флаг роли выставлен ДО app.js');
    assert_true(strpos($html, 'adm-nav') !== false, 'шапка админки вставлена');

    $publicLen = render_length_in_subprocess(__DIR__ . '/../public_html/index.php');
    if ($publicLen !== null) {
        // Админ-версия отличается от публичной только точечными правками
        // (заголовок вкладки, вырезанная метрика, вставленные шапка/CSS/флаг) —
        // разброс в пару тысяч байт, не на порядок.
        assert_true(abs(strlen($html) - $publicLen) < 3000,
            'длина admin.php (' . strlen($html) . ') далека от index.php (' . $publicLen . ') сильнее, чем на точечные правки');
    }
});

test('/admin/news (admin-news.php) реально отдаёт разметку ленты с редактором, а не 500', function () {
    $html = render_admin_page_in_subprocess(__DIR__ . '/../public_html/admin-news.php');
    assert_true($html !== null, 'admin-news.php должен дорендериться до конца, не уйти в ошибочную 500-ветку');
    if ($html === null) { return; }

    assert_true(strpos($html, 'news.php не рендерится') === false, 'страница не ушла в ветку ошибки');
    assert_true(strlen($html) > 5000, 'это лента плюс модалка редактора, а не заглушка');
    assert_true(strpos($html, 'id="feed"') !== false, 'лента новостей присутствует');
    assert_true(strpos($html, 'id="newsEditor"') !== false, 'модалка редактора поста вставлена');
    // Скрипт редактора встаёт сразу за news-page.js: тот объявляет
    // window.NEWSPAGE, без которого редактор молча ничего не делает. Сама
    // модалка при этом на месте, так что проверка выше такую поломку не видит.
    assert_true((bool)preg_match(
        '~<script\b[^>]*\bsrc="/js/news-page\.js[^"]*"[^>]*></script>\s*<script src="/js/news-editor\.js\?v=\d+"></script>~',
        $html), 'news-editor.js подключён сразу после news-page.js');
    assert_true(strpos($html, 'window.NX_ADMIN_PAGE = true') !== false, 'флаг роли выставлен');
    assert_true(strpos($html, 'adm-nav') !== false, 'шапка админки вставлена');

    $publicLen = render_length_in_subprocess(__DIR__ . '/../public_html/news.php');
    if ($publicLen !== null) {
        // Здесь разброс больше: admin-news.php добавляет целую модалку
        // редактора (восемь полей, кроп-редактор) — это не точечная правка,
        // проверяем только то, что админ-версия не МЕНЬШЕ публичной.
        assert_true(strlen($html) >= $publicLen,
            'admin-news.php (' . strlen($html) . ' байт) короче news.php (' . $publicLen . ' байт) — редактор потерялся');
    }
});

// --------------------------------------------------------------------------
//  Кого пускает панель
// --------------------------------------------------------------------------

$ADMIN_PHP   = __DIR__ . '/../public_html/admin.php';
$SUPPORT_PHP = __DIR__ . '/../public_html/admin-support.php';

// Чужому панель отвечает тем же, что LiteSpeed на maknemy.com отдаёт на
// несуществующий адрес: 1251 байт, два из них — CR. Ни кнопки входа, ни
// «нет доступа», ни слова о панели.
function assert_host_404(array $r, string $who): void {
    $html = $r['html'];
    assert_eq(false, $r['done'], "$who: запрос закончен");
    assert_eq(1251, strlen($html), "$who: тело байт в байт как у хостера");
    assert_true(strpos($html, "<title> 404 Not Found\r\n</title>") !== false, "$who: заголовок 404");
    assert_eq(1, substr_count($html, "Not Found\r\n</h2>"), "$who: второй CR на месте");
    assert_eq(2, substr_count($html, "\r"), "$who: других CR нет");
    foreach (['id="stage"', 'adm-', 'Roblox', 'MAKNEMY', 'admin'] as $leak) {
        assert_eq(false, strpos($html, $leak), "$who: в ответе нет $leak");
    }
}

test('анониму /admin и /admin/support отвечают 404 хостера', function () use ($ADMIN_PHP, $SUPPORT_PHP) {
    foreach ([$ADMIN_PHP, $SUPPORT_PHP] as $page) {
        $r = admin_run_as($page, '', '/admin?login=error');
        if ($r === null) { return; }
        assert_host_404($r, basename($page));
    }
});

test('вошедшему игроку без роли — тот же 404', function () use ($ADMIN_PHP, $SUPPORT_PHP) {
    foreach ([$ADMIN_PHP, $SUPPORT_PHP] as $page) {
        $r = admin_run_as($page, '555');
        if ($r === null) { return; }
        assert_host_404($r, basename($page));
    }
});

test('модератора редактор тирлиста не пускает', function () use ($ADMIN_PHP) {
    $r = admin_run_as($ADMIN_PHP, '2');
    if ($r === null) { return; }
    assert_eq(false, $r['done'], 'уведён к обращениям');
    assert_eq('', trim($r['html']), 'ни экрана, ни редактора');
});

test('модератор видит обращения и одну вкладку', function () use ($SUPPORT_PHP) {
    $r = admin_run_as($SUPPORT_PHP, '2');
    if ($r === null) { return; }
    assert_true($r['done'], 'страница отдана целиком');
    assert_true(strpos($r['html'], 'Центр обращений') !== false, 'обращения');
    assert_true(strpos($r['html'], 'href="/admin/support"') !== false, 'вкладка обращений');
    foreach (['/admin"', '/admin/news"', '/admin/promo"'] as $tab) {
        assert_eq(false, strpos($r['html'], 'href="' . $tab), "вкладки $tab нет");
    }
    assert_eq(false, strpos($r['html'], 'Уведомления в Telegram'), 'блока бота нет');
});

test('админ видит обращения со всеми вкладками и ботом', function () use ($SUPPORT_PHP) {
    $r = admin_run_as($SUPPORT_PHP, '1');
    if ($r === null) { return; }
    assert_true($r['done'], 'страница отдана целиком');
    foreach (['/admin"', '/admin/news"', '/admin/promo"', '/admin/support"'] as $tab) {
        assert_true(strpos($r['html'], 'href="' . $tab) !== false, "вкладка $tab");
    }
    assert_true(strpos($r['html'], 'Уведомления в Telegram') !== false, 'блок бота');
});

run_tests();
