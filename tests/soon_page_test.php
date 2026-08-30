<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/soon.php';

// С TESTING определена — require выше не печатает ни байта HTML (весь шаблон
// обёрнут в `if (!defined('TESTING'))`, см. soon.php) и не трогает БД: он
// только объявляет soon_parse_section() и один раз молча вычисляет $section/
// $message из отсутствующего $_GET. Ниже тестируется чистая часть —
// разбор параметра — и статическая разметка трёх страниц, которые ведут сюда.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function read_file_or_fail_soon(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// --------------------------------------------------------------------------
//  soon_parse_section — только три известных значения, ничего похожего на
//  приведение произвольной строки (тот же принцип, что у news_parse_post_id()
//  в news.php).
// --------------------------------------------------------------------------

test('soon_parse_section принимает три известных значения', function () {
    assert_eq('trading', soon_parse_section('trading'));
    assert_eq('calculator', soon_parse_section('calculator'));
    assert_eq('profile', soon_parse_section('profile'));
});

test('soon_parse_section отклоняет пустую строку и отсутствие параметра', function () {
    assert_eq(null, soon_parse_section(''));
    assert_eq(null, soon_parse_section(null));
});

test('soon_parse_section отклоняет мусор и похожие, но неточные значения', function () {
    assert_eq(null, soon_parse_section('Trading'));       // регистр не совпадает
    assert_eq(null, soon_parse_section('trading '));      // пробел
    assert_eq(null, soon_parse_section(' trading'));
    assert_eq(null, soon_parse_section('trading;drop table news'));
    assert_eq(null, soon_parse_section('../../etc/passwd'));
    assert_eq(null, soon_parse_section('0'));
    assert_eq(null, soon_parse_section('null'));
    assert_eq(null, soon_parse_section('all'));
});

test('soon_parse_section отклоняет не-строки, а не приводит их к строке', function () {
    assert_eq(null, soon_parse_section(0));
    assert_eq(null, soon_parse_section(true));
    assert_eq(null, soon_parse_section(['trading']));
});

// --------------------------------------------------------------------------
//  Разметка /soon: не индексируется, несёт нужные ключи дословаря и версии.
// --------------------------------------------------------------------------

test('/soon помечена как неиндексируемая', function () use ($PUB) {
    $s = read_file_or_fail_soon($PUB . '/soon.php');
    assert_true(strpos($s, "header('X-Robots-Tag: noindex, follow');") !== false,
        'X-Robots-Tag должен запрещать индексацию, но разрешать переход по ссылкам');
    assert_true(strpos($s, '<meta name="robots" content="noindex, follow" />') !== false,
        'meta robots должна дублировать заголовок — часть краулеров не читает X-Robots-Tag');
});

test('/soon подключает общую шапку, фон и подвал', function () use ($PUB) {
    $s = read_file_or_fail_soon($PUB . '/soon.php');
    foreach (['css/base.css', 'css/topbar.css', 'css/design-page.css', 'css/soon.css'] as $css) {
        assert_true(strpos($s, $css) !== false, "не подключён $css");
    }
    assert_true(strpos($s, '<header class="mk-top">') !== false, 'общая шапка сайта');
    assert_true(strpos($s, '<footer class="mk-foot">') !== false, 'общий подвал');
});

test('стили и скрипты /soon подключены с номером версии', function () use ($PUB) {
    $s = read_file_or_fail_soon($PUB . '/soon.php');
    assert_true((bool)preg_match('/href="css\/soon\.css\?v=\d+"/', $s), 'soon.css с ?v=');
    assert_true((bool)preg_match('/src="js\/soon\.js\?v=\d+"/', $s), 'soon.js с ?v=');
    assert_true((bool)preg_match('/src="js\/i18n\.js\?v=\d+"/', $s), 'i18n.js с ?v=');
});

// Дата запуска нигде не называется — заказчик прямо просил не обещать то,
// чего никто не знает. Числа внутри <svg> (координаты иконок) вырезаны
// заранее: "2079" в пути значка — не год, а совпадение с шаблоном.
test('/soon не обещает дату или обратный отсчёт', function () use ($PUB) {
    $s = read_file_or_fail_soon($PUB . '/soon.php');
    $noSvg = preg_replace('/<svg\b.*?<\/svg>/su', '', $s);
    assert_eq(0, preg_match('/\b(202[6-9]|20[3-9]\d)\b/', $noSvg), 'дата/год запуска не должны упоминаться');
    assert_eq(0, (int)preg_match('/countdown|таймер|обратный отсч/ui', $s), 'обратного отсчёта быть не должно');

    $js = read_file_or_fail_soon($PUB . '/js/i18n.js');
    preg_match_all('/"soon\.[a-zA-Z]+":\s*"([^"]*)"/', $js, $m);
    assert_true(count($m[1]) > 0, 'должны найтись строки soon.* в дословаре');
    foreach ($m[1] as $value) {
        assert_eq(0, preg_match('/\b(202[6-9]|20[3-9]\d)\b/', $value), "строка дословаря обещает дату: $value");
    }
});

test('заголовок /soon называет раздел через дословарь, без плейсхолдеров в разметке', function () use ($PUB) {
    $s = read_file_or_fail_soon($PUB . '/soon.php');
    assert_true(strpos($s, 'data-i18n="<?= htmlspecialchars($message[\'key\']') !== false,
        'ключ заголовка должен выбираться сервером по $section');
    assert_eq(false, strpos($s, '{section}'), 'в разметке не должно остаться шаблонных плейсхолдеров');
});

// --------------------------------------------------------------------------
//  Три входа в шапке: «Трейдинг», «Калькулятор», «Профиль» — во всех трёх
//  файлах, которые несут .mk-top.
// --------------------------------------------------------------------------

test('«Трейдинг» больше не тупик ни в одном файле с шапкой', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php'] as $f) {
        $s = read_file_or_fail_soon($PUB . '/' . $f);
        assert_eq(0, preg_match('/<span class="mk-pill" aria-disabled="true"[^>]*>\s*<svg[^>]*viewBox="0 0 18 19"/s', $s),
            "$f: «Трейдинг» больше не должен быть отключённым span");
        assert_true((bool)preg_match('/<a class="mk-pill" href="\/soon\?section=trading">\s*<svg[^>]*viewBox="0 0 18 19"/s', $s),
            "$f: «Трейдинг» должен вести на /soon?section=trading");
    }
});

test('«Калькулятор» больше не тупик ни в одном файле с шапкой', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php'] as $f) {
        $s = read_file_or_fail_soon($PUB . '/' . $f);
        assert_eq(0, preg_match('/href="#"\s+aria-disabled="true"/', $s),
            "$f: href=\"#\" с aria-disabled должен был уйти");
        assert_true((bool)preg_match('/<a class="mk-pill" href="\/soon\?section=calculator">\s*<svg[^>]*viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001/s', $s),
            "$f: «Калькулятор» должен вести на /soon?section=calculator");
    }
});

test('«Профиль» больше не мёртвая кнопка ни в одном файле с шапкой', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php'] as $f) {
        $s = read_file_or_fail_soon($PUB . '/' . $f);
        assert_eq(0, preg_match('/<button class="mk-avatar"/', $s), "$f: <button class=\"mk-avatar\"> должен был уйти");
        assert_true(strpos($s, '<a class="mk-avatar" href="/soon?section=profile" aria-label="Профиль" title="Профиль">') !== false,
            "$f: аватар должен вести на /soon?section=profile и сохранить доступное имя «Профиль»");
    }
});

// Ключ topbar.tradingUnavailable стал мёртвым: «Трейдинг» больше не
// disabled, и title у него больше нет.
test('неиспользуемый ключ topbar.tradingUnavailable убран из дословаря', function () use ($PUB) {
    $js = read_file_or_fail_soon($PUB . '/js/i18n.js');
    assert_eq(0, substr_count($js, 'topbar.tradingUnavailable'), 'ключ должен был уйти вместе с disabled-состоянием');
    foreach (['home.php', 'index.php', 'news.php'] as $f) {
        $s = read_file_or_fail_soon($PUB . '/' . $f);
        assert_eq(0, substr_count($s, 'topbar.tradingUnavailable'), "$f: ссылки на мёртвый ключ быть не должно");
    }
});

// --------------------------------------------------------------------------
//  .htaccess: чистый адрес, 301 на слэше и на .php — тот же приём, что у
//  /tierlist.
// --------------------------------------------------------------------------

test('/soon/ уводится 301-м на адрес без слэша, и раньше внутреннего рероута', function () use ($PUB) {
    $ht = read_file_or_fail_soon($PUB . '/.htaccess');
    $strip = strpos($ht, 'RewriteRule ^soon/$ /soon [L,R=301]');
    $route = strpos($ht, 'RewriteRule ^soon$ /soon.php [L]');
    assert_true($strip !== false, 'правило снятия слэша на месте');
    assert_true($route !== false, 'правило рероута на месте');
    assert_true($strip !== false && $route !== false && $strip < $route,
        'снятие слэша должно стоять раньше рероута');
});

test('прямой /soon.php уводится на /soon, и только снаружи', function () use ($PUB) {
    $ht = read_file_or_fail_soon($PUB . '/.htaccess');
    assert_true((bool)preg_match(
        '/RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\R\s*RewriteRule \^soon\\\\\.php\$ \/soon \[L,R=301\]/',
        $ht), 'редирект soon.php должен быть закрыт условием REDIRECT_STATUS');
});

run_tests();
