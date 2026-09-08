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

run_tests();
