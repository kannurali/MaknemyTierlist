<?php
// «Запомнить вход». Без этого вход держался ровно столько, сколько PHP-сессия:
// её кука живёт до закрытия браузера, а файл сессии хостинг стирает после
// ~24 минут простоя. Человек, вернувшийся на сайт завтра, входил через Roblox
// заново.
//
// Отдельная долгая кука nx_remember = «selector.validator»:
//   selector  — открытый номер строки в login_tokens;
//   validator — секрет. В базе лежит только его sha256, так что утёкшая
//               таблица не даёт войти ни под кем.
// Когда сессии нет (или она пустая), start_site_session() находит строку по
// selector, сверяет хеш и кладёт в свежую сессию user_id — как после входа.
//
// Серверная строка, а не подписанная кука: выход гасит ключ на сервере, и
// украденная кука после выхода ничего не открывает. Это важно с тех пор, как
// админы входят через тот же Roblox (site_role() в _bootstrap.php).
//
// Срок скользящий: каждое восстановление, не чаще раза в сутки, продлевает
// ключ на REMEMBER_TTL. Кто заходит хотя бы раз в полгода, не входит заново
// никогда.
//
// Без таблицы (не выполнена миграция 2026-09-24-login-tokens.sql) всё
// работает как раньше: ключ не выдаётся, вход живёт до закрытия браузера.

const REMEMBER_COOKIE = 'nx_remember';
const REMEMBER_TTL    = 180 * 86400;
const REMEMBER_RENEW  = 86400;
// Столько устройств одного человека помнится разом. Больше — самые старые
// ключи гаснут: вход на чужом компьютере без выхода не копится вечно.
const REMEMBER_MAX_PER_USER = 10;

/** [selector, validator] из значения куки, либо null на любой мусор. */
function remember_parse($raw): ?array {
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{24})\.([a-f0-9]{64})\z/', $raw, $m)) {
        return null;
    }
    return [$m[1], $m[2]];
}

/**
 * Новый ключ для $userId. Возвращает значение куки.
 *
 * Здесь же уборка: истёкшие ключи всех пользователей и лишние ключи этого.
 * Выдача случается только при входе через Roblox, так что это редкий запрос.
 */
function remember_issue(PDO $pdo, string $userId, int $now): string {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));

    $pdo->prepare('DELETE FROM login_tokens WHERE expires_at < :now')->execute([':now' => $now]);
    $pdo->prepare(
        'INSERT INTO login_tokens (selector, token_hash, user_id, created_at, expires_at)
         VALUES (:s, :h, :u, :c, :e)'
    )->execute([
        ':s' => $selector, ':h' => hash('sha256', $validator), ':u' => $userId,
        ':c' => $now, ':e' => $now + REMEMBER_TTL,
    ]);

    $st = $pdo->prepare('SELECT selector FROM login_tokens WHERE user_id = :u ORDER BY created_at DESC, selector');
    $st->execute([':u' => $userId]);
    $extra = array_slice($st->fetchAll(PDO::FETCH_COLUMN), REMEMBER_MAX_PER_USER);
    $del = $pdo->prepare('DELETE FROM login_tokens WHERE selector = :s');
    foreach ($extra as $s) { $del->execute([':s' => $s]); }

    return $selector . '.' . $validator;
}

/**
 * Кто стоит за кукой. null — ключа нет, он чужой или истёк.
 * renew — срок продлён, куку надо выдать заново с новой датой.
 *
 * Validator не меняется при восстановлении нарочно: две вкладки, открытые
 * разом после перезапуска браузера, пришли бы с одним и тем же ключом, и та,
 * что опоздала, выкинула бы человека из аккаунта.
 */
function remember_check(PDO $pdo, $raw, int $now): ?array {
    $parts = remember_parse($raw);
    if ($parts === null) { return null; }
    [$selector, $validator] = $parts;

    $st = $pdo->prepare('SELECT token_hash, user_id, expires_at FROM login_tokens WHERE selector = :s');
    $st->execute([':s' => $selector]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
        return null;
    }
    $expires = (int)$row['expires_at'];
    if ($expires <= $now) {
        $pdo->prepare('DELETE FROM login_tokens WHERE selector = :s')->execute([':s' => $selector]);
        return null;
    }

    $renew = $expires < $now + REMEMBER_TTL - REMEMBER_RENEW;
    if ($renew) {
        $expires = $now + REMEMBER_TTL;
        $pdo->prepare('UPDATE login_tokens SET expires_at = :e WHERE selector = :s')
            ->execute([':e' => $expires, ':s' => $selector]);
    }
    return ['user_id' => (string)$row['user_id'], 'expires' => $expires, 'renew' => $renew];
}

/** Погасить ключ из куки. Чужой или битый ключ — ничего не делать. */
function remember_revoke(PDO $pdo, $raw): void {
    $parts = remember_parse($raw);
    if ($parts === null) { return; }
    $pdo->prepare('DELETE FROM login_tokens WHERE selector = :s')->execute([':s' => $parts[0]]);
}

// ---------------------------------------------------------------------------
//  Кука и запрос. Отдельно от чистых функций выше: тесты сюда не заходят.
// ---------------------------------------------------------------------------

function remember_cookie_present(array $cookies): bool {
    $v = $cookies[REMEMBER_COOKIE] ?? '';
    return is_string($v) && $v !== '';
}

// Параметры те же, что у куки сессии (start_site_session()), и по той же
// причине Lax: первый заход на сайт после перезапуска браузера часто идёт по
// ссылке с другого сайта, и со Strict кука на нём не пришла бы.
function remember_cookie_send(string $value, int $expires): void {
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
        'samesite' => 'Lax',
    ]);
}

function remember_cookie_clear(): void {
    remember_cookie_send('', time() - 42000);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/**
 * user_id по куке этого запроса, либо null. Проверка одна на запрос: её
 * зовут и resume_site_session(), и следом start_site_session().
 *
 * Отказ ошибкой базы куку не трогает: сбой соединения или ещё не созданная
 * таблица не должны разлогинивать всех. Гасится только ключ, которого точно
 * нет или который истёк.
 */
function remember_user(): ?string {
    static $memo = [];
    $raw = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!remember_cookie_present($_COOKIE)) { return null; }
    if (array_key_exists($raw, $memo)) { return $memo[$raw]; }

    try {
        $hit = remember_check(db(), $raw, time());
    } catch (PDOException $e) {
        return $memo[$raw] = null;
    }
    if ($hit === null) {
        remember_cookie_clear();
        return $memo[$raw] = null;
    }
    if ($hit['renew']) { remember_cookie_send($raw, $hit['expires']); }
    return $memo[$raw] = $hit['user_id'];
}

/** Вход состоялся: выдать этому браузеру ключ взамен прежнего. */
function remember_login(string $userId): void {
    try {
        $pdo = db();
        remember_revoke($pdo, $_COOKIE[REMEMBER_COOKIE] ?? null);
        $value = remember_issue($pdo, $userId, time());
    } catch (PDOException $e) {
        return;
    }
    remember_cookie_send($value, time() + REMEMBER_TTL);
    $_COOKIE[REMEMBER_COOKIE] = $value;
}

/** Выход: погасить ключ на сервере и стереть куку. */
function remember_logout(): void {
    if (!remember_cookie_present($_COOKIE)) { return; }
    try {
        remember_revoke(db(), $_COOKIE[REMEMBER_COOKIE]);
    } catch (PDOException $e) {
        // Кука стирается всё равно: этот браузер выйти обязан.
    }
    remember_cookie_clear();
}
