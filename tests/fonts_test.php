<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Шрифты сайта.
//
// Регресс, который ловит этот файл: в Bootshaus-Regular.ttf восемь глифов
// были собраны СОСТАВНЫМИ ИЗ СОСТАВНЫХ — например Й = (И = отражённая N) +
// breve, то есть вложенность глубины два. Chrome такую цепочку не
// разворачивает и рисует только верхний уровень: вместо «Й» на экране
// оставалась одна голая галочка без основы, вместо «½» — огрызок дроби.
//
// Поймать это глазами почти невозможно: на сайте до появления профиля не было
// ни одного заголовка с Й в дисплейном шрифте, и буква жила сломанной
// незамеченной. Поэтому проверяем не вид, а структуру файла — читаем сам .ttf.
//
// Проверяется РЕАЛЬНЫЙ файл, а не maxp.maxComponentDepth: это поле —
// метаданные, их можно объявить какими угодно, а глифы оставить вложенными.

$ROOT = dirname(__DIR__);
$TTF  = $ROOT . '/public_html/assets/fonts/Bootshaus/Bootshaus-Regular.ttf';

// Символы, которые были сломаны. Список — не абстрактный «весь юникод», а
// ровно те восемь, что чинились: если однажды в шрифт вернут старую версию
// файла, тест назовёт их поимённо.
const FONT_FIXED_CHARS = [
    0x0419 => 'Й',  0x040D => 'Ѝ',
    0x013F => 'Ŀ',  0x0150 => 'Ő',  0x0170 => 'Ű',
    0x00BD => '½',  0x00BC => '¼',  0x00BE => '¾',
];

// --------------------------------------------------------------------------
//  Минимальный разбор TrueType: таблицы → cmap → loca → glyf
// --------------------------------------------------------------------------
// Своими руками, а не библиотекой: composer в проекте не используется, а
// нужно отсюда ровно одно число — знак numberOfContours у восьми глифов.

function ttf_tables(string $bin): array {
    $num = unpack('n', substr($bin, 4, 2))[1];
    $out = [];
    for ($i = 0; $i < $num; $i++) {
        $rec = substr($bin, 12 + $i * 16, 16);
        $tag = substr($rec, 0, 4);
        $out[$tag] = [
            'offset' => unpack('N', substr($rec, 8, 4))[1],
            'length' => unpack('N', substr($rec, 12, 4))[1],
        ];
    }
    return $out;
}

// cmap формата 4 (BMP, platform 3 / encoding 1) — им закодированы все
// интересующие нас символы.
function ttf_cmap4(string $bin, int $cmapOff): array {
    $n = unpack('n', substr($bin, $cmapOff + 2, 2))[1];
    $sub = null;
    for ($i = 0; $i < $n; $i++) {
        $r    = substr($bin, $cmapOff + 4 + $i * 8, 8);
        $plat = unpack('n', substr($r, 0, 2))[1];
        $enc  = unpack('n', substr($r, 2, 2))[1];
        $off  = unpack('N', substr($r, 4, 4))[1];
        if (($plat === 3 && $enc === 1) || ($plat === 0)) { $sub = $cmapOff + $off; break; }
    }
    if ($sub === null) { return []; }
    if (unpack('n', substr($bin, $sub, 2))[1] !== 4) { return []; }

    $segX2  = unpack('n', substr($bin, $sub + 6, 2))[1];
    $seg    = intdiv($segX2, 2);
    $endO   = $sub + 14;
    $startO = $endO + $segX2 + 2;
    $deltaO = $startO + $segX2;
    $rangeO = $deltaO + $segX2;

    $map = [];
    for ($i = 0; $i < $seg; $i++) {
        $end   = unpack('n', substr($bin, $endO   + $i * 2, 2))[1];
        $start = unpack('n', substr($bin, $startO + $i * 2, 2))[1];
        $delta = unpack('n', substr($bin, $deltaO + $i * 2, 2))[1];
        $range = unpack('n', substr($bin, $rangeO + $i * 2, 2))[1];
        if ($start === 0xFFFF) { continue; }
        for ($c = $start; $c <= $end && $c !== 0x10000; $c++) {
            if ($range === 0) {
                $gid = ($c + $delta) & 0xFFFF;
            } else {
                $gi  = $rangeO + $i * 2 + $range + ($c - $start) * 2;
                if ($gi + 2 > strlen($bin)) { continue; }
                $gid = unpack('n', substr($bin, $gi, 2))[1];
                if ($gid !== 0) { $gid = ($gid + $delta) & 0xFFFF; }
            }
            if ($gid !== 0) { $map[$c] = $gid; }
        }
    }
    return $map;
}

function ttf_loca(string $bin, array $t): array {
    $fmt      = unpack('n', substr($bin, $t['head']['offset'] + 50, 2))[1];  // indexToLocFormat
    $numGlyph = unpack('n', substr($bin, $t['maxp']['offset'] + 4, 2))[1];
    $off      = $t['loca']['offset'];
    $loca     = [];
    for ($i = 0; $i <= $numGlyph; $i++) {
        $loca[] = $fmt === 0
            ? unpack('n', substr($bin, $off + $i * 2, 2))[1] * 2
            : unpack('N', substr($bin, $off + $i * 4, 4))[1];
    }
    return $loca;
}

// Отрицательное numberOfContours = составной глиф. Пустой глиф (loca[i] ==
// loca[i+1]) контуров не имеет вовсе и составным не является.
function ttf_is_composite(string $bin, array $t, array $loca, int $gid): bool {
    if (!isset($loca[$gid + 1]) || $loca[$gid] === $loca[$gid + 1]) { return false; }
    $at = $t['glyf']['offset'] + $loca[$gid];
    $n  = unpack('n', substr($bin, $at, 2))[1];
    return $n > 0x7FFF;   // int16 < 0
}

// --------------------------------------------------------------------------
//  Проверки
// --------------------------------------------------------------------------

test('Bootshaus на месте и читается как TrueType', function () use ($TTF) {
    assert_true(is_file($TTF), 'файл шрифта существует');
    $bin = file_get_contents($TTF);
    assert_true(strlen($bin) > 1000, 'файл не обрезан');
    $t = ttf_tables($bin);
    foreach (['head', 'maxp', 'loca', 'glyf', 'cmap'] as $tag) {
        assert_true(isset($t[$tag]), "таблица $tag на месте");
    }
});

test('починенные символы собраны простыми глифами, а не составными из составных',
function () use ($TTF) {
    $bin  = file_get_contents($TTF);
    $t    = ttf_tables($bin);
    $cmap = ttf_cmap4($bin, $t['cmap']['offset']);
    assert_true(count($cmap) > 100, 'cmap разобран');
    $loca = ttf_loca($bin, $t);

    foreach (FONT_FIXED_CHARS as $cp => $ch) {
        $hex = sprintf('U+%04X', $cp);
        assert_true(isset($cmap[$cp]), "$ch ($hex) есть в шрифте");
        if (!isset($cmap[$cp])) { continue; }
        assert_eq(false, ttf_is_composite($bin, $t, $loca, $cmap[$cp]),
            "$ch ($hex) должен быть простым глифом — составной из составного не рисуется в Chrome");
    }
});

// Прописные и строчные в этом шрифте делят один глиф, поэтому строчные
// проверяем отдельно: если однажды им дадут собственные глифы, вложенность
// может вернуться незамеченной именно там.
test('строчные варианты починенных букв тоже простые', function () use ($TTF) {
    $bin  = file_get_contents($TTF);
    $t    = ttf_tables($bin);
    $cmap = ttf_cmap4($bin, $t['cmap']['offset']);
    $loca = ttf_loca($bin, $t);

    foreach ([0x0439 => 'й', 0x045D => 'ѝ', 0x0140 => 'ŀ', 0x0151 => 'ő', 0x0171 => 'ű'] as $cp => $ch) {
        if (!isset($cmap[$cp])) { continue; }   // нет в шрифте — нечего ломаться
        assert_eq(false, ttf_is_composite($bin, $t, $loca, $cmap[$cp]),
            sprintf('%s (U+%04X) должен быть простым глифом', $ch, $cp));
    }
    assert_true(true, 'строчные проверены');
});

// Шрифт раздаётся по собственному адресу из @font-face. Без ?v= браузер с
// прежней копией в кеше не увидит починку никогда: обновление base.css его не
// касается — адрес самого .ttf не менялся бы.
test('шрифт подключён с версией, а base.css её обгоняет', function () use ($ROOT) {
    $css = file_get_contents($ROOT . '/public_html/css/base.css');
    assert_true((bool)preg_match(
        '~url\("\.\./assets/fonts/Bootshaus/Bootshaus-Regular\.ttf\?v=(\d+)"\)~', $css, $m),
        'у Bootshaus в @font-face есть ?v=');
    assert_true((int)$m[1] >= 2, 'версия шрифта поднята после починки глифов');

    // Сам base.css тоже обязан приехать заново — иначе браузер не узнает про
    // новый адрес шрифта.
    foreach (glob($ROOT . '/public_html/*.php') as $page) {
        $s = file_get_contents($page);
        if (strpos($s, 'css/base.css?v=') === false) { continue; }
        preg_match('~css/base\.css\?v=(\d+)~', $s, $v);
        assert_true((int)$v[1] >= 10, basename($page) . ': версия base.css поднята вместе со шрифтом');
    }
});

run_tests();
