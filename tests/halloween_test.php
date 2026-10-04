<?php
define('TESTING', 1);
// Остальные тесты видят сайт без темы; здесь она включена (api/lib/halloween.php).
define('HALLOWEEN', true);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../tools/halloween_css.php';

// Хэллоуин-тема — api/lib/halloween.php, tools/halloween_css.php,
// css/hw/, css/halloween.css, assets/halloween/.
//
// Главные обещания:
//  - тема живёт ровно с 1 октября по 2 ноября по Москве, без правок кода;
//  - копии стилей в css/hw/ совпадают с тем, что генератор собрал бы из
//    сегодняшних css/*.css, — правка оригинала без перегенерации ловится здесь;
//  - каждая страница, где подключён перекрашиваемый файл, подключает в тему
//    копию с тем же ?v=, а вне темы — прежний файл;
//  - всё, на что ссылается тема, лежит на диске.

$PUB = dirname(__DIR__) . '/public_html';

// Рендер — до первого вывода раннера, иначе header() в странице ругается.
ob_start();
require $PUB . '/privacy.php';
$PRIVACY = (string)ob_get_clean();

function hw_pages(string $pub): array {
    $out = [];
    foreach (array_merge(glob("$pub/*.php"), ["$pub/api/lib/legal_page.php"]) as $f) {
        $out[substr($f, strlen($pub) + 1)] = file_get_contents($f);
    }
    return $out;
}

function hw_at(string $msk): DateTimeImmutable {
    return new DateTimeImmutable($msk, new DateTimeZone('Europe/Moscow'));
}

test('тема включена с 1 октября по 2 ноября по Москве', function () {
    assert_eq(false, halloween_on(hw_at('2026-09-30 23:59:59')), '30 сентября');
    assert_eq(true,  halloween_on(hw_at('2026-10-01 00:00:00')), '1 октября, полночь');
    assert_eq(true,  halloween_on(hw_at('2026-10-31 12:00:00')), 'сам Хэллоуин');
    assert_eq(true,  halloween_on(hw_at('2026-11-02 23:59:59')), '2 ноября, конец дня');
    assert_eq(false, halloween_on(hw_at('2026-11-03 00:00:00')), '3 ноября');
    assert_eq(false, halloween_on(hw_at('2027-03-15 12:00:00')), 'весна');
    assert_eq(true,  halloween_on(hw_at('2027-10-15 12:00:00')), 'следующий год');
});

test('граница считается по Москве, а не по часам сервера', function () {
    // 2 ноября 22:30 UTC — в Москве уже 3 ноября.
    assert_eq(false, halloween_on(new DateTimeImmutable('2026-11-02 22:30:00', new DateTimeZone('UTC'))), '3 ноября по Москве');
    // 30 сентября 21:30 UTC — в Москве уже 1 октября.
    assert_eq(true, halloween_on(new DateTimeImmutable('2026-09-30 21:30:00', new DateTimeZone('UTC'))), '1 октября по Москве');
});

test('цвета макета переводятся точно', function () {
    assert_eq([132, 0, 255], hw_color(0x61, 0xb5, 0xe9), '#61b5e9 → #8400ff');
    assert_eq([187, 0, 255], hw_color(0x2d, 0x4a, 0xed), '#2d4aed → #bb00ff');
    assert_eq([102, 0, 255], hw_color(0, 140, 255), 'свечение подвала');
    assert_eq(null, hw_color(255, 255, 255), 'белый не трогаем');
    assert_eq(null, hw_color(0, 255, 217), 'бирюзовый не трогаем');
    assert_eq(null, hw_color(229, 57, 53), 'красный не трогаем');
});

test('генератор не трогает селекторы и переносит пути на уровень глубже', function () {
    $css = "#add, #bad { color: #61b5e9; background: url(\"../assets/design/page-bg.webp\") }"
         . " .x { src: url(\"../assets/fonts/a.ttf?v=2\"); fill: url(#ace); color: rgba(45, 74, 237, .5) }";
    $out = hw_transform_css($css);
    assert_true(strpos($out, '#add, #bad {') === 0, 'id в селекторе остались');
    assert_true(strpos($out, 'color: #8400ff') !== false, 'цвет заменён');
    assert_true(strpos($out, 'url("../../assets/halloween/page-bg.webp")') !== false, 'фон заменён');
    assert_true(strpos($out, 'url("../../assets/fonts/a.ttf?v=2")') !== false, 'шрифт на уровень глубже');
    assert_true(strpos($out, 'url(#ace)') !== false, 'ссылка на градиент svg цела');
    assert_true(strpos($out, 'rgba(187, 0, 255, .5)') !== false, 'rgba с прозрачностью');
});

test('копии в css/hw/ свежие', function () {
    foreach (hw_css_plan() as [$name, $want, $have]) {
        assert_true($have !== null, "нет css/hw/$name — php tools/halloween_css.php");
        assert_true($want === $have, "css/hw/$name устарела — php tools/halloween_css.php");
    }
});

test('страница подключает копию с тем же номером, что и оригинал', function () use ($PUB) {
    $seen = 0;
    foreach (hw_pages($PUB) as $page => $html) {
        preg_match_all('~href="css/([a-z-]+\.css)\?v=(\d+)"~', $html, $orig, PREG_SET_ORDER);
        preg_match_all('~href="css/hw/([a-z-]+\.css)\?v=(\d+)"~', $html, $copy, PREG_SET_ORDER);
        $o = [];
        foreach ($orig as $m) { $o[$m[1]] = $m[2]; }
        $c = [];
        foreach ($copy as $m) { $c[$m[1]] = $m[2]; }
        foreach ($o as $file => $v) {
            if (!in_array($file, HW_CSS_FILES, true)) { continue; }
            $seen++;
            assert_eq($v, $c[$file] ?? null, "$page: css/hw/$file?v=$v рядом с css/$file?v=$v");
        }
        foreach ($c as $file => $v) {
            assert_eq($v, $o[$file] ?? null, "$page: у css/hw/$file есть оригинал с тем же ?v=");
        }
        if ($c) {
            assert_true(strpos($html, 'href="css/halloween.css?v=') !== false, "$page: декор css/halloween.css");
        }
    }
    assert_true($seen > 30, "ссылки вообще нашлись ($seen)");
});

test('всё, на что ссылается тема, лежит на диске', function () use ($PUB) {
    foreach (HW_ASSETS as $from => $to) {
        assert_true(is_file("$PUB/assets/$from"), "оригинал assets/$from");
        assert_true(is_file("$PUB/assets/$to"), "замена assets/$to");
    }
    foreach (HALLOWEEN_IMAGES as $from => $to) {
        assert_true(is_file("$PUB/$from"), $from);
        assert_true(is_file("$PUB/$to"), $to);
    }
    foreach (HALLOWEEN_DECOR as $items) {
        foreach ($items as $d) {
            assert_true(is_file("$PUB/assets/halloween/{$d[0]}.webp"), "assets/halloween/{$d[0]}.webp");
        }
    }
    foreach (hw_pages($PUB) + ['js/app.js' => file_get_contents("$PUB/js/app.js")] as $page => $html) {
        preg_match_all('~assets/halloween/[a-z0-9.-]+~', $html, $m);
        foreach ($m[0] as $path) {
            assert_true(is_file("$PUB/$path"), "$page: $path");
        }
    }
});

test('декор вызывается только для описанных страниц', function () use ($PUB) {
    $used = [];
    foreach (hw_pages($PUB) as $page => $html) {
        preg_match_all("~halloween_decor\('([a-z-]+)'~", $html, $m);
        foreach ($m[1] as $key) {
            assert_true(isset(HALLOWEEN_DECOR[$key]), "$page: halloween_decor('$key')");
            $used[$key] = true;
        }
    }
    foreach (array_keys(HALLOWEEN_DECOR) as $key) {
        assert_true(isset($used[$key]), "декор '$key' нигде не выводится");
    }
});

test('в тему страница отдаёт копии стилей и декор', function () use ($PRIVACY) {
    $html = $PRIVACY;
    foreach (['base.css', 'topbar.css', 'design-page.css', 'legal.css'] as $f) {
        assert_true(strpos($html, 'href="css/hw/' . $f . '?v=') !== false, "privacy: css/hw/$f");
        assert_eq(false, strpos($html, 'href="css/' . $f . '?v='), "privacy: без css/$f");
    }
    assert_true(strpos($html, 'href="css/halloween.css?v=') !== false, 'privacy: css/halloween.css');

    $decor = halloween_decor('trading');
    assert_eq(3, substr_count($decor, '<img class="hw-bat" src="assets/halloween/bat.webp"'), 'три мыши на /trading');
    assert_true(strpos($decor, '--r:-53.5deg') !== false, 'поворот из макета');
    assert_eq('', halloween_decor('stock'), 'у страницы без декора — пусто');
    assert_eq('assets/halloween/card-tier.webp', hw_img('assets/design/home/card-tier.webp'), 'карточка главной');
    assert_eq('assets/design/logo-mk.png', hw_img('assets/design/logo-mk.png'), 'чужая картинка как была');
});

run_tests();
