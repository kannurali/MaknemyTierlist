<?php
// Чаты: общая логика для api/chat.php, api/chat_send.php и api/chat_review.php.
//
// Вынесено в lib по той же причине, что og.php и validate.php: три эндпоинта
// делают разные вещи с одними и теми же правилами доступа, и дублировать
// проверку «я участник этого диалога» в трёх местах — верный способ однажды
// поправить её в двух.
//
// Кто пишет и кому — берётся из настоящей сессии Roblox (api/session.php,
// api/lib/roblox_oauth.php). Своей таблицы пользователей чат не заводит:
// users уже есть, ключ там roblox_id, и второй список людей означал бы два
// ответа на вопрос «кто это».

// Границы. Сообщение длиннее — отвергается; без потолка одна вставка может
// занять сколько угодно места в TEXT.
const CHAT_BODY_MAX   = 2000;
const CHAT_REVIEW_MAX = 500;

// Сколько сообщений отдаём за раз. Диалог может быть длинным, а страница
// рисует их все — без предела один старый чат съел бы и память, и трафик.
const CHAT_PAGE_SIZE = 200;

// Оценка → репутация. На профиле две иконки, целое сердце и разбитое, то есть
// шкала там двоичная, а в чате пять звёзд. Тройка не идёт никуда: это «ни то
// ни сё», и записывать её в минус было бы несправедливо к собеседнику.
const CHAT_STARS_LIKE    = 4;   // 4 и 5 — в плюс
const CHAT_STARS_DISLIKE = 2;   // 1 и 2 — в минус

// Через сколько секунд молчания человек перестаёт считаться «в сети».
// Отсчёт идёт от ПРИСУТСТВИЯ — users.last_seen_at, отметки, которую ставит
// запрос состояния из шапки на каждой странице. Не от last_login_at: вход
// пишется ровно один раз, а сессия живёт долго, и по нему собеседник, который
// прямо сейчас читает переписку, выглядел бы ушедшим.
//
// Колонку last_seen_at заводит не чат: она приходит вместе со страницей
// профиля, и на боевой базе её может ещё не быть. Чат её только читает и
// переживает отсутствие — до миграции статус считается по входу, как раньше.
//
// Хранимой колонки status нет намеренно: её пришлось бы кому-то проставлять и
// вовремя сбрасывать, а забытое «в сети» врало бы неделями.
const CHAT_ONLINE_WINDOW = 300;

/**
 * Кто сейчас пишет. Roblox-идентификатор строкой или '' — «никто».
 *
 * Читается из той же сессии, что и api/session.php: ключ user_id ставит
 * api/roblox_callback.php после успешного входа. Строка, а не int: id у
 * Roblox 64-битный, и таскать его числом через json/сравнения незачем.
 *
 * Проверка на цифры обязательна. Значение уходит в запросы параметром, но
 * ещё и сравнивается с id участников ветки — а нестрогое сравнение '0' с
 * чем угодно однажды подарило бы доступ.
 */
function chat_me(array $session): string {
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

// Есть ли таблицы чата. На бою их нет до запуска миграции — это штатное
// состояние, а не ошибка: страница по нему рисует пустой чат. Тот же приём,
// что у api/promo.php.
function chat_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM chat_threads LIMIT 1');
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Строка users → то, что уезжает наружу.
 *
 * Имена берутся из полей Roblox: display_name показываем, username — это
 * адресная форма (@ник). Статус вычисляется, а не хранится.
 *
 * Берём ПОЗДНЕЕ из присутствия и входа. Вход — тоже присутствие, причём
 * достовернее старой отметки: человек только что стоял у экрана. А колонки
 * last_seen_at может не быть вовсе — тогда остаётся один вход, как и было до
 * появления отметки.
 */
function chat_user_row(?array $r, int $now): ?array {
    if (!$r) { return null; }
    $seen  = (int)($r['last_seen_at'] ?? 0);
    $login = (int)($r['last_login_at'] ?? 0);
    if ($seen < $login) { $seen = $login; }
    return [
        'id'     => (string)$r['roblox_id'],
        'nick'   => (string)($r['display_name'] !== '' ? $r['display_name'] : $r['username']),
        'handle' => '@' . (string)$r['username'],
        'avatar' => (string)($r['avatar_url'] ?? ''),
        'status' => ($seen > 0 && ($now - $seen) <= CHAT_ONLINE_WINDOW) ? 'online' : 'offline',
    ];
}

// Пара всегда упорядочена: a_id < b_id. Иначе диалог (5,9) и (9,5) стали бы
// разными строками, и переписка расползлась бы по двум веткам.
//
// Сравнение ЧИСЛОВОЕ, хотя id ходят строками: '9' > '10' как строки, и пара
// нормализовалась бы по-разному для разных людей.
function chat_pair(string $x, string $y): array {
    return bccomp_safe($x, $y) <= 0 ? [$x, $y] : [$y, $x];
}

// Сравнение двух десятичных строк без bcmath: длина, потом посимвольно.
// bcmath на хостинге может быть не собран, а id длиннее PHP_INT_MAX не
// бывает — но полагаться на это в сравнении не хочется.
function bccomp_safe(string $a, string $b): int {
    $a = ltrim($a, '0') ?: '0';
    $b = ltrim($b, '0') ?: '0';
    if (strlen($a) !== strlen($b)) { return strlen($a) < strlen($b) ? -1 : 1; }
    return strcmp($a, $b);
}

/**
 * Диалоги пользователя, свежие сверху. [] для «никто» и когда таблиц нет.
 */
function chat_threads(PDO $pdo, string $me, int $now): array {
    if ($me === '' || !chat_ready($pdo)) { return []; }

    $st = $pdo->prepare(
        'SELECT id, last_at, a_id, b_id FROM chat_threads
          WHERE a_id = :me OR b_id = :me
       ORDER BY last_at DESC, id DESC'
    );
    $st->execute([':me' => $me]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { return []; }

    // Собеседников и последние сообщения тянем пакетом, а не по запросу на
    // диалог: список из двадцати веток иначе дал бы сорок обращений к базе.
    $peerIds = [];
    foreach ($rows as $r) {
        $peerIds[] = ((string)$r['a_id'] === $me) ? (string)$r['b_id'] : (string)$r['a_id'];
    }
    $peers = chat_users_by_id($pdo, $peerIds, $now);
    $last  = chat_last_messages($pdo, array_column($rows, 'id'));

    $out = [];
    foreach ($rows as $r) {
        $peerId = ((string)$r['a_id'] === $me) ? (string)$r['b_id'] : (string)$r['a_id'];
        $peer   = $peers[$peerId] ?? null;
        if (!$peer) { continue; }   // собеседник ещё не заходил на сайт
        $out[] = [
            'id'     => (int)$r['id'],
            'peer'   => $peer,
            'last'   => $last[(int)$r['id']] ?? null,
            'lastAt' => (int)$r['last_at'],
        ];
    }
    return $out;
}

function chat_users_by_id(PDO $pdo, array $ids, int $now): array {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (!$ids) { return []; }
    // Плейсхолдеры по числу id: подставлять их в текст запроса нельзя даже
    // после проверки на цифры — привычка важнее одного безопасного случая.
    $in = implode(',', array_fill(0, count($ids), '?'));
    // SELECT * , а не перечисление: last_seen_at приезжает отдельной
    // миграцией, и на боевой базе её может ещё не быть — перечисленная колонка
    // уронила бы запрос целиком, то есть выбила бы весь список диалогов.
    // Звёздочка отдаёт то, что есть; наружу всё равно уходит ровно то, что
    // собирает chat_user_row.
    $st = $pdo->prepare("SELECT * FROM users WHERE roblox_id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['roblox_id']] = chat_user_row($r, $now);
    }
    return $out;
}

function chat_last_messages(PDO $pdo, array $threadIds): array {
    $threadIds = array_values(array_unique(array_map('intval', $threadIds)));
    if (!$threadIds) { return []; }
    $in = implode(',', array_fill(0, count($threadIds), '?'));
    // Последнее сообщение каждой ветки берём по MAX(id), а не MAX(created_at):
    // две записи одной секундой дали бы неопределённый порядок, а id растёт
    // строго.
    $st = $pdo->prepare(
        "SELECT m.thread_id, m.body, m.sender_id, m.created_at
           FROM chat_messages m
           JOIN (SELECT thread_id, MAX(id) AS mx FROM chat_messages
                  WHERE thread_id IN ($in) GROUP BY thread_id) t
             ON t.mx = m.id"
    );
    $st->execute($threadIds);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['thread_id']] = [
            'body'   => (string)$r['body'],
            'mine'   => null,   // проставляет вызывающий: он знает, кто «я»
            'sender' => (string)$r['sender_id'],
            'at'     => (int)$r['created_at'],
        ];
    }
    return $out;
}

/**
 * Участник ли пользователь этой ветки.
 *
 * Проверка обязана быть у КАЖДОГО обращения к переписке. Без неё достаточно
 * подобрать номер ветки в адресе, чтобы читать и писать в чужой диалог.
 */
/**
 * Открыть диалог с человеком: вернуть существующий или завести новый.
 * [код, тело] — как у остальных обработчиков.
 *
 * Единственное место, где ветки вообще появляются. До него переписку можно
 * было только читать: ветки заводила лишь сеялка, то есть на бою чат был
 * пуст навсегда.
 *
 * Собеседник обязан существовать в users. Иначе перебором ?to= заводились бы
 * ветки с несуществующими номерами — мусор в базе, который никто не увидит и
 * никто не уберёт.
 *
 * Сам с собой — нет. Пара нормализуется как a_id < b_id (chat_pair), и для
 * такой «пары» обе колонки совпали бы: диалог с собой прошёл бы проверку
 * членства и выглядел бы как обычный, но собеседника в нём нет.
 */
function chat_open(PDO $pdo, string $me, string $peerRaw, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'unauthorized']]; }
    if (!chat_ready($pdo)) { return [503, ['ok' => false, 'error' => 'unavailable']]; }

    $peer = preg_match('/^\d{1,20}\z/', $peerRaw) === 1 ? ltrim($peerRaw, '0') : '';
    if ($peer === '' || $peer === $me) { return [400, ['ok' => false, 'error' => 'bad_peer']]; }

    $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = :id');
    $st->execute([':id' => $peer]);
    if ($st->fetchColumn() === false) { return [404, ['ok' => false, 'error' => 'no_peer']]; }

    [$a, $b] = chat_pair($me, $peer);

    $sel = $pdo->prepare('SELECT id FROM chat_threads WHERE a_id = :a AND b_id = :b');
    $sel->execute([':a' => $a, ':b' => $b]);
    $id = $sel->fetchColumn();
    if ($id !== false) { return [200, ['ok' => true, 'thread' => (int)$id]]; }

    // last_at нулевой: переписки ещё нет, и пустая ветка не должна вытеснять
    // из списка те, где people действительно говорили.
    try {
        $ins = $pdo->prepare('INSERT INTO chat_threads (a_id, b_id, last_at) VALUES (:a, :b, 0)');
        $ins->execute([':a' => $a, ':b' => $b]);
        return [200, ['ok' => true, 'thread' => (int)$pdo->lastInsertId()]];
    } catch (PDOException $e) {
        // Гонка двух вкладок: UNIQUE(a_id, b_id) не дал завести вторую ветку.
        // Это не ошибка — нужная ветка уже есть, её и возвращаем.
        $sel->execute([':a' => $a, ':b' => $b]);
        $id = $sel->fetchColumn();
        if ($id !== false) { return [200, ['ok' => true, 'thread' => (int)$id]]; }
        return [500, ['ok' => false, 'error' => 'failed']];
    }
}

function chat_is_member(PDO $pdo, string $me, int $threadId): bool {
    if ($me === '' || $threadId <= 0) { return false; }
    $st = $pdo->prepare('SELECT 1 FROM chat_threads WHERE id = :t AND (a_id = :me OR b_id = :me)');
    $st->execute([':t' => $threadId, ':me' => $me]);
    return (bool)$st->fetchColumn();
}

function chat_messages(PDO $pdo, string $me, int $threadId): array {
    if (!chat_ready($pdo) || !chat_is_member($pdo, $me, $threadId)) { return []; }
    $st = $pdo->prepare(
        'SELECT id, sender_id, body, created_at FROM chat_messages WHERE thread_id = :t
       ORDER BY id DESC LIMIT ' . (int)CHAT_PAGE_SIZE
    );
    $st->execute([':t' => $threadId]);
    // Тянем свежие, показываем по возрастанию: LIMIT обязан отрезать старые,
    // а не новые.
    $rows = array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'   => (int)$r['id'],
            'mine' => (string)$r['sender_id'] === $me,
            'body' => (string)$r['body'],
            'at'   => (int)$r['created_at'],
        ];
    }
    return $out;
}

/** Отправка сообщения. Возвращает [код, тело] как остальные обработчики. */
function chat_send(PDO $pdo, string $me, int $threadId, string $body, int $now): array {
    if ($me === '')                            { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!chat_ready($pdo))                     { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!chat_is_member($pdo, $me, $threadId)) { return [403, ['ok' => false, 'error' => 'not_a_member']]; }

    // Пробелы по краям срезаем ДО проверки на пустоту: сообщение из одних
    // пробелов — это пустое сообщение, а не короткое.
    $body = trim($body);
    if ($body === '')                     { return [400, ['ok' => false, 'error' => 'empty']]; }
    if (mb_strlen($body) > CHAT_BODY_MAX) { return [400, ['ok' => false, 'error' => 'too_long']]; }

    $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at)
                   VALUES (:t, :s, :b, :at)')
        ->execute([':t' => $threadId, ':s' => $me, ':b' => $body, ':at' => $now]);

    // Номер снимаем СРАЗУ. После следующего запроса значение уже не гарантия:
    // в MySQL оно живёт до очередной вставки, в других драйверах правила свои,
    // и клиент получал id 0 — сообщение, которое не отличить от соседнего.
    $id = (int)$pdo->lastInsertId();

    // Ветку двигаем наверх списка. Отдельным запросом, а не триггером:
    // триггеры в этой базе не используются нигде, и прятать в них логику
    // значит сделать её невидимой при чтении кода.
    $pdo->prepare('UPDATE chat_threads SET last_at = :at WHERE id = :t')
        ->execute([':at' => $now, ':t' => $threadId]);

    return [200, [
        'ok'      => true,
        'message' => ['id' => $id, 'mine' => true, 'body' => $body, 'at' => $now],
    ]];
}

/**
 * Отзыв о собеседнике. Повторная отправка ПРАВИТ прежний, а не добавляет
 * второй: иначе репутацию накручивают, отправив форму сто раз.
 */
function chat_review(PDO $pdo, string $me, int $threadId, int $stars, string $body, int $now): array {
    if ($me === '')                            { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!chat_ready($pdo))                     { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!chat_is_member($pdo, $me, $threadId)) { return [403, ['ok' => false, 'error' => 'not_a_member']]; }
    if ($stars < 1 || $stars > 5)              { return [400, ['ok' => false, 'error' => 'bad_stars']]; }

    $body = trim($body);
    if (mb_strlen($body) > CHAT_REVIEW_MAX)    { return [400, ['ok' => false, 'error' => 'too_long']]; }

    $st = $pdo->prepare('SELECT a_id, b_id FROM chat_threads WHERE id = :t');
    $st->execute([':t' => $threadId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $target = ((string)$t['a_id'] === $me) ? (string)$t['b_id'] : (string)$t['a_id'];

    // Себе отзыв не поставить. Ветка «сам с собой» в норме не создаётся, но
    // проверка стоит копейки, а последствие — накрутка своей репутации.
    if ($target === $me) { return [400, ['ok' => false, 'error' => 'self']]; }

    // Своими руками, а не ON DUPLICATE KEY: тот синтаксис MySQL-ный, а тесты
    // гоняются на SQLite, и схемы обязаны вести себя одинаково.
    $has = $pdo->prepare('SELECT id FROM chat_reviews WHERE thread_id = :t AND author_id = :a');
    $has->execute([':t' => $threadId, ':a' => $me]);
    $id = $has->fetchColumn();

    if ($id) {
        $pdo->prepare('UPDATE chat_reviews SET stars = :s, body = :b, created_at = :at WHERE id = :id')
            ->execute([':s' => $stars, ':b' => $body, ':at' => $now, ':id' => $id]);
    } else {
        $pdo->prepare('INSERT INTO chat_reviews (thread_id, author_id, target_id, stars, body, created_at)
                       VALUES (:t, :a, :g, :s, :b, :at)')
            ->execute([':t' => $threadId, ':a' => $me, ':g' => $target,
                       ':s' => $stars, ':b' => $body, ':at' => $now]);
    }

    chat_recount_reputation($pdo, $target);
    return [200, ['ok' => true, 'stars' => $stars, 'target' => $target]];
}

/**
 * Пересчёт репутации из отзывов.
 *
 * Считаем заново, а не прибавляем к счётчику: правка отзыва меняет вклад с
 * плюса на минус, и инкрементами это разъедется при первой же смене оценки.
 */
function chat_recount_reputation(PDO $pdo, string $userId): void {
    $st = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN stars >= :like THEN 1 ELSE 0 END) AS up,
            SUM(CASE WHEN stars <= :dis  THEN 1 ELSE 0 END) AS down
           FROM chat_reviews WHERE target_id = :u'
    );
    $st->execute([':like' => CHAT_STARS_LIKE, ':dis' => CHAT_STARS_DISLIKE, ':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    // Репутация — денормализованный СЧЁТЧИК, а не сам отзыв: отзыв уже лежит
    // в chat_reviews, и пересчёт не должен уметь уронить его сохранение.
    // Колонок может не быть (миграция выполняется руками и отдельно от
    // выкладки) — тогда счётчики просто останутся нулями, как и до неё.
    try {
        $pdo->prepare('UPDATE users SET likes = :l, dislikes = :d WHERE roblox_id = :u')
            ->execute([':l' => (int)($r['up'] ?? 0), ':d' => (int)($r['down'] ?? 0), ':u' => $userId]);
    } catch (PDOException $e) {
        // колонок репутации ещё нет
    }
}

/** Мой отзыв в этой ветке — форма открывается заполненной, если он уже есть. */
function chat_my_review(PDO $pdo, string $me, int $threadId): ?array {
    if (!chat_ready($pdo) || !chat_is_member($pdo, $me, $threadId)) { return null; }
    $st = $pdo->prepare('SELECT stars, body FROM chat_reviews WHERE thread_id = :t AND author_id = :a');
    $st->execute([':t' => $threadId, ':a' => $me]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['stars' => (int)$r['stars'], 'body' => (string)($r['body'] ?? '')] : null;
}
