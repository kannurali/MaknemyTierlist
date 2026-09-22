<?php
// Трейдинг: лента объявлений /trading, публикация с /trading/new, закрытие.
// Общая логика для api/trades.php, api/trade_create.php и api/trade_close.php.
//
// Автор объявления — вошедший через Roblox (users.roblox_id), та же личность,
// что у чата и профиля; своей таблицы людей трейдинг не заводит. Предметы —
// id из тирлиста (tierlist.data), как в калькуляторе: объявление хранит только
// id, а цену и картинку лента берёт из текущего тирлиста.

require_once __DIR__ . '/profile.php';

// Сколько предметов на стороне. Столько же ячеек у калькулятора (CALC.MAX_SLOTS
// в js/calc.js): страница публикации — это его панель, и объявление, которое
// она не умеет собрать, сервер принимать не должен.
const TRADE_SIDE_MAX = 4;

// Открытых объявлений у одного человека одновременно. Лента общая: без
// потолка один человек забил бы её своими копиями.
const TRADE_OPEN_MAX = 5;

// Сколько живёт объявление. Старое предложение почти наверняка уже неактуально,
// а сам автор о нём забыл. Истечение статуса не меняет (см. миграцию).
const TRADE_TTL = 14 * 86400;

// Страница ленты. Больше за раз не нужно: карточка высокая, и двадцать штук —
// это несколько экранов прокрутки.
const TRADE_PAGE_SIZE = 20;

// Поисковая строка. Длиннее — обрезается: названия предметов и ники короче.
const TRADE_QUERY_MAX = 40;

// Сколько совпавших предметов поиск кладёт в запрос. Короткая строка («a»)
// совпала бы с половиной тирлиста, и каждый id — это ещё одно условие LIKE.
const TRADE_QUERY_ITEMS_MAX = 30;

// id предмета в тирлисте. Генерирует его админка (`id` + base36), но правило
// нарочно шире — лишь бы в нём не было кавычек и знаков LIKE: по этим id
// лента ищет внутри JSON сторон (trade_feed).
const TRADE_ITEM_ID_RE = '/^[A-Za-z0-9_-]{1,40}\z/';

/** Кто пришёл. Тот же разборщик, что у профиля. */
function trade_me(array $session): string {
    return profile_me($session);
}

/**
 * Есть ли таблица объявлений. На бою её нет до запуска миграции
 * docs/migrations/2026-09-23-trading.sql — это штатное состояние, а не
 * ошибка: лента тогда пустая, публикация отвечает 503.
 */
function trade_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM trade_offers LIMIT 1');
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Каталог предметов из тирлиста: id → ['name' => …, 'value' => число].
 *
 * Цена — строка вида "12000" или "0.3" (её вводит админка); всё, что числом
 * не читается, считается нулём — ровно как CALC.itemValue в калькуляторе,
 * иначе сумма на сервере и на странице разошлась бы.
 */
function trade_catalog(PDO $pdo): array {
    $raw = $pdo->query('SELECT data FROM tierlist WHERE id = 1')->fetchColumn();
    $doc = is_string($raw) ? json_decode($raw, true) : null;
    $out = [];
    if (!is_array($doc) || !isset($doc['tiers']) || !is_array($doc['tiers'])) { return $out; }
    foreach ($doc['tiers'] as $tier) {
        if (!is_array($tier) || !isset($tier['items']) || !is_array($tier['items'])) { continue; }
        foreach ($tier['items'] as $it) {
            if (!is_array($it)) { continue; }
            $id = isset($it['id']) && is_string($it['id']) ? $it['id'] : '';
            if ($id === '' || preg_match(TRADE_ITEM_ID_RE, $id) !== 1) { continue; }
            $out[$id] = [
                'name'  => isset($it['name']) && is_string($it['name']) ? $it['name'] : '',
                'value' => trade_parse_value($it['value'] ?? null),
            ];
        }
    }
    return $out;
}

function trade_parse_value($raw): float {
    if (is_int($raw) || is_float($raw)) { return $raw >= 0 ? (float)$raw : 0.0; }
    if (!is_string($raw)) { return 0.0; }
    $s = trim($raw);
    return preg_match('/^\d+(\.\d+)?\z/', $s) === 1 ? (float)$s : 0.0;
}

/**
 * Сторона сделки из присланного — список id, либо null, если прислали мусор.
 *
 * Каждый id обязан быть в текущем тирлисте: иначе в ленте висел бы предмет,
 * которого никто не видит, а перебором можно было бы положить в базу что
 * угодно. Порядок сохраняется, повторы допустимы (два одинаковых фрукта —
 * обычная сделка), пустая сторона — это пустой список, а не ошибка: решает
 * вызывающий.
 */
function trade_clean_side($raw, array $catalog): ?array {
    if (!is_array($raw)) { return null; }
    $raw = array_values($raw);
    if (count($raw) > TRADE_SIDE_MAX) { return null; }
    $out = [];
    foreach ($raw as $id) {
        if (!is_string($id) || preg_match(TRADE_ITEM_ID_RE, $id) !== 1) { return null; }
        if (!isset($catalog[$id])) { return null; }
        $out[] = $id;
    }
    return $out;
}

function trade_side_value(array $ids, array $catalog): float {
    $sum = 0.0;
    foreach ($ids as $id) { $sum += isset($catalog[$id]) ? $catalog[$id]['value'] : 0.0; }
    return $sum;
}

/** JSON стороны из базы → список id. Испорченная строка — пустая сторона. */
function trade_decode_side($raw): array {
    $list = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($list)) { return []; }
    $out = [];
    foreach ($list as $id) {
        if (is_string($id) && preg_match(TRADE_ITEM_ID_RE, $id) === 1) { $out[] = $id; }
    }
    return $out;
}

/** Сколько у человека живых открытых объявлений. */
function trade_open_count(PDO $pdo, string $me, int $now): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM trade_offers
                          WHERE user_id = :me AND status = 'open' AND created_at > :cut");
    $st->execute([':me' => $me, ':cut' => $now - TRADE_TTL]);
    return (int)$st->fetchColumn();
}

/**
 * Опубликовать объявление. [код, тело] — как у остальных обработчиков.
 *
 * Отдавать что-то нужно обязательно: объявление «хочу, ничего не предлагая»
 * — это просьба, а не сделка. Хотеть можно и ничего — это «жду
 * предложений», обычная форма на трейд-досках.
 */
function trade_create(PDO $pdo, string $me, $giveRaw, $wantRaw, int $now): array {
    if ($me === '')          { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!trade_ready($pdo))  { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!profile_exists($pdo, $me)) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $catalog = trade_catalog($pdo);
    $give = trade_clean_side($giveRaw, $catalog);
    $want = trade_clean_side($wantRaw === null ? [] : $wantRaw, $catalog);
    if ($give === null || $want === null) { return [400, ['ok' => false, 'error' => 'bad_items']]; }
    if (!$give)                           { return [400, ['ok' => false, 'error' => 'empty_give']]; }

    if (trade_open_count($pdo, $me, $now) >= TRADE_OPEN_MAX) {
        return [409, ['ok' => false, 'error' => 'too_many', 'max' => TRADE_OPEN_MAX]];
    }

    $pdo->prepare("INSERT INTO trade_offers (user_id, give, want, status, created_at)
                   VALUES (:u, :g, :w, 'open', :at)")
        ->execute([':u' => $me, ':g' => json_encode($give), ':w' => json_encode($want), ':at' => $now]);
    // Номер снимаем сразу: после следующего запроса значение уже не гарантия.
    $id = (int)$pdo->lastInsertId();

    return [200, ['ok' => true, 'id' => $id]];
}

/**
 * Закрыть объявление.
 *
 * Автор выбирает исход: 'done' — сделка состоялась, 'cancel' — снимает.
 * Оба исхода пишутся в журнал профиля (profile_trades), по нему профиль
 * рисует «успешно/отказ» и оборот. Сумма — отданное по ценам тирлиста на
 * момент закрытия: именно этим человек и расплатился.
 *
 * Администратор может снять чужое объявление ('remove') — это модерация,
 * а не отказ автора, поэтому в журнал автора оно не попадает.
 *
 * Закрыть можно только открытое: повторный клик не должен дописать в журнал
 * вторую сделку.
 */
function trade_close(PDO $pdo, string $me, bool $admin, int $id, string $result, int $now): array {
    if ($me === '' && !$admin) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!trade_ready($pdo))    { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    if (!in_array($result, ['done', 'cancel', 'remove'], true)) {
        return [400, ['ok' => false, 'error' => 'bad_result']];
    }

    $st = $pdo->prepare('SELECT id, user_id, give, status FROM trade_offers WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not_found']]; }

    $mine = $me !== '' && (string)$row['user_id'] === $me;
    if ($result === 'remove' ? !$admin : !$mine) {
        return [403, ['ok' => false, 'error' => 'forbidden']];
    }
    if ((string)$row['status'] !== 'open') { return [409, ['ok' => false, 'error' => 'closed']]; }

    $status = $result === 'done' ? 'done' : ($result === 'cancel' ? 'cancelled' : 'removed');

    // Условие на status в самом UPDATE, а не только в проверке выше: две
    // вкладки, нажавшие «сделка состоялась» одновременно, обе прошли бы
    // проверку, и в журнал легли бы две сделки. Строка меняется только у
    // первой.
    $up = $pdo->prepare("UPDATE trade_offers SET status = :s, closed_at = :at
                          WHERE id = :id AND status = 'open'");
    $up->execute([':s' => $status, ':at' => $now, ':id' => $id]);
    if ($up->rowCount() !== 1) { return [409, ['ok' => false, 'error' => 'closed']]; }

    if ($status !== 'removed') {
        $value = 0;
        if ($status === 'done') {
            $value = (int)round(trade_side_value(trade_decode_side($row['give']), trade_catalog($pdo)));
        }
        trade_journal($pdo, (string)$row['user_id'], $status === 'done' ? 'ok' : 'declined', $value, $now);
    }

    return [200, ['ok' => true, 'id' => $id, 'status' => $status]];
}

/**
 * Запись в журнал сделок профиля. Журнал — производное: объявление уже
 * закрыто, и отсутствие таблицы (миграцию выполнили не целиком) не должно
 * уронить само закрытие.
 */
function trade_journal(PDO $pdo, string $userId, string $status, int $value, int $now): void {
    try {
        $pdo->prepare('INSERT INTO profile_trades (user_id, day, status, value) VALUES (:u, :d, :s, :v)')
            ->execute([':u' => $userId, ':d' => date('Y-m-d', $now), ':s' => $status, ':v' => max(0, $value)]);
    } catch (PDOException $e) {
        // таблицы журнала ещё нет
    }
}

/**
 * Авторы объявлений пакетом. SELECT * — по той же причине, что в
 * profile_card: колонки репутации и присутствия приезжают миграциями, и
 * перечисленная колонка уронила бы всю ленту до их запуска.
 */
function trade_authors(PDO $pdo, array $ids, int $now): array {
    $ids = array_values(array_unique(array_map('strval', $ids)));
    if (!$ids) { return []; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM users WHERE roblox_id IN ($in)");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $name = trim((string)($r['username'] ?? ''));
        $nick = trim((string)($r['display_name'] ?? ''));
        if ($nick === '') { $nick = $name; }
        $avatar = (string)($r['avatar_url'] ?? '');
        if (!roblox_avatar_ok($avatar)) { $avatar = ''; }
        $seen  = (int)($r['last_seen_at'] ?? 0);
        $login = (int)($r['last_login_at'] ?? 0);
        if ($seen < $login) { $seen = $login; }
        $out[(string)$r['roblox_id']] = [
            'id'       => (string)$r['roblox_id'],
            'nick'     => $nick,
            'handle'   => $name !== '' ? '@' . $name : '',
            'avatar'   => $avatar,
            'status'   => ($seen > 0 && ($now - $seen) <= PROFILE_ONLINE_WINDOW) ? 'online' : 'offline',
            'likes'    => (int)($r['likes'] ?? 0),
            'dislikes' => (int)($r['dislikes'] ?? 0),
        ];
    }
    return $out;
}

/** Строки trade_offers → то, что уезжает наружу. Без автора — пропуск. */
function trade_rows_out(PDO $pdo, array $rows, string $me, int $now): array {
    if (!$rows) { return []; }
    $authors = trade_authors($pdo, array_column($rows, 'user_id'), $now);
    $out = [];
    foreach ($rows as $r) {
        $author = $authors[(string)$r['user_id']] ?? null;
        if (!$author) { continue; }   // автора удалили из users
        $out[] = [
            'id'      => (int)$r['id'],
            'author'  => $author,
            'give'    => trade_decode_side($r['give']),
            'want'    => trade_decode_side($r['want']),
            'at'      => (int)$r['created_at'],
            'mine'    => $me !== '' && (string)$r['user_id'] === $me,
        ];
    }
    return $out;
}

/** Поисковая строка → то, что можно искать. Пустая — поиска нет. */
function trade_clean_query($raw): string {
    if (!is_string($raw)) { return ''; }
    $q = preg_replace('/[\x00-\x1F\x7F]/u', '', $raw);
    if ($q === null) { return ''; }
    $q = trim(preg_replace('/\s+/u', ' ', $q));
    return mb_substr($q, 0, TRADE_QUERY_MAX);
}

/**
 * Лента: открытые живые объявления, свежие сверху.
 *
 * $before — номер последнего показанного объявления (следующая страница).
 * По id, а не по времени: две публикации одной секундой дали бы
 * неопределённый порядок, а id растёт строго.
 *
 * $q ищет по названию предмета (на любой стороне) и по нику автора. Названия
 * живут не в базе, а в тирлисте, поэтому сначала строка превращается в
 * список id совпавших предметов, а уже он — в условия запроса.
 *
 * Свои объявления на первой странице без поиска едут отдельным списком
 * `mine`: иначе через пару дней они утонули бы в чужих, и снять их было бы
 * не найти где. Из общей ленты они при этом не пропадают.
 */
function trade_feed(PDO $pdo, string $me, string $q, int $before, int $now): array {
    $empty = ['ok' => true, 'ready' => false, 'offers' => [], 'mine' => [], 'more' => false];
    if (!trade_ready($pdo)) { return $empty; }
    $empty['ready'] = true;

    $where  = ["o.status = 'open'", 'o.created_at > ?'];
    $params = [$now - TRADE_TTL];
    if ($before > 0) { $where[] = 'o.id < ?'; $params[] = $before; }

    if ($q !== '') {
        $or = [];
        $needle = mb_strtolower($q);
        $hits = 0;
        foreach (trade_catalog($pdo) as $id => $it) {
            if ($hits >= TRADE_QUERY_ITEMS_MAX) { break; }
            if ($it['name'] === '' || mb_strpos(mb_strtolower($it['name']), $needle) === false) { continue; }
            $or[] = 'o.give LIKE ?'; $params[] = '%"' . $id . '"%';
            $or[] = 'o.want LIKE ?'; $params[] = '%"' . $id . '"%';
            $hits++;
        }
        // Ник — через LIKE с экранированием: % и _ в запросе человека — это
        // буквы, а не шаблон. Экранирующий символ — «!», а не обратная косая:
        // литерал '\' MySQL читает как экранированную кавычку и рвёт запрос,
        // а '\\' SQLite считает двумя символами и отвергает.
        $like = '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $or[] = "o.user_id IN (SELECT roblox_id FROM users
                                WHERE LOWER(username) LIKE ? ESCAPE '!'
                                   OR LOWER(display_name) LIKE ? ESCAPE '!')";
        $params[] = $like;
        $params[] = $like;
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    // На одну строку больше страницы: так без второго запроса видно, есть ли
    // что показывать дальше.
    $sql = 'SELECT o.id, o.user_id, o.give, o.want, o.created_at FROM trade_offers o
             WHERE ' . implode(' AND ', $where) . '
          ORDER BY o.id DESC LIMIT ' . (TRADE_PAGE_SIZE + 1);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > TRADE_PAGE_SIZE;
    if ($more) { $rows = array_slice($rows, 0, TRADE_PAGE_SIZE); }

    $mine = [];
    if ($me !== '' && $before === 0 && $q === '') {
        $ms = $pdo->prepare("SELECT id, user_id, give, want, created_at FROM trade_offers
                              WHERE user_id = ? AND status = 'open' AND created_at > ?
                           ORDER BY id DESC LIMIT " . TRADE_OPEN_MAX);
        $ms->execute([$me, $now - TRADE_TTL]);
        $mine = trade_rows_out($pdo, $ms->fetchAll(PDO::FETCH_ASSOC), $me, $now);
    }

    return [
        'ok'     => true,
        'ready'  => true,
        'offers' => trade_rows_out($pdo, $rows, $me, $now),
        'mine'   => $mine,
        'more'   => $more,
        'ttl'    => TRADE_TTL,
    ];
}

/**
 * Сколько объявлений человек вообще создавал. Профиль показывает это как
 * «созданные»: журнал profile_trades знает только закрытые, а открытое
 * объявление — тоже созданное. null — таблицы нет.
 */
function trade_created_count(PDO $pdo, string $userId): ?int {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM trade_offers WHERE user_id = :u AND status <> 'removed'");
        $st->execute([':u' => $userId]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) {
        return null;
    }
}
