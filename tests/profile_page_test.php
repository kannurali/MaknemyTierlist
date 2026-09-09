<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Профиль игрока — /profile. Как и у calculator_page_test.php, здесь не
// вызываются функции (страница статична, данных для неё нет), а проверяется
// то, что не поймает ни компилятор, ни линтер: маршрут в .htaccess, ссылка на
// профиль со всех страниц, реально существующие файлы css/js, ключи словаря
// под каждым data-i18n и решения по доступности, которые легко потерять при
// следующей правке разметки.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

// Все страницы сайта: профиль обязан быть достижим с любой из них.
$PAGES = ['home.php', 'index.php', 'news.php', 'calculator.php', 'profile.php'];

function pf_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// Ключ обязан лежать в ОБОИХ языковых блоках по одному разу.
//
// Раньше это проверялось как substr_count($i18n, '"ключ"') == 2, и такая
// проверка не отличает «в ru и в en» от «дважды в ru и ни разу в en» —
// то есть пропускает ровно тот регресс, ради которого написана. Поэтому
// словарь режется по началу блока en и каждая половина считается отдельно.
function pf_i18n_has(string $i18n, string $key): array {
    $enAt = strpos($i18n, "\n    en: {");
    if ($enAt === false) { return [0, 0]; }
    $needle = '"' . $key . '"';
    return [
        substr_count(substr($i18n, 0, $enAt), $needle),
        substr_count(substr($i18n, $enAt), $needle),
    ];
}

function pf_assert_key(string $i18n, string $key): void {
    [$ru, $en] = pf_i18n_has($i18n, $key);
    assert_eq(1, $ru, "ключ $key ровно один раз в ru");
    assert_eq(1, $en, "ключ $key ровно один раз в en");
}

// --------------------------------------------------------------------------
//  Маршрут /profile
// --------------------------------------------------------------------------

test('/profile ведёт на profile.php', function () use ($PUB) {
    $ht = pf_read($PUB . '/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^profile$ /profile.php [L]') !== false,
        'внутренний рероут /profile → /profile.php');
});

test('/profile/ уводится 301-м на адрес без слэша, и раньше рероута', function () use ($PUB) {
    $ht = pf_read($PUB . '/.htaccess');
    $strip = strpos($ht, 'RewriteRule ^profile/$ /profile [L,R=301]');
    $route = strpos($ht, 'RewriteRule ^profile$ /profile.php [L]');
    assert_true($strip !== false, 'правило снятия слэша на месте');
    assert_true($route !== false, 'правило рероута на месте');
    assert_true($strip < $route, 'снятие слэша должно стоять раньше рероута');
});

// Внутренний рероут /profile → /profile.php сам попадает под паттерн
// ^profile\.php$: без условия на REDIRECT_STATUS получился бы бесконечный
// редирект. Ровно та же ловушка, что у /calculator.
test('прямой /profile.php уводится на /profile, и только снаружи', function () use ($PUB) {
    $ht = pf_read($PUB . '/.htaccess');
    assert_true((bool)preg_match(
        '/RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\R\s*RewriteRule \^profile\\\\\.php\$ \/profile \[L,R=301\]/',
        $ht), 'редирект profile.php должен быть закрыт условием REDIRECT_STATUS');
});

test('профиль объявляет себя на /profile', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true(strpos($s, '<link rel="canonical" href="https://maknemy.com/profile" />') !== false,
        'canonical профиля');
});

// Профиль пуст и одинаков для всех, а когда появятся живые аккаунты,
// индексировать чужие профили будет нельзя тем более. Поэтому noindex — и по
// той же причине его нет в карте сайта. Оба условия проверяем вместе: снять
// одно, забыв про другое, — обычная невнимательность.
test('профиль закрыт от индексации и не значится в карте сайта', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true(strpos($s, '<meta name="robots" content="noindex, follow" />') !== false,
        'noindex на странице профиля');
    $map = pf_read($PUB . '/sitemap.xml');
    assert_eq(false, strpos($map, 'maknemy.com/profile'), '/profile не должен попадать в sitemap');
});

// --------------------------------------------------------------------------
//  Кнопка профиля в шапке
// --------------------------------------------------------------------------

// Аватар в шапке — КНОПКА, а не ссылка на профиль. Его забирает под себя
// js/topbar.js: для невошедшего он превращается в ссылку входа, для
// вошедшего — в кнопку выпадающего меню. Ссылка сюда ломала бы и то и
// другое: initUserMenu делает preventDefault, и href просто перестал бы
// работать, а у невошедшего осталась бы ссылка в профиль, куда его не
// пустят.
//
// Разметка обязана быть ОДИНАКОВОЙ на всех пяти страницах: пять копий шапки
// расходятся молча, и именно на profile.php она однажды уже разошлась.
test('аватар в шапке — кнопка меню, одна и та же на всех страницах', function () use ($PUB, $PAGES) {
    $canon = '<button class="mk-avatar" type="button" aria-label="Профиль" data-soon '
           . 'data-i18n-title="topbar.soon" title="В активной разработке">';
    foreach ($PAGES as $f) {
        $s = pf_read($PUB . '/' . $f);
        assert_true(strpos($s, $canon) !== false, "$f: аватар должен быть кнопкой меню");
        assert_eq(0, preg_match('/<a class="mk-avatar"/', $s),
            "$f: аватар не должен быть ссылкой");
    }
});

// На /profile ведёт ПУНКТ меню пользователя, который строит js/topbar.js.
// Пункт появляется только у вошедшего — невошедшему показывать нечего.
test('в меню пользователя есть пункт «Мой профиль» на /profile', function () use ($PUB) {
    $js   = pf_read($PUB . '/js/topbar.js');
    $i18n = pf_read($PUB . '/js/i18n.js');

    assert_true(strpos($js, 'var PROFILE_PATH = "/profile";') !== false,
        'адрес профиля объявлен один раз');
    assert_true(strpos($js, 'mine.href = PROFILE_PATH;') !== false, 'пункт ведёт на /profile');
    assert_true(strpos($js, 'mine.setAttribute("data-i18n", "user.mine");') !== false,
        'подпись пункта переводится');
    assert_true(strpos($js, 'menu.appendChild(mine);') !== false, 'пункт попадает в меню');

    // Ключ обязан быть в трёх местах: в обоих языках словаря и в FALLBACK
    // самого topbar.js. Без словаря I18N.t вернёт сам ключ строкой, без
    // FALLBACK — undefined, когда словарь не доехал.
    pf_assert_key($i18n, 'user.mine');
    assert_true(strpos($js, '"user.mine": "Мой профиль"') !== false,
        'запасная подпись на случай неподъехавшего словаря');

    // Порядок: свой профиль выше внешней ссылки на Roblox, выход — последним
    // и отбит волоском (деструктивное действие не должно стоять вплотную к
    // навигационным).
    $mine = strpos($js, 'menu.appendChild(mine);');
    $prof = strpos($js, 'menu.appendChild(prof);');
    $out  = strpos($js, 'menu.appendChild(out);');
    assert_true($mine < $prof && $prof < $out, 'порядок пунктов: свой, Roblox, выход');
    assert_true(strpos($js, 'out.className = "mk-user-item mk-user-out";') !== false,
        'у выхода свой класс');
    assert_true(strpos(pf_read($PUB . '/css/topbar.css'), '.mk-user-out') !== false,
        'и свой стиль-разделитель');
});

// Пункт, ведущий на текущую страницу, помечается aria-current — тем же
// приёмом, что «Тирлист» на index.php. Меню строится скриптом на всех
// страницах сразу, поэтому проверка идёт по условию в коде.
test('пункт профиля помечен aria-current на самой странице профиля', function () use ($PUB) {
    $js = pf_read($PUB . '/js/topbar.js');
    assert_true(strpos($js, 'if (location.pathname === PROFILE_PATH) mine.setAttribute("aria-current", "page");') !== false,
        'aria-current ставится только на /profile');
});

// --------------------------------------------------------------------------
//  Подключённые файлы
// --------------------------------------------------------------------------

// Версия в ?v= обязательна: страница отдаётся с no-cache, а css/js — нет, и
// без нового адреса посетитель остался бы на старом коде.
test('профиль подключает свои css и js с версией, и файлы существуют', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    foreach ([
        'css/profile.css'    => '/<link rel="stylesheet" href="(css\/profile\.css)\?v=\d+" \/>/',
        'js/profile-page.js' => '/<script src="(js\/profile-page\.js)\?v=\d+" defer><\/script>/',
        'js/profile-chart.js' => '/<script src="(js\/profile-chart\.js)\?v=\d+" defer><\/script>/',
    ] as $file => $re) {
        assert_true((bool)preg_match($re, $s), "$file подключён с версией");
        assert_true(is_file($PUB . '/' . $file), "$file существует на диске");
    }
});

// Шапка и подвал приезжают из общих файлов — профиль обязан подключать те же,
// что остальные страницы, иначе он разъедется с сайтом при первой же правке
// оформления.
test('профиль использует общие шапку, подвал и фон', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    foreach (['css/base.css', 'css/topbar.css', 'css/design-page.css', 'js/topbar.js'] as $shared) {
        assert_true(strpos($s, $shared . '?v=') !== false, "$shared подключён");
    }
    assert_true(strpos($s, '<footer class="mk-foot">') !== false, 'общий подвал на месте');
    assert_true(strpos($s, '<header class="mk-top">') !== false, 'общая шапка на месте');
});

// Переключатель языка живёт в самой шапке и обязан быть на профиле такой же,
// как на остальных страницах: шапка общая, и страница без переключателя
// становится тупиком — сменить язык на ней нечем.
test('в шапке профиля есть переключатель языка той же формы, что у всех', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = pf_read($PUB . '/' . $f);
        $head = substr($s, 0, strpos($s, '</header>') ?: strlen($s));
        assert_true((bool)preg_match(
            '/<div class="mk-top-lang lang-switch" id="langSwitch" role="group"/', $head),
            "$f: переключатель языка в шапке");
        assert_eq(1, preg_match_all('/<button class="chip" type="button" data-lang="ru"/', $head),
            "$f: кнопка RU");
        assert_eq(1, preg_match_all('/<button class="chip" type="button" data-lang="en"/', $head),
            "$f: кнопка EN");
    }
});

// Переключатель без обработчика — мёртвая кнопка. Скрипт страницы обязан и
// применять язык, и слушать клики: на профиле нет ничего, что делало бы это
// за него (js/topbar.js занимается только плашкой «В активной разработке»).
test('скрипт профиля применяет язык и слушает переключатель', function () use ($PUB) {
    $js = pf_read($PUB . '/js/profile-page.js');
    assert_true(strpos($js, 'langSwitch') !== false, 'скрипт находит переключатель');
    assert_true(strpos($js, "addEventListener('click'") !== false, 'вешает обработчик');
    assert_true(strpos($js, 'nexus-lang-v1') !== false,
        'ключ хранения тот же, что на остальных страницах — иначе выбор не переносится');
    assert_true(strpos($js, 'aria-pressed') !== false, 'состояние кнопок объявляется');
});

// Подписи разделов в шапке переводятся ключами nav.*. Профиль, собранный без
// них, остался бы русским посреди английского интерфейса.
test('пилюли разделов в шапке профиля несут ключи перевода', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    $nav = substr($s, strpos($s, '<ul class="mk-nav">'));
    $nav = substr($nav, 0, strpos($nav, '</ul>'));
    foreach (['nav.home', 'nav.tierlist', 'nav.trading', 'nav.calculator', 'nav.news'] as $k) {
        assert_true(strpos($nav, 'data-i18n="' . $k . '"') !== false, "ключ $k на пилюле");
    }
});

// Версия ?v= у словаря обязана совпадать на ВСЕХ страницах. Разные номера
// означают, что часть посетителей держит в кеше копию без ключей profile.*, и
// на профиле они увидят голые имена ключей вместо текста. Тот же инвариант,
// что проверяет calculator_page_test.php для трёх страниц, — здесь для всех.
test('словарь подключён одной версией на всех пяти страницах', function () use ($PUB, $PAGES) {
    $seen = [];
    foreach ($PAGES as $f) {
        preg_match('/i18n\.js\?v=(\d+)/', pf_read($PUB . '/' . $f), $m);
        assert_true(!empty($m[1]), "$f: подключает i18n.js с версией");
        $seen[$m[1]] = true;
    }
    assert_eq(1, count($seen), 'версия словаря должна быть одна: ' . implode(', ', array_keys($seen)));
});

// --------------------------------------------------------------------------
//  Переводы
// --------------------------------------------------------------------------

// Каждый ключ из разметки обязан быть в обоих языках. Пропущенный ключ не
// падает в рантайме (t() откатывается на русскую строку, а потом на сам
// ключ), поэтому в браузере он выглядит как «почти работает» — ловить его
// нужно здесь.
test('под каждым data-i18n профиля есть строка в ru и en', function () use ($PUB) {
    $s    = pf_read($PUB . '/profile.php');
    $i18n = pf_read($PUB . '/js/i18n.js');

    preg_match_all('/data-i18n(?:-title|-label|-placeholder)?="([^"]+)"/', $s, $m);
    $keys = array_values(array_unique($m[1]));
    assert_true(count($keys) > 0, 'ключи в разметке нашлись');

    // Словарь — два блока подряд, ru и затем en. Ключ обязан встретиться в
    // каждом: одного вхождения на файл мало, оно означало бы перевод только
    // на одном языке.
    $enAt = strpos($i18n, "\n    en: {");
    assert_true($enAt !== false, 'блок en найден в словаре');
    $ru = substr($i18n, 0, $enAt);
    $en = substr($i18n, $enAt);

    foreach ($keys as $k) {
        assert_true(strpos($ru, '"' . $k . '"') !== false, "ключ $k в ru");
        assert_true(strpos($en, '"' . $k . '"') !== false, "ключ $k в en");
    }
});

// Подпись бургера меняется вместе с состоянием (js/profile-page.js). Обе
// строки обязаны быть в словаре: без второй кнопка объявляла бы «свернуть»
// тогда, когда нажатие развернёт.
test('у бургера меню обе подписи состояния переведены', function () use ($PUB) {
    $js   = pf_read($PUB . '/js/profile-page.js');
    $i18n = pf_read($PUB . '/js/i18n.js');
    foreach (['profile.menuCollapse', 'profile.menuExpand'] as $k) {
        assert_true(strpos($js, $k) !== false, "$k используется в скрипте");
        pf_assert_key($i18n, $k);
    }
});

// --------------------------------------------------------------------------
//  Доступность и решения по разметке
// --------------------------------------------------------------------------

// Бургер должен быть связан со списком: без aria-controls/aria-expanded
// скринридер объявит просто «кнопка» и не скажет, что она сворачивает.
test('бургер меню связан со списком и объявляет состояние', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true((bool)preg_match(
        '/<button class="pf-menu-toggle"[^>]*id="pfMenuToggle"[^>]*aria-expanded="true"[^>]*aria-controls="pfMenuList"/s',
        $s), 'бургер объявляет состояние и связан со списком');
    assert_true(strpos($s, 'id="pfMenuList"') !== false, 'список несёт тот самый id');
});

// «Удалить аккаунт» в макете набран тем же серым, что «Чаты», и стоит в общем
// списке. Так оставлять нельзя: промах по строке стоит аккаунта. Отдельный
// класс — то, чем это расхождение с макетом держится, и терять его при
// правках разметки не следует.
test('удаление аккаунта отделено от остальных пунктов меню', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true((bool)preg_match(
        '/<li class="pf-menu-danger"><button class="pf-menu-item"[^>]*data-i18n="profile\.menuDelete"/', $s),
        'пункт удаления помечен pf-menu-danger');
    $css = pf_read($PUB . '/css/profile.css');
    assert_true(strpos($css, '.pf-menu-danger .pf-menu-item') !== false,
        'у опасного пункта свой стиль');
});

// Выход и смена аккаунта — НАСТОЯЩИЕ: вход через Roblox на сайте есть, и
// пункт, отвечающий «в активной разработке» рядом с работающим выходом в
// шапке, был бы прямым враньём. Остальные три раздела не существуют и
// обязаны отвечать той же плашкой, что «Трейдинг» в шапке: клики по
// [data-soon] ловит js/topbar.js делегированием на документе.
test('в меню профиля работает то, что работает, и помечено то, что нет', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-page.js');
    preg_match('/<ul class="pf-menu-list".*?<\/ul>/s', $s, $m);
    assert_true(!empty($m), 'список меню найден');

    $n = preg_match_all('/<button class="pf-menu-item"/', $m[0]);
    assert_eq(5, $n, 'пять пунктов из макета');
    assert_eq(3, substr_count($m[0], 'data-soon'), 'три ненастоящих: чаты, помощь, удаление');
    assert_eq(3, substr_count($m[0], 'data-i18n-title="topbar.soon"'), 'у каждого из них ключ объяснения');
    assert_eq(0, substr_count($m[0], 'href="#"'), 'пустых якорей быть не должно');

    // Настоящие пункты обязаны быть БЕЗ data-soon: иначе делегат из
    // topbar.js перехватил бы клик, показал плашку — и выход не случился бы.
    foreach (['pfLogout' => 'profile.menuLogout', 'pfSwitch' => 'profile.menuSwitch'] as $id => $key) {
        assert_true((bool)preg_match(
            '/<button class="pf-menu-item" type="button" id="' . $id . '" data-i18n="'
            . preg_quote($key, '/') . '">/', $m[0]),
            "пункт $id — рабочая кнопка без data-soon");
    }

    assert_true(strpos($js, "auth.logout(function () { location.assign('/'); });") !== false,
        'выход зовёт общий модуль и уводит с профиля');
    assert_true(strpos($js, "auth.switchAccount('/profile');") !== false,
        'смена аккаунта возвращает на профиль');
});

// Выход из аккаунта есть и в шапке (меню пользователя), и на карточке. Две
// независимые копии одного вызова разъехались бы при первой же правке —
// например когда выход начнёт требовать токен. Поэтому он ровно один, в
// js/auth.js, и обе стороны зовут его.
test('выход из аккаунта живёт в одном месте', function () use ($PUB) {
    $auth = pf_read($PUB . '/js/auth.js');
    assert_true(strpos($auth, 'var LOGOUT = "/api/logout.php";') !== false, 'адрес выхода объявлен здесь');
    assert_true(strpos($auth, 'root.MKAuth = {') !== false, 'модуль опубликован как MKAuth');

    foreach (['js/topbar.js', 'js/profile-page.js'] as $f) {
        $js = pf_read($PUB . '/' . $f);
        assert_eq(0, substr_count($js, '/api/logout.php'), "$f: своего адреса выхода быть не должно");
        assert_eq(0, substr_count($js, '/api/roblox_start.php'), "$f: и своего адреса входа тоже");
    }

    // Порядок подключения: auth.js обязан идти ДО тех, кто им пользуется.
    foreach (['home.php', 'index.php', 'news.php', 'calculator.php', 'profile.php'] as $f) {
        $page = pf_read($PUB . '/' . $f);
        $a = strpos($page, 'js/auth.js?v=');
        $t = strpos($page, 'js/topbar.js?v=');
        assert_true($a !== false && $t !== false && $a < $t, "$f: auth.js подключён перед topbar.js");
    }
});

// График рисуется по данным из /api/profile-stats.php. В разметке от него
// только каркас — числа и оси собирает js/profile-chart.js, потому что их
// количество зависит от длины месяца.
//
// Прежняя версия была перенесена из макета с НАРИСОВАННОЙ ломаной: линия шла
// от 15 до 50, а счётчики под ней стояли на нуле. Эти проверки держат
// границу, чтобы декоративная линия не вернулась.
test('график берёт данные из API, а не из разметки', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-chart.js');

    assert_true(strpos($s, 'id="pfChart"') !== false, 'каркас графика на месте');
    assert_true(strpos($s, 'id="pfPlot"') !== false, 'поле графика на месте');
    assert_true(strpos($js, '/api/profile-stats.php') !== false, 'скрипт ходит в API');
    assert_true(is_file($PUB . '/api/profile-stats.php'), 'эндпоинт существует');

    // Ни одной готовой координаты в разметке: если в profile.php снова
    // появится <polyline points="...">, значит линию опять нарисовали руками.
    $markup = preg_replace('/<!--.*?-->/s', '', $s);
    assert_eq(0, substr_count($markup, '<polyline'), 'ломаной в разметке быть не должно');
    assert_eq(0, preg_match('/points="[\d\s.,]+"/', $markup), 'координат в разметке быть не должно');
});

// Легенда обещает два ряда — «успешно» и «отказ». В прежней версии линия была
// одна, и понять, какой из двух она соответствует, было нельзя.
test('у графика две линии под две записи в легенде', function () use ($PUB) {
    $js  = pf_read($PUB . '/js/profile-chart.js');
    $css = pf_read($PUB . '/css/profile.css');
    assert_true(strpos($js, "path('ok')") !== false, 'ряд успешных рисуется');
    assert_true(strpos($js, "path('declined')") !== false, 'ряд отказов рисуется');
    // Различие не только цветом: на цвет полагаться нельзя (дальтонизм,
    // контрастные режимы, печать).
    assert_true(strpos($css, 'stroke-dasharray') !== false, 'ряды различаются ещё и штрихом');
});

// Ось X раньше была набором безымянных засечек — понять, что по горизонтали,
// было невозможно.
test('ось X подписана днями', function () use ($PUB) {
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true(strpos($js, 'pf-axis-text') !== false, 'подписи осей рисуются');
    assert_true(strpos($js, 'String(d.day)') !== false, 'по горизонтали — номера дней');
});

// Значения должны читаться не только мышью: поле фокусируемое, стрелки
// перебирают дни.
test('значения графика доступны с клавиатуры', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true((bool)preg_match('/id="pfPlot"[^>]*tabindex="0"/s', $s)
             || (bool)preg_match('/tabindex="0"[^>]*id="pfPlot"/s', $s),
        'поле графика фокусируемое');
    assert_true(strpos($js, "'ArrowRight'") !== false, 'стрелки перебирают дни');
    assert_true(strpos($js, 'pfReadout') !== false, 'значение выводится текстом');
});

// SVG на слух не читается. Те же числа обычной таблицей — единственный
// честный способ отдать их без картинки.
test('данные графика продублированы таблицей для скринридера', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true((bool)preg_match('/<table class="pf-sr-only" id="pfChartTable">/', $s),
        'таблица есть и скрыта визуально');
    assert_true(strpos($js, "cell('th', String(d.day), 'row')") !== false,
        'строки таблицы заполняются');

    // Собирается узлами, а не строкой. Сейчас в таблицу идут только числа, но
    // на странице есть и пользовательский текст («о себе», ник), и innerHTML
    // рядом с ним — заряженное ружьё для следующей правки.
    assert_eq(0, substr_count($js, 'innerHTML'), 'innerHTML в графике быть не должно');
    foreach ([pf_read($PUB . '/js/profile-page.js'), pf_read($PUB . '/js/auth.js')] as $other) {
        assert_eq(0, substr_count($other, 'innerHTML'), 'и в остальных скриптах профиля тоже');
    }
});

// Пустое состояние обязано отличать «сделок ещё не было» от «в этом месяце не
// было»: это разные ответы, и общий на оба вводил бы в заблуждение.
test('у пустого графика два разных объяснения', function () use ($PUB) {
    $i18n = pf_read($PUB . '/js/i18n.js');
    foreach (['profile.chartNoData', 'profile.chartNoMonth', 'profile.chartError'] as $k) {
        pf_assert_key($i18n, $k);
    }
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true(strpos($js, 'profile.chartNoData') !== false, 'нет сделок вообще');
    assert_true(strpos($js, 'profile.chartNoMonth') !== false, 'нет сделок в месяце');
    assert_true(strpos($js, 'profile.chartError') !== false, 'ответ не доехал');
});

// Выбор месяца в макете был пилюлей, похожей на выпадающий список и не
// нажимавшейся. Теперь это настоящий select.
test('месяц выбирается настоящим select, а не декоративной пилюлей', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true(strpos($s, '<select id="pfMonth"') !== false, 'нативный select');
    assert_true(strpos($js, "addEventListener('change'") !== false, 'смена месяца перезагружает данные');
});

// Счётчики заполняет тот же ответ API — иначе они снова разошлись бы с
// графиком, как в макете (нули под линией, идущей от 15 до 50).
test('счётчики заполняются из того же ответа, что и график', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-chart.js');
    foreach (['pfStatDeals', 'pfStatCreated', 'pfStatCancelled'] as $id) {
        assert_true(strpos($s, 'id="' . $id . '"') !== false, "счётчик $id размечен");
        assert_true(strpos($js, $id) !== false, "счётчик $id заполняется скриптом");
    }
    assert_true(strpos($js, 'lifetime') !== false, 'счётчики берут числа за всё время');
});

// Тестовые данные живут в базе разработчика, а не в коде. tools/ не
// публикуется (.cpanel.yml копирует только public_html), поэтому выдуманные
// цифры физически не могут уехать на бой.
test('сеялка тестовых данных лежит вне публикуемой папки', function () use ($ROOT) {
    assert_true(is_file($ROOT . '/tools/seed-profile-stats.php'), 'сеялка на месте');
    assert_eq(false, is_file($ROOT . '/public_html/tools/seed-profile-stats.php'),
        'в public_html её быть не должно');
    $api = pf_read($ROOT . '/public_html/api/profile-stats.php');
    assert_eq(0, preg_match('/\bmt_rand\b|\brand\(/', $api),
        'в боевом коде не должно быть генерации чисел');
});

// Счётчики — это пары «подпись → значение», и размечены они списком
// определений. Тремя <div> с текстом связь между подписью и числом
// существовала бы только визуально.
test('счётчики размечены списком определений', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true(strpos($s, '<dl class="pf-stats">') !== false, 'счётчики в <dl>');
    assert_eq(3, preg_match_all('/<div class="pf-stat">\s*<dt/', $s), 'три пары dt/dd');
});

// Профиль всегда свой, значит «о себе» пишет сам человек. Раньше здесь стоял
// <p> с подсказкой: аккаунтов не было, и поле ввода обещало бы то, чего
// страница не умела. Теперь умеет — и поле обязано быть настоящим, с
// подписью, счётчиком и сохранением.
test('«о себе» — настоящее поле со счётчиком и сохранением', function () use ($PUB) {
    $s    = pf_read($PUB . '/profile.php');
    $js   = pf_read($PUB . '/js/profile-page.js');
    $i18n = pf_read($PUB . '/js/i18n.js');

    assert_true((bool)preg_match('/<textarea class="pf-about-input" id="pfAboutInput"/', $s),
        'поле ввода на месте');
    assert_true((bool)preg_match('/<label class="pf-sr-only" for="pfAboutInput"/', $s),
        'у поля есть подпись — placeholder подписью не считается');
    assert_true(strpos($s, 'data-i18n-placeholder="profile.aboutEmpty"') !== false,
        'подсказка переводится вместе со страницей');
    assert_true((bool)preg_match('/<button class="pf-about-save" type="button" id="pfAboutSave"[^>]*disabled>/', $s),
        'кнопка сохранения выключена, пока нечего сохранять');
    assert_true(strpos($s, 'id="pfAboutStatus" role="status" aria-live="polite"') !== false,
        'об исходе сохранения сообщают вслух');

    // Предел один и тот же на трёх сторонах: в базе (VARCHAR), на сервере
    // (PROFILE_ABOUT_MAX) и в счётчике. Разъедутся — человек напишет текст,
    // который молча обрежут.
    assert_true(strpos($js, 'var ABOUT_MAX = 280;') !== false, 'предел в скрипте');
    $lib = pf_read($PUB . '/api/lib/profile.php');
    assert_true(strpos($lib, 'const PROFILE_ABOUT_MAX = 280;') !== false, 'тот же предел на сервере');
    $mig = pf_read(dirname($PUB) . '/docs/migrations/2026-09-09-profile.sql');
    assert_true(strpos($mig, 'VARCHAR(280)') !== false, 'и та же ширина колонки');
    assert_true(strpos(pf_read(dirname($PUB) . '/schema.sql'), 'about         VARCHAR(280)') !== false,
        'чистая установка получает колонку сразу, без миграции');

    // Считаем СИМВОЛЫ, а не единицы UTF-16: сервер режет mb_substr, и по
    // эмодзи счётчик разошёлся бы с обрезкой.
    assert_true(strpos($js, 'Array.from(text).length') !== false, 'счётчик считает символы');

    foreach (['profile.aboutSave', 'profile.aboutSaved', 'profile.aboutError',
              'profile.aboutTooOften', 'profile.aboutUnavailable'] as $k) {
        pf_assert_key($i18n, $k);
    }
});

// Гейт: невошедшему показывать пустую карточку нельзя — она читается как «у
// вас ничего нет», хотя правда «вы не вошли». Решает это СЕРВЕР, а не
// скрипт: иначе страница сначала мигнула бы карточкой.
test('невошедшему страница отдаёт предложение войти, а не пустую карточку', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-page.js');

    assert_true(strpos($s, "start_site_session();") !== false, 'страница открывает сессию');
    assert_true(strpos($s, "\$pfAuthed = profile_me(\$_SESSION) !== '';") !== false,
        'и спрашивает у неё, кто пришёл');
    assert_true((bool)preg_match(
        '/<p class="pf-gate" id="pfGate" data-i18n="profile\.login"<\?php if \(\$pfAuthed\): \?> hidden<\?php endif; \?>>/', $s),
        'гейт скрыт только для вошедшего');
    assert_true((bool)preg_match(
        '/<section class="pf-card" id="pfCard" aria-labelledby="pfNick"<\?php if \(!\$pfAuthed\): \?> hidden<\?php endif; \?>>/', $s),
        'карточка скрыта только для невошедшего');

    // Сессия может отвалиться между отдачей страницы и запросом данных —
    // тогда переключить обязан скрипт. Проверяем НАПРАВЛЕНИЕ, а не наличие
    // функции: перепутанные местами gate и card дали бы вошедшему гейт, а
    // анониму — пустую карточку, и «функция renderAuth есть» это пропустит.
    assert_true(strpos($js, 'if (gate) { gate.hidden = !!authed; }') !== false,
        'гейт прячется, когда вошли');
    assert_true(strpos($js, 'if (card) { card.hidden = !authed; }') !== false,
        'карточка прячется, когда не вошли');
    assert_true(strpos($js, 'renderAuth(!!d.authed);') !== false, 'по ответу API');

    // Поле «о себе» приезжает выключенным и включается только с данными.
    // Иначе при не доехавшем ответе человек напишет текст в пустое поле и
    // сохранением сотрёт то, что лежит в базе.
    assert_true((bool)preg_match('/<textarea class="pf-about-input" id="pfAboutInput" rows="2" disabled/', $s),
        'поле выключено в разметке');
    assert_true(strpos($js, 'aboutInput.disabled = false;') !== false,
        'включается только в loadAbout, по пришедшим данным');
    assert_eq(1, substr_count($js, 'aboutInput.disabled = false;'), 'ровно в одном месте');
    assert_true(strpos($js, 'if (d.authed) { loadAbout(lastCard); }') !== false,
        'и только вошедшему: невошедшему включать нечего и незачем');

    // Черновик важнее повторного ответа: mk:profiledata приходит на КАЖДУЮ
    // смену месяца в графике, и безусловная заливка стирала бы набранное.
    assert_true(strpos($js, "var dirty = aboutReady && aboutInput.value !== savedAbout;") !== false,
        'повторные данные не затирают правку');
    pf_assert_key(pf_read($PUB . '/js/i18n.js'), 'profile.login');
});

// Единица макета: вся геометрия страницы построена на --u, той же, что у
// шапки (topbar.css). min() держит 1px как потолок — без него карточка
// раздувалась бы на широком мониторе вслед за окном.
test('страница масштабируется той же единицей, что и шапка', function () use ($PUB) {
    $css = pf_read($PUB . '/css/profile.css');
    assert_true(strpos($css, '--u: min(1px, calc(100vw / 1443));') !== false,
        'единица макета объявлена так же, как в topbar.css');
    $top = pf_read($PUB . '/css/topbar.css');
    assert_true(strpos($top, 'min(1px, calc(100vw / 1443))') !== false,
        'и в шапке она такая же — значения не должны разъезжаться');
});

// Мобильного макета для профиля нет, но отдавать телефону сжатую до
// нечитаемости десктопную раскладку нельзя. Линейный поток ниже 900px — то,
// чем это закрыто.
test('на узких экранах раскладка переходит в линейный поток', function () use ($PUB) {
    $css = pf_read($PUB . '/css/profile.css');
    assert_true(strpos($css, '@media (max-width: 900px)') !== false, 'есть узкая раскладка');
    $at = strpos($css, '@media (max-width: 900px)');
    $mob = substr($css, $at);
    assert_true(strpos($mob, '.pf-react { position: static; }') !== false
             || strpos($mob, 'position: static;') !== false,
        'наложения сняты — блоки встают в поток');
});

// --------------------------------------------------------------------------
//  Карточка профиля живёт данными
// --------------------------------------------------------------------------

// Ник, аватар, «о себе», статус и репутация раньше были зашиты в разметку.
// Теперь это подписи-заглушки, которые заменяются данными из API.
test('поля карточки размечены точками привязки и заполняются скриптом', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-page.js');
    foreach (['pfNick', 'pfHandle', 'pfAboutInput', 'pfAvatar', 'pfStatus',
              'pfLikes', 'pfDislikes'] as $id) {
        assert_true(strpos($s, 'id="' . $id . '"') !== false, "$id размечен");
        assert_true(strpos($js, $id) !== false, "$id заполняется скриптом");
    }
    assert_true(strpos($js, 'mk:profiledata') !== false, 'карточка слушает данные');
});

// Живое значение обязано пережить смену языка. Пока на узле висит data-i18n,
// общий проход applyLang() затрёт настоящий ник словом «Игровой ник».
test('после подстановки данных ключ перевода с узла снимается', function () use ($PUB) {
    $js = pf_read($PUB . '/js/profile-page.js');
    assert_true(strpos($js, "removeAttribute('data-i18n')") !== false,
        'ключ снимается вместе с подстановкой значения');
    // У статуса ключ не снимается, а ПЕРЕставляется на конкретное состояние:
    // снятый оставлял подпись на языке, который был в момент прихода данных,
    // и переключение на английский её больше не трогало.
    assert_true(strpos($js, "setAttribute('data-i18n-label'") !== false,
        'у статуса ключ подписи переставляется на текущее состояние');
    assert_eq(0, substr_count($js, "removeAttribute('data-i18n-label')"),
        'снимать ключ подписи нельзя — подпись перестала бы переводиться');
});

// Аватар до прихода данных скрыт: <img> с пустым src нарисовал бы значок
// битого файла поверх серого круга из макета.
test('аватар скрыт, пока картинки нет', function () use ($PUB) {
    $s  = pf_read($PUB . '/profile.php');
    $js = pf_read($PUB . '/js/profile-page.js');
    assert_true((bool)preg_match('/<img id="pfAvatar"[^>]*hidden/', $s), 'в разметке скрыт');
    assert_true(strpos($js, 'img.hidden = false') !== false, 'показывается только с картинкой');
});

// Три точки статуса в макете горели все сразу — индикатор, который ничего не
// индицирует. Активна должна быть ровно одна, и состояние обязано называться
// словами: цвет ничего не сообщает тому, кто его не видит.
//
// Точек ДВЕ, а не три: красная «занят» убрана вместе с этой правкой. Статус
// вычисляется из last_login_at, и третьего значения оттуда взять неоткуда —
// точка, которая не может загореться, это декорация.
test('статус двухпозиционный, выводится из данных и назван словами', function () use ($PUB) {
    $s    = pf_read($PUB . '/profile.php');
    $css  = pf_read($PUB . '/css/profile.css');
    $js   = pf_read($PUB . '/js/profile-page.js');
    $i18n = pf_read($PUB . '/js/i18n.js');

    foreach (['online', 'offline'] as $st) {
        assert_true(strpos($s, 'data-state="' . $st . '"') !== false, "точка $st размечена");
        assert_true(strpos($css, '.pf-status[data-state="' . $st . '"]') !== false,
            "у состояния $st свой стиль");
    }
    assert_eq(0, substr_count($s, 'data-state="busy"'), 'точки без источника данных в разметке нет');
    assert_eq(0, substr_count($css, 'busy'), 'и стиля под неё тоже');
    assert_eq(0, substr_count($js, 'busy'), 'и скрипт про неё не знает');

    assert_true(strpos($css, 'opacity: .26') !== false, 'неактивные приглушены');
    foreach (['profile.statusOnline', 'profile.statusOffline', 'profile.statusUnknown'] as $k) {
        pf_assert_key($i18n, $k);
    }

    // Хранимой колонки status нет намеренно: её пришлось бы кому-то
    // сбрасывать. Окно молчания — то же, что у чата.
    $lib = pf_read($PUB . '/api/lib/profile.php');
    assert_true(strpos($lib, 'const PROFILE_ONLINE_WINDOW = 300;') !== false, 'окно объявлено');
    assert_true(strpos($lib, "(\$now - \$seen) <= PROFILE_ONLINE_WINDOW) ? 'online' : 'offline'") !== false,
        'статус считается из last_login_at');
});

// Значок без числа не отвечает на вопрос, счётчик это или кнопка. В макете
// чисел не было вовсе.
test('у сердец есть числа и подписи', function () use ($PUB) {
    $s    = pf_read($PUB . '/profile.php');
    $i18n = pf_read($PUB . '/js/i18n.js');
    assert_true((bool)preg_match('/<b id="pfLikes">/', $s), 'счётчик лайков');
    assert_true((bool)preg_match('/<b id="pfDislikes">/', $s), 'счётчик дизлайков');
    assert_eq(0, substr_count($s, '<div class="pf-react" aria-hidden="true">'),
        'репутация больше не декорация');
    foreach (['profile.likes', 'profile.dislikes'] as $k) {
        pf_assert_key($i18n, $k);
    }
});

// Меню наложено на карточку абсолютом, а график лежит в потоке под ним —
// значит выросшее меню молча наедет на шапку графика. Так уже случилось: при
// починке переноса строк интерлиньяж подняли с 15 до 24, меню выросло со 178
// до 223 и перекрыло заголовок «Сделки по дням» на 36 точек.
//
// Тест считает высоту меню по числам из CSS и не даёт ей выйти за макетные
// 178. Считаем, а не смотрим: браузера здесь нет, а числа сложить можно.
test('меню умещается в макетные 178 и не наезжает на график', function () use ($PUB) {
    $css = pf_read($PUB . '/css/profile.css');

    // Достаём calc(N * var(--u)) из нужных правил.
    $num = function (string $rule, string $prop) use ($css) {
        $at = strpos($css, $rule . ' {');
        assert_true($at !== false, "правило $rule на месте");
        $body = substr($css, $at, strpos($css, '}', $at) - $at);
        assert_true((bool)preg_match('/' . preg_quote($prop, '/') . ':[^;]*?calc\((\d+(?:\.\d+)?) \* var\(--u\)\)/', $body, $m),
            "$rule: $prop задан в единицах макета");
        return (float)$m[1];
    };

    $lineHeight = $num('.pf-menu-item', 'line-height');
    $gap        = $num('.pf-menu-list li + li', 'margin-top');
    $dangerPad  = $num('.pf-menu-list li.pf-menu-danger', 'padding-top');

    // Поля панели и блок бургера — из соответствующих правил.
    assert_true((bool)preg_match('/\.pf-menu \{.*?padding: calc\((\d+) \* var\(--u\)\) 0 calc\((\d+) \* var\(--u\)\)/s', $css, $p),
        'поля панели заданы в единицах макета');
    $padTop = (float)$p[1]; $padBottom = (float)$p[2];

    assert_true((bool)preg_match('/\.pf-menu-toggle \{.*?margin: 0 0 calc\((\d+) \* var\(--u\)\).*?padding: calc\((\d+) \* var\(--u\)\) 0/s', $css, $t),
        'отступы бургера заданы в единицах макета');
    $burgerGap = (float)$t[1]; $burgerPad = (float)$t[2];

    // Три полоски 4 с шагом 2 = 16.
    $burger = $burgerPad * 2 + 16;

    // 5 пунктов, 4 обычных промежутка, у опасного сверх того поле и волосок.
    $items = 5 * $lineHeight + 4 * $gap + $dangerPad + 1;
    $total = $padTop + $burger + $burgerGap + $items + $padBottom;

    assert_true($total <= 178,
        sprintf('меню %.0f точек при потолке 178 — оно наедет на шапку графика на %.0f',
            $total, $total - 178));

    // И зазор до графика: шапка начинается на 215-й точке карточки, меню
    // стоит на 28-й. Меньше пяти точек воздуха — уже слипание.
    $bottom = 28 + $total;
    assert_true($bottom <= 210, sprintf('низ меню на %.0f, шапка графика на 215', $bottom));
});

// --------------------------------------------------------------------------
//  Комментарии в отдаваемых файлах
// --------------------------------------------------------------------------

// Сайт отдаёт исходники как есть, без сборки: комментарии в файлах из
// public_html приезжают посетителю и читаются через «Просмотр кода». Их убрали
// из всего проекта (коммит dcb9b8e, 3241 штука в 34 файлах) — новые файлы
// обязаны следовать тому же правилу, иначе политика продержится до первой
// страницы.
//
// PHP-комментарии проверять незачем: они не покидают сервер, и в
// api/lib/profile.php объяснения оставлены намеренно. Разбор решений по
// вёрстке живёт в docs/profile-page.md.
test('в отдаваемых файлах профиля нет пояснительных комментариев', function () use ($PUB, $ROOT) {
    $css = pf_read($PUB . '/css/profile.css');
    assert_eq(0, substr_count($css, '/' . '*'), 'css/profile.css без комментариев');

    foreach (['js/profile-page.js', 'js/profile-chart.js', 'js/auth.js'] as $f) {
        $js = pf_read($PUB . '/' . $f);
        assert_eq(0, substr_count($js, '/' . '*'), "$f: без блочных комментариев");
        // Строчные ищем по началу строки: внутри кода встречается '//' в
        // адресах (http://www.w3.org/...), и запрещать их незачем.
        assert_eq(0, preg_match('~^\s*//~m', $js), "$f: без строчных комментариев");
    }

    // Разметка страницы. PHP-часть до закрывающего тега не в счёт — она
    // остаётся на сервере, и комментарии в ней оставлены намеренно.
    $page   = pf_read($PUB . '/profile.php');
    $markup = substr($page, strpos($page, '?' . '>'));
    assert_eq(0, substr_count($markup, '<!' . '--'), 'profile.php: разметка без комментариев');

    // Знание из вырезанных комментариев не должно пропасть: контраст, поводы
    // для расхождений с макетом и уже случившиеся регрессии описаны в docs.
    $doc = pf_read($ROOT . '/docs/profile-page.md');
    foreach (['244:7400', '--u', '7.39:1', 'line-height', 'order'] as $mark) {
        assert_true(strpos($doc, $mark) !== false, "docs/profile-page.md помнит про «{$mark}»");
    }
});

run_tests();
