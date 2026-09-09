<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';

// Страница чатов — /chat. Как и у profile_page_test.php, здесь не вызываются
// функции (логика покрыта chat_test.php), а проверяется то, что не поймает ни
// компилятор, ни линтер: маршрут, ссылка на чат со всех страниц, ключи
// словаря и решения по безопасности разметки.

$ROOT = dirname(__DIR__);
$PUB  = $ROOT . '/public_html';

// Все страницы сайта: кнопка чата стоит в общей шапке, значит должна вести на
// /chat отовсюду.
$PAGES = ['home.php', 'index.php', 'news.php', 'calculator.php', 'chat.php'];

function cp_read(string $path): string {
    $s = @file_get_contents($path);
    if ($s === false) throw new RuntimeException("не читается: $path");
    return $s;
}

// Разметка без комментариев. Комментарии в этих файлах ОБЪЯСНЯЮТ решения и
// потому содержат ровно те слова, которые ищут проверки ниже: «data-soon» в
// пояснении, почему кнопка перестала им быть. Считать их — значит падать на
// собственной документации.
function cp_markup(string $src): string {
    return preg_replace('/<!--.*?-->/s', '', $src);
}

// То же для JavaScript: блочные и строчные комментарии прочь. Строки с "//"
// внутри (адреса вида http://) в этих файлах не встречаются — путь к API
// начинается с одинарного слэша, — но если появятся, проверку придётся
// уточнять, а не ослаблять.
function cp_code(string $src): string {
    $src = preg_replace('~/\*.*?\*/~s', '', $src);
    return preg_replace('~^\s*//.*$~m', '', $src);
}

// Ключ обязан лежать в ОБОИХ языковых блоках по одному разу. substr_count по
// всему файлу не отличает «в ru и en» от «дважды в ru» — то есть пропускает
// ровно тот регресс, ради которого пишется.
function cp_assert_key(string $i18n, string $key): void {
    $enAt = strpos($i18n, "\n    en: {");
    assert_true($enAt !== false, 'блок en найден');
    $needle = '"' . $key . '"';
    assert_eq(1, substr_count(substr($i18n, 0, $enAt), $needle), "ключ $key ровно один раз в ru");
    assert_eq(1, substr_count(substr($i18n, $enAt), $needle), "ключ $key ровно один раз в en");
}

// --------------------------------------------------------------------------
//  Маршрут
// --------------------------------------------------------------------------

test('/chat ведёт на chat.php', function () use ($PUB) {
    $ht = cp_read($PUB . '/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^chat$ /chat.php [L]') !== false,
        'внутренний рероут /chat → /chat.php');
});

test('/chat/ уводится 301-м на адрес без слэша, и раньше рероута', function () use ($PUB) {
    $ht    = cp_read($PUB . '/.htaccess');
    $strip = strpos($ht, 'RewriteRule ^chat/$ /chat [L,R=301]');
    $route = strpos($ht, 'RewriteRule ^chat$ /chat.php [L]');
    assert_true($strip !== false, 'правило снятия слэша на месте');
    assert_true($route !== false, 'правило рероута на месте');
    assert_true($strip < $route, 'снятие слэша должно стоять раньше рероута');
});

// Внутренний рероут /chat → /chat.php сам попадает под паттерн ^chat\.php$:
// без условия на REDIRECT_STATUS получился бы бесконечный редирект. Та же
// ловушка, что у /calculator и /profile.
test('прямой /chat.php уводится на /chat, и только снаружи', function () use ($PUB) {
    $ht = cp_read($PUB . '/.htaccess');
    assert_true((bool)preg_match(
        '/RewriteCond %\{ENV:REDIRECT_STATUS\} \^\$\s*\R\s*RewriteRule \^chat\\\\\.php\$ \/chat \[L,R=301\]/',
        $ht), 'редирект chat.php должен быть закрыт условием REDIRECT_STATUS');
});

// Переписка приватная: индексировать её нельзя, и ходить по ссылкам из неё
// поисковику тоже незачем.
test('чат закрыт от индексации и не значится в карте сайта', function () use ($PUB) {
    $s = cp_read($PUB . '/chat.php');
    assert_true(strpos($s, '<meta name="robots" content="noindex, nofollow" />') !== false,
        'noindex, nofollow');
    assert_true(strpos($s, '<link rel="canonical" href="https://maknemy.com/chat" />') !== false,
        'canonical чата');
    $map = cp_read($PUB . '/sitemap.xml');
    assert_eq(false, strpos($map, 'maknemy.com/chat'), '/chat не должен попадать в sitemap');
});

// --------------------------------------------------------------------------
//  Кнопка чата в шапке
// --------------------------------------------------------------------------

test('кнопка чата ведёт на /chat со всех страниц', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = cp_read($PUB . '/' . $f);
        assert_true((bool)preg_match('/<a class="mk-chat" href="\/chat"/', $s),
            "$f: чат должен вести на /chat");
        assert_eq(0, preg_match('/<button class="mk-chat"/', $s),
            "$f: чат не должен оставаться кнопкой data-soon");
    }
});

test('на самом чате кнопка помечена aria-current, на остальных — нет', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = cp_read($PUB . '/' . $f);
        preg_match('/<a class="mk-chat"[^>]*>/', $s, $m);
        assert_true(!empty($m), "$f: кнопка чата найдена");
        $has = strpos($m[0], 'aria-current="page"') !== false;
        assert_eq($f === 'chat.php', $has, "$f: aria-current только на своей странице");
    }
});

// Чат из «В активной разработке» вышел, и в шапке осталось двое: «Трейдинг»
// (раздела нет) и кнопка профиля — её js/topbar.js по ответу /api/session.php
// превращает во вход через Roblox или в меню пользователя, а до ответа она
// остаётся прежней заглушкой.
test('чат больше не значится неготовым разделом', function () use ($PUB, $PAGES) {
    foreach ($PAGES as $f) {
        $s = cp_read($PUB . '/' . $f);
        $head = cp_markup(substr($s, 0, strpos($s, '</header>') ?: strlen($s)));
        assert_eq(2, substr_count($head, 'data-soon'), "$f: трейдинг и профиль");
        assert_eq(0, preg_match('/<button class="mk-chat"[^>]*data-soon/', $head),
            "$f: чат среди них быть не должен");
    }
});

// --------------------------------------------------------------------------
//  Подключения
// --------------------------------------------------------------------------

test('чат подключает свои css и js с версией, и файлы существуют', function () use ($PUB) {
    $s = cp_read($PUB . '/chat.php');
    foreach ([
        'css/chat.css'    => '/<link rel="stylesheet" href="(css\/chat\.css)\?v=\d+" \/>/',
        'js/chat-page.js' => '/<script src="(js\/chat-page\.js)\?v=\d+" defer><\/script>/',
    ] as $file => $re) {
        assert_true((bool)preg_match($re, $s), "$file подключён с версией");
        assert_true(is_file($PUB . '/' . $file), "$file существует на диске");
    }
});

test('чат использует общие шапку, подвал, фон и счётчик', function () use ($PUB) {
    $s = cp_read($PUB . '/chat.php');
    foreach (['css/base.css', 'css/topbar.css', 'css/design-page.css', 'js/topbar.js'] as $shared) {
        assert_true(strpos($s, $shared . '?v=') !== false, "$shared подключён");
    }
    assert_true(strpos($s, '<header class="mk-top">') !== false, 'общая шапка');
    assert_true(strpos($s, '<footer class="mk-foot">') !== false, 'общий подвал');
    assert_true(strpos($s, 'metrika_counter_html()') !== false, 'счётчик Метрики');
});

// Словарь один на весь сайт: разные номера версий означают, что часть
// посетителей держит в кеше копию без новых ключей.
test('словарь подключён одной версией на всех страницах', function () use ($PUB, $PAGES) {
    $seen = [];
    foreach ($PAGES as $f) {
        preg_match('/i18n\.js\?v=(\d+)/', cp_read($PUB . '/' . $f), $m);
        assert_true(!empty($m[1]), "$f: подключает i18n.js с версией");
        $seen[$m[1]] = true;
    }
    assert_eq(1, count($seen), 'версия одна: ' . implode(', ', array_keys($seen)));
});

// --------------------------------------------------------------------------
//  Безопасность разметки
// --------------------------------------------------------------------------

// Тексты сообщений и ники — пользовательский ввод. Один innerHTML в этом
// файле означает, что чужое сообщение выполнит скрипт у всех, кто открыл
// переписку. Проверка тупая нарочно: запрет целиком, без исключений.
test('в скрипте чата нет ни одного innerHTML', function () use ($PUB) {
    $js = cp_code(cp_read($PUB . '/js/chat-page.js'));
    assert_eq(0, substr_count($js, 'innerHTML'), 'innerHTML запрещён — только textContent');
    assert_eq(0, substr_count($js, 'insertAdjacentHTML'), 'и insertAdjacentHTML тоже');
    assert_eq(0, substr_count($js, 'outerHTML'), 'и outerHTML');
    assert_true(strpos($js, 'textContent') !== false, 'текст ставится textContent');
});

// Оценка — ровно одна из пяти, значит радиогруппа, а не набор кнопок:
// стрелки обязаны ходить по ней с клавиатуры.
test('оценка размечена радиогруппой', function () use ($PUB) {
    $s = cp_read($PUB . '/chat.php');
    assert_eq(5, preg_match_all('/<input type="radio" name="stars" value="[1-5]" \/>/', $s),
        'пять радиокнопок одной группы');
    assert_true(strpos($s, '<fieldset class="ct-stars"') !== false, 'обёрнуты в fieldset');
    assert_true(strpos($s, '<legend class="ct-sr-only"') !== false, 'у группы есть подпись');
});

// Список диалогов — не просто набор кнопок: скринридер обязан знать, какой
// открыт, иначе выбор виден только глазами по обводке.
test('выбор диалога объявляется, а не только подсвечивается', function () use ($PUB) {
    $s  = cp_read($PUB . '/chat.php');
    $js = cp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($s, 'role="tablist"') !== false, 'список размечен tablist');
    assert_true(strpos($js, "setAttribute('role', 'tab')") !== false, 'карточки — вкладки');
    assert_true(strpos($js, "aria-selected") !== false, 'состояние выбора объявляется');
});

// Приходящие сообщения обязаны объявляться, но не перебивать чтение.
test('лента сообщений объявляется вежливо', function () use ($PUB) {
    $s = cp_read($PUB . '/chat.php');
    assert_true((bool)preg_match('/id="ctLog"[^>]*role="log"[^>]*aria-live="polite"/', $s),
        'лента — role="log" с polite');
});

// --------------------------------------------------------------------------
//  Переводы
// --------------------------------------------------------------------------

test('под каждым data-i18n страницы чата есть строка в ru и en', function () use ($PUB) {
    $s    = cp_read($PUB . '/chat.php');
    $i18n = cp_read($PUB . '/js/i18n.js');
    preg_match_all('/data-i18n(?:-title|-label|-placeholder)?="([^"]+)"/', $s, $m);
    $keys = array_values(array_unique($m[1]));
    assert_true(count($keys) > 0, 'ключи в разметке нашлись');
    foreach ($keys as $k) { cp_assert_key($i18n, $k); }
});

// Ключи, которые ставит скрипт: общий проход по разметке их не покрывает,
// потому что узлов с ними в HTML нет.
test('строки, которые ставит скрипт, тоже переведены', function () use ($PUB) {
    $i18n = cp_read($PUB . '/js/i18n.js');
    foreach (['chat.with', 'chat.you', 'chat.pick', 'chat.noThreads', 'chat.noMessages',
              'chat.notReady', 'chat.error', 'chat.sendFailed', 'chat.tooFast',
              'chat.reviewSaved', 'chat.reviewFailed', 'chat.pickStars', 'chat.hideList',
              'chat.login', 'chat.status.online', 'chat.status.offline'] as $k) {
        cp_assert_key($i18n, $k);
    }
});

// Пустых состояний три, и они отвечают на разные вопросы: «переписки нет»,
// «чатов на сайте ещё нет» и «ответ не доехал». Общий текст на все три
// вводил бы в заблуждение.
test('у пустого чата три разных объяснения', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    foreach (['chat.noThreads', 'chat.notReady', 'chat.error'] as $k) {
        assert_true(strpos($js, $k) !== false, "скрипт различает случай $k");
    }
});

// Сайт отдаёт исходники как есть, без сборки: комментарии в файлах из
// public_html приезжают посетителю и читаются через «Просмотр кода». Их убрали
// из всего проекта (коммит dcb9b8e, 3241 штука в 34 файлах) — новые файлы
// обязаны следовать тому же правилу, иначе политика продержится до первой
// страницы.
//
// PHP-комментарии проверять незачем: они не покидают сервер, и в api/lib/chat.php
// объяснения оставлены намеренно.
test('в отдаваемых файлах чата нет пояснительных комментариев', function () use ($PUB) {
    $css = cp_read($PUB . '/css/chat.css');
    assert_eq(0, preg_match('~/\*~', $css), 'css/chat.css без комментариев');

    $js = cp_read($PUB . '/js/chat-page.js');
    assert_eq(0, preg_match('~/\*~', $js), 'js/chat-page.js без блочных комментариев');
    assert_eq(0, preg_match('~^\s*//~m', $js), 'и без строчных');

    // В разметке остаётся только то, что политика оставила намеренно:
    // маркеры счётчика Метрики, по которым metrika_strip() вырезает его из
    // админки. Их печатает metrika_counter_html(), в исходнике страницы их нет.
    $page = cp_read($PUB . '/chat.php');
    assert_eq(0, substr_count($page, '<!--'), 'chat.php без HTML-комментариев');
});

run_tests();
