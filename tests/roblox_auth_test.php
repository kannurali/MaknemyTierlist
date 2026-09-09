<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/roblox_oauth.php';
require __DIR__ . '/../public_html/api/session.php';

// Вход через Roblox (OAuth 2.0 / OIDC). Сеть здесь не трогается: проверяются
// сборка запроса, разбор ответа и то, на чём вход обязан отказать.

$OAUTH = [
    'client_id'    => '3325951287074976031',
    'client_secret' => 'shh',
    'redirect_uri' => 'https://maknemy.com/api/roblox_callback.php',
];

// --------------------------------------------------------------------------
//  Выключенный вход
// --------------------------------------------------------------------------

test('без client_id вход выключен', function () {
    assert_eq([], roblox_oauth_config([]), 'пустой конфиг');
    assert_eq(false, roblox_oauth_enabled([]), 'выключен');
    assert_eq([], roblox_oauth_config([
        'roblox_client_id' => 'x', 'roblox_client_secret' => '',
        'roblox_redirect_uri' => 'https://maknemy.com/api/roblox_callback.php',
    ]), 'половина настроек — всё равно выключен');
});

test('полный конфиг включает вход', function () {
    $cfg = [
        'roblox_client_id'     => ' 123 ',
        'roblox_client_secret' => 'secret',
        'roblox_redirect_uri'  => 'https://maknemy.com/api/roblox_callback.php',
    ];
    assert_true(roblox_oauth_enabled($cfg), 'включён');
    assert_eq('123', roblox_oauth_config($cfg)['client_id'], 'пробелы обрезаны');
});

// --------------------------------------------------------------------------
//  PKCE
// --------------------------------------------------------------------------

// Контрольный пример из RFC 7636 (приложение B). Если challenge считается
// иначе — Roblox отвергнет обмен кода, и вход молча перестанет работать.
test('code_challenge совпадает с примером из RFC 7636', function () {
    assert_eq(
        'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        roblox_code_challenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'),
        'S256 от verifier'
    );
});

test('токены случайны и в base64url', function () {
    $a = roblox_random_token();
    $b = roblox_random_token();
    assert_true($a !== $b, 'два вызова не совпадают');
    assert_eq(1, preg_match('/^[A-Za-z0-9_-]+$/', $a), 'только безопасные символы');
    assert_true(strlen($a) >= 43, 'длина не ниже минимума PKCE');
});

// --------------------------------------------------------------------------
//  Адрес страницы согласия
// --------------------------------------------------------------------------

test('authorize-URL несёт все обязательные параметры', function () use ($OAUTH) {
    $url = roblox_authorize_url($OAUTH, 'st4te', 'verifier-verifier-verifier-verifier-verifier');
    $q = [];
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);

    assert_eq('https://apis.roblox.com/oauth/v1/authorize', strtok($url, '?'), 'эндпоинт');
    assert_eq('3325951287074976031', $q['client_id'], 'client_id');
    assert_eq('code', $q['response_type'], 'response_type');
    assert_eq('openid profile', $q['scope'], 'scope');
    assert_eq('st4te', $q['state'], 'state');
    assert_eq('S256', $q['code_challenge_method'], 'PKCE именно S256');
    assert_eq(
        roblox_code_challenge('verifier-verifier-verifier-verifier-verifier'),
        $q['code_challenge'], 'challenge, а не сам verifier'
    );
    // Сам verifier в адрес не попадает — иначе PKCE не защищал бы ни от чего.
    assert_eq(false, strpos($url, 'verifier-verifier'), 'verifier не утёк в URL');
    assert_eq($OAUTH['redirect_uri'], $q['redirect_uri'], 'redirect_uri');
});

// --------------------------------------------------------------------------
//  Возврат на сайт (open redirect)
// --------------------------------------------------------------------------

test('возврат разрешён только на внутренний путь', function () {
    assert_eq('/news', roblox_safe_return('/news'), 'обычный путь');
    assert_eq('/calculator?tab=2', roblox_safe_return('/calculator?tab=2'), 'путь с query');
    assert_eq('/', roblox_safe_return(null), 'ничего не передали');
    assert_eq('/', roblox_safe_return(''), 'пусто');
    assert_eq('/', roblox_safe_return('https://evil.example/x'), 'абсолютный адрес');
    assert_eq('/', roblox_safe_return('//evil.example/x'), 'protocol-relative');
    assert_eq('/', roblox_safe_return('/\\evil.example/x'), 'обратный слэш');
    assert_eq('/', roblox_safe_return("/ok\r\nLocation: https://evil.example"), 'перевод строки');
    assert_eq('/', roblox_safe_return('/' . str_repeat('a', 300)), 'слишком длинный');
});

test('метка результата дописывается к любому пути', function () {
    assert_eq('/news?login=ok', roblox_with_flag('/news', 'ok'), 'без query');
    assert_eq('/news?p=1&login=error', roblox_with_flag('/news?p=1', 'error'), 'с query');
});

// --------------------------------------------------------------------------
//  Аватар
// --------------------------------------------------------------------------

test('аватар принимается только с доменов Roblox по https', function () {
    assert_true(roblox_avatar_ok('https://tr.rbxcdn.com/abc/420/420/AvatarHeadshot/Png'), 'rbxcdn');
    assert_true(roblox_avatar_ok('https://www.roblox.com/headshot.png'), 'roblox.com');
    assert_eq(false, roblox_avatar_ok('http://tr.rbxcdn.com/a.png'), 'http запрещён');
    assert_eq(false, roblox_avatar_ok('https://evil.example/a.png'), 'чужой домен');
    // Хвост-обманка: домен заканчивается на evil.example, а не на rbxcdn.com.
    assert_eq(false, roblox_avatar_ok('https://rbxcdn.com.evil.example/a.png'), 'подстановка в начало');
    assert_eq(false, roblox_avatar_ok('https://notrbxcdn.com/a.png'), 'суффикс без точки');
    assert_eq(false, roblox_avatar_ok(''), 'пусто');
    assert_eq(false, roblox_avatar_ok('javascript:alert(1)'), 'не http-схема');
});

// --------------------------------------------------------------------------
//  Разбор userinfo
// --------------------------------------------------------------------------

test('профиль собирается из claims', function () {
    $p = roblox_profile_from_claims([
        'sub' => '1234567',
        'preferred_username' => 'maknemy',
        'nickname' => 'Maknemy',
        'picture' => 'https://tr.rbxcdn.com/x/150/150/AvatarHeadshot/Png',
    ]);
    assert_eq('1234567', $p['roblox_id'], 'id');
    assert_eq('maknemy', $p['username'], 'ник');
    assert_eq('Maknemy', $p['display_name'], 'display name');
    assert_eq('https://tr.rbxcdn.com/x/150/150/AvatarHeadshot/Png', $p['avatar_url'], 'аватар');
});

test('без nickname показываем ник', function () {
    $p = roblox_profile_from_claims(['sub' => '7', 'preferred_username' => 'solo']);
    assert_eq('solo', $p['display_name'], 'подставился username');
    assert_eq('', $p['avatar_url'], 'аватара нет');
});

test('чужой аватар отбрасывается, а не запоминается', function () {
    $p = roblox_profile_from_claims([
        'sub' => '7', 'preferred_username' => 'solo',
        'picture' => 'https://evil.example/track.gif',
    ]);
    assert_eq('', $p['avatar_url'], 'ссылка не сохранена');
});

test('негодный sub — не пускаем', function () {
    assert_eq(null, roblox_profile_from_claims([]), 'нет sub');
    assert_eq(null, roblox_profile_from_claims(['sub' => '']), 'пустой sub');
    // "12abc" при приведении к int стал бы юзером 12, то есть чужим аккаунтом.
    assert_eq(null, roblox_profile_from_claims(['sub' => '12abc']), 'мусор в sub');
    assert_eq(null, roblox_profile_from_claims(['sub' => 12]), 'sub не строка');
    assert_eq(null, roblox_profile_from_claims(['sub' => '-5']), 'отрицательный');
});

test('длинные ник и имя обрезаются под колонки', function () {
    $p = roblox_profile_from_claims([
        'sub' => '7',
        'preferred_username' => str_repeat('u', 200),
        'nickname' => str_repeat('d', 200),
    ]);
    assert_eq(64, mb_strlen($p['username']), 'ник по 64');
    assert_eq(64, mb_strlen($p['display_name']), 'имя по 64');
});

// --------------------------------------------------------------------------
//  База
// --------------------------------------------------------------------------

test('первый вход создаёт запись, повторный обновляет её', function () {
    $db = test_db();
    roblox_touch_user($db, [
        'roblox_id' => '42', 'username' => 'old', 'display_name' => 'Old',
        'avatar_url' => 'https://tr.rbxcdn.com/a.png',
    ], 1000);

    $u = roblox_load_user($db, '42');
    assert_eq('old', $u['name'], 'ник записан');
    assert_eq('https://www.roblox.com/users/42/profile', $u['profile'], 'ссылка на профиль');

    // Человек сменил ник и аватар в Roblox — шапка обязана показать новое.
    roblox_touch_user($db, [
        'roblox_id' => '42', 'username' => 'new', 'display_name' => 'New',
        'avatar_url' => 'https://tr.rbxcdn.com/b.png',
    ], 2000);

    $u = roblox_load_user($db, '42');
    assert_eq('new', $u['name'], 'ник обновлён');
    assert_eq('https://tr.rbxcdn.com/b.png', $u['avatar'], 'аватар обновлён');

    $row = $db->query("SELECT created_at, last_login_at FROM users WHERE roblox_id = 42")
              ->fetch(PDO::FETCH_ASSOC);
    assert_eq(1000, (int)$row['created_at'], 'дата регистрации не переписана');
    assert_eq(2000, (int)$row['last_login_at'], 'последний вход обновлён');
    assert_eq(1, (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(), 'дубля нет');
});

test('неизвестный id — не пользователь', function () {
    assert_eq(null, roblox_load_user(test_db(), '999'), 'записи нет');
});

// --------------------------------------------------------------------------
//  Ручка состояния
// --------------------------------------------------------------------------

$CFG_ON = [
    'roblox_client_id' => '1', 'roblox_client_secret' => '2',
    'roblox_redirect_uri' => 'https://maknemy.com/api/roblox_callback.php',
];

test('аноним: пользователя нет и база не открывается', function () use ($CFG_ON) {
    $opened = false;
    $s = handle_session(function () use (&$opened) { $opened = true; return test_db(); }, [], $CFG_ON);
    assert_eq(null, $s['user'], 'никто не вошёл');
    assert_eq(false, $s['admin'], 'и не админ');
    assert_true($s['roblox'], 'вход настроен');
    assert_eq(false, $opened, 'соединения с БД не было');
});

test('вошедший отдаётся шапке', function () use ($CFG_ON) {
    $db = test_db();
    roblox_touch_user($db, [
        'roblox_id' => '42', 'username' => 'mak', 'display_name' => 'Mak',
        'avatar_url' => 'https://tr.rbxcdn.com/a.png',
    ], 1000);
    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON);
    assert_eq('42', $s['user']['id'], 'id');
    assert_eq('Mak', $s['user']['display'], 'display name');
});

test('сессия ссылается на удалённого пользователя — считаем, что не вошёл', function () use ($CFG_ON) {
    $db = test_db();
    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON);
    assert_eq(null, $s['user'], 'записи нет — и пользователя нет');
});

test('без таблицы users сайт не падает', function () use ($CFG_ON) {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON);
    assert_eq(null, $s['user'], 'не вошёл');
    assert_true($s['roblox'], 'кнопка входа при этом остаётся');
});

test('вход выключен — шапка узнаёт об этом', function () {
    $s = handle_session(function () { return test_db(); }, [], []);
    assert_eq(false, $s['roblox'], 'client_id не прописан');
});

test('админская сессия не путается с пользовательской', function () use ($CFG_ON) {
    $s = handle_session(function () { return test_db(); }, ['admin' => true], $CFG_ON);
    assert_true($s['admin'], 'админ');
    assert_eq(null, $s['user'], 'но не игрок Roblox');
});

// --------------------------------------------------------------------------
//  Присутствие
// --------------------------------------------------------------------------

// last_login_at пишется РОВНО ОДИН РАЗ — при возврате с roblox.com, — а
// сессия живёт долго. Считать по нему «в сети» значит гасить индикатор через
// пять минут после логина у человека, который всю неделю ходит по сайту.
// Поэтому присутствие отмечается отдельно, и отмечает его запрос состояния из
// шапки: он и так случается на каждой странице у каждого вошедшего.

function ra_seen(PDO $db, string $id): int {
    $st = $db->prepare('SELECT last_seen_at FROM users WHERE roblox_id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

function ra_user(PDO $db, string $id = '42', int $at = 1000): void {
    roblox_touch_user($db, [
        'roblox_id' => $id, 'username' => 'mak', 'display_name' => 'Mak',
        'avatar_url' => 'https://tr.rbxcdn.com/a.png',
    ], $at);
}

test('отметка присутствия ставится не чаще раза в минуту', function () {
    $db = test_db();
    ra_user($db);
    $u = roblox_load_user($db, '42');
    assert_eq(0, $u['seen'], 'вход отметку присутствия не ставит');

    assert_true(roblox_touch_seen($db, $u, 5000), 'первая отметка проходит');
    assert_eq(5000, ra_seen($db, '42'), 'записана');

    $u = roblox_load_user($db, '42');
    assert_eq(false, roblox_touch_seen($db, $u, 5000 + ROBLOX_SEEN_THROTTLE - 1),
        'секундой раньше порога — не пишем');
    assert_eq(5000, ra_seen($db, '42'), 'значение не тронуто');

    assert_true(roblox_touch_seen($db, $u, 5000 + ROBLOX_SEEN_THROTTLE),
        'ровно на пороге — пишем');
    assert_eq(5000 + ROBLOX_SEEN_THROTTLE, ra_seen($db, '42'), 'обновлено');
});

test('отметка не трогает чужие строки и не создаёт своих', function () {
    $db = test_db();
    ra_user($db, '42');
    ra_user($db, '43');
    roblox_touch_seen($db, roblox_load_user($db, '42'), 5000);
    assert_eq(5000, ra_seen($db, '42'), 'своя строка отмечена');
    assert_eq(0, ra_seen($db, '43'), 'соседняя — нет');
    assert_eq(2, (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'строк не прибавилось');
});

// Колонки на боевой базе может ещё не быть: миграция выполняется руками и
// отдельно от выкладки. Отметка обязана в этом случае промолчать, а не уронить
// ответ шапки — присутствие это украшение, а не вход.
test('без колонки присутствия отметка молчит, а вход работает', function () use ($CFG_ON) {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE users (
        roblox_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_login_at INTEGER NOT NULL
    )");
    ra_user($db);

    $u = roblox_load_user($db, '42');
    assert_true($u !== null, 'пользователь читается');
    assert_eq(null, $u['seen'], 'присутствия нет — и это не ноль, а «нечего отмечать»');
    assert_eq(false, roblox_touch_seen($db, $u, 9999), 'отметка не делается');

    // Предыдущая строка отказывает по стражу на null и до базы не доходит.
    // Здесь отметку подставляем руками, чтобы страж пропустил, и запрос
    // ДОШЁЛ до несуществующей колонки: без try/catch это исключение уронило бы
    // ответ шапки, то есть выбило бы вход у всех до выполнения миграции.
    assert_eq(false, roblox_touch_seen($db, ['id' => '42', 'seen' => 0], 9999),
        'запрос в несуществующую колонку не роняет ответ');

    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON, 9999);
    assert_eq('42', $s['user']['id'], 'шапка по-прежнему видит вошедшего');
});

test('ручка состояния отмечает присутствие сама', function () use ($CFG_ON) {
    $db = test_db();
    ra_user($db);

    handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON, 7000);
    assert_eq(7000, ra_seen($db, '42'), 'отметка поставлена при обычном запросе состояния');

    handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON, 7030);
    assert_eq(7000, ra_seen($db, '42'), 'через полминуты второй записи нет');

    handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON, 7060);
    assert_eq(7060, ra_seen($db, '42'), 'через минуту — есть');
});

// В бою handle_session() зовётся без $now — время берётся внутри. Подстановку
// надо проверить отдельно: все остальные проверки передают время сами и
// сломанный time() в них не виден.
test('без переданного времени отметка берёт текущее', function () use ($CFG_ON) {
    $db = test_db();
    ra_user($db);
    $before = time();
    handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON);
    $after = time();

    $seen = ra_seen($db, '42');
    assert_true($seen >= $before && $seen <= $after,
        "отметка $seen лежит между $before и $after");
});

test('анониму присутствие не отмечается и база не открывается', function () use ($CFG_ON) {
    $opened = false;
    handle_session(function () use (&$opened) { $opened = true; return test_db(); }, [], $CFG_ON, 7000);
    assert_eq(false, $opened, 'соединения не было');
});

test('сессия на удалённого пользователя ничего не отмечает', function () use ($CFG_ON) {
    $db = test_db();
    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '999'], $CFG_ON, 7000);
    assert_eq(null, $s['user'], 'не вошёл');
    assert_eq(0, (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'строка не завелась');
});

// seen — внутреннее поле отметки. Шапке оно не нужно, а лишнее поле в JSON
// это лишнее обещание: сегодня им никто не пользуется, завтра кто-нибудь
// начнёт, и убрать его будет уже нельзя.
test('время присутствия не уезжает в ответ шапки', function () use ($CFG_ON) {
    $db = test_db();
    ra_user($db);
    $s = handle_session(function () use ($db) { return $db; }, ['user_id' => '42'], $CFG_ON, 7000);
    assert_eq(false, array_key_exists('seen', $s['user']), 'поля seen в ответе нет');
    assert_eq(['id', 'name', 'display', 'avatar', 'profile'], array_keys($s['user']),
        'ответ несёт ровно то, что нужно шапке');
});

// --------------------------------------------------------------------------
//  Схема и её зеркало в тестах
// --------------------------------------------------------------------------

// test_db() повторяет schema.sql руками, и разъезжаются они молча: тест на
// забытой колонке остаётся зелёным, а бой падает. Так уже могло случиться с
// about и last_seen_at — обе приехали миграциями и в оба места вписывались
// отдельно.
//
// Сравниваются НАБОРЫ имён колонок, а не текст: типы у MySQL и SQLite разные
// по определению (BIGINT UNSIGNED против INTEGER), и требовать их совпадения
// значило бы требовать невозможного.
function ra_columns(string $sql, string $create): array {
    $at = strpos($sql, $create);
    if ($at === false) { return []; }
    $body = substr($sql, $at + strlen($create));
    // Закрывающая скобка стоит в начале строки: в schema.sql за ней идёт
    // ENGINE=InnoDB, в tests/lib.php — кавычка с точкой с запятой, и искать
    // конкретный хвост значило бы завязываться на оба диалекта сразу.
    if (!preg_match('/\n\s*\)/', $body, $m, PREG_OFFSET_CAPTURE)) { return []; }
    $body = substr($body, 0, $m[0][1]);
    $out = [];
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '--') === 0) { continue; }
        if (preg_match('/^([a-z_]+)\s/i', $line, $m)) {
            $name = strtolower($m[1]);
            if (in_array($name, ['primary', 'unique', 'key', 'index', 'constraint'], true)) { continue; }
            $out[] = $name;
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

test('users в test_db() несёт ровно те же колонки, что и schema.sql', function () {
    $root   = dirname(__DIR__);
    $schema = ra_columns((string)file_get_contents($root . '/schema.sql'),
                         "CREATE TABLE IF NOT EXISTS users (");
    $mirror = ra_columns((string)file_get_contents($root . '/tests/lib.php'),
                         'CREATE TABLE users (');

    assert_true(count($schema) > 0, 'колонки в schema.sql нашлись');
    assert_true(in_array('last_seen_at', $schema, true), 'присутствие описано в schema.sql');
    assert_eq($schema, $mirror, 'наборы колонок совпадают');
});

// Колонка, добавленная миграцией, обязана быть и в schema.sql: миграция — для
// уже созданной боевой базы, а чистая установка идёт по схеме. Забыть второе
// значит завести пользователя без колонки и упереться в неё на первой же
// странице профиля.
test('миграция присутствия согласована со схемой', function () {
    $root = dirname(__DIR__);
    $mig  = (string)file_get_contents($root . '/docs/migrations/2026-09-10-last-seen.sql');
    assert_true(strpos($mig, 'ADD COLUMN last_seen_at') !== false, 'миграция заводит колонку');
    assert_true(strpos($mig, 'WHERE last_seen_at = 0') !== false,
        'перенос не откатывает уже проставленные отметки при повторном запуске');

    $schema = (string)file_get_contents($root . '/schema.sql');
    assert_true(strpos($schema, 'last_seen_at  BIGINT UNSIGNED NOT NULL DEFAULT 0') !== false,
        'та же колонка описана в schema.sql');
    assert_true(strpos($mig, 'BIGINT UNSIGNED NOT NULL DEFAULT 0') !== false,
        'и тем же типом');
});

run_tests();
