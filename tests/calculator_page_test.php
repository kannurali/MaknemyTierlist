<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Калькулятор трейдов — /calculator. Как и у home_page_test.php, здесь не
// вызываются функции (сама страница статичная, данные тянет клиент), а
// проверяется то, что не поймает ни компилятор, ни линтер: маршрут в
// .htaccess, ссылка на калькулятор с трёх остальных страниц, реально
// существующие ассеты, версии подключённых файлов.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function calc_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// --------------------------------------------------------------------------
//  Маршрут /calculator
// --------------------------------------------------------------------------

test('/calculator ведёт на calculator.php', function () use ($PUB) {
    $ht = calc_read($PUB . '/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^calculator$ /calculator.php [L]') !== false,
        'внутренний рероут /calculator → /calculator.php');
});

test('/calculator/ уводится 301-м на адрес без слэша, и раньше рероута', function () use ($PUB) {
    $ht = calc_read($PUB . '/.htaccess');
    $strip = strpos($ht, 'RewriteRule ^calculator/$ /calculator [L,R=301]');
    $route = strpos($ht, 'RewriteRule ^calculator$ /calculator.php [L]');
    assert_true($strip !== false, 'правило снятия слэша на месте');
    assert_true($route !== false, 'правило рероута на месте');
    assert_true($strip !== false && $route !== false && $strip < $route,
        'снятие слэша должно стоять раньше рероута');
});

// Прямой /calculator.php — дубль /calculator, поэтому уводится 301-м. Но
// внутренний рероут /calculator → /calculator.php сам попадает под этот
// паттерн: без условия на REDIRECT_STATUS получился бы бесконечный редирект.
test('прямой /calculator.php уводится на /calculator, и только снаружи', function () use ($PUB) {
    $ht = calc_read($PUB . '/.htaccess');
    assert_true((bool)preg_match(
        '/RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\R\s*RewriteRule \^calculator\\\\\.php\$ \/calculator \[L,R=301\]/',
        $ht), 'редирект calculator.php должен быть закрыт условием REDIRECT_STATUS');
});

test('калькулятор объявляет себя на /calculator', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true(strpos($calc, '<link rel="canonical" href="https://maknemytierlist.site/calculator" />') !== false,
        'canonical калькулятора');
    assert_true(strpos($calc, '<meta property="og:url" content="https://maknemytierlist.site/calculator" />') !== false,
        'og:url калькулятора');
});

test('в карте сайта есть /calculator', function () use ($PUB) {
    $map = calc_read($PUB . '/sitemap.xml');
    assert_true(strpos($map, '<loc>https://maknemytierlist.site/calculator</loc>') !== false, '/calculator в sitemap');
});

// --------------------------------------------------------------------------
//  Пилюля «Калькулятор» в шапке — на всех четырёх страницах
// --------------------------------------------------------------------------

test('пилюля «Калькулятор» ведёт на /calculator на всех страницах, включая саму себя', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php'] as $f) {
        $s = calc_read($PUB . '/' . $f);
        assert_true((bool)preg_match(
            '/<a class="mk-pill" href="\/calculator"[^>]*>\s*<svg viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001V13\.3/',
            $s), "$f: пилюля «Калькулятор» должна вести на /calculator");
        assert_eq(0, preg_match(
            '/<a class="mk-pill" href="#" aria-disabled="true"[^>]*>\s*<svg viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001V13\.3/',
            $s), "$f: пилюля «Калькулятор» не должна остаться отключённой");
    }
});

test('на самой странице калькулятора пилюля «Калькулятор» помечена текущей', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true((bool)preg_match(
        '/<a class="mk-pill" href="\/calculator" aria-current="page">\s*<svg viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001V13\.3/',
        $calc), 'aria-current="page" на пилюле калькулятора');
});

// Раздел «Трейдинг» другой пилюлей той же формы (viewBox 18x19) — правка
// калькулятора не должна была случайно расшевелить и её.
test('пилюля «Трейдинг» остаётся отключённой', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php'] as $f) {
        $s = calc_read($PUB . '/' . $f);
        assert_true((bool)preg_match(
            '/<a class="mk-pill" href="#" aria-disabled="true"[^>]*>\s*<svg viewBox="0 0 18 19"/',
            $s), "$f: «Трейдинг» должен оставаться отключённым");
    }
});

// --------------------------------------------------------------------------
//  Общий хром: шапка, фон, подвал
// --------------------------------------------------------------------------

test('калькулятор подключает общую шапку, фон и свой CSS', function () use ($PUB) {
    $s = calc_read($PUB . '/calculator.php');
    foreach (['css/base.css', 'css/topbar.css', 'css/design-page.css', 'css/calculator.css'] as $css) {
        assert_true(strpos($s, $css) !== false, "не подключён $css");
    }
    assert_true(strpos($s, '<header class="mk-top">') !== false, 'общая шапка сайта');
    assert_true(strpos($s, '<footer class="mk-foot">') !== false, 'общий подвал');
    assert_eq(5, substr_count($s, 'class="mk-foot-nick"'), 'пять ников в подвале');
});

test('шапка стоит перед основным контентом', function () use ($PUB) {
    $s = calc_read($PUB . '/calculator.php');
    $top = strpos($s, '<header class="mk-top">');
    $main = strpos($s, '<main class="tc-page">');
    assert_true($top !== false && $main !== false && $top < $main, 'шапка должна идти раньше main');
});

// --------------------------------------------------------------------------
//  Версии статики
// --------------------------------------------------------------------------

test('стили и скрипты калькулятора подключены с номером версии', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true((bool)preg_match('/href="css\/calculator\.css\?v=\d+"/', $calc), 'calculator.css с ?v=');
    assert_true((bool)preg_match('/src="js\/calc\.js\?v=\d+"/', $calc), 'calc.js с ?v=');
    assert_true((bool)preg_match('/src="js\/calculator-page\.js\?v=\d+"/', $calc), 'calculator-page.js с ?v=');
    assert_true((bool)preg_match('/src="js\/i18n\.js\?v=\d+"/', $calc), 'i18n.js с ?v=');
});

// i18n.js — общий файл: калькулятор дописал в него новые ключи (calc.*).
// Разные номера версий на разных страницах означают, что кто-то держит в
// кеше браузера старую копию словаря без этих ключей.
test('i18n.js подключён одной версией на тирлисте, ленте и калькуляторе', function () use ($PUB) {
    preg_match('/i18n\.js\?v=(\d+)/', calc_read($PUB . '/index.php'), $a);
    preg_match('/i18n\.js\?v=(\d+)/', calc_read($PUB . '/news.php'), $b);
    preg_match('/i18n\.js\?v=(\d+)/', calc_read($PUB . '/calculator.php'), $c);
    assert_true(!empty($a[1]) && !empty($b[1]) && !empty($c[1]), 'все три страницы подключают i18n.js');
    assert_eq($a[1], $b[1], 'версии i18n.js на тирлисте и ленте должны совпадать');
    assert_eq($a[1], $c[1], 'версии i18n.js на тирлисте и калькуляторе должны совпадать');
});

// --------------------------------------------------------------------------
//  Ассеты: всё, на что ссылается калькулятор, обязано лежать на диске.
// --------------------------------------------------------------------------

test('все локальные ассеты калькулятора существуют', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    preg_match_all('/(?:src|href)="([^"#?:]+)(?:\?[^"]*)?"/', $calc, $m);
    $checked = 0;
    foreach ($m[1] as $ref) {
        if (strpos(basename($ref), '.') === false) continue; // /, /tierlist, /news, /calculator — не файлы
        $path = $ref[0] === '/' ? $PUB . $ref : $PUB . '/' . $ref;
        assert_true(is_file($path), "нет файла: $ref");
        $checked++;
    }
    assert_true($checked > 8, "проверено ссылок: $checked — подозрительно мало");
});

// --------------------------------------------------------------------------
//  Каждый data-i18n* на странице ссылается на реальный ключ словаря — иначе
//  посетитель увидит буквально "calc.someKey" вместо текста.
// --------------------------------------------------------------------------

test('все calc.* ключи, использованные в разметке, определены в i18n.js', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    $i18n = calc_read($PUB . '/js/i18n.js');
    preg_match_all('/data-i18n(?:-title|-label|-placeholder)?="(calc\.[a-zA-Z]+)"/', $calc, $m);
    assert_true(count($m[1]) > 5, 'на странице должно быть больше пяти calc.* ключей в разметке');
    foreach (array_unique($m[1]) as $key) {
        assert_true(strpos($i18n, '"' . $key . '"') !== false, "ключ $key не определён в i18n.js");
    }
});

// --------------------------------------------------------------------------
//  Доступность: видимый :focus-visible, реальные подписи у иконочных кнопок.
// --------------------------------------------------------------------------

test('интерактивные элементы калькулятора получают видимый focus-visible', function () use ($PUB) {
    $css = calc_read($PUB . '/css/calculator.css');
    foreach (['.tc-search-input:focus-visible', '.tc-btn:focus-visible', '.tc-clear-side:focus-visible'] as $sel) {
        assert_true(strpos($css, $sel) !== false, "нет правила $sel");
    }
    assert_true(strpos($css, '@media (prefers-reduced-motion: reduce)') !== false,
        'должен быть блок prefers-reduced-motion');
});

test('кнопки-иконки (очистить сторону) несут aria-label, а не только глиф', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_eq(2, substr_count($calc, 'class="tc-clear-side"'), 'две кнопки очистки стороны');
    assert_true((bool)preg_match('/class="tc-clear-side" title="[^"]+" aria-label="[^"]+"/', $calc),
        'у кнопки очистки должны быть и title, и aria-label');
});

test('вердикт и разница объявлены как живая область для скринридера', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true((bool)preg_match('/<section class="tc-result" id="tcResult" role="status" aria-live="polite">/', $calc),
        'область итога должна быть role=status aria-live=polite');
});

// --------------------------------------------------------------------------
//  Текст в DOM — только через textContent, никогда через innerHTML.
// --------------------------------------------------------------------------

test('DOM-код калькулятора не использует innerHTML', function () use ($PUB) {
    $js = calc_read($PUB . '/js/calculator-page.js');
    assert_eq(0, substr_count($js, 'innerHTML'), 'innerHTML запрещён — только textContent');
});

// --------------------------------------------------------------------------
//  Чистая логика — отдельный DOM-free модуль (как js/news.js, js/tiers.js).
// --------------------------------------------------------------------------

test('calc.js не трогает DOM и экспортируется как CALC', function () use ($PUB) {
    $js = calc_read($PUB . '/js/calc.js');
    assert_eq(0, preg_match('/\bdocument\.|\bwindow\.(?!CALC)/', $js), 'calc.js должен быть свободен от DOM');
    assert_true(strpos($js, 'root.CALC = api;') !== false, 'модуль должен экспортироваться как CALC');
    assert_true(strpos($js, 'module.exports = api;') !== false, 'модуль должен быть доступен из node (require)');
});

run_tests();
