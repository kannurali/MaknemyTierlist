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
    assert_true(strpos($calc, '<link rel="canonical" href="https://maknemy.com/calculator" />') !== false,
        'canonical калькулятора');
    assert_true(strpos($calc, '<meta property="og:url" content="https://maknemy.com/calculator" />') !== false,
        'og:url калькулятора');
});

test('в карте сайта есть /calculator', function () use ($PUB) {
    $map = calc_read($PUB . '/sitemap.xml');
    assert_true(strpos($map, '<loc>https://maknemy.com/calculator</loc>') !== false, '/calculator в sitemap');
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
// «Трейдинг» — именно <button data-soon>, а не мёртвый <span> и не
// <a href="#">: кнопка кликается и по клику показывает «В активной
// разработке» (js/topbar.js), см. комментарий у самой пилюли. Проверяем
// форму, а не только атрибут, иначе возврат к ссылке-пустышке прошёл бы
// незамеченным.
test('пилюля «Трейдинг» остаётся отключённой', function () use ($PUB) {
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php'] as $f) {
        $s = calc_read($PUB . '/' . $f);
        assert_true((bool)preg_match(
            '/<button class="mk-pill" type="button" data-soon [^>]*>\s*<svg viewBox="0 0 18 19"/',
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
    foreach (['.tc-search-input:focus-visible', '.tc-btn:focus-visible', '.tc-slot:focus-visible'] as $sel) {
        assert_true(strpos($css, $sel) !== false, "нет правила $sel");
    }
    assert_true(strpos($css, '@media (prefers-reduced-motion: reduce)') !== false,
        'должен быть блок prefers-reduced-motion');
});

test('иконочная кнопка каталога несёт aria-label, а не только глиф', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true((bool)preg_match('/class="tc-cat-close"[^>]*aria-label="[^"]+"/', $calc),
        'у кнопки закрытия каталога должен быть aria-label');
});

// Доска повторяет макет: в нём сделка всегда пустая и на панелях нет ни
// «+» в слоте, ни крестика очистки стороны. Очистить всё можно кнопкой в
// служебной полосе, убрать один предмет — крестиком на самом слоте
// (.tc-slot-remove, его рисует JS уже занятому слоту).
test('на доске нет органов управления, которых нет в макете', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    $css  = calc_read($PUB . '/css/calculator.css');
    $js   = calc_read($PUB . '/js/calculator-page.js');
    assert_eq(0, substr_count($calc, 'tc-clear-side'), 'крестика очистки стороны в разметке быть не должно');
    assert_eq(0, substr_count($css, 'tc-clear-side'), 'правил .tc-clear-side в CSS быть не должно');
    assert_eq(0, substr_count($js, 'tc-clear-side'), 'обработчика .tc-clear-side в JS быть не должно');
    assert_true(strpos($css, '.tc-slot.is-empty::before') === false,
        'пустой слот в макете без «+»');
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

// --------------------------------------------------------------------------
//  Состояния исхода: цвета и лица сняты с компонент-сетов макета «Frame 40»
//  (столбики) и «Group 10» (карточка вердикта).
// --------------------------------------------------------------------------

test('цвета исхода совпадают с макетом', function () use ($PUB) {
    $css = calc_read($PUB . '/css/calculator.css');
    assert_true(strpos($css, '--tc-win: #48ff00;') !== false, 'выигрыш зелёный');
    assert_true(strpos($css, '--tc-lose: #ff0000;') !== false, 'проигрыш красный');
    assert_true(strpos($css, '--tc-fair: #00e8f0;') !== false, 'ничья бирюзовая');
    // Выигрыш и ничья однажды стояли наоборот: зелёный означал честную
    // сделку, бирюзовый — выгодную. Привязку столбика к токену держим тестом.
    foreach (['win' => '--tc-win', 'fair' => '--tc-fair', 'lose' => '--tc-lose'] as $state => $token) {
        $re = '/\.tc-gauge\[data-state="' . $state . '"\] > span \{\s*background: var\(' . preg_quote($token, '/') . '\)/u';
        assert_true((bool)preg_match($re, $css), "столбик в состоянии $state берёт $token");
    }
});

test('у значка вердикта своё лицо на каждое состояние', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    $css  = calc_read($PUB . '/css/calculator.css');
    foreach (['none', 'win', 'lose', 'fair'] as $state) {
        assert_true(strpos($calc, 'tc-face tc-face-' . $state) !== false, "в разметке есть лицо $state");
        $re = '/\.tc-result-badge\[data-verdict="' . $state . '"\]\s+\.tc-face-' . $state . '\b/u';
        assert_true((bool)preg_match($re, $css), "CSS показывает лицо $state");
    }
    assert_true(strpos($css, '.tc-face { display: none; }') !== false, 'остальные лица скрыты');
});

test('заголовок карточки вердикта переведён на оба языка', function () use ($PUB) {
    $i18n = calc_read($PUB . '/js/i18n.js');
    $js   = calc_read($PUB . '/js/calculator-page.js');
    foreach (['calc.verdictWinTitle', 'calc.verdictFairTitle', 'calc.verdictLoseTitle'] as $key) {
        assert_eq(2, substr_count($i18n, '"' . $key . '"'), "ключ $key должен быть и в ru, и в en");
        assert_true(strpos($js, $key) !== false, "renderResult должен использовать $key");
    }
});

test('каталог красит плашку названия градиентом своего типа', function () use ($PUB) {
    $css = calc_read($PUB . '/css/calculator.css');
    $js  = calc_read($PUB . '/js/calculator-page.js');
    foreach (['fr', 'cs', 'cm', 'ms', 'pm', 'gp', 'cr', 'vh'] as $code) {
        assert_true(strpos($css, '--tc-plate-' . $code . ':') !== false, "нет градиента типа $code");
    }
    assert_true(strpos($js, '"var(--tc-plate-" + code + ")"') !== false,
        'JS должен подставлять градиент типа в плашку');
});

test('палитра объявлена и для каталога: он лежит вне .tc-page', function () use ($PUB) {
    $css  = calc_read($PUB . '/css/calculator.css');
    $calc = calc_read($PUB . '/calculator.php');
    // Модалка — соседний узел main, а не его потомок. Пока токены жили
    // только на .tc-page, var(--tc-grad) и var(--tc-panel) внутри неё не
    // разрешались, и пилюля с плашками оставались без заливки.
    $mainEnd = strpos($calc, '</main>');
    $modal   = strpos($calc, 'class="tc-cat-backdrop"');
    assert_true($mainEnd !== false && $modal !== false && $modal > $mainEnd,
        'каталог должен лежать после </main> — если это изменилось, правило ниже можно упростить');
    assert_true((bool)preg_match('/\.tc-page,\s*\.tc-cat-backdrop \{/u', $css),
        'палитра должна объявляться и на .tc-cat-backdrop');
});

// Переключатель языка живёт в base.css и одинаков на калькуляторе, тирлисте
// и в ленте. Половинки когда-то скруглялись каждая сама — у активной справа
// оставалось скругление, у соседней слева был прямой угол, и на стыке торчала
// ступенька: пара читалась двумя кнопками вместо одного выбора.
test('переключатель языка — один сегментированный контрол, а не два чипа', function () use ($PUB) {
    $css = calc_read($PUB . '/css/base.css');
    assert_true((bool)preg_match('/\.lang-switch \{[^}]*border-radius: 999px;[^}]*background: rgba\(0, 0, 0, 0\.536\)/su', $css),
        'у пары должна быть общая дорожка');
    assert_true(strpos($css, '.lang-switch .chip:first-child') === false,
        'половинки не должны скруглять свои внешние углы сами');
    assert_true(strpos($css, '.lang-switch .chip:last-child') === false,
        'половинки не должны скруглять свои внешние углы сами');
    assert_true((bool)preg_match('/\.lang-switch \.chip\.active \{[^}]*linear-gradient\(255deg/su', $css),
        'выбранная половина — та же градиентная пилюля, что у кнопок шапки');

    // Место переключателя — общая шапка сайта, а не полоса под ней:
    // раньше он стоял на каждой странице по-своему (в тулбаре тирлиста и
    // ленты, в .tc-extras калькулятора), и три места разъезжались при
    // каждой правке. Разметку держит тест topbar_test.php, здесь — стили.
    $tb = calc_read($PUB . '/css/topbar.css');
    assert_true((bool)preg_match('/\.mk-top-lang \{/u', $tb),
        'у переключателя в шапке должна быть своя геометрия');
    assert_true(strpos($tb, '.mk-top.is-stuck .mk-top-lang') !== false,
        'при прокрутке переключатель уезжает вместе с логотипом');

    // Старое место обязано исчезнуть целиком: правило, оставшееся без
    // разметки, — это следующий разработчик, который правит мёртвый CSS.
    $dp = calc_read($PUB . '/css/design-page.css');
    assert_true(strpos($dp, '.toolbar .lang-switch') === false,
        'правил переключателя в панели фильтров больше быть не должно');
});

// Цены калькулятора — это цены тирлиста: отдельной копии нет, каталог собран
// из того же /api/tierlist.php. Открытая вкладка обязана их догонять, иначе
// правка в админке доезжала бы до неё только после F5.
test('калькулятор догоняет цены тирлиста, а не читает их один раз', function () use ($PUB) {
    $js = calc_read($PUB . '/js/calculator-page.js');
    assert_true(strpos($js, '/api/state.php') !== false,
        'опрос должен идти через дешёвый /api/state.php');
    assert_true(strpos($js, 'setInterval(poll, POLL_MS)') !== false,
        'опрос должен быть по таймеру');
    assert_true(strpos($js, 'visibilitychange') !== false,
        'и сразу при возврате на вкладку, без ожидания интервала');
    // Полный документ — только по смене rev, иначе опрос перестал бы быть
    // дешёвым и тянул бы весь тирлист каждые полминуты.
    assert_true(strpos($js, 'st.rev === lastRev') !== false,
        'без смены rev тирлист не перекачивается');
    assert_true(strpos($js, '"?rev=" + encodeURIComponent(rev)') !== false,
        'документ запрашивается по конкретной ревизии — её ответ immutable');
    // Сделка хранит сами предметы, а не только их id: без пересборки на доске
    // остались бы объекты со старой ценой.
    assert_true(strpos($js, 'function remapSides()') !== false,
        'после обновления каталога строки сделки надо пересобрать');
});

// Превью ссылки. На остальных страницах баннер «ВАША РЕКЛАМА» из превью уже
// убран (og_brand_card в api/lib/og.php); калькулятор оставался последним, кто
// на него ссылался, — и в чужом чате по ссылке на калькулятор показывалось
// объявление вместо калькулятора.
test('превью калькулятора — карточка вердикта, а не рекламный баннер', function () use ($PUB) {
    $calc = calc_read($PUB . '/calculator.php');
    assert_true(strpos($calc, 'content="https://maknemy.com/assets/og-calculator.jpg?v=1"') !== false,
        'og:image должен вести на превью калькулятора');
    // Комментарии вырезаны: старый адрес назван в одном из них как раз затем,
    // чтобы его сюда не вернули, и сам по себе он ничего не ломает.
    $markup = preg_replace('/<!--.*?-->/s', '', $calc);
    assert_eq(0, substr_count($markup, 'assets/og-image.jpg'),
        'баннер «ВАША РЕКЛАМА» в превью возвращаться не должен');

    // Размеры в мета-тегах и размеры файла обязаны совпадать: соцсети рисуют
    // рамку по тегам ещё до загрузки картинки, и расхождение видно рывком.
    $path = $PUB . '/assets/og-calculator.jpg';
    assert_true(is_file($path), 'нет assets/og-calculator.jpg');
    $size = getimagesize($path);
    assert_eq(1200, $size[0], 'ширина файла');
    assert_eq(630, $size[1], 'высота файла');
    assert_eq('image/jpeg', $size['mime'], 'формат файла');
    assert_true(strpos($calc, '<meta property="og:image:width" content="1200" />') !== false,
        'og:image:width должен совпадать с файлом');
    assert_true(strpos($calc, '<meta property="og:image:height" content="630" />') !== false,
        'og:image:height должен совпадать с файлом');
});

// Полос прокрутки на сайте две: страничная (design-page.css, правило по html)
// и внутренняя у сетки каталога. Обе сняты с одного макета и ужаты в одинаковых
// пропорциях. Разъедутся — на одной странице окажутся ползунки разной толщины,
// а заметить это можно только открыв каталог. Тест держит их одним числом.
test('ползунок каталога такой же ширины, как страничный', function () use ($PUB) {
    $calc = calc_read($PUB . '/css/calculator.css');
    $page = calc_read($PUB . '/css/design-page.css');

    assert_true((bool)preg_match('/\.tc-cat-grid::-webkit-scrollbar \{ width: (\d+)px;/', $calc, $a),
        'у сетки каталога должна быть своя ширина полосы');
    assert_true((bool)preg_match('/html::-webkit-scrollbar \{[^}]*width: (\d+)px;/s', $page, $b),
        'у страницы должна быть своя ширина полосы');
    assert_eq($b[1], $a[1], 'ширина ползунка одна на обе полосы');

    assert_true((bool)preg_match('/\.tc-cat-grid::-webkit-scrollbar-thumb \{[^}]*border-radius: (\d+)px;/s', $calc, $c),
        'у ползунка каталога должен быть радиус');
    assert_true((bool)preg_match('/html::-webkit-scrollbar-thumb \{[^}]*border-radius: (\d+)px;/s', $page, $d),
        'у страничного ползунка должен быть радиус');
    assert_eq($d[1], $c[1], 'радиус ползунка один на обе полосы');
});

run_tests();
