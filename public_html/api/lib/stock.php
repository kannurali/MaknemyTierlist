<?php
// Сток фруктов Blox Fruits: страница /stock и «напиши, когда в стоке Kitsune».
//
// Откуда данные. Своих данных о стоке у сайта нет. Публичный бот Vulcan
// (не наш) публикует сток в канал #stock-feed нашего Discord-сервера: сам при
// каждой смене и в ответ на /stock. Наш бот Fool's eyes сидит на том же
// сервере только с правом читать, и раз в минуту api/stock_pull.php (cron в
// cPanel) забирает через REST API Discord новые сообщения канала и разбирает
// их здесь. Постоянного соединения с Discord нет: шаред-хостинг не держит
// долгие процессы, а сток меняется раз в несколько часов, так что минута
// задержки ничего не решает. Боевой сервер в Нидерландах — Discord оттуда
// доступен, хотя у большей части аудитории (РФ) он заблокирован.
//
// Что уходит в Telegram. Человек отмечает на /stock фрукты, и при смене стока
// бот пишет только тем, чей отмеченный фрукт в нём появился. Отметки — в
// stock_watch, Telegram подключается тем же колокольчиком, что в профиле.
//
// Ничего из этого не имеет права уронить сайт: нет токена или канала — сбор
// выключен, нет таблиц — /stock показывает пустой сток, Discord молчит —
// на странице остаётся последний известный сток с временем, когда его видели.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

require_once __DIR__ . '/telegram.php';

const STOCK_KINDS = ['normal', 'mirage'];

// Сколько длится смена у каждого дилера, секунды. Страница рисует по ней,
// какая часть смены уже прошла.
const STOCK_PERIODS = ['normal' => 14400, 'mirage' => 7200];

const STOCK_DISCORD_API = 'https://discord.com/api/v10';

// Подпись источника на странице. Решение владельца: сток подписан нашим
// ботом, который его забирает.
const STOCK_SOURCE = "Fool's eyes";

// Две метки времени смены в пределах этого (секунды) — одна и та же смена.
// Vulcan отвечает на /stock посреди смены с той же меткой, что и в автопосте,
// но держим запас на случай, если он пересчитает её с точностью до минуты.
const STOCK_SAME_ROTATION = 300;

// Сообщение старше этого (секунды) о смене уже не рассылается. Иначе после
// простоя (cron молчал, Discord лежал) бот разослал бы позавчерашний сток.
const STOCK_FRESH = 900;

// Сколько фруктов один человек может отметить. Фруктов в игре около сорока;
// предел только против запроса, который попытается раздуть таблицу.
const STOCK_WATCH_MAX = 60;

// Сколько после наступившей смены (секунды) бот ходит в Discord на каждом
// запуске cron, дожидаясь нового стока. Vulcan присылает его через несколько
// секунд или пару минут; 20 минут — с большим запасом.
const STOCK_WINDOW = 1200;

// Если Vulcan молчит дольше STOCK_WINDOW (лёг, сменил канал), бот не долбит
// Discord каждую минуту, а заглядывает раз в столько секунд.
const STOCK_SLOW = 300;

// Сколько сообщений канала забирается за раз. Канал пишется раз в два часа,
// так что столько набирается только после долгого простоя.
const STOCK_PULL_LIMIT = 50;

// Тирлист пишет «Permanent Lighting» — так и было в игре до переименования. В
// стоке тот же фрукт называется Lightning, и без этого у него не было бы
// картинки, а в фильтре он стоял бы дважды.
const STOCK_ALIASES = ['lighting' => 'Lightning'];

// Цена фрукта у дилера в игре, белли. Нужна для двух вещей: порядка в списке
// для уведомлений (дорогие сверху) и редкости фрукта, которую страница
// показывает цветом, — у фрукта, которого сейчас нет в стоке, своей цены под
// рукой нет. Цена из стока важнее этой таблицы: игра поменяет цену — сток
// покажет новую сразу, а таблица догонит при следующей правке.
// Новый фрукт, которого здесь нет, встаёт в конец списка без редкости.
const STOCK_PRICES = [
    'rocket' => 5000, 'spin' => 7500, 'blade' => 30000, 'spring' => 60000, 'bomb' => 80000,
    'smoke' => 100000, 'spike' => 180000, 'flame' => 250000, 'ice' => 350000, 'sand' => 420000,
    'dark' => 500000, 'eagle' => 550000, 'diamond' => 600000, 'light' => 650000, 'rubber' => 750000,
    'ghost' => 940000, 'magma' => 960000, 'quake' => 1000000, 'buddha' => 1200000, 'love' => 1300000,
    'creation' => 1400000, 'spider' => 1500000, 'sound' => 1700000, 'phoenix' => 1800000,
    'portal' => 1900000, 'lightning' => 2100000, 'pain' => 2300000, 'blizzard' => 2400000,
    'gravity' => 2500000, 'mammoth' => 2700000, 'trex' => 2700000, 'dough' => 2800000,
    'shadow' => 2900000, 'venom' => 3000000, 'gas' => 3200000, 'control' => 3200000,
    'spirit' => 3400000, 'tiger' => 5000000, 'yeti' => 5000000, 'magnet' => 6000000,
    'kitsune' => 8000000,
    'dragon' => 15000000,
];

/**
 * Редкость по цене, как она устроена в игре: обычные дешевле 250 000,
 * необычные — до 650 000, редкие — до миллиона, легендарные — до 2 500 000,
 * дальше мифические. '' — цена неизвестна.
 */
function stock_rarity(?int $price): string {
    if ($price === null || $price <= 0) { return ''; }
    if ($price < 250000) { return 'common'; }
    if ($price < 650000) { return 'uncommon'; }
    if ($price < 1000000) { return 'rare'; }
    if ($price < 2500000) { return 'legendary'; }
    return 'mythical';
}

// --------------------------------------------------------------------------
//  Разбор сообщения Vulcan
// --------------------------------------------------------------------------

/**
 * Ключ фрукта: «T-Rex» → «trex», «Dragon (West + East)» → «dragon».
 * По нему сток сводится с картинками тирлиста и с отметками людей, поэтому
 * регистр, дефисы и пояснения в скобках роли не играют.
 */
function stock_fruit_key(string $name): string {
    $s = strtolower(preg_replace('/\([^)]*\)/', ' ', $name) ?? '');
    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    if (isset(STOCK_ALIASES[$s])) { $s = stock_fruit_key(STOCK_ALIASES[$s]); }
    return $s;
}

/**
 * Весь текст сообщения по порядку. Vulcan шлёт сток в двух видах: ответ на
 * /stock — классический embed (поля name/value), автопост при смене — новые
 * компоненты Discord (контейнер с текстовыми блоками type 10). Разбору всё
 * равно, откуда строки, поэтому собираем их из всех мест, где бывает текст.
 */
function stock_message_texts(array $msg): array {
    $out = [];
    if (isset($msg['content']) && is_string($msg['content'])) { $out[] = $msg['content']; }
    foreach (is_array($msg['embeds'] ?? null) ? $msg['embeds'] : [] as $e) {
        if (!is_array($e)) { continue; }
        foreach (['title', 'description'] as $k) {
            if (isset($e[$k]) && is_string($e[$k])) { $out[] = $e[$k]; }
        }
        foreach (is_array($e['fields'] ?? null) ? $e['fields'] : [] as $f) {
            if (!is_array($f)) { continue; }
            foreach (['name', 'value'] as $k) {
                if (isset($f[$k]) && is_string($f[$k])) { $out[] = $f[$k]; }
            }
        }
    }
    $walk = function ($list) use (&$walk, &$out): void {
        if (!is_array($list)) { return; }
        foreach ($list as $c) {
            if (!is_array($c)) { continue; }
            if (isset($c['content']) && is_string($c['content'])) { $out[] = $c['content']; }
            $walk($c['components'] ?? null);
            if (isset($c['component']) && is_array($c['component'])) { $walk([$c['component']]); }
        }
    };
    $walk($msg['components'] ?? null);
    return $out;
}

/**
 * Сток из текста: ['normal' => ['fruits' => [[key, name, price], …], 'ends' => unix|null], …].
 * В ответе только те виды, у которых нашёлся хотя бы один фрукт: автопост
 * несёт один вид, ответ на /stock — оба.
 *
 * Строка фрукта у Vulcan: «<:spike:…> **Spike • <:money:…>`180,000`**».
 * Эмодзи и звёздочки разметки снимаются, остаётся «Spike • `180,000`».
 * Заголовок вида — любая строка, где есть «normal»/«mirage» и «stock»
 * («NORMAL STOCK», «Current Mirage Stock»). Время смены — метка Discord
 * <t:1790899211:R>, которую клиент рисует как «через 41 минуту».
 */
function stock_parse_text(string $text): array {
    $out  = [];
    $kind = null;
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $ts = preg_match('/<t:(\d{9,11})(?::[A-Za-z])?>/', $line, $tm) ? (int)$tm[1] : null;
        $clean = preg_replace('/<a?:[A-Za-z0-9_~]+:\d+>/', ' ', $line) ?? '';
        $clean = trim(str_replace(['**', '__'], '', $clean));
        $clean = preg_replace('/^-#\s*/', '', $clean) ?? $clean;

        if (preg_match('/\b(normal|mirage)\b.*\bstock\b|\bstock\b.*\b(normal|mirage)\b/i', $clean, $hm)) {
            $kind = strtolower(($hm[1] ?? '') !== '' ? $hm[1] : ($hm[2] ?? ''));
            if (!isset($out[$kind])) { $out[$kind] = ['fruits' => [], 'ends' => null]; }
            continue;
        }
        if ($kind === null) { continue; }
        if ($ts !== null) {
            $out[$kind]['ends'] = stock_snap($ts);
            continue;
        }
        if (!preg_match('/^[^A-Za-z`]*([A-Za-z][A-Za-z\'\- ]{0,30}?)\s*[•·]\s*[^0-9`]*`?\s*(\d[\d,. ]{0,14})/u', $clean, $m)) {
            continue;
        }
        $name  = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
        $price = (int)preg_replace('/\D/', '', $m[2]);
        $key   = stock_fruit_key($name);
        if ($key === '' || strlen($key) > 24 || $price <= 0 || $price > 100000000) { continue; }
        foreach ($out[$kind]['fruits'] as $f) {
            if ($f['key'] === $key) { continue 2; }
        }
        $out[$kind]['fruits'][] = ['key' => $key, 'name' => $name, 'price' => $price];
    }
    foreach (array_keys($out) as $k) {
        if (!$out[$k]['fruits']) { unset($out[$k]); }
    }
    return $out;
}

// Сток в игре меняется ровно в начале часа: обычный — каждые 4 часа, Mirage —
// каждые 2. Vulcan же пишет время смены с собственным опозданием — 20:00:12 у
// обычного и 20:01:12 у Mirage, — и без этого таймер Mirage на странице
// отставал на минуту (замечание владельца). Метка не дальше 10 минут от
// начала часа приводится к нему; метка дальше — не похожа на смену в начале
// часа и остаётся как есть. Применяется и к уже сохранённым меткам
// (stock_read), чтобы старые строки в базе не показывали прежнее время.
const STOCK_SNAP = 600;

function stock_snap(int $ts): int {
    $hour = (int)round($ts / 3600) * 3600;
    return abs($ts - $hour) <= STOCK_SNAP ? $hour : $ts;
}

function stock_parse_message(array $msg): array {
    return stock_parse_text(implode("\n", stock_message_texts($msg)));
}

// Похоже ли сообщение на сток, который не разобрался. Тогда админу уходит
// уведомление: значит, Vulcan сменил формат, и разбор пора чинить. Обычная
// болтовня в канале (её там быть не должно, но вдруг) под это не попадает.
function stock_looks_unparsed(array $msg): bool {
    $bot = !empty($msg['author']['bot']) || isset($msg['webhook_id']);
    return $bot && stripos(implode("\n", stock_message_texts($msg)), 'stock') !== false;
}

// --------------------------------------------------------------------------
//  Хранение
// --------------------------------------------------------------------------

// Есть ли таблицы. Нет — штатное состояние до миграции 2026-10-02-stock.sql.
function stock_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM stock LIMIT 1');
        $pdo->query('SELECT 1 FROM stock_feed LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function stock_watch_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM stock_watch LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/** Текущий сток: ['normal' => [fruits, ends, seen, message_id] | null, 'mirage' => …]. */
function stock_read(PDO $pdo): array {
    $out = array_fill_keys(STOCK_KINDS, null);
    try {
        $rows = $pdo->query('SELECT kind, fruits, ends_at, seen_at, message_id FROM stock')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $out;
    }
    foreach ($rows as $r) {
        if (!in_array($r['kind'], STOCK_KINDS, true)) { continue; }
        $fruits = json_decode((string)$r['fruits'], true);
        $out[$r['kind']] = [
            'fruits'     => is_array($fruits) ? $fruits : [],
            'ends'       => (int)$r['ends_at'] > 0 ? stock_snap((int)$r['ends_at']) : null,
            'seen'       => (int)$r['seen_at'],
            'message_id' => (string)$r['message_id'],
        ];
    }
    return $out;
}

function stock_write(PDO $pdo, string $kind, array $fruits, ?int $ends, string $messageId, int $now): void {
    $args = [
        ':k' => $kind,
        ':f' => json_encode($fruits, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':e' => $ends ?? 0,
        ':s' => $now,
        ':m' => $messageId,
    ];
    $has = $pdo->prepare('SELECT 1 FROM stock WHERE kind = :k');
    $has->execute([':k' => $kind]);
    if ($has->fetchColumn() === false) {
        $pdo->prepare('INSERT INTO stock (kind, fruits, ends_at, seen_at, message_id) VALUES (:k, :f, :e, :s, :m)')->execute($args);
        return;
    }
    $pdo->prepare('UPDATE stock SET fruits = :f, ends_at = :e, seen_at = :s, message_id = :m WHERE kind = :k')->execute($args);
}

// Докуда прочитан канал и о каком сообщении админа уже предупредили.
function stock_feed_get(PDO $pdo): array {
    $row = $pdo->query('SELECT last_id, alerted_id, polled_at FROM stock_feed WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->exec("INSERT INTO stock_feed (id, last_id, alerted_id, polled_at) VALUES (1, '0', '0', 0)");
        return ['last_id' => '0', 'alerted_id' => '0', 'polled_at' => 0];
    }
    return ['last_id' => (string)$row['last_id'], 'alerted_id' => (string)$row['alerted_id'], 'polled_at' => (int)$row['polled_at']];
}

/**
 * Пора ли идти в Discord. Сток меняется по расписанию, и время ближайшей
 * смены у нас есть точное — Vulcan кладёт его в каждый пост. Поэтому бот
 * «спит», пока смена не наступила, а с её наступления ходит в канал на каждом
 * запуске cron, пока не придёт новый сток: он сдвигает время смены вперёд, и
 * бот снова засыпает до следующей.
 *
 * Ходить на каждом запуске, когда:
 *  - стока нет совсем (первый запуск) — расписание неизвестно;
 *  - ближайшая смена наступила не дальше STOCK_WINDOW назад.
 * Раз в STOCK_SLOW — когда смена наступила давно, а нового стока всё нет
 * (Vulcan молчит); когда смена неправдоподобно далеко (кривая метка) — иначе
 * бот проспал бы её навсегда; когда одного из видов ещё не было или у него
 * нет времени смены.
 */
function stock_due(array $stock, int $now, int $polledAt): bool {
    $next = null;
    $unknown = false;
    foreach (STOCK_KINDS as $kind) {
        $s = $stock[$kind] ?? null;
        if ($s === null || empty($s['ends'])) { $unknown = true; continue; }
        $next = $next === null ? (int)$s['ends'] : min($next, (int)$s['ends']);
    }
    if ($next === null) { return true; }
    $slow = $now - $polledAt >= STOCK_SLOW;
    if ($next > $now + max(STOCK_PERIODS) + STOCK_SAME_ROTATION) { return $slow; }
    if ($now >= $next && $now - $next <= STOCK_WINDOW) { return true; }
    if ($now >= $next || $unknown) { return $slow; }
    return false;
}

function stock_feed_save(PDO $pdo, string $lastId, string $alertedId, int $now): void {
    $pdo->prepare('UPDATE stock_feed SET last_id = :l, alerted_id = :a, polled_at = :p WHERE id = 1')
        ->execute([':l' => $lastId, ':a' => $alertedId, ':p' => $now]);
}

// id сообщений Discord — числа длиннее, чем гарантированно влезает в int на
// любой сборке PHP, поэтому сравниваются строкой: сначала длина, потом цифры.
function stock_id_gt(string $a, string $b): bool {
    if (strlen($a) !== strlen($b)) { return strlen($a) > strlen($b); }
    return strcmp($a, $b) > 0;
}

/**
 * Положить разобранный сток в базу. Возвращает виды, у которых началась НОВАЯ
 * смена, — только о них и рассылка.
 *
 * Ответ на /stock посреди смены несёт ту же метку времени, что автопост в её
 * начале: это та же смена, и второй рассылки нет. Если состав при этом
 * другой (Vulcan поправился) — он просто перезаписывается. Сообщение со
 * старой сменой (канал читается после простоя) свежий сток не затирает.
 */
function stock_apply(PDO $pdo, array $parsed, string $messageId, int $now): array {
    $cur = stock_read($pdo);
    $new = [];
    foreach ($parsed as $kind => $s) {
        if (!in_array($kind, STOCK_KINDS, true)) { continue; }
        $row  = $cur[$kind];
        $ends = $s['ends'];
        if ($row === null) {
            stock_write($pdo, $kind, $s['fruits'], $ends, $messageId, $now);
            $new[$kind] = $s;
            continue;
        }
        $same = $s['fruits'] == $row['fruits'];
        if ($ends !== null && $row['ends'] !== null) {
            if ($ends > $row['ends'] + STOCK_SAME_ROTATION) {
                stock_write($pdo, $kind, $s['fruits'], $ends, $messageId, $now);
                $new[$kind] = $s;
            } elseif (abs($ends - $row['ends']) <= STOCK_SAME_ROTATION) {
                stock_write($pdo, $kind, $s['fruits'], $ends, $messageId, $now);
            }
            continue;
        }
        // Метки времени нет у одной из сторон — смена узнаётся только по составу.
        if (!$same) {
            stock_write($pdo, $kind, $s['fruits'], $ends ?? $row['ends'], $messageId, $now);
            $new[$kind] = $s;
        }
    }
    return $new;
}

// --------------------------------------------------------------------------
//  Сбор из Discord
// --------------------------------------------------------------------------

/**
 * Настройки сбора из config.php. Кривое значение — то же, что пустое: сбор
 * выключен, а не падает на первом запросе.
 */
function stock_config(array $cfg): array {
    $token = isset($cfg['discord_bot_token']) && is_string($cfg['discord_bot_token']) ? trim($cfg['discord_bot_token']) : '';
    if (!preg_match('/^[A-Za-z0-9_.-]{50,100}\z/', $token)) { $token = ''; }
    $chan = isset($cfg['discord_stock_channel']) ? trim((string)$cfg['discord_stock_channel']) : '';
    if (!preg_match('/^\d{15,22}\z/', $chan)) { $chan = ''; }
    return ['token' => $token, 'channel' => $chan];
}

/**
 * Настоящий транспорт: GET https://discord.com/api/v10<путь> от имени бота.
 * Возвращает [код HTTP, разобранное тело | null]. Тесты подставляют свой.
 */
function stock_discord_http(string $token): callable {
    return function (string $path) use ($token): array {
        if (!function_exists('curl_init')) { return [0, null]; }
        $ch = curl_init(STOCK_DISCORD_API . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bot ' . $token,
                // Discord требует User-Agent такого вида у ботов.
                'User-Agent: DiscordBot (https://maknemy.com, 1)',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        return [$code, is_array($d) ? $d : null];
    };
}

/**
 * Один проход cron: забрать новые сообщения канала, разобрать, сохранить.
 * $get($путь) → [код, тело]. Возвращает, что сделано:
 *   ['ok' => bool, 'read' => сколько сообщений, 'new' => [вид => сток], 'unparsed' => [id, …], 'error' => …]
 * Рассылку делает вызывающий (stock_pull_and_notify): так проход
 * проверяется без Telegram.
 *
 * Самый первый проход (канал ещё не читали) берёт последние сообщения, но
 * не рассылает: всё, что старше STOCK_FRESH, рассылкой не считается.
 *
 * Между сменами проход в Discord не ходит вовсе (см. stock_due) и
 * возвращает 'skipped' => true. $force — сходить в любом случае (ручной
 * запуск с ключом -f).
 */
function stock_pull(PDO $pdo, array $sc, callable $get, int $now, bool $force = false): array {
    if ($sc['token'] === '' || $sc['channel'] === '') { return ['ok' => false, 'error' => 'off']; }
    if (!stock_ready($pdo)) { return ['ok' => false, 'error' => 'not_ready']; }

    $feed = stock_feed_get($pdo);
    if (!$force && !stock_due(stock_read($pdo), $now, $feed['polled_at'])) {
        return ['ok' => true, 'skipped' => true, 'read' => 0, 'new' => [], 'unparsed' => []];
    }
    $path = '/channels/' . $sc['channel'] . '/messages?limit=' . STOCK_PULL_LIMIT;
    if ($feed['last_id'] !== '0') { $path .= '&after=' . $feed['last_id']; }

    [$code, $body] = $get($path);
    if ($code !== 200 || !is_array($body)) {
        return ['ok' => false, 'error' => 'discord_' . $code];
    }

    $msgs = [];
    foreach ($body as $m) {
        if (is_array($m) && isset($m['id']) && is_string($m['id']) && preg_match('/^\d{1,22}\z/', $m['id'])) {
            $msgs[] = $m;
        }
    }
    usort($msgs, function (array $a, array $b): int {
        if ($a['id'] === $b['id']) { return 0; }
        return stock_id_gt($a['id'], $b['id']) ? 1 : -1;
    });

    $last     = $feed['last_id'];
    $alerted  = $feed['alerted_id'];
    $new      = [];
    $unparsed = [];
    foreach ($msgs as $m) {
        if (stock_id_gt($m['id'], $last)) { $last = $m['id']; }
        $parsed = stock_parse_message($m);
        if (!$parsed) {
            if (stock_looks_unparsed($m) && stock_id_gt($m['id'], $alerted)) {
                $unparsed[] = $m['id'];
                $alerted = $m['id'];
            }
            continue;
        }
        $at = isset($m['timestamp']) && is_string($m['timestamp']) ? strtotime($m['timestamp']) : false;
        $fresh = $at !== false && $now - $at <= STOCK_FRESH;
        foreach (stock_apply($pdo, $parsed, $m['id'], $now) as $kind => $s) {
            if ($fresh) { $new[$kind] = $s; } else { unset($new[$kind]); }
        }
    }
    stock_feed_save($pdo, $last, $alerted, $now);
    return ['ok' => true, 'read' => count($msgs), 'new' => $new, 'unparsed' => $unparsed];
}

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

/**
 * Список фруктов для фильтра и картинки к стоку: из тирлиста.
 *
 * Обычных фруктов («Kitsune Fruit», тип f) в тирлисте нет у дешёвых — Spike,
 * Bomb, Ice там только пермами («Permanent Spike», тип p). Поэтому список
 * строится по пермам, и картинка у всех фруктов — перма (решение владельца,
 * чтобы сток выглядел одинаково). Картинка фрукта — только запасная, если у
 * перма её нет. Фрукт из стока, которого в тирлисте нет (вышел новый),
 * добавляется без картинки, чтобы его можно было отметить сразу.
 *
 * Возвращает [[key, name, icon, price, rarity], …]: сначала дорогие, как
 * мифические сверху в игре; фрукты без известной цены — в конце по алфавиту.
 * price — из стока, если фрукт там сейчас есть, иначе из STOCK_PRICES.
 */
function stock_catalog(array $tierState, array $stock): array {
    $perm  = [];
    $fruit = [];
    foreach (stock_tier_items($tierState) as $it) {
        $name = $it['name'];
        if ($it['type'] === 'p' && preg_match('/^Permanent\s+(.+)$/i', $name, $m) && stripos($name, 'token') === false) {
            $key = stock_fruit_key($m[1]);
            if ($key !== '' && !isset($perm[$key])) { $perm[$key] = ['name' => $m[1], 'icon' => $it['icon']]; }
        } elseif ($it['type'] === 'f' && preg_match('/^(.+?)\s+Fruit\b/i', $name, $m)) {
            $key = stock_fruit_key($m[1]);
            if ($key !== '' && !isset($fruit[$key]) && $it['icon'] !== '') { $fruit[$key] = $it['icon']; }
        }
    }

    $names  = [];
    $prices = STOCK_PRICES;
    foreach ($stock as $s) {
        foreach (is_array($s['fruits'] ?? null) ? $s['fruits'] : [] as $f) {
            $names[$f['key']]  = $f['name'];
            $prices[$f['key']] = (int)$f['price'];
        }
    }

    $out = [];
    foreach ($perm as $key => $p) {
        // Имя — как в стоке, если фрукт там был; иначе из перма: «T-rex» →
        // «T-Rex», «Dragon (West + East)» → «Dragon», «Lighting» → «Lightning».
        $bare = trim(preg_replace('/\([^)]*\)/', '', $p['name']) ?? '');
        $raw  = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $bare) ?? '');
        $name = $names[$key] ?? STOCK_ALIASES[$raw] ?? ucwords(strtolower($bare), " -");
        $out[$key] = ['key' => $key, 'name' => $name, 'icon' => $p['icon'] !== '' ? $p['icon'] : ($fruit[$key] ?? '')];
    }
    foreach ($names as $key => $name) {
        if (!isset($out[$key])) { $out[$key] = ['key' => $key, 'name' => $name, 'icon' => $fruit[$key] ?? '']; }
    }
    foreach ($out as $key => $c) {
        $price = $prices[$key] ?? null;
        $out[$key]['price']  = $price;
        $out[$key]['rarity'] = stock_rarity($price);
    }
    uasort($out, function (array $a, array $b): int {
        if ($a['price'] !== $b['price']) {
            if ($a['price'] === null) { return 1; }
            if ($b['price'] === null) { return -1; }
            return $b['price'] <=> $a['price'];
        }
        return strcmp($a['key'], $b['key']);
    });
    return array_values($out);
}

// Предметы тирлиста с типом и картинкой. tg_tier_items() отдаёт только имя и
// цену, а здесь нужны ещё тип (f/p) и картинка. Картинка — только своя: со
// своего сайта или относительная, чтобы чужой адрес из админки не уехал на
// страницу мимо CSP.
function stock_tier_items(array $state): array {
    $out = [];
    foreach (is_array($state['tiers'] ?? null) ? $state['tiers'] : [] as $tier) {
        if (!is_array($tier) || !is_array($tier['items'] ?? null)) { continue; }
        foreach ($tier['items'] as $it) {
            if (!is_array($it) || !is_string($it['name'] ?? null)) { continue; }
            $icon = is_string($it['icon'] ?? null) ? $it['icon'] : '';
            if (!preg_match('~^(/images/|https://maknemy\.com/images/)[A-Za-z0-9._-]+\z~', $icon)) { $icon = ''; }
            $out[] = [
                'name' => tg_clean_line($it['name'], 64),
                'type' => is_string($it['type'] ?? null) ? $it['type'] : '',
                'icon' => $icon,
            ];
        }
    }
    return $out;
}

/** Ответ api/stock.php. */
function stock_public(PDO $pdo, array $tierState): array {
    $stock = stock_read($pdo);
    $out = ['ok' => true, 'source' => STOCK_SOURCE];
    foreach (STOCK_KINDS as $kind) {
        $s = $stock[$kind];
        if ($s === null) { $out[$kind] = null; continue; }
        $fruits = [];
        foreach ($s['fruits'] as $f) { $fruits[] = $f + ['rarity' => stock_rarity((int)$f['price'])]; }
        $out[$kind] = ['fruits' => $fruits, 'ends' => $s['ends'], 'seen' => $s['seen'], 'period' => STOCK_PERIODS[$kind]];
    }
    $out['catalog'] = stock_catalog($tierState, $stock);
    return $out;
}

// --------------------------------------------------------------------------
//  Отметки «напиши, когда в стоке»
// --------------------------------------------------------------------------

/** Отмеченные фрукты человека. null — таблицы нет (миграция не выполнена). */
function stock_watch_get(PDO $pdo, string $me): ?array {
    try {
        $st = $pdo->prepare('SELECT fruit FROM stock_watch WHERE user_id = :u ORDER BY fruit');
        $st->execute([':u' => $me]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Отметить или снять один фрукт: {"fruit": "kitsune", "on": true}.
 * Только настоящий true/false — «0» или «нет» из кривого запроса не должны
 * молча снять отметку.
 */
function stock_watch_set(PDO $pdo, string $me, array $body): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    $watch = stock_watch_get($pdo, $me);
    if ($watch === null) { return [503, ['ok' => false, 'error' => 'not_ready']]; }

    $fruit = isset($body['fruit']) && is_string($body['fruit']) ? $body['fruit'] : '';
    if (!preg_match('/^[a-z0-9]{2,24}\z/', $fruit) || !is_bool($body['on'] ?? null)) {
        return [400, ['ok' => false, 'error' => 'bad_request']];
    }
    $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = :u');
    $st->execute([':u' => $me]);
    if ($st->fetchColumn() === false) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $has = in_array($fruit, $watch, true);
    if ($body['on'] && !$has) {
        if (count($watch) >= STOCK_WATCH_MAX) { return [400, ['ok' => false, 'error' => 'too_many']]; }
        try {
            $pdo->prepare('INSERT INTO stock_watch (user_id, fruit) VALUES (:u, :f)')->execute([':u' => $me, ':f' => $fruit]);
        } catch (PDOException $e) {
            // Второе нажатие, пришедшее следом, вставило строку первым.
        }
    } elseif (!$body['on'] && $has) {
        $pdo->prepare('DELETE FROM stock_watch WHERE user_id = :u AND fruit = :f')->execute([':u' => $me, ':f' => $fruit]);
    }
    return [200, ['ok' => true, 'watch' => stock_watch_get($pdo, $me) ?? []]];
}

// --------------------------------------------------------------------------
//  Рассылка
// --------------------------------------------------------------------------

function stock_text(string $key, string $lang): string {
    $t = [
        'ru' => [
            'normal'   => 'Обычный сток',
            'mirage'   => 'Сток Mirage',
            'left'     => 'Смена через %s',
            'h'        => '%d ч',
            'min'      => '%d мин',
            'open'     => 'Открыть сток',
            'pick'     => 'Выбрать фрукты',
            'unparsed' => 'Не разобрал сообщение стока в Discord — похоже, Vulcan сменил формат. Сток на сайте не обновляется, пока разбор не починят.',
        ],
        'en' => [
            'normal'   => 'Normal stock',
            'mirage'   => 'Mirage stock',
            'left'     => 'Next change in %s',
            'h'        => '%d h',
            'min'      => '%d min',
            'open'     => 'Open stock',
            'pick'     => 'Choose fruits',
            'unparsed' => 'Could not read a stock message in Discord — Vulcan seems to have changed its format. Stock on the site stays stale until the parser is fixed.',
        ],
    ];
    return $t[tg_lang($lang)][$key];
}

function stock_price(int $price, string $lang): string {
    return number_format($price, 0, '', tg_lang($lang) === 'en' ? ',' : ' ');
}

// «1 ч 52 мин». Меньше минуты — «1 мин»: «0 мин» читалось бы как «уже».
function stock_left(int $seconds, string $lang): string {
    $min = max(1, (int)ceil($seconds / 60));
    $h = intdiv($min, 60);
    $m = $min % 60;
    $parts = [];
    if ($h > 0) { $parts[] = sprintf(stock_text('h', $lang), $h); }
    if ($m > 0) { $parts[] = sprintf(stock_text('min', $lang), $m); }
    return implode(' ', $parts);
}

/**
 * Текст уведомления: вид стока, отмеченные фрукты с ценой, сколько до смены.
 * Без parse_mode, как и остальные уведомления бота.
 */
function stock_tg_text(string $kind, array $fruits, ?int $ends, int $now, string $lang): string {
    $lines = ['🔔 ' . stock_text($kind, $lang)];
    foreach ($fruits as $f) {
        $lines[] = $f['name'] . ' — ' . stock_price((int)$f['price'], $lang);
    }
    if ($ends !== null && $ends > $now) {
        $lines[] = '';
        $lines[] = '⏳ ' . sprintf(stock_text('left', $lang), stock_left($ends - $now, $lang));
    }
    return implode("\n", $lines);
}

/**
 * Разослать о новой смене тем, чей отмеченный фрукт в ней есть. Telegram-чат,
 * привязанный к двум аккаунтам сайта, получает одно сообщение со всеми
 * совпадениями обоих. Возвращает, скольким ушло.
 */
function stock_notify(PDO $pdo, array $tg, callable $send, string $kind, array $stock, int $now, ?callable $sleep = null): int {
    if (!tg_enabled($tg) || !tg_ready($pdo) || !stock_watch_ready($pdo)) { return 0; }
    $byKey = [];
    foreach ($stock['fruits'] as $f) { $byKey[$f['key']] = $f; }
    if (!$byKey) { return 0; }
    $sleep = $sleep ?? function (int $us): void { usleep($us); };

    $in = implode(',', array_fill(0, count($byKey), '?'));
    $st = $pdo->prepare(
        "SELECT l.chat_id, l.lang, w.fruit FROM stock_watch w
           JOIN tg_links l ON l.user_id = w.user_id
          WHERE w.fruit IN ($in)
          ORDER BY l.linked_at, l.user_id, w.fruit"
    );
    $st->execute(array_keys($byKey));

    $chats = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $chatId = (int)$r['chat_id'];
        if (!isset($chats[$chatId])) { $chats[$chatId] = ['lang' => tg_lang($r['lang']), 'keys' => []]; }
        $chats[$chatId]['keys'][(string)$r['fruit']] = true;
    }

    $sent = 0;
    $n = 0;
    foreach ($chats as $chatId => $c) {
        $fruits = [];
        foreach ($stock['fruits'] as $f) {
            if (isset($c['keys'][$f['key']])) { $fruits[] = $f; }
        }
        $msg = tg_message_buttons($chatId, stock_tg_text($kind, $fruits, $stock['ends'], $now, $c['lang']), [
            [stock_text('open', $c['lang']), TG_SITE . '/stock'],
            [stock_text('pick', $c['lang']), TG_SITE . '/stock#watch'],
        ]);
        if ($n++ > 0) { $sleep(TG_BROADCAST_GAP); }
        $res = $send('sendMessage', $msg);
        if (is_array($res) && (int)($res['error_code'] ?? 0) === 429) {
            $wait = (int)($res['parameters']['retry_after'] ?? 1);
            $sleep(max(1, min(30, $wait)) * 1000000);
            $res = $send('sendMessage', $msg);
        }
        if (tg_after_send($pdo, $chatId, $res)) { $sent++; }
    }
    return $sent;
}

// Сообщение стока не разобралось — сказать админам, подключившим Telegram.
function stock_alert_admins(PDO $pdo, array $cfg, array $tg, callable $send): int {
    $ids = config_id_list($cfg, 'admin_ids');
    if (!$ids || !tg_enabled($tg) || !tg_ready($pdo)) { return 0; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT DISTINCT chat_id, lang FROM tg_links WHERE user_id IN ($in)");
    $st->execute($ids);
    $sent = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $chatId = (int)$r['chat_id'];
        $res = $send('sendMessage', tg_message($chatId, '⚠️ ' . stock_text('unparsed', (string)$r['lang'])));
        if (tg_after_send($pdo, $chatId, $res)) { $sent++; }
    }
    return $sent;
}

/**
 * Проход cron целиком: забрать сток и разослать о новых сменах.
 * $get и $send подставляют тесты; на бою — настоящие Discord и Telegram.
 */
function stock_pull_and_notify(PDO $pdo, array $cfg, int $now, ?callable $get = null, ?callable $send = null, ?callable $sleep = null, bool $force = false): array {
    $sc  = stock_config($cfg);
    $res = stock_pull($pdo, $sc, $get ?? stock_discord_http($sc['token']), $now, $force);
    if (empty($res['ok'])) { return $res; }

    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return $res; }
    $send = $send ?? tg_http($tg['token']);
    $res['sent'] = 0;
    foreach ($res['new'] as $kind => $s) {
        $res['sent'] += stock_notify($pdo, $tg, $send, $kind, $s, $now, $sleep);
    }
    if ($res['unparsed']) { stock_alert_admins($pdo, $cfg, $tg, $send); }
    return $res;
}
