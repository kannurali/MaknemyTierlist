<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';

test('read_json_body parses injected raw body', function () {
    $GLOBALS['__RAW_BODY__'] = '{"dir":-1}';
    assert_eq(['dir' => -1], read_json_body(), 'parses JSON');
});

test('read_json_body returns [] on garbage', function () {
    $GLOBALS['__RAW_BODY__'] = 'not json';
    assert_eq([], read_json_body(), 'garbage -> []');
});

test('is_admin false without session flag', function () {
    $_SESSION = [];
    assert_eq(false, is_admin(), 'no flag -> not admin');
});

// --------------------------------------------------------------------------
//  Сессия — только по куке
// --------------------------------------------------------------------------

test('a session counts as present only with a non-empty cookie', function () {
    $name = session_name();
    assert_eq(false, site_session_cookie_present([]), 'no cookie');
    assert_eq(false, site_session_cookie_present([$name => '']), 'empty cookie');
    assert_eq(false, site_session_cookie_present([$name => ['x']]), 'array instead of a string');
    assert_eq(false, site_session_cookie_present(['other' => 'abc']), 'someone else\'s cookie');
    assert_true(site_session_cookie_present([$name => 'abc123']), 'present');
});

// --------------------------------------------------------------------------
//  Адрес посетителя
// --------------------------------------------------------------------------

test('without Cloudflare the client is REMOTE_ADDR', function () {
    assert_eq('203.0.113.7', client_ip(['REMOTE_ADDR' => '203.0.113.7']), 'plain');
    assert_eq('unknown', client_ip([]), 'nothing at all');
});

// Верить CF-Connecting-IP от кого угодно — значит дать любому скрипту
// назваться новым адресом на каждом запросе и пройти мимо лимитов.
test('CF-Connecting-IP is trusted only from a Cloudflare address', function () {
    $cf = ['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9'];
    assert_eq('198.51.100.9', client_ip($cf), 'via Cloudflare');

    $forged = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_CF_CONNECTING_IP' => '198.51.100.9'];
    assert_eq('203.0.113.7', client_ip($forged), 'a forged header from anyone else is ignored');

    $v6 = ['REMOTE_ADDR' => '2a06:98c1::1', 'HTTP_CF_CONNECTING_IP' => '2001:db8:1:2:3:4:5:6'];
    assert_eq('2001:db8:1:2:3:4:5:6', client_ip($v6), 'Cloudflare node on IPv6');

    $junk = ['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'];
    assert_eq('172.70.1.2', client_ip($junk), 'garbage in the header falls back');
});

test('ip_in_cidr respects the prefix length and the address family', function () {
    assert_true(ip_in_cidr('104.16.0.1', '104.16.0.0/13'), 'inside a /13');
    assert_true(ip_in_cidr('104.23.255.255', '104.16.0.0/13'), 'last address of the /13');
    assert_eq(false, ip_in_cidr('104.24.0.0', '104.16.0.0/13'), 'first address after it');
    assert_true(ip_in_cidr('2a06:98c7:ffff::1', '2a06:98c0::/29'), 'inside an IPv6 /29');
    assert_eq(false, ip_in_cidr('2a06:98c8::1', '2a06:98c0::/29'), 'just outside it');
    assert_eq(false, ip_in_cidr('::ffff:104.16.0.1', '104.16.0.0/13'), 'IPv6 form never matches an IPv4 net');
    assert_eq(false, ip_in_cidr('garbage', '104.16.0.0/13'), 'garbage');
    assert_true(ip_in_cidr('10.1.2.3', '10.1.2.3'), 'bare address means /32');
    assert_eq(false, ip_in_cidr('10.1.2.3', '10.0.0.0/40'), 'impossible prefix');
});

test('every listed Cloudflare range parses', function () {
    foreach (CLOUDFLARE_RANGES as $range) {
        [$net] = explode('/', $range);
        assert_true(ip_in_cidr($net, $range), "$range contains its own network address");
    }
});

// Абоненту IPv6 выдают целую /64: лимит «на адрес» обходился бы перебором.
test('limit keys: IPv4 as is, IPv6 by its /64', function () {
    assert_eq('203.0.113.7', client_key(['REMOTE_ADDR' => '203.0.113.7']), 'v4');
    assert_eq('2001:db8:1:2::/64', client_key(['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd']), 'v6 -> /64');
    assert_eq(client_key(['REMOTE_ADDR' => '2001:db8:1:2::1']), client_key(['REMOTE_ADDR' => '2001:db8:1:2:ffff::9']),
        'one /64 is one visitor');
    assert_true(client_key(['REMOTE_ADDR' => '2001:db8:1:2::1']) !== client_key(['REMOTE_ADDR' => '2001:db8:1:3::1']),
        'the neighbouring /64 is another one');
    assert_eq('203.0.113.7', client_key(['REMOTE_ADDR' => '::ffff:203.0.113.7']), 'v4-mapped -> v4');
    assert_eq('unknown', client_key([]), 'no address');
});

// --------------------------------------------------------------------------
//  Кеш LiteSpeed
// --------------------------------------------------------------------------

test('parse_rev_param accepts digits only', function () {
    assert_eq(0, parse_rev_param('0'), 'zero');
    assert_eq(1726000000000, parse_rev_param('1726000000000'), 'ms timestamp');
    foreach ([null, '', ' 1', '1 ', '-1', '+1', '1.5', '1e3', 'abc', ['1'], 12, str_repeat('9', 19)] as $raw) {
        assert_eq(null, parse_rev_param($raw), 'rejects ' . var_export($raw, true));
    }
});

test('only a query in the address the browser sent counts', function () {
    assert_eq(false, request_has_query(['REQUEST_URI' => '/news/42']), 'internally rewritten /news/42');
    assert_eq(true, request_has_query(['REQUEST_URI' => '/tierlist?utm_source=tg']), 'utm');
    assert_eq(true, request_has_query(['REQUEST_URI' => '/?']), 'an empty query still counts');
    assert_eq(false, request_has_query([]), 'no request at all');
});

// Ключ кеша — адрес целиком: страница с любым хвостом легла бы в кеш
// отдельной копией, и флуд случайными адресами заваливал бы его.
test('a page is cacheable only at its clean address', function () {
    assert_true(page_lscache_allowed(['REQUEST_URI' => '/']), 'home');
    assert_true(page_lscache_allowed(['REQUEST_URI' => '/news/42']), 'a post');
    assert_eq(false, page_lscache_allowed(['REQUEST_URI' => '/tierlist?x=1']), 'query');
    assert_eq(false, page_lscache_allowed(['REQUEST_URI' => '/home.php/x', 'PATH_INFO' => '/x']), 'path tail');
});

// --------------------------------------------------------------------------
//  Уборка файлов лимитов
// --------------------------------------------------------------------------

test('rate_gc removes only stale limiter files', function () {
    $dir = sys_get_temp_dir() . '/nxgc_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $old   = "$dir/nexus_rate_old.json";
    $fresh = "$dir/nexus_rate_fresh.json";
    $login = "$dir/nexus_login_old.json";
    $other = "$dir/unrelated.json";
    foreach ([$old, $fresh, $login, $other] as $f) { file_put_contents($f, '[]'); }
    $now = time();
    foreach ([$old, $login, $other] as $f) { touch($f, $now - 2 * 86400); }

    assert_eq(2, rate_gc($dir, $now), 'two stale limiter files');
    assert_eq(false, is_file($old), 'stale rate file removed');
    assert_eq(false, is_file($login), 'stale login file removed');
    assert_true(is_file($fresh), 'fresh file kept');
    assert_true(is_file($other), 'foreign file untouched');

    @unlink($fresh);
    @unlink($other);
    @rmdir($dir);
});

// --------------------------------------------------------------------------
//  Блокировка файла лимита
// --------------------------------------------------------------------------

// Без flock параллельные запросы читали одно и то же состояние и все разом
// проходили лимит. Восемь процессов по десять попыток при лимите 20: пройти
// должны ровно 20.
test('parallel processes cannot overrun a limit', function () {
    $key  = 'par-' . bin2hex(random_bytes(4));
    $boot = var_export(realpath(__DIR__ . '/../public_html/api/_bootstrap.php'), true);
    $k    = var_export($key, true);
    $code = "define('TESTING', 1); require $boot; \$n = 0;"
          . " for (\$i = 0; \$i < 10; \$i++) { if (rate_limit_allow('par', $k, 20, 3600, 1000000)) { \$n++; } }"
          . " echo \$n;";
    $procs = [];
    for ($p = 0; $p < 8; $p++) {
        $pipes = [];
        $h = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$h, $pipes];
    }
    $total = 0;
    foreach ($procs as [$h, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($h);
        assert_eq('', trim($err), 'child stderr');
        $total += (int)$out;
    }
    assert_eq(20, $total, 'exactly the limit got through');
    @unlink(rate_file('par', $key));
});

run_tests();
