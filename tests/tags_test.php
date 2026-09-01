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

// Все значки — вектор из набора легенды. Имя файла не всегда равно коду
// типа в базе: "f" пишется значком FR, "p" — PM, а скины и мутации стали
// конфигурациями. Перевод кода в имя живёт в app.js (BADGE_FILE).
// Порядок — тот же, что в колонке типов легенды (тест ниже это и пинует).
$FILES = ['fr', 'cs', 'cm', 'ms', 'pm', 'gp', 'cr', 'vh'];
$ALIAS = ['f' => 'fr', 'p' => 'pm', 's' => 'cs', 'm' => 'cm', 'v' => 'vh'];

// --------------------------------------------------------------------------
//  Набор категорий в модалке администратора
// --------------------------------------------------------------------------

test('в модалке есть все семь категорий, включая ваучеры и конфигурации', function () use ($PUB) {
    $s = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    assert_true($from !== false, 'блок категорий должен быть на месте');
    $seg = substr($s, $from, strpos($s, '</div>', $from) - $from);

    // Порядок тот же, что в легенде на странице: админ выбирает то же самое,
    // что потом увидит читатель.
    // Скина и мутации в списке нет: обе стали конфигурациями, и старая
    // кнопка выдавала бы новым предметам тип, которого больше нет в легенде.
    $want = ['', 'cs', 'cm', 'ms', 'cr', 'gp', 'vh'];
    preg_match_all('/data-v="([^"]*)"/', $seg, $m);
    assert_eq($want, $m[1], 'состав и порядок категорий');
});

test('каждая категория подписана ключом словаря, и ключ определён', function () use ($PUB) {
    $s = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);
    $i18n = tag_read($PUB . '/js/i18n.js');

    preg_match_all('/data-i18n="(modal\.cat[A-Za-z]+)"/', $seg, $m);
    assert_eq(6, count($m[1]), 'подписаны все категории, кроме пустой');
    foreach ($m[1] as $key) {
        assert_true(strpos($i18n, '"' . $key . '"') !== false, "ключ $key не определён в i18n.js");
    }
});

// Кнопку подчёркивает цвет её значка — по нему видно, какой значок получит
// предмет, ещё до сохранения. Токен без значения даёт прозрачную полосу.
test('у каждой категории есть свой цветовой токен', function () use ($PUB) {
    $s    = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);
    preg_match_all('/class="t-([a-z]+)"/', $seg, $cls);

    $base   = tag_read($PUB . '/css/base.css');
    $styles = tag_read($PUB . '/css/styles.css');
    assert_eq(6, count($cls[1]), 'каждая непустая кнопка красится своим классом');
    foreach ($cls[1] as $t) {
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
test('для каждого типа есть файл значка на диске', function () use ($PUB, $FILES) {
    foreach ($FILES as $t) {
        assert_true(is_file($PUB . '/assets/design/legend/badge-' . $t . '.svg'),
            "нет assets/design/legend/badge-$t.svg");
    }
});

// Значок попадает и в экспорт PNG, а туда html2canvas кладёт растр размером
// не больше собственного размера картинки (inlineStageImages в app.js). На
// карточке значок занимает около 116 px по ширине: собственные 42 px
// растянулись бы втрое и дали мыльную надпись.
test('значки крупнее, чем место под них в экспорте', function () use ($PUB, $FILES) {
    foreach ($FILES as $t) {
        $svg = tag_read($PUB . '/assets/design/legend/badge-' . $t . '.svg');
        assert_true((bool)preg_match('/<svg width="(\d+)"/', $svg, $m), "нет ширины у badge-$t.svg");
        assert_true((int)$m[1] >= 120, "badge-$t.svg слишком мелкий: {$m[1]} px");
        assert_true(strpos($svg, 'viewBox="0 0 42 18"') !== false,
            "badge-$t.svg должен остаться в прежней системе координат");
    }
});

// Код типа в базе короче имени файла и старше его. Промах в таблице —
// битая картинка у читателя, а не ошибка в консоли разработчика.
test('старые коды типов ведут на существующие файлы', function () use ($PUB, $ALIAS) {
    $js = tag_read($PUB . '/js/app.js');
    assert_true((bool)preg_match('/const BADGE_FILE = \{([^}]*)\}/', $js, $m),
        'нужна таблица BADGE_FILE');
    foreach ($ALIAS as $type => $file) {
        assert_true((bool)preg_match('/\b' . $type . ':\s*"' . $file . '"/', $m[1]),
            "$type должен вести на badge-$file.svg");
        assert_true(is_file($PUB . '/assets/design/legend/badge-' . $file . '.svg'),
            "нет файла для алиаса $type");
    }
});

// Список категорий в app.js отставал от разметки: cs/cm/ms/vh в него не
// попали, и предмет с таким типом открывался «Обычным фруктом», а первое же
// сохранение записывало ему "f". Тип терялся молча.
test('кнопки категорий и CATEGORIES в app.js совпадают', function () use ($PUB) {
    $s    = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mType2">');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);
    preg_match_all('/data-v="([^"]+)"/', $seg, $m);

    $js = tag_read($PUB . '/js/app.js');
    assert_true((bool)preg_match('/const CATEGORIES = \[([^\]]*)\]/', $js, $c),
        'нужен список CATEGORIES');
    preg_match_all('/"([a-z]+)"/', $c[1], $got);
    assert_eq($m[1], $got[1], 'состав и порядок категорий');
});

// Путь строится в одном месте. Второе такое место разошлось бы с первым при
// первой же правке — новый значок появился бы на карточке, но не в просмотре.
test('путь к значку строится одной функцией', function () use ($PUB) {
    $js = tag_read($PUB . '/js/app.js');
    assert_true(strpos($js, 'function badgeSrc(type)') !== false, 'нужна badgeSrc()');
    // Комментарии вырезаем: пути упомянуты и в пояснении над функцией.
    $code = preg_replace('~//[^\n]*~', '', $js);
    assert_eq(1, preg_match_all('~"assets/design/legend/badge-" \+ ~', $code),
        'путь должен собираться ровно один раз');
    assert_eq(0, preg_match_all('~assets/badge-~', $code),
        'растровых значков старого тирлиста в коде больше нет');
    assert_eq(2, preg_match_all('~badgeSrc\((?:item|it)\.type\)~', $code),
        'оба места рендера обязаны звать badgeSrc');
});

// Легенда и карточка берут значок из одного набора. Разъедутся — читатель
// увидит на карточке одну букву, а в подсказке для новичков другую.
test('в легенде стоят те же файлы значков, что отдаёт badgeSrc', function () use ($PUB, $FILES) {
    $s    = tag_read($PUB . '/index.php');
    // Внутри колонки свои <div> на каждую строку, поэтому режем до начала
    // следующей колонки, а не до первого закрывающего тега.
    $from = strpos($s, '<div class="legend-col lc-types">');
    assert_true($from !== false, 'нужна колонка типов в легенде');
    $col  = substr($s, $from, strpos($s, 'lc-demand', $from) - $from);
    preg_match_all('~assets/design/legend/badge-([a-z]+)\.svg~', $col, $m);
    assert_eq($FILES, $m[1], 'состав и порядок значков в легенде');
});

// --------------------------------------------------------------------------
//  Спрос
// --------------------------------------------------------------------------

// Точек спроса пять, и «оверпрайс» — верх шкалы, а не сноска в конце: за
// такой предмет переплачивают, значит отдать его легче всего. Порядок сверху
// вниз тот же, что у оценок в DEMAND_WEIGHT (js/calc.js): 12, 10, 8, 5, 2.
// Кружок оверпрайса залит градиентом из картинки, поэтому у него есть файл,
// класс в CSS и подпись в словаре.
test('точка «оверпрайс» есть в легенде, в модалке и на диске', function () use ($PUB) {
    $s = tag_read($PUB . '/index.php');

    $from = strpos($s, '<div class="legend-col lc-demand">');
    assert_true($from !== false, 'нужна колонка спроса в легенде');
    $col  = substr($s, $from, strpos($s, 'lc-trends', $from) - $from);
    preg_match_all('/lgd d-([a-z]+)/', $col, $m);
    assert_eq(['neon', 'green', 'yellow', 'orange', 'red'], $m[1], 'состав и порядок точек спроса');

    $from = strpos($s, '<div class="seg" id="mDemand">');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);
    preg_match_all('/data-v="([^"]*)"/', $seg, $m);
    // Порядок кнопок в модалке повторяет легенду: админ жмёт ровно тот
    // кружок, который увидит читатель.
    assert_eq(['', 'neon', 'green', 'yellow', 'orange', 'red'], $m[1], 'кнопки спроса в модалке');

    // Путь на карточке собирается как "assets/dot-" + demand + ".png".
    foreach (['green', 'yellow', 'orange', 'red', 'neon'] as $d) {
        assert_true(is_file($PUB . '/assets/dot-' . $d . '.png'), "нет assets/dot-$d.png");
    }
    $css = tag_read($PUB . '/css/styles.css');
    assert_true(strpos($css, '.d-neon { --dc: url("../assets/dot-neon.png")') !== false,
        'точка в легенде должна брать ту же картинку, что и карточка');
    $i18n = tag_read($PUB . '/js/i18n.js');
    assert_eq(2, preg_match_all('/"legend\.neon":/', $i18n), 'подпись нужна на обоих языках');
});

// --------------------------------------------------------------------------
//  Тренд и флаги предмета
// --------------------------------------------------------------------------

// «?» и NEW переехали из тумблеров в сегмент тренда, но трендом не стали:
// у предмета может гореть стрелка И оба флага сразу. Если они попадут в
// выбор одного значения, стрелка будет гасить NEW и наоборот.
test('в тренде стрелки — один выбор, «?» и NEW — самостоятельные флаги', function () use ($PUB) {
    $s    = tag_read($PUB . '/index.php');
    $from = strpos($s, '<div class="seg" id="mTrend">');
    assert_true($from !== false, 'нужен сегмент тренда');
    $seg  = substr($s, $from, strpos($s, '</div>', $from) - $from);

    preg_match_all('/data-v="([^"]*)"/', $seg, $v);
    assert_eq(['', 'up', 'swap', 'down'], $v[1], 'трендом остаются только стрелки');
    preg_match_all('/data-flag="([a-z]+)"/', $seg, $f);
    assert_eq(['wip', 'flag'], $f[1], 'флаги помечены data-flag');

    // Порядок в ряду тот же, что в колонке трендов легенды: «?», стрелки, NEW.
    preg_match_all('/legend\/trend-([a-z]+)\.(?:svg|png)/', $seg, $img);
    assert_eq(['wip', 'up', 'swap', 'down', 'new'], $img[1], 'порядок и картинки как в легенде');

    // Тумблеров больше нет — иначе флаг редактировался бы из двух мест.
    assert_true(strpos($s, 'id="mNew"') === false, 'тумблер NEW должен быть убран');
    assert_true(strpos($s, 'id="mWip"') === false, 'тумблер «?» должен быть убран');

    $js = tag_read($PUB . '/js/app.js');
    assert_true(strpos($js, 'const SEG_VALUE = "button:not([data-flag])"') !== false,
        'выбор значения обязан пропускать кнопки-флаги');
    assert_true(strpos($js, 'if (btn.dataset.flag) { btn.classList.toggle("active"); return; }') !== false,
        'щелчок по флагу не должен трогать остальные кнопки');
    assert_eq(0, preg_match_all('/#mNew|#mWip/', $js), 'ссылок на тумблеры в коде не осталось');
});

// --------------------------------------------------------------------------
//  Группировка фильтров
// --------------------------------------------------------------------------

// Категорий четыре: фрукты, пермы, пассы, конфигураторы. Ваучеры идут вместе
// с пассами, а все скины, мутации и конфигурации — в конфигураторы. Ошибка
// здесь не падает, а молча прячет предмет из выбранной категории.
test('groupOf раскладывает все типы, включая снятые с производства', function () use ($PUB) {
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
    assert_true(strpos($news, '<body class="news-bg">') !== false,
        'лента должна помечать body своим классом');

    $css = tag_read($PUB . '/css/news-design.css');
    assert_true((bool)preg_match('/\.news-bg::before \{[^}]*position: fixed;/s', $css),
        'слой фона должен быть прибит к экрану');
    // Геометрия та же, что у body на остальных страницах (design-page.css):
    // иначе лента стоит на другом кадре фона, чем тирлист и главная.
    assert_true((bool)preg_match('/\.news-bg::before \{[^}]*background-position: calc\(-272 \* var\(--pu\)\) calc\(-533 \* var\(--pu\)\);/s', $css),
        'фон ленты должен стоять по макетной геометрии');
    // cover остаётся запасным вариантом для узких высоких экранов, где
    // макетной картинки не хватает по высоте.
    assert_true((bool)preg_match('/@media \(max-aspect-ratio: 1443\/2703\) \{[^}]*\{[^}]*background-size: cover;/s', $css),
        'на узком высоком экране нужен запасной cover');

    // Фон body обязан быть прозрачным: у html свой непрозрачный фон, поэтому
    // фон body не продвигается на канву и рисуется обычным слоем элемента —
    // а слой с z-index: -1 уходит ПОД него, и страница остаётся чёрной.
    assert_true((bool)preg_match('/\.news-bg \{ background: none; \}/', $css),
        'заливка на body закрыла бы собой фиксированный слой');
    // Своя картинка в шапке повторяет макетную геометрию страницы и на
    // нижней границе шапки давала бы стык двух кадров одной картинки.
    assert_true(strpos($css, '.news-bg .mk-top::before { display: none; }') !== false,
        'у шапки на ленте не должно быть своего фона');

    // .nw-body в news.css — это ТЕЛО ПОСТА внутри карточки. Фон страницы,
    // повешенный на такое имя, красил каждый пост непрозрачным чёрным вместо
    // стекла карточки и разворачивал полноэкранный фиксированный слой на
    // каждую карточку ленты.
    assert_eq(0, preg_match('/^\s*\.nw-body(::|\s*\{)/m', $css),
        'фон страницы не должен висеть на классе тела поста');
});

// --------------------------------------------------------------------------
//  Рекламные борта ленты
// --------------------------------------------------------------------------

// Пока слот не куплен, борта скрыты. Раньше они оставались полосатыми
// панелями «свободного места», и на живом сайте это читалось как поломка
// вёрстки: две белые панели во всю высоту экрана по бокам ленты. Борта
// тирлиста (.ptn-rail в styles.css) ведут себя ровно так же.
test('пустые рекламные борта ленты скрыты, пока нет кампании', function () use ($PUB) {
    $news = tag_read($PUB . '/news.php');
    assert_eq(2, preg_match_all('/<div class="nw-rail-slot nw-rail-[lr]" aria-hidden="true" hidden>/', $news),
        'оба борта должны стоять в разметке скрытыми');

    // display у слота перебивает браузерный display: none для [hidden] —
    // без явного правила скрытый борт всё равно занимал бы место.
    $css = tag_read($PUB . '/css/news-design.css');
    assert_true(strpos($css, '.nw-rail-slot[hidden] { display: none; }') !== false,
        'нужно явное правило для [hidden]');

    // Показывает борт только реальный креатив: hidden снимается там же, где
    // в панель вставляется картинка, а не заранее по факту ответа API.
    $js = tag_read($PUB . '/js/news-page.js');
    assert_true(strpos($js, 'if (el.parentElement) el.parentElement.hidden = false;') !== false,
        'борт открывается только под найденный креатив');
    $from = strpos($js, 'function fillNewsRail');
    $to   = strpos($js, 'el.parentElement.hidden = false', $from);
    assert_true($from !== false && $to !== false && $to > $from,
        'снятие hidden должно жить внутри fillNewsRail()');
});

// Панели администратора вставляют свою навигацию сразу за <body>. Лента
// дописала тегу класс, и точный поиск "<body>" промахивался молча: панель
// открывалась без переходов между разделами.
// Размеры полосы прокрутки сняты с макета «новостискролинг»: дорожка 18,
// ползунок 29 (он шире дорожки и выступает за неё), радиус ползунка 12.
test('полоса прокрутки повторяет макет', function () use ($PUB) {
    $css = tag_read($PUB . '/css/design-page.css');
    assert_true((bool)preg_match('/html::-webkit-scrollbar \{[^}]*width: 29px;/s', $css),
        'полосе нужны 29px — ширина ползунка из макета');
    assert_true((bool)preg_match('/html::-webkit-scrollbar-track \{[^}]*background-clip: padding-box;/s', $css),
        'дорожка ужимается до макетных 18 прозрачными полями');
    assert_true((bool)preg_match('/html::-webkit-scrollbar-thumb \{[^}]*border-radius: 12px;/s', $css),
        'радиус ползунка из макета');
    assert_true((bool)preg_match('/html::-webkit-scrollbar-thumb \{[^}]*linear-gradient\(180deg, #61b5e9, #2d4aed\)/s', $css),
        'градиент ползунка из макета');
});

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
