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

// --------------------------------------------------------------------------
//  Ссылка на профиль собеседника
// --------------------------------------------------------------------------

// Имя собеседника в шапке комнаты ведёт на его профиль — ровно за этим
// репутация и статус там и показываются: посмотреть, с кем имеешь дело, перед
// сделкой.
//
// Страница профиля приезжает ОТДЕЛЬНОЙ веткой, и до неё ссылка вела бы в 404.
// Поэтому решает сервер: есть ли profile.php рядом. Порядок вливания веток от
// этого перестаёт зависеть — появился файл, заработали ссылки.
test('имя собеседника ведёт на его профиль, если тот уже есть на сайте', function () use ($PUB) {
    $page = cp_read($PUB . '/chat.php');
    $js   = cp_read($PUB . '/js/chat-page.js');
    $i18n = cp_read($PUB . '/js/i18n.js');

    assert_true(strpos($page, "\$ctProfile = is_file(__DIR__ . '/profile.php');") !== false,
        'наличие страницы профиля проверяется, а не задаётся флагом руками');
    assert_true(strpos($page, '<?= $ctProfile ? \' data-profile="1"\' : \'\' ?>') !== false,
        'и уезжает в разметку признаком');

    assert_true(strpos($js, "shell.dataset.profile === '1'") !== false,
        'скрипт читает признак с сервера, а не решает сам');
    assert_true(strpos($js, "a.href = PROFILE_PATH + '?id=' + encodeURIComponent(peer.id);") !== false,
        'ссылка ведёт на профиль собеседника');
    assert_true(strpos($js, "var PROFILE_PATH = '/profile';") !== false, 'адрес объявлен один раз');

    // Без признака имя остаётся текстом — ссылки в никуда быть не должно.
    assert_true(strpos($js, 'if (!withProfile) {') !== false, 'ветка без профиля есть');

    // Ник — пользовательский текст. Он ставится textContent, а не разметкой:
    // innerHTML во всём файле запрещён отдельной проверкой выше.
    assert_true(strpos($js, 'a.textContent = peer.nick;') !== false, 'ник вставляется текстом');

    // Ссылка, подписанная одним ником, не говорит, куда ведёт.
    assert_true(strpos($js, "a.setAttribute('aria-label', tx('chat.peerProfile'") !== false,
        'у ссылки есть внятная подпись');
    cp_assert_key($i18n, 'chat.peerProfile');

    assert_true(strpos(cp_read($PUB . '/css/chat.css'), '.ct-peer-link') !== false,
        'и свой стиль: ссылку видно, что она ссылка');
});

// --------------------------------------------------------------------------
//  Смайлики
// --------------------------------------------------------------------------

// Набор живёт одной строкой в скрипте, а не сотней кнопок в разметке: панель
// открывают не в каждом визите, а одиннадцать килобайт разметки приезжали бы
// всем и всегда. Кнопки строятся при первом открытии.
function cp_emoji_list(string $js): array {
    if (!preg_match("/var EMOJI = '([^']+)';/u", $js, $m)) { return []; }
    return explode(' ', $m[1]);
}

test('панель смайликов подключена к кнопке и объявляет своё состояние', function () use ($PUB) {
    $s = cp_markup(cp_read($PUB . '/chat.php'));

    assert_true((bool)preg_match(
        '/<button class="ct-emoji-btn" type="button" id="ctEmojiBtn"\s+aria-expanded="false" aria-controls="ctEmoji"/u', $s),
        'кнопка связана с панелью и говорит, раскрыта ли она');
    assert_true(strpos($s, 'data-i18n-label="chat.emoji"') !== false, 'подпись кнопки переводится');
    assert_true((bool)preg_match('/<div class="ct-emoji" id="ctEmoji" role="group"[^>]*hidden>/u', $s),
        'панель по умолчанию скрыта');

    // Панель лежит ВНУТРИ формы отправки: она позиционируется от неё, и
    // вынесенная наружу уехала бы в угол страницы.
    $form = strpos($s, '<form class="ct-compose"');
    $panel = strpos($s, 'id="ctEmoji"');
    $end  = strpos($s, '</form>', $form === false ? 0 : $form);
    assert_true($form !== false && $panel !== false && $form < $panel && $panel < $end,
        'панель внутри формы отправки');

    cp_assert_key(cp_read($PUB . '/js/i18n.js'), 'chat.emoji');
});

test('набор смайликов без повторов и без пустых мест', function () use ($PUB) {
    $list = cp_emoji_list(cp_read($PUB . '/js/chat-page.js'));
    assert_true(count($list) > 100, 'набор из макета, а не три штуки для вида');
    assert_eq(count($list), count(array_unique($list)), 'повторов нет');
    foreach ($list as $e) {
        assert_true($e !== '', 'пустых мест нет');
        // Разделитель — пробел, поэтому ни один смайлик не должен его содержать:
        // иначе один символ развалился бы на два «смайлика».
        assert_eq(0, preg_match('/\s/u', $e), 'внутри смайлика нет пробелов');
    }
});

// Панель — сетка из сотни с лишним кнопок. Без своей клавиатурной модели она
// означала бы 118 остановок по Tab между полем ввода и кнопкой «Отправить».
test('по панели ходят стрелками, а не Tab-ом', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($js, "b.tabIndex = i === 0 ? 0 : -1;") !== false,
        'в обходе по Tab остаётся ровно одна кнопка');
    assert_true(strpos($js, "all[k].tabIndex = k === emojiAt ? 0 : -1;") !== false,
        'и она переезжает вслед за фокусом');
    foreach (['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End'] as $key) {
        assert_true(strpos($js, "'" . $key . "'") !== false, "стрелка $key обслуживается");
    }
    // Число колонок считается по раскладке, а не задано числом: на узком
    // экране их шесть, на широком восемь, и «вниз» обязано попадать в тот же
    // столбец.
    assert_true(strpos($js, 'function emojiColumns()') !== false, 'колонки считаются по факту');
    assert_true(strpos($js, 'offsetTop') !== false, 'по раскладке, а не по числу в коде');

    assert_true(strpos($js, "e.key !== 'Escape'") !== false, 'Escape закрывает панель');
    assert_true(strpos($js, 'emojiBtn.focus();') !== false, 'и возвращает фокус на кнопку');
});

// Смайлик вставляется в место курсора, а не приписывается в конец: человек
// правит середину сообщения, и «в конец» означало бы переписывать хвост.
test('смайлик встаёт в место курсора и не переполняет сообщение', function () use ($PUB) {
    $js  = cp_read($PUB . '/js/chat-page.js');
    $lib = cp_read($PUB . '/api/lib/chat.php');

    assert_true(strpos($js, 'input.selectionStart') !== false, 'позиция курсора учитывается');
    // Проверяем саму склейку, а не упоминание selectionStart: приписать
    // смайлик в конец можно и не тронув эту строку.
    assert_true(strpos($js, 'input.value.slice(0, start) + ch + input.value.slice(end)') !== false,
        'текст склеивается вокруг выделения, а не дописывается в хвост');
    assert_true(strpos($js, 'input.setSelectionRange(caret, caret);') !== false,
        'после вставки курсор стоит за смайликом');
    assert_true(strpos($js, 'input.focus();') !== false, 'фокус возвращается в поле');

    // Предел тот же, что на сервере, и считается ТАК ЖЕ — в символах.
    // maxlength у поля меряет единицы UTF-16, и по нему эмодзи весит два.
    assert_true(strpos($js, 'if (Array.from(next).length > BODY_MAX) { return; }') !== false,
        'вставка не переполняет сообщение');
    assert_true((bool)preg_match('/var BODY_MAX = (\d+);/u', $js, $a), 'предел объявлен в скрипте');
    assert_true((bool)preg_match('/const CHAT_BODY_MAX\s+= (\d+);/u', $lib, $b), 'и на сервере');
    assert_eq($b[1], $a[1], 'пределы совпадают');
    assert_true(strpos($lib, 'mb_strlen($body) > CHAT_BODY_MAX') !== false,
        'сервер тоже считает символы, а не байты');
});

// Вся геометрия панели на десктопе построена на --u (единице макета). На 375px
// та даёт 0.26px: без своих метрик панель схлопнулась бы до 89px с
// десятипиксельными смайликами. Ровно это и случилось при первой проверке.
test('на узких экранах у панели свои метрики, а не макетная единица', function () use ($PUB) {
    $css = cp_read($PUB . '/css/chat.css');
    $at  = strpos($css, '@media (max-width: 900px)');
    assert_true($at !== false, 'медиазапрос найден');
    $mobile = substr($css, $at);

    foreach (['.ct-emoji-btn', '.ct-emoji {', '.ct-emoji-item'] as $sel) {
        assert_true(strpos($mobile, $sel) !== false, "$sel переопределён для узких экранов");
    }
    // Зона нажатия: 40px против 24 по WCAG 2.5.8 — пальцем в сетку из ста
    // кнопок иначе не попасть.
    assert_true(strpos($mobile, '.ct-emoji-item { width: auto; height: 40px;') !== false,
        'у смайлика на телефоне палец-совместимая высота');
    assert_true(strpos($mobile, 'max-height: 46vh') !== false,
        'панель не занимает весь экран и оставляет видимой переписку');
});

// --------------------------------------------------------------------------
//  Чат обновляется сам
// --------------------------------------------------------------------------

// Без этого раздел не был чатом: страница читала переписку ОДИН раз, и ответ
// собеседника не появлялся, пока человек не нажмёт F5. Проверить это глазами
// нельзя — пустой экран выглядит как «он просто не ответил».
test('переписка перечитывается сама, пока вкладка открыта', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');

    assert_true((bool)preg_match('/var POLL_MS = (\d+);/u', $js, $m), 'период опроса объявлен');
    assert_true((int)$m[1] >= 5000, 'не чаще раза в пять секунд: это общий хостинг');
    assert_true(strpos($js, 'pollTimer = setTimeout(pollNow, POLL_MS);') !== false, 'опрос повторяется');
    assert_true(strpos($js, 'load(state.thread, true)') !== false, 'перечитывает открытый диалог');

    // Скрытая вкладка не должна долбить сервер. И обязана догнать пропущенное,
    // как только на неё вернулись.
    assert_true(strpos($js, "if (document.hidden || !state.authed) { return; }") !== false,
        'в скрытой вкладке опрос стоит');
    assert_true(strpos($js, "document.addEventListener('visibilitychange'") !== false,
        'возврат на вкладку отслеживается');
    assert_true(strpos($js, 'if (document.hidden) { clearTimeout(pollTimer); return; }') !== false,
        'уход со вкладки гасит таймер');
});

// Тихая перечитка обязана быть ТИХОЙ: она случается каждые несколько секунд, и
// любой её побочный эффект человек поймает пальцами — посреди набора текста.
test('фоновая перечитка ничего не сбрасывает', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');

    assert_true(strpos($js, 'var mine = quiet ? seq : ++seq;') !== false,
        'фоновая перечитка уступает нажатию человека, а не наоборот');
    assert_true(strpos($js, "if (!quiet) { shell.classList.add('is-loading'); }") !== false,
        'без мигания загрузки');
    assert_true(strpos($js, 'if (quiet && sig === lastSig) { return; }') !== false,
        'ничего не перерисовывается, пока ничего не изменилось');
    // Проверяем именно блок отзыва: «if (!quiet) {» в файле не одно.
    assert_true(strpos($js, "if (!quiet) {\n        var kept = reviewDrafts[state.thread];") !== false,
        'форму отзыва трогает только «громкая» перечитка');
    assert_true(strpos($js, "setStars(kept ? kept.stars : (d.review ? d.review.stars : 0));") !== false,
        'и даже она уступает начатому отзыву');
    assert_true(strpos($js, 'if (mine !== seq || quiet) { return; }') !== false,
        'сетевая осечка в фоне не сносит страницу');

    // Человек отлистал историю вверх — перечитка не должна дёргать его вниз.
    assert_true(strpos($js, 'log.scrollTop = (keepScroll && !atBottom) ? keepAt : log.scrollHeight;') !== false,
        'прокрутка сохраняется, если человек читает старое');
});

// Черновик принадлежит ДИАЛОГУ, а не полю ввода. Общий на всех он означал бы,
// что начатое одному человеку сообщение уезжает другому — молча, одним
// нажатием «Отправить».
test('черновик остаётся в своём диалоге', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($js, 'var drafts = {};') !== false, 'черновики хранятся по диалогам');
    assert_true(strpos($js, 'drafts[state.thread] = input.value;') !== false,
        'при уходе из диалога черновик запоминается');
    assert_true(strpos($js, "input.value = drafts[state.thread] || '';") !== false,
        'при возврате — восстанавливается');
    assert_true(strpos($js, "drafts[state.thread] = '';") !== false,
        'после отправки черновик пуст');
    assert_true(strpos($js, 'drafts[state.thread] = text;') !== false,
        'а если не отправилось — текст возвращается на место');
});

// Вход в переписку с конкретным человеком: /chat?to=<roblox_id>. Отсюда чат
// становится чатом — до этого диалог нельзя было начать вообще.
test('/chat?to= открывает переписку с человеком', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');

    assert_true((bool)preg_match("~/\[\?&\]to=~", $js) || strpos($js, "to=(\\d{1,20})") !== false,
        'номер собеседника читается из адреса');
    assert_true(strpos($js, "post('/api/chat_open.php', { to: peer })") !== false,
        'ветка заводится POST-ом, а не GET-ом с параметром');

    // Параметр из адреса убирается: иначе перезагрузка и «назад» повторяли бы
    // открытие, а адрес в строке врал бы про то, что сейчас открыто.
    assert_true(strpos($js, "history.replaceState(null, '', location.pathname)") !== false,
        'параметр не остаётся в адресной строке');

    // Не открылось — страница всё равно работает, просто со своим списком.
    assert_true(strpos($js, 'load(0).then(pollLater, pollLater);') !== false,
        'осечка не оставляет страницу пустой');

    $api = cp_read($PUB . '/api/chat_open.php');
    assert_true(strpos($api, 'require_post();') !== false, 'ручка только POST');
    assert_true(strpos($api, "rate_limit_allow('chat_open'") !== false, 'и с ограничением частоты');
    assert_true(strpos($api, "header('Cache-Control: no-store');") !== false, 'ответ не кешируется');
});

// Список диалогов объявлен как role="tablist" — значит и вести себя обязан
// как он: по вкладкам ходят стрелками, а Tab перескакивает список целиком.
// Иначе у человека с полусотней переписок до поля ввода полсотни нажатий.
test('по списку диалогов ходят стрелками', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($js, "b.tabIndex = t.id === state.thread ? 0 : -1;") !== false,
        'в обходе по Tab остаётся только открытый диалог');
    assert_true(strpos($js, "tabs.forEach(function (t, i) { t.tabIndex = i === next ? 0 : -1; });") !== false,
        'и метка переезжает вслед за фокусом');
    foreach (['ArrowDown', 'ArrowUp', 'Home', 'End'] as $key) {
        assert_true(strpos($js, "'" . $key . "'") !== false, "стрелка $key обслуживается");
    }
    // Вкладка обязана называть панель, которой управляет.
    assert_true(strpos($js, "b.setAttribute('aria-controls', 'ctRoom');") !== false,
        'вкладка связана с панелью переписки');
    assert_true(strpos(cp_markup(cp_read($PUB . '/chat.php')), 'id="ctRoom"') !== false,
        'и панель с таким id существует');
});

// Аватар приезжает с CDN Roblox. Без no-referrer каждый показ списка диалогов
// сообщает чужому домену адрес нашей страницы.
test('аватары в списке не сообщают Roblox, откуда их грузят', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    $at = strpos($js, "img.src = t.peer.avatar;");
    assert_true($at !== false, 'аватар подставляется');
    assert_true(strpos(substr($js, max(0, $at - 120), 160), "referrerPolicy = 'no-referrer'") !== false,
        'и перед этим выставлен no-referrer');
});

// Половину текстов чата пишет скрипт: пустые состояния, приглашение войти,
// заголовок комнаты. Общий проход по [data-i18n] до них не достаёт — у этих
// узлов ключа в разметке нет. Без отдельной перерисовки переключатель языка
// менял бы только половину страницы.
test('смена языка перерисовывает и то, что пишет скрипт', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    $at = strpos($js, "document.querySelectorAll('#langSwitch [data-lang]')");
    assert_true($at !== false, 'общий проход по языку найден');
    $after = substr($js, $at, 1200);

    assert_true(strpos($after, 'renderList();') !== false, 'список диалогов перерисовывается');
    assert_true(strpos($after, 'renderRoom(state.messages,') !== false,
        'и переписка — вместе с заголовком и пустым состоянием');
    assert_true(strpos($after, "gate.textContent = tx('chat.login'") !== false,
        'приглашение войти переводится');
    assert_true(strpos($after, "pageEmpty.textContent = tx('chat.notReady'") !== false,
        'и «чаты появятся вместе с аккаунтами» тоже');

    // Заголовок комнаты строится одной функцией — иначе смена языка затёрла
    // бы ссылку на профиль собеседника обычным текстом.
    assert_eq(2, substr_count($js, 'roomTitle.textContent'),
        'заголовок пишется только внутри roomTitleFor');
    assert_true(strpos($js, 'state.messages = d.messages || [];') !== false,
        'последняя переписка сохранена — иначе перерисовывать нечем');
});

// --------------------------------------------------------------------------
//  Что чат обязан пережить
// --------------------------------------------------------------------------

// Обрыв сети посреди отправки. fetch при этом не возвращает ответ, а БРОСАЕТ:
// без перехвата поле ввода оставалось выключенным навсегда, набранный текст
// исчезал, и на экране не появлялось ни слова о том, что случилось.
test('обрыв сети при отправке не запирает поле и не съедает текст', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');

    $at = strpos($js, 'async function post(url, payload)');
    assert_true($at !== false, 'отправщик найден');
    $body = substr($js, $at, 700);
    assert_true(strpos($body, 'try {') !== false, 'сетевая осечка перехвачена');
    assert_true(strpos($body, 'return { ok: false, status: 0, data: null };') !== false,
        'и превращается в обычный неуспех, а не в исключение');

    // Дальше по неуспеху отрабатывает общая ветка: текст возвращается в поле,
    // человеку пишут, что не отправилось.
    assert_true(strpos($js, 'input.disabled = false;') !== false, 'поле разблокируется');
    assert_true(strpos($js, "input.value = text;") !== false, 'текст возвращается');
    assert_true(strpos($js, "tx('chat.sendFailed'") !== false, 'и человеку об этом говорят');
});

// Отзыв — такой же черновик, как сообщение, и терять его нельзя: человек
// ставит оценку, дописывает реплику в чат, отправляет — и оценка откатывалась
// к сохранённой на сервере, молча и незаметно (фокус в это время в поле ввода).
test('начатый отзыв переживает отправку сообщения и смену диалога', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    assert_true(strpos($js, 'var reviewDrafts = {};') !== false, 'черновики отзывов хранятся');
    assert_true(strpos($js, 'reviewDrafts[state.thread] = { stars: n, body: reviewTxt.value };') !== false,
        'начатый отзыв запоминается');
    assert_true(strpos($js, 'delete reviewDrafts[state.thread];') !== false,
        'а сохранённый — забывается, иначе правка из другой вкладки никогда не подтянется');
    assert_true(strpos($js, 'function keepDrafts()') !== false, 'черновики снимаются одним местом');

    // Отправка сообщения перечитывает страницу ТИХО: иначе она же и затирала
    // бы всё, что человек набрал, пока летел ответ.
    assert_true(strpos($js, "      keepDrafts();\n      load(state.thread, true);") !== false,
        'после отправки перечитка тихая');
});

// На телефоне список диалогов выезжает поверх переписки. Закрыть его было
// нечем: он накрывал собственную кнопку, и человек оставался в нём заперт.
test('список диалогов на телефоне закрывается', function () use ($PUB) {
    $js  = cp_read($PUB . '/js/chat-page.js');
    $css = cp_read($PUB . '/css/chat.css');

    assert_true(strpos($js, 'function railOpen(next)') !== false, 'открытие и закрытие в одном месте');
    assert_true(strpos($js, "list.addEventListener('click', function () { railOpen(false); });") !== false,
        'выбор диалога закрывает список');
    assert_true(strpos($js, 'if (rail.contains(e.target) || railToggle.contains(e.target)) { return; }') !== false,
        'клик мимо списка закрывает его');
    assert_true(strpos($js, "if (e.key === 'Escape' && shell.classList.contains('rail-open'))") !== false,
        'и Escape тоже');

    // И главное: панель больше не лежит поверх своей кнопки.
    $at = strpos($css, '@media (max-width: 900px)');
    assert_true($at !== false, 'медиазапрос найден');
    assert_true(strpos(substr($css, $at), 'inset: 58px auto 10px 10px;') !== false,
        'список начинается ниже шапки комнаты, где стоит кнопка');
});

// Шапка /chat собрана по образцу calculator.php — вместе с пометкой текущей
// страницы, которая осталась на «Калькуляторе». Скринридер на /chat объявлял
// бы текущим разделом калькулятор.
test('текущей страницей в шапке помечен чат, и только он', function () use ($PUB) {
    $s = cp_markup(cp_read($PUB . '/chat.php'));
    $head = substr($s, strpos($s, '<header class="mk-top">'));
    $head = substr($head, 0, strpos($head, '</header>'));

    assert_eq(1, substr_count($head, 'aria-current="page"'), 'пометка ровно одна');
    assert_true((bool)preg_match('/<a class="mk-chat" href="\/chat"[^>]*aria-current="page"/u', $head),
        'и стоит она на чате');
    assert_eq(0, preg_match('/href="\/calculator" aria-current/u', $head),
        'на калькуляторе её нет');
});

// Лента объявлена как role="log" aria-live="polite": всё, что в неё попадает,
// скринридер читает вслух. Полная пересборка на каждой фоновой перечитке —
// это весь диалог заново, каждые десять секунд. Дописываем только новое.
test('фоновая перечитка дописывает сообщения, а не пересобирает ленту', function () use ($PUB) {
    $js = cp_read($PUB . '/js/chat-page.js');
    $s  = cp_markup(cp_read($PUB . '/chat.php'));

    assert_true(strpos($s, 'role="log"') !== false, 'лента объявлена как журнал');
    assert_true(strpos($s, 'aria-live="polite"') !== false, 'и читается вслух');

    assert_true(strpos($js, 'function sameHead(messages)') !== false,
        'уже показанное сверяется с пришедшим');
    // И сверка действительно применяется: объявить её и не позвать — ровно та
    // правка, после которой лента снова пересобиралась бы целиком.
    assert_true(strpos($js, 'var from = keepScroll && peer ? sameHead(messages) : -1;') !== false,
        'сверка применяется при тихой перерисовке');
    assert_true(strpos($js, 'if (from >= 0) {') !== false, 'и решает, дописывать или пересобирать');
    assert_true(strpos($js, "if (shown[i].dataset.id !== String(messages[i].id)) { return -1; }") !== false,
        'сверка по настоящим номерам сообщений, а не по количеству');
    assert_true(strpos($js, 'li.dataset.id = String(m.id);') !== false, 'номер есть на каждом пузыре');
    assert_true(strpos($js, 'for (var k = from; k < messages.length; k++) { log.appendChild(bubble(messages[k])); }') !== false,
        'дописывается только хвост');

    // Заголовок комнаты при этом пересобирается всегда: он зависит от языка.
    $at = strpos($js, 'function renderRoom(');
    assert_true($at !== false && strpos(substr($js, $at, 400), 'roomTitleFor(peer);') !== false,
        'заголовок обновляется до ветки с дописыванием');
});

run_tests();
