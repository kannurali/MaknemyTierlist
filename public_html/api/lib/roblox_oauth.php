<?php
// Вход через Roblox — официальный OAuth 2.0 / OpenID Connect самого Roblox
// (https://apis.roblox.com/oauth/.well-known/openid-configuration).
//
// Пароль пользователя сайт не видит никогда: логин и 2FA проходят на
// roblox.com, обратно приходит одноразовый code, и по нему сервер сам
// забирает профиль. Своей регистрации с паролем на сайте нет и не будет —
// хранить чужие пароли ради тирлиста незачем.
//
// Файл объявляет только функции: никаких заголовков и редиректов, чтобы
// tests/roblox_auth_test.php мог его подключить и проверить чистую часть
// (сборку authorize-URL, PKCE, разбор claims, upsert) без сети.

const ROBLOX_AUTHORIZE_URL = 'https://apis.roblox.com/oauth/v1/authorize';
const ROBLOX_TOKEN_URL     = 'https://apis.roblox.com/oauth/v1/token';
const ROBLOX_USERINFO_URL  = 'https://apis.roblox.com/oauth/v1/userinfo';

// Больше не просим: openid даёт сам факт входа, profile — ник, имя и аватар.
// email потребовал бы отдельного согласия у каждого входящего, а сайту он
// не нужен. Инвентарь и трейды Roblox через OAuth не отдаёт вовсе — какие
// фрукты у игрока, узнать отсюда нельзя.
const ROBLOX_SCOPE = 'openid profile';

// Сколько ждём Roblox на обмене кода и запросе профиля. Оба запроса стоят
// между кликом «Войти» и возвратом на сайт, поэтому лимит жёсткий: лучше
// показать «попробуйте ещё раз», чем держать соединение открытым минуту.
const ROBLOX_HTTP_TIMEOUT = 8;

/**
 * Настройки приложения из config.php. Пустой массив = вход выключен:
 * пока в конфиге нет client_id/secret, api/roblox_start.php отвечает 503,
 * а шапка не показывает кнопку входа (см. api/session.php). Сайт при этом
 * работает целиком — ровно как promo.php без своей таблицы.
 */
function roblox_oauth_config(array $cfg): array {
    $id     = trim((string)($cfg['roblox_client_id'] ?? ''));
    $secret = trim((string)($cfg['roblox_client_secret'] ?? ''));
    $redir  = trim((string)($cfg['roblox_redirect_uri'] ?? ''));
    if ($id === '' || $secret === '' || $redir === '') { return []; }
    return ['client_id' => $id, 'client_secret' => $secret, 'redirect_uri' => $redir];
}

function roblox_oauth_enabled(array $cfg): bool {
    return roblox_oauth_config($cfg) !== [];
}

/** Случайная строка в base64url — годится и для state, и для code_verifier. */
function roblox_random_token(int $bytes = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * PKCE S256: challenge = base64url(sha256(verifier)) от СЫРЫХ байтов хеша.
 *
 * PKCE здесь при том, что client_secret у нас есть и приложение
 * конфиденциальное: без него перехваченный code из адресной строки (история
 * браузера, реферер, чужое расширение) можно обменять с любого клиента,
 * знающего secret. С ним украденный code бесполезен без verifier, который
 * лежит только в серверной сессии и по сети не ходит.
 */
function roblox_code_challenge(string $verifier): string {
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** Адрес страницы согласия Roblox, куда уходит браузер с /api/roblox_start.php. */
function roblox_authorize_url(array $oauth, string $state, string $verifier): string {
    return ROBLOX_AUTHORIZE_URL . '?' . http_build_query([
        'client_id'             => $oauth['client_id'],
        'redirect_uri'          => $oauth['redirect_uri'],
        'response_type'         => 'code',
        'scope'                 => ROBLOX_SCOPE,
        'state'                 => $state,
        'code_challenge'        => roblox_code_challenge($verifier),
        'code_challenge_method' => 'S256',
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Куда вернуть человека после входа. Принимаем только внутренний путь сайта:
 * без этой проверки ссылку /api/roblox_start.php?return=https://зло.example
 * можно разослать как «вход на maknemy», и сайт сам увёз бы пользователя на
 * чужую страницу уже после успешного логина — классический open redirect.
 *
 * Отбрасываем и «//host» (браузер читает это как protocol-relative адрес, то
 * есть тоже чужой домен), и «/\host» — Chrome трактует обратный слэш как
 * обычный. Переводы строк — чтобы значение не дописало свой заголовок в
 * ответ Location.
 */
function roblox_safe_return(?string $path): string {
    $p = (string)$path;
    if ($p === '' || $p[0] !== '/') { return '/'; }
    if (strlen($p) > 1 && ($p[1] === '/' || $p[1] === '\\')) { return '/'; }
    if (strpbrk($p, "\r\n") !== false) { return '/'; }
    if (strlen($p) > 200) { return '/'; }
    return $p;
}

/**
 * Дописать к внутреннему пути метку результата входа — по ней шапка
 * показывает сообщение и тут же убирает параметр из адреса (js/topbar.js).
 * Отдельная функция, потому что путь может уже нести свой query.
 */
function roblox_with_flag(string $path, string $flag): string {
    return $path . (strpos($path, '?') === false ? '?' : '&') . 'login=' . $flag;
}

/**
 * Аватарку кладём в <img src>, поэтому берём её только с доменов Roblox.
 * userinfo сейчас всегда отдаёт rbxcdn, но claim — это данные извне, и
 * подставлять их в разметку без проверки нельзя: чужой src дал бы внешнему
 * домену пинг с каждой загрузки шапки.
 */
function roblox_avatar_ok(string $url): bool {
    if ($url === '' || strlen($url) > 255) { return false; }
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') { return false; }
    $host = strtolower((string)($parts['host'] ?? ''));
    // substr вместо str_ends_with: на хостинге может стоять PHP 7.4, где
    // этой функции ещё нет, и весь вход упал бы на первом же аватаре.
    foreach (['rbxcdn.com', 'roblox.com'] as $suffix) {
        $dotted = '.' . $suffix;
        if ($host === $suffix || substr($host, -strlen($dotted)) === $dotted) { return true; }
    }
    return false;
}

/**
 * Профиль из claims userinfo. null — если ответ не тот, что ожидаем:
 * пускать внутрь запись без внятного id нельзя, это и есть личность юзера.
 *
 * sub у Roblox — это userId строкой из одних цифр. Проверяем ctype_digit,
 * а не (int): "12abc" молча стал бы юзером 12, то есть чужим аккаунтом.
 */
function roblox_profile_from_claims(array $claims): ?array {
    $sub = $claims['sub'] ?? null;
    if (!is_string($sub) || $sub === '' || !ctype_digit($sub) || strlen($sub) > 19) {
        return null;
    }
    $username = (string)($claims['preferred_username'] ?? '');
    // nickname — это display name; если его нет, показываем ник.
    $display  = (string)($claims['nickname'] ?? $claims['name'] ?? '');
    if ($display === '') { $display = $username; }
    $avatar   = (string)($claims['picture'] ?? '');
    if (!roblox_avatar_ok($avatar)) { $avatar = ''; }

    return [
        'roblox_id'    => $sub,
        'username'     => mb_substr($username, 0, 64),
        'display_name' => mb_substr($display, 0, 64),
        'avatar_url'   => $avatar,
    ];
}

/** Ссылка на профиль игрока. Не храним в базе — она выводится из id. */
function roblox_profile_url(string $robloxId): string {
    return 'https://www.roblox.com/users/' . $robloxId . '/profile';
}

/**
 * Запомнить вошедшего. Ник и аватар перезаписываем на каждом входе: человек
 * мог сменить и то и другое в Roblox, а шапка обязана показывать текущее.
 *
 * Upsert разветвлён в PHP, а не написан одним запросом: ON DUPLICATE KEY
 * UPDATE (MySQL) и ON CONFLICT DO UPDATE (SQLite) непереносимы, а набор
 * тестов ходит по SQLite — ровно та же причина, по которой так сделано в
 * api/promo.php.
 */
function roblox_touch_user(PDO $pdo, array $profile, int $now): void {
    $sel = $pdo->prepare("SELECT roblox_id FROM users WHERE roblox_id = :id");
    $sel->execute([':id' => $profile['roblox_id']]);
    $exists = $sel->fetchColumn();

    if ($exists === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
             VALUES (:id, :u, :d, :a, :c, :l)"
        );
        $stmt->execute([
            ':id' => $profile['roblox_id'], ':u' => $profile['username'],
            ':d'  => $profile['display_name'], ':a' => $profile['avatar_url'],
            ':c'  => $now, ':l' => $now,
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        "UPDATE users SET username = :u, display_name = :d, avatar_url = :a, last_login_at = :l
         WHERE roblox_id = :id"
    );
    $stmt->execute([
        ':id' => $profile['roblox_id'], ':u' => $profile['username'],
        ':d'  => $profile['display_name'], ':a' => $profile['avatar_url'], ':l' => $now,
    ]);
}

/** Строка пользователя для api/session.php. null — если запись пропала. */
function roblox_load_user(PDO $pdo, string $robloxId): ?array {
    $stmt = $pdo->prepare(
        "SELECT roblox_id, username, display_name, avatar_url FROM users WHERE roblox_id = :id"
    );
    $stmt->execute([':id' => $robloxId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    return [
        'id'      => (string)$row['roblox_id'],
        'name'    => (string)$row['username'],
        'display' => (string)$row['display_name'],
        'avatar'  => (string)$row['avatar_url'],
        'profile' => roblox_profile_url((string)$row['roblox_id']),
    ];
}

// ---------------------------------------------------------------------------
//  Сеть. Отдельно от чистых функций выше: тесты сюда не заходят.
// ---------------------------------------------------------------------------

/** POST form-urlencoded → распарсенный JSON, либо null на любой осечке. */
function roblox_http_post_form(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ROBLOX_HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) { return null; }
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : null;
}

/** GET с Bearer → распарсенный JSON, либо null. */
function roblox_http_get_json(string $url, string $bearer): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $bearer, 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ROBLOX_HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) { return null; }
    $data = json_decode((string)$body, true);
    return is_array($data) ? $data : null;
}

/**
 * Обменять code на access token. Токен нужен ровно на один запрос userinfo
 * и никуда не сохраняется: сайту от Roblox нужна только личность в момент
 * входа, а не постоянный доступ к аккаунту. Нечего хранить — нечего и
 * утекать при взломе базы; refresh_token мы по той же причине выбрасываем.
 */
function roblox_exchange_code(array $oauth, string $code, string $verifier): ?string {
    $res = roblox_http_post_form(ROBLOX_TOKEN_URL, [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'code_verifier' => $verifier,
        'client_id'     => $oauth['client_id'],
        'client_secret' => $oauth['client_secret'],
        'redirect_uri'  => $oauth['redirect_uri'],
    ]);
    $token = $res['access_token'] ?? null;
    return (is_string($token) && $token !== '') ? $token : null;
}

/** Профиль вошедшего по access token. */
function roblox_fetch_profile(string $accessToken): ?array {
    $claims = roblox_http_get_json(ROBLOX_USERINFO_URL, $accessToken);
    return $claims === null ? null : roblox_profile_from_claims($claims);
}
