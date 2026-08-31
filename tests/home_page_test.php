<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Главная — статичная страница: функций, которые можно позвать из теста, у
// неё нет. Ломается в ней другое — то, что не видно ни компилятору, ни
// линтеру: съехавший маршрут, ссылка на несуществующий ассет, забытый
// canonical после переезда тирлиста с "/" на /tierlist. Ровно это здесь и
// проверяется.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

function read_file_or_fail(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// --------------------------------------------------------------------------
//  Маршруты: "/" — главная, /tierlist — тирлист.
// --------------------------------------------------------------------------

test('DirectoryIndex отдаёт главную, а не тирлист', function () use ($PUB) {
    $ht = read_file_or_fail($PUB . '/.htaccess');
    assert_true((bool)preg_match('/^DirectoryIndex\s+home\.php\s*$/m', $ht),
        'DirectoryIndex должен указывать на home.php');
    assert_eq(0, preg_match('/^DirectoryIndex\s+index\.php\s*$/m', $ht),
        'старая директива на index.php должна была уйти');
});

test('/tierlist ведёт на index.php', function () use ($PUB) {
    $ht = read_file_or_fail($PUB . '/.htaccess');
    // Сравнение подстрокой, а не регуляркой с якорем на конец строки: файл
    // хранится с CRLF, и `$` в многострочном режиме об возврат каретки спотыкается.
    assert_true(strpos($ht, 'RewriteRule ^tierlist$ /index.php [L]') !== false,
        'внутренний рероут /tierlist → /index.php');
});

// Слэш на конце обязан сниматься ВНЕШНИМ редиректом и обязательно ДО
// внутреннего рероута: на /tierlist/ база документа стала бы "/tierlist/", и
// все документ-относительные пути index.php ("css/base.css", "js/app.js")
// вернули бы 404. Тот же принцип уже действует для /news и /admin.
test('/tierlist/ уводится 301-м на адрес без слэша, и раньше рероута', function () use ($PUB) {
    $ht = read_file_or_fail($PUB . '/.htaccess');
    $strip = strpos($ht, 'RewriteRule ^tierlist/$ /tierlist [L,R=301]');
    $route = strpos($ht, 'RewriteRule ^tierlist$ /index.php [L]');
    assert_true($strip !== false, 'правило снятия слэша на месте');
    assert_true($route !== false, 'правило рероута на месте');
    assert_true($strip !== false && $route !== false && $strip < $route,
        'снятие слэша должно стоять раньше рероута');
});

// /index.php после переезда — дубль /tierlist, поэтому уводится 301-м. Но
// внутренний рероут /tierlist → /index.php сам попадает под этот паттерн:
// без условия на REDIRECT_STATUS получился бы бесконечный редирект.
test('прямой /index.php уводится на /tierlist, и только снаружи', function () use ($PUB) {
    $ht = read_file_or_fail($PUB . '/.htaccess');
    assert_true((bool)preg_match(
        '/RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\R\s*RewriteRule \^index\\\\\.php\$ \/tierlist \[L,R=301\]/',
        $ht), 'редирект index.php должен быть закрыт условием REDIRECT_STATUS');
});

// --------------------------------------------------------------------------
//  Канонические адреса: тирлист больше не живёт на "/".
// --------------------------------------------------------------------------

test('тирлист объявляет себя на /tierlist', function () use ($PUB) {
    $idx = read_file_or_fail($PUB . '/index.php');
    assert_true(strpos($idx, '<link rel="canonical" href="https://maknemy.com/tierlist" />') !== false,
        'canonical тирлиста');
    assert_true(strpos($idx, '<meta property="og:url" content="https://maknemy.com/tierlist" />') !== false,
        'og:url тирлиста');

    // Превью /tierlist — фирменная карточка при любых данных, а не картинка,
    // собранная по строке тирлиста. Решение владельца проекта: ссылка должна
    // выглядеть одинаково узнаваемо, а не меняться от каждой правки цен.
    // Проверка идёт по исходнику, поэтому ищется вызов, а не готовый адрес.
    // Ищутся ровно две формы вызова, а не подстрока 'og_tierlist_image(':
    // имя функции упомянуто в комментарии рядом (она осталась и обслуживает
    // home.php), и проверка по подстроке ловила бы этот комментарий.
    assert_true(strpos($idx, 'og_brand_card()') !== false,
        'тирлист показывает фирменную карточку');
    assert_true(strpos($idx, 'og_tierlist_image($summary)') === false
        && strpos($idx, 'og_tierlist_image(null)') === false,
        'и не собирает картинку из живых данных');
    assert_true(is_file($PUB . '/assets/og-card.jpg'), 'файл карточки лежит в assets/');
});

test('главная объявляет себя на корне', function () use ($PUB) {
    $home = read_file_or_fail($PUB . '/home.php');
    assert_true(strpos($home, '<link rel="canonical" href="https://maknemy.com/" />') !== false,
        'canonical главной');

    // Превью корня — свой первый экран, НЕ карточка тирлиста и не картинка
    // по живым данным: разделы должны различаться в чате с первого взгляда.
    // Ищется литерал из массива $ogImage, а не готовый content="…": проверка
    // идёт по исходнику, в самом теге стоит echo.
    assert_true(strpos($home, "'https://maknemy.com/assets/og-home.jpg?v=1'") !== false,
        'главная показывает свой первый экран');
    // Ищется ПРИСВОЕНИЕ, а не подстрока 'og_brand_card()': имя функции
    // упомянуто в комментарии рядом (она осталась и обслуживает /tierlist),
    // и проверка по подстроке ловила бы этот комментарий.
    assert_true(strpos($home, '$ogImage = og_brand_card()') === false,
        'и не подменяется карточкой тирлиста');
    assert_true(is_file($PUB . '/assets/og-home.jpg'), 'файл карточки лежит в assets/');
    assert_true(strpos($home, 'og_tierlist_image(') === false,
        'и не собирает картинку из живых данных');
    assert_true(strpos($home, 'db()') === false,
        'и не ходит в базу ради превью');
});

test('в карте сайта есть оба адреса', function () use ($PUB) {
    $map = read_file_or_fail($PUB . '/sitemap.xml');
    assert_true(strpos($map, '<loc>https://maknemy.com/</loc>') !== false, 'корень');
    assert_true(strpos($map, '<loc>https://maknemy.com/tierlist</loc>') !== false, '/tierlist');
    assert_true(strpos($map, '<loc>https://maknemy.com/news</loc>') !== false, '/news');
});

// Ни одна страница не должна вести на тирлист по старому адресу: ссылка
// осталась бы рабочей, но открывала бы главную — молча и не туда.
test('никакая страница не зовёт тирлист по старому адресу', function () use ($PUB) {
    foreach (['index.php', 'news.php', 'home.php'] as $f) {
        $s = read_file_or_fail($PUB . '/' . $f);
        assert_eq(0, preg_match('/<a[^>]*href="\/"[^>]*>\s*Тирлист/u', $s),
            "$f: ссылка «Тирлист» должна вести на /tierlist");
    }
});

// --------------------------------------------------------------------------
//  Ассеты: всё, на что ссылается главная, обязано лежать на диске.
// --------------------------------------------------------------------------

test('все локальные ассеты главной существуют', function () use ($PUB) {
    $home = read_file_or_fail($PUB . '/home.php');
    preg_match_all('/(?:src|href)="([^"#?:]+)(?:\?[^"]*)?"/', $home, $m);
    $checked = 0;
    foreach ($m[1] as $ref) {
        // Проверяем только пути к файлам. Ссылки на разделы (/tierlist,
        // /news) файлами на диске не являются — их разбирает .htaccess, и
        // отдельные тесты выше следят за этими маршрутами.
        if (strpos(basename($ref), '.') === false) continue;
        // Абсолютные пути (/favicon.ico и т. п.) считаются от корня сайта.
        $path = $ref[0] === '/' ? $PUB . $ref : $PUB . '/' . $ref;
        assert_true(is_file($path), "нет файла: $ref");
        $checked++;
    }
    assert_true($checked > 10, "проверено ссылок: $checked — подозрительно мало");
});

test('все url() из home.css разрешаются', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    preg_match_all('/url\("([^"]+)"\)/', $css, $m);
    assert_true(count($m[1]) > 0, 'в home.css должны быть картинки');
    foreach ($m[1] as $ref) {
        $path = realpath($PUB . '/css/' . $ref);
        assert_true($path !== false && is_file($path), "нет файла: $ref");
    }
});

// --------------------------------------------------------------------------
//  Анимация: набор сдвигов снят с макета и правится только сознательно.
//  Числа — разница «слайд 1 минус слайд 2» из Figma (см. спеку
//  docs/superpowers/specs/2026-08-28-home-page-design.md).
// --------------------------------------------------------------------------

test('стартовые сдвиги совпадают с макетом', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    $want = [
        'hm-fly-sakura-l' => '-199',
        'hm-fly-sakura-r' => '301',
        'hm-fly-phone'    => '473',
        'hm-fly-circle'   => '518',
        'hm-fly-sq-lg'    => '318',
        'hm-fly-sq-sm'    => '-537',
        'hm-fly-tri'      => '-627',
        'hm-fly-ghost'    => '-621',
        'hm-fly-card'     => '160',
    ];
    foreach ($want as $name => $px) {
        assert_true((bool)preg_match(
            '/@keyframes ' . preg_quote($name, '/') . '\s*\{\s*from\s*\{\s*translate:\s*calc\(' .
            preg_quote($px, '/') . ' \* var\(--u\)\);\s*\}\s*\}/', $css),
            "$name должен стартовать со сдвига {$px}px");
    }
    // Ряд карточек — единственный, кто едет по вертикали.
    assert_true((bool)preg_match(
        '/@keyframes hm-fly-cards\s*\{\s*from\s*\{\s*translate:\s*0 calc\(476 \* var\(--u\)\);\s*\}\s*\}/', $css),
        'hm-fly-cards должен стартовать со сдвига 476px вниз');
});

test('тайминг анимации взят из прототипа', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true(strpos($css, 'animation-duration: 1s;') !== false, 'длительность 1s');
    assert_true(strpos($css, 'animation-delay: 0.8s;') !== false, 'задержка 0.8s');
    assert_true(strpos($css, 'cubic-bezier(0, 0, 0.58, 1)') !== false, 'кривая EASE_OUT');
    assert_true(strpos($css, 'animation-fill-mode: both;') !== false,
        'без fill-mode: both страница мигнёт финальным кадром до старта');
});

test('анимация отключается при prefers-reduced-motion', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true((bool)preg_match(
        '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*\.hm-anim/s', $css),
        'блок reduced-motion должен глушить .hm-anim');
});

// --------------------------------------------------------------------------
//  Разделы без своей страницы не должны притворяться ссылками.
// --------------------------------------------------------------------------

test('карточки и кнопка без страницы — не ссылки', function () use ($PUB) {
    $home = read_file_or_fail($PUB . '/home.php');
    // «Фрукты» и «Цены» ещё не существуют: если кто-то сделает их <a>, они
    // уедут в никуда, а не «пока никуда».
    foreach (['Фрукты', 'Цены'] as $name) {
        assert_eq(0, preg_match('/<a[^>]*class="hm-card"[^>]*>(?:(?!<\/a>).)*?' . $name . '/su', $home),
            "карточка «{$name}» пока не должна быть ссылкой");
    }
    assert_true(strpos($home, '<a class="hm-btn hm-btn-accent" href="/tierlist">') !== false,
        'кнопка «фрукты» ведёт на тирлист');
    // А те, у кого адрес есть, обязаны на него вести.
    assert_true(strpos($home, '<a class="hm-card" href="/tierlist">') !== false, 'карточка «Тир»');
    assert_true(strpos($home, '<a class="hm-card" href="/news">') !== false, 'карточка «Новости»');
});

// --------------------------------------------------------------------------
//  Номера версий: страница несёт ?v= для своих стилей и скрипта.
// --------------------------------------------------------------------------

test('стили и скрипт главной подключены с номером версии', function () use ($PUB) {
    $home = read_file_or_fail($PUB . '/home.php');
    assert_true((bool)preg_match('/href="css\/home\.css\?v=\d+"/', $home), 'home.css с ?v=');
    assert_true((bool)preg_match('/src="js\/home\.js\?v=\d+"/', $home), 'home.js с ?v=');
});

// design-page.css отдаётся и тирлисту, и главной. Разъехавшиеся номера
// означают, что после правки общего файла одна из страниц осталась на
// закешированной версии.
test('общий design-page.css подключён с одной версией на обеих страницах', function () use ($PUB) {
    $home = read_file_or_fail($PUB . '/home.php');
    $idx  = read_file_or_fail($PUB . '/index.php');
    preg_match('/design-page\.css\?v=(\d+)/', $home, $a);
    preg_match('/design-page\.css\?v=(\d+)/', $idx, $b);
    assert_true(!empty($a[1]) && !empty($b[1]), 'обе страницы подключают design-page.css');
    assert_eq($a[1], $b[1], 'версии design-page.css должны совпадать');
});

// --------------------------------------------------------------------------
//  Наведение. В макете это подмена варианта компонента (кнопка 32:892 →
//  32:895, акцентная 32:902 → 32:904, карточка → 81:239); числа компонентов
//  домножены на 2.194 — во столько раз экземпляры в лиде крупнее мастера.
// --------------------------------------------------------------------------

test('на наведении кнопки растут — как варианты в макете', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    // Вариант наведения в макете крупнее обычного: 127×35 → 142×43 у «о нас»
    // и 127×35 → 138×39 у «фрукты». Именно рост высоты, а не смена радиуса,
    // превращает пилюлю в скруглённый прямоугольник.
    assert_true(strpos($css, 'width: calc(298 * var(--u));') !== false, 'ширина «о нас» на наведении');
    assert_true(strpos($css, 'height: calc(90 * var(--u));') !== false, 'высота на наведении');
    assert_true(strpos($css, 'width: calc(274 * var(--u));') !== false, 'ширина «фрукты» на наведении');
    // Обычные размеры — из экземпляров лида.
    assert_true(strpos($css, 'height: calc(72 * var(--u));') !== false, 'обычная высота «о нас»');
    assert_true(strpos($css, 'height: calc(81 * var(--u));') !== false, 'обычная высота «фрукты»');
});

test('обводка «о нас» пунктирная, с отношением штрихов из макета', function () use ($PUB) {
    $css  = read_file_or_fail($PUB . '/css/home.css');
    $home = read_file_or_fail($PUB . '/home.php');
    // strokeDashes [4.39, 8.78] при толщине 2.19 — штрих к промежутку 1:2.
    // `border: dashed` так не умеет: браузер считает длину штриха сам и даёт
    // примерно 1:1, то есть вдвое более густой пунктир. Поэтому SVG.
    assert_true(strpos($css, 'stroke-dasharray: calc(4.39 * var(--u)) calc(8.78 * var(--u));') !== false,
        'обычный пунктир');
    assert_true(strpos($css, 'stroke-dasharray: calc(8.78 * var(--u)) calc(21.94 * var(--u));') !== false,
        'пунктир на наведении');
    assert_true(strpos($css, 'stroke-width: calc(2.19 * var(--u));') !== false, 'обычная толщина');
    assert_true(strpos($css, 'stroke-width: calc(4.39 * var(--u));') !== false, 'толщина на наведении');
    assert_true(strpos($home, 'vector-effect="non-scaling-stroke"') !== false,
        'растяжение рамки не должно перекашивать штрихи');
    assert_eq(0, preg_match('/\.hm-btn-ghost\s*\{[^}]*border:[^;]*dashed/s', $css),
        'пунктир не должен снова стать border: dashed');
});

// В макете подпись меняет не только регистр, но и шрифт: обычное состояние —
// узкий Bebas прописными, наведённое — широкий гротеск строчными. Слои
// перетекают прозрачностью, как Smart Animate между вариантами.
test('подпись «о нас» перетекает из прописных в строчные', function () use ($PUB) {
    $css  = read_file_or_fail($PUB . '/css/home.css');
    $home = read_file_or_fail($PUB . '/home.php');
    // Три слоя всего: у «о нас» два (прописной и строчный), у «фрукты» —
    // только широкий строчный, она в макете такая в обоих состояниях.
    assert_eq(3, substr_count($home, 'hm-btn-word-'), 'слои подписей');
    assert_true(strpos($css, '.hm-btn-word-rest') !== false, 'слой прописных');
    assert_true(strpos($css, '.hm-btn-word-hover') !== false, 'слой строчных');
    assert_true((bool)preg_match('/\.hm-btn-word\s*\{[^}]*transition:\s*opacity/s', $css),
        'слои должны перетекать, а не переключаться');
});

// В макете экземпляр «о нас» перекрывает заливку варианта нулевой альфой —
// на записи прототипа кнопка остаётся тёмной. Сплошная заливка здесь была
// ошибкой, и тест сторожит, чтобы она не вернулась.
test('«о нас» не заливается на наведении', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_eq(0, preg_match('/\.hm-btn-ghost[^{]*:hover[^{]*\{[^}]*background:/s', $css),
        'у наведённой «о нас» не должно быть заливки');
    assert_eq(0, substr_count($css, '.hm-btn-ghost::before'), 'слой заливки должен был уйти');
});

test('градиент «фрукты» разворачивается на наведении', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    // Углы посчитаны из ручек макета: [0.967,-0.076] → [0,1] и [0,0] → [1,1.163].
    assert_true(strpos($css, 'linear-gradient(252deg, var(--hm-accent-a), var(--hm-accent-b))') !== false,
        'обычный градиент — светлый конец справа');
    assert_true(strpos($css, 'linear-gradient(109deg, var(--hm-accent-a), var(--hm-accent-b))') !== false,
        'на наведении светлый конец слева');
    // Вторым слоем, а не сменой background: background-image не
    // интерполируется и просто перещёлкнулся бы.
    assert_true(strpos($css, '.hm-btn-accent::before') !== false, 'разворот сделан отдельным слоем');
});

test('тень углубляется на наведении', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true(strpos($css, '0 calc(15.4 * var(--u)) calc(46.1 * var(--u)) rgba(37, 44, 97, 0.2)') !== false,
        'глубокая тень наведения');
    assert_true(strpos($css, '0 calc(6.6 * var(--u)) calc(17.6 * var(--u)) rgba(136, 144, 194, 0.25)') !== false,
        'вторая тень наведения');
});

test('арт на карточке поворачивается, а не просто растёт', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    // Числа сняты из ноды варианта наведения (81:239) через мост Figma:
    // сторона 252 → 265.84 (×1.055), поворот 0° → -23.743°. В CSS знак
    // обратный: в Figma отсчёт против часовой, в CSS — по часовой.
    assert_true(strpos($css, 'rotate(23.743deg)') !== false, 'поворот из ноды');
    assert_true(strpos($css, 'scale(1.0548)') !== false, 'увеличение из ноды');
    assert_true(strpos($css, 'translate(calc(57.51 * var(--u)), calc(-71 * var(--u)))') !== false,
        'сдвиг от левого верхнего угла');
    assert_true(strpos($css, 'transform-origin: 0 0;') !== false,
        'вращение вокруг того же угла, от которого считался сдвиг');
    // Обе прежние догадки давали тот же габарит 350.4 и обе были неверны —
    // тест держит именно ту пару, что лежит в макете.
    assert_eq(0, substr_count($css, 'scale(1.3908)'), 'догадка «просто увеличение» не должна вернуться');
    assert_eq(0, substr_count($css, 'rotate(-10deg)'), 'догадка «1.2 и 10°» не должна вернуться');
});

test('кружок со стрелкой стоит там, где в макете', function () use ($PUB) {
    $css  = read_file_or_fail($PUB . '/css/home.css');
    $home = read_file_or_fail($PUB . '/home.php');
    assert_true(strpos($css, 'left: calc(252.8 * var(--u));') !== false, 'позиция по X');
    assert_true(strpos($css, 'top: calc(322 * var(--u));') !== false, 'позиция по Y');
    assert_true(strpos($css, 'width: calc(46.9 * var(--u));') !== false, 'диаметр');
    // Кружок лежит во всех пяти карточках, чтобы включение «Фруктов» и
    // «Цен» свелось к замене div на a.
    assert_eq(5, substr_count($home, 'class="hm-card-go"'), 'кружок в каждой карточке');
});

// :hover на тач-экране залипает после тапа — карточка осталась бы в
// наведённом состоянии до следующего касания в другом месте.
test('наведение закрыто медиазапросом hover', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true((bool)preg_match('/@media \(hover: hover\)\s*\{/', $css), 'блок @media (hover: hover)');
    $at = strpos($css, '@media (hover: hover)');
    assert_true($at !== false && strpos($css, 'a.hm-card:hover .hm-card-art') > $at,
        'правила наведения должны лежать внутри этого блока');
});

// Наведение обещает переход. У «фруктов» и «цен» страниц нет, и подсвечивать
// их как кликабельные нельзя.
test('разделы без страницы не подсвечиваются при наведении', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true(substr_count($css, ':not([aria-disabled="true"]):hover') >= 3,
        'правила кнопок должны исключать aria-disabled');
    assert_true(strpos($css, 'a.hm-card:hover') !== false && strpos($css, '.hm-card:hover .hm-card-art') !== false,
        'карточки подсвечиваются только как ссылки (a.hm-card)');
});

test('тайминг наведения взят из прототипа', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true(strpos($css, '--hm-hover: 0.744s;') !== false, 'длительность 0.744s');
});

// Главная набирается теми же шрифтами, что и тирлист: переменные --ui и
// --display объявлены в base.css. Раньше здесь стоял Oswald (подмена Bebas
// Neue из макета) — на сайте так не набрано больше нигде.
test('главная набрана шрифтами тирлиста', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    assert_true(strpos($css, 'font-family: var(--ui);') !== false, 'текст — --ui');
    assert_true(strpos($css, 'font-family: var(--display);') !== false, 'заголовки — --display');
    assert_eq(0, preg_match('/font-family:[^;]*Oswald/i', $css), 'Oswald должен был уйти');
});

// Интерлиньяж меньше кегля означает налезающие друг на друга строки. У
// Bebas из макета это сходило с рук (нет надстрочных элементов, низкий
// рост строчных), у обычного гротеска — нет.
test('нигде интерлиньяж не меньше кегля', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/home.css');
    // Пары «font-size: calc(N*u)» и следующий за ней «line-height: calc(M*u)».
    preg_match_all('/font-size:\s*calc\(([\d.]+) \* var\(--u\)\);\s*
\s*line-height:\s*calc\(([\d.]+) \* var\(--u\)\);/', $css, $m, PREG_SET_ORDER);
    foreach ($m as $pair) {
        assert_true((float)$pair[2] >= (float)$pair[1],
            "интерлиньяж {$pair[2]} меньше кегля {$pair[1]}");
    }
    // И то же самое для мобильной раскладки, где размеры в пикселях.
    preg_match_all('/font-size:\s*([\d.]+)px;\s*line-height:\s*([\d.]+)px;/', $css, $m2, PREG_SET_ORDER);
    foreach ($m2 as $pair) {
        assert_true((float)$pair[2] >= (float)$pair[1],
            "интерлиньяж {$pair[2]}px меньше кегля {$pair[1]}px");
    }
    assert_true(true, 'проверка выполнена');
});

// После переезда тирлиста с "/" на /tierlist корень стал отдельным
// разделом. С каждой страницы сайта на него должен быть путь — иначе
// раздел оказывается доступен только по прямому адресу.
test('на главную можно попасть с любой страницы', function () use ($PUB) {
    foreach (['index.php' => 'тирлист', 'news.php' => 'новости', 'home.php' => 'главная'] as $f => $lab) {
        $s = read_file_or_fail($PUB . '/' . $f);
        assert_true((bool)preg_match('/<a[^>]*href="\/"[^>]*>/', $s),
            "$lab: нет ссылки на корень");
    }
});

// --------------------------------------------------------------------------
//  Лента новостей по редизайну (Figma «новости», node 169:600).
// --------------------------------------------------------------------------

test('лента подключает шапку, фон и свой редизайн', function () use ($PUB) {
    $s = read_file_or_fail($PUB . '/news.php');
    foreach (['css/topbar.css', 'css/design-page.css', 'css/news-design.css'] as $css) {
        assert_true(strpos($s, $css) !== false, "не подключён $css");
    }
    assert_true(strpos($s, '<header class="mk-top">') !== false, 'общая шапка сайта');
    assert_true(strpos($s, '<footer class="mk-foot">') !== false, 'общий подвал');
});

// topbar.css прячет старый бренд и дублирующее меню правилом
// `.mk-top ~ .toolbar …`. Соседний комбинатор смотрит только вперёд, так
// что порядок в разметке — часть работающего кода, а не оформление.
test('тулбар идёт после шапки, иначе старое меню останется видимым', function () use ($PUB) {
    foreach (['news.php', 'index.php'] as $f) {
        $s = read_file_or_fail($PUB . '/' . $f);
        $top = strpos($s, '<header class="mk-top">');
        $bar = strpos($s, '<div class="toolbar"');
        assert_true($top !== false, "$f: нет шапки");
        if ($bar === false) continue;
        assert_true($top < $bar, "$f: тулбар должен идти после шапки");
    }
});

// Заголовок страницы и фильтры убраны по редизайну. renderFilters() при
// этом остался — он обязан молча выходить, не найдя контейнера, иначе
// лента упадёт целиком.
test('фильтры убраны, и код это переживает', function () use ($PUB) {
    $s  = read_file_or_fail($PUB . '/news.php');
    $js = read_file_or_fail($PUB . '/js/news-page.js');
    // Комментарии вырезаем: рядом с местом, где стояли фильтры, в
    // комментарии приведён сам тег — там сказано, как вернуть их обратно.
    $markup = preg_replace('/<!--.*?-->/s', '', $s);
    assert_eq(0, preg_match('/<div[^>]*id="newsFilters"/', $markup), 'контейнера фильтров быть не должно');
    assert_eq(0, preg_match('/<h1[^>]*nw-title/', $markup), 'заголовка страницы быть не должно');
    assert_true((bool)preg_match('/function renderFilters\(\)\s*\{[^}]*if \(!filtersEl\) return;/s', $js),
        'renderFilters должен выходить без контейнера');
});

// Кнопка «поделиться» осталась (в макете она есть отдельной иконкой), но
// стала круглой и без подписи — значит подпись обязана быть в title и
// aria-label, иначе назначение иконки неочевидно.
// Обе кнопки карточки — одинаковые круги с белым знаком: сердце (контур
// из макета) и изогнутая стрелка. Пилюля с числом и бумажный самолётик
// заменены по правке заказчика, и тест держит именно новый вид.
test('кнопки карточки — круги со знаками, а не пилюля с числом', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/news-design.css');
    $js  = read_file_or_fail($PUB . '/js/news-page.js');
    assert_true((bool)preg_match('/\.nw-card \.nw-like,\s*
\s*\.nw-card \.nw-copy \{[^}]*border-radius: 50%;/s', $css),
        'обе кнопки круглые');
    assert_true(strpos($css, 'width: calc(64.4 * var(--u));') !== false, 'диаметр из макета');
    assert_true(strpos($js, 'HEART_PATH') !== false, 'сердце — контур, а не эмодзи');
    assert_eq(0, substr_count($js, '"🤍"'), 'эмодзи-сердца быть не должно');
    assert_eq(0, substr_count($js, '"💙"'), 'эмодзи-сердца быть не должно');
    // Число лайков спрятано от глаз, но обязано остаться доступным.
    assert_true((bool)preg_match('/\.nw-card \.nw-like-count \{[^}]*clip-path: inset\(50%\);/s', $css),
        'счётчик скрыт визуально, а не удалён');
    assert_true(strpos($js, 'btn.title = tx(liked ? "news.likeRemove" : "news.like") + " (" + likes + ")";') !== false,
        'число лайков должно остаться в подсказке');
    // У SVG нет offsetWidth — перезапуск анимации сердца сломался бы молча.
    assert_true(strpos($js, 'heartEl.getBoundingClientRect();') !== false,
        'перезапуск анимации должен работать на SVG');
});

test('кнопка «поделиться» — иконка с доступной подписью', function () use ($PUB) {
    $js = read_file_or_fail($PUB . '/js/news-page.js');
    assert_true(strpos($js, 'SHARE_PATH') !== false, 'иконка рисуется контуром, а не эмодзи');
    assert_eq(0, substr_count($js, '"🔗︎"'), 'эмодзи-скрепка должна была уйти');
    assert_true(strpos($js, 'copy.title = tx("news.copyLink");') !== false, 'подсказка');
    assert_true(strpos($js, 'copy.setAttribute("aria-label", tx("news.copyLink"));') !== false, 'aria-label');
    assert_true(strpos($js, 'copyPostLink(post, copy)') !== false, 'поведение кнопки не изменилось');
});

test('геометрия карточки взята из макета', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/news-design.css');
    // Мастер-компонент 843×763 в единицах колонки (множитель 810/843).
    assert_true(strpos($css, 'width: calc(810 * var(--u));') !== false, 'ширина колонки');
    assert_true(strpos($css, 'border-radius: calc(23.06 * var(--u));') !== false, 'радиус карточки');
    assert_true(strpos($css, 'height: calc(381.5 * var(--u));') !== false, 'высота картинки');
    assert_true(strpos($css, 'height: calc(64.4 * var(--u));') !== false, 'диаметр круглых кнопок');
    // Полосатые панели: 248×670, полосы под -45° толщиной 30 с шагом 78.
    assert_true(strpos($css, 'width: calc(248 * var(--u));') !== false, 'ширина панели');
    assert_true(strpos($css, 'repeating-linear-gradient(-45deg') !== false, 'диагональные полосы');
});

// --------------------------------------------------------------------------
//  Боковые панели ленты — рекламные места, а не декор.
// --------------------------------------------------------------------------

test('борта ленты подключены к системе рекламы', function () use ($PUB) {
    $html = read_file_or_fail($PUB . '/news.php');
    $js   = read_file_or_fail($PUB . '/js/news-page.js');
    assert_true(strpos($html, 'id="newsRailL"') !== false, 'левый борт');
    assert_true(strpos($html, 'id="newsRailR"') !== false, 'правый борт');
    // Отбор кампаний — тем же модулем, что на тирлисте: две страницы не
    // должны расходиться в том, какая кампания сейчас крутится.
    assert_true(strpos($html, 'js/promo.js') !== false, 'модуль отбора кампаний');
    assert_true(strpos($js, 'promo.eligible(promo.normalizeDoc(doc), "rail"') !== false,
        'слот rail из общей системы');
    // Маркировку рекламы выкидывать нельзя.
    assert_true(strpos($js, 'erid: ') !== false, 'erid должен выводиться');
    assert_true(strpos($js, 'tx("ad.chip")') !== false, 'плашка «Реклама»');
});

// Версия общего модуля обязана совпадать: иначе браузер держит в кеше две
// копии одного файла, и страницы разъезжаются по поведению рекламы.
test('promo.js подключён одной версией на обеих страницах', function () use ($PUB) {
    preg_match('/promo\.js\?v=(\d+)/', read_file_or_fail($PUB . '/news.php'), $a);
    preg_match('/promo\.js\?v=(\d+)/', read_file_or_fail($PUB . '/index.php'), $b);
    assert_true(!empty($a[1]) && !empty($b[1]), 'обе страницы подключают promo.js');
    assert_eq($a[1], $b[1], 'версии promo.js должны совпадать');
});

// --------------------------------------------------------------------------
//  Подвал: ники участников под названиями ролей.
// --------------------------------------------------------------------------

test('в подвале у каждой роли есть ник', function () use ($PUB) {
    $nicks = ['MKSVTN', 'DANIKTOR', 'GLH', 'активно ищем', 'The Fool'];
    foreach (['index.php', 'news.php', 'home.php'] as $f) {
        $s = read_file_or_fail($PUB . '/' . $f);
        assert_eq(5, substr_count($s, 'class="mk-foot-nick"'), "$f: пять ников");
        foreach ($nicks as $n) {
            assert_true(strpos($s, '>' . $n . '</span>') !== false, "$f: нет ника $n");
        }
    }
});

// i18n.js переписывает textContent элемента с data-i18n целиком. Если ключ
// оставить на <li>, при переключении языка ник исчезнет вместе с разметкой.
test('ключ перевода роли не накрывает ник', function () use ($PUB) {
    foreach (['index.php', 'news.php'] as $f) {
        $s = read_file_or_fail($PUB . '/' . $f);
        assert_eq(0, preg_match('/<li data-i18n="site\.foot/', $s),
            "$f: ключ должен стоять на внутреннем span, а не на li");
        assert_true(strpos($s, '<li><span data-i18n="site.footAuthor">') !== false,
            "$f: ключ на внутреннем span");
    }
});

// --------------------------------------------------------------------------
//  Шапка и реклама остаются на виду при прокрутке.
// --------------------------------------------------------------------------

test('шапка липкая, и её высота живёт одним значением', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/topbar.css');
    assert_true((bool)preg_match('/\.mk-top \{[^}]*position: sticky;[^}]*top: 0;/s', $css),
        'шапка должна быть липкой');
    // Высоту читают ещё два места — панель фильтров и рекламные борта.
    // Разъедутся значения — липкие блоки полезут друг на друга.
    assert_true(strpos($css, '--mk-top-h:') !== false, 'высота вынесена в переменную');
    assert_true(strpos($css, 'height: var(--mk-top-h);') !== false, 'шапка берёт высоту оттуда же');
    // Панель фильтров липкой быть не должна: в макете она стоит на своём месте,
    // а липкой наезжала на постер и легенду. Липкая только шапка.
    $dp = read_file_or_fail($PUB . '/css/design-page.css');
    assert_true((bool)preg_match('/\.toolbar \{[^}]*position: static;/s', $dp),
        'панель фильтров не липкая');
    $nd = read_file_or_fail($PUB . '/css/news-design.css');
    // Борт опущен ещё на высоту строки переключателя языка: она тоже
    // липкая и прижата вправо, иначе накрывала бы правый борт.
    assert_true(strpos($nd, 'top: calc(var(--mk-top-h, 0px) + 16px + 55px);') !== false,
        'рекламный борт прилипает под шапкой и строкой языка');
});

// overflow: hidden создаёт скролл-контейнер и ломает position: sticky у
// потомков — борта переставали липнуть. clip обрезает так же, но
// контейнером не становится.
test('полотно ленты обрезано clip, а не hidden', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/news-design.css');
    assert_true((bool)preg_match('/\.nw-page \{[^}]*overflow: clip;/s', $css), 'нужен overflow: clip');
    assert_eq(0, preg_match('/\.nw-page \{[^}]*overflow: hidden;/s', $css), 'hidden сломает липкие борта');
});

// Тулбар лежит СНАРУЖИ .nw-page, где объявлена --u. calc с этой переменной
// там невалиден, и весь margin молча обнулялся: переключатель языка
// прилипал к линии под шапкой и срезался правым краем окна.
test('отступы тулбара ленты не зависят от --u', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/news-design.css');
    assert_true((bool)preg_match('/\.mk-top ~ \.toolbar \{[^}]*margin: 18px 42px 0;/s', $css),
        'отступы должны быть в пикселях');
    assert_eq(0, preg_match('/\.mk-top ~ \.toolbar \{[^}]*margin:[^;]*var\(--u\)/s', $css),
        '--u снаружи .nw-page не определена');
});

// --------------------------------------------------------------------------
//  Полоса прокрутки в цветах макета.
// --------------------------------------------------------------------------

// В Chromium стандартные scrollbar-width / scrollbar-color и правила
// ::-webkit-scrollbar несовместимы: как только задано стандартное свойство,
// движок перестаёт применять ::-webkit-* — вместе со скруглением и
// градиентом. Поэтому стандартные отданы только Firefox, через @supports.
test('оформление полосы прокрутки не отключает само себя', function () use ($PUB) {
    $css = read_file_or_fail($PUB . '/css/design-page.css');
    assert_true(strpos($css, 'html::-webkit-scrollbar-thumb') !== false, 'ползунок оформлен');
    // Цвета из макета: дорожка #D9D9D9, ползунок — акцентный градиент.
    assert_true(strpos($css, 'background: #d9d9d9;') !== false, 'цвет дорожки из макета');
    assert_true(strpos($css, 'linear-gradient(180deg, #61b5e9, #2d4aed)') !== false,
        'градиент ползунка из макета');
    assert_true(strpos($css, '@supports not selector(::-webkit-scrollbar)') !== false,
        'стандартные свойства обязаны быть закрыты фичер-запросом');
    // Стандартные свойства должны встречаться ровно по разу и только
    // ПОСЛЕ открытия @supports — иначе Chromium отключит ::-webkit-*.
    // Сравниваем позиции, а не вырезаем блок регуляркой: файл хранится с
    // CRLF, и вырезание по переводу строки на нём молча не срабатывало.
    $at = strpos($css, '@supports not selector(::-webkit-scrollbar)');
    // scrollbar-width встречается ещё раз у строки фильтров — там он
    // прячет её собственную горизонтальную полосу и к этому блоку
    // отношения не имеет. Поэтому проверяем не количество, а что за
    // пределами @supports нет НИ ОДНОГО объявления на корне.
    assert_true(strpos($css, 'scrollbar-color:') > $at, 'scrollbar-color только внутри @supports');
    $rootStd = preg_match('/html \{[^}]*scrollbar-(width|color)/s', substr($css, 0, $at));
    assert_eq(0, $rootStd, 'стандартные свойства на html вне @supports отключат ::-webkit-*');
    // Полоса красится только у корневого скроллера: иначе те же цвета
    // достались бы горизонтальной прокрутке карточек на телефоне.
    assert_true(strpos($css, 'html::-webkit-scrollbar-thumb') !== false,
        'правила должны быть привязаны к html');
});

run_tests();
