<?php
require_once __DIR__ . '/roblox_oauth.php';

// Профиль игрока — общая часть для api/profile-stats.php и api/profile-about.php.
//
// Своей таблицы пользователей профиль не заводит. Личность берётся из той же
// сессии, что и в шапке: вход через Roblox (api/roblox_cb.php) кладёт в
// $_SESSION['user_id'] roblox_id, а запись о человеке лежит в users. Второй
// список людей означал бы два ответа на вопрос «кто это»: ник в шапке и ник в
// профиле разъехались бы при первой же смене имени в Roblox.

// Через сколько секунд молчания считаем, что человек ушёл. То же окно, что у
// чата: статус выводится из last_login_at, который пишет roblox_touch_user()
// при каждом входе, и врать не может. Хранимой колонки status нет намеренно —
// её пришлось бы кому-то проставлять и вовремя сбрасывать.
const PROFILE_ONLINE_WINDOW = 300;

// Предел «о себе». 280 — не круглое число ради круглого: строка кладётся в
// VARCHAR(280) и должна помещаться в него целиком после обрезки.
const PROFILE_ABOUT_MAX = 280;

/**
 * roblox_id вошедшего, либо пустая строка. Единственное место, где код
 * профиля узнаёт, чей это профиль: другого источника личности нет, и
 * подставить чужой id параметром запроса нельзя.
 *
 * Проверка строгая, хотя значение и кладёт наш же обработчик входа: сессия
 * переживает выкладки, а roblox_id уходит в запросы к базе.
 */
function profile_me(array $session): string {
    $raw = $session['user_id'] ?? '';
    // Проверка типа, а не приведение. Массив в сессии (испорченная запись,
    // чужой код, положивший туда список) дал бы «Array to string conversion»
    // ПЕРЕД телом ответа, после чего JSON уже не разбирается. А true молча
    // превратился бы в '1' — то есть в пользователя с roblox_id 1, вполне
    // существующий номер. Личность — не то место, где уместны приведения.
    if (!is_string($raw) && !is_int($raw)) { return ''; }
    $id = (string)$raw;
    return preg_match('/^\d{1,20}\z/', $id) === 1 ? $id : '';
}

/**
 * Карточка профиля из users. null — записи нет (сессия пережила удаление
 * пользователя), и вызывающий обязан отнестись к этому как к «не вошёл».
 *
 * SELECT * здесь осознанный, а не ленивый. Колонки likes/dislikes приезжают
 * миграцией чатов, about — миграцией профиля, и на боевой базе может не быть
 * ни тех ни другой. Перечисленный список колонок уронил бы запрос целиком,
 * тогда как звёздочка отдаёт то, что есть, а отсутствие лишнего читается
 * обычной проверкой isset. Таблица узкая, лишних данных не приедет.
 */
function profile_card(PDO $pdo, string $me, int $now): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE roblox_id = :id');
    $stmt->execute([':id' => $me]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }

    $nick = trim((string)($row['display_name'] ?? ''));
    $name = trim((string)($row['username'] ?? ''));
    if ($nick === '') { $nick = $name; }

    // Аватар проверяем повторно, хотя roblox_touch_user() кладёт уже
    // проверенный: строка уходит в <img src>, а база — не то место, которому
    // стоит верить на слово при выводе в разметку.
    $avatar = (string)($row['avatar_url'] ?? '');
    if (!roblox_avatar_ok($avatar)) { $avatar = ''; }

    $seen = (int)($row['last_login_at'] ?? 0);

    return [
        'nick'     => $nick !== '' ? $nick : null,
        'handle'   => $name !== '' ? '@' . $name : null,
        'avatar'   => $avatar !== '' ? $avatar : null,
        'about'    => profile_about_show($row),
        'status'   => ($seen > 0 && ($now - $seen) <= PROFILE_ONLINE_WINDOW) ? 'online' : 'offline',
        'likes'    => (int)($row['likes'] ?? 0),
        'dislikes' => (int)($row['dislikes'] ?? 0),
        'roblox'   => roblox_profile_url($me),
    ];
}

/** «О себе» из строки users, либо null: колонки может не быть, а пустую строку показывать нечем. */
function profile_about_show(array $row): ?string {
    $about = trim((string)($row['about'] ?? ''));
    return $about !== '' ? $about : null;
}

/**
 * Привести присланный текст к тому, что можно хранить и показывать.
 *
 * Переводы строк схлопываем: карточка выводит «о себе» одним абзацем, и
 * пятьдесят пустых строк растянули бы её на весь экран. Управляющие символы
 * убираем целиком — в тексте о себе им делать нечего, а в логах и заголовках
 * они умеют неприятное.
 *
 * Обрезаем mb_substr по СИМВОЛАМ, а не substr по байтам: кириллица в utf8mb4
 * занимает два байта, и байтовая обрезка разрубила бы букву пополам.
 */
function profile_about_clean(string $raw): string {
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw);
    if ($text === null) { return ''; }          // строка не в UTF-8 — считаем пустой
    $text = preg_replace('/\s*\R\s*/u', ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', (string)$text);
    $text = trim((string)$text);
    return mb_substr($text, 0, PROFILE_ABOUT_MAX);
}

/**
 * Сохранить «о себе». false — колонки about в базе нет (миграция профиля не
 * выполнена): это не ошибка кода, и отвечать на неё пятисоткой нельзя, но и
 * молчать об этом тоже — иначе страница покажет «сохранено», а текст пропадёт.
 */
function profile_about_save(PDO $pdo, string $me, string $about): bool {
    try {
        $stmt = $pdo->prepare('UPDATE users SET about = :a WHERE roblox_id = :id');
        $stmt->execute([':a' => $about === '' ? null : $about, ':id' => $me]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
