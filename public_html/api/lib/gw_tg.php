<?php
// Участие в розыгрыше прямо в Telegram — без входа на сайт.
//
// Под постом розыгрыша в канале (раздел «Пост в канале» в giveaway.php)
// кнопка «🎁 Участвовать». Человек жмёт её и не уходит из канала. Кнопка под
// постом одна: только такую Telegram показывает и в полосе закреплённого
// сообщения. Ссылка «Участвовать на сайте» — строкой в конце поста
// (gw_post_text()).
//
// Кнопка бывает двух видов.
// - Мини-приложение (/tg-app, api/gw_tg_app.php) — если у бота в @BotFather
//   включено Main Mini App (getMe отвечает has_main_web_app). Кнопка — ссылка
//   https://t.me/<бот>?startapp=gw<id>: Telegram открывает страницу поверх
//   чата, она присылает подписанные Telegram данные (initData), сервер
//   проверяет подпись токеном бота и записывает участие. Ссылка переживает
//   пересылку поста: в пересланной копии Telegram оставляет кнопки, только
//   если все они — ссылки (Telegram Desktop, CopyMarkupToForward()).
// - Кнопка бота (callback gw:<id>) — пока мини-приложения нет. Telegram
//   присылает вебхуку callback_query, бот отвечает всплывающим окном. При
//   пересылке поста такая кнопка пропадает.
// Оба пути — gw_tg_join(): проверка подписки на каналы из условий
// (getChatMember) и запись.
//
// Кто участвует. Telegram подключён к аккаунту сайта (tg_links) — участвует
// этот аккаунт, ровно как с кнопки на сайте (gw_enter()). Не подключён —
// участвует сам Telegram, без аккаунта Roblox: строка giveaway_entries с
// user_id = GW_TG_UID_BASE + id в Telegram. У колонки нет места под «пусто»,
// а ключ (розыгрыш, user_id) обязан быть уникальным; аккаунты Roblox до
// такого числа не дорастут. Имя, @ник и язык — в tg_people. Такой участник
// считается везде, как обычный: число участников, лимит, отчёт рекламодателю
// (src cb), отписка, итоги. Бан сайта на него не действует: банят аккаунты
// Roblox.
//
// Приз. Бот не может первым написать тому, кто его не запускал. Поэтому под
// постом с итогами, если среди победителей есть участники из Telegram, —
// кнопка «Забрать приз»: она открывает бота (/start gwp<id>), бот проверяет,
// что этот Telegram выиграл, поздравляет и просит ник в Roblox; ответ
// ложится в tg_people.roblox_nick и виден в админке. Кто запускал бота
// раньше, тому бот пишет сам сразу после итогов и так же просит ник.
//
// Нужно: таблица tg_people (миграция 2026-10-28-giveaway-tg-entry.sql) и
// callback_query среди обновлений вебхука (после мержа — «Подключить вебхук»
// на /admin/support). Нет таблицы — под постом прежняя кнопка на сайт, а
// нажатие подключённого к сайту Telegram всё равно работает.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

const GW_TG_UID_BASE = 9000000000000000000;
// Всплывающее окно Telegram — до 200 знаков.
const GW_TG_ALERT_MAX = 200;
// Ник в Roblox: 3–20 латинских букв, цифр и _.
const GW_TG_NICK_RE = '/^[A-Za-z0-9_]{3,20}\z/';

function gw_tg_uid(int $chat): string {
    return (string)(GW_TG_UID_BASE + $chat);
}

// Участник из Telegram без аккаунта сайта.
function gw_is_tg_uid($uid): bool {
    return is_numeric($uid) && (int)$uid >= GW_TG_UID_BASE;
}

function gw_has_tg_entry(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM tg_people LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Человек из Telegram: имя, @ник и язык обновляются при каждом нажатии.
function gw_tg_person_save(PDO $pdo, array $from, int $now): void {
    $chat = (int)$from['id'];
    $first = is_string($from['first_name'] ?? null) ? $from['first_name'] : '';
    $last  = is_string($from['last_name'] ?? null) ? $from['last_name'] : '';
    $user  = is_string($from['username'] ?? null) && preg_match('/^[A-Za-z0-9_]{3,32}\z/', $from['username']) ? $from['username'] : '';
    $vals  = [
        ':n' => tg_clean_line(trim($first . ' ' . $last), 80),
        ':u' => $user,
        ':l' => tg_lang_from_telegram($from['language_code'] ?? ''),
        ':t' => $now,
        ':c' => $chat,
    ];
    $st = $pdo->prepare('SELECT 1 FROM tg_people WHERE chat_id = :c');
    $st->execute([':c' => $chat]);
    if ($st->fetchColumn() !== false) {
        // Без first_name Telegram прислал не профиль — имя и @ник не трогаем.
        if (!is_string($from['first_name'] ?? null)) {
            $pdo->prepare('UPDATE tg_people SET lang = :l, updated_at = :t WHERE chat_id = :c')
                ->execute([':l' => $vals[':l'], ':t' => $now, ':c' => $chat]);
            return;
        }
        $pdo->prepare('UPDATE tg_people SET name = :n, username = :u, lang = :l, updated_at = :t WHERE chat_id = :c')->execute($vals);
        return;
    }
    try {
        $pdo->prepare('INSERT INTO tg_people (chat_id, name, username, lang, updated_at) VALUES (:c, :n, :u, :l, :t)')->execute($vals);
    } catch (PDOException $e) {
        // Два нажатия разом — строку уже вставило первое.
    }
}

function gw_tg_person(PDO $pdo, int $chat): ?array {
    $st = $pdo->prepare('SELECT * FROM tg_people WHERE chat_id = :c');
    $st->execute([':c' => $chat]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// Кнопка под постом идущего розыгрыша: участие прямо в Telegram.
// $tail — хвост надписи «Участвовать», число участников: « (128)».
// $app — имя бота с мини-приложением (gw_tg_app_bot()): кнопка-ссылка на
// него; пусто — кнопка бота.
function gw_tg_post_buttons(array $row, array $map, string $tail = '', string $app = ''): array {
    if ($app !== '') {
        return [[gw_button($map, '🎁', 'Участвовать' . $tail, gw_tg_app_link($app, (int)$row['id']))]];
    }
    $join = gw_button($map, '🎁', 'Участвовать' . $tail, '');
    unset($join['url']);
    $join['callback_data'] = 'gw:' . (int)$row['id'];
    return [[$join]];
}

function gw_tg_app_link(string $bot, int $id): string {
    return 'https://t.me/' . $bot . '?startapp=gw' . $id;
}

// Имя бота, если у него включено мини-приложение (Main Mini App в
// @BotFather), иначе ''. Спрашивает Telegram (getMe): вызывающие зовут это
// раз на проход по постам и только когда есть что править. Telegram молчит —
// '' (кнопка бота), следующая правка поста вернёт ссылку.
function gw_tg_app_bot(callable $send): string {
    $res = $send('getMe', []);
    $me = is_array($res) && !empty($res['ok']) && is_array($res['result'] ?? null) ? $res['result'] : [];
    $name = is_string($me['username'] ?? null) ? $me['username'] : '';
    if (empty($me['has_main_web_app']) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}\z/', $name)) { return ''; }
    return $name;
}

// Кнопка «Забрать приз» под постом с итогами: открывает бота.
function gw_tg_prize_button(array $row, string $bot): array {
    return ['text' => '🎁 Забрать приз', 'url' => 'https://t.me/' . $bot . '?start=gwp' . (int)$row['id']];
}

// Есть ли среди победителей участники из Telegram без аккаунта сайта.
function gw_tg_has_winners(PDO $pdo, int $id): bool {
    $st = $pdo->prepare('SELECT user_id FROM giveaway_entries WHERE giveaway_id = :g AND prize IS NOT NULL');
    $st->execute([':g' => $id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (gw_is_tg_uid($uid)) { return true; }
    }
    return false;
}

// --------------------------------------------------------------------------
//  Нажатие «Участвовать»
// --------------------------------------------------------------------------

function gw_tg_text(string $key, string $lang, array $v = []): string {
    $t = [
        'joined'  => ['✅ Вы участвуете в розыгрыше «{title}»! Итоги: {when}.{keep}',
                      "✅ You're in the giveaway «{title}»! Results: {when}.{keep}"],
        'keep'    => [' Не отписывайтесь от каналов из условий до итогов.',
                      " Don't unsubscribe from the channels in the conditions until the results."],
        'last'    => ['✅ Вы участвуете и заняли последнее место — приём закрыт, итоги совсем скоро.',
                      "✅ You're in and took the last spot — entries are closed, results very soon."],
        'already' => ['Вы уже участвуете ✅ Итоги: {when}.', "You're already in ✅ Results: {when}."],
        'missing' => ['Чтобы участвовать, подпишитесь: {list}. Потом нажмите «Участвовать» ещё раз.',
                      'To enter, subscribe to: {list}. Then tap «Участвовать» again.'],
        'down'    => ['Telegram не ответил при проверке подписки. Нажмите ещё раз через минуту.',
                      "Telegram didn't answer while checking your subscription. Tap again in a minute."],
        'closed'  => ['Приём заявок закрыт.', 'Entries are closed.'],
        'full'    => ['Все места заняты — приём закрыт.', 'All spots are taken — entries are closed.'],
        'banned'  => ['Участие в розыгрышах для вас закрыто.', "You can't enter giveaways."],
        'tg_used' => ['Этот Telegram уже участвует с другого аккаунта сайта.', 'This Telegram is already in with another site account.'],
        'site'    => ['Участвуйте на сайте maknemy.com, в разделе «Розыгрыши».', 'Join on the site maknemy.com, in the «Розыгрыши» section.'],
        'error'   => ['Не получилось. Нажмите ещё раз.', "That didn't work. Tap again."],
        // Мини-приложение: текст — заголовок экрана; название, призы и срок
        // итогов там на карточке, каналы — списком с кнопками «Подписаться».
        'app_joined'  => ['Вы участвуете!', "You're in!"],
        'app_already' => ['Вы уже участвуете!', "You're already in!"],
        'app_last'    => ['Вы участвуете и заняли последнее место — итоги совсем скоро.',
                          "You're in and took the last spot — results very soon."],
        'app_missing' => ['Чтобы участвовать, подпишитесь на каналы из условий.',
                          'To enter, subscribe to the channels from the conditions.'],
        'auth'    => ['Откройте розыгрыш заново из Telegram.', 'Open the giveaway again from Telegram.'],
        'rate'    => ['Слишком много попыток. Попробуйте через несколько минут.', 'Too many tries. Try again in a few minutes.'],
        'none'    => ['Сейчас нет розыгрыша, в котором можно участвовать.', 'There is no giveaway to enter right now.'],
    ];
    $s = $t[$key][$lang === 'en' ? 1 : 0];
    $pairs = [];
    foreach ($v as $k => $val) { $pairs['{' . $k . '}'] = (string)$val; }
    return strtr($s, $pairs);
}

function gw_tg_alert(array $cq, string $text): array {
    return [
        'method'            => 'answerCallbackQuery',
        'callback_query_id' => (string)($cq['id'] ?? ''),
        'text'              => mb_substr($text, 0, GW_TG_ALERT_MAX),
        'show_alert'        => true,
    ];
}

// Названия каналов, на которые не подписан, — через запятую.
function gw_tg_missing_list(PDO $pdo, array $missing): string {
    $channels = gw_channels($pdo, array_map('intval', $missing));
    $names = [];
    foreach ($missing as $c) {
        $title = isset($channels[(int)$c]) ? (string)$channels[(int)$c]['title'] : '';
        $names[] = $title !== '' ? $title : 'канал из условий';
    }
    return implode(', ', array_unique($names));
}

function gw_tg_joined_text(array $row, string $lang): string {
    return gw_tg_text('joined', $lang, [
        'title' => gw_title($row, $lang),
        'when'  => gw_when($row, $lang),
        'keep'  => gw_checked_channels($row) ? gw_tg_text('keep', $lang) : '',
    ]);
}

// Ответ gw_enter() — ключ текста gw_tg_text().
function gw_tg_enter_key(array $p, ?array $note): string {
    if (!empty($p['ok'])) {
        if ($note === null) { return 'already'; }
        return !empty($note['last']) ? 'last' : 'joined';
    }
    $err = (string)($p['error'] ?? '');
    if ($err === 'channels') { return 'missing'; }
    if ($err === 'tg_down') { return 'down'; }
    if (in_array($err, ['closed', 'full', 'banned', 'tg_used'], true)) { return $err; }
    return 'error';
}

/**
 * Участие из Telegram — кнопкой бота под постом и из мини-приложения.
 * $from — профиль Telegram (callback_query.from или user из initData).
 * ['key' => что вышло, 'row' => строка розыгрыша (после записи) или null,
 * 'missing' => [chat_id каналов, на которые не подписан], 'after' => что
 * сделать после ответа: null, ['join', записка gw_enter()] (участвует
 * аккаунт сайта), ['places', записка] или ['due', записка] (участник из
 * Telegram; due — занял последнее место)].
 * Ключи — тексты gw_tg_text(): joined, last, already, missing, down, closed,
 * full, banned, tg_used, site (нет tg_people — участие только на сайте),
 * error.
 */
function gw_tg_join(PDO $pdo, array $cfg, callable $send, array $from, int $id, int $now): array {
    $out = function (string $key, ?array $row = null, array $missing = [], ?array $after = null): array {
        return ['key' => $key, 'row' => $row, 'missing' => $missing, 'after' => $after];
    };
    if (!is_int($from['id'] ?? null) || !empty($from['is_bot']) || !gw_ready($pdo)) { return $out('error'); }
    $chat = $from['id'];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $row = gw_load($pdo, $id);
    if (!$row || $row['status'] === 'draft') { return $out('closed'); }

    // Telegram подключён к аккаунту сайта — участвует аккаунт, как с сайта.
    $site = false;
    try {
        $st = $pdo->prepare('SELECT user_id FROM tg_links WHERE chat_id = :c LIMIT 1');
        $st->execute([':c' => $chat]);
        $site = $st->fetchColumn();
    } catch (PDOException $e) {
        $site = false;
    }
    if ($site !== false) {
        $res = gw_enter($pdo, $cfg, $send, (string)$site, $id, $now, 'cb');
        $note = $res[2] ?? null;
        $key = gw_tg_enter_key($res[1], $note);
        return $out($key, $note['row'] ?? $row, $key === 'missing' ? array_map('strval', $res[1]['missing'] ?? []) : [], $note ? ['join', $note] : null);
    }

    if (!gw_has_tg_entry($pdo)) { return $out('site', $row); }
    if (gw_phase($row, $now) !== 'live') { return $out($row['status'] === 'live' && gw_full($pdo, $row) ? 'full' : 'closed', $row); }

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries e WHERE e.giveaway_id = :g AND e.chat_id = :c' . gw_active_sql($pdo));
    $st->execute([':g' => $id, ':c' => $chat]);
    if ($st->fetchColumn() !== false) { return $out('already', $row); }

    $channels = gw_checked_channels($row);
    if ($channels) {
        $chk = gw_check_channels($send, $channels, $chat);
        if ($chk['error']) { return $out('down', $row); }
        if (!$chk['ok']) { return $out('missing', $row, $chk['missing']); }
    }

    gw_tg_person_save($pdo, $from, $now);
    [$err, $after] = gw_add_entry($pdo, $id, gw_tg_uid($chat), $chat, $now, 'cb');
    if ($err === 'closed' || $err === 'full') { return $out($err, $after ?: $row); }
    if ($err === 'dup') { return $out('tg_used', $row); }
    $last = (int)$after['ends_at'] <= $now;
    $note = ['chat_id' => $chat, 'lang' => $lang, 'row' => $after, 'last' => $last, 'user' => gw_tg_uid($chat)];
    return $out($last ? 'last' : 'joined', $after, [], [$last ? 'due' : 'places', $note]);
}

// Текст ответа gw_tg_join(). $app — для мини-приложения: там текст —
// короткий заголовок экрана (app_*).
function gw_tg_join_text(PDO $pdo, array $j, string $lang, bool $app = false): string {
    $key = $j['key'];
    if ($app && in_array($key, ['joined', 'already', 'last', 'missing'], true)) { return gw_tg_text('app_' . $key, $lang); }
    if ($key === 'joined') { return gw_tg_joined_text($j['row'], $lang); }
    if ($key === 'already') { return gw_tg_text('already', $lang, ['when' => gw_when($j['row'], $lang)]); }
    if ($key === 'missing') { return gw_tg_text('missing', $lang, ['list' => gw_tg_missing_list($pdo, $j['missing'])]); }
    return gw_tg_text($key, $lang);
}

/**
 * Нажатие «Участвовать» под постом в канале (callback_query с данными
 * gw:<id>). ['reply' => answerCallbackQuery — его вебхук отдаёт телом
 * ответа, 'after' => что сделать после ответа (gw_tg_join())].
 * null — нажатие не наше.
 */
function gw_tg_callback(PDO $pdo, array $cfg, callable $send, array $update, int $now): ?array {
    $cq = $update['callback_query'] ?? null;
    if (!is_array($cq) || !is_string($cq['data'] ?? null) || !preg_match('/^gw:([1-9][0-9]{0,8})\z/', $cq['data'], $m)) {
        return null;
    }
    $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $j = gw_tg_join($pdo, $cfg, $send, $from, (int)$m[1], $now);
    return ['reply' => gw_tg_alert($cq, gw_tg_join_text($pdo, $j, $lang)), 'after' => $j['after']];
}

// --------------------------------------------------------------------------
//  Мини-приложение (/tg-app, api/gw_tg_app.php)
// --------------------------------------------------------------------------

// Данные Telegram (initData) годны сутки: дольше мини-приложение открытым
// не держат, а перехваченная строка не должна работать вечно.
const GW_TG_APP_TTL = 86400;

/**
 * Проверить initData мини-приложения. Подпись — по правилам Telegram
 * («Validating data received via the Mini App»): ключ = HMAC-SHA256 токена
 * бота с ключом "WebAppData"; hash = HMAC-SHA256 строки из всех полей,
 * кроме hash, вида «поле=значение», по алфавиту, через перевод строки.
 * Подделать её без токена нельзя, поэтому id в Telegram отсюда — настоящий.
 * ['user' => профиль Telegram, 'start' => start_param] или null.
 */
function gw_tg_app_auth(string $init, string $token, int $now): ?array {
    if ($init === '' || strlen($init) > 8192 || $token === '') { return null; }
    $data = [];
    foreach (explode('&', $init) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) !== 2) { return null; }
        $data[urldecode($kv[0])] = urldecode($kv[1]);
    }
    $hash = (string)($data['hash'] ?? '');
    unset($data['hash']);
    if (!preg_match('/^[0-9a-f]{64}\z/', $hash)) { return null; }
    ksort($data, SORT_STRING);
    $lines = [];
    foreach ($data as $k => $v) { $lines[] = $k . '=' . $v; }
    $key = hash_hmac('sha256', $token, 'WebAppData', true);
    if (!hash_equals(hash_hmac('sha256', implode("\n", $lines), $key), $hash)) { return null; }
    $at = (int)($data['auth_date'] ?? 0);
    if ($at <= 0 || $at < $now - GW_TG_APP_TTL || $at > $now + 300) { return null; }
    $user = json_decode((string)($data['user'] ?? ''), true);
    if (!is_array($user) || !is_int($user['id'] ?? null) || $user['id'] <= 0 || !empty($user['is_bot'])) { return null; }
    return ['user' => $user, 'start' => (string)($data['start_param'] ?? '')];
}

// Какой розыгрыш открыть: из ссылки кнопки (startapp=gw<id>), а без неё
// (мини-приложение открыли из профиля бота) — последний идущий.
function gw_tg_app_target(PDO $pdo, string $start, int $now): int {
    if (preg_match('/^gw([1-9][0-9]{0,8})\z/', $start, $m)) { return (int)$m[1]; }
    $real = gw_has_test($pdo) ? ' AND test = 0' : '';
    $st = $pdo->prepare("SELECT id FROM giveaways WHERE status = 'live' AND ends_at > :now" . $real . ' ORDER BY published_at DESC, id DESC LIMIT 1');
    $st->execute([':now' => $now]);
    return (int)$st->fetchColumn();
}

// Розыгрыш для экрана мини-приложения.
// Призы с одинаковым названием — одной строкой «× N»: в админке их часто
// заводят по одному («Permanent Venom» трижды). icon — полный адрес иконки
// предмета с сайта или null.
function gw_tg_app_prizes(array $row): array {
    $out = [];
    $at = [];
    foreach (gw_prizes($row['prizes_json']) as $p) {
        $key = mb_strtolower(trim($p['name']));
        if (isset($at[$key])) {
            $out[$at[$key]]['count'] += max(1, $p['count']);
            if ($out[$at[$key]]['icon'] === null && $p['icon'] !== '') { $out[$at[$key]]['icon'] = TG_SITE . $p['icon']; }
            continue;
        }
        $at[$key] = count($out);
        $out[] = ['name' => $p['name'], 'count' => max(1, $p['count']), 'icon' => $p['icon'] !== '' ? TG_SITE . $p['icon'] : null];
    }
    return $out;
}

// Номер участника — по порядку вступления, «билет №…» на экране. 0 — не
// участвует. Отписавшиеся номер не освобождают: у остальных он не меняется.
function gw_tg_app_ticket(PDO $pdo, int $id, int $chat): int {
    $st = $pdo->prepare('SELECT joined_at, user_id FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c');
    $st->execute([':g' => $id, ':c' => $chat]);
    $me = $st->fetch(PDO::FETCH_ASSOC);
    if (!$me) { return 0; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM giveaway_entries WHERE giveaway_id = :g
                          AND (joined_at < :t OR (joined_at = :t2 AND user_id <= :u))');
    $st->execute([':g' => $id, ':t' => (int)$me['joined_at'], ':t2' => (int)$me['joined_at'], ':u' => (string)$me['user_id']]);
    return (int)$st->fetchColumn();
}

function gw_tg_app_card(PDO $pdo, array $row, string $lang, int $now): array {
    $id = (int)$row['id'];
    $prizes = gw_tg_app_prizes($row);
    return [
        'id'      => $id,
        'title'   => gw_title($row, $lang),
        'image'   => (string)$row['image_url'] !== '' ? TG_SITE . $row['image_url'] : null,
        'prizes'  => $prizes,
        // У розыгрыша «по числу участников» срок — это и есть полоса
        // «N из max» на экране; строкой его не повторяем.
        'when'    => gw_no_deadline($row) ? '' : gw_when($row, $lang),
        'entries' => gw_entry_counts($pdo, [$id])[$id] ?? 0,
        'max'     => gw_max($row),
        'phase'   => gw_phase($row, $now),
        'site'    => gw_post_link($id, gw_is_test($row)),
    ];
}

// --------------------------------------------------------------------------
//  Аватарки каналов в мини-приложении
// --------------------------------------------------------------------------
//
// Фото канала бот берёт у Telegram (getChat → photo.small_file_id, 160×160 →
// getFile → файл с api.telegram.org/file/bot<токен>/…) и кладёт копию в
// images_dir тем же save_image_bytes(), что картинки новостей:
// /images/<sha1>.jpg. Адрес файла у Telegram содержит токен бота — наружу
// уходит только копия. Какое фото у какого канала и когда спрашивали — в JSON
// во временной папке (таблица ради этого не нужна; пропал файл — спросим
// заново). Раз в сутки спрашиваем снова: фото каналы меняют. Нет фото,
// Telegram молчит, нет images_dir — '' (на странице буква).

const GW_TG_PHOTO_TTL = 86400;

function gw_tg_photo_state(): string {
    return sys_get_temp_dir() . '/maknemy-tg-photos.json';
}

// Скачать файл Telegram по file_path из getFile.
function gw_tg_file_fetch(string $token): callable {
    return function (string $path) use ($token): ?string {
        if (!function_exists('curl_init') || !preg_match('~^[A-Za-z0-9_][A-Za-z0-9_./-]{0,200}\z~', $path) || strpos($path, '..') !== false) { return null; }
        $ch = curl_init('https://api.telegram.org/file/bot' . $token . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return is_string($raw) && $code === 200 && $raw !== '' && strlen($raw) <= 512000 ? $raw : null;
    };
}

// Адрес копии фото канала на сайте или ''.
function gw_tg_channel_photo(callable $send, ?callable $fetch, string $dir, int $chat, int $now, string $statePath): string {
    if ($dir === '') { return ''; }
    $state = json_decode((string)@file_get_contents($statePath), true);
    $have = is_array($state) && is_array($state[(string)$chat] ?? null) ? $state[(string)$chat] : null;
    if ($have && $now - (int)($have[1] ?? 0) < GW_TG_PHOTO_TTL
        && ((string)$have[0] === '' || is_file(rtrim($dir, '/\\') . '/' . basename((string)$have[0])))) {
        return (string)$have[0];
    }
    if ($fetch === null) { return $have ? (string)$have[0] : ''; }

    $url = '';
    $res = $send('getChat', ['chat_id' => $chat]);
    if ($res === null) { return $have ? (string)$have[0] : ''; }
    $fileId = $res['result']['photo']['small_file_id'] ?? null;
    if (!empty($res['ok']) && is_string($fileId) && $fileId !== '') {
        $file = $send('getFile', ['file_id' => $fileId]);
        $path = is_array($file) && !empty($file['ok']) ? ($file['result']['file_path'] ?? null) : null;
        $bytes = is_string($path) ? $fetch($path) : null;
        if ($bytes === null) { return $have ? (string)$have[0] : ''; }
        try {
            require_once __DIR__ . '/images.php';
            $url = save_image_bytes($bytes, $dir, 512000, ['jpg', 'png', 'webp']);
        } catch (Throwable $e) {
            error_log('tg channel photo: ' . $e->getMessage());
            return $have ? (string)$have[0] : '';
        }
    }
    json_state_update($statePath, function ($s) use ($chat, $url, $now) {
        $s = is_array($s) ? $s : [];
        $s[(string)$chat] = [$url, $now];
        return [null, $s];
    }, null);
    return $url;
}

// Каналы, на которые не подписан: название, ссылка «Подписаться» — та же
// ссылка-приглашение розыгрыша, что в окне на сайте (gw_public_item()), — и
// аватарка (полный адрес или null). $photo(chat_id) → адрес на сайте или ''.
function gw_tg_app_missing(PDO $pdo, int $id, array $missing, string $lang, ?callable $photo = null): array {
    $channels = gw_channels($pdo, array_map('intval', $missing));
    $links = gw_links($pdo, [$id])[$id] ?? [];
    $out = [];
    foreach ($missing as $c) {
        $ch = $channels[(int)$c] ?? null;
        $title = $ch && (string)$ch['title'] !== '' ? (string)$ch['title'] : ($lang === 'en' ? 'Channel from the conditions' : 'Канал из условий');
        $src = $photo ? $photo((int)$c) : '';
        $out[] = ['title' => $title, 'url' => $links[(int)$c] ?? ($ch ? gw_channel_url($ch) : ''), 'photo' => $src !== '' ? TG_SITE . $src : null];
    }
    return $out;
}

// Ответ без розыгрыша: auth, rate, none.
function gw_tg_app_fail(string $key, string $lang): array {
    return ['ok' => false, 'state' => $key, 'lang' => $lang, 'text' => gw_tg_text($key, $lang), 'note' => '', 'ticket' => 0, 'gw' => null, 'missing' => []];
}

/**
 * Мини-приложение открыли или нажали в нём «Проверить подписку»: участие
 * человека из проверенных initData ($auth — gw_tg_app_auth()).
 * [код, тело, что сделать после ответа — для gw_tg_after()].
 * $fetch и $photoState — скачивание файлов Telegram и кеш аватарок каналов
 * (gw_tg_channel_photo()); по умолчанию настоящие.
 */
function gw_tg_app_join(PDO $pdo, array $cfg, callable $send, array $auth, int $now, ?callable $fetch = null, ?string $photoState = null): array {
    $from = $auth['user'];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $id = gw_ready($pdo) ? gw_tg_app_target($pdo, (string)$auth['start'], $now) : 0;
    $row = $id > 0 ? gw_load($pdo, $id) : null;
    if (!$row || $row['status'] === 'draft') { return [200, gw_tg_app_fail('none', $lang), null]; }
    $j = gw_tg_join($pdo, $cfg, $send, $from, $id, $now);
    $row = $j['row'] ?? $row;
    $ok = in_array($j['key'], ['joined', 'last', 'already'], true);
    // Под заголовком «Вы участвуете»: отписавшийся до итогов выбывает.
    $keep = in_array($j['key'], ['joined', 'already'], true) && gw_checked_channels($row) ? trim(gw_tg_text('keep', $lang)) : '';
    $missing = [];
    if ($j['key'] === 'missing') {
        $tg = tg_config($cfg);
        $dir = is_string($cfg['images_dir'] ?? null) ? $cfg['images_dir'] : '';
        $fetch = $fetch ?? ($tg['token'] !== '' ? gw_tg_file_fetch($tg['token']) : null);
        $state = $photoState ?? gw_tg_photo_state();
        $photo = function (int $chat) use ($send, $fetch, $dir, $now, $state): string {
            return gw_tg_channel_photo($send, $fetch, $dir, $chat, $now, $state);
        };
        $missing = gw_tg_app_missing($pdo, $id, $j['missing'], $lang, $photo);
    }
    return [200, [
        'ok'      => $ok,
        'state'   => $j['key'],
        'lang'    => $lang,
        'text'    => gw_tg_join_text($pdo, $j, $lang, true),
        'note'    => $keep,
        'ticket'  => $ok ? gw_tg_app_ticket($pdo, $id, (int)$from['id']) : 0,
        'gw'      => gw_tg_app_card($pdo, $row, $lang, $now),
        'missing' => $missing,
    ], $j['after']];
}

// После ответа вебхуку: «вы участвуете» в личку (аккаунт сайта),
// напоминания «осталось N мест», итоги, если приём закрыт.
function gw_tg_after(PDO $pdo, array $cfg, ?array $after, int $now): void {
    if (!$after) { return; }
    $note = $after[1] ?? null;
    if ($after[0] === 'join' && $note) { gw_after_join($pdo, $cfg, $note); }
    if ($note) { gw_after_counts($pdo, $cfg, $now, (int)$note['row']['id']); }
    if ($note && empty($note['last'])) { gw_after_places($pdo, $cfg, $note, $now); }
    if ($note && !empty($note['last'])) { gw_after_due($pdo, $cfg, $now); }
}

// --------------------------------------------------------------------------
//  Приз: ник в Roblox
// --------------------------------------------------------------------------

function gw_tg_prize_text(string $key, string $lang, array $v = []): string {
    $t = [
        'won'     => ["🎉 Поздравляем! Вы победили в розыгрыше «{title}». Ваш приз: {prize}.\n\nПришлите одним сообщением свой ник в Roblox — на него выдадут приз.",
                      "🎉 Congratulations! You won the giveaway «{title}». Your prize: {prize}.\n\nSend your Roblox username in one message — the prize goes to it."],
        'won_has' => ["🎉 Вы победили в розыгрыше «{title}». Ваш приз: {prize}.\n\nНик в Roblox для приза: {nick}. Ошиблись — пришлите новый одним сообщением.",
                      "🎉 You won the giveaway «{title}». Your prize: {prize}.\n\nRoblox username for the prize: {nick}. Wrong? Send a new one in one message."],
        'site'    => ["🎉 Вы победили в розыгрыше «{title}». Ваш приз: {prize}.\n\nПриз выдадут на аккаунт Roblox, с которым вы вошли на сайт.",
                      "🎉 You won the giveaway «{title}». Your prize: {prize}.\n\nThe prize goes to the Roblox account you signed in with."],
        'lost'    => ['В розыгрыше «{title}» вы не выиграли. Следите за новыми розыгрышами в канале!',
                      "You didn't win the giveaway «{title}». Watch the channel for new giveaways!"],
        'wait'    => ['Итоги розыгрыша «{title}» ещё не подведены. Когда будут, приходите снова.',
                      'The results of «{title}» are not in yet. Come back when they are.'],
        'nick_ok' => ['Спасибо! Ник {nick} передан владельцу — приз выдадут на него.',
                      'Thanks! The username {nick} has been passed on — the prize goes to it.'],
        'nick_bad'=> ['Ник в Roblox — от 3 до 20 латинских букв, цифр или _. Пришлите ещё раз.',
                      'A Roblox username is 3–20 Latin letters, digits or _. Send it again.'],
        'write_ok'=> ['🔔 Готово! Если выиграете в розыгрыше, бот напишет вам сюда.',
                      '🔔 Done! If you win a giveaway, the bot will message you here.'],
    ];
    $s = $t[$key][$lang === 'en' ? 1 : 0];
    $pairs = [];
    foreach ($v as $k => $val) { $pairs['{' . $k . '}'] = (string)$val; }
    return strtr($s, $pairs);
}

// Поздравление участнику из Telegram с просьбой о нике; ждём его ответ.
function gw_tg_won_text(PDO $pdo, array $row, string $prize, int $chat, string $lang): string {
    $p = gw_tg_person($pdo, $chat);
    $nick = $p ? (string)$p['roblox_nick'] : '';
    $pdo->prepare('UPDATE tg_people SET await_gw = :g WHERE chat_id = :c')->execute([':g' => (int)$row['id'], ':c' => $chat]);
    $v = ['title' => gw_title($row, $lang), 'prize' => $prize, 'nick' => $nick];
    return gw_tg_prize_text($nick !== '' ? 'won_has' : 'won', $lang, $v);
}

/**
 * Личка бота: «/start gwp<id>» (кнопка «Забрать приз» под постом с итогами)
 * и ответ с ником в Roblox. Ответ бота — вызов sendMessage для тела ответа
 * вебхуку; null — сообщение не про приз.
 */
function gw_tg_bot_reply(PDO $pdo, array $update, int $now): ?array {
    $msg = $update['message'] ?? null;
    if (!is_array($msg) || ($msg['chat']['type'] ?? '') !== 'private' || !is_int($msg['chat']['id'] ?? null)) { return null; }
    if (!gw_has_tg_entry($pdo)) { return null; }
    $chat = $msg['chat']['id'];
    $from = is_array($msg['from'] ?? null) ? $msg['from'] : ['id' => $chat];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $text = is_string($msg['text'] ?? null) ? trim($msg['text']) : '';
    $say = function (string $s) use ($chat): array {
        return ['method' => 'sendMessage', 'chat_id' => $chat, 'text' => $s];
    };

    // Мини-приложение после участия просит разрешить боту писать
    // (requestWriteAccess); разрешили — Telegram кладёт в личку служебное
    // сообщение. Отвечаем по делу, а не общим «привет» tg_handle_update().
    if (is_array($msg['write_access_allowed'] ?? null)) {
        return $say(gw_tg_prize_text('write_ok', $lang));
    }

    if (preg_match('~^/start(?:@\w+)?\s+gwp([1-9][0-9]{0,8})\z~', $text, $m)) {
        $row = gw_load($pdo, (int)$m[1]);
        if (!$row || $row['status'] === 'draft') { return null; }
        $title = gw_title($row, $lang);
        if ($row['status'] !== 'drawn') { return $say(gw_tg_prize_text('wait', $lang, ['title' => $title])); }
        $st = $pdo->prepare('SELECT user_id, prize FROM giveaway_entries WHERE giveaway_id = :g AND chat_id = :c AND prize IS NOT NULL');
        $st->execute([':g' => (int)$row['id'], ':c' => $chat]);
        $win = $st->fetch(PDO::FETCH_ASSOC);
        if (!$win) { return $say(gw_tg_prize_text('lost', $lang, ['title' => $title])); }
        $prize = (string)(gw_decode_list($row['prizes_json'])[(int)$win['prize']]['name'] ?? '');
        if (!gw_is_tg_uid($win['user_id'])) {
            return $say(gw_tg_prize_text('site', $lang, ['title' => $title, 'prize' => $prize]));
        }
        gw_tg_person_save($pdo, $from + ['id' => $chat], $now);
        return $say(gw_tg_won_text($pdo, $row, $prize, $chat, $lang));
    }

    if ($text === '' || $text[0] === '/') { return null; }
    $p = gw_tg_person($pdo, $chat);
    if (!$p || (int)$p['await_gw'] <= 0) { return null; }
    if (!preg_match(GW_TG_NICK_RE, $text)) { return $say(gw_tg_prize_text('nick_bad', $lang)); }
    $pdo->prepare('UPDATE tg_people SET roblox_nick = :n, await_gw = 0, updated_at = :t WHERE chat_id = :c')
        ->execute([':n' => $text, ':t' => $now, ':c' => $chat]);
    return $say(gw_tg_prize_text('nick_ok', $lang, ['nick' => $text]));
}
