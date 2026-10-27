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
// ещё раз при розыгрыше: отписавшийся после участия приз не получает (если у
// розыгрыша включено снятие при отписке — см. «Отписка» ниже).
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
// Когда итоги, админ выбирает сам (поле end_by): «по времени» — дата конца
// без лимита участников; «по числу участников» — лимит без даты. У второго в
// ends_at лежит GW_NO_DEADLINE: так «идёт, пока ends_at в будущем» работает
// без новой колонки и без миграции, а показывают вместо даты лимит.
//
// Лимит участников (колонка max_entries, 0 — без лимита). Кто занял последнее
// место, тот закрывает приём: ends_at становится «сейчас», и дальше розыгрыш
// живёт как закончившийся по времени. У розыгрышей, сохранённых до выбора
// end_by, есть и дата, и лимит — приём закрывает то, что наступит раньше.
// Последнее место разыгрывается под замком строки розыгрыша (gw_add_entry()),
// чтобы два одновременных нажатия не прошли оба.
//
// Кто выигрывает. Итоги подводятся сами, как только розыгрыш закончился —
// по времени или по лимиту (gw_after_due()): отдельного cron нет, проход
// запускает первый же запрос к /api/giveaways.php после конца (окно
// спрашивает его почти на каждой странице), а при лимите — сразу вступление
// последнего участника. Кнопка «Разыграть» в админке осталась на случай,
// когда сам проход не вышел (Telegram не ответил). Призы разыгрываются по
// порядку, один человек — максимум один приз. Выбор — random_int по
// перемешанному списку участников.
//
// Что пишет бот. О старте — всем подключившим бота, мимо переключателей в
// профиле (так решил владелец: о розыгрышах знать должны все), кроме тех, кто
// сам отписался от розыгрышей на /giveaways (gw_prefs, lib/gw_prefs.php). Там
// же подписчик выбирает напоминания: за сколько до итогов и за сколько мест до
// лимита (раздел «Напоминания подписчикам»). О победе — каждому победителю.
// Связывается с победителем владелец сам. Ещё бот публикует розыгрыш постом
// в отмеченные каналы и дописывает туда итоги (раздел «Пост в канале»).
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
// Отписка. Вышел из канала условий — у его строки giveaway_entries ставится
// left_at, бот пишет ему, и выиграть он уже не может. Строка не удаляется:
// это доказательство, что человек вступал подписанным. Окно снова зовёт его
// участвовать — подписался заново и нажал, строка оживает. Узнаёт об отписке
// бот тремя путями: вебхук chat_member (сразу, но на бою Telegram его не
// присылал), фоновая перепроверка getChatMember (gw_sweep(), понемногу после
// ответов /api/giveaways.php) и итоги. Сообщение одно — при отметке.
//
// Галочка «Гарантия» (колонка drop_unsub, по умолчанию включена) решает
// только одно — занимает ли отписавшийся место. Включена: он выбывает из
// участников — не считается и не занимает место в лимите, так что число
// участников всегда равно числу подписчиков. Выключена: остаётся в числе
// участников и занимает место, но выиграть всё равно не может. Учёт
// подписчиков для рекламодателя (giveaway_subs) ведётся в обоих случаях.
// До миграции 2026-10-18-giveaway-unsub.sql строка удаляется, перепроверки
// в фоне нет.
//
// Ничего из этого не роняет остальной сайт: нет таблиц — окно молчит,
// страница пуста, админка просит миграцию.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/gw_prefs.php';
require_once __DIR__ . '/news_blocks.php';
require_once __DIR__ . '/translate.php';
require_once __DIR__ . '/i18n_content.php';

const GW_TITLE_MAX       = 120;
const GW_PRIZES_MAX      = 20;
const GW_PRIZE_NAME_MAX  = 60;
const GW_PRIZE_COUNT_MAX = 1000;
const GW_CHANNELS_MAX    = 5;
const GW_ENTRIES_MAX     = 1000000;
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
// ends_at розыгрыша «по числу участников»: 2100-01-01, верхняя граница поля.
// Раньше лимит его не наступит, а набранный лимит ставит ends_at = «сейчас».
const GW_NO_DEADLINE     = 4102444800;
const GW_END_BY          = ['time', 'entries'];
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

    // Каналы, куда бот публикует пост розыгрыша (раздел «Пост в канале»).
    $posts = [];
    $raw = $in['posts'] ?? [];
    if (!nb_is_list($raw) || count($raw) > GW_CHANNELS_MAX) { return $fail('bad posts'); }
    foreach ($raw as $c) {
        $id = gw_chat_id($c);
        if ($id === null) { return $fail('bad posts'); }
        if (!in_array($id, $posts, true)) { $posts[] = $id; }
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
    if (!nb_is_int_between($ends, 0, GW_NO_DEADLINE)) { return $fail('bad ends_at'); }

    $max = $in['max_entries'] ?? 0;
    if (!nb_is_int_between($max, 0, GW_ENTRIES_MAX)) { return $fail('bad max_entries'); }

    // Без end_by — как до выбора: и дата, и лимит, что наступит раньше.
    $endBy = $in['end_by'] ?? null;
    if ($endBy !== null && !in_array($endBy, GW_END_BY, true)) { return $fail('bad end_by'); }
    if ($endBy === 'entries') { $ends = GW_NO_DEADLINE; }
    if ($endBy === 'time') { $max = 0; }

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
        'posts'         => $posts,
        'conds'         => $conds,
        'ends_at'       => (int)$ends,
        'max_entries'   => (int)$max,
        'test'          => ($in['test'] ?? false) === true,
        'drop_unsub'    => ($in['drop_unsub'] ?? true) !== false,
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

// Колонку max_entries добавляет миграция 2026-10-15-giveaway-max-entries.sql.
// До неё лимита нет ни у кого, а поставить его админка не даёт.
function gw_has_max(PDO $pdo): bool {
    try {
        $pdo->query('SELECT max_entries FROM giveaways LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Колонку drop_unsub добавляет миграция 2026-10-18-giveaway-unsub.sql.
// До неё гарантия у всех, а снять галочку админка не даёт.
function gw_has_drop(PDO $pdo): bool {
    try {
        $pdo->query('SELECT drop_unsub FROM giveaways LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Гарантия: отписавшийся выбывает из участников и освобождает место. Нет
// колонки — да, как до галочки.
function gw_drops_unsub(array $row): bool {
    return !array_key_exists('drop_unsub', $row) || !empty($row['drop_unsub']);
}

// Колонки left_at и checked_at у giveaway_entries — та же миграция.
function gw_has_left(PDO $pdo): bool {
    try {
        $pdo->query('SELECT left_at, checked_at FROM giveaway_entries LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// «Занимает место» — для числа участников и лимита. Отписавшийся занимает,
// если у розыгрыша нет гарантии. Запросу нужны giveaway_entries e и
// giveaways g.
function gw_counted_sql(PDO $pdo): string {
    if (!gw_has_left($pdo)) { return ''; }
    return gw_has_drop($pdo) ? ' AND (e.left_at = 0 OR g.drop_unsub = 0)' : ' AND e.left_at = 0';
}

// «Ещё участвует» в запрос к giveaway_entries под псевдонимом $e. До
// миграции отписавшихся в таблице нет — условие не нужно.
function gw_active_sql(PDO $pdo, string $e = 'e'): string {
    return gw_has_left($pdo) ? ' AND ' . $e . '.left_at = 0' : '';
}

// Лимит участников, 0 — без лимита.
function gw_max(array $row): int {
    return max(0, (int)($row['max_entries'] ?? 0));
}

// Розыгрыш «по числу участников»: даты конца нет, итоги — когда наберётся
// лимит. Набранный лимит ставит настоящий ends_at, и даты снова «есть».
function gw_no_deadline(array $row): bool {
    return (int)$row['ends_at'] >= GW_NO_DEADLINE;
}

// Как его показывать: entries — лимит вместо даты, time — дата.
function gw_end_by(array $row): string {
    return gw_no_deadline($row) ? 'entries' : 'time';
}

// Сколько всего мест-победителей во всех призах.
function gw_slots_total(array $row): int {
    $n = 0;
    foreach (gw_decode_list($row['prizes_json'] ?? null) as $p) {
        if (is_array($p)) { $n += max(1, (int)($p['count'] ?? 1)); }
    }
    return $n;
}

// Набран ли лимит.
function gw_full(PDO $pdo, array $row): bool {
    $max = gw_max($row);
    return $max > 0 && (gw_entry_counts($pdo, [(int)$row['id']])[(int)$row['id']] ?? 0) >= $max;
}

// Лимит набран, а время конца ещё впереди — приём закрывается сейчас. Так
// бывает после последнего участника и когда лимит в админке опустили ниже
// числа уже вступивших. true — закрыли.
function gw_close_full(PDO $pdo, array $row, int $now): bool {
    if ($row['status'] !== 'live' || (int)$row['ends_at'] <= $now || !gw_full($pdo, $row)) { return false; }
    $pdo->prepare('UPDATE giveaways SET ends_at = :now, updated_at = :now2 WHERE id = :id AND ends_at > :now3')
        ->execute([':now' => $now, ':now2' => $now, ':now3' => $now, ':id' => (int)$row['id']]);
    return true;
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
    if (gw_no_deadline($row)) {
        if (gw_max($row) <= 0) { $out[] = 'no_max'; }
    }
    elseif ($ends <= $now) { $out[] = 'ends_in_past'; }
    elseif ($ends > $now + GW_MAX_SPAN) { $out[] = 'ends_too_far'; }
    foreach (gw_checked_channels($row) as $id) {
        $c = $channels[$id] ?? null;
        if (!$c || (int)$c['is_admin'] !== 1) { $out[] = 'channel_not_admin'; break; }
    }
    if (gw_max($row) > 0 && gw_max($row) < gw_slots_total($row)) { $out[] = 'max_below_prizes'; }
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

// Сколько участвует — на карточке и в лимите участников. Отписавшиеся (left_at)
// считаются только у розыгрыша без гарантии (gw_counted_sql()).
function gw_entry_counts(PDO $pdo, array $ids): array {
    if (!$ids) { return []; }
    $st = $pdo->query(
        'SELECT e.giveaway_id, COUNT(*) AS n FROM giveaway_entries e JOIN giveaways g ON g.id = e.giveaway_id
          WHERE e.giveaway_id IN (' . implode(',', array_map('intval', $ids)) . ')' . gw_counted_sql($pdo) . '
          GROUP BY e.giveaway_id'
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
        'end_by'       => gw_end_by($row),
        'published_at' => (int)$row['published_at'],
        'entries'      => $counts[$id] ?? 0,
        'max_entries'  => gw_max($row),
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

// Список для окна и страницы на одном языке (?lang=). Переводы из таблицы
// нужны только восьми языкам: EN розыгрыша — в колонках.
function gw_localize_list(PDO $pdo, array $list, string $lang): array {
    $ids = array_merge(array_column($list['live'], 'id'), array_column($list['past'], 'id'));
    $rows = in_array($lang, I18N_CONTENT_LANGS, true) ? i18n_rows($pdo, 'gw', $lang, $ids) : [];
    foreach (['live', 'past'] as $k) {
        foreach ($list[$k] as $i => $g) {
            $list[$k][$i] = i18n_gw_localize($g, $lang, $rows[(string)$g['id']] ?? []);
        }
    }
    return $list;
}

// Места с текстом у розыгрыша в базе — для фонового перевода. Номера
// условий те же, что у gw_public_item(): оба идут по gw_conds().
function gw_i18n_load(PDO $pdo, int $id): array {
    $row = gw_load($pdo, $id);
    if (!$row) { return []; }
    return [(string)$id => i18n_units_gw((string)$row['title_ru'], gw_decode_blocks($row['body_json']), gw_conds($row['conds_json'] ?? null))];
}

function gw_i18n_job(PDO $pdo, int $id, callable $requestFor, float $deadline): array {
    $load = function () use ($pdo, $id): array { return gw_i18n_load($pdo, $id); };
    return i18n_fill($pdo, 'gw', $load, I18N_CONTENT_LANGS, $requestFor, $deadline);
}

// Где человек уже участвует среди идущих розыгрышей — для окна: оно не
// зовёт туда, где человек уже есть. Нет таблиц — пусто.
function gw_joined_ids(PDO $pdo, string $me, int $now): array {
    if ($me === '') { return []; }
    try {
        $st = $pdo->prepare(
            "SELECT e.giveaway_id FROM giveaway_entries e JOIN giveaways g ON g.id = e.giveaway_id
              WHERE e.user_id = :u AND g.status = 'live' AND g.ends_at > :now" . gw_active_sql($pdo)
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
 *   full      — набран лимит участников;
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
    if (!$row || gw_phase($row, $now) !== 'live') {
        return [409, ['ok' => false, 'error' => $row && $row['status'] === 'live' && gw_full($pdo, $row) ? 'full' : 'closed']];
    }

    // Отписавшийся (строка с left_at) вступает заново: проверка подписки та
    // же, а gw_add_entry() оживляет его строку.
    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries e WHERE e.giveaway_id = :g AND e.user_id = :u' . gw_active_sql($pdo));
    $st->execute([':g' => $id, ':u' => $me]);
    if ($st->fetchColumn() !== false) { return [200, ['ok' => true, 'joined' => true]]; }

    $st = $pdo->prepare('SELECT chat_id, lang FROM tg_links WHERE user_id = :u');
    $st->execute([':u' => $me]);
    $link = $st->fetch(PDO::FETCH_ASSOC);
    if (!$link) { return [409, ['ok' => false, 'error' => 'tg']]; }
    $chat = (int)$link['chat_id'];

    $st = $pdo->prepare('SELECT user_id FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
    $st->execute([':g' => $id, ':c' => $chat]);
    $owner = $st->fetchColumn();
    if ($owner !== false && (string)$owner !== $me) { return [409, ['ok' => false, 'error' => 'tg_used']]; }

    $channels = gw_checked_channels($row);
    if ($channels) {
        $chk = gw_check_channels($send, $channels, $chat);
        if ($chk['error']) { return [503, ['ok' => false, 'error' => 'tg_down']]; }
        if (!$chk['ok']) { return [409, ['ok' => false, 'error' => 'channels', 'missing' => $chk['missing']]]; }
    }

    [$err, $row] = gw_add_entry($pdo, $id, $me, $chat, $now, gw_src($src));
    if ($err === 'closed' || $err === 'full') { return [409, ['ok' => false, 'error' => $err]]; }
    if ($err === 'dup') {
        // Гонка двух вкладок или двух аккаунтов с одним Telegram: ключ
        // не пустил вторую запись. Кто именно — решает повторная проверка.
        $st = $pdo->prepare('SELECT user_id FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
        $st->execute([':g' => $id, ':c' => $chat]);
        $who = $st->fetchColumn();
        if ($who !== false && (string)$who === $me) { return [200, ['ok' => true, 'joined' => true]]; }
        return [409, ['ok' => false, 'error' => 'tg_used']];
    }
    // Занял последнее место — окно и бот скажут «приём закрыт» вместо даты итогов.
    $last = (int)$row['ends_at'] <= $now;
    return [200, ['ok' => true, 'joined' => true] + ($last ? ['last' => true] : []),
        ['chat_id' => $chat, 'lang' => tg_lang($link['lang']), 'row' => $row, 'last' => $last, 'user' => $me]];
}

/**
 * Записать участника. [ошибка, строка розыгрыша после записи]; ошибка
 * пустая — записан, иначе closed, full или dup (ключ не пустил: этот человек
 * или этот Telegram уже участвует).
 *
 * Всё под замком строки розыгрыша: UPDATE ставит его до конца транзакции, и
 * второе одновременное нажатие ждёт, пока первое не запишется. Без этого два
 * человека на последнее место прошли бы оба. Последний участник закрывает
 * приём (gw_close_full()), и строка возвращается уже с новым ends_at.
 */
function gw_add_entry(PDO $pdo, int $id, string $me, int $chat, int $now, string $src): array {
    $hasMax = gw_has_max($pdo);
    $hasLeft = gw_has_left($pdo);
    $pdo->beginTransaction();
    try {
        if ($hasMax) {
            $pdo->prepare('UPDATE giveaways SET max_entries = max_entries WHERE id = :id')->execute([':id' => $id]);
        }
        $row = gw_load($pdo, $id);
        if (!$row || gw_phase($row, $now) !== 'live') {
            $full = $row && $row['status'] === 'live' && gw_full($pdo, $row);
            $pdo->rollBack();
            return [$full ? 'full' : 'closed', $row];
        }
        if (gw_close_full($pdo, $row, $now)) {
            $pdo->commit();
            return ['full', gw_load($pdo, $id)];
        }
        // Строка отписавшегося оживает: первое вступление и источник остаются.
        $back = 0;
        if ($hasLeft) {
            $up = $pdo->prepare('UPDATE giveaway_entries SET left_at = 0, checked_at = :at, chat_id = :c
                                  WHERE giveaway_id = :g AND user_id = :u AND left_at > 0');
            $up->execute([':at' => $now, ':c' => $chat, ':g' => $id, ':u' => $me]);
            $back = $up->rowCount();
        }
        if ($back === 0) {
            $pdo->prepare('INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at, src'
                    . ($hasLeft ? ', checked_at' : '') . ') VALUES (:g, :u, :c, :at, :src' . ($hasLeft ? ', :at2' : '') . ')')
                ->execute([':g' => $id, ':u' => $me, ':c' => $chat, ':at' => $now, ':src' => $src] + ($hasLeft ? [':at2' => $now] : []));
        }
        if (gw_close_full($pdo, $row, $now)) { $row = gw_load($pdo, $id); }
        $pdo->commit();
        return ['', $row];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return ['dup', null];
    }
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
    $hasMax = gw_has_max($pdo);
    if ($r['max_entries'] > 0 && !$hasMax) { return [409, ['ok' => false, 'error' => 'max_migrate']]; }
    $hasDrop = gw_has_drop($pdo);
    if (!$r['drop_unsub'] && !$hasDrop) { return [409, ['ok' => false, 'error' => 'drop_migrate']]; }
    $hasPosts = gw_has_posts($pdo);
    if ($r['posts'] && !$hasPosts) { return [409, ['ok' => false, 'error' => 'posts_migrate']]; }

    $old = null;
    if ($id > 0) {
        $old = gw_load($pdo, $id);
        if (!$old) { return [404, ['ok' => false, 'error' => 'not found']]; }
        if ($old['status'] === 'drawn') { return [409, ['ok' => false, 'error' => 'drawn']]; }
        $r['blocks'] = gw_reuse_en($r['blocks'], gw_decode_blocks($old['body_json']));
        if ($old['status'] !== 'draft') { $r['test'] = gw_is_test($old); }
        // Закончившийся (лимит набран или «Завершить досрочно») правка
        // «по числу участников» не открывает заново: даты в форме нет, и
        // сохранение описания иначе вернуло бы приём.
        if (gw_no_deadline($r) && gw_phase($old, $now) === 'ended') { $r['ends_at'] = (int)$old['ends_at']; }
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
    if ($hasMax) { $cols[':max'] = $r['max_entries']; }
    if ($hasDrop) { $cols[':drop'] = $r['drop_unsub'] ? 1 : 0; }
    if ($id > 0) {
        $pdo->prepare(
            'UPDATE giveaways SET title_ru = :title_ru, title_en = :title_en, image_url = :image_url,
                    image_w = :image_w, image_h = :image_h, body_json = :body, prizes_json = :prizes,
                    channels_json = :channels, ' . ($hasConds ? 'conds_json = :conds, ' : '')
                    . ($hasTest ? 'test = :test, ' : '') . ($hasMax ? 'max_entries = :max, ' : '')
                    . ($hasDrop ? 'drop_unsub = :drop, ' : '')
                    . 'ends_at = :ends, updated_at = :now
              WHERE id = :id'
        )->execute($cols + [':id' => $id]);
        // Лимит опустили до числа уже вступивших (или ниже) — приём закрыт.
        $saved = gw_load($pdo, $id);
        if ($saved) { gw_close_full($pdo, $saved, $now); }
    } else {
        $pdo->prepare(
            "INSERT INTO giveaways (status, title_ru, title_en, image_url, image_w, image_h, body_json,
                    prizes_json, channels_json, " . ($hasConds ? 'conds_json, ' : '') . ($hasTest ? 'test, ' : '')
                    . ($hasMax ? 'max_entries, ' : '') . ($hasDrop ? 'drop_unsub, ' : '') . "ends_at, created_at, updated_at)
             VALUES ('draft', :title_ru, :title_en, :image_url, :image_w, :image_h, :body, :prizes,
                    :channels, " . ($hasConds ? ':conds, ' : '') . ($hasTest ? ':test, ' : '') . ($hasMax ? ':max, ' : '')
                    . ($hasDrop ? ':drop, ' : '') . ":ends, :now, :now2)"
        )->execute($cols + [':now2' => $now]);
        $id = (int)$pdo->lastInsertId();
    }
    // Без поля posts (старая админка) план постов не трогается.
    if ($hasPosts && array_key_exists('posts', $body)) { gw_posts_plan($pdo, $id, $r['posts']); }
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
    // Вышедший пост остаётся в канале — его владелец убирает сам.
    if (gw_has_posts($pdo)) { $pdo->prepare('DELETE FROM giveaway_posts WHERE giveaway_id = :id')->execute([':id' => $id]); }
    $pdo->prepare('DELETE FROM giveaways WHERE id = :id')->execute([':id' => $id]);
    return [200, ['ok' => true]];
}

function gw_rand_default(): callable {
    return function (int $n): int { return random_int(0, $n - 1); };
}

/**
 * Разыграть закончившийся розыгрыш. [код, тело, победители для бота,
 * отписавшиеся для бота — те же записи, что у gw_member_update()].
 *
 * Участники перемешиваются, и призы по порядку раздаются первым подходящим:
 * не забанен, бот всё ещё подключён (иначе о победе не сказать) и подписан на
 * все каналы прямо сейчас. Не ответил Telegram хоть раз — розыгрыш не
 * записывается вовсе: «не смог проверить» не должно значить «проиграл».
 * Уже отписавшиеся (left_at) в розыгрыш не попадают, а найденные сейчас
 * получают left_at и сообщение — с гарантией и без.
 *
 * $rand(n) → число 0..n-1; тесты подставляют предсказуемое.
 */
function gw_draw(PDO $pdo, array $cfg, callable $send, int $id, int $now, ?callable $rand = null): array {
    $rand = $rand ?? gw_rand_default();
    $row = gw_load($pdo, $id);
    if (!$row) { return [404, ['ok' => false, 'error' => 'not found'], [], []]; }
    if ($row['status'] === 'drawn') { return [409, ['ok' => false, 'error' => 'drawn'], [], []]; }
    if (gw_phase($row, $now) !== 'ended') { return [409, ['ok' => false, 'error' => 'not ended'], [], []]; }

    $prizes   = gw_decode_list($row['prizes_json']);
    $channels = gw_checked_channels($row);
    $banned   = array_flip(site_banned_ids($cfg, $now, $pdo));

    $hasLeft  = gw_has_left($pdo);

    $st = $pdo->prepare(
        'SELECT e.user_id, l.chat_id, l.lang FROM giveaway_entries e LEFT JOIN tg_links l ON l.user_id = e.user_id
          WHERE e.giveaway_id = :g' . gw_active_sql($pdo) . ' ORDER BY e.joined_at, e.user_id'
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
    $gone = [];
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
            if ($chk['error']) { return [503, ['ok' => false, 'error' => 'tg_down'], [], []]; }
            if (!$chk['ok']) {
                $skipped++;
                $gone[$uid] = gw_left_note($pdo, $row, (int)$cand['chat_id'], (int)$chk['missing'][0]);
                continue;
            }
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
            return [409, ['ok' => false, 'error' => 'drawn'], [], []];
        }
        $pdo->prepare('UPDATE giveaway_entries SET prize = NULL WHERE giveaway_id = :g')->execute([':g' => $id]);
        $up = $pdo->prepare('UPDATE giveaway_entries SET prize = :p WHERE giveaway_id = :g AND user_id = :u');
        foreach ($winners as $w) { $up->execute([':p' => $w['prize'], ':g' => $id, ':u' => $w['user_id']]); }
        if ($hasLeft && $gone) {
            $mark = $pdo->prepare('UPDATE giveaway_entries SET left_at = :at WHERE giveaway_id = :g AND user_id = :u AND left_at = 0');
            foreach (array_keys($gone) as $uid) { $mark->execute([':at' => $now, ':g' => $id, ':u' => (string)$uid]); }
        }
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
    ], $winners, array_values($gone)];
}

// Розыгрыш для админки: все поля и участники с победителями.
function gw_admin_item(PDO $pdo, array $row, int $now): array {
    $id = (int)$row['id'];
    // Отписавшиеся — в конце списка, с датой отписки: это след того, что они
    // вступали подписанными.
    $hasLeft = gw_has_left($pdo);
    $st = $pdo->prepare(
        'SELECT e.user_id, e.chat_id, e.joined_at, e.prize, e.src, ' . ($hasLeft ? 'e.left_at' : '0 AS left_at') . ',
                u.username, u.display_name, u.avatar_url, l.tg_name
           FROM giveaway_entries e
           LEFT JOIN users u ON u.roblox_id = e.user_id
           LEFT JOIN tg_links l ON l.user_id = e.user_id
          WHERE e.giveaway_id = :g
          ORDER BY CASE WHEN e.prize IS NULL THEN 1 ELSE 0 END, e.prize, '
            . ($hasLeft ? 'CASE WHEN e.left_at = 0 THEN 0 ELSE 1 END, ' : '') . 'e.joined_at DESC
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
            'left_at'   => (int)$e['left_at'],
        ];
    }
    $count = gw_entry_counts($pdo, [$id])[$id] ?? 0;
    $left = 0;
    if ($hasLeft) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM giveaway_entries WHERE giveaway_id = :g AND left_at > 0');
        $st->execute([':g' => $id]);
        $left = (int)$st->fetchColumn();
    }
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
        'end_by'       => gw_end_by($row),
        'published_at' => (int)$row['published_at'],
        'drawn_at'     => (int)$row['drawn_at'],
        'test'         => gw_is_test($row),
        'drop_unsub'   => gw_drops_unsub($row),
        'entries'      => $count,
        'left'         => $left,
        'rows'         => gw_drops_unsub($row) ? $count + $left : $count,
        'max_entries'  => gw_max($row),
        'posts'        => gw_admin_posts($pdo, $row),
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
            'end_by'  => gw_end_by($r),
            'entries' => $counts[(int)$r['id']] ?? 0,
            'max'     => gw_max($r),
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

// Посты розыгрыша для админки: [{chat, posted, url, results}]. Нет таблицы —
// null, и раздела «Пост в Telegram» в админке нет.
function gw_admin_posts(PDO $pdo, array $row): ?array {
    if (!gw_has_posts($pdo)) { return null; }
    $posts = gw_posts($pdo, (int)$row['id']);
    $channels = gw_channels($pdo, array_keys($posts));
    $out = [];
    foreach ($posts as $chat => $p) {
        $c = $channels[$chat] ?? null;
        $out[] = [
            'chat'    => (string)$chat,
            'posted'  => (int)$p['message_id'] > 0,
            'url'     => $c ? gw_post_url($c, (int)$p['message_id']) : '',
            'results' => (int)$p['results_at'] > 0,
        ];
    }
    return $out;
}

// Каналы последнего розыгрыша с постом — их админка отмечает у нового.
function gw_post_last(PDO $pdo): array {
    if (!gw_has_posts($pdo)) { return []; }
    $st = $pdo->query('SELECT chat_id FROM giveaway_posts WHERE giveaway_id = (SELECT MAX(giveaway_id) FROM giveaway_posts) ORDER BY chat_id');
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
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

// Хвост к дате итогов, когда есть лимит участников.
function gw_max_note(array $row, string $lang): string {
    $max = gw_max($row);
    if ($max <= 0) { return ''; }
    return $lang === 'en' ? ' or earlier, once the entry count reaches ' . $max
                          : ' или раньше, когда участников станет ' . $max;
}

// Когда итоги — для сообщений бота: дата или, у розыгрыша «по числу
// участников», лимит.
function gw_when(array $row, string $lang): string {
    if (gw_no_deadline($row)) {
        return $lang === 'en' ? 'once ' . gw_max($row) . ' people have entered'
                              : 'когда участников станет ' . gw_max($row);
    }
    return gw_date((int)$row['ends_at'], $lang) . gw_max_note($row, $lang);
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
    $date  = gw_when($row, $lang);
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

// Кому писать о старте: всем подключившим бота, кроме отписавшихся от
// розыгрышей. Таблицы gw_prefs нет — всем, как было до подписки.
function gw_announce_rows(PDO $pdo): array {
    try {
        return $pdo->query(
            'SELECT l.chat_id, l.lang FROM tg_links l LEFT JOIN gw_prefs p ON p.user_id = l.user_id
              WHERE p.user_id IS NULL OR p.subscribed = 1 ORDER BY l.linked_at, l.user_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $pdo->query('SELECT chat_id, lang FROM tg_links ORDER BY linked_at, user_id')->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Рассылка о старте — всем, кто подключил бота, без учёта переключателей
 * профиля, но без отписавшихся от розыгрышей. Сколько ушло.
 */
function gw_announce(PDO $pdo, callable $send, array $row, ?callable $sleep = null): int {
    try {
        $rows = gw_announce_rows($pdo);
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

// $gone — отписавшиеся, найденные на итогах (четвёртый элемент gw_draw()).
function gw_after_draw(PDO $pdo, array $cfg, array $row, array $winners, ?callable $send = null, ?callable $sleep = null, array $gone = []): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg) || (!$winners && !$gone)) { return; }
    tg_finish_response();
    tg_long_run();
    $send = $send ?? tg_http($tg['token']);
    try {
        if ($winners) { gw_notify_winners($pdo, $send, $row, $winners, $sleep); }
        if ($gone) { gw_left_send($pdo, $send, $gone, time(), $sleep); }
    } catch (Throwable $e) {
        error_log('giveaway winners: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
//  Пост в канале
// --------------------------------------------------------------------------
//
// Идущий розыгрыш бот публикует в каналы, отмеченные у него в админке
// («Пост в Telegram»), — по посту в каждый. Пост собран из того же, что
// карточка на сайте: баннер над текстом (превью ссылки, как у рассылки о
// старте), название, описание с оформлением, призы, условия и срок итогов, а
// под ним кнопка «Участвовать» на карточку розыгрыша. Нажавший её помечен
// как пришедший из канала (src ch) — это видно в отчёте рекламодателю.
//
// Пока розыгрыш идёт, каждое сохранение в админке правит пост: поменяли
// приз или срок — в канале то же самое. После итогов бот переписывает пост:
// победители по призам и сколько было участников, кнопка ведёт на итоги.
// Именно правка, а не новый пост: победители оказываются там, где люди
// нажимали «Участвовать».
//
// Текст — HTML-разметка Telegram (parse_mode HTML), всё от людей проходит
// через gw_html(). Язык поста — русский: каналы владельца русскоязычные.
// Сообщение, а не фото с подписью: у подписи предел 1024 знака, а список
// победителей бывает на полсотни строк, у сообщения — 4096.
//
// Отметили канал у черновика — пост выйдет при публикации; у идущего —
// сразу при сохранении. Не вышел (Telegram не ответил) — выйдет при
// следующем сохранении. Вышедший пост галочкой не снимается: удалить
// сообщение старше 48 часов бот не может, и итоги допишутся туда. Удалили
// пост в канале руками — бот это узнает при правке и забудет пост.
//
// Нет таблицы giveaway_posts (миграция 2026-10-27-giveaway-posts.sql) —
// раздела в админке нет, остальное работает как раньше.

const GW_POST_LANG = 'ru';
// Предел сообщения — 4096 знаков без разметки. Считаем с разметкой: запас.
const GW_POST_MAX = 4000;
// Описание в посте не длиннее этого: призы, условия и срок важнее.
const GW_POST_BODY_MAX = 1500;
// Победителей больше — список свёрнут, разворачивается нажатием.
const GW_POST_FOLD = 10;

function gw_has_posts(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM giveaway_posts LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Посты розыгрыша по каналам: [chat_id => строка giveaway_posts].
function gw_posts(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT * FROM giveaway_posts WHERE giveaway_id = :g ORDER BY chat_id');
    $st->execute([':g' => $id]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['chat_id']] = $r; }
    return $out;
}

// Отмеченные в админке каналы: новые — в план, снятые до выхода поста — из
// плана. Вышедший пост остаётся.
function gw_posts_plan(PDO $pdo, int $id, array $chats): void {
    $have = gw_posts($pdo, $id);
    $add = $pdo->prepare('INSERT INTO giveaway_posts (giveaway_id, chat_id) VALUES (:g, :c)');
    foreach ($chats as $c) {
        if (!isset($have[$c])) { $add->execute([':g' => $id, ':c' => $c]); }
    }
    $del = $pdo->prepare('DELETE FROM giveaway_posts WHERE giveaway_id = :g AND chat_id = :c AND message_id = 0');
    foreach (array_keys($have) as $c) {
        if (!in_array($c, $chats, true)) { $del->execute([':g' => $id, ':c' => $c]); }
    }
}

// Ссылка на пост: у публичного канала t.me/имя/номер, у приватного
// t.me/c/<id без -100>/номер — откроется у подписчиков.
function gw_post_url(array $channel, int $messageId): string {
    if ($messageId <= 0) { return ''; }
    if ((string)$channel['username'] !== '') { return 'https://t.me/' . $channel['username'] . '/' . $messageId; }
    $id = (string)$channel['chat_id'];
    if (strpos($id, '-100') !== 0) { return ''; }
    return 'https://t.me/c/' . substr($id, 4) . '/' . $messageId;
}

// Кнопка поста: карточка розыгрыша с пометкой -ch — страница запомнит, что
// человек пришёл из канала.
function gw_post_link(int $id, bool $test): string {
    return TG_SITE . '/giveaways' . ($test ? '?test' : '') . '#g' . $id . '-ch';
}

function gw_html(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Кусок описания с оформлением карточки: жирный, курсив, подчёркнутый,
// зачёркнутый, спойлер, код и ссылки. Внутри кода Telegram другого
// оформления не принимает — у кода оно опускается.
function gw_spans_html(array $spans): string {
    $tags = ['b' => 'b', 'i' => 'i', 'u' => 'u', 'st' => 's', 'sp' => 'tg-spoiler'];
    $out = '';
    foreach ($spans as $sp) {
        $t = gw_html((string)$sp['s']);
        if ($t === '') { continue; }
        if (!empty($sp['c'])) {
            $t = '<code>' . $t . '</code>';
        } else {
            foreach ($tags as $flag => $tag) {
                if (!empty($sp[$flag])) { $t = '<' . $tag . '>' . $t . '</' . $tag . '>'; }
            }
        }
        if (isset($sp['href']) && nb_is_safe_href($sp['href'])) {
            $t = '<a href="' . gw_html($sp['href']) . '">' . $t . '</a>';
        }
        $out .= $t;
    }
    return $out;
}

// Блок описания на языке поста; пустой русский — английский.
function gw_block_html(array $b): string {
    $pick = function (array $ru, array $en): array { return tr_spans_empty($ru) ? $en : $ru; };
    if ($b['t'] === 'p') { return trim(gw_spans_html($pick($b['ru'], $b['en']))); }
    if ($b['t'] === 'quote') {
        $in = trim(gw_spans_html($pick($b['ru'], $b['en'])));
        if ($in === '') { return ''; }
        return '<blockquote' . (!empty($b['collapsible']) ? ' expandable' : '') . '>' . $in . '</blockquote>';
    }
    if ($b['t'] === 'list') {
        $lines = [];
        foreach ($b['items'] as $it) {
            $t = trim(gw_spans_html($pick($it['ru'], $it['en'])));
            if ($t === '') { continue; }
            $lines[] = (!empty($b['ordered']) ? (count($lines) + 1) . '. ' : '• ') . $t;
        }
        return implode("\n", $lines);
    }
    return '';
}

// Описание целыми блоками, пока влезает в GW_POST_BODY_MAX: обрезанный
// посередине блок оставил бы незакрытый тег.
function gw_body_html(array $blocks): string {
    $parts = [];
    $len = 0;
    foreach ($blocks as $b) {
        $html = gw_block_html($b);
        if ($html === '') { continue; }
        if ($len + mb_strlen($html) > GW_POST_BODY_MAX) {
            if ($parts) { $parts[] = '…'; }
            break;
        }
        $parts[] = $html;
        $len += mb_strlen($html) + 2;
    }
    return implode("\n\n", $parts);
}

// Условия: каналы — по ссылке этого розыгрыша (кто подпишется по ней,
// попадёт в отчёт рекламодателю), свои условия — со своей ссылкой, и то, что
// нужно всегда: вход через Roblox и бот.
function gw_post_conds(PDO $pdo, array $row, string $bot): string {
    $id = (int)$row['id'];
    $links = gw_links($pdo, [$id])[$id] ?? [];
    $chIds = array_map('intval', gw_decode_list($row['channels_json']));
    $channels = gw_channels($pdo, $chIds);
    $lines = [];
    foreach ($chIds as $cid) {
        $c = $channels[$cid] ?? null;
        if (!$c) { continue; }
        $url = $links[$cid] ?? gw_channel_url($c);
        $title = gw_html((string)$c['title'] !== '' ? (string)$c['title'] : 'канал');
        $lines[] = '• Подписаться на ' . ($url !== '' ? '<a href="' . gw_html($url) . '">' . $title . '</a>' : $title);
    }
    foreach (gw_conds($row['conds_json'] ?? null) as $c) {
        $btn = $c['btn_ru'] !== '' ? $c['btn_ru'] : 'Подписаться';
        $lines[] = '• ' . gw_html($c['ru']) . ' — <a href="' . gw_html($c['url']) . '">' . gw_html($btn) . '</a>';
    }
    $lines[] = '• Войти на maknemy.com через Roblox и подключить бота' . ($bot !== '' ? ' @' . gw_html($bot) : '');
    return implode("\n", $lines);
}

// Пост идущего розыгрыша.
function gw_post_text(PDO $pdo, array $row, string $bot): string {
    $prizes = [];
    foreach (gw_decode_list($row['prizes_json']) as $p) {
        $prizes[] = '<b>' . gw_html((string)$p['name']) . '</b>' . ((int)$p['count'] > 1 ? ' × ' . (int)$p['count'] : '');
    }
    $body = gw_body_html(gw_decode_blocks($row['body_json']));
    return (gw_is_test($row) ? "🧪 Тестовый розыгрыш\n" : '')
        . '🎁 <b>' . gw_html(gw_title($row, GW_POST_LANG)) . '</b>'
        . ($body !== '' ? "\n\n" . $body : '')
        . "\n\n🏆 <b>Призы</b>\n<blockquote>" . implode("\n", $prizes) . '</blockquote>'
        . "\n✅ <b>Условия</b>\n" . gw_post_conds($pdo, $row, $bot)
        . "\n\n⏳ <b>Итоги:</b> " . gw_html(gw_when($row, GW_POST_LANG));
}

// Победитель в посте: ник на сайте и логин Roblox, если они разные (не
// только регистром). Без @: Telegram сделал бы из логина ссылку на чужой
// аккаунт с тем же именем.
function gw_winner_label(array $w): string {
    $name = (string)$w['name'];
    $display = (string)$w['display'];
    if ($display === '') { return $name !== '' ? $name : 'id ' . $w['id']; }
    if ($name === '' || mb_strtolower($display) === mb_strtolower($name)) { return $display; }
    return $display . ' (' . $name . ')';
}

// Пост после итогов: победители по призам. Не влезли все — хвост «и ещё N».
function gw_post_results_text(PDO $pdo, array $row): string {
    $id = (int)$row['id'];
    $byPrize = [];
    foreach (gw_winners($pdo, [$id])[$id] ?? [] as $w) { $byPrize[$w['prize']][] = $w; }
    $count = gw_entry_counts($pdo, [$id])[$id] ?? 0;
    $head = (gw_is_test($row) ? "🧪 Тестовый розыгрыш\n" : '')
        . '🎉 <b>Итоги: ' . gw_html(gw_title($row, GW_POST_LANG)) . '</b>';
    $foot = "\n\n👥 Участников: " . $count;
    if (!$byPrize) { return $head . "\n\nПобедителей нет: никто не выполнил условия." . $foot; }
    $foot .= "\nПоздравляем! Бот уже написал победителям.";

    $budget = GW_POST_MAX - mb_strlen($head . $foot) - 200;
    $lines = [];
    $used = 0;
    $total = 0;
    $shown = 0;
    $full = false;
    foreach (gw_decode_list($row['prizes_json']) as $k => $p) {
        if (empty($byPrize[$k])) { continue; }
        $total += count($byPrize[$k]);
        if ($full) { continue; }
        $prize = '<b>' . gw_html((string)$p['name']) . '</b>';
        $group = [];
        foreach ($byPrize[$k] as $n => $w) {
            $line = ($n + 1) . '. ' . gw_html(gw_winner_label($w));
            $add = mb_strlen($line) + 1 + ($group ? 0 : mb_strlen($prize) + 1);
            if ($used + $add > $budget) { $full = true; break; }
            $group[] = $line;
            $used += $add;
        }
        if ($group) {
            $lines[] = $prize;
            $lines = array_merge($lines, $group);
            $shown += count($group);
        }
    }
    if ($total > $shown) { $lines[] = '…и ещё ' . ($total - $shown) . ' — все победители на сайте'; }
    return $head . "\n\n🏆 <b>Победители</b>\n<blockquote" . ($total > GW_POST_FOLD ? ' expandable' : '') . '>'
        . implode("\n", $lines) . '</blockquote>' . $foot;
}

// Поля sendMessage / editMessageText поста: текст, баннер над ним, кнопка.
function gw_post_params(array $row, string $text, bool $results): array {
    $id = (int)$row['id'];
    $btn = $results
        ? ['text' => '🏆 Итоги на сайте', 'url' => gw_link($id, false, gw_is_test($row))]
        : ['text' => '🎁 Участвовать', 'url' => gw_post_link($id, gw_is_test($row))];
    return ['text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => ['inline_keyboard' => [[$btn]]]] + gw_preview($row);
}

// Ответ Telegram на правку: ok — 'ok'; «не изменилось» — тоже ok; поста
// больше нет (удалили в канале) — 'gone'; молчит — 'down'; иначе 'fail'.
function gw_post_edit_state(?array $res): string {
    if ($res === null) { return 'down'; }
    if (!empty($res['ok'])) { return 'ok'; }
    $d = (string)($res['description'] ?? '');
    if (strpos($d, 'message is not modified') !== false) { return 'ok'; }
    if (strpos($d, 'message to edit not found') !== false || strpos($d, 'MESSAGE_ID_INVALID') !== false) { return 'gone'; }
    return 'fail';
}

/**
 * Привести посты идущего розыгрыша к его виду: где поста ещё нет — выпустить,
 * где есть — поправить. Черновик и закончившийся не трогаются (итоги —
 * gw_post_results()). ['posted' => n, 'edited' => n, 'failed' => [канал, …]].
 * $bot — имя бота для строки условий.
 */
function gw_post_sync(PDO $pdo, callable $send, array $row, string $bot, int $now): array {
    $out = ['posted' => 0, 'edited' => 0, 'failed' => []];
    if ($row['status'] !== 'live' || !gw_has_posts($pdo)) { return $out; }
    $id = (int)$row['id'];
    $posts = gw_posts($pdo, $id);
    if (!$posts) { return $out; }
    $channels = gw_channels($pdo, array_keys($posts));
    $text = gw_post_text($pdo, $row, $bot);
    foreach ($posts as $chat => $p) {
        if ((int)$p['results_at'] > 0) { continue; }
        $c = $channels[$chat] ?? null;
        $name = $c && (string)$c['title'] !== '' ? (string)$c['title'] : (string)$chat;
        $params = ['chat_id' => $chat] + gw_post_params($row, $text, false);
        $mid = (int)$p['message_id'];
        if ($mid > 0) {
            $state = gw_post_edit_state($send('editMessageText', $params + ['message_id' => $mid]));
            if ($state === 'ok') { $out['edited']++; continue; }
            if ($state === 'gone') {
                $pdo->prepare('DELETE FROM giveaway_posts WHERE giveaway_id = :g AND chat_id = :c')->execute([':g' => $id, ':c' => $chat]);
            }
            $out['failed'][] = $name;
            continue;
        }
        if (!$c || (int)$c['is_admin'] !== 1) { $out['failed'][] = $name; continue; }
        $res = $send('sendMessage', $params);
        $newId = is_array($res) && !empty($res['ok']) ? (int)($res['result']['message_id'] ?? 0) : 0;
        if ($newId <= 0) { $out['failed'][] = $name; continue; }
        $pdo->prepare('UPDATE giveaway_posts SET message_id = :m, posted_at = :t WHERE giveaway_id = :g AND chat_id = :c AND message_id = 0')
            ->execute([':m' => $newId, ':t' => $now, ':g' => $id, ':c' => $chat]);
        $out['posted']++;
    }
    return $out;
}

/**
 * Дописать итоги в вышедшие посты разыгранного розыгрыша. Возвращает, сколько
 * не вышло из-за молчания Telegram: их повторит следующий проход автоитогов.
 * Отказ Telegram (пост удалили) окончательный — пост больше не трогаем.
 */
function gw_post_results(PDO $pdo, callable $send, array $row, int $now): int {
    if ($row['status'] !== 'drawn' || !gw_has_posts($pdo)) { return 0; }
    $id = (int)$row['id'];
    $st = $pdo->prepare('SELECT chat_id, message_id FROM giveaway_posts WHERE giveaway_id = :g AND message_id > 0 AND results_at = 0');
    $st->execute([':g' => $id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { return 0; }
    $text = gw_post_results_text($pdo, $row);
    $mark = $pdo->prepare('UPDATE giveaway_posts SET results_at = :t WHERE giveaway_id = :g AND chat_id = :c');
    $failed = 0;
    foreach ($rows as $r) {
        $params = ['chat_id' => (int)$r['chat_id'], 'message_id' => (int)$r['message_id']] + gw_post_params($row, $text, true);
        if (gw_post_edit_state($send('editMessageText', $params)) === 'down') { $failed++; continue; }
        $mark->execute([':t' => $now, ':g' => $id, ':c' => (int)$r['chat_id']]);
    }
    return $failed;
}

// Разыгранные розыгрыши, в чьи посты итоги ещё не дописаны.
function gw_post_due_ids(PDO $pdo): array {
    if (!gw_has_posts($pdo)) { return []; }
    return array_map('intval', $pdo->query(
        "SELECT DISTINCT p.giveaway_id FROM giveaway_posts p JOIN giveaways g ON g.id = p.giveaway_id
          WHERE g.status = 'drawn' AND p.message_id > 0 AND p.results_at = 0 ORDER BY p.giveaway_id"
    )->fetchAll(PDO::FETCH_COLUMN));
}

function gw_has_post_due(PDO $pdo): bool {
    try {
        return (bool)gw_post_due_ids($pdo);
    } catch (PDOException $e) {
        return false;
    }
}

// --------------------------------------------------------------------------
//  Автоитоги
// --------------------------------------------------------------------------

// Не удалось подвести итоги (Telegram не ответил, бот потерял права в
// канале) — следующая попытка не раньше чем через столько секунд, иначе
// каждый запрос к /api/giveaways.php снова ходил бы в Telegram.
const GW_AUTO_RETRY = 60;

// Розыгрыши, которые закончились, а итогов ещё нет.
function gw_due_ids(PDO $pdo, int $now): array {
    $st = $pdo->prepare("SELECT id FROM giveaways WHERE status = 'live' AND ends_at <= :now ORDER BY ends_at, id");
    $st->execute([':now' => $now]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// Есть ли что разыграть. Нет таблиц — нечего.
function gw_has_due(PDO $pdo, int $now): bool {
    try {
        return (bool)gw_due_ids($pdo, $now);
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Подвести итоги всех закончившихся розыгрышей и поздравить победителей.
 * ['drawn' => сколько разыграно, 'failed' => сколько не вышло]. Не вышло —
 * розыгрыш остаётся ждать итогов, и следующий проход попробует снова.
 */
function gw_draw_due(PDO $pdo, array $cfg, callable $send, int $now, ?callable $sleep = null, ?callable $rand = null): array {
    $out = ['drawn' => 0, 'failed' => 0];
    $tried = [];
    foreach (gw_due_ids($pdo, $now) as $id) {
        [$s, , $winners, $gone] = gw_draw($pdo, $cfg, $send, $id, $now, $rand);
        if ($s !== 200) {
            // drawn — его только что разыграл кто-то другой (кнопка в админке).
            if ($s !== 409) { $out['failed']++; }
            continue;
        }
        $out['drawn']++;
        $row = gw_load($pdo, $id);
        // Пост в канале — раньше поздравлений: тех может быть полсотни с паузами.
        $out['failed'] += gw_post_results($pdo, $send, $row, $now);
        $tried[$id] = true;
        if ($winners) { gw_notify_winners($pdo, $send, $row, $winners, $sleep); }
        if ($gone) { gw_left_send($pdo, $send, $gone, $now, $sleep); }
    }
    // Посты, в которые итоги не дописались прошлый раз (Telegram молчал).
    foreach (gw_post_due_ids($pdo) as $id) {
        if (!isset($tried[$id])) { $out['failed'] += gw_post_results($pdo, $send, gw_load($pdo, $id), $now); }
    }
    return $out;
}

/**
 * Автоитоги после ответа посетителю. Проход идёт один за раз — замок-файл,
 * второй запрос в это время просто выходит. В том же файле — время
 * последней неудачи: после неё следующий проход ждёт GW_AUTO_RETRY секунд.
 * $send и $lockPath подставляют тесты.
 */
function gw_after_due(PDO $pdo, array $cfg, int $now, ?callable $send = null, ?string $lockPath = null, ?callable $sleep = null): array {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return ['skipped' => 'off']; }
    tg_finish_response();
    tg_long_run();
    $lock = @fopen($lockPath ?? sys_get_temp_dir() . '/maknemy-gw-draw.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { return ['skipped' => 'busy']; }
    $res = ['skipped' => 'retry'];
    try {
        $failedAt = (int)stream_get_contents($lock);
        if ($failedAt <= 0 || $now - $failedAt >= GW_AUTO_RETRY) {
            $res = gw_draw_due($pdo, $cfg, $send ?? tg_http($tg['token']), $now, $sleep);
            ftruncate($lock, 0);
            rewind($lock);
            if ($res['failed']) { fwrite($lock, (string)$now); }
        }
    } catch (Throwable $e) {
        error_log('giveaway auto draw: ' . $e->getMessage());
        $res = ['skipped' => 'error'];
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $res;
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
//  Напоминания подписчикам
// --------------------------------------------------------------------------
//
// В «Настройках уведомлений» на /giveaways человек сам выбирает, за сколько
// до итогов напомнить и за сколько мест до лимита (lib/gw_prefs.php). Каждое
// напоминание — одно на человека и розыгрыш: строка в gw_reminders ставится
// до отправки, и второй проход её уже не пошлёт.
//
// «По времени» проверяет тот же запрос к /api/giveaways.php, что подводит
// итоги, — после ответа (gw_after_remind()). Cron нет, поэтому напоминание
// может опоздать, пока на сайт никто не заходит; текст считает оставшееся
// время в момент отправки, так что опоздавшее не врёт. Напоминания нет, если
// его момент наступил раньше публикации розыгрыша или раньше, чем человек
// сохранил настройку: иначе «за 3 дня» у розыгрыша на 2 дня пришло бы сразу
// вслед за рассылкой о старте.
//
// «По местам» — сразу после вступления очередного участника
// (gw_after_places()), только у розыгрыша с лимитом и только если порог
// меньше лимита. Только что вступивший своё напоминание не получает: ему
// бот уже написал «вы участвуете».
//
// Участвующему бот пишет «вы участвуете», остальным — «успейте». Тестовый
// розыгрыш напоминает только своим участникам — это сами тестирующие.

// Правило «тестовый — только участникам». До миграции
// 2026-10-15-giveaway-test.sql колонки test нет, и тестовых не бывает.
function gw_remind_test_sql(PDO $pdo): string {
    return gw_has_test($pdo) ? ' AND (g.test = 0 OR e.user_id IS NOT NULL)' : '';
}

/**
 * Кому пора напомнить о времени: строки giveaway_id, user_id, chat_id, lang,
 * joined. Нет таблиц — никому.
 */
function gw_remind_time_due(PDO $pdo, int $now, int $limit = 0): array {
    try {
        $st = $pdo->prepare(
            "SELECT g.id AS giveaway_id, p.user_id, l.chat_id, l.lang,
                    CASE WHEN e.user_id IS NULL THEN 0 ELSE 1 END AS joined
               FROM giveaways g
               JOIN gw_prefs p ON p.subscribed = 1 AND p.time_left > 0
               JOIN tg_links l ON l.user_id = p.user_id
               LEFT JOIN giveaway_entries e ON e.giveaway_id = g.id AND e.user_id = p.user_id" . gw_active_sql($pdo) . "
               LEFT JOIN gw_reminders r ON r.giveaway_id = g.id AND r.user_id = p.user_id AND r.kind = 'time'
              WHERE g.status = 'live' AND g.ends_at > :now AND g.ends_at < :nodl
                AND g.ends_at - p.time_left <= :now2
                AND g.ends_at - p.time_left >= g.published_at
                AND g.ends_at - p.time_left >= p.updated_at
                AND r.giveaway_id IS NULL" . gw_remind_test_sql($pdo) . "
              ORDER BY g.ends_at, g.id, p.user_id" . ($limit > 0 ? ' LIMIT ' . $limit : '')
        );
        // Целыми, а не строками: в SQLite разность колонок не имеет типа, и
        // строковый параметр рядом с ней сравнивается как текст.
        $st->bindValue(':now', $now, PDO::PARAM_INT);
        $st->bindValue(':nodl', GW_NO_DEADLINE, PDO::PARAM_INT);
        $st->bindValue(':now2', $now, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Есть ли кому напомнить о времени. Один короткий запрос на каждый
// некешированный ответ /api/giveaways.php.
function gw_has_remind(PDO $pdo, int $now): bool {
    return (bool)gw_remind_time_due($pdo, $now, 1);
}

/**
 * Кому напомнить о местах после очередного участника: те же поля плюс left —
 * сколько мест осталось. Приём закрыт или лимита нет — никому.
 */
function gw_remind_places_due(PDO $pdo, array $row, int $now): array {
    $max = gw_max($row);
    if ($max <= 0 || $row['status'] !== 'live' || (int)$row['ends_at'] <= $now) { return []; }
    $id = (int)$row['id'];
    $left = $max - (gw_entry_counts($pdo, [$id])[$id] ?? 0);
    if ($left <= 0) { return []; }
    try {
        $st = $pdo->prepare(
            "SELECT g.id AS giveaway_id, p.user_id, l.chat_id, l.lang,
                    CASE WHEN e.user_id IS NULL THEN 0 ELSE 1 END AS joined
               FROM giveaways g
               JOIN gw_prefs p ON p.subscribed = 1 AND p.places_left >= :left AND p.places_left < :max
               JOIN tg_links l ON l.user_id = p.user_id
               LEFT JOIN giveaway_entries e ON e.giveaway_id = g.id AND e.user_id = p.user_id" . gw_active_sql($pdo) . "
               LEFT JOIN gw_reminders r ON r.giveaway_id = g.id AND r.user_id = p.user_id AND r.kind = 'places'
              WHERE g.id = :g AND r.giveaway_id IS NULL" . gw_remind_test_sql($pdo) . "
              ORDER BY p.user_id"
        );
        $st->execute([':left' => $left, ':max' => $max, ':g' => $id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
    foreach ($rows as $i => $r) { $rows[$i]['left'] = $left; }
    return $rows;
}

// Застолбить напоминание. true — его ещё не было, слать можно.
function gw_remind_claim(PDO $pdo, int $id, string $user, string $kind, int $now): bool {
    try {
        $pdo->prepare('INSERT INTO gw_reminders (giveaway_id, user_id, kind, sent_at) VALUES (:g, :u, :k, :at)')
            ->execute([':g' => $id, ':u' => $user, ':k' => $kind, ':at' => $now]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function gw_plural_ru(int $n, string $one, string $few, string $many): string {
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) { return $one; }
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) { return $few; }
    return $many;
}

// Сколько осталось, с точностью до минуты: «1 день 4 часа», «3 часа 20 минут»,
// «45 минут». Две старшие единицы, нулевые не пишутся.
function gw_span(int $sec, string $lang): string {
    $min = max(1, (int)round($sec / 60));
    $d = intdiv($min, 1440);
    $h = intdiv($min % 1440, 60);
    $m = $min % 60;
    $parts = $d > 0 ? [[$d, 'd'], [$h, 'h']] : ($h > 0 ? [[$h, 'h'], [$m, 'm']] : [[$m, 'm']]);
    $ru = ['d' => ['день', 'дня', 'дней'], 'h' => ['час', 'часа', 'часов'], 'm' => ['минута', 'минуты', 'минут']];
    $en = ['d' => 'day', 'h' => 'hour', 'm' => 'minute'];
    $out = [];
    foreach ($parts as [$n, $u]) {
        if ($n <= 0) { continue; }
        $out[] = $n . ' ' . ($lang === 'en' ? $en[$u] . ($n === 1 ? '' : 's') : gw_plural_ru($n, $ru[$u][0], $ru[$u][1], $ru[$u][2]));
    }
    return implode(' ', $out);
}

function gw_remind_time_text(array $row, int $left, bool $joined, string $lang): string {
    $title = gw_title($row, $lang);
    $span  = gw_span($left, $lang);
    if ($lang === 'en') {
        return "⏰ The giveaway «" . $title . "» on maknemy.com ends in " . $span . ".\n\n"
            . ($joined ? "You're in ✅ If you win, I'll message you here." : "You haven't entered yet — there's still time.");
    }
    return "⏰ До итогов розыгрыша «" . $title . "» на maknemy.com — " . $span . ".\n\n"
        . ($joined ? 'Вы участвуете ✅ О победе я напишу сюда.' : 'Вы ещё не участвуете — успейте, пока приём открыт.');
}

function gw_remind_places_text(array $row, int $left, bool $joined, string $lang): string {
    $title = gw_title($row, $lang);
    $max   = gw_max($row);
    if ($lang === 'en') {
        return "🔥 Only " . $left . ' spot' . ($left === 1 ? '' : 's') . ' of ' . $max . " left in the giveaway «" . $title . "» on maknemy.com.\n\n"
            . ($joined ? "You're in ✅" : 'Grab yours while they last.');
    }
    return "🔥 В розыгрыше «" . $title . "» на maknemy.com осталось " . $left . ' ' . gw_plural_ru($left, 'место', 'места', 'мест')
        . ' из ' . $max . ".\n\n"
        . ($joined ? 'Вы участвуете ✅' : 'Успейте занять своё, пока места есть.');
}

/**
 * Разослать напоминания вида $kind по строкам из gw_remind_*_due(). Сколько
 * ушло. $skipUser — только что вступивший: напоминание за ним столбится, но
 * не шлётся. Telegram-чат, привязанный к двум аккаунтам, получает одно.
 */
function gw_remind_send(PDO $pdo, callable $send, string $kind, array $rows, int $now, string $skipUser = '', ?callable $sleep = null): int {
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $loaded = [];
    $seen = [];
    $tried = 0;
    $sent = 0;
    foreach ($rows as $r) {
        $id = (int)$r['giveaway_id'];
        $user = (string)$r['user_id'];
        if (!gw_remind_claim($pdo, $id, $user, $kind, $now) || $user === $skipUser) { continue; }
        $chat = (int)$r['chat_id'];
        if (isset($seen[$id . ':' . $chat])) { continue; }
        $seen[$id . ':' . $chat] = true;
        if (!array_key_exists($id, $loaded)) { $loaded[$id] = gw_load($pdo, $id); }
        $row = $loaded[$id];
        if (!$row) { continue; }

        $lang = tg_lang($r['lang']);
        $joined = (int)$r['joined'] === 1;
        $text = $kind === 'time'
            ? gw_remind_time_text($row, (int)$row['ends_at'] - $now, $joined, $lang)
            : gw_remind_places_text($row, (int)$r['left'], $joined, $lang);
        $btn = $joined ? ($lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш') : ($lang === 'en' ? 'Join' : 'Участвовать');
        $msg = tg_message_buttons($chat, $text, [[$btn, gw_link($id, !$joined, gw_is_test($row))]]);
        $msg['link_preview_options'] = ['is_disabled' => true];

        if ($tried++ > 0) { $sleep(TG_BROADCAST_GAP); }
        $res = $send('sendMessage', $msg);
        if (is_array($res) && (int)($res['error_code'] ?? 0) === 429) {
            $wait = (int)($res['parameters']['retry_after'] ?? 1);
            $sleep(max(1, min(30, $wait)) * 1000000);
            $res = $send('sendMessage', $msg);
        }
        if (tg_after_send($pdo, $chat, $res)) { $sent++; }
    }
    return $sent;
}

/**
 * Напоминания о времени после ответа /api/giveaways.php. Проход один за раз —
 * замок-файл, второй запрос в это время просто выходит. $send и $lockPath
 * подставляют тесты.
 */
function gw_after_remind(PDO $pdo, array $cfg, int $now, ?callable $send = null, ?string $lockPath = null, ?callable $sleep = null): array {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return ['skipped' => 'off']; }
    tg_finish_response();
    tg_long_run();
    $lock = @fopen($lockPath ?? sys_get_temp_dir() . '/maknemy-gw-remind.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { return ['skipped' => 'busy']; }
    try {
        $res = ['sent' => gw_remind_send($pdo, $send ?? tg_http($tg['token']), 'time', gw_remind_time_due($pdo, $now), $now, '', $sleep)];
    } catch (Throwable $e) {
        error_log('giveaway remind: ' . $e->getMessage());
        $res = ['skipped' => 'error'];
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $res;
}

// Напоминания о местах после вступления. $note — третий элемент ответа
// gw_enter(). Ответ сайту уже ушёл.
function gw_after_places(PDO $pdo, array $cfg, array $note, int $now, ?callable $send = null, ?callable $sleep = null): int {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return 0; }
    tg_finish_response();
    tg_long_run();
    try {
        $rows = gw_remind_places_due($pdo, $note['row'], $now);
        if (!$rows) { return 0; }
        return gw_remind_send($pdo, $send ?? tg_http($tg['token']), 'places', $rows, $now, (string)($note['user'] ?? ''), $sleep);
    } catch (Throwable $e) {
        error_log('giveaway places: ' . $e->getMessage());
        return 0;
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

// Карточки розыгрыша на страницах сайта (js/gw-card.js). Перешёл с
// карточки и вступил на /giveaways — у участника src «c-» + место, так в
// отчёте видно, какое место приводит участников. Показы и переходы каждого
// места копятся в gw_card_stats (api/gw_stat.php).
const GW_CARD_PLACES = ['tier', 'trade', 'calc', 'stock', 'news'];

// ch — по кнопке поста розыгрыша в Telegram-канале (раздел «Пост в канале»).
const GW_SOURCES = ['tg', 'ch', 'pop', 'page', 'c-tier', 'c-trade', 'c-calc', 'c-stock', 'c-news'];

function gw_src($v): string {
    return is_string($v) && in_array($v, GW_SOURCES, true) ? $v : '';
}

// Сколько показов и переходов прибавить месту. Строки нет — вставка; два
// одновременных первых показа упрутся в ключ, и второй тогда прибавляется
// повторным UPDATE. Без таблицы (миграция не выполнена) — false.
function gw_card_add(PDO $pdo, int $id, string $place, int $views, int $clicks): bool {
    if ($id <= 0 || !in_array($place, GW_CARD_PLACES, true) || ($views <= 0 && $clicks <= 0)) { return false; }
    $up = function () use ($pdo, $id, $place, $views, $clicks): int {
        $st = $pdo->prepare('UPDATE gw_card_stats SET views = views + :v, clicks = clicks + :c WHERE giveaway_id = :g AND place = :p');
        $st->bindValue(':v', $views, PDO::PARAM_INT);
        $st->bindValue(':c', $clicks, PDO::PARAM_INT);
        $st->bindValue(':g', $id, PDO::PARAM_INT);
        $st->bindValue(':p', $place);
        $st->execute();
        return $st->rowCount();
    };
    try {
        if ($up() > 0) { return true; }
        try {
            $st = $pdo->prepare('INSERT INTO gw_card_stats (giveaway_id, place, views, clicks) VALUES (:g, :p, :v, :c)');
            $st->bindValue(':g', $id, PDO::PARAM_INT);
            $st->bindValue(':p', $place);
            $st->bindValue(':v', $views, PDO::PARAM_INT);
            $st->bindValue(':c', $clicks, PDO::PARAM_INT);
            $st->execute();
        } catch (PDOException $e) {
            $up();
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Записать события карточек из js/gw-card.js: [{id, p: место, k: "v"|"c"}].
 * Считаются только розыгрыши, которые видны на сайте (идут или уже
 * закончились, не черновики): иначе таблицу забили бы чужими id. Повторы
 * одного места в пачке складываются. Вернёт, сколько событий принято.
 */
function gw_card_track(PDO $pdo, array $events, int $max = 12): int {
    $sum = [];
    foreach (array_slice($events, 0, $max) as $e) {
        if (!is_array($e)) { continue; }
        $id = $e['id'] ?? null;
        $id = is_int($id) ? $id : (is_string($id) && ctype_digit($id) ? (int)$id : 0);
        $place = $e['p'] ?? '';
        $kind = $e['k'] ?? '';
        if ($id <= 0 || !is_string($place) || !in_array($place, GW_CARD_PLACES, true) || ($kind !== 'v' && $kind !== 'c')) { continue; }
        $key = $id . ':' . $place;
        if (!isset($sum[$key])) { $sum[$key] = ['id' => $id, 'place' => $place, 'v' => 0, 'c' => 0]; }
        $sum[$key][$kind]++;
    }
    if (!$sum) { return 0; }
    $ids = array_values(array_unique(array_column($sum, 'id')));
    $ok = [];
    try {
        $st = $pdo->query("SELECT id FROM giveaways WHERE status IN ('live', 'drawn') AND id IN (" . implode(',', array_map('intval', $ids)) . ')');
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gid) { $ok[(int)$gid] = true; }
    } catch (PDOException $e) {
        return 0;
    }
    $n = 0;
    foreach ($sum as $row) {
        if (!isset($ok[$row['id']])) { continue; }
        if (gw_card_add($pdo, $row['id'], $row['place'], $row['v'], $row['c'])) { $n += $row['v'] + $row['c']; }
    }
    return $n;
}

// Места карточек одного розыгрыша для отчёта: показы, переходы и участия,
// в порядке GW_CARD_PLACES. Без таблицы показы и переходы нулевые.
function gw_card_report(PDO $pdo, int $id, array $sources): array {
    $stats = [];
    try {
        $st = $pdo->prepare('SELECT place, views, clicks FROM gw_card_stats WHERE giveaway_id = :g');
        $st->execute([':g' => $id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $stats[(string)$r['place']] = $r; }
    } catch (PDOException $e) {
        $stats = [];
    }
    $out = [];
    foreach (GW_CARD_PLACES as $place) {
        $out[] = [
            'place'   => $place,
            'views'   => (int)($stats[$place]['views'] ?? 0),
            'clicks'  => (int)($stats[$place]['clicks'] ?? 0),
            'entries' => (int)($sources['c-' . $place] ?? 0),
        ];
    }
    return $out;
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
              WHERE e.chat_id = :u AND g.status = 'live'" . gw_active_sql($pdo)
        );
        $st->execute([':u' => $uid]);
        $notes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!in_array($channel, gw_checked_channels($row), true)) { continue; }
            if (!gw_mark_left($pdo, (int)$row['id'], $uid, $now)) { continue; }
            $notes[] = gw_left_note($pdo, $row, $uid, $channel);
        }
        return $notes;
    } catch (PDOException $e) {
        error_log('giveaway member update: ' . $e->getMessage());
        return [];
    }
}

/**
 * Снять участие отписавшегося: left_at у его строки (до миграции — строка
 * удаляется). true — сняли сейчас, и бот должен написать; false — уже было
 * снято раньше.
 */
function gw_mark_left(PDO $pdo, int $gid, int $chat, int $now): bool {
    if (gw_has_left($pdo)) {
        $st = $pdo->prepare('UPDATE giveaway_entries SET left_at = :at WHERE giveaway_id = :g AND chat_id = :c AND left_at = 0');
        $st->execute([':at' => $now, ':g' => $gid, ':c' => $chat]);
    } else {
        $st = $pdo->prepare('DELETE FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
        $st->execute([':g' => $gid, ':c' => $chat]);
    }
    return $st->rowCount() > 0;
}

// Кому и что написать о снятом участии: chat_id, язык, розыгрыш, название канала.
function gw_left_note(PDO $pdo, array $row, int $chat, int $channel): array {
    $title = gw_channels($pdo, [$channel])[$channel]['title'] ?? '';
    $st = $pdo->prepare('SELECT lang FROM tg_links WHERE chat_id = :u LIMIT 1');
    $st->execute([':u' => $chat]);
    return ['chat_id' => $chat, 'lang' => tg_lang($st->fetchColumn()), 'row' => $row, 'channel' => (string)$title];
}

// --------------------------------------------------------------------------
//  Перепроверка подписки в фоне
// --------------------------------------------------------------------------
//
// На бою вебхук не узнавал о выходах из каналов: отписавшиеся доживали до
// итогов, а бот им ничего не писал. Поэтому участников идущих розыгрышей бот
// перепроверяет сам — getChatMember, не больше GW_SWEEP_CALLS запросов за
// проход, проход не чаще раза в GW_SWEEP_EVERY секунд, после ответа
// /api/giveaways.php (как автоитоги и напоминания). Первыми идут те, кого
// дольше всех не проверяли (checked_at), одного человека — не чаще раза в
// GW_SWEEP_RECHECK секунд. Отписался — gw_mark_left() и то же сообщение, что
// из вебхука. Бот не может проверить канал (Telegram молчит, бота
// разжаловали) — человек не трогается и уходит в конец очереди; три таких
// ответа подряд заканчивают проход.

const GW_SWEEP_EVERY   = 60;
const GW_SWEEP_CALLS   = 40;
const GW_SWEEP_RECHECK = 600;

/**
 * Один проход перепроверки. ['checked' => сколько людей проверено,
 * 'left' => записи для сообщения «вы отписались»]. До миграции — ничего.
 */
function gw_sweep(PDO $pdo, callable $send, int $now, int $calls = GW_SWEEP_CALLS): array {
    $out = ['checked' => 0, 'left' => []];
    if ($calls <= 0 || !gw_has_left($pdo)) { return $out; }
    $st = $pdo->prepare(
        "SELECT e.giveaway_id, e.chat_id FROM giveaway_entries e JOIN giveaways g ON g.id = e.giveaway_id
          WHERE g.status = 'live' AND g.ends_at > :now AND e.left_at = 0 AND e.checked_at <= :old
          ORDER BY e.checked_at, e.joined_at LIMIT " . (int)$calls
    );
    $st->bindValue(':now', $now, PDO::PARAM_INT);
    $st->bindValue(':old', $now - GW_SWEEP_RECHECK, PDO::PARAM_INT);
    $st->execute();
    $touch = $pdo->prepare('UPDATE giveaway_entries SET checked_at = :at WHERE giveaway_id = :g AND chat_id = :c');
    $rows = [];
    $errors = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $gid = (int)$e['giveaway_id'];
        $chat = (int)$e['chat_id'];
        if (!array_key_exists($gid, $rows)) { $rows[$gid] = gw_load($pdo, $gid); }
        $row = $rows[$gid];
        if (!$row) { continue; }
        $chs = gw_checked_channels($row);
        if (count($chs) > $calls) { break; }
        $missing = null;
        $error = false;
        foreach ($chs as $c) {
            $calls--;
            $s = gw_member_state($send, $c, $chat);
            if ($s === 'error') { $error = true; break; }
            if ($s === 'no') { $missing = $c; break; }
        }
        if ($missing !== null) {
            if (gw_mark_left($pdo, $gid, $chat, $now)) { $out['left'][] = gw_left_note($pdo, $row, $chat, $missing); }
        } else {
            $touch->execute([':at' => $now, ':g' => $gid, ':c' => $chat]);
        }
        if ($error) {
            if (++$errors >= 3) { break; }
            continue;
        }
        $errors = 0;
        $out['checked']++;
    }
    return $out;
}

/**
 * Перепроверка после ответа /api/giveaways.php. Проход один за раз —
 * замок-файл, в нём время начала последнего прохода: следующий — не раньше
 * чем через GW_SWEEP_EVERY секунд. $send и $lockPath подставляют тесты.
 */
function gw_after_sweep(PDO $pdo, array $cfg, int $now, ?callable $send = null, ?string $lockPath = null): array {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return ['skipped' => 'off']; }
    $lock = @fopen($lockPath ?? sys_get_temp_dir() . '/maknemy-gw-sweep.lock', 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { return ['skipped' => 'busy']; }
    $res = ['skipped' => 'early'];
    try {
        $last = (int)stream_get_contents($lock);
        if ($last <= 0 || $now - $last >= GW_SWEEP_EVERY) {
            ftruncate($lock, 0);
            rewind($lock);
            fwrite($lock, (string)$now);
            fflush($lock);
            tg_finish_response();
            tg_long_run();
            $send = $send ?? tg_http($tg['token']);
            $done = gw_sweep($pdo, $send, $now);
            gw_left_send($pdo, $send, $done['left'], $now);
            $res = ['checked' => $done['checked'], 'left' => count($done['left'])];
        }
    } catch (Throwable $e) {
        error_log('giveaway sweep: ' . $e->getMessage());
        $res = ['skipped' => 'error'];
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return $res;
}

/**
 * Отчёт для рекламодателя: откуда пришли участники и кто подписался на
 * каждый канал по ссылке розыгрыша — заведённой ботом или ссылке своего
 * условия. counts = false: у условия публичный адрес канала, а вступление
 * по нему Telegram ни с какой ссылкой не связывает — считать нечего.
 */
function gw_report(PDO $pdo, array $row): array {
    $id = (int)$row['id'];
    $sources = array_fill_keys(GW_SOURCES, 0) + ['' => 0];
    $st = $pdo->prepare('SELECT e.src, COUNT(*) AS n FROM giveaway_entries e WHERE e.giveaway_id = :g' . gw_active_sql($pdo) . ' GROUP BY e.src');
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
               LEFT JOIN giveaway_entries e ON e.giveaway_id = s.giveaway_id AND e.chat_id = s.tg_user_id' . gw_active_sql($pdo) . '
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
    return ['sources' => $sources, 'channels' => $out, 'cards' => gw_card_report($pdo, $id, $sources)];
}

// $last — человек занял последнее место, и приём уже закрыт.
function gw_joined_text(array $row, string $lang, bool $last = false): string {
    $title = gw_title($row, $lang);
    $date  = gw_when($row, $lang);
    $cond  = gw_checked_channels($row) ? true : false;
    $drop  = gw_drops_unsub($row);
    if ($lang === 'en') {
        return "✅ You're in the giveaway «" . $title . "» on maknemy.com.\n\n"
            . ($last ? "You took the last spot: " . gw_max($row) . " entries, so entries are closed. Results soon." : 'Results: ' . $date . '.')
            . ($cond ? "\n\nDon't unsubscribe from the channels in the conditions: if you do, "
                . ($drop ? 'your entry is removed automatically.' : "you won't be able to win.") : '')
            . "\n\nIf you win, I'll message you here.";
    }
    return "✅ Вы участвуете в розыгрыше «" . $title . "» на maknemy.com.\n\n"
        . ($last ? 'Вы заняли последнее место: участников ' . gw_max($row) . ', приём закрыт. Итоги — скоро.' : 'Итоги — ' . $date . '.')
        . ($cond ? "\n\nНе отписывайтесь от каналов из условий: если отпишетесь, "
            . ($drop ? 'участие снимется автоматически.' : 'выиграть уже не получится.') : '')
        . "\n\nО победе я напишу сюда.";
}

// С гарантией участие снято и место освободилось; без неё место за человеком
// остаётся, но выиграть он не может. Вернуться — подписаться и нажать
// «Участвовать», пока приём открыт.
function gw_left_text(array $row, string $channel, string $lang, int $now): string {
    $title = gw_title($row, $lang);
    $drop  = gw_drops_unsub($row);
    $open  = gw_phase($row, $now) === 'live';
    $byMax = gw_no_deadline($row);
    $date  = $byMax ? '' : gw_date((int)$row['ends_at'], $lang);
    if ($lang === 'en') {
        return "❌ You unsubscribed from «" . $channel . "», so "
            . ($drop ? 'your entry in the giveaway «' . $title . '» was removed.' : "you can't win the giveaway «" . $title . '» anymore.')
            . ($open ? "\n\n" . ($drop ? 'To get back in' : 'To be in the running again') . ', subscribe again and tap «Enter» on the site '
                . ($byMax ? ($drop ? 'while spots remain.' : 'while entries are open.') : 'before ' . $date . '.') : '');
    }
    return "❌ Вы отписались от канала «" . $channel . "», поэтому "
        . ($drop ? 'участие в розыгрыше «' . $title . '» снято.' : 'выиграть в розыгрыше «' . $title . '» вы уже не сможете.')
        . ($open ? "\n\n" . ($drop ? 'Чтобы вернуться' : 'Чтобы снова побороться за приз') . ', подпишитесь снова и нажмите «Участвовать» на сайте '
            . ($byMax ? ($drop ? 'пока есть места.' : 'пока идёт приём.') : 'до ' . $date . '.') : '');
}

// Только что вступил — бот подтверждает. Ответ сайту уже ушёл.
function gw_after_join(PDO $pdo, array $cfg, array $note, ?callable $send = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    try {
        $lang = tg_lang($note['lang']);
        $msg = tg_message_buttons((int)$note['chat_id'], gw_joined_text($note['row'], $lang, !empty($note['last'])),
            [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$note['row']['id'], false, gw_is_test($note['row']))]]);
        $chat = (int)$note['chat_id'];
        tg_after_send($pdo, $chat, ($send ?? tg_http($tg['token']))('sendMessage', $msg));
    } catch (Throwable $e) {
        error_log('giveaway joined: ' . $e->getMessage());
    }
}

// Отписавшимся — что участие снято. Записи — из gw_member_update(),
// gw_sweep() или gw_draw(). Сколько ушло.
function gw_left_send(PDO $pdo, callable $send, array $notes, int $now, ?callable $sleep = null): int {
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $sent = 0;
    foreach ($notes as $i => $n) {
        try {
            if ($i > 0) { $sleep(TG_BROADCAST_GAP); }
            $lang = tg_lang($n['lang']);
            $msg = tg_message_buttons((int)$n['chat_id'], gw_left_text($n['row'], $n['channel'], $lang, $now),
                [[$lang === 'en' ? 'Open the giveaway' : 'Открыть розыгрыш', gw_link((int)$n['row']['id'], false, gw_is_test($n['row']))]]);
            $res = $send('sendMessage', $msg);
            if (tg_after_send($pdo, (int)$n['chat_id'], $res)) {
                $sent++;
            } else {
                error_log('giveaway left: ' . (is_array($res) ? (string)($res['description'] ?? 'no description') : 'no answer'));
            }
        } catch (Throwable $e) {
            error_log('giveaway left: ' . $e->getMessage());
        }
    }
    return $sent;
}

// После вебхука. Ответ Telegram на вебхук уже ушёл.
function gw_after_member(PDO $pdo, array $cfg, array $notes, int $now, ?callable $send = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg) || !$notes) { return; }
    tg_finish_response();
    gw_left_send($pdo, $send ?? tg_http($tg['token']), $notes, $now);
}
