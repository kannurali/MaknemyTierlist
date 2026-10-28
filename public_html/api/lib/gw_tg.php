<?php
// Участие в розыгрыше прямо в Telegram — без входа на сайт.
//
// Под постом розыгрыша в канале (раздел «Пост в канале» в giveaway.php)
// кнопка «🎁 Участвовать» — не ссылка, а кнопка бота (callback): человек жмёт
// её и не уходит из канала. Telegram присылает вебхуку callback_query, бот
// проверяет подписку на каналы из условий (getChatMember) и отвечает
// всплывающим окном: «✅ Вы участвуете» или на какие каналы подписаться.
// Рядом вторая кнопка — «На сайте», для тех, кто хочет участвовать там.
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

// Кнопки под постом идущего розыгрыша: участие прямо в Telegram и сайт.
function gw_tg_post_buttons(array $row, array $map): array {
    $id = (int)$row['id'];
    $join = gw_button($map, '🎁', 'Участвовать', '');
    unset($join['url']);
    $join['callback_data'] = 'gw:' . $id;
    return [[$join], [['text' => 'На сайте ↗', 'url' => gw_post_link($id, gw_is_test($row))]]];
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
        'site'    => ['Участвуйте на сайте: кнопка «На сайте» под постом.', 'Join on the site: the «На сайте» button under the post.'],
        'error'   => ['Не получилось. Нажмите ещё раз.', "That didn't work. Tap again."],
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

// Ответ gw_enter() — для всплывающего окна.
function gw_tg_enter_text(PDO $pdo, array $row, array $p, ?array $note, string $lang): string {
    if (!empty($p['ok'])) {
        if ($note === null) { return gw_tg_text('already', $lang, ['when' => gw_when($row, $lang)]); }
        return !empty($note['last']) ? gw_tg_text('last', $lang) : gw_tg_joined_text($note['row'], $lang);
    }
    $err = (string)($p['error'] ?? '');
    if ($err === 'channels') { return gw_tg_text('missing', $lang, ['list' => gw_tg_missing_list($pdo, $p['missing'] ?? [])]); }
    if ($err === 'tg_down') { return gw_tg_text('down', $lang); }
    if (in_array($err, ['closed', 'full', 'banned', 'tg_used'], true)) { return gw_tg_text($err, $lang); }
    return gw_tg_text('error', $lang);
}

/**
 * Нажатие «Участвовать» под постом в канале (callback_query с данными
 * gw:<id>). ['reply' => answerCallbackQuery — его вебхук отдаёт телом
 * ответа, 'after' => что сделать после ответа: null, ['join', записка
 * gw_enter()] (участвует аккаунт сайта), ['places', записка] или ['due',
 * записка] (участник из Telegram; due — занял последнее место)].
 * null — нажатие не наше.
 */
function gw_tg_callback(PDO $pdo, array $cfg, callable $send, array $update, int $now): ?array {
    $cq = $update['callback_query'] ?? null;
    if (!is_array($cq) || !is_string($cq['data'] ?? null) || !preg_match('/^gw:([1-9][0-9]{0,8})\z/', $cq['data'], $m)) {
        return null;
    }
    $from = is_array($cq['from'] ?? null) ? $cq['from'] : [];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $no = function (string $key) use ($cq, $lang): array {
        return ['reply' => gw_tg_alert($cq, gw_tg_text($key, $lang)), 'after' => null];
    };
    if (!is_int($from['id'] ?? null) || !empty($from['is_bot']) || !gw_ready($pdo)) { return $no('error'); }
    $chat = $from['id'];
    $id = (int)$m[1];
    $row = gw_load($pdo, $id);
    if (!$row || $row['status'] === 'draft') { return $no('closed'); }

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
        return ['reply' => gw_tg_alert($cq, gw_tg_enter_text($pdo, $row, $res[1], $note, $lang)), 'after' => $note ? ['join', $note] : null];
    }

    if (!gw_has_tg_entry($pdo)) { return $no('site'); }
    if (gw_phase($row, $now) !== 'live') { return $no($row['status'] === 'live' && gw_full($pdo, $row) ? 'full' : 'closed'); }

    $st = $pdo->prepare('SELECT 1 FROM giveaway_entries e WHERE e.giveaway_id = :g AND e.chat_id = :c' . gw_active_sql($pdo));
    $st->execute([':g' => $id, ':c' => $chat]);
    if ($st->fetchColumn() !== false) {
        return ['reply' => gw_tg_alert($cq, gw_tg_text('already', $lang, ['when' => gw_when($row, $lang)])), 'after' => null];
    }

    $channels = gw_checked_channels($row);
    if ($channels) {
        $chk = gw_check_channels($send, $channels, $chat);
        if ($chk['error']) { return $no('down'); }
        if (!$chk['ok']) {
            return ['reply' => gw_tg_alert($cq, gw_tg_text('missing', $lang, ['list' => gw_tg_missing_list($pdo, $chk['missing'])])), 'after' => null];
        }
    }

    gw_tg_person_save($pdo, $from, $now);
    [$err, $row] = gw_add_entry($pdo, $id, gw_tg_uid($chat), $chat, $now, 'cb');
    if ($err === 'closed' || $err === 'full') { return $no($err); }
    if ($err === 'dup') { return $no('tg_used'); }
    $last = (int)$row['ends_at'] <= $now;
    $note = ['chat_id' => $chat, 'lang' => $lang, 'row' => $row, 'last' => $last, 'user' => gw_tg_uid($chat)];
    return [
        'reply' => gw_tg_alert($cq, $last ? gw_tg_text('last', $lang) : gw_tg_joined_text($row, $lang)),
        'after' => [$last ? 'due' : 'places', $note],
    ];
}

// После ответа вебхуку: «вы участвуете» в личку (аккаунт сайта),
// напоминания «осталось N мест», итоги, если приём закрыт.
function gw_tg_after(PDO $pdo, array $cfg, ?array $after, int $now): void {
    if (!$after) { return; }
    $note = $after[1] ?? null;
    if ($after[0] === 'join' && $note) { gw_after_join($pdo, $cfg, $note); }
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
