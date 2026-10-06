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

// --- бан ----------------------------------------------------------------------

test('бан — из banned_ids, с той же чисткой списка', function () {
    $cfg = ['banned_ids' => ['555', ' 0777 '], 'admin_ids' => ['555']];
    assert_eq(true, site_banned('555', $cfg), 'в списке');
    assert_eq(true, site_banned('777', $cfg), 'пробелы и нули в конфиге не мешают');
    assert_eq(false, site_banned('556', $cfg), 'не в списке');
    assert_eq(false, site_banned('', $cfg), 'аноним');
    assert_eq(false, site_banned('555', []), 'списка нет — бана нет');
    assert_eq(false, site_banned('555', ['banned_ids' => '555']), 'не массив — бана нет');
});

// --- временный бан ------------------------------------------------------------

// Полночь 20.10.2026 по Москве (UTC+3) — 21:00 UTC накануне.
const BAN_MIDNIGHT = 1792443600;

test('срок бана — дата или дата со временем, по Москве', function () {
    assert_eq(gmmktime(21, 0, 0, 10, 19, 2026), BAN_MIDNIGHT, 'константа теста верна');
    assert_eq(BAN_MIDNIGHT, ban_parse_until('2026-10-20'), 'дата — до полуночи');
    assert_eq(BAN_MIDNIGHT + 18 * 3600 + 30 * 60, ban_parse_until('2026-10-20 18:30'), 'с временем');
    assert_eq(BAN_MIDNIGHT + 9 * 3600, ban_parse_until(' 2026-10-20  9:00 '), 'час одной цифрой, пробелы');
    assert_eq(0, ban_parse_until('2026-02-30'), 'похоже на дату, но такой нет — без срока');
    assert_eq(0, ban_parse_until('2026-10-20 25:00'), 'и такого часа нет');
    foreach (['1234567890', '20.10.2026', '2026-10-20T18:30', '', 'завтра'] as $bad) {
        assert_eq(null, ban_parse_until($bad), "не дата: '$bad'");
    }
    assert_eq(null, ban_parse_until(1234567890), 'число — не дата');
    assert_eq(null, ban_parse_until(null), 'null — не дата');
});

test('бан со сроком действует до срока и снимается сам', function () {
    $cfg = ['banned_ids' => ['555', '777' => '2026-10-20', 888 => '2026-10-20 18:30']];
    $before = BAN_MIDNIGHT - 1;
    assert_eq(0, site_ban_until('555', $cfg, $before), 'обычный — навсегда');
    assert_eq(BAN_MIDNIGHT, site_ban_until('777', $cfg, $before), 'до срока — забанен, конец известен');
    assert_eq(null, site_ban_until('777', $cfg, BAN_MIDNIGHT), 'в срок — уже нет');
    assert_eq(true, site_banned('888', $cfg, BAN_MIDNIGHT), 'у второго срок дальше');
    assert_eq(false, site_banned('888', $cfg, BAN_MIDNIGHT + 19 * 3600), 'и он кончился');
    assert_eq(0, site_ban_until('555', $cfg, BAN_MIDNIGHT + 999999999), 'бессрочный не кончается');
    assert_eq(['555', '888'], site_banned_ids($cfg, BAN_MIDNIGHT), 'список действующих — строками');
});

test('бан со сроком: кривые записи и повторы', function () {
    $cfg = ['banned_ids' => [
        '2026-10-20',                 // дата без id в списке — пропуск
        '111' => '2026-02-30',        // несуществующая дата — навсегда
        '222' => '2026-10-20', '0222' => '2026-10-25',  // два срока — дальний
        '333' => '2026-10-20', 'x' => '333',            // срок и «навсегда» — навсегда
        'abc' => '2026-10-20',        // id не число — пропуск
    ]];
    $bans = site_bans($cfg, BAN_MIDNIGHT - 1);
    assert_eq(0, $bans['111'] ?? 'нет', 'кривая дата — без срока');
    assert_eq(BAN_MIDNIGHT + 5 * 86400, $bans['222'] ?? 'нет', 'дальний из двух сроков');
    assert_eq(0, $bans['333'] ?? 'нет', 'навсегда сильнее срока');
    assert_eq(['111', '222', '333'], site_banned_ids($cfg, BAN_MIDNIGHT - 1), 'больше никого');
});

test('callback не пускает забаненного: проверка до записи в users и в сессию', function () {
    $cb = file_get_contents(__DIR__ . '/../public_html/api/roblox_callback.php');
    $ban   = strpos($cb, "site_ban_until((string)\$profile['roblox_id'], \$cfg)");
    $touch = strpos($cb, 'roblox_touch_user(');
    $set   = strpos($cb, "\$_SESSION['user_id'] = ");
    assert_true($ban !== false, 'проверка есть');
    assert_true($ban < $touch && $ban < $set, 'и стоит раньше входа');
    assert_true(strpos($cb, "'banned-' . \$until : 'banned'") !== false, 'шапке уходит флаг banned, со сроком — с концом');
});

test('шапка показывает отказ забаненному на обоих языках, срок — датой', function () {
    $pub = __DIR__ . '/../public_html/js';
    $top = file_get_contents("$pub/topbar.js");
    assert_true(strpos($top, '/[?&]login=([a-z]+(?:-[0-9]+)?)/') !== false, 'флаг читается вместе со сроком');
    assert_true(strpos($top, '/^banned(?:-([0-9]+))?$/') !== false, 'topbar.js ловит оба вида');
    $i18n = file_get_contents("$pub/i18n.js");
    foreach (['user.banned', 'user.bannedUntil'] as $k) {
        assert_eq(2, substr_count($i18n, "\"$k\":"), "$k: ru и en");
        assert_eq(1, substr_count($top, "\"$k\":"), "$k: запасной текст в topbar.js");
    }
    assert_eq(3, substr_count($i18n . $top, 'заблокирован на сайте до {date}') + substr_count($i18n, 'banned from the site until {date}'), 'место для даты во всех трёх текстах');
    assert_true(strpos($top, '.replace("{date}", banDate(') !== false, 'и дата туда подставляется');
    $auth = file_get_contents("$pub/auth.js");
    assert_true(strpos($auth, 'login=[^&]*') !== false, 'MKAuth.here() уносит флаг из адреса целиком, со сроком');
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
