<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

// «Запомнить вход» (api/lib/remember.php): долгая кука, по которой пустая
// сессия получает user_id обратно, и человек не входит через Roblox заново
// после каждого перезапуска браузера.

// Зеркалит login_tokens из schema.sql.
function remember_db(): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE login_tokens (
        selector TEXT NOT NULL PRIMARY KEY,
        token_hash TEXT NOT NULL,
        user_id INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL
    )");
    return $pdo;
}

function remember_rows(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM login_tokens')->fetchColumn();
}

const T0 = 1790000000;

// --------------------------------------------------------------------------
//  Ключ
// --------------------------------------------------------------------------

test('кука разбирается только в точном формате', function () {
    $sel = str_repeat('a', 24);
    $val = str_repeat('b', 64);
    assert_eq([$sel, $val], remember_parse("$sel.$val"), 'правильная');
    assert_eq(null, remember_parse(null), 'нет куки');
    assert_eq(null, remember_parse(['x']), 'массив');
    assert_eq(null, remember_parse(''), 'пусто');
    assert_eq(null, remember_parse("$sel$val"), 'без точки');
    assert_eq(null, remember_parse(strtoupper("$sel.$val")), 'заглавные');
    assert_eq(null, remember_parse("$sel.$val\n"), 'хвост после ключа');
    assert_eq(null, remember_parse(substr($sel, 1) . ".$val"), 'короткий selector');
    assert_eq(null, remember_parse("$sel.$val" . 'b'), 'длинный секрет');
});

test('выданный ключ узнаёт того же человека', function () {
    $pdo = remember_db();
    $raw = remember_issue($pdo, '123', T0);
    assert_true(remember_parse($raw) !== null, 'формат куки');
    $hit = remember_check($pdo, $raw, T0 + 60);
    assert_eq('123', $hit['user_id'] ?? null, 'user_id');
    assert_eq(false, $hit['renew'], 'свежий ключ не продлевается');
});

test('в базе нет секрета — только его хеш', function () {
    $pdo = remember_db();
    $raw = remember_issue($pdo, '123', T0);
    [$sel, $val] = remember_parse($raw);
    $row = $pdo->query('SELECT * FROM login_tokens')->fetch(PDO::FETCH_ASSOC);
    assert_eq($sel, $row['selector'], 'selector открыт');
    assert_eq(hash('sha256', $val), $row['token_hash'], 'хеш секрета');
    assert_eq(false, strpos(implode('|', $row), $val), 'секрета нет ни в одной колонке');
});

test('подобранный секрет не пускает', function () {
    $pdo = remember_db();
    $raw = remember_issue($pdo, '123', T0);
    [$sel] = remember_parse($raw);
    assert_eq(null, remember_check($pdo, $sel . '.' . str_repeat('0', 64), T0), 'чужой секрет');
    assert_eq(null, remember_check($pdo, str_repeat('c', 24) . '.' . str_repeat('0', 64), T0), 'нет такого selector');
    assert_eq(null, remember_check($pdo, 'мусор', T0), 'мусор');
    assert_eq('123', remember_check($pdo, $raw, T0)['user_id'] ?? null, 'настоящий ключ цел');
});

test('ключи разных входов разные', function () {
    $pdo = remember_db();
    $a = remember_issue($pdo, '123', T0);
    $b = remember_issue($pdo, '123', T0);
    assert_true($a !== $b, 'не повторяются');
    assert_eq(2, remember_rows($pdo), 'два устройства — два ключа');
});

// --------------------------------------------------------------------------
//  Срок
// --------------------------------------------------------------------------

test('истёкший ключ не пускает и удаляется', function () {
    $pdo = remember_db();
    $raw = remember_issue($pdo, '123', T0);
    assert_eq(null, remember_check($pdo, $raw, T0 + REMEMBER_TTL), 'ровно на сроке уже нет');
    assert_eq(0, remember_rows($pdo), 'строка стёрта');
});

test('срок скользит: восстановление продлевает ключ, но не чаще раза в сутки', function () {
    $pdo = remember_db();
    $raw = remember_issue($pdo, '123', T0);

    $hit = remember_check($pdo, $raw, T0 + REMEMBER_RENEW - 1);
    assert_eq(false, $hit['renew'], 'меньше суток — без записи');
    assert_eq(T0 + REMEMBER_TTL, $hit['expires'], 'срок прежний');

    $later = T0 + 100 * 86400;
    $hit = remember_check($pdo, $raw, $later);
    assert_eq(true, $hit['renew'], 'через 100 дней — продлить');
    assert_eq($later + REMEMBER_TTL, $hit['expires'], 'ещё полгода от сегодня');

    // Без продления ключ умер бы на 180-й день. Продлённый жив и на 250-й.
    $hit = remember_check($pdo, $raw, T0 + 250 * 86400);
    assert_eq('123', $hit['user_id'] ?? null, 'продлённый ключ жив');
});

test('выдача убирает истёкшие ключи всех', function () {
    $pdo = remember_db();
    remember_issue($pdo, '1', T0);
    remember_issue($pdo, '2', T0);
    remember_issue($pdo, '3', T0 + REMEMBER_TTL + 1);
    assert_eq(1, remember_rows($pdo), 'остался только новый');
});

test('одиннадцатое устройство гасит самое старое', function () {
    $pdo = remember_db();
    $first = remember_issue($pdo, '123', T0);
    for ($i = 1; $i < REMEMBER_MAX_PER_USER; $i++) { remember_issue($pdo, '123', T0 + $i); }
    $other = remember_issue($pdo, '999', T0);
    assert_eq('123', remember_check($pdo, $first, T0 + 60)['user_id'] ?? null, 'десять — все живы');

    remember_issue($pdo, '123', T0 + 100);
    assert_eq(null, remember_check($pdo, $first, T0 + 200), 'самый старый погашен');
    assert_eq(REMEMBER_MAX_PER_USER + 1, remember_rows($pdo), 'десять своих + чужой');
    assert_eq('999', remember_check($pdo, $other, T0 + 200)['user_id'] ?? null, 'чужие ключи не тронуты');
});

// --------------------------------------------------------------------------
//  Выход
// --------------------------------------------------------------------------

test('выход гасит только свой ключ', function () {
    $pdo = remember_db();
    $mine  = remember_issue($pdo, '123', T0);
    $phone = remember_issue($pdo, '123', T0);
    remember_revoke($pdo, $mine);
    assert_eq(null, remember_check($pdo, $mine, T0), 'этот браузер вышел');
    assert_eq('123', remember_check($pdo, $phone, T0)['user_id'] ?? null, 'телефон остался');
    remember_revoke($pdo, 'мусор');
    remember_revoke($pdo, null);
    assert_eq(1, remember_rows($pdo), 'мусор ничего не стёр');
});

// --------------------------------------------------------------------------
//  Живой запрос: сессия, кука, база — в отдельном процессе php
// --------------------------------------------------------------------------

// Прогоняет $body в дочернем php с настоящими сессиями и базой-файлом.
// $cookies — что прислал браузер. Возвращает то, что напечатал $body (JSON).
function remember_run(string $dbFile, array $cookies, string $body): ?array {
    if (!function_exists('shell_exec')) { return null; }
    $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/nx_remember_' . getmypid();
    if (!is_dir("$dir/sess")) {
        @mkdir("$dir/sess", 0777, true);
        register_shutdown_function(function () use ($dir) {
            foreach (glob("$dir/sess/*") ?: [] as $f) { @unlink($f); }
            @rmdir("$dir/sess");
            @unlink("$dir/config.php");
            @unlink("$dir/run.php");
            @rmdir($dir);
        });
    }
    file_put_contents("$dir/config.php", '<?php return ' . var_export([
        'dsn' => 'sqlite:' . $dbFile, 'db_user' => '', 'db_pass' => '',
    ], true) . ';');
    $script = "$dir/run.php";
    file_put_contents($script, '<?php'
        . "\nini_set('session.save_path', " . var_export("$dir/sess", true) . ');'
        . "\ndefine('CONFIG_PATH', " . var_export("$dir/config.php", true) . ');'
        . "\n\$_COOKIE = " . var_export($cookies, true) . ';'
        . "\nrequire " . var_export(str_replace('\\', '/', __DIR__) . '/../public_html/api/_bootstrap.php', true) . ';'
        . "\n" . $body . "\n");
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $devnull = DIRECTORY_SEPARATOR === '/' ? '2>/dev/null' : '2>nul';
    $out = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . $devnull);
    $data = is_string($out) ? json_decode(trim($out), true) : null;
    return is_array($data) ? $data : null;
}

function remember_db_file(bool $withTable): array {
    $file = str_replace('\\', '/', sys_get_temp_dir()) . '/nx_remember_' . getmypid() . '_' . mt_rand() . '.sqlite';
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($withTable) {
        $pdo->exec("CREATE TABLE login_tokens (selector TEXT PRIMARY KEY, token_hash TEXT NOT NULL,
            user_id INTEGER NOT NULL, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)");
    }
    register_shutdown_function(function () use ($file) { @unlink($file); });
    return [$file, $pdo];
}

const REMEMBER_PROBE = '$ok = resume_site_session();'
    . ' echo json_encode(["ok" => $ok, "uid" => $_SESSION["user_id"] ?? null,'
    . ' "active" => session_status() === PHP_SESSION_ACTIVE, "cookie" => $_COOKIE[REMEMBER_COOKIE] ?? null]);';

test('после перезапуска браузера кука входа возвращает сессию', function () {
    [$file, $pdo] = remember_db_file(true);
    $raw = remember_issue($pdo, '777', time());
    $r = remember_run($file, [REMEMBER_COOKIE => $raw], REMEMBER_PROBE);
    assert_true($r !== null, 'дочерний php отработал');
    if ($r === null) { return; }
    assert_eq(true, $r['ok'], 'сессия есть');
    assert_eq('777', $r['uid'], 'тот же человек');
});

test('сессию стёр хостинг — кука входа возвращает её под новым id', function () {
    [$file, $pdo] = remember_db_file(true);
    $raw = remember_issue($pdo, '777', time());
    $stale = str_repeat('d', 26);
    $r = remember_run($file, [session_name() => $stale, REMEMBER_COOKIE => $raw],
        'start_site_session(); echo json_encode(["uid" => $_SESSION["user_id"] ?? null, "sid" => session_id()]);');
    assert_true($r !== null, 'дочерний php отработал');
    if ($r === null) { return; }
    assert_eq('777', $r['uid'], 'user_id вернулся');
    assert_true($r['sid'] !== $stale, 'идентификатор сессии сменён');
});

test('поддельная кука входа не заводит сессию и стирается', function () {
    [$file] = remember_db_file(true);
    $r = remember_run($file, [REMEMBER_COOKIE => str_repeat('a', 24) . '.' . str_repeat('b', 64)], REMEMBER_PROBE);
    assert_true($r !== null, 'дочерний php отработал');
    if ($r === null) { return; }
    assert_eq(false, $r['ok'], 'не вошёл');
    assert_eq(false, $r['active'], 'сессия не заведена');
    assert_eq(null, $r['cookie'], 'кука стёрта');
});

test('без таблицы всё как раньше, и кука не стирается', function () {
    [$file] = remember_db_file(false);
    $raw = str_repeat('a', 24) . '.' . str_repeat('b', 64);
    $r = remember_run($file, [REMEMBER_COOKIE => $raw], REMEMBER_PROBE);
    assert_true($r !== null, 'дочерний php отработал');
    if ($r === null) { return; }
    assert_eq(false, $r['ok'], 'не вошёл');
    assert_eq($raw, $r['cookie'], 'сбой базы не разлогинивает');
});

test('выход гасит ключ, и следующий запрос уже аноним', function () {
    [$file, $pdo] = remember_db_file(true);
    $raw = remember_issue($pdo, '777', time());
    $r = remember_run($file, [REMEMBER_COOKIE => $raw],
        'remember_logout(); $ok = resume_site_session(); echo json_encode(["ok" => $ok]);');
    assert_true($r !== null, 'дочерний php отработал');
    if ($r === null) { return; }
    assert_eq(false, $r['ok'], 'выход не восстановил сессию сам себе');
    assert_eq(0, remember_rows($pdo), 'ключ стёрт на сервере');
    $r = remember_run($file, [REMEMBER_COOKIE => $raw], REMEMBER_PROBE);
    assert_eq(false, $r['ok'] ?? null, 'старая кука больше не пускает');
});

// --------------------------------------------------------------------------
//  Проводка
// --------------------------------------------------------------------------

test('вход выдаёт ключ, оба выхода его гасят раньше сессии', function () {
    $pub = __DIR__ . '/../public_html';
    $cb = file_get_contents("$pub/api/roblox_callback.php");
    $set = strpos($cb, "\$_SESSION['user_id'] = \$profile['roblox_id'];");
    $rem = strpos($cb, "remember_login(\$profile['roblox_id']);");
    assert_true($set !== false && $rem !== false && $set < $rem, 'callback: ключ после входа');
    foreach (['api/logout.php', 'admin-logout.php'] as $f) {
        $src = file_get_contents("$pub/$f");
        $out = strpos($src, 'remember_logout();');
        $res = preg_match('/if \(!?resume_site_session\(\)\)/', $src, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
        assert_true($out !== false && $res !== false && $out < $res, "$f: ключ гасится до сессии");
    }
});

test('кука входа закрыта от скриптов страницы', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/lib/remember.php');
    assert_true(strpos($src, "'httponly' => true") !== false, 'HttpOnly');
    assert_true(strpos($src, "'samesite' => 'Lax'") !== false, 'SameSite=Lax');
});

run_tests();
