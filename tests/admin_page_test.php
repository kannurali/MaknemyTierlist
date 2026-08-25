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
function render_admin_page_in_subprocess(string $absPath): ?string {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    // Маркер печатается ПОСЛЕ require — если admin.php/admin-news.php ушли в
    // exit на ошибочной ветке, маркер в выводе не появится, и мы это увидим.
    $marker = '___NX_ADMIN_PAGE_RENDER_COMPLETED___';
    $code = 'session_start(); $_SESSION["admin"] = true; require getenv("NX_TARGET"); echo '
        . var_export($marker, true) . ';';
    $cmd = 'NX_TARGET=' . escapeshellarg($absPath) . ' ' . escapeshellarg($php)
        . ' -r ' . escapeshellarg($code) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if ($out === null || $out === false) { return null; }
    $pos = strpos($out, $marker);
    if ($pos === false) { return null; } // страница не дорендерилась до конца
    return substr($out, 0, $pos);
}

// Тот же трюк для чистой публичной страницы (без сессии/шапки админа) — она
// не должна дойти до exit ни при каких условиях (см. og_tierlist_data()/
// og_news_data(): любая ошибка откатывается на статичный баннер), поэтому
// здесь длина сравнивается напрямую, без маркера.
function render_length_in_subprocess(string $absPath): ?int {
    if (!function_exists('shell_exec') || !is_file($absPath)) { return null; }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $code = 'define("TESTING",1); ob_start(); require getenv("NX_TARGET"); echo strlen(ob_get_clean());';
    $cmd = 'NX_TARGET=' . escapeshellarg($absPath) . ' ' . escapeshellarg($php)
        . ' -r ' . escapeshellarg($code) . ' 2>/dev/null';
    $out = shell_exec($cmd);
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

run_tests();
