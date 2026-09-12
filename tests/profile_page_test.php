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
//  Настоящий рендер страницы
// --------------------------------------------------------------------------
//
// Разметку профиля теперь собирает СЕРВЕР: он же решает, показать карточку,
// предложение войти или «профиля нет». Проверять это чтением исходника
// бессмысленно — там ветвление, а не готовый ответ. Поэтому страница
// исполняется по-настоящему, в отдельном процессе, с подставленной сессией,
// параметром ?id= и своей базой на SQLite.
//
// Отдельным процессом по тем же причинам, что в tests/metrika_test.php:
// require исполняет страницу один раз на процесс, а её ветка «профиля нет»
// меняет код ответа, который надо прочитать целиком.
//
// В коде дочернего php нет ни одной ДВОЙНОЙ кавычки: escapeshellarg() на
// Windows оборачивает аргумент в двойные кавычки, а встреченные внутри
// заменяет пробелами.

const PF_MARK = '___NX_PROFILE_RENDER___';

function pf_fixture_db(string $file): void {
    @unlink($file);
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        roblox_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_login_at INTEGER NOT NULL,
        about TEXT NULL DEFAULT NULL,
        likes INTEGER NOT NULL DEFAULT 0,
        dislikes INTEGER NOT NULL DEFAULT 0
    )");
    $ins = $pdo->prepare('INSERT INTO users
        (roblox_id, username, display_name, avatar_url, created_at, last_login_at, about, likes, dislikes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $now = time();
    // Свой: онлайн, с аватаром, с текстом о себе и с репутацией.
    $ins->execute(['900000001', 'mksvtn', 'MKSVTN', 'https://tr.rbxcdn.com/a.png',
                   $now - 86400, $now, 'Меняю фрукты по тирлисту', 3, 1]);
    // Чужой: офлайн, без аватара и без текста о себе.
    $ins->execute(['900000004', 'thefool', 'The Fool', '', $now - 86400, $now - 100000, null, 0, 0]);
    // Ник с разметкой: она обязана уехать на страницу экранированной.
    $ins->execute(['900000009', 'xss', '<script>alert(1)</script>', '', $now - 86400, $now,
                   'a <b>bold</b> claim', 0, 0]);
    // Без имени вовсе: у Roblox не обязательны ни display_name, ни username.
    $ins->execute(['900000011', '', '', '', $now - 86400, $now, null, 0, 0]);
}

function pf_render(string $me, string $id): ?array {
    if (!function_exists('shell_exec')) { return null; }
    $php  = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    // Имя уникальное на процесс и на вызов: два прогона набора рядом (или
    // прогон рядом с чьей-то копией репозитория) иначе затирали бы друг другу
    // базу на полпути, и падение выглядело бы как плавающий дефект страницы.
    $tmp  = sys_get_temp_dir();
    $tag  = getmypid() . '_' . uniqid();
    $dbf  = $tmp . '/nx_profile_' . $tag . '.sqlite';
    $cfgf = $tmp . '/nx_profile_' . $tag . '_config.php';

    pf_fixture_db($dbf);
    file_put_contents($cfgf, "<?php return ['dsn' => 'sqlite:" . $dbf . "', 'db_user' => '', "
        . "'db_pass' => '', 'admin_hash' => '', 'images_dir' => '', 'deploy_secret' => '', "
        . "'deploy_repo' => '', 'deploy_path' => '', 'deploy_branch' => ''];\n");

    // Маркер печатается ПОСЛЕ require, вместе с кодом ответа: ушла страница в
    // exit или в фатал — маркера не будет, и мы это увидим, а не примем
    // обрезанный вывод за разметку.
    $code = 'define(' . var_export('CONFIG_PATH', true) . ', $argv[2]);'
          . ' session_start(); $_SESSION[' . var_export('user_id', true) . '] = $argv[3];'
          . ' $_GET[' . var_export('id', true) . '] = $argv[4];'
          . ' require $argv[1];'
          . ' echo ' . var_export(PF_MARK, true) . ' , http_response_code();';

    $devnull = DIRECTORY_SEPARATOR === '/' ? '2>/dev/null' : '2>nul';
    $cmd = escapeshellarg($php) . ' -r ' . escapeshellarg($code)
         . ' ' . escapeshellarg(dirname(__DIR__) . '/public_html/profile.php')
         . ' ' . escapeshellarg($cfgf)
         . ' ' . escapeshellarg($me)
         . ' ' . escapeshellarg($id)
         . ' ' . $devnull;

    $out = shell_exec($cmd);
    @unlink($dbf); @unlink($cfgf);
    if ($out === null || $out === false) { return null; }
    $pos = strpos($out, PF_MARK);
    if ($pos === false) { return null; }
    return [
        'html'   => substr($out, 0, $pos),
        'status' => (int)(substr($out, $pos + strlen(PF_MARK)) ?: 200),
    ];
}

test('аноним видит предложение войти и ни строчки карточки', function () {
    $r = pf_render('', '');
    assert_true($r !== null, 'страница отрендерилась до конца');
    if ($r === null) { return; }
    assert_eq(200, $r['status'], 'это не ошибка — просто не вошли');
    assert_true(strpos($r['html'], 'data-i18n="profile.login"') !== false, 'гейт показан');
    assert_eq(0, substr_count($r['html'], 'hidden>Войдите'), 'и не спрятан');
    assert_eq(0, substr_count($r['html'], 'class="pf-card"'), 'карточки в разметке нет вовсе');
    assert_eq(0, substr_count($r['html'], 'pfLogout'), 'операций с аккаунтом тоже');
});

test('свой профиль: карточка, меню аккаунта и правка «о себе»', function () {
    $r = pf_render('900000001', '');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    $h = $r['html'];

    assert_eq(200, $r['status'], 'ответ 200');
    assert_true(strpos($h, '<h1 class="pf-nick" id="pfNick">MKSVTN</h1>') !== false, 'ник напечатан сервером');
    assert_true(strpos($h, '>@mksvtn</p>') !== false, 'хендл на месте');
    assert_true(strpos($h, '<title>MKSVTN — профиль игрока') !== false, 'ник попал в заголовок вкладки');
    assert_true(strpos($h, 'src="https://tr.rbxcdn.com/a.png"') !== false, 'аватар подставлен');
    assert_true(strpos($h, 'class="pf-avatar has-photo"') !== false, 'и заглушка-силуэт погашена');
    assert_true(strpos($h, 'data-state="online"') !== false, 'статус посчитан');
    assert_true(strpos($h, '<b id="pfLikes">3</b>') !== false, 'репутация напечатана');
    assert_true(strpos($h, '<b id="pfDislikes">1</b>') !== false, 'обе половины');
    assert_true(strpos($h, 'id="pfLogout"') !== false, 'выход только на своём профиле');
    assert_true(strpos($h, 'id="pfAboutInput"') !== false, '«о себе» правится');
    assert_true(strpos($h, '>Меняю фрукты по тирлисту</textarea>') !== false, 'и приезжает заполненным');
    assert_true(strpos($h, 'canonical" href="https://maknemy.com/profile"') !== false,
        'канонический адрес своего профиля — без ?id=');

    // Ни одной подписи-заглушки: карточка приезжает готовой, а не
    // достраивается скриптом.
    assert_eq(0, substr_count($h, 'data-i18n="profile.nick"'), 'заглушки ника нет');
    assert_eq(0, substr_count($h, 'data-i18n="profile.handle"'), 'заглушки хендла нет');
});

test('чужой профиль: те же данные, но без операций с аккаунтом', function () {
    $r = pf_render('900000001', '900000004');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    $h = $r['html'];

    assert_eq(200, $r['status'], 'ответ 200');
    assert_true(strpos($h, '<h1 class="pf-nick" id="pfNick">The Fool</h1>') !== false, 'ник соседа');
    assert_true(strpos($h, '<title>The Fool — профиль игрока') !== false, 'его же в заголовке');
    assert_true(strpos($h, 'data-state="offline"') !== false, 'его статус, а не мой');
    assert_true(strpos($h, 'canonical" href="https://maknemy.com/profile?id=900000004"') !== false,
        'канонический адрес несёт ?id=');

    // Всё, что относится к аккаунту, на чужом профиле ОТСУТСТВУЕТ в разметке,
    // а не спрятано стилями: спрятанное видно в исходнике и возвращается
    // одним снятым атрибутом.
    foreach (['pfLogout', 'pfSwitch', 'pfMenuToggle', 'pf-menu-list',
              'profile.menuDelete', 'pfAboutInput', 'pfAboutSave'] as $mark) {
        assert_eq(0, substr_count($h, $mark), "на чужом профиле нет: $mark");
    }
    assert_true(strpos($h, 'data-i18n="profile.aboutNone"') !== false,
        'пустое «о себе» соседа — своё состояние, а не подсказка «опишите себя»');
});

// График спрашивает статистику ТОГО ЖЕ человека, чью карточку напечатал
// сервер. Раньше скрипт вытаскивал id из адреса сам — и расходился с сервером
// на ?id=1&id=2 (PHP берёт последний, регулярка первый) и на любой другой
// записи, которую эти два разборщика читают по-разному. Теперь адресат один
// и приезжает из разметки.
test('график берёт адресата из разметки, а не из адресной строки', function () use ($PUB) {
    $js = pf_read($PUB . '/js/profile-chart.js');
    assert_true(strpos($js, 'const WHO  = root.dataset.profile') !== false,
        'id цели читается из data-атрибута');
    assert_eq(0, preg_match('~location\.search~', $js), 'адресную строку скрипт не разбирает');

    // Прочитать id мало — он обязан уехать В ЗАПРОС. Без этого под чужой
    // карточкой рисовалась бы своя статистика: карточку печатает сервер, и
    // подмены не видно ни по одному признаку на экране.
    assert_true(strpos($js, "q.push('id=' + encodeURIComponent(WHO))") !== false,
        'адресат попадает в адрес запроса');
    $at = strpos($js, "const url = '/api/profile-stats.php'");
    assert_true($at !== false, 'сборка адреса найдена');
    assert_true(strpos(substr($js, max(0, $at - 260), 320), 'WHO') !== false,
        'и попадает именно в ту сборку, которая идёт в fetch');

    $mine = pf_render('900000001', '');
    $peer = pf_render('900000001', '900000004');
    assert_true($mine !== null && $peer !== null, 'обе страницы отрендерились');
    if ($mine === null || $peer === null) { return; }
    assert_true(strpos($mine['html'], 'data-profile="900000001"') !== false, 'свой id в разметке');
    assert_eq(0, substr_count($mine['html'], 'data-peer'), 'и метки «чужой» на своём нет');
    assert_true(strpos($peer['html'], 'data-profile="900000004"') !== false, 'чужой id в разметке');
    assert_true(strpos($peer['html'], 'data-peer="1"') !== false, 'и метка «чужой» стоит');
});

test('свой профиль по своему же ?id= остаётся своим', function () {
    $r = pf_render('900000001', '900000001');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    assert_true(strpos($r['html'], 'id="pfLogout"') !== false, 'меню аккаунта на месте');
    assert_true(strpos($r['html'], 'id="pfAboutInput"') !== false, '«о себе» правится');
});

// Сессия переживает удаление аккаунта. Свой профиль такому зрителю уже не
// открывается — и чужой не должен: карточка чужого от личности смотрящего не
// зависит, и без проверки зрителя удалённый пользователь сохранял бы доступ
// ко всем профилям сайта.
test('сессия удалённого пользователя не открывает и чужие профили', function () {
    foreach (['', '900000004'] as $id) {
        $r = pf_render('900009999', $id);
        assert_true($r !== null, 'страница отрендерилась');
        if ($r === null) { continue; }
        assert_eq(200, $r['status'], 'это не 404: профиль-то есть, зрителя нет');
        assert_true(strpos($r['html'], 'data-i18n="profile.login"') !== false,
            'показано предложение войти, ?id=' . $id);
        assert_eq(0, substr_count($r['html'], 'class="pf-card"'), 'карточки нет, ?id=' . $id);
    }
});

// Ни display_name, ни username у Roblox не обязательны. Пустой <h1> оставил
// бы карточку без доступного имени — заголовок, который нечем прочитать.
test('профиль без имени показывает номер, а не пустой заголовок', function () {
    $r = pf_render('900000001', '900000011');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    assert_true(strpos($r['html'], '<h1 class="pf-nick" id="pfNick">#900000011</h1>') !== false,
        'вместо пустоты — номер');
    assert_eq(0, substr_count($r['html'], '<h1 class="pf-nick" id="pfNick"></h1>'), 'пустого заголовка нет');
});

// Подписи вокруг карточки написаны от первого лица. На чужом профиле «О себе»
// и «чем больше сделок — тем выше опыт» звучат как обращение не к тому.
test('подписи на чужом профиле не обращаются к его владельцу', function () use ($PUB) {
    $mine = pf_render('900000001', '');
    $peer = pf_render('900000001', '900000004');
    assert_true($mine !== null && $peer !== null, 'обе страницы отрендерились');
    if ($mine === null || $peer === null) { return; }

    assert_true(strpos($mine['html'], 'data-i18n="profile.aboutTitle"') !== false, 'своё — «О себе»');
    assert_true(strpos($peer['html'], 'data-i18n="profile.aboutTitlePeer"') !== false, 'чужое — «Об игроке»');
    assert_true(strpos($mine['html'], 'data-i18n="profile.statsNote"') !== false, 'своя подпись под счётчиками');
    assert_true(strpos($peer['html'], 'data-i18n="profile.statsNotePeer"') !== false, 'и чужая');

    $i18n = pf_read($PUB . '/js/i18n.js');
    foreach (['profile.aboutTitlePeer', 'profile.statsNotePeer'] as $k) { pf_assert_key($i18n, $k); }
});

// «О себе» пишет человек, и одно длинное слово (ссылка, набор символов) без
// переноса уносит вбок всю страницу — у КАЖДОГО, кто её открыл.
test('длинное слово в «о себе» переносится, а не ломает раскладку', function () use ($PUB) {
    $css = pf_read($PUB . '/css/profile.css');
    foreach (['.pf-about-text', '.pf-about-input'] as $sel) {
        $at = strpos($css, $sel . ' {');
        assert_true($at !== false, "правило $sel найдено");
        $block = substr($css, $at, strpos($css, '}', $at) - $at);
        assert_true(strpos($block, 'overflow-wrap: anywhere') !== false, "$sel переносит длинное слово");
    }
});

test('несуществующий профиль отвечает настоящим 404', function () {
    $r = pf_render('900000001', '900009999');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    assert_eq(404, $r['status'], 'не 200 на пустой странице');
    assert_true(strpos($r['html'], 'data-i18n="profile.missing"') !== false, 'и объяснение показано');
    assert_eq(0, substr_count($r['html'], 'class="pf-card"'), 'карточки нет');
});

// Ник и «о себе» пишет человек. Всё, что он написал, обязано уехать на
// страницу текстом, а не разметкой.
test('ник и «о себе» экранируются', function () {
    $r = pf_render('900000001', '900000009');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    $h = $r['html'];
    assert_eq(0, substr_count($h, '<script>alert(1)</script>'), 'разметка из ника не исполнится');
    assert_true(strpos($h, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'она экранирована');
    assert_eq(0, substr_count($h, 'a <b>bold</b> claim'), 'и из «о себе» тоже');
    assert_true(strpos($h, 'a &lt;b&gt;bold&lt;/b&gt; claim') !== false, 'экранирована');
});

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

// Канонический адрес зависит от того, чей профиль открыт, — значит и
// проверяется он на отрендеренной странице (см. тесты рендера выше). Здесь
// остаётся то, что от данных не зависит.
test('профиль объявляет себя на /profile', function () use ($PUB) {
    $s = pf_read($PUB . '/profile.php');
    assert_true(strpos($s, "'https://maknemy.com/profile'") !== false,
        'канонический адрес своего профиля');
    assert_true(strpos($s, "'https://maknemy.com/profile?id=' . \$pfWho") !== false,
        'и чужого — с ?id=');
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
// aria-current помечает пункт, ведущий на ТЕКУЩУЮ страницу. На /profile?id=
// открыт чужой профиль, а пункт ведёт на свой — метка там врала бы о
// местоположении, хотя pathname у обеих страниц один.
test('пункт профиля помечен aria-current только на СВОЁМ профиле', function () use ($PUB) {
    $js = pf_read($PUB . '/js/topbar.js');
    assert_true(strpos($js, 'location.pathname === PROFILE_PATH && !/[?&]id=/.test(location.search)') !== false,
        'чужой профиль под метку не попадает');
    assert_true(strpos($js, 'if (onMine) mine.setAttribute("aria-current", "page");') !== false,
        'и метка ставится по этому условию');
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
    // Значение может быть подставлено PHP — например ключ статуса, который
    // зависит от данных. Такие проверяются там, где решается их набор
    // (см. тест про статус ниже), а сюда попадает не имя ключа, а кусок кода.
    $keys = array_values(array_unique(array_filter($m[1], function ($k) {
        return strpos($k, '<?') === false;
    })));
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
    assert_true(strpos($s, "\$pfMe   = profile_me(\$_SESSION);") !== false,
        'и спрашивает у неё, кто пришёл');
    assert_true(strpos($s, "\$pfId   = profile_target(\$_GET);") !== false,
        'а у адреса — чей профиль открыт');

    // Три состояния взаимоисключающие, и решает их сервер: карточки
    // невошедшего в разметке нет вовсе, а не спрятана атрибутом.
    assert_true(strpos($s, "if (\$pfState === 'missing') { http_response_code(404); }") !== false,
        'пропавший профиль отвечает 404');
    assert_true(strpos($s, "<?php if (\$pfState === 'card'): ?>") !== false,
        'карточка печатается только при наличии данных');

    // Сессия может отвалиться между отдачей страницы и запросом данных —
    // тогда переключить обязан скрипт. Проверяем НАПРАВЛЕНИЕ, а не наличие
    // функции: перепутанные местами gate и card дали бы вошедшему гейт, а
    // анониму — пустую карточку, и «функция renderAuth есть» это пропустит.
    assert_true(strpos($js, 'if (gate) { gate.hidden = !!authed; }') !== false,
        'гейт прячется, когда вошли');
    assert_true(strpos($js, 'if (card) { card.hidden = !authed; }') !== false,
        'карточка прячется, когда не вошли');
    assert_true(strpos($js, "renderAuth(!!(e.detail && e.detail.authed));") !== false,
        'по ответу API');

    // Текст «о себе» приезжает в разметке, а не заливается скриптом: заливка
    // на каждом ответе стирала бы набранное, а до ответа поле было бы пустым.
    assert_true(strpos($js, "var savedAbout = aboutInput ? aboutInput.value : '';") !== false,
        'исходное значение берётся из разметки');
    assert_eq(0, substr_count($js, 'loadAbout'),
        'заливки поля по приходу данных больше нет вовсе');
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
// Карточку печатает сервер, скрипт её не трогает. Точки привязки всё равно
// нужны — по ним ходят и стили, и график, — но проверять их надо в
// отрендеренной странице, а не в шаблоне.
test('поля карточки размечены точками привязки', function () {
    $r = pf_render('900000001', '');
    assert_true($r !== null, 'страница отрендерилась');
    if ($r === null) { return; }
    foreach (['pfNick', 'pfHandle', 'pfAvatar', 'pfStatus', 'pfLikes', 'pfDislikes'] as $id) {
        assert_true(strpos($r['html'], 'id="' . $id . '"') !== false, "$id размечен");
    }
});

// Прежде карточку заполнял js/profile-page.js: страница показывала подписи
// «Игровой ник» и «@никнейм», а настоящие значения подставлялись ответом
// эндпоинта. Пути назад к этому быть не должно — два источника на одно поле
// разъезжаются, а на экране мелькают заглушки.
test('скрипт больше не собирает карточку', function () use ($PUB) {
    $js = pf_read($PUB . '/js/profile-page.js');
    foreach (['renderCard', 'renderAvatar', 'renderStatus', 'STATUS_KEYS', 'lastCard'] as $gone) {
        assert_eq(0, substr_count($js, $gone), "в скрипте не осталось $gone");
    }
    assert_true(strpos($js, 'mk:profiledata') !== false, 'событие он всё ещё слушает');
    // Единственное, что осталось от карточки в скрипте, — переключение на
    // гейт: по ответу эндпоинта и при 401 на сохранении «о себе».
    assert_true(strpos($js, 'function renderAuth(authed)') !== false, 'функция на месте');
    assert_eq(3, substr_count($js, 'renderAuth'), 'объявление и два вызова, больше карточке нечего делать');
});

// Аватара может не быть: у Roblox он не обязателен, а битый URL мы и сами
// отбрасываем. Тогда в круге остаётся силуэт из макета — и никакого пустого
// <img>, который нарисовал бы значок сломанного файла.
test('без аватара остаётся силуэт, с аватаром он гаснет', function () {
    $with = pf_render('900000001', '');
    $without = pf_render('900000001', '900000004');
    assert_true($with !== null && $without !== null, 'обе страницы отрендерились');
    if ($with === null || $without === null) { return; }

    assert_true(strpos($with['html'], 'class="pf-avatar has-photo"') !== false, 'с картинкой — класс есть');
    assert_true(strpos($with['html'], '<img id="pfAvatar"') !== false, 'и сам <img>');
    assert_true(strpos($with['html'], 'referrerpolicy="no-referrer"') !== false,
        'адрес нашей страницы не уходит на CDN Roblox');

    assert_eq(0, substr_count($without['html'], '<img id="pfAvatar"'), 'без картинки <img> не печатается');
    assert_true(strpos($without['html'], 'class="pf-avatar"') !== false, 'класса has-photo нет');
    assert_true(strpos($without['html'], 'pf-avatar-empty') !== false, 'силуэт на месте');
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

// Профиль показывает репутацию и «о себе» затем, чтобы понять, иметь ли с
// человеком дело. Следующий шаг — написать ему, и путь туда должен быть на
// той же странице, а не в памяти о том, что у сайта есть /chat.
test('с чужого профиля можно написать человеку', function () use ($PUB) {
    $page = pf_read($PUB . '/profile.php');

    assert_true(strpos($page, "\$pfChat = is_file(__DIR__ . '/chat.php');") !== false,
        'наличие чата проверяется, а не задаётся флагом руками');
    assert_true(strpos($page, '<?php if (!$pfSelf && $pfChat): ?>') !== false,
        'кнопка только на чужом профиле и только когда чат есть');
    assert_true(strpos($page, 'href="/chat?to=<?= htmlspecialchars($pfWho, ENT_QUOTES, \'UTF-8\') ?>"') !== false,
        'ведёт в переписку именно с этим человеком, и номер экранирован');
    pf_assert_key(pf_read($PUB . '/js/i18n.js'), 'profile.write');
    assert_true(strpos(pf_read($PUB . '/css/profile.css'), '.pf-write-link') !== false,
        'и у неё есть свой вид');

    // Свой профиль такой кнопки не несёт: писать самому себе некуда.
    $mine = pf_render('900000001', '');
    assert_true($mine !== null, 'своя страница отрендерилась');
    if ($mine === null) { return; }
    assert_eq(0, substr_count($mine['html'], 'pf-write'), 'на своём профиле кнопки нет');
});

run_tests();
