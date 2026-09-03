<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Общая шапка сайта (.mk-top) и разделы, которых на сайте ещё нет.
//
// Разметка шапки продублирована на трёх страницах — так на сайте сделан весь
// общий хром. Копии расходятся молча, поэтому здесь проверяется не одна
// страница, а совпадение всех трёх.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function top_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

/** Разметка шапки без комментариев: в комментариях рядом с кнопками разобрано,
 *  почему они такие, и те же слова встречаются в тексте пояснений. */
function top_header(string $pub, string $file): string {
    $page = preg_replace('/<!--.*?-->/s', '', top_read($pub . '/' . $file));
    $from = strpos($page, '<header class="mk-top">');
    if ($from === false) throw new RuntimeException("$file: нет шапки");
    return substr($page, $from, strpos($page, '</header>', $from) - $from);
}

$PAGES = ['home.php', 'index.php', 'news.php'];
// Калькулятор появился позже и несёт ту же шапку. В $PAGES его нет, чтобы не
// переписывать проверки, написанные до него; новые смотрят все четыре копии.
$PAGES4 = ['home.php', 'index.php', 'news.php', 'calculator.php'];

// --------------------------------------------------------------------------
//  Разделы в разработке
// --------------------------------------------------------------------------

// «Трейдинг» и профиль на сайт пока не выложены, и вести на них шапка не
// должна ни с одной страницы. Это <button data-soon>, а не мёртвый <span> и
// не href="#": кнопка кликается и по клику показывает «В активной
// разработке» (js/topbar.js). Проверяем форму, а не только атрибут: возврат
// к ссылке или к безответной пилюле прошёл бы незамеченным.
test('«Трейдинг» в шапке — кнопка data-soon, а не ссылка', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = top_header($PUB, $f);
        assert_true((bool)preg_match(
            '/<button class="mk-pill" type="button" data-soon [^>]*>\s*<svg viewBox="0 0 18 19"/',
            $s), "$f: «Трейдинг» должен быть кнопкой data-soon");
    }
});

// «Калькулятор» получил страницу (/calculator, см. tests/calculator_page_test.php)
// и вышел из разработки: пилюля — рабочая ссылка на всех трёх страницах, той
// же формы, что и раньше кнопка data-soon (только тег и атрибуты сменились).
test('«Калькулятор» в шапке — рабочая ссылка на /calculator, а не кнопка data-soon', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = top_header($PUB, $f);
        assert_true((bool)preg_match(
            '/<a class="mk-pill" href="\/calculator"[^>]*>\s*<svg viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001V13\.3/',
            $s), "$f: «Калькулятор» должен вести на /calculator");
        assert_eq(0, preg_match(
            '/<button class="mk-pill" type="button" data-soon [^>]*>\s*<svg viewBox="0 0 19 19"[^>]*><path d="M5\.70001 8\.55001V13\.3/',
            $s), "$f: «Калькулятор» не должен оставаться кнопкой data-soon");
    }
});

// Кнопка профиля — тот же случай, и она обязана лежать ВНУТРИ .mk-top-bar:
// разделы и профиль по редизайну один блок, а не плашка и отдельная кнопка
// рядом с ней.
test('профиль — кнопка data-soon внутри плашки навигации', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = top_header($PUB, $f);
        assert_true((bool)preg_match(
            '/<button class="mk-avatar" type="button" aria-label="Профиль" data-soon /', $s),
            "$f: профиль должен быть кнопкой data-soon");
        $bar = strpos($s, '<nav class="mk-top-bar"');
        $av  = strpos($s, '<button class="mk-avatar"');
        $end = strpos($s, '</nav>', $bar === false ? 0 : $bar);
        assert_true($bar !== false && $av !== false && $bar < $av && $av < $end,
            "$f: аватар должен лежать внутри .mk-top-bar");
    }
});

// Каждая кнопка data-soon обязана объяснять, почему не ведёт никуда: title
// для мыши и ключ словаря, чтобы объяснение переводилось вместе с сайтом.
// «Калькулятор» больше не в их числе — у него есть страница, см. тест выше.
test('у каждой кнопки data-soon есть подпись и ключ перевода', function () use ($PUB, $PAGES) {
    $i18n = top_read($PUB . '/js/i18n.js');
    assert_true(strpos($i18n, '"topbar.soon"') !== false, 'ключ topbar.soon в словаре');
    foreach ($PAGES as $f) {
        $s = top_header($PUB, $f);
        assert_eq(3, substr_count($s, 'data-soon'), "$f: трейдинг, чат и профиль");
        assert_eq(3, substr_count($s, 'data-i18n-title="topbar.soon"'), "$f: ключ на всех трёх");
        assert_eq(3, substr_count($s, 'title="В активной разработке"'), "$f: подпись на всех трёх");
    }
});

// На главной те же слова говорит карточка «Фрукты»: раздела под ней тоже нет,
// и отвечать она обязана так же, как шапка, — иначе на одном сайте живут два
// разных объяснения одного и того же. «Цены» из этого списка вышли: раздел
// готов, и карточка ведёт на /calculator (см. home_page_test.php).
test('неготовая карточка главной отвечает тем же сообщением', function () use ($PUB) {
    $home = preg_replace('/<!--.*?-->/s', '', top_read($PUB . '/home.php'));
    assert_eq(1, preg_match_all('/<div class="hm-card" aria-disabled="true" data-soon /', $home),
        'неготовая карточка помечена data-soon');
    assert_eq(0, substr_count($home, 'title="Раздел в разработке"'),
        'старая формулировка не должна остаться нигде');
    $css = top_read($PUB . '/css/home.css');
    assert_true((bool)preg_match('/\.hm-card\[data-soon\] \{[^}]*cursor: pointer;/s', $css),
        'карточка, которая отвечает на нажатие, обязана выглядеть нажимаемой');
});

// --------------------------------------------------------------------------
//  Поведение шапки
// --------------------------------------------------------------------------

// Поведение живёт в js/topbar.js: без него кнопки молчат в ответ на клик,
// а липкая шапка не сворачивается при прокрутке.
test('js/topbar.js подключён на всех страницах с версией', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = top_read($PUB . '/' . $f);
        assert_true((bool)preg_match('/src="js\/topbar\.js\?v=\d+" defer/', $s),
            "$f: нет js/topbar.js");
    }
    assert_true(is_file($PUB . '/js/topbar.js'), 'файл js/topbar.js должен существовать');
});

test('шапка липкая, и её высота живёт одним значением', function () use ($PUB) {
    $css = top_read($PUB . '/css/topbar.css');
    assert_true((bool)preg_match('/\.mk-top \{[^}]*position: sticky;[^}]*top: 0;/s', $css),
        'шапка должна быть липкой');
    // Высоту читают рекламные борта ленты. Разъедутся значения — липкие
    // блоки полезут друг на друга.
    assert_true(strpos($css, '--mk-top-h:') !== false, 'высота вынесена в переменную');
    assert_true(strpos($css, 'height: var(--mk-top-h);') !== false, 'шапка берёт высоту оттуда же');
    $nd = top_read($PUB . '/css/news-design.css');
    assert_true(strpos($nd, 'top: calc(var(--mk-top-h, 0px) + 16px);') !== false,
        'рекламный борт прилипает под шапкой');
});

// Прокрученная шапка убирает плашку разделов за край — язычок остаётся
// единственным способом вернуть меню. Без него навигации на прокрученной
// странице не будет вовсе, поэтому проверяем и кнопку, и связку с плашкой.
test('у шапки есть язычок, связанный с плашкой разделов', function () use ($PUB, $PAGES) {
    $i18n = top_read($PUB . '/js/i18n.js');
    foreach (['topbar.showNav', 'topbar.hideNav'] as $key) {
        assert_true(strpos($i18n, '"' . $key . '"') !== false, "ключ $key в словаре");
    }
    foreach ($PAGES as $f) {
        $s = top_header($PUB, $f);
        assert_true(strpos($s, '<nav class="mk-top-bar" id="mkTopBar"') !== false,
            "$f: у плашки должен быть id для aria-controls");
        assert_true((bool)preg_match(
            '/<button class="mk-top-toggle" type="button" id="mkTopToggle"\s+aria-expanded="[^"]+" aria-controls="mkTopBar"/s',
            $s), "$f: язычок должен управлять плашкой по aria-controls");
        assert_true(strpos($s, 'data-i18n-label="topbar.showNav"') !== false,
            "$f: подпись язычка должна переводиться");
    }
});

test('свёрнутая шапка ничего не оставляет ни на экране, ни в таб-порядке', function () use ($PUB) {
    $css = top_read($PUB . '/css/topbar.css');
    // Плашка уезжает за правый край, логотип — за левый. Без обрезки они
    // растягивали документ, и появлялась горизонтальная прокрутка на всю
    // ширину плашки.
    assert_true((bool)preg_match('/\.mk-top \{[^}]*overflow-x: clip;/s', $css),
        'шапка обязана обрезать уехавшие блоки');
    // visibility, а не одна opacity: прозрачный блок остался бы кликабельным
    // и в обходе по Tab.
    foreach (['.mk-top.is-stuck .mk-top-bar', '.mk-top.is-stuck .mk-top-brand'] as $sel) {
        assert_true((bool)preg_match('/' . preg_quote($sel, '/') . ' \{[^}]*visibility: hidden;/s', $css),
            "$sel: свёрнутый блок не должен оставаться в таб-порядке");
    }
    assert_true((bool)preg_match('/\.mk-top\.is-stuck \.mk-top-brand \{[^}]*translateX\(calc\(-100%/s', $css),
        'логотип уезжает за левый край');
    assert_true((bool)preg_match('/\.mk-top\.is-stuck \.mk-top-bar \{[^}]*translateX\(calc\(100%/s', $css),
        'плашка разделов уезжает за правый край');
});

// На телефоне шапка при прокрутке не меняется вовсе: она и так вдвое ниже
// десктопной, а прятать её там значит отнимать единственную навигацию.
test('на узких экранах шапка не сворачивается', function () use ($PUB) {
    $js = top_read($PUB . '/js/topbar.js');
    assert_true(strpos($js, 'matchMedia("(min-width: 761px)")') !== false,
        'режим сворачивания включается по ширине экрана');
    assert_true((bool)preg_match('/var next = WIDE\.matches && y > STICK_AT;/', $js),
        'класс is-stuck не должен вешаться на узком экране');
    // Окно можно растянуть мышью — поведение обязано переключиться без
    // перезагрузки страницы.
    assert_true(strpos($js, 'WIDE.addEventListener("change", sync)') !== false,
        'смена режима должна пересчитываться на лету');
});

// Кнопка чата появилась в макете шапки (Figma, нода 244:7171) — такой же
// круг с градиентом, что и профиль, слева от него. Раздела ещё нет, поэтому
// она такая же «в разработке», как «Трейдинг» и профиль.
test('чат — кнопка data-soon рядом с профилем, а не ссылка', function () use ($PUB, $PAGES4) {
    foreach ($PAGES4 as $f) {
        $s = top_header($PUB, $f);
        assert_true((bool)preg_match(
            '/<button class="mk-chat" type="button" aria-label="Чат" data-soon /', $s),
            "$f: чат должен быть кнопкой data-soon");
        // Порядок из макета: чат стоит ПЕРЕД профилем, оба внутри плашки.
        $bar  = strpos($s, '<nav class="mk-top-bar"');
        $chat = strpos($s, '<button class="mk-chat"');
        $av   = strpos($s, '<button class="mk-avatar"');
        $end  = strpos($s, '</nav>', $bar === false ? 0 : $bar);
        assert_true($bar !== false && $chat !== false && $bar < $chat && $chat < $av && $av < $end,
            "$f: чат должен лежать в .mk-top-bar перед профилем");
    }

    $css = top_read($PUB . '/css/topbar.css');
    // Волосяной разделитель отделяет разделы от пары круглых кнопок. С
    // приходом чата он обязан переехать на него: у профиля он оказался бы
    // посреди пары.
    assert_true(strpos($css, '.mk-chat::before') !== false,
        'разделитель рисует кнопка чата');
    assert_eq(0, preg_match('/\.mk-avatar::before \{/', $css),
        'у профиля своего разделителя быть не должно');
});

// --------------------------------------------------------------------------
//  Переключатель языка
// --------------------------------------------------------------------------

// RU|EN стоял на каждой странице по-своему: в тулбаре тирлиста и ленты, в
// .tc-extras калькулятора. Три места расходились при каждой правке, а на
// прокрученной странице полоса с переключателем оставалась висеть над
// содержимым, хотя шапка над ней уже свернулась. Теперь он в самой шапке —
// один компонент на три страницы, и уезжает вместе с логотипом.
//
// Главной в списке нет намеренно: сайт на ней только по-русски, словарь
// js/i18n.js туда не подключён, и переключать там нечего.
test('переключатель языка живёт в шапке, а не в полосе под ней', function () use ($PUB) {
    foreach (['index.php', 'news.php', 'calculator.php'] as $f) {
        $head = top_header($PUB, $f);
        assert_true((bool)preg_match('/<div class="mk-top-lang lang-switch" id="langSwitch"/', $head),
            "$f: переключатель должен стоять внутри шапки");
        // Обе половинки видны всегда: из одной кнопки не понять, какие языки
        // вообще есть (см. историю правок в design-page.css).
        assert_true((bool)preg_match('/data-lang="ru"/', $head), "$f: половинка RU");
        assert_true((bool)preg_match('/data-lang="en"/', $head), "$f: половинка EN");
        // Второй копии остаться не должно: обработчики ищут #langSwitch по id,
        // и вторая пара молча перестала бы работать.
        $page = preg_replace('/<!--.*?-->/s', '', top_read($PUB . '/' . $f));
        assert_eq(1, substr_count($page, 'id="langSwitch"'), "$f: переключатель один на страницу");
    }

    $css = top_read($PUB . '/css/topbar.css');
    assert_true((bool)preg_match('/\.mk-top\.is-stuck \.mk-top-lang \{[^}]*visibility: hidden;/s', $css),
        'при прокрутке переключатель уезжает и уходит из таб-порядка');
});

// --------------------------------------------------------------------------
//  Лента карточек на главной
// --------------------------------------------------------------------------

// Захват указателя с первого pointerdown переносил на .hm-cards и
// последующий click: его целью становился контейнер, а не карточка под
// пальцем. Карточки-ссылки переставали открываться совсем, а «Фрукты» и
// «Цены» молчали в ответ на нажатие — js/topbar.js ищет [data-soon] от
// e.target. Захватывать указатель можно только после начала протяжки.
test('карусель карточек не забирает указатель до начала протяжки', function () use ($PUB) {
    // Комментарии вырезаем: причина правки разобрана прямо над кодом, и
    // слово setPointerCapture встречается там же.
    $js = preg_replace('~//[^
]*~', '', top_read($PUB . '/js/home.js'));
    assert_eq(0, preg_match('/pointerdown[^}]*setPointerCapture/s', $js),
        'на pointerdown указатель захватывать нельзя — click уйдёт контейнеру');
    assert_true((bool)preg_match('/if \(!captured && moved > DRAG_AT\) \{\s*captured = true;/s', $js),
        'захват включается только после порога протяжки');
    assert_true(strpos($js, 'if (moved > DRAG_AT) {') !== false,
        'клик после протяжки глушится по тому же порогу');
});

run_tests();
