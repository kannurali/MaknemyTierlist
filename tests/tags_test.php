<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Значки предметов и фильтры по категориям.
//
// Значок — это тип предмета, который админ выбирает в модалке и который потом
// подставляется в имя файла картинки. Три места (кнопки в разметке, словарь,
// файл значка на диске) обязаны сходиться: разъедутся — админ выберет
// категорию, а читатель увидит битую картинку.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function tag_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// Значки, доставшиеся от старого тирлиста растром, и пришедшие с новой
// легендой вектором. Разделение живёт ещё и в app.js (LEGACY_BADGES).
$LEGACY = ['f', 'p', 's', 'm', 'gp', 'cr'];
$MODERN = ['cs', 'cm', 'ms', 'vh'];

// --------------------------------------------------------------------------
//  Набор категорий в модалке администратора
// --------------------------------------------------------------------------

test('в модалке есть все девять категорий, включая ваучеры и конфигурации', function () use ($PUB) {
    $s = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    assert_true($from !== false, 'блок категорий должен быть на месте');
    $seg = substr($s, $from, strpos($s, '</div>', $from) - $from);

    // Порядок тот же, что в легенде на странице: админ выбирает то же самое,
    // что потом увидит читатель.
    $want = ['', 's', 'm', 'cs', 'cm', 'ms', 'cr', 'gp', 'vh'];
    preg_match_all('/data-v="([^"]*)"/', $seg, $m);
    assert_eq($want, $m[1], 'состав и порядок категорий');
});

test('каждая категория подписана ключом словаря, и ключ определён', function () use ($PUB) {
    $s = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);
    $i18n = tag_read($PUB . '/js/i18n.js');

    preg_match_all('/data-i18n="(modal\.cat[A-Za-z]+)"/', $seg, $m);
    assert_eq(8, count($m[1]), 'подписаны все категории, кроме пустой');
    foreach ($m[1] as $key) {
        assert_true(strpos($i18n, '"' . $key . '"') !== false, "ключ $key не определён в i18n.js");
    }
});

// Кнопку подчёркивает цвет её значка — по нему видно, какой значок получит
// предмет, ещё до сохранения. Токен без значения даёт прозрачную полосу.
test('у каждой категории есть свой цветовой токен', function () use ($PUB, $LEGACY, $MODERN) {
    $base   = tag_read($PUB . '/css/base.css');
    $styles = tag_read($PUB . '/css/styles.css');
    foreach (array_merge($LEGACY, $MODERN) as $t) {
        assert_true((bool)preg_match('/--t-' . $t . ':\s*#[0-9a-f]{3,8};/i', $base),
            "нет цвета --t-$t в base.css");
        assert_true(strpos($styles, '.t-' . $t . ' { --bc: var(--t-' . $t . '); }') !== false,
            "нет класса .t-$t в styles.css");
    }
});

// --------------------------------------------------------------------------
//  Файлы значков
// --------------------------------------------------------------------------

// Тип подставляется в путь к картинке. Нет файла — у читателя битый значок,
// и заметит это не разработчик, а посетитель.
test('для каждого типа есть файл значка на диске', function () use ($PUB, $LEGACY, $MODERN) {
    foreach ($LEGACY as $t) {
        assert_true(is_file($PUB . '/assets/badge-' . $t . '.png'), "нет assets/badge-$t.png");
    }
    foreach ($MODERN as $t) {
        assert_true(is_file($PUB . '/assets/design/legend/badge-' . $t . '.svg'),
            "нет assets/design/legend/badge-$t.svg");
    }
});

// Путь строится в одном месте. Второе такое место разошлось бы с первым при
// первой же правке — новый значок появился бы на карточке, но не в просмотре.
test('путь к значку строится одной функцией', function () use ($PUB) {
    $js = tag_read($PUB . '/js/app.js');
    assert_true(strpos($js, 'function badgeSrc(type)') !== false, 'нужна badgeSrc()');
    // Комментарии вырезаем: пути упомянуты и в пояснении над функцией.
    $code = preg_replace('~//[^\n]*~', '', $js);
    assert_eq(1, preg_match_all('~"assets/badge-" \+ ~', $code),
        'растровый путь должен собираться ровно один раз');
    assert_eq(2, preg_match_all('~badgeSrc\((?:item|it)\.type\)~', $code),
        'оба места рендера обязаны звать badgeSrc');
});

// --------------------------------------------------------------------------
//  Группировка фильтров
// --------------------------------------------------------------------------

// Категорий четыре: фрукты, пермы, пассы, конфигураторы. Ваучеры идут вместе
// с пассами, а все скины, мутации и конфигурации — в конфигураторы. Ошибка
// здесь не падает, а молча прячет предмет из выбранной категории.
test('groupOf раскладывает все девять типов по четырём фильтрам', function () use ($PUB) {
    $js = tag_read($PUB . '/js/app.js');
    $from = strpos($js, 'function groupOf(type)');
    assert_true($from !== false, 'нужна groupOf()');
    $fn = substr($js, $from, strpos($js, "\n  }", $from) - $from);

    assert_true((bool)preg_match('/type === "p"\)\s*return "perms"/', $fn), 'пермы');
    foreach (['gp', 'vh'] as $t) {
        assert_true(strpos($fn, 'type === "' . $t . '"') !== false && strpos($fn, '"passes"') !== false,
            "$t должен попадать в пассы");
    }
    foreach (['s', 'm', 'cs', 'cm', 'ms', 'cr'] as $t) {
        assert_true(strpos($fn, 'type === "' . $t . '"') !== false,
            "$t должен попадать в конфигураторы");
    }
    assert_true(strpos($fn, 'return "fruits"') !== false, 'остальное — фрукты');

    // Порядок проверок важен: «пассы» стоят до «конфигураторов», иначе vh
    // никогда бы туда не дошёл.
    assert_true(strpos($fn, '"passes"') < strpos($fn, '"configurators"'),
        'пассы должны проверяться раньше конфигураторов');
});

// --------------------------------------------------------------------------
//  Фон ленты новостей
// --------------------------------------------------------------------------

// Лента длиннее фоновой картинки: страница с двумя десятками постов уезжает
// на несколько экранов, картинка кончается, и дальше идёт сплошная тёмная
// заливка. Фон прибит к экрану — двигается только лента поверх него.
test('фон ленты прибит к экрану, а не едет со страницей', function () use ($PUB) {
    $news = tag_read($PUB . '/news.php');
    assert_true(strpos($news, '<body class="nw-body">') !== false,
        'лента должна помечать body своим классом');

    $css = tag_read($PUB . '/css/news-design.css');
    assert_true((bool)preg_match('/\.nw-body::before \{[^}]*position: fixed;/s', $css),
        'слой фона должен быть прибит к экрану');
    assert_true((bool)preg_match('/\.nw-body::before \{[^}]*cover/s', $css),
        'cover — иначе на высоком экране снизу останется та же тёмная полоса');
    // Своя картинка в шапке повторяет макетную геометрию страницы и на
    // нижней границе шапки давала бы стык двух кадров одной картинки.
    assert_true(strpos($css, '.nw-body .mk-top::before { display: none; }') !== false,
        'у шапки на ленте не должно быть своего фона');
});

// Панели администратора вставляют свою навигацию сразу за <body>. Лента
// дописала тегу класс, и точный поиск "<body>" промахивался молча: панель
// открывалась без переходов между разделами.
test('панели администратора находят body с атрибутами', function () use ($PUB) {
    foreach (['admin.php', 'admin-news.php'] as $f) {
        $s = tag_read($PUB . '/' . $f);
        assert_true(strpos($s, "preg_replace('~<body[^>]*>~'") !== false,
            "$f: тег body надо искать с атрибутами");
        assert_eq(0, substr_count($s, "preg_replace('~<body>~'"),
            "$f: точный поиск \"<body>\" промахнётся");
    }
});

run_tests();
