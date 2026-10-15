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
// Кроме каналов бывают свои условия — текст со ссылкой (conds_json). Обычно
// бот их не проверяет и на участие они не влияют. Но если у условия выбран
// канал бота (поле chat) — его подписку бот проверяет так же, как у каналов
// из списка: ссылка своя (например, приглашение, созданное вручную), а
// проверка та же (gw_checked_channels()).
//
// Админ без нужных прав (TG_CHANNEL_RIGHTS в telegram.php) — всё равно что
// не админ: is_admin = 0, в колонке missing — каких прав не хватает. Бот
// сразу пишет добавившему, чего ему не дали; перед публикацией права
// перечитываются у Telegram.
//
// Кто выигрывает. Победителей выбирает кнопка «Разыграть» в админке, после
// конца розыгрыша. Призы разыгрываются по порядку, один человек — максимум
// один приз. Выбор — random_int по перемешанному списку участников.
//
// Что пишет бот. О старте — всем подключившим бота, мимо переключателей в
// профиле (так решил владелец: о розыгрышах знать должны все). О победе —
// каждому победителю. Связывается с победителем владелец сам.
//
// Тестовый розыгрыш (колонка test) — чтобы проверить всё вживую, не трогая
// посетителей. В /api/giveaways.php его нет, поэтому ни окна, ни карточки на
// /giveaways никто не видит; видит только тот, кто открыл /giveaways?test —
// браузер запоминает это (js/giveaway.js) и дальше берёт
// /api/giveaways.php?test=1. О старте бот не пишет никому; «вы участвуете» и
// поздравление победителю приходят как обычно — это сами тестирующие.
// Пометка ставится только у черновика: идущий розыгрыш тестом не станет и
// тестом быть не перестанет.
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
// Свои условия: текст и ссылка (YouTube, TikTok, канал без бота). Нажатие на
// условие открывает ссылку; бот проверяет только те, у которых выбран канал.
const GW_CONDS_MAX       = 5;
const GW_COND_TEXT_MAX   = 100;
const GW_COND_URL_MAX    = 300;
// Надпись на кнопке условия. Пусто — «Подписаться».
const GW_COND_BTN_MAX    = 24;
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
        $pdo->query('SELECT 1 FROM giveaway_links LIMIT 1');
        $pdo->query('SELECT 1 FROM giveaway_subs LIMIT 1');
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

    $conds = [];
    $raw = $in['conds'] ?? [];
    if (!nb_is_list($raw) || count($raw) > GW_CONDS_MAX) { return $fail('bad conds'); }
    foreach ($raw as $c) {
        if (!is_array($c)) { return $fail('bad conds'); }
        $ru = isset($c['ru']) && is_string($c['ru']) ? gw_clean($c['ru'], GW_COND_TEXT_MAX + 1) : '';
        $en = isset($c['en']) && is_string($c['en']) ? gw_clean($c['en'], GW_COND_TEXT_MAX + 1) : '';
        if ($ru === '' || mb_strlen($ru) > GW_COND_TEXT_MAX || mb_strlen($en) > GW_COND_TEXT_MAX) {
            return $fail('bad cond text');
        }
        $url = gw_cond_url($c['url'] ?? null);
        if ($url === null) { return $fail('bad cond url'); }
        $btnRu = isset($c['btn_ru']) && is_string($c['btn_ru']) ? gw_clean($c['btn_ru'], GW_COND_BTN_MAX + 1) : '';
        $btnEn = isset($c['btn_en']) && is_string($c['btn_en']) ? gw_clean($c['btn_en'], GW_COND_BTN_MAX + 1) : '';
        if (mb_strlen($btnRu) > GW_COND_BTN_MAX || mb_strlen($btnEn) > GW_COND_BTN_MAX) { return $fail('bad cond button'); }
        // Без русской надписи кнопка «Подписаться» на обоих языках.
        if ($btnRu === '') { $btnEn = ''; }
        $chat = '';
        if (isset($c['chat']) && $c['chat'] !== '' && $c['chat'] !== null) {
            $chatId = gw_chat_id($c['chat']);
            if ($chatId === null) { return $fail('bad cond chat'); }
            $chat = (string)$chatId;
        }
        $conds[] = ['ru' => $ru, 'en' => $en, 'url' => $url, 'btn_ru' => $btnRu, 'btn_en' => $btnEn, 'chat' => $chat];
    }
    if (count(gw_checked_channels(['channels_json' => json_encode($channels), 'conds_json' => json_encode($conds)])) > GW_CHANNELS_MAX) {
        return $fail('bad channels');
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
        'conds'         => $conds,
        'ends_at'       => (int)$ends,
        'test'          => ($in['test'] ?? false) === true,
    ]];
}

// Ссылка своего условия: только http(s), без пробелов и кавычек — она уходит
// в href. null — не годится.
function gw_cond_url($v): ?string {
    if (!is_string($v)) { return null; }
    $v = trim($v);
    if ($v === '' || strlen($v) > GW_COND_URL_MAX) { return null; }
    if (!preg_match('~^https?://[^/?#\s<>"\'[:cntrl:]]+[^\s<>"\'[:cntrl:]]*\z~i', $v)) { return null; }
    return $v;
}

// Свои условия из базы: [{ru, en, url, btn_ru, btn_en, chat}]. Кривые строки
// пропускаются; у сохранённых до надписей на кнопках они пустые, до выбора
// канала — chat пустой (бот не проверяет).
function gw_conds($json): array {
    $str = function (array $c, string $k): string { return isset($c[$k]) && is_string($c[$k]) ? $c[$k] : ''; };
    $out = [];
    foreach (gw_decode_list($json) as $c) {
        if (!is_array($c) || $str($c, 'ru') === '') { continue; }
        $url = gw_cond_url($c['url'] ?? null);
        if ($url === null) { continue; }
        $btnRu = $str($c, 'btn_ru');
        $chat = gw_chat_id($c['chat'] ?? null);
        $out[] = [
            'ru' => $c['ru'], 'en' => $str($c, 'en'), 'url' => $url,
            'btn_ru' => $btnRu, 'btn_en' => $btnRu === '' ? '' : $str($c, 'btn_en'),
            'chat' => $chat === null ? '' : (string)$chat,
        ];
    }
    return $out;
}

// Каналы, подписку на которые бот проверяет: отмеченные в списке и
// выбранные у своих условий, без повторов. Отсюда берут участие, розыгрыш,
// снятие участия при отписке и проверка перед публикацией.
function gw_checked_channels(array $row): array {
    $out = [];
    foreach (gw_decode_list($row['channels_json'] ?? null) as $c) {
        $id = gw_chat_id($c);
        if ($id !== null && !in_array($id, $out, true)) { $out[] = $id; }
    }
    foreach (gw_conds($row['conds_json'] ?? null) as $c) {
        $id = gw_chat_id($c['chat']);
        if ($id !== null && !in_array($id, $out, true)) { $out[] = $id; }
    }
    return $out;
}

function gw_conds_encode(array $conds): string {
    return json_encode($conds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Колонку conds_json добавляет миграция 2026-10-15-giveaway-conds.sql. До неё
// розыгрыши сохраняются как раньше, только без своих условий.
function gw_has_conds(PDO $pdo): bool {
    try {
        $pdo->query('SELECT conds_json FROM giveaways LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Колонку test добавляет миграция 2026-10-15-giveaway-test.sql. До неё все
// розыгрыши настоящие, а пометить тестовым админка не даёт.
function gw_has_test(PDO $pdo): bool {
    try {
        $pdo->query('SELECT test FROM giveaways LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function gw_is_test(array $row): bool {
    return !empty($row['test']);
}

/**
 * Английский у своих условий — у текста и у надписи на кнопке. Пустой EN
 * берётся из прежней версии с тем же русским, а нет такой — переводится. EN,
 * оставшийся от прежнего русского (русский поменяли, английский нет),
 * считается пустым. [условия, сколько не перевелось].
 */
function gw_conds_en(array $conds, array $old, ?callable $translate, float $deadline): array {
    $pairs = ['en' => ['ru', GW_COND_TEXT_MAX], 'btn_en' => ['btn_ru', GW_COND_BTN_MAX]];
    $texts = [];
    foreach ($pairs as $enKey => [$ruKey]) {
        $byRu = [];
        $byEn = [];
        foreach ($old as $o) {
            if ($o[$enKey] === '') { continue; }
            $byRu[$o[$ruKey]] = $o[$enKey];
            $byEn[$o[$enKey]] = $o[$ruKey];
        }
        foreach ($conds as $i => $c) {
            if ($c[$enKey] !== '' && isset($byEn[$c[$enKey]]) && $byEn[$c[$enKey]] !== $c[$ruKey]) { $c[$enKey] = ''; }
            if ($c[$enKey] === '' && isset($byRu[$c[$ruKey]])) { $c[$enKey] = $byRu[$c[$ruKey]]; }
            if ($c[$enKey] === '' && $c[$ruKey] !== '') { $texts[$enKey . ':' . $i] = $c[$ruKey]; }
            $conds[$i] = $c;
        }
    }
    if (!$texts || $translate === null) { return [$conds, 0]; }
    $failed = 0;
    foreach (tr_translate_texts($texts, $translate, $deadline) as $k => $en) {
        [$enKey, $i] = explode(':', $k);
        [$ruKey, $max] = $pairs[$enKey];
        $en = $en === null ? '' : gw_clean(tr_fix_dashes($en, $conds[(int)$i][$ruKey]), $max + 1);
        if ($en === '' || mb_strlen($en) > $max) { $failed++; continue; }
        $conds[(int)$i][$enKey] = $en;
    }
    return [$conds, $failed];
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
    foreach (gw_checked_channels($row) as $id) {
        $c = $channels[$id] ?? null;
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
    // *: колонки missing до миграции 2026-10-14-tg-channel-rights.sql нет.
    $sql = 'SELECT * FROM tg_channels';
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
function gw_public_item(array $row, int $now, array $counts, array $channels, array $winners, array $links = []): array {
    $id = (int)$row['id'];
    $chs = [];
    foreach (gw_decode_list($row['channels_json']) as $cid) {
        $c = $channels[(int)$cid] ?? null;
        if (!$c) { continue; }
        $url = $links[$id][(int)$cid] ?? gw_channel_url($c);
        $chs[] = ['id' => (string)$c['chat_id'], 'title' => (string)$c['title'], 'url' => $url];
    }
    $conds = [];
    foreach (gw_conds($row['conds_json'] ?? null) as $c) {
        $conds[] = [
            'text' => ['ru' => $c['ru'], 'en' => $c['en']],
            'url'  => $c['url'],
            'btn'  => ['ru' => $c['btn_ru'], 'en' => $c['btn_en']],
            // Канал, который проверяет бот: по нему окно отмечает условие,
            // когда участие не пустило (missing). Пусто — не проверяется.
            'chat' => $c['chat'],
        ];
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
        'conds'        => $conds,
        'ends_at'      => (int)$row['ends_at'],
        'published_at' => (int)$row['published_at'],
        'entries'      => $counts[$id] ?? 0,
        'winners'      => $row['status'] === 'drawn' ? ($winners[$id] ?? []) : [],
        'test'         => gw_is_test($row),
    ];
}

/**
 * Для окна и страницы: идущие (новые первыми) и прошедшие (недавние
 * первыми). Одинаково для всех посетителей — ответ кешируется.
 * $withTest — вместе с тестовыми (/api/giveaways.php?test=1).
 */
function gw_public_list(PDO $pdo, int $now, bool $withTest = false): array {
    $real = !$withTest && gw_has_test($pdo) ? ' AND test = 0' : '';
    $live = $pdo->prepare(
        "SELECT * FROM giveaways WHERE status = 'live' AND ends_at > :now" . $real . " ORDER BY published_at DESC, id DESC"
    );
    $live->execute([':now' => $now]);
    $liveRows = $live->fetchAll(PDO::FETCH_ASSOC);

    $past = $pdo->prepare(
        "SELECT * FROM giveaways WHERE (status = 'drawn' OR (status = 'live' AND ends_at <= :now))" . $real . "
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
    $links    = gw_links($pdo, $ids);

    $map = function (array $rows) use ($now, $counts, $channels, $winners, $links): array {
        $out = [];
        foreach ($rows as $r) { $out[] = gw_public_item($r, $now, $counts, $channels, $winners, $links); }
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
 * Вступить в розыгрыш. [код, тело, кому написать «вы участвуете» или null].
 * $src — откуда пришёл человек (gw_src()). Ошибки — по полю error, их
 * читает окно:
 *   login     — не вошёл через Roblox;
 *   banned    — забанен;
 *   closed    — розыгрыша нет, он не опубликован или время вышло;
 *   tg        — бот не подключён (окно предложит подключить);
 *   tg_used   — этот Telegram уже участвует с другого аккаунта;
 *   channels  — не подписан: missing — на какие каналы;
 *   tg_down   — Telegram не ответил, попробовать позже.
 */
function gw_enter(PDO $pdo, array $cfg, callable $send, string $me, int $id, int $now, string $src = ''): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'login']]; }
    if (!gw_ready($pdo) || !tg_ready($pdo)) { return [503, ['ok' => false, 'error' => 'closed']]; }
    if (site_banned($me, $cfg, $now, $pdo)) { return [403, ['ok' => false, 'error' => 'banned']]; }

    $row = $id > 0 ? gw_load($pdo, $id) : null;
    if (!$row || gw_phase($row, $now) !== 'live') { return [409, ['ok' => false, 'error' => 'closed']]; }

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries WHERE giveaway_id = :g AND user_id = :u');
    $st->execute([':g' => $id, ':u' => $me]);
    if ($st->fetchColumn() !== false) { return [200, ['ok' => true, 'joined' => true]]; }

    $st = $pdo->prepare('SELECT chat_id, lang FROM tg_links WHERE user_id = :u');
    $st->execute([':u' => $me]);
    $link = $st->fetch(PDO::FETCH_ASSOC);
    if (!$link) { return [409, ['ok' => false, 'error' => 'tg']]; }
    $chat = (int)$link['chat_id'];

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
    $st->execute([':g' => $id, ':c' => $chat]);
    if ($st->fetchColumn() !== false) { return [409, ['ok' => false, 'error' => 'tg_used']]; }

    $channels = gw_checked_channels($row);
    if ($channels) {
        $chk = gw_check_channels($send, $channels, $chat);
        if ($chk['error']) { return [503, ['ok' => false, 'error' => 'tg_down']]; }
        if (!$chk['ok']) { return [409, ['ok' => false, 'error' => 'channels', 'missing' => $chk['missing']]]; }
    }

    try {
        $pdo->prepare('INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at, src) VALUES (:g, :u, :c, :at, :src)')
            ->execute([':g' => $id, ':u' => $me, ':c' => $chat, ':at' => $now, ':src' => gw_src($src)]);
    } catch (PDOException $e) {
        // Гонка двух вкладок или двух аккаунтов с одним Telegram: ключ
        // не пустил вторую запись. Кто именно — решает повторная проверка.
        $st = $pdo->prepare('SELECT user_id FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
        $st->execute([':g' => $id, ':c' => $chat]);
        $who = $st->fetchColumn();
        if ($who !== false && (string)$who === $me) { return [200, ['ok' => true, 'joined' => true]]; }
        return [409, ['ok' => false, 'error' => 'tg_used']];
    }
    return [200, ['ok' => true, 'joined' => true], ['chat_id' => $chat, 'lang' => tg_lang($link['lang']), 'row' => $row]];
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
    $hasConds = gw_has_conds($pdo);
    if ($r['conds'] && !$hasConds) { return [409, ['ok' => false, 'error' => 'conds_migrate']]; }
    $hasTest = gw_has_test($pdo);
    if ($r['test'] && !$hasTest) { return [409, ['ok' => false, 'error' => 'test_migrate']]; }

    $old = null;
    if ($id > 0) {
        $old = gw_load($pdo, $id);
        if (!$old) { return [404, ['ok' => false, 'error' => 'not found']]; }
        if ($old['status'] === 'drawn') { return [409, ['ok' => false, 'error' => 'drawn']]; }
        $r['blocks'] = gw_reuse_en($r['blocks'], gw_decode_blocks($old['body_json']));
        if ($old['status'] !== 'draft') { $r['test'] = gw_is_test($old); }
    }

    $deadline = microtime(true) + TR_BUDGET_SEC;
    [$r['conds'], $failed] = gw_conds_en($r['conds'], $old ? gw_conds($old['conds_json'] ?? null) : [], $translate, $deadline);
    if ($translate !== null) {
        $post = ['title_ru' => $r['title_ru'], 'title_en' => $r['title_en'], 'body_ru' => '', 'body_en' => ''];
        $oldPost = $old ? [
            'title_ru' => $old['title_ru'], 'title_en' => $old['title_en'],
            'body_ru' => '', 'body_en' => '', 'body_json' => $old['body_json'],
        ] : null;
        $tr = news_autotranslate($post, $r['blocks'], $oldPost, $translate, $deadline);
        $failed += $tr['failed'];
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
    if ($hasConds) { $cols[':conds'] = gw_conds_encode($r['conds']); }
    if ($hasTest) { $cols[':test'] = $r['test'] ? 1 : 0; }
    if ($id > 0) {
        $pdo->prepare(
            'UPDATE giveaways SET title_ru = :title_ru, title_en = :title_en, image_url = :image_url,
                    image_w = :image_w, image_h = :image_h, body_json = :body, prizes_json = :prizes,
                    channels_json = :channels, ' . ($hasConds ? 'conds_json = :conds, ' : '')
                    . ($hasTest ? 'test = :test, ' : '') . 'ends_at = :ends, updated_at = :now
              WHERE id = :id'
        )->execute($cols + [':id' => $id]);
    } else {
        $pdo->prepare(
            "INSERT INTO giveaways (status, title_ru, title_en, image_url, image_w, image_h, body_json,
                    prizes_json, channels_json, " . ($hasConds ? 'conds_json, ' : '') . ($hasTest ? 'test, ' : '')
                    . "ends_at, created_at, updated_at)
             VALUES ('draft', :title_ru, :title_en, :image_url, :image_w, :image_h, :body, :prizes,
                    :channels, " . ($hasConds ? ':conds, ' : '') . ($hasTest ? ':test, ' : '') . ":ends, :now, :now2)"
        )->execute($cols + [':now2' => $now]);
        $id = (int)$pdo->lastInsertId();
    }
    return [200, ['ok' => true, 'id' => $id, 'translate_failed' => $failed]];
}

/**
 * Опубликовать черновик: розыгрыш начинается, и бот пишет всем. [код, тело,
 * строка для рассылки или null]. Повторная публикация ничего не шлёт.
 * $botId — права бота в каналах перечитываются у Telegram (0 — нет).
 */
function gw_publish(PDO $pdo, int $id, int $now, ?callable $send = null, int $botId = 0): array {
    $row = gw_load($pdo, $id);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], null]; }
    if ($row['status'] !== 'draft') { return [409, ['ok' => false, 'error' => 'not draft'], null]; }
    $checked = gw_checked_channels($row);
    if ($send !== null) { gw_recheck_rights($pdo, $send, $botId, $checked, $now); }
    $problems = gw_publish_problems($row, gw_channels($pdo, $checked), $now);
    if ($problems) { return [409, ['ok' => false, 'error' => 'incomplete', 'problems' => $problems], null]; }
    if ($send !== null) {
        $failed = gw_ensure_links($pdo, $send, $row, $now);
        if ($failed) {
            return [409, ['ok' => false, 'error' => 'incomplete', 'problems' => ['invite_link'], 'channels' => $failed], null];
        }
    }

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
    $pdo->prepare('DELETE FROM giveaway_links WHERE giveaway_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM giveaway_subs WHERE giveaway_id = :id')->execute([':id' => $id]);
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
    $channels = gw_checked_channels($row);
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
        'SELECT e.user_id, e.chat_id, e.joined_at, e.prize, e.src, u.username, u.display_name, u.avatar_url, l.tg_name
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
            'src'       => (string)$e['src'],
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
        'conds'        => gw_conds($row['conds_json'] ?? null),
        'ends_at'      => (int)$row['ends_at'],
        'published_at' => (int)$row['published_at'],
        'drawn_at'     => (int)$row['drawn_at'],
        'test'         => gw_is_test($row),
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
            'test'    => gw_is_test($r),
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
            'missing'  => tg_right_names(gw_split_rights($c['missing'] ?? ''), 'ru'),
        ];
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Каналы: что присылает Telegram
// --------------------------------------------------------------------------

/**
 * Готов ли бот к розыгрышам по объекту ChatMember бота в канале:
 * [1 — админ со всеми нужными правами, иначе 0; каких прав не хватает].
 * Не админ — [0, []]: тут дело не в правах.
 */
function gw_bot_state(array $member, string $kind): array {
    if ((string)($member['status'] ?? '') !== 'administrator') { return [0, []]; }
    $missing = [];
    foreach (tg_admin_rights($kind === 'channel') as $k) {
        if (empty($member[$k])) { $missing[] = $k; }
    }
    return [$missing ? 0 : 1, $missing];
}

function gw_split_rights($v): array {
    return is_string($v) && $v !== '' ? explode(',', $v) : [];
}

// Каких прав не хватает. Отдельным запросом: до миграции
// 2026-10-14-tg-channel-rights.sql колонки нет, и тогда канал просто
// неготов (is_admin = 0), а какие права не выданы — админка не скажет.
function gw_store_missing(PDO $pdo, int $chatId, array $missing): void {
    try {
        $pdo->prepare('UPDATE tg_channels SET missing = :m WHERE chat_id = :c')
            ->execute([':m' => implode(',', $missing), ':c' => $chatId]);
    } catch (PDOException $e) {
        return;
    }
}

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

    [$admin, $missing] = gw_bot_state(is_array($m['new_chat_member'] ?? null) ? $m['new_chat_member'] : [], $kind);
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
    gw_store_missing($pdo, $id, $missing);
    return ($admin && $user === '' && ($prev === false || (string)$prev === '')) ? $id : null;
}

/**
 * Что бот ответит тому, кто дал ему админа в канале или поменял его права:
 * каких прав не хватает или что канал готов. Уходит телом ответа вебхука.
 * Человек не начинал диалог с ботом — Telegram сообщение не доставит, и
 * ничего страшного: то же видно в админке. Права поменяли, а нужные
 * остались как были — бот молчит.
 */
function gw_channel_reply(array $update, string $botName): ?array {
    $m = $update['my_chat_member'] ?? null;
    if (!is_array($m)) { return null; }
    $chat = is_array($m['chat'] ?? null) ? $m['chat'] : [];
    $kind = (string)($chat['type'] ?? '');
    $from = is_array($m['from'] ?? null) ? $m['from'] : [];
    if (!in_array($kind, ['channel', 'supergroup', 'group'], true) || !is_int($from['id'] ?? null) || !empty($from['is_bot'])) {
        return null;
    }
    $new = is_array($m['new_chat_member'] ?? null) ? $m['new_chat_member'] : [];
    $old = is_array($m['old_chat_member'] ?? null) ? $m['old_chat_member'] : [];
    if ((string)($new['status'] ?? '') !== 'administrator') { return null; }
    [, $missing] = gw_bot_state($new, $kind);
    if ((string)($old['status'] ?? '') === 'administrator' && gw_bot_state($old, $kind)[1] === $missing) { return null; }

    $lang  = tg_lang_from_telegram($from['language_code'] ?? '');
    $title = is_string($chat['title'] ?? null) ? gw_clean($chat['title'], 128) : '';
    $text  = gw_rights_text($title, tg_right_names($missing, $lang), $lang);
    $url   = tg_add_channel_url($botName);
    if (!$missing || $url === '') { return ['method' => 'sendMessage'] + tg_message($from['id'], $text); }
    return ['method' => 'sendMessage'] + tg_message($from['id'], $text, $lang === 'en' ? 'Grant the rights' : 'Выдать права', $url);
}

function gw_rights_text(string $title, array $missing, string $lang): string {
    if ($lang === 'en') {
        if (!$missing) { return '✅ The channel «' . $title . '» is ready for maknemy.com giveaways: I have all the rights I need.'; }
        return '⚠️ In the channel «' . $title . "» I'm missing rights for giveaways:\n• " . implode("\n• ", $missing)
            . "\n\nWithout them the channel can't be a giveaway condition. Turn them on: channel → Administrators → me.";
    }
    if (!$missing) { return '✅ Канал «' . $title . '» готов к розыгрышам maknemy.com: все нужные права у меня есть.'; }
    return '⚠️ В канале «' . $title . "» мне не хватает прав для розыгрышей:\n• " . implode("\n• ", $missing)
        . "\n\nБез них канал нельзя поставить в условия розыгрыша. Включите их: канал → Администраторы → я.";
}

/**
 * Перечитать канал у Telegram: название, адрес, админ ли бот и с какими
 * правами, ссылка для приватного. Кнопка «Обновить» в админке и запрос
 * ссылки после вебхука.
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
    [$admin, $missing] = gw_bot_state($botId > 0 ? (gw_bot_member($send, $chatId, $botId) ?? []) : [], (string)($c['type'] ?? ''));

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
    gw_store_missing($pdo, $chatId, $missing);
    return true;
}

/**
 * Бот в канале глазами Telegram (объект ChatMember). Бота в канале нет —
 * ['status' => 'left']; Telegram не ответил — null.
 */
function gw_bot_member(callable $send, int $chatId, int $botId): ?array {
    $res = $send('getChatMember', ['chat_id' => $chatId, 'user_id' => $botId]);
    if (!is_array($res)) { return null; }
    if (!empty($res['ok'])) { return is_array($res['result'] ?? null) ? $res['result'] : null; }
    return in_array((int)($res['error_code'] ?? 0), [400, 403], true) ? ['status' => 'left'] : null;
}

/**
 * Перед публикацией: права бота в каналах розыгрыша — заново у Telegram.
 * Вебхук мог не дойти, а каналы, добавленные до проверки прав, её ещё не
 * проходили. Telegram не ответил — остаётся записанное.
 */
function gw_recheck_rights(PDO $pdo, callable $send, int $botId, array $chIds, int $now): void {
    if ($botId <= 0) { return; }
    $channels = gw_channels($pdo, $chIds);
    foreach ($chIds as $cid) {
        $cid = (int)$cid;
        if (!isset($channels[$cid])) { continue; }
        $me = gw_bot_member($send, $cid, $botId);
        if ($me === null) { continue; }
        [$admin, $missing] = gw_bot_state($me, (string)$channels[$cid]['kind']);
        $pdo->prepare('UPDATE tg_channels SET is_admin = :a, updated_at = :now WHERE chat_id = :c')
            ->execute([':a' => $admin, ':now' => $now, ':c' => $cid]);
        gw_store_missing($pdo, $cid, $missing);
    }
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

// Ссылка на розыгрыш. Из рассылки о старте — с пометкой -tg: страница
// запоминает, что человек пришёл из бота (см. src в giveaway_entries).
// Тестовый без ?test на странице не виден.
function gw_link(int $id, bool $fromBot = false, bool $test = false): string {
    return TG_SITE . '/giveaways' . ($test ? '?test' : '') . '#g' . $id . ($fromBot ? '-tg' : '');
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
    $cond  = gw_checked_channels($row) ? 'ch' : (gw_conds($row['conds_json'] ?? null) ? 'own' : '');
    if ($lang === 'en') {
        return "🎁 New giveaway on maknemy.com\n\n" . $title . "\n\nPrizes:\n" . implode("\n", $lines)
            . "\n\nResults: " . $date . '.'
            . ($cond === 'ch' ? ' To enter, subscribe to the channels from the conditions.' : '')
            . ($cond === 'own' ? ' The conditions are on the giveaway card.' : '')
            . ' Join on the site after signing in with Roblox.';
    }
    return "🎁 Новый розыгрыш на maknemy.com\n\n" . $title . "\n\nПризы:\n" . implode("\n", $lines)
        . "\n\nИтоги: " . $date . '.'
        . ($cond === 'ch' ? ' Для участия подпишитесь на каналы из условий.' : '')
        . ($cond === 'own' ? ' Условия — в карточке розыгрыша.' : '')
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
            [[$lang === 'en' ? 'Join' : 'Участвовать', gw_link($id, true, gw_is_test($row))]],
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
            [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$row['id'], false, gw_is_test($row))]]);
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

// --------------------------------------------------------------------------
//  Учёт подписчиков для рекламодателя
// --------------------------------------------------------------------------
//
// Розыгрыш с условием «подписаться на канал» продаётся рекламодателю, и
// платит он за подписчиков, пришедших от нас. Поэтому у каждого розыгрыша в
// каждом канале своя ссылка-приглашение (gw_ensure_links()), а Telegram
// сообщает вебхуку обо всех вступлениях и выходах в каналах, где бот —
// админ (chat_member). Вступил по нашей ссылке — строка в giveaway_subs.
// Вышел — left_at и, если розыгрыш ещё не разыгран, участие снимается.
//
// У своего условия с каналом ссылка своя — её бот не заводил, а Telegram в
// обновлении показывает такую ссылку с обрезанным хвостом («…»). Вступление
// по ней засчитывается розыгрышу, пока он идёт: видимое начало совпало с
// ссылкой условия (gw_invite_match()).

const GW_SOURCES = ['tg', 'pop', 'page'];

function gw_src($v): string {
    return is_string($v) && in_array($v, GW_SOURCES, true) ? $v : '';
}

// Ссылки розыгрышей: [id розыгрыша => [канал => ссылка]].
function gw_links(PDO $pdo, array $ids): array {
    if (!$ids) { return []; }
    $out = [];
    try {
        $st = $pdo->query('SELECT giveaway_id, channel_id, invite_url FROM giveaway_links WHERE giveaway_id IN ('
            . implode(',', array_map('intval', $ids)) . ')');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['giveaway_id']][(int)$r['channel_id']] = (string)$r['invite_url'];
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

/**
 * Завести ссылку-приглашение розыгрыша в каждом его канале, где её ещё нет.
 * Возвращает названия каналов, где Telegram ссылку не дал (у бота нет права
 * приглашать пользователей).
 */
function gw_ensure_links(PDO $pdo, callable $send, array $row, int $now): array {
    $id = (int)$row['id'];
    $have = gw_links($pdo, [$id])[$id] ?? [];
    $channels = gw_channels($pdo, gw_decode_list($row['channels_json']));
    $failed = [];
    foreach (gw_decode_list($row['channels_json']) as $cid) {
        $cid = (int)$cid;
        if (isset($have[$cid])) { continue; }
        $res = $send('createChatInviteLink', ['chat_id' => $cid, 'name' => mb_substr('Maknemy #' . $id, 0, 32)]);
        $url = is_array($res) && !empty($res['ok']) && is_array($res['result'] ?? null)
            ? (string)($res['result']['invite_link'] ?? '') : '';
        if (!preg_match('~^https://t\.me/\S{1,100}\z~', $url)) {
            $failed[] = isset($channels[$cid]) ? (string)$channels[$cid]['title'] : (string)$cid;
            continue;
        }
        $pdo->prepare('INSERT INTO giveaway_links (giveaway_id, channel_id, invite_url, created_at) VALUES (:g, :c, :u, :at)')
            ->execute([':g' => $id, ':c' => $cid, ':u' => $url, ':at' => $now]);
    }
    return $failed;
}

// Ссылка-приглашение в одном виде: https://t.me/+хеш. t.me/joinchat/хеш —
// старая запись того же приглашения. Хеш различает регистр — не трогаем.
function gw_invite_norm(string $url): string {
    $u = preg_replace('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/~i', 'https://t.me/', trim($url));
    $u = preg_replace('~^https://t\.me/joinchat/~i', 'https://t.me/+', (string)$u);
    return rtrim((string)$u, '/');
}

/**
 * Та ли это ссылка условия. $seen — из обновления chat_member: ссылку,
 * созданную другим админом, Telegram отдаёт с «…» вместо второй половины,
 * и тогда хватает совпадения начала. Слишком короткое начало (меньше
 * четырёх знаков хеша) ничего не доказывает.
 */
function gw_invite_match(string $seen, string $cond): bool {
    $cond = gw_invite_norm($cond);
    $base = 'https://t.me/+';
    if (strpos($cond, $base) !== 0 || strlen($cond) <= strlen($base)) { return false; }
    $seen = gw_invite_norm($seen);
    $cut = strpos($seen, '…');
    if ($cut === false) { $cut = strpos($seen, '...'); }
    if ($cut === false) { return $seen === $cond; }
    $head = substr($seen, 0, $cut);
    return strlen($head) >= strlen($base) + 4 && strpos($cond, $head) === 0;
}

// Ссылки своих условий с каналом: [канал => ссылка], первая на канал.
function gw_cond_links(array $row): array {
    $out = [];
    foreach (gw_conds($row['conds_json'] ?? null) as $c) {
        $id = gw_chat_id($c['chat']);
        if ($id !== null && !isset($out[$id])) { $out[$id] = $c['url']; }
    }
    return $out;
}

// Идущие розыгрыши, которым засчитывается вступление в $channel по ссылке
// $link из своих условий.
function gw_cond_link_ids(PDO $pdo, int $channel, string $link, int $now): array {
    $st = $pdo->prepare("SELECT * FROM giveaways WHERE status = 'live' AND ends_at > :now");
    $st->execute([':now' => $now]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        foreach (gw_conds($row['conds_json'] ?? null) as $c) {
            if (gw_chat_id($c['chat']) === $channel && gw_invite_match($link, $c['url'])) {
                $out[] = (int)$row['id'];
                break;
            }
        }
    }
    return $out;
}

// Состоит ли человек в канале по объекту ChatMember из обновления.
function gw_is_member(array $m): bool {
    $status = (string)($m['status'] ?? '');
    if (in_array($status, ['creator', 'administrator', 'member'], true)) { return true; }
    return $status === 'restricted' && !empty($m['is_member']);
}

/**
 * Обновление chat_member: кто-то вступил в канал или вышел из него.
 * Возвращает, кому бот должен написать «участие снято»:
 * [['chat_id', 'lang', 'row', 'channel'], …].
 */
function gw_member_update(PDO $pdo, array $update, int $now): array {
    $m = $update['chat_member'] ?? null;
    if (!is_array($m)) { return []; }
    $channel = $m['chat']['id'] ?? null;
    $new = is_array($m['new_chat_member'] ?? null) ? $m['new_chat_member'] : [];
    $user = is_array($new['user'] ?? null) ? $new['user'] : [];
    $uid = $user['id'] ?? null;
    if (!is_int($channel) || !is_int($uid) || !empty($user['is_bot'])) { return []; }

    try {
        if (gw_is_member($new)) {
            $link = is_array($m['invite_link'] ?? null) ? (string)($m['invite_link']['invite_link'] ?? '') : '';
            if ($link === '') { return []; }
            $st = $pdo->prepare('SELECT giveaway_id FROM giveaway_links WHERE channel_id = :c AND invite_url = :u');
            $st->execute([':c' => $channel, ':u' => $link]);
            $gids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $gids = array_values(array_unique(array_merge($gids, gw_cond_link_ids($pdo, $channel, $link, $now))));
            $name = tg_display_name($user);
            foreach ($gids as $gid) {
                $up = $pdo->prepare('UPDATE giveaway_subs SET left_at = 0, tg_name = :n
                                      WHERE giveaway_id = :g AND channel_id = :c AND tg_user_id = :u');
                $up->execute([':n' => $name, ':g' => (int)$gid, ':c' => $channel, ':u' => $uid]);
                if ($up->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO giveaway_subs (giveaway_id, channel_id, tg_user_id, tg_name, joined_at, left_at)
                                   VALUES (:g, :c, :u, :n, :at, 0)')
                        ->execute([':g' => (int)$gid, ':c' => $channel, ':u' => $uid, ':n' => $name, ':at' => $now]);
                }
            }
            return [];
        }

        $pdo->prepare('UPDATE giveaway_subs SET left_at = :at WHERE channel_id = :c AND tg_user_id = :u AND left_at = 0')
            ->execute([':at' => $now, ':c' => $channel, ':u' => $uid]);

        $st = $pdo->prepare(
            "SELECT g.* FROM giveaway_entries e JOIN giveaways g ON g.id = e.giveaway_id
              WHERE e.chat_id = :u AND g.status = 'live'"
        );
        $st->execute([':u' => $uid]);
        $notes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($channel, gw_checked_channels($row), true)) { continue; }
            $del = $pdo->prepare('DELETE FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :u');
            $del->execute([':g' => (int)$row['id'], ':u' => $uid]);
            if ($del->rowCount() < 1) { continue; }
            $title = gw_channels($pdo, [$channel])[$channel]['title'] ?? '';
            $lang = $pdo->prepare('SELECT lang FROM tg_links WHERE chat_id = :u LIMIT 1');
            $lang->execute([':u' => $uid]);
            $notes[] = ['chat_id' => $uid, 'lang' => tg_lang($lang->fetchColumn()), 'row' => $row, 'channel' => (string)$title];
        }
        return $notes;
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Отчёт для рекламодателя: откуда пришли участники и кто подписался на
 * каждый канал по ссылке розыгрыша — заведённой ботом или ссылке своего
 * условия. counts = false: у условия публичный адрес канала, а вступление
 * по нему Telegram ни с какой ссылкой не связывает — считать нечего.
 */
function gw_report(PDO $pdo, array $row): array {
    $id = (int)$row['id'];
    $sources = ['tg' => 0, 'pop' => 0, 'page' => 0, '' => 0];
    $st = $pdo->prepare('SELECT src, COUNT(*) AS n FROM giveaway_entries WHERE giveaway_id = :g GROUP BY src');
    $st->execute([':g' => $id]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = gw_src($r['src']);
        $sources[$k] += (int)$r['n'];
    }

    $links = gw_links($pdo, [$id])[$id] ?? [];
    $own = array_diff_key(gw_cond_links($row), $links);
    $links += $own;
    $chIds = gw_checked_channels($row);
    $channels = gw_channels($pdo, $chIds);
    $out = [];
    foreach ($chIds as $cid) {
        $st = $pdo->prepare(
            'SELECT s.tg_user_id, s.tg_name, s.joined_at, s.left_at, l.user_id AS site_id, u.username, u.display_name,
                    e.src, CASE WHEN e.user_id IS NULL THEN 0 ELSE 1 END AS entered
               FROM giveaway_subs s
               LEFT JOIN tg_links l ON l.chat_id = s.tg_user_id
               LEFT JOIN users u ON u.roblox_id = l.user_id
               LEFT JOIN giveaway_entries e ON e.giveaway_id = s.giveaway_id AND e.chat_id = s.tg_user_id
              WHERE s.giveaway_id = :g AND s.channel_id = :c
              ORDER BY s.joined_at, s.tg_user_id'
        );
        $st->execute([':g' => $id, ':c' => $cid]);
        $list = [];
        $seen = [];
        $stayed = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $tgId = (string)$s['tg_user_id'];
            if (isset($seen[$tgId])) { continue; }
            $seen[$tgId] = true;
            if ((int)$s['left_at'] === 0) { $stayed++; }
            $list[] = [
                'tg_id'     => $tgId,
                'tg_name'   => (string)$s['tg_name'],
                'joined_at' => (int)$s['joined_at'],
                'left_at'   => (int)$s['left_at'],
                'site_id'   => $s['site_id'] === null ? '' : (string)$s['site_id'],
                'site_name' => (string)($s['display_name'] ?? '') !== '' ? (string)$s['display_name'] : (string)($s['username'] ?? ''),
                'entered'   => (int)$s['entered'] === 1,
                'src'       => gw_src($s['src']),
            ];
        }
        $out[] = [
            'id'     => (string)$cid,
            'title'  => isset($channels[$cid]) ? (string)$channels[$cid]['title'] : (string)$cid,
            'link'   => $links[$cid] ?? '',
            'own'    => isset($own[$cid]),
            'counts' => !isset($own[$cid]) || strpos(gw_invite_norm($own[$cid]), 'https://t.me/+') === 0,
            'joined' => count($list),
            'stayed' => $stayed,
            'list'   => $list,
        ];
    }
    return ['sources' => $sources, 'channels' => $out];
}

function gw_joined_text(array $row, string $lang): string {
    $title = gw_title($row, $lang);
    $date  = gw_date((int)$row['ends_at'], $lang);
    $cond  = gw_checked_channels($row) ? true : false;
    if ($lang === 'en') {
        return "✅ You're in the giveaway «" . $title . "» on maknemy.com.\n\nResults: " . $date . '.'
            . ($cond ? "\n\nDon't unsubscribe from the channels in the conditions: if you do, your entry is removed automatically." : '')
            . "\n\nIf you win, I'll message you here.";
    }
    return "✅ Вы участвуете в розыгрыше «" . $title . "» на maknemy.com.\n\nИтоги — " . $date . '.'
        . ($cond ? "\n\nНе отписывайтесь от каналов из условий: если отпишетесь, участие снимется автоматически." : '')
        . "\n\nО победе я напишу сюда.";
}

function gw_left_text(array $row, string $channel, string $lang, int $now): string {
    $title = gw_title($row, $lang);
    $open  = gw_phase($row, $now) === 'live';
    $date  = gw_date((int)$row['ends_at'], $lang);
    if ($lang === 'en') {
        return "❌ You unsubscribed from «" . $channel . "», so your entry in the giveaway «" . $title . "» was removed."
            . ($open ? "\n\nTo get back in, subscribe again and tap «Enter» on the site before " . $date . '.' : '');
    }
    return "❌ Вы отписались от канала «" . $channel . "», поэтому участие в розыгрыше «" . $title . "» снято."
        . ($open ? "\n\nЧтобы вернуться, подпишитесь снова и нажмите «Участвовать» на сайте до " . $date . '.' : '');
}

// Только что вступил — бот подтверждает. Ответ сайту уже ушёл.
function gw_after_join(PDO $pdo, array $cfg, array $note, ?callable $send = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    try {
        $lang = tg_lang($note['lang']);
        $msg = tg_message_buttons((int)$note['chat_id'], gw_joined_text($note['row'], $lang),
            [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$note['row']['id'], false, gw_is_test($note['row']))]]);
        $chat = (int)$note['chat_id'];
        tg_after_send($pdo, $chat, ($send ?? tg_http($tg['token']))('sendMessage', $msg));
    } catch (Throwable $e) {
        error_log('giveaway joined: ' . $e->getMessage());
    }
}

// Отписавшимся — что участие снято. Ответ Telegram на вебхук уже ушёл.
function gw_after_member(PDO $pdo, array $cfg, array $notes, int $now, ?callable $send = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg) || !$notes) { return; }
    tg_finish_response();
    $send = $send ?? tg_http($tg['token']);
    foreach ($notes as $n) {
        try {
            $lang = tg_lang($n['lang']);
            $msg = tg_message_buttons((int)$n['chat_id'], gw_left_text($n['row'], $n['channel'], $lang, $now),
                [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$n['row']['id'], false, gw_is_test($n['row']))]]);
            tg_after_send($pdo, (int)$n['chat_id'], $send('sendMessage', $msg));
        } catch (Throwable $e) {
            error_log('giveaway left: ' . $e->getMessage());
        }
    }
}
