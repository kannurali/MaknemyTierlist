<?php
// Розыгрыши: вкладка /admin/giveaways, окно на страницах сайта
// (js/giveaway-pop.js) и страница /giveaways.
//
// Кто участвует. Только вошедший через Roblox и подключивший бота
// (колокольчик): без бота нельзя ни проверить подписку на канал, ни сообщить
// о победе. Один Telegram — одно участие: уникальный ключ (розыгрыш,
// chat_id) не пускает второй аккаунт сайта, привязанный к тому же Telegram.
//
// Условия — подписка на каналы, где бот администратор. Список каналов
// наполняет сам Telegram: добавили бота в канал — вебхуку приходит
// my_chat_member (gw_channel_update()). Подписку бот проверяет при участии и
// ещё раз при розыгрыше: отписавшийся после участия приз не получает.
//
// Кто выигрывает. Победителей выбирает кнопка «Разыграть» в админке, после
// конца розыгрыша. Призы разыгрываются по порядку, один человек — максимум
// один приз. Выбор — random_int по перемешанному списку участников.
//
// Что пишет бот. О старте — всем подключившим бота, мимо переключателей в
// профиле (так решил владелец: о розыгрышах знать должны все). О победе —
// каждому победителю. Связывается с победителем владелец сам.
//
// Ничего из этого не роняет остальной сайт: нет таблиц — окно молчит,
// страница пуста, админка просит миграцию.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/news_blocks.php';
require_once __DIR__ . '/translate.php';

const GW_TITLE_MAX       = 120;
const GW_PRIZES_MAX      = 20;
const GW_PRIZE_NAME_MAX  = 60;
const GW_PRIZE_COUNT_MAX = 1000;
const GW_CHANNELS_MAX    = 5;
// Описание короче новости: оно живёт в окне поверх страницы.
const GW_BODY_JSON_MAX   = 32768;
const GW_BODY_TYPES      = ['p', 'quote', 'list'];
// Дальше трёх месяцев розыгрыш не планируют, а опечатка в годе
// (2062 вместо 2026) иначе повесила бы окно на сорок лет.
const GW_MAX_SPAN        = 90 * 86400;
// Сколько прошедших розыгрышей показывает страница.
const GW_PAST_LIMIT      = 12;
// Сколько участников отдаёт админке «открыть розыгрыш». Победители — всегда.
const GW_ADMIN_ENTRIES   = 500;
const GW_STATUSES        = ['draft', 'live', 'drawn'];
const GW_TZ              = 'Europe/Moscow';

// --------------------------------------------------------------------------
//  Таблицы
// --------------------------------------------------------------------------

function gw_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM giveaways LIMIT 1');
        $pdo->query('SELECT 1 FROM giveaway_entries LIMIT 1');
        $pdo->query('SELECT 1 FROM tg_channels LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// --------------------------------------------------------------------------
//  Проверка того, что прислала админка
// --------------------------------------------------------------------------

// Строка из админки без управляющих символов и двойных пробелов.
function gw_clean(string $s, int $max): string {
    return tg_clean_line($s, $max);
}

// Id канала Telegram: отрицательное число у каналов и групп. Принимается
// число или строка из цифр со знаком — из JSON он приходит числом, из формы
// строкой.
function gw_chat_id($v): ?int {
    if (is_int($v)) { return $v !== 0 ? $v : null; }
    if (is_string($v) && preg_match('/^-?[1-9][0-9]{0,18}\z/', $v)) { return (int)$v; }
    if (is_float($v) && $v == (int)$v && $v != 0.0) { return (int)$v; }
    return null;
}

/**
 * Поля розыгрыша из тела запроса. Черновик может быть пустым: проверка
 * «всё ли заполнено» — в gw_publish_problems(), перед публикацией.
 * Возвращает ['ok', 'error', 'row'], где row — значения колонок.
 */
function gw_validate(array $in): array {
    $fail = function (string $e): array { return ['ok' => false, 'error' => $e, 'row' => null]; };

    $titleRu = isset($in['title_ru']) && is_string($in['title_ru']) ? gw_clean($in['title_ru'], GW_TITLE_MAX + 1) : '';
    $titleEn = isset($in['title_en']) && is_string($in['title_en']) ? gw_clean($in['title_en'], GW_TITLE_MAX + 1) : '';
    if (mb_strlen($titleRu) > GW_TITLE_MAX || mb_strlen($titleEn) > GW_TITLE_MAX) { return $fail('title too long'); }

    $img = ['url' => '', 'w' => 0, 'h' => 0];
    if (isset($in['image']) && $in['image'] !== null) {
        if (!nb_valid_image_item($in['image'])) { return $fail('bad image'); }
        $img = ['url' => $in['image']['url'], 'w' => (int)$in['image']['w'], 'h' => (int)$in['image']['h']];
    }

    $blocks = [];
    if (isset($in['body']) && $in['body'] !== null) {
        $v = news_blocks_validate($in['body']);
        if (!$v['ok']) { return $fail($v['error']); }
        foreach ($v['blocks'] as $b) {
            if (!in_array($b['t'], GW_BODY_TYPES, true)) { return $fail('bad body block'); }
        }
        $blocks = $v['blocks'];
    }
    $bodyJson = gw_body_encode($blocks);
    if ($bodyJson === null) { return $fail('body too large'); }

    $prizes = [];
    $raw = $in['prizes'] ?? [];
    if (!nb_is_list($raw) || count($raw) > GW_PRIZES_MAX) { return $fail('bad prizes'); }
    foreach ($raw as $p) {
        if (!is_array($p) || !isset($p['name']) || !is_string($p['name'])) { return $fail('bad prizes'); }
        $name = gw_clean($p['name'], GW_PRIZE_NAME_MAX + 1);
        if ($name === '' || mb_strlen($name) > GW_PRIZE_NAME_MAX) { return $fail('bad prize name'); }
        if (!nb_is_int_between($p['count'] ?? null, 1, GW_PRIZE_COUNT_MAX)) { return $fail('bad prize count'); }
        $icon = gw_prize_icon($p['icon'] ?? '');
        if ($icon === null) { return $fail('bad prize icon'); }
        $prizes[] = ['name' => $name, 'count' => (int)$p['count'], 'icon' => $icon];
    }

    $channels = [];
    $raw = $in['channels'] ?? [];
    if (!nb_is_list($raw) || count($raw) > GW_CHANNELS_MAX) { return $fail('bad channels'); }
    foreach ($raw as $c) {
        $id = gw_chat_id($c);
        if ($id === null) { return $fail('bad channels'); }
        if (!in_array($id, $channels, true)) { $channels[] = $id; }
    }

    $ends = $in['ends_at'] ?? 0;
    if (!nb_is_int_between($ends, 0, 4102444800)) { return $fail('bad ends_at'); }

    return ['ok' => true, 'error' => '', 'row' => [
        'title_ru'      => $titleRu,
        'title_en'      => $titleEn,
        'image_url'     => $img['url'],
        'image_w'       => $img['w'],
        'image_h'       => $img['h'],
        'blocks'        => $blocks,
        'body_json'     => $bodyJson,
        'prizes'        => $prizes,
        'prizes_json'   => json_encode($prizes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'channels'      => $channels,
        'channels_json' => json_encode($channels),
        'ends_at'       => (int)$ends,
    ]];
}

// Иконка приза — картинка предмета из тирлиста. В данных тирлиста адреса
// бывают и абсолютными (https://maknemy.com/images/…), и от корня; хранится
// всегда путь от корня. Пусто — иконки нет, карточка рисует запасной значок.
// null — чужой адрес или не картинка сайта.
function gw_prize_icon($v): ?string {
    if ($v === null || $v === '') { return ''; }
    if (!is_string($v)) { return null; }
    $path = preg_replace('~^https://(?:www\.)?maknemy\.com(?=/images/)~', '', $v);
    return preg_match(NB_IMAGE_RE, $path) ? $path : null;
}

function gw_body_encode(array $blocks): ?string {
    $enc = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($enc === false || strlen($enc) > GW_BODY_JSON_MAX) { return null; }
    return '{"v":' . NB_DOC_VERSION . ',"blocks":' . $enc . '}';
}

// Колонки JSON из базы. Кривое содержимое — пустой список, а не ошибка:
// одна испорченная строка не должна ронять страницу со всеми розыгрышами.
function gw_decode_list($json): array {
    $d = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    return is_array($d) && nb_is_list($d) ? $d : [];
}

// Призы для показа: у каждого name, count и icon. У розыгрышей, сохранённых
// до появления иконок, icon нет — отдаётся пустой.
function gw_prizes($json): array {
    $out = [];
    foreach (gw_decode_list($json) as $p) {
        if (!is_array($p)) { continue; }
        $icon = gw_prize_icon($p['icon'] ?? '');
        $out[] = ['name' => (string)($p['name'] ?? ''), 'count' => (int)($p['count'] ?? 1), 'icon' => $icon ?? ''];
    }
    return $out;
}

function gw_decode_blocks($json): array {
    $d = is_string($json) && $json !== '' ? json_decode($json, true) : null;
    $v = news_blocks_validate($d);
    return $v['ok'] ? $v['blocks'] : [];
}

/**
 * Чего не хватает, чтобы опубликовать. Пустой список — можно.
 * $channels — строки tg_channels по chat_id.
 */
function gw_publish_problems(array $row, array $channels, int $now): array {
    $out = [];
    if (trim((string)$row['title_ru']) === '') { $out[] = 'no_title'; }
    if (!gw_decode_list($row['prizes_json'])) { $out[] = 'no_prizes'; }
    $ends = (int)$row['ends_at'];
    if ($ends <= $now) { $out[] = 'ends_in_past'; }
    elseif ($ends > $now + GW_MAX_SPAN) { $out[] = 'ends_too_far'; }
    foreach (gw_decode_list($row['channels_json']) as $id) {
        $c = $channels[(int)$id] ?? null;
        if (!$c || (int)$c['is_admin'] !== 1) { $out[] = 'channel_not_admin'; break; }
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Чтение
// --------------------------------------------------------------------------

function gw_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM giveaways WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Каналы по chat_id. Пустой $ids — все каналы.
function gw_channels(PDO $pdo, ?array $ids = null): array {
    if ($ids !== null && !$ids) { return []; }
    $sql = 'SELECT chat_id, title, username, kind, invite_url, is_admin, updated_at FROM tg_channels';
    if ($ids !== null) {
        $sql .= ' WHERE chat_id IN (' . implode(',', array_map('intval', $ids)) . ')';
    }
    $out = [];
    foreach ($pdo->query($sql . ' ORDER BY updated_at DESC')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['chat_id']] = $r;
    }
    return $out;
}

// Ссылка «Подписаться»: приглашение, а без него — публичный адрес.
function gw_channel_url(array $c): string {
    if ((string)$c['invite_url'] !== '') { return (string)$c['invite_url']; }
    if ((string)$c['username'] !== '') { return 'https://t.me/' . $c['username']; }
    return '';
}

// Состояние для показа: live, пока идёт; ended — время вышло, а кнопку
// «Разыграть» ещё не нажали; drawn — победители выбраны.
function gw_phase(array $row, int $now): string {
    if ($row['status'] === 'live') { return (int)$row['ends_at'] > $now ? 'live' : 'ended'; }
    return (string)$row['status'];
}

function gw_entry_counts(PDO $pdo, array $ids): array {
    if (!$ids) { return []; }
    $st = $pdo->query(
        'SELECT giveaway_id, COUNT(*) AS n FROM giveaway_entries WHERE giveaway_id IN ('
        . implode(',', array_map('intval', $ids)) . ') GROUP BY giveaway_id'
    );
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['giveaway_id']] = (int)$r['n']; }
    return $out;
}

// Победители розыгрышей: [id розыгрыша => [{prize, id, name, display, avatar}]].
function gw_winners(PDO $pdo, array $ids): array {
    if (!$ids) { return []; }
    $st = $pdo->query(
        'SELECT e.giveaway_id, e.user_id, e.prize, u.username, u.display_name, u.avatar_url
           FROM giveaway_entries e LEFT JOIN users u ON u.roblox_id = e.user_id
          WHERE e.prize IS NOT NULL AND e.giveaway_id IN (' . implode(',', array_map('intval', $ids)) . ')
          ORDER BY e.prize, e.joined_at'
    );
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['giveaway_id']][] = [
            'prize'   => (int)$r['prize'],
            'id'      => (string)$r['user_id'],
            'name'    => (string)($r['username'] ?? ''),
            'display' => (string)($r['display_name'] ?? ''),
            'avatar'  => (string)($r['avatar_url'] ?? ''),
        ];
    }
    return $out;
}

// Розыгрыш так, как его видит посетитель.
function gw_public_item(array $row, int $now, array $counts, array $channels, array $winners): array {
    $id = (int)$row['id'];
    $chs = [];
    foreach (gw_decode_list($row['channels_json']) as $cid) {
        $c = $channels[(int)$cid] ?? null;
        if (!$c) { continue; }
        $chs[] = ['id' => (string)$c['chat_id'], 'title' => (string)$c['title'], 'url' => gw_channel_url($c)];
    }
    $img = (string)$row['image_url'] !== ''
        ? ['url' => (string)$row['image_url'], 'w' => (int)$row['image_w'], 'h' => (int)$row['image_h']]
        : null;
    return [
        'id'           => $id,
        'phase'        => gw_phase($row, $now),
        'title'        => ['ru' => (string)$row['title_ru'], 'en' => (string)$row['title_en']],
        'image'        => $img,
        'body'         => gw_decode_blocks($row['body_json']),
        'prizes'       => gw_prizes($row['prizes_json']),
        'channels'     => $chs,
        'ends_at'      => (int)$row['ends_at'],
        'published_at' => (int)$row['published_at'],
        'entries'      => $counts[$id] ?? 0,
        'winners'      => $row['status'] === 'drawn' ? ($winners[$id] ?? []) : [],
    ];
}

/**
 * Для окна и страницы: идущие (новые первыми) и прошедшие (недавние
 * первыми). Одинаково для всех посетителей — ответ кешируется.
 */
function gw_public_list(PDO $pdo, int $now): array {
    $live = $pdo->prepare(
        "SELECT * FROM giveaways WHERE status = 'live' AND ends_at > :now ORDER BY published_at DESC, id DESC"
    );
    $live->execute([':now' => $now]);
    $liveRows = $live->fetchAll(PDO::FETCH_ASSOC);

    $past = $pdo->prepare(
        "SELECT * FROM giveaways WHERE status = 'drawn' OR (status = 'live' AND ends_at <= :now)
          ORDER BY ends_at DESC, id DESC LIMIT " . GW_PAST_LIMIT
    );
    $past->execute([':now' => $now]);
    $pastRows = $past->fetchAll(PDO::FETCH_ASSOC);

    $ids = [];
    $chIds = [];
    foreach (array_merge($liveRows, $pastRows) as $r) {
        $ids[] = (int)$r['id'];
        foreach (gw_decode_list($r['channels_json']) as $c) { $chIds[] = (int)$c; }
    }
    $counts   = gw_entry_counts($pdo, $ids);
    $channels = gw_channels($pdo, array_values(array_unique($chIds)));
    $winners  = gw_winners($pdo, $ids);

    $map = function (array $rows) use ($now, $counts, $channels, $winners): array {
        $out = [];
        foreach ($rows as $r) { $out[] = gw_public_item($r, $now, $counts, $channels, $winners); }
        return $out;
    };
    return ['live' => $map($liveRows), 'past' => $map($pastRows)];
}

// Где человек уже участвует среди идущих розыгрышей — для окна: оно не
// зовёт туда, где человек уже есть. Нет таблиц — пусто.
function gw_joined_ids(PDO $pdo, string $me, int $now): array {
    if ($me === '') { return []; }
    try {
        $st = $pdo->prepare(
            "SELECT e.giveaway_id FROM giveaway_entries e JOIN giveaways g ON g.id = e.giveaway_id
              WHERE e.user_id = :u AND g.status = 'live' AND g.ends_at > :now"
        );
        $st->execute([':u' => $me, ':now' => $now]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

// --------------------------------------------------------------------------
//  Подписка
// --------------------------------------------------------------------------

/**
 * Подписан ли человек на канал: 'yes', 'no' или 'error' — Telegram не
 * ответил или бот больше не может смотреть участников (его разжаловали).
 * 400 у getChatMember значит «такого участника нет» — это 'no'.
 */
function gw_member_state(callable $send, int $channel, int $tgUser): string {
    $res = $send('getChatMember', ['chat_id' => $channel, 'user_id' => $tgUser]);
    if (!is_array($res)) { return 'error'; }
    if (!empty($res['ok'])) {
        $m = is_array($res['result'] ?? null) ? $res['result'] : [];
        $status = (string)($m['status'] ?? '');
        if (in_array($status, ['creator', 'administrator', 'member'], true)) { return 'yes'; }
        if ($status === 'restricted') { return !empty($m['is_member']) ? 'yes' : 'no'; }
        return 'no';
    }
    return (int)($res['error_code'] ?? 0) === 400 ? 'no' : 'error';
}

/**
 * Проверить все каналы розыгрыша. ['ok' => true] — подписан везде;
 * иначе ['ok' => false, 'missing' => [chat_id…]] или ['ok' => false,
 * 'error' => true], если хоть один канал проверить не вышло.
 */
function gw_check_channels(callable $send, array $channels, int $tgUser): array {
    $missing = [];
    foreach ($channels as $c) {
        $s = gw_member_state($send, (int)$c, $tgUser);
        if ($s === 'error') { return ['ok' => false, 'error' => true, 'missing' => []]; }
        if ($s === 'no') { $missing[] = (string)$c; }
    }
    return $missing ? ['ok' => false, 'error' => false, 'missing' => $missing] : ['ok' => true, 'error' => false, 'missing' => []];
}

// --------------------------------------------------------------------------
//  Участие
// --------------------------------------------------------------------------

/**
 * Вступить в розыгрыш. [код, тело]. Ошибки — по полю error, их читает окно:
 *   login     — не вошёл через Roblox;
 *   banned    — забанен;
 *   closed    — розыгрыша нет, он не опубликован или время вышло;
 *   tg        — бот не подключён (окно предложит подключить);
 *   tg_used   — этот Telegram уже участвует с другого аккаунта;
 *   channels  — не подписан: missing — на какие каналы;
 *   tg_down   — Telegram не ответил, попробовать позже.
 */
function gw_enter(PDO $pdo, array $cfg, callable $send, string $me, int $id, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'login']]; }
    if (!gw_ready($pdo) || !tg_ready($pdo)) { return [503, ['ok' => false, 'error' => 'closed']]; }
    if (site_banned($me, $cfg, $now, $pdo)) { return [403, ['ok' => false, 'error' => 'banned']]; }

    $row = $id > 0 ? gw_load($pdo, $id) : null;
    if (!$row || gw_phase($row, $now) !== 'live') { return [409, ['ok' => false, 'error' => 'closed']]; }

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries WHERE giveaway_id = :g AND user_id = :u');
    $st->execute([':g' => $id, ':u' => $me]);
    if ($st->fetchColumn() !== false) { return [200, ['ok' => true, 'joined' => true]]; }

    $st = $pdo->prepare('SELECT chat_id FROM tg_links WHERE user_id = :u');
    $st->execute([':u' => $me]);
    $chat = $st->fetchColumn();
    if ($chat === false) { return [409, ['ok' => false, 'error' => 'tg']]; }
    $chat = (int)$chat;

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
    $st->execute([':g' => $id, ':c' => $chat]);
    if ($st->fetchColumn() !== false) { return [409, ['ok' => false, 'error' => 'tg_used']]; }

    $channels = gw_decode_list($row['channels_json']);
    if ($channels) {
        $chk = gw_check_channels($send, $channels, $chat);
        if ($chk['error']) { return [503, ['ok' => false, 'error' => 'tg_down']]; }
        if (!$chk['ok']) { return [409, ['ok' => false, 'error' => 'channels', 'missing' => $chk['missing']]]; }
    }

    try {
        $pdo->prepare('INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at) VALUES (:g, :u, :c, :at)')
            ->execute([':g' => $id, ':u' => $me, ':c' => $chat, ':at' => $now]);
    } catch (PDOException $e) {
        // Гонка двух вкладок или двух аккаунтов с одним Telegram: ключ
        // не пустил вторую запись. Кто именно — решает повторная проверка.
        $st = $pdo->prepare('SELECT user_id FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
        $st->execute([':g' => $id, ':c' => $chat]);
        $who = $st->fetchColumn();
        if ($who !== false && (string)$who === $me) { return [200, ['ok' => true, 'joined' => true]]; }
        return [409, ['ok' => false, 'error' => 'tg_used']];
    }
    return [200, ['ok' => true, 'joined' => true]];
}

// --------------------------------------------------------------------------
//  Админка
// --------------------------------------------------------------------------

// EN абзацев, чей русский текст не менялся, берётся из прежней версии:
// редактор шлёт описание целиком и без английского, и без этого каждое
// сохранение заново гоняло бы весь текст через переводчик.
function gw_reuse_en(array $blocks, array $oldBlocks): array {
    $known = [];
    foreach (tr_units($oldBlocks) as $u) {
        $en = tr_unit_get($oldBlocks, $u, 'en');
        if (!tr_spans_empty($en)) { $known[tr_spans_key(tr_unit_get($oldBlocks, $u, 'ru'))] = $en; }
    }
    if (!$known) { return $blocks; }
    foreach (tr_units($blocks) as $u) {
        if (!tr_spans_empty(tr_unit_get($blocks, $u, 'en'))) { continue; }
        $key = tr_spans_key(tr_unit_get($blocks, $u, 'ru'));
        if (isset($known[$key])) { tr_unit_set_en($blocks, $u, $known[$key]); }
    }
    return $blocks;
}

/**
 * Сохранить черновик или правку. Разыгранный розыгрыш не правится.
 * $translate — «русский → английский» для пустого EN (на бою Google), null —
 * без перевода (тесты).
 */
function gw_save(PDO $pdo, array $body, int $now, ?callable $translate = null): array {
    $id = 0;
    if (array_key_exists('id', $body) && $body['id'] !== null && $body['id'] !== 0) {
        $id = read_row_id($body);
        if ($id <= 0) { return [400, ['ok' => false, 'error' => 'bad id']]; }
    }
    $v = gw_validate($body);
    if (!$v['ok']) { return [400, ['ok' => false, 'error' => $v['error']]]; }
    $r = $v['row'];

    $old = null;
    if ($id > 0) {
        $old = gw_load($pdo, $id);
        if (!$old) { return [404, ['ok' => false, 'error' => 'not found']]; }
        if ($old['status'] === 'drawn') { return [409, ['ok' => false, 'error' => 'drawn']]; }
        $r['blocks'] = gw_reuse_en($r['blocks'], gw_decode_blocks($old['body_json']));
    }

    $failed = 0;
    if ($translate !== null) {
        $post = ['title_ru' => $r['title_ru'], 'title_en' => $r['title_en'], 'body_ru' => '', 'body_en' => ''];
        $oldPost = $old ? [
            'title_ru' => $old['title_ru'], 'title_en' => $old['title_en'],
            'body_ru' => '', 'body_en' => '', 'body_json' => $old['body_json'],
        ] : null;
        $tr = news_autotranslate($post, $r['blocks'], $oldPost, $translate);
        $failed = $tr['failed'];
        if (mb_strlen($tr['post']['title_en']) <= GW_TITLE_MAX) { $r['title_en'] = $tr['post']['title_en']; }
        $check = news_blocks_validate(['v' => NB_DOC_VERSION, 'blocks' => $tr['blocks']]);
        if ($check['ok'] && gw_body_encode($tr['blocks']) !== null) { $r['blocks'] = $tr['blocks']; }
        else { $failed++; }
    }
    $r['body_json'] = gw_body_encode($r['blocks']);
    if ($r['body_json'] === null) { return [400, ['ok' => false, 'error' => 'body too large']]; }

    $cols = [
        ':title_ru' => $r['title_ru'], ':title_en' => $r['title_en'],
        ':image_url' => $r['image_url'], ':image_w' => $r['image_w'], ':image_h' => $r['image_h'],
        ':body' => $r['body_json'], ':prizes' => $r['prizes_json'], ':channels' => $r['channels_json'],
        ':ends' => $r['ends_at'], ':now' => $now,
    ];
    if ($id > 0) {
        $pdo->prepare(
            'UPDATE giveaways SET title_ru = :title_ru, title_en = :title_en, image_url = :image_url,
                    image_w = :image_w, image_h = :image_h, body_json = :body, prizes_json = :prizes,
                    channels_json = :channels, ends_at = :ends, updated_at = :now
              WHERE id = :id'
        )->execute($cols + [':id' => $id]);
    } else {
        $pdo->prepare(
            "INSERT INTO giveaways (status, title_ru, title_en, image_url, image_w, image_h, body_json,
                    prizes_json, channels_json, ends_at, created_at, updated_at)
             VALUES ('draft', :title_ru, :title_en, :image_url, :image_w, :image_h, :body, :prizes,
                    :channels, :ends, :now, :now2)"
        )->execute($cols + [':now2' => $now]);
        $id = (int)$pdo->lastInsertId();
    }
    return [200, ['ok' => true, 'id' => $id, 'translate_failed' => $failed]];
}

/**
 * Опубликовать черновик: розыгрыш начинается, и бот пишет всем. [код, тело,
 * строка для рассылки или null]. Повторная публикация ничего не шлёт.
 */
function gw_publish(PDO $pdo, int $id, int $now): array {
    $row = gw_load($pdo, $id);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], null]; }
    if ($row['status'] !== 'draft') { return [409, ['ok' => false, 'error' => 'not draft'], null]; }
    $problems = gw_publish_problems($row, gw_channels($pdo, gw_decode_list($row['channels_json'])), $now);
    if ($problems) { return [409, ['ok' => false, 'error' => 'incomplete', 'problems' => $problems], null]; }

    $st = $pdo->prepare("UPDATE giveaways SET status = 'live', published_at = :now, updated_at = :now2 WHERE id = :id AND status = 'draft'");
    $st->execute([':now' => $now, ':now2' => $now, ':id' => $id]);
    if ($st->rowCount() !== 1) { return [409, ['ok' => false, 'error' => 'not draft'], null]; }
    $row['status'] = 'live';
    $row['published_at'] = $now;
    return [200, ['ok' => true], $row];
}

// Завершить досрочно: время конца — сейчас, дальше всё как у закончившегося.
function gw_end(PDO $pdo, int $id, int $now): array {
    $row = gw_load($pdo, $id);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not found']]; }
    if (gw_phase($row, $now) !== 'live') { return [409, ['ok' => false, 'error' => 'not live']]; }
    $pdo->prepare('UPDATE giveaways SET ends_at = :now, updated_at = :now2 WHERE id = :id')
        ->execute([':now' => $now, ':now2' => $now, ':id' => $id]);
    return [200, ['ok' => true]];
}

function gw_delete(PDO $pdo, int $id): array {
    if (!gw_load($pdo, $id)) { return [404, ['ok' => false, 'error' => 'not found']]; }
    $pdo->prepare('DELETE FROM giveaway_entries WHERE giveaway_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM giveaways WHERE id = :id')->execute([':id' => $id]);
    return [200, ['ok' => true]];
}

function gw_rand_default(): callable {
    return function (int $n): int { return random_int(0, $n - 1); };
}

/**
 * Разыграть закончившийся розыгрыш. [код, тело, победители для бота].
 *
 * Участники перемешиваются, и призы по порядку раздаются первым подходящим:
 * не забанен, бот всё ещё подключён (иначе о победе не сказать) и подписан на
 * все каналы прямо сейчас. Не ответил Telegram хоть раз — розыгрыш не
 * записывается вовсе: «не смог проверить» не должно значить «проиграл».
 *
 * $rand(n) → число 0..n-1; тесты подставляют предсказуемое.
 */
function gw_draw(PDO $pdo, array $cfg, callable $send, int $id, int $now, ?callable $rand = null): array {
    $rand = $rand ?? gw_rand_default();
    $row = gw_load($pdo, $id);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], []]; }
    if ($row['status'] === 'drawn') { return [409, ['ok' => false, 'error' => 'drawn'], []]; }
    if (gw_phase($row, $now) !== 'ended') { return [409, ['ok' => false, 'error' => 'not ended'], []]; }

    $prizes   = gw_decode_list($row['prizes_json']);
    $channels = gw_decode_list($row['channels_json']);
    $banned   = array_flip(site_banned_ids($cfg, $now, $pdo));

    $st = $pdo->prepare(
        'SELECT e.user_id, l.chat_id, l.lang FROM giveaway_entries e LEFT JOIN tg_links l ON l.user_id = e.user_id
          WHERE e.giveaway_id = :g ORDER BY e.joined_at, e.user_id'
    );
    $st->execute([':g' => $id]);
    $pool = $st->fetchAll(PDO::FETCH_ASSOC);

    for ($i = count($pool) - 1; $i > 0; $i--) {
        $j = $rand($i + 1);
        $tmp = $pool[$i];
        $pool[$i] = $pool[$j];
        $pool[$j] = $tmp;
    }

    $slots = [];
    foreach ($prizes as $k => $p) {
        for ($n = 0; $n < (int)$p['count']; $n++) { $slots[] = $k; }
    }

    $winners = [];
    $skipped = 0;
    $usedChats = [];
    foreach ($pool as $cand) {
        if (count($winners) >= count($slots)) { break; }
        $uid = (string)$cand['user_id'];
        if ($cand['chat_id'] === null || isset($banned[$uid]) || isset($usedChats[(int)$cand['chat_id']])) {
            $skipped++;
            continue;
        }
        if ($channels) {
            $chk = gw_check_channels($send, $channels, (int)$cand['chat_id']);
            if ($chk['error']) { return [503, ['ok' => false, 'error' => 'tg_down'], []]; }
            if (!$chk['ok']) { $skipped++; continue; }
        }
        $usedChats[(int)$cand['chat_id']] = true;
        $winners[] = [
            'user_id' => $uid,
            'chat_id' => (int)$cand['chat_id'],
            'lang'    => tg_lang($cand['lang']),
            'prize'   => $slots[count($winners)],
        ];
    }

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE giveaways SET status = 'drawn', drawn_at = :now, updated_at = :now2 WHERE id = :id AND status = 'live'");
        $st->execute([':now' => $now, ':now2' => $now, ':id' => $id]);
        if ($st->rowCount() !== 1) {
            $pdo->rollBack();
            return [409, ['ok' => false, 'error' => 'drawn'], []];
        }
        $pdo->prepare('UPDATE giveaway_entries SET prize = NULL WHERE giveaway_id = :g')->execute([':g' => $id]);
        $up = $pdo->prepare('UPDATE giveaway_entries SET prize = :p WHERE giveaway_id = :g AND user_id = :u');
        foreach ($winners as $w) { $up->execute([':p' => $w['prize'], ':g' => $id, ':u' => $w['user_id']]); }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    return [200, [
        'ok'       => true,
        'winners'  => count($winners),
        'slots'    => count($slots),
        'entries'  => count($pool),
        'skipped'  => $skipped,
    ], $winners];
}

// Розыгрыш для админки: все поля и участники с победителями.
function gw_admin_item(PDO $pdo, array $row, int $now): array {
    $id = (int)$row['id'];
    $st = $pdo->prepare(
        'SELECT e.user_id, e.chat_id, e.joined_at, e.prize, u.username, u.display_name, u.avatar_url, l.tg_name
           FROM giveaway_entries e
           LEFT JOIN users u ON u.roblox_id = e.user_id
           LEFT JOIN tg_links l ON l.user_id = e.user_id
          WHERE e.giveaway_id = :g
          ORDER BY CASE WHEN e.prize IS NULL THEN 1 ELSE 0 END, e.prize, e.joined_at DESC
          LIMIT ' . GW_ADMIN_ENTRIES
    );
    $st->execute([':g' => $id]);
    $entries = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $entries[] = [
            'id'        => (string)$e['user_id'],
            'name'      => (string)($e['username'] ?? ''),
            'display'   => (string)($e['display_name'] ?? ''),
            'avatar'    => (string)($e['avatar_url'] ?? ''),
            'tg'        => (string)($e['tg_name'] ?? ''),
            'joined_at' => (int)$e['joined_at'],
            'prize'     => $e['prize'] === null ? null : (int)$e['prize'],
        ];
    }
    $count = gw_entry_counts($pdo, [$id])[$id] ?? 0;
    return [
        'id'           => $id,
        'status'       => (string)$row['status'],
        'phase'        => gw_phase($row, $now),
        'title_ru'     => (string)$row['title_ru'],
        'title_en'     => (string)$row['title_en'],
        'image'        => (string)$row['image_url'] !== ''
            ? ['url' => (string)$row['image_url'], 'w' => (int)$row['image_w'], 'h' => (int)$row['image_h']]
            : null,
        'body'         => ['v' => NB_DOC_VERSION, 'blocks' => gw_decode_blocks($row['body_json'])],
        'prizes'       => gw_prizes($row['prizes_json']),
        'channels'     => array_map('strval', gw_decode_list($row['channels_json'])),
        'ends_at'      => (int)$row['ends_at'],
        'published_at' => (int)$row['published_at'],
        'drawn_at'     => (int)$row['drawn_at'],
        'entries'      => $count,
        'list'         => $entries,
    ];
}

function gw_admin_list(PDO $pdo, int $now): array {
    $rows = $pdo->query(
        "SELECT * FROM giveaways ORDER BY CASE status WHEN 'draft' THEN 0 WHEN 'live' THEN 1 ELSE 2 END,
                CASE WHEN published_at = 0 THEN updated_at ELSE published_at END DESC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $counts = gw_entry_counts($pdo, array_map(function ($r) { return (int)$r['id']; }, $rows));
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'      => (int)$r['id'],
            'status'  => (string)$r['status'],
            'phase'   => gw_phase($r, $now),
            'title'   => (string)$r['title_ru'],
            'ends_at' => (int)$r['ends_at'],
            'entries' => $counts[(int)$r['id']] ?? 0,
        ];
    }
    return $out;
}

function gw_admin_channels(PDO $pdo): array {
    $out = [];
    foreach (gw_channels($pdo) as $c) {
        $out[] = [
            'id'       => (string)$c['chat_id'],
            'title'    => (string)$c['title'],
            'username' => (string)$c['username'],
            'kind'     => (string)$c['kind'],
            'url'      => gw_channel_url($c),
            'admin'    => (int)$c['is_admin'] === 1,
        ];
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Каналы: что присылает Telegram
// --------------------------------------------------------------------------

/**
 * Обновление my_chat_member: бота добавили в канал или группу, повысили,
 * понизили или выгнали. Записывает канал и возвращает его chat_id, если
 * ссылку-приглашение надо запросить отдельно (приватный канал, бот —
 * админ), иначе null.
 */
function gw_channel_update(PDO $pdo, array $update, int $now): ?int {
    $m = $update['my_chat_member'] ?? null;
    if (!is_array($m)) { return null; }
    $chat = is_array($m['chat'] ?? null) ? $m['chat'] : [];
    $kind = (string)($chat['type'] ?? '');
    $id = $chat['id'] ?? null;
    if (!is_int($id) || !in_array($kind, ['channel', 'supergroup', 'group'], true)) { return null; }

    $status = is_array($m['new_chat_member'] ?? null) ? (string)($m['new_chat_member']['status'] ?? '') : '';
    $admin = $status === 'administrator' ? 1 : 0;
    $title = is_string($chat['title'] ?? null) ? gw_clean($chat['title'], 128) : '';
    $user  = is_string($chat['username'] ?? null) && preg_match('/^[A-Za-z0-9_]{4,32}\z/', $chat['username'])
        ? $chat['username'] : '';

    try {
        $st = $pdo->prepare('SELECT invite_url FROM tg_channels WHERE chat_id = :c');
        $st->execute([':c' => $id]);
        $prev = $st->fetchColumn();
        if ($prev === false) {
            $pdo->prepare('INSERT INTO tg_channels (chat_id, title, username, kind, invite_url, is_admin, updated_at)
                           VALUES (:c, :t, :u, :k, \'\', :a, :now)')
                ->execute([':c' => $id, ':t' => $title, ':u' => $user, ':k' => $kind, ':a' => $admin, ':now' => $now]);
        } else {
            $pdo->prepare('UPDATE tg_channels SET title = :t, username = :u, kind = :k, is_admin = :a, updated_at = :now
                            WHERE chat_id = :c')
                ->execute([':c' => $id, ':t' => $title, ':u' => $user, ':k' => $kind, ':a' => $admin, ':now' => $now]);
        }
    } catch (PDOException $e) {
        return null;
    }
    return ($admin && $user === '' && ($prev === false || (string)$prev === '')) ? $id : null;
}

/**
 * Перечитать канал у Telegram: название, адрес, админ ли бот, ссылка для
 * приватного. Кнопка «Обновить» в админке и запрос ссылки после вебхука.
 */
function gw_channel_refresh(PDO $pdo, array $tg, callable $send, int $chatId, int $now): bool {
    $info = $send('getChat', ['chat_id' => $chatId]);
    if (!is_array($info) || empty($info['ok']) || !is_array($info['result'] ?? null)) {
        if (is_array($info) && in_array((int)($info['error_code'] ?? 0), [400, 403], true)) {
            $pdo->prepare('UPDATE tg_channels SET is_admin = 0, updated_at = :now WHERE chat_id = :c')
                ->execute([':now' => $now, ':c' => $chatId]);
        }
        return false;
    }
    $c = $info['result'];
    $title = is_string($c['title'] ?? null) ? gw_clean($c['title'], 128) : '';
    $user  = is_string($c['username'] ?? null) && preg_match('/^[A-Za-z0-9_]{4,32}\z/', $c['username']) ? $c['username'] : '';

    $botId = (int)strtok((string)$tg['token'], ':');
    $admin = $botId > 0 && gw_member_state_raw($send, $chatId, $botId) === 'administrator' ? 1 : 0;

    $invite = '';
    if ($admin && $user === '') {
        $link = $send('exportChatInviteLink', ['chat_id' => $chatId]);
        if (is_array($link) && !empty($link['ok']) && is_string($link['result'] ?? null)
            && preg_match('~^https://t\.me/\S{1,200}\z~', $link['result'])) {
            $invite = $link['result'];
        }
    }
    $sql = 'UPDATE tg_channels SET title = :t, username = :u, is_admin = :a, updated_at = :now'
        . ($invite !== '' || $user !== '' ? ', invite_url = :i' : '') . ' WHERE chat_id = :c';
    $params = [':t' => $title, ':u' => $user, ':a' => $admin, ':now' => $now, ':c' => $chatId];
    if ($invite !== '' || $user !== '') { $params[':i'] = $invite; }
    $pdo->prepare($sql)->execute($params);
    return true;
}

function gw_member_state_raw(callable $send, int $chatId, int $userId): string {
    $res = $send('getChatMember', ['chat_id' => $chatId, 'user_id' => $userId]);
    if (!is_array($res) || empty($res['ok']) || !is_array($res['result'] ?? null)) { return ''; }
    return (string)($res['result']['status'] ?? '');
}

// --------------------------------------------------------------------------
//  Что пишет бот
// --------------------------------------------------------------------------

const GW_MONTHS_RU = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа',
                      'сентября', 'октября', 'ноября', 'декабря'];

// Дата конца по Москве: «20 октября, 20:00 МСК» / «20 October, 20:00 MSK».
function gw_date(int $ts, string $lang): string {
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(GW_TZ));
    if ($lang === 'en') { return $d->format('j F, H:i') . ' MSK'; }
    return $d->format('j') . ' ' . GW_MONTHS_RU[(int)$d->format('n') - 1] . ', ' . $d->format('H:i') . ' МСК';
}

function gw_title(array $row, string $lang): string {
    $en = (string)$row['title_en'];
    return $lang === 'en' && $en !== '' ? $en : (string)$row['title_ru'];
}

function gw_link(int $id): string {
    return TG_SITE . '/giveaways#g' . $id;
}

// Картинка розыгрыша — превью над текстом сообщения.
function gw_preview(array $row): array {
    if ((string)$row['image_url'] === '') { return ['link_preview_options' => ['is_disabled' => true]]; }
    return ['link_preview_options' => [
        'url'                => TG_SITE . $row['image_url'],
        'prefer_large_media' => true,
        'show_above_text'    => true,
    ]];
}

function gw_announce_text(array $row, string $lang): string {
    $lines = [];
    foreach (gw_decode_list($row['prizes_json']) as $p) {
        $lines[] = '• ' . $p['name'] . ((int)$p['count'] > 1 ? ' × ' . (int)$p['count'] : '');
    }
    $title = gw_title($row, $lang);
    $date  = gw_date((int)$row['ends_at'], $lang);
    $cond  = gw_decode_list($row['channels_json']) ? true : false;
    if ($lang === 'en') {
        return "🎁 New giveaway on maknemy.com\n\n" . $title . "\n\nPrizes:\n" . implode("\n", $lines)
            . "\n\nResults: " . $date . '.'
            . ($cond ? ' To enter, subscribe to the channels from the conditions.' : '')
            . ' Join on the site after signing in with Roblox.';
    }
    return "🎁 Новый розыгрыш на maknemy.com\n\n" . $title . "\n\nПризы:\n" . implode("\n", $lines)
        . "\n\nИтоги: " . $date . '.'
        . ($cond ? ' Для участия подпишитесь на каналы из условий.' : '')
        . ' Участвовать — на сайте после входа через Roblox.';
}

function gw_win_text(array $row, string $prize, string $lang): string {
    $title = gw_title($row, $lang);
    if ($lang === 'en') {
        return "🎉 Congratulations! You won the giveaway «" . $title . "» on maknemy.com.\n\nYour prize: " . $prize
            . "\n\nThe site owner will contact you soon.";
    }
    return "🎉 Поздравляем! Вы победили в розыгрыше «" . $title . "» на maknemy.com.\n\nВаш приз: " . $prize
        . "\n\nСкоро с вами свяжется владелец сайта.";
}

/**
 * Рассылка о старте — всем, кто подключил бота, без учёта переключателей.
 * Сколько ушло.
 */
function gw_announce(PDO $pdo, callable $send, array $row, ?callable $sleep = null): int {
    try {
        $rows = $pdo->query('SELECT chat_id, lang FROM tg_links ORDER BY linked_at, user_id')->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return 0;
    }
    $id = (int)$row['id'];
    return tg_send_rows($pdo, $send, $rows, function (string $lang) use ($row, $id): array {
        return [
            gw_announce_text($row, $lang),
            [[$lang === 'en' ? 'Join' : 'Участвовать', gw_link($id)]],
            gw_preview($row),
        ];
    }, $sleep);
}

// Каждому победителю — своё сообщение с его призом.
function gw_notify_winners(PDO $pdo, callable $send, array $row, array $winners, ?callable $sleep = null): int {
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $prizes = gw_decode_list($row['prizes_json']);
    $sent = 0;
    foreach ($winners as $i => $w) {
        $lang = tg_lang($w['lang']);
        $prize = (string)($prizes[$w['prize']]['name'] ?? '');
        $msg = tg_message_buttons((int)$w['chat_id'], gw_win_text($row, $prize, $lang),
            [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$row['id'])]]);
        if ($i > 0) { $sleep(TG_BROADCAST_GAP); }
        if (tg_after_send($pdo, (int)$w['chat_id'], $send('sendMessage', $msg))) { $sent++; }
    }
    return $sent;
}

// Обёртки для эндпоинтов: ответ админке уже ушёл, дальше — только бот.
function gw_after_publish(PDO $pdo, array $cfg, array $row, ?callable $send = null, ?callable $sleep = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    tg_long_run();
    try {
        gw_announce($pdo, $send ?? tg_http($tg['token']), $row, $sleep);
    } catch (Throwable $e) {
        error_log('giveaway announce: ' . $e->getMessage());
    }
}

function gw_after_draw(PDO $pdo, array $cfg, array $row, array $winners, ?callable $send = null, ?callable $sleep = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg) || !$winners) { return; }
    tg_finish_response();
    tg_long_run();
    try {
        gw_notify_winners($pdo, $send ?? tg_http($tg['token']), $row, $winners, $sleep);
    } catch (Throwable $e) {
        error_log('giveaway winners: ' . $e->getMessage());
    }
}

// После вебхука: ссылка-приглашение для приватного канала.
function gw_after_channel(PDO $pdo, array $cfg, int $chatId, int $now): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    try {
        gw_channel_refresh($pdo, $tg, tg_http($tg['token']), $chatId, $now);
    } catch (Throwable $e) {
        error_log('giveaway channel: ' . $e->getMessage());
    }
}
