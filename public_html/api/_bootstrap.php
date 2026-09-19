<?php
// Shared bootstrap. Requiring this declares functions only — no side effects,
// so tests can include it safely (they define TESTING and inject their own PDO).

if (!defined('CONFIG_PATH')) {
    // Production: override by defining CONFIG_PATH before including this file
    // (e.g. to a path above webroot). Default assumes repo layout.
    define('CONFIG_PATH', __DIR__ . '/../../config.php');
}

function app_config(): array {
    static $cfg = null;
    if ($cfg === null) { $cfg = require CONFIG_PATH; }
    return $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config();
        $pdo = new PDO($c['dsn'], $c['db_user'], $c['db_pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function read_json_body(): array {
    // Tests inject via $GLOBALS['__RAW_BODY__']; live reads php://input.
    $raw = $GLOBALS['__RAW_BODY__'] ?? file_get_contents('php://input');
    if ($raw === '' || $raw === false) { return []; }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// Строгий разбор id из тела запроса: настоящий int или строка из одних цифр.
// (int)$x — то, что было раньше в news_delete.php/news_save.php — молча
// приводит "1abc" к 1, true к 1, [1,2,3] к 1 и 5.7 к 5, то есть превращает
// мусор в валидный положительный id. filter_var(..., FILTER_VALIDATE_INT) тут
// не годится: он сам принимает "-5"/"+5" и ведущие/хвостовые пробелы, а нам
// нужен отказ на любой не-цифровой символ — поэтому явная пара is_int/
// ctype_digit. Возвращает 0 на любой мусор, и вызывающий код продолжает
// пользоваться уже существующей проверкой `$id <= 0` → 400.
function read_row_id(array $body): int {
    $raw = $body['id'] ?? null;
    if (is_int($raw)) { return $raw; }
    if (is_string($raw) && $raw !== '' && ctype_digit($raw)) { return (int)$raw; }
    return 0;
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Единственная PHP-сессия сайта: в ней и админский флаг, и вошедший через
// Roblox посетитель (api/roblox_callback.php). Кука одна — и функция одна.
//
// SameSite=Lax здесь не просто «по умолчанию безопасно»: возврат с
// roblox.com — это переход верхнего уровня по GET, и именно Lax пропускает
// на нём куку. Со Strict сессия на возврате оказалась бы пустой, и вход не
// доходил бы до конца.
function start_site_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Прежнее имя той же сессии. Оставлено как есть: его зовут все админские
// эндпоинты, и переименование ничего бы не улучшило.
function start_admin_session(): void { start_site_session(); }

// Пришла ли с запросом кука сессии.
function site_session_cookie_present(array $cookies): bool {
    $v = $cookies[session_name()] ?? '';
    return is_string($v) && $v !== '';
}

// Продолжить сессию, если посетитель её уже заводил, и ничего не делать, если
// нет. session_start() без куки не находит сессию, а заводит новую: пишет
// файл в каталог сессий и отвечает Set-Cookie. Для анонимного запроса это
// чистый расход — по файлу на запрос, и флуд такими запросами копит их
// тысячами в счёт лимита тарифа (400 000 inode на всё). А ответ с Set-Cookie
// LiteSpeed вдобавок не кладёт в свой кеш.
//
// true — сессия открыта; false — куки нет, $_SESSION пустой.
function resume_site_session(): bool {
    if (session_status() === PHP_SESSION_ACTIVE) { return true; }
    if (!site_session_cookie_present($_COOKIE)) {
        $_SESSION = [];
        return false;
    }
    start_site_session();
    return true;
}

function is_admin(): bool { return !empty($_SESSION['admin']); }

// Без куки админской сессии быть не может — отказ сразу, без пустой сессии
// под каждый анонимный запрос.
function require_admin(): void {
    if (!resume_site_session() || !is_admin()) { json_out(['error' => 'unauthorized'], 401); exit; }
}

// State-changing endpoints must be POST. Without this a bare GET — an <img>
// tag on someone else's page, a link-preview crawler, a scanner walking the
// URLs visible in app.js — would mutate data.
function require_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['error' => 'method_not_allowed'], 405);
        exit;
    }
}

// ---------------------------------------------------------------------------
//  Адрес посетителя — для лимитов частоты и троттлинга входа.
//
//  Пока сайт отвечает сам, это REMOTE_ADDR. Если поставить его за Cloudflare
//  (так хостер предлагает спасаться от DDoS), REMOTE_ADDR станет адресом
//  узла Cloudflare: все посетители окажутся в одной корзине, и лимит лайков
//  (20 в час на адрес) кончится у всего сайта разом. Настоящий адрес
//  Cloudflare передаёт в CF-Connecting-IP. Верить заголовку можно только в
//  запросе, пришедшем с адреса Cloudflare, — иначе любой впишет туда что
//  угодно и обойдёт лимиты. Если LiteSpeed сам подставит настоящий адрес в
//  REMOTE_ADDR, тот уже не из диапазонов Cloudflare, и заголовок просто не
//  читается.
//
//  Диапазоны — https://www.cloudflare.com/ips/ на 2026-09-18. Меняются редко.
//  Устаревший список ничего не ломает: заголовок перестанет читаться только
//  для запросов с новых адресов.
// ---------------------------------------------------------------------------
const CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

// Входит ли адрес в сеть вида "a.b.c.d/n" или "x:y::/n". Адрес одного
// семейства в сети другого не входит никогда.
function ip_in_cidr(string $ip, string $cidr): bool {
    $parts  = explode('/', $cidr, 2);
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton($parts[0]);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) { return false; }
    $max  = strlen($ipBin) * 8;
    $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : $max;
    if ($bits > $max) { return false; }
    $full = intdiv($bits, 8);
    if (substr($ipBin, 0, $full) !== substr($netBin, 0, $full)) { return false; }
    $rest = $bits % 8;
    if ($rest === 0) { return true; }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($ipBin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
}

function from_cloudflare(string $ip): bool {
    foreach (CLOUDFLARE_RANGES as $range) {
        if (ip_in_cidr($ip, $range)) { return true; }
    }
    return false;
}

// Настоящий адрес посетителя. $server — для тестов, по умолчанию $_SERVER.
function client_ip(?array $server = null): string {
    $server = $server ?? $_SERVER;
    $remote = trim((string)($server['REMOTE_ADDR'] ?? ''));
    $cf     = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) !== false && from_cloudflare($remote)) {
        return $cf;
    }
    return $remote !== '' ? $remote : 'unknown';
}

// Ключ посетителя для лимитов. IPv4 — сам адрес. IPv6 — его сеть /64:
// абоненту выдают целую /64, и лимит «на адрес» обходился бы перебором
// адресов внутри неё. У самого сайта IPv6 нет, но за Cloudflare посетители
// приходят и по нему.
function client_key(?array $server = null): string {
    $ip  = client_ip($server);
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) { return $ip; }
    // ::ffff:a.b.c.d — тот же IPv4, записанный в виде IPv6.
    if (substr($bin, 0, 12) === str_repeat(chr(0), 10) . chr(255) . chr(255)) {
        return (string)inet_ntop(substr($bin, 12));
    }
    return inet_ntop(substr($bin, 0, 8) . str_repeat(chr(0), 8)) . '/64';
}

// ---------------------------------------------------------------------------
//  Файлы состояния лимитов: прочитать и переписать под блокировкой.
//  Без flock параллельные запросы читали одно и то же состояние и все разом
//  проходили лимит — ровно тот случай, ради которого лимит и стоит.
// ---------------------------------------------------------------------------

// $fn получает прочитанное (массив или null, если файла нет или он битый) и
// возвращает [результат, что записать]; null вместо данных — ничего не
// писать. Ошибка ввода-вывода отдаёт $onError: сломанный временный каталог
// не должен ронять сайт.
function json_state_update(string $path, callable $fn, $onError) {
    $h = @fopen($path, 'c+');
    if ($h === false) { return $onError; }
    try {
        if (!flock($h, LOCK_EX)) { return $onError; }
        $raw  = stream_get_contents($h);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        [$result, $next] = $fn(is_array($data) ? $data : null);
        if ($next !== null) {
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, json_encode($next));
            fflush($h);
        }
        return $result;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// Чтение под разделяемой блокировкой: иначе можно попасть между усечением
// файла и записью и принять пустой файл за «ограничений нет».
function json_state_read(string $path): ?array {
    $h = @fopen($path, 'r');
    if ($h === false) { return null; }
    try {
        if (!flock($h, LOCK_SH)) { return null; }
        $raw  = stream_get_contents($h);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

// ---------------------------------------------------------------------------
//  Login throttle — brute-force guard keyed by client IP.
//  State is a small JSON file in the system temp dir: no DB table, works on
//  any shared host. Failures escalate into a timed lockout.
// ---------------------------------------------------------------------------
function throttle_file(string $key): string {
    return rtrim(sys_get_temp_dir(), "/\\") . '/nexus_login_' . sha1($key) . '.json';
}

function throttle_read(string $key): array {
    $d = json_state_read(throttle_file($key));
    return [
        'fails' => (int)($d['fails'] ?? 0),
        'until' => (int)($d['until'] ?? 0),
    ];
}

// Seconds the caller must wait before another attempt is accepted (0 = now).
function throttle_retry_after(string $key, int $now): int {
    $s = throttle_read($key);
    return max(0, $s['until'] - $now);
}

// Record a failed attempt; locks out once $maxFails is reached. Returns the
// new failure count. A lock that has already expired resets the counter, so a
// later typo costs one attempt rather than an instant re-lock.
function throttle_register_failure(string $key, int $now, int $maxFails = 5, int $lockSeconds = 300): int {
    return json_state_update(throttle_file($key), function ($s) use ($now, $maxFails, $lockSeconds) {
        $prevUntil = (int)($s['until'] ?? 0);
        $expired = $prevUntil > 0 && $prevUntil <= $now;
        $fails = $expired ? 1 : (int)($s['fails'] ?? 0) + 1;
        $until = $fails >= $maxFails ? $now + $lockSeconds : 0;
        return [$fails, ['fails' => $fails, 'until' => $until]];
    }, 1);
}

function throttle_clear(string $key): void {
    $f = throttle_file($key);
    if (is_file($f)) { @unlink($f); }
}

// ---------------------------------------------------------------------------
//  Sliding-window rate limit — caps how often one client may hit an endpoint
//  (the login throttle above punishes FAILURES; this caps raw frequency).
//  Same storage approach: a JSON file per bucket+key in the temp dir, so it
//  needs no DB table and works on any shared host. Failing open on file I/O
//  errors is deliberate — a broken temp dir should not take the feature down.
// ---------------------------------------------------------------------------
function rate_file(string $bucket, string $key): string {
    return rtrim(sys_get_temp_dir(), "/\\") . '/nexus_rate_' . sha1($bucket . '|' . $key) . '.json';
}

// True → allowed (and the hit is recorded); false → over the limit.
function rate_limit_allow(string $bucket, string $key, int $max, int $windowSeconds, int $now): bool {
    rate_gc_sometimes();
    return json_state_update(rate_file($bucket, $key), function ($hits) use ($max, $windowSeconds, $now) {
        // keep only hits still inside the window
        $cutoff = $now - $windowSeconds;
        $hits = array_values(array_filter($hits ?? [], function ($t) use ($cutoff) {
            return is_int($t) && $t > $cutoff;
        }));
        if (count($hits) >= $max) { return [false, null]; }
        $hits[] = $now;
        return [true, $hits];
    }, true);
}

// Уборка: файлы лимитов и троттлинга, которых не трогали сутки. Окна у всех
// лимитов — не больше часа, такой файл уже ничего не помнит. Раньше на каждый
// новый адрес файл оставался навсегда, и флуд с тысяч адресов копил бы их в
// счёт лимита inode.
const RATE_FILE_MAX_AGE = 86400;

function rate_gc(string $dir, int $now, int $maxAge = RATE_FILE_MAX_AGE): int {
    $dir = rtrim($dir, "/\\");
    $files = array_merge(
        glob($dir . '/nexus_rate_*.json') ?: [],
        glob($dir . '/nexus_login_*.json') ?: []
    );
    $removed = 0;
    foreach ($files as $f) {
        $m = @filemtime($f);
        if ($m !== false && $m < $now - $maxAge && @unlink($f)) { $removed++; }
    }
    return $removed;
}

// Раз примерно на 500 обращений: обход каталога дороже самой проверки лимита,
// делать его на каждом запросе незачем.
function rate_gc_sometimes(): void {
    if (mt_rand(1, 500) === 1) { rate_gc(sys_get_temp_dir(), time()); }
}

// ---------------------------------------------------------------------------
//  Кеш LiteSpeed (LSCache). CacheEngine у хостера включён, .htaccess включает
//  поиск в кеше (CacheLookup). Ответ, помеченный lscache_public(), LiteSpeed
//  запоминает и дальше отдаёт сам, не запуская PHP. На тарифе 20 процессов
//  PHP и одно ядро, и без кеша каждый запрос к странице или к опросу — это
//  PHP и MySQL: чтобы положить сайт, хватило бы одного компьютера со
//  скриптом.
//
//  Помечать можно только ответы, одинаковые для всех: без сессии, без
//  Set-Cookie, без админских полей. Персональный ответ из кеша получил бы
//  следующий посетитель.
//
//  Заголовок читает только LiteSpeed, браузеру по-прежнему идёт обычный
//  Cache-Control. Вне LiteSpeed (локальный Apache, php -S) он ни на что не
//  влияет.
// ---------------------------------------------------------------------------
function lscache_public(int $seconds): void {
    header('X-LiteSpeed-Cache-Control: public, max-age=' . $seconds);
}

function lscache_off(): void {
    header('X-LiteSpeed-Cache-Control: no-cache');
}

// Был ли в адресе запроса «?». Внутренний рероут из .htaccess
// (/news/42 → news.php?id=42) не считается: REQUEST_URI хранит адрес таким,
// каким его прислал браузер.
function request_has_query(?array $server = null): bool {
    $uri = (string)(($server ?? $_SERVER)['REQUEST_URI'] ?? '');
    return strpos($uri, '?') !== false;
}

// Сколько секунд LiteSpeed держит публичную страницу.
const PAGE_LSCACHE_SECONDS = 60;

// Страницы одинаковы для всех: вход и прочее личное шапка узнаёт отдельным
// запросом к api/session.php, а он не кешируется. Поэтому страницу можно
// держать в кеше минуту — после публикации или деплоя свежая дойдёт до
// посетителя не позже чем через минуту с небольшим.
//
// Адрес с параметрами (?utm_source=…, ?signin, мусор) или с хвостом после
// .php в кеш не кладётся: ключ кеша — адрес целиком, и флуд случайными
// адресами завалил бы кеш тысячами копий одной и той же страницы.
function page_lscache_allowed(?array $server = null): bool {
    $server = $server ?? $_SERVER;
    return !defined('NX_ADMIN_RENDER') && !request_has_query($server) && empty($server['PATH_INFO']);
}

function page_lscache(): void {
    if (page_lscache_allowed()) { lscache_public(PAGE_LSCACHE_SECONDS); }
}

// Номер ревизии из ?rev=: только цифры, иначе null.
function parse_rev_param($raw): ?int {
    if (!is_string($raw) || $raw === '' || strlen($raw) > 18 || !ctype_digit($raw)) { return null; }
    return (int)$raw;
}
