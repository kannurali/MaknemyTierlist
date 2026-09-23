<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

// Роли сайта: админ и модератор — это аккаунты Roblox из admin_ids и
// moderator_ids в config.php. Пароля больше нет.

// --- список id из конфига ---------------------------------------------------

test('config_id_list берёт только цифры, без нулей впереди и без повторов', function () {
    $cfg = ['admin_ids' => ['101', 102, ' 103 ', '101', 'abc', '', '0', '007', ['x'], true, 1.5]];
    assert_eq(['101', '102', '103', '7'], config_id_list($cfg, 'admin_ids'));
    assert_eq([], config_id_list(['admin_ids' => 'не список'], 'admin_ids'), 'не массив — пусто');
    assert_eq([], config_id_list([], 'admin_ids'), 'ключа нет — пусто');
});

// --- роль ---------------------------------------------------------------------

$CFG = ['admin_ids' => ['1', '2'], 'moderator_ids' => ['2', '3']];

test('админ — из admin_ids, модератор — из moderator_ids', function () use ($CFG) {
    assert_eq('admin', site_role(['user_id' => '1'], $CFG), 'админ');
    assert_eq('moderator', site_role(['user_id' => '3'], $CFG), 'модератор');
    assert_eq('', site_role(['user_id' => '4'], $CFG), 'обычный игрок');
    assert_eq('', site_role([], $CFG), 'аноним');
});

test('кто в обоих списках — админ', function () use ($CFG) {
    assert_eq('admin', site_role(['user_id' => '2'], $CFG));
});

test('флаг старого входа по паролю прав не даёт', function () use ($CFG) {
    assert_eq('', site_role(['admin' => true], $CFG), 'одна старая метка');
    assert_eq('', site_role(['admin' => true, 'user_id' => '4'], $CFG), 'и вместе с игроком');
});

test('без списков в конфиге ни у кого прав нет', function () {
    assert_eq('', site_role(['user_id' => '1'], []));
});

test('user_id не строкой — не вошёл', function () use ($CFG) {
    assert_eq('', site_role(['user_id' => 1], $CFG), 'число');
    assert_eq('', site_role(['user_id' => ['1']], $CFG), 'массив');
    assert_eq('', site_role(['user_id' => true], $CFG), 'true');
});

test('is_admin и is_moderator смотрят на открытую сессию', function () {
    $_SESSION = [];
    assert_eq(false, is_admin(), 'аноним — не админ, конфиг даже не читается');
    assert_eq(false, is_moderator(), 'и не модератор');
});

// --- вход по паролю убран ------------------------------------------------------

// Файл остаётся на сервере: деплой ничего не удаляет, и прежний login.php
// продолжал бы пускать по паролю. Поэтому он обязан существовать и ничего не
// проверять.
test('login.php — заглушка: 410 и никакой проверки пароля', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/login.php');
    assert_true(strpos($src, "'password_login_removed'], 410)") !== false, 'отвечает 410');
    assert_eq(false, strpos($src, 'password_verify'), 'пароль не проверяется');
    assert_eq(false, strpos($src, 'admin_hash'), 'хеш не читается');
    assert_eq(false, strpos($src, '$_SESSION'), 'сессия не трогается');
});

test('ни один файл сайта больше не ставит и не читает $_SESSION[admin]', function () {
    $root = __DIR__ . '/../public_html';
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') { continue; }
        $src = file_get_contents($f->getPathname());
        assert_eq(false, strpos($src, "\$_SESSION['admin']"), $f->getPathname());
        assert_eq(false, strpos($src, "\$session['admin']"), $f->getPathname());
    }
});

test('панель пускает по роли, остальным её как будто нет', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/lib/admin_page.php');
    assert_eq(false, strpos($src, 'type="password"'), 'поля пароля нет');
    assert_eq(false, strpos($src, '/api/login.php'), 'на login.php ничего не ходит');
    assert_eq(false, strpos($src, 'roblox_start'), 'своей кнопки входа у панели нет');
    assert_true(strpos($src, "    admin_not_found();\n    exit;") !== false
        || strpos($src, "    admin_not_found();\r\n    exit;") !== false, 'чужим — 404');
});

test('robots.txt не рассказывает про панель', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/robots.txt');
    assert_eq(false, stripos($src, 'admin'), 'ни строки про /admin');
});

test('обращения отмечают модераторы, вебхук ставят только админы', function () {
    $pub = __DIR__ . '/../public_html';
    assert_true(strpos(file_get_contents("$pub/api/support_status.php"), 'require_moderator();') !== false, 'support_status');
    assert_true(strpos(file_get_contents("$pub/api/tg_setup.php"), 'require_admin();') !== false, 'tg_setup');
    assert_true(strpos(file_get_contents("$pub/admin-support.php"), "admin_page_guard('moderator');") !== false,
        '/admin/support открыт модераторам');
    foreach (['admin.php', 'admin-news.php', 'admin-promo.php'] as $f) {
        assert_true((bool)strpos(file_get_contents("$pub/$f"), 'admin_page_guard();') !== false, "$f — только админам");
    }
    foreach (['save.php', 'upload.php', 'news_save.php', 'news_delete.php'] as $f) {
        assert_true(strpos(file_get_contents("$pub/api/$f"), 'require_admin();') !== false, "api/$f — только админам");
    }
});

run_tests();
