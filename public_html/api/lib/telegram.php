<?php
// Уведомления в Telegram: «вам написали в чате» и «новое обращение в
// поддержку» для модераторов.
//
// Как человек подключается. Колокольчик в /chat просит у api/tg_link.php
// одноразовую ссылку t.me/<бот>?start=<код>. Человек жмёт Start, Telegram
// присылает «/start <код>» на api/tg_webhook.php, и только в этот момент
// появляется связь аккаунт сайта → chat_id. Первым бот написать не может, так
// что Start нужен в любом случае, а код делает его заодно и привязкой.
//
// Что уходит в Telegram. Только КТО написал, без текста: переписка остаётся
// на сайте (так решил владелец). Кнопка под уведомлением открывает нужный
// диалог.
//
// Когда бот молчит — см. tg_claim_notify(): человек прямо сейчас в этом
// диалоге, или о непрочитанном в этой ветке уже звали.
//
// Модераторы — это обычные аккаунты сайта (вход через Roblox), чьи id
// перечислены в config.php (moderator_ids). Подключают Telegram они тем же
// колокольчиком. Список в конфиге, а не колонка в users: назначить модератора
// может только тот, у кого есть доступ к серверу, и никакой запрос с сайта
// этого не сделает.
//
// Ничего из этого не имеет права уронить чат или поддержку: пустой токен —
// функция выключена, таблиц нет — выключена, Telegram не отвечает — сообщение
// всё равно лежит на сайте.
//
// PHP 7.4: без match, без str_contains, без именованных аргументов.

require_once __DIR__ . '/chat.php';

const TG_API  = 'https://api.telegram.org/bot';
// Адрес сайта для кнопок и вебхука. Задан явно, а не собран из
// $_SERVER['HTTP_HOST']: заголовок Host приходит от клиента и подделывается.
const TG_SITE = 'https://maknemy.com';

// Сколько живёт ссылка из колокольчика. Хватает, чтобы открыть Telegram и
// нажать Start, и не хватает, чтобы забытая вкладка годами держала рабочий код.
const TG_CODE_TTL = 900;

// Человек «в диалоге», если его страница отмечалась в ветке не позже этого.
// Открытая вкладка перечитывает чат раз в 10 секунд (POLL_MS в
// js/chat-page.js), запас — на две пропущенные перечитки.
const TG_VIEWING = 25;

const TG_HTTP_TIMEOUT = 4;

// --------------------------------------------------------------------------
//  Настройки
// --------------------------------------------------------------------------

/**
 * Настройки бота из config.php. Кривое значение — то же, что пустое:
 * функция выключена, а не падает на первом запросе к Telegram.
 */
function tg_config(array $cfg): array {
    $token = isset($cfg['tg_bot_token']) && is_string($cfg['tg_bot_token']) ? trim($cfg['tg_bot_token']) : '';
    if (!preg_match('/^\d{1,20}:[A-Za-z0-9_-]{20,100}\z/', $token)) { $token = ''; }

    $name = isset($cfg['tg_bot_name']) && is_string($cfg['tg_bot_name']) ? ltrim(trim($cfg['tg_bot_name']), '@') : '';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}\z/', $name)) { $name = ''; }

    return ['token' => $token, 'name' => $name, 'moderators' => config_id_list($cfg, 'moderator_ids')];
}

function tg_enabled(array $tg): bool {
    return ($tg['token'] ?? '') !== '' && ($tg['name'] ?? '') !== '';
}

// Секрет вебхука. Telegram кладёт его в заголовок каждого запроса к
// api/tg_webhook.php, и без него запрос отвергается. Выводится из токена, а не
// хранится отдельно: одной строкой в конфиге меньше, и сменить токен —
// значит сменить и секрет.
function tg_webhook_secret(string $token): string {
    return hash_hmac('sha256', 'maknemy-telegram-webhook', $token);
}

// Есть ли таблицы. Нет — штатное состояние до миграции, а не ошибка.
function tg_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM tg_links LIMIT 1');
        $pdo->query('SELECT 1 FROM tg_codes LIMIT 1');
        $pdo->query('SELECT 1 FROM chat_reads LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function tg_lang($raw): string {
    return $raw === 'en' ? 'en' : 'ru';
}

// Язык из профиля Telegram — для ответов тем, кто пишет боту без кода:
// своего языка на сайте мы о них не знаем. Украинский, белорусский и
// казахский — тоже русский: английский этим людям читать труднее.
function tg_lang_from_telegram($code): string {
    $code = is_string($code) ? strtolower(substr($code, 0, 2)) : '';
    return in_array($code, ['ru', 'uk', 'be', 'kk'], true) ? 'ru' : 'en';
}

// --------------------------------------------------------------------------
//  Связь с Telegram
// --------------------------------------------------------------------------

/**
 * Настоящий транспорт: POST https://api.telegram.org/bot<токен>/<метод>.
 * Возвращает разобранный ответ Telegram или null — сеть, таймаут, не JSON.
 *
 * Вызовы из кода идут через callable $send, а не напрямую: тесты подставляют
 * свой и видят, что и кому ушло, не выходя в сеть.
 *
 * Ответ разбирается при любом коде HTTP: на 403 «бот заблокирован» Telegram
 * тоже отвечает JSON, и именно по нему привязка снимается.
 */
function tg_http(string $token): callable {
    return function (string $method, array $params) use ($token): ?array {
        if (!function_exists('curl_init')) { return null; }
        $ch = curl_init(TG_API . $token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => TG_HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) { return null; }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    };
}

// Ответ Telegram на отправку. 403 — человек заблокировал бота или удалил
// аккаунт: писать туда больше нельзя, и привязка снимается сама, иначе каждое
// следующее сообщение в чате стучалось бы в закрытую дверь.
function tg_after_send(PDO $pdo, int $chatId, ?array $res): bool {
    if (is_array($res) && !empty($res['ok'])) { return true; }
    if (is_array($res) && (int)($res['error_code'] ?? 0) === 403) {
        try {
            $pdo->prepare('DELETE FROM tg_links WHERE chat_id = :c')->execute([':c' => $chatId]);
        } catch (PDOException $e) {
            // таблицы нет — снимать нечего
        }
    }
    return false;
}

/**
 * Ответить пользователю и не держать при этом отправителя.
 *
 * Отправитель ждёт только своё сообщение. Сессия закрывается раньше всего:
 * PHP держит файл сессии заблокированным до конца скрипта, и следующий запрос
 * того же человека (перечитка чата) стоял бы в очереди, пока мы говорим с
 * Telegram. Дальше LiteSpeed (или FPM) отдаёт ответ, а скрипт продолжает.
 * Где ни того ни другого нет (php -S), уведомление просто уходит до ответа.
 */
function tg_finish_response(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// --------------------------------------------------------------------------
//  Привязка
// --------------------------------------------------------------------------

// 24 случайных байта в base64url — 32 символа. Параметр start у Telegram
// принимает до 64 символов из [A-Za-z0-9_-].
function tg_new_code(): string {
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

/**
 * Выдать ссылку на бота с одноразовым кодом. [код, тело].
 *
 * У человека один живой код: прежний стирается. Заодно убираются чужие
 * просроченные — отдельной уборки у таблицы нет.
 */
function tg_link_start(PDO $pdo, array $tg, string $me, string $lang, int $now, ?string $code = null): array {
    if ($me === '')          { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!tg_enabled($tg))    { return [503, ['ok' => false, 'error' => 'off']]; }
    if (!tg_ready($pdo))     { return [503, ['ok' => false, 'error' => 'not_ready']]; }

    $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = :u');
    $st->execute([':u' => $me]);
    if ($st->fetchColumn() === false) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $code = $code ?? tg_new_code();
    $pdo->prepare('DELETE FROM tg_codes WHERE user_id = :u OR expires_at < :now')
        ->execute([':u' => $me, ':now' => $now]);
    $pdo->prepare('INSERT INTO tg_codes (code_hash, user_id, lang, expires_at) VALUES (:h, :u, :l, :e)')
        ->execute([':h' => hash('sha256', $code), ':u' => $me, ':l' => tg_lang($lang), ':e' => $now + TG_CODE_TTL]);

    return [200, ['ok' => true, 'url' => 'https://t.me/' . $tg['name'] . '?start=' . $code]];
}

function tg_unlink(PDO $pdo, string $me): array {
    if ($me === '')      { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    if (!tg_ready($pdo)) { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    $pdo->prepare('DELETE FROM tg_links WHERE user_id = :u')->execute([':u' => $me]);
    $pdo->prepare('DELETE FROM tg_codes WHERE user_id = :u')->execute([':u' => $me]);
    return [200, ['ok' => true]];
}

/**
 * Погасить код и привязать аккаунт к чату. Язык приходит из кода: его
 * выбрал сам человек на сайте, когда жал колокольчик. null — кода нет или он
 * просрочен.
 *
 * Код гасится DELETE-ом, и привязка идёт только если удалил именно этот
 * запрос: два одновременных /start с одним кодом не привяжут аккаунт дважды.
 */
function tg_link_finish(PDO $pdo, string $code, int $chatId, string $tgName, int $now): ?string {
    $hash = hash('sha256', $code);
    $st = $pdo->prepare('SELECT user_id, lang, expires_at FROM tg_codes WHERE code_hash = :h');
    $st->execute([':h' => $hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }

    $del = $pdo->prepare('DELETE FROM tg_codes WHERE code_hash = :h');
    $del->execute([':h' => $hash]);
    if ($del->rowCount() !== 1 || (int)$row['expires_at'] < $now) { return null; }

    $user = (string)$row['user_id'];
    $lang = tg_lang($row['lang']);
    $pdo->prepare('DELETE FROM tg_links WHERE user_id = :u')->execute([':u' => $user]);
    $pdo->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, lang, linked_at) VALUES (:u, :c, :n, :l, :at)')
        ->execute([':u' => $user, ':c' => $chatId, ':n' => $tgName, ':l' => $lang, ':at' => $now]);
    return $lang;
}

// Как показать человеку, куда идут уведомления: @ник, а без ника — имя.
function tg_display_name(array $from): string {
    $user = $from['username'] ?? '';
    if (is_string($user) && preg_match('/^[A-Za-z0-9_]{3,32}\z/', $user)) { return '@' . $user; }
    $first = $from['first_name'] ?? '';
    $first = is_string($first) ? trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $first) ?? '') : '';
    return mb_substr($first, 0, 64);
}

/**
 * Для колокольчика: включён ли бот, подключён ли человек и куда.
 *
 * Зовётся на каждой перечитке чата (раз в 10 секунд), поэтому одним запросом
 * и без tg_ready(): нет таблицы — запрос падает, и колокольчик не
 * показывается.
 */
function tg_status(PDO $pdo, array $tg, string $me): array {
    $off = ['on' => false, 'linked' => false, 'name' => '', 'mod' => false];
    if ($me === '' || !tg_enabled($tg)) { return $off; }
    try {
        $st = $pdo->prepare('SELECT tg_name FROM tg_links WHERE user_id = :u');
        $st->execute([':u' => $me]);
        $name = $st->fetchColumn();
    } catch (PDOException $e) {
        return $off;
    }
    return [
        'on'     => true,
        'linked' => $name !== false,
        'name'   => $name !== false ? (string)$name : '',
        'mod'    => in_array($me, $tg['moderators'], true),
    ];
}

// --------------------------------------------------------------------------
//  Входящие от Telegram
// --------------------------------------------------------------------------

function tg_text(string $key, string $lang): string {
    $t = [
        'ru' => [
            'linked'  => 'Готово! Напишу, когда вам ответят на maknemy.com. Отключить — /stop или колокольчик в чате.',
            'expired' => 'Ссылка устарела. Откройте чат на maknemy.com и нажмите колокольчик ещё раз.',
            'stopped' => 'Уведомления отключены. Включить снова — колокольчик в чате на maknemy.com.',
            'hello'   => 'Я сообщаю о новых сообщениях на maknemy.com. Чтобы включить уведомления, откройте чат на сайте и нажмите колокольчик.',
            'openSite'=> 'Открыть чат',
            'chat'    => 'Новое сообщение от %s',
            'openChat'=> 'Открыть чат',
            'support' => 'Новое обращение в поддержку от %s',
            'openTickets' => 'Открыть обращения',
        ],
        'en' => [
            'linked'  => 'Done! I will let you know when someone messages you on maknemy.com. To turn it off, send /stop or use the bell in the chat.',
            'expired' => 'This link has expired. Open the chat on maknemy.com and tap the bell again.',
            'stopped' => 'Notifications are off. To turn them back on, use the bell in the chat on maknemy.com.',
            'hello'   => 'I let you know about new messages on maknemy.com. To turn notifications on, open the chat on the site and tap the bell.',
            'openSite'=> 'Open chat',
            'chat'    => 'New message from %s',
            'openChat'=> 'Open chat',
            'support' => 'New support request from %s',
            'openTickets' => 'Open requests',
        ],
    ];
    return $t[tg_lang($lang)][$key];
}

// Сообщение с одной кнопкой-ссылкой. Без parse_mode: ники приходят от
// пользователей, и простой текст не требует экранирования, которое можно
// забыть.
function tg_message(int $chatId, string $text, ?string $button = null, ?string $url = null): array {
    $out = ['chat_id' => $chatId, 'text' => $text];
    if ($button !== null && $url !== null) {
        $out['reply_markup'] = ['inline_keyboard' => [[['text' => $button, 'url' => $url]]]];
    }
    return $out;
}

/**
 * Разобрать обновление от Telegram. Возвращает вызов метода, который Telegram
 * выполнит сам (ответ на вебхук может быть вызовом API), или null — отвечать
 * нечего. Так бот отвечает на /start без второго исходящего запроса.
 *
 * Только личные чаты: в группу бота могут добавить, и привязывать чат группы
 * к аккаунту сайта нельзя — уведомления читали бы все её участники.
 */
function tg_handle_update(PDO $pdo, array $update, int $now): ?array {
    $msg = $update['message'] ?? null;
    if (!is_array($msg)) { return null; }
    $chat = $msg['chat'] ?? null;
    if (!is_array($chat) || ($chat['type'] ?? '') !== 'private' || !is_int($chat['id'] ?? null)) { return null; }
    $chatId = $chat['id'];

    $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
    $lang = tg_lang_from_telegram($from['language_code'] ?? '');
    $text = is_string($msg['text'] ?? null) ? trim($msg['text']) : '';

    if (preg_match('~^/start(?:@\w+)?\s+([A-Za-z0-9_-]{16,64})\z~', $text, $m)) {
        $linked = tg_link_finish($pdo, $m[1], $chatId, tg_display_name($from), $now);
        if ($linked !== null) {
            return ['method' => 'sendMessage'] + tg_message($chatId, tg_text('linked', $linked));
        }
        return ['method' => 'sendMessage']
            + tg_message($chatId, tg_text('expired', $lang), tg_text('openSite', $lang), TG_SITE . '/chat');
    }

    if (preg_match('~^/stop(?:@\w+)?\z~', $text)) {
        $pdo->prepare('DELETE FROM tg_links WHERE chat_id = :c')->execute([':c' => $chatId]);
        return ['method' => 'sendMessage'] + tg_message($chatId, tg_text('stopped', $lang));
    }

    return ['method' => 'sendMessage']
        + tg_message($chatId, tg_text('hello', $lang), tg_text('openSite', $lang), TG_SITE . '/chat');
}

// --------------------------------------------------------------------------
//  Исходящие уведомления
// --------------------------------------------------------------------------

// «Ник (@логин)» из строки users; логин опускается, если совпадает с ником.
function tg_user_label(PDO $pdo, string $id): string {
    $st = $pdo->prepare('SELECT username, display_name FROM users WHERE roblox_id = :u');
    $st->execute([':u' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { return ''; }
    $user = (string)$r['username'];
    $nick = (string)$r['display_name'] !== '' ? (string)$r['display_name'] : $user;
    if ($user === '' || strcasecmp($user, $nick) === 0) { return $nick; }
    return $nick . ' (@' . $user . ')';
}

/**
 * Можно ли звать человека по этому сообщению — и если да, занять право.
 *
 * Нельзя, если он прямо сейчас в диалоге (seen_at свежий) или если о
 * непрочитанном в этой ветке уже звали (notified_id > last_read_id).
 *
 * Проверка и отметка — одним UPDATE с условием. Два сообщения подряд
 * отправляются параллельными запросами, и «прочитать, потом записать» дало бы
 * два уведомления вместо одного. Строки нет — человек ветку ещё не открывал:
 * её заводит вставка, а проигравшая гонку вставка упирается в первичный ключ.
 */
function tg_claim_notify(PDO $pdo, int $threadId, string $userId, int $messageId, int $now): bool {
    $up = $pdo->prepare(
        'UPDATE chat_reads SET notified_id = :m
          WHERE thread_id = :t AND user_id = :u
            AND notified_id <= last_read_id AND seen_at < :cut'
    );
    $up->execute([':m' => $messageId, ':t' => $threadId, ':u' => $userId, ':cut' => $now - TG_VIEWING]);
    if ($up->rowCount() > 0) { return true; }

    $has = $pdo->prepare('SELECT 1 FROM chat_reads WHERE thread_id = :t AND user_id = :u');
    $has->execute([':t' => $threadId, ':u' => $userId]);
    if ($has->fetchColumn() !== false) { return false; }

    try {
        $pdo->prepare('INSERT INTO chat_reads (thread_id, user_id, last_read_id, seen_at, notified_id)
                       VALUES (:t, :u, 0, 0, :m)')
            ->execute([':t' => $threadId, ':u' => $userId, ':m' => $messageId]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Сообщение в чате → уведомление собеседнику. true — ушло.
 */
function tg_notify_chat(PDO $pdo, array $tg, callable $send, string $senderId, int $threadId, int $messageId, int $now): bool {
    if (!tg_enabled($tg) || !tg_ready($pdo)) { return false; }

    $st = $pdo->prepare('SELECT a_id, b_id FROM chat_threads WHERE id = :t');
    $st->execute([':t' => $threadId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) { return false; }
    $to = ((string)$t['a_id'] === $senderId) ? (string)$t['b_id'] : (string)$t['a_id'];
    if ($to === $senderId) { return false; }

    $st = $pdo->prepare('SELECT chat_id, lang FROM tg_links WHERE user_id = :u');
    $st->execute([':u' => $to]);
    $link = $st->fetch(PDO::FETCH_ASSOC);
    if (!$link) { return false; }

    if (!tg_claim_notify($pdo, $threadId, $to, $messageId, $now)) { return false; }

    $who     = tg_user_label($pdo, $senderId);
    $lang    = tg_lang($link['lang']);
    $chatId  = (int)$link['chat_id'];
    $res = $send('sendMessage', tg_message(
        $chatId,
        '💬 ' . sprintf(tg_text('chat', $lang), $who),
        tg_text('openChat', $lang),
        TG_SITE . '/chat?to=' . $senderId
    ));
    return tg_after_send($pdo, $chatId, $res);
}

/**
 * Новое обращение → всем модераторам, подключившим Telegram. Возвращает,
 * скольким ушло. Автору о собственном обращении не пишем, даже если он
 * модератор.
 */
function tg_notify_support(PDO $pdo, array $tg, callable $send, string $authorId): int {
    if (!tg_enabled($tg) || !$tg['moderators'] || !tg_ready($pdo)) { return 0; }

    $in = implode(',', array_fill(0, count($tg['moderators']), '?'));
    $st = $pdo->prepare("SELECT user_id, chat_id, lang FROM tg_links WHERE user_id IN ($in)");
    $st->execute($tg['moderators']);
    $links = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$links) { return 0; }

    $who  = tg_user_label($pdo, $authorId);
    $sent = 0;
    foreach ($links as $l) {
        if ((string)$l['user_id'] === $authorId) { continue; }
        $lang   = tg_lang($l['lang']);
        $chatId = (int)$l['chat_id'];
        $res = $send('sendMessage', tg_message(
            $chatId,
            '🆘 ' . sprintf(tg_text('support', $lang), $who),
            tg_text('openTickets', $lang),
            TG_SITE . '/admin/support'
        ));
        if (tg_after_send($pdo, $chatId, $res)) { $sent++; }
    }
    return $sent;
}

// Обёртки для эндпоинтов: ответ уже ушёл, дальше — только уведомление, и
// любая его ошибка остаётся в журнале, а не у посетителя.
function tg_after_chat_send(PDO $pdo, array $cfg, string $me, int $threadId, int $messageId, int $now): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    try {
        tg_notify_chat($pdo, $tg, tg_http($tg['token']), $me, $threadId, $messageId, $now);
    } catch (Throwable $e) {
        error_log('tg chat notify: ' . $e->getMessage());
    }
}

function tg_after_support(PDO $pdo, array $cfg, string $authorId): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg) || !$tg['moderators']) { return; }
    tg_finish_response();
    try {
        tg_notify_support($pdo, $tg, tg_http($tg['token']), $authorId);
    } catch (Throwable $e) {
        error_log('tg support notify: ' . $e->getMessage());
    }
}

// --------------------------------------------------------------------------
//  Установка вебхука (кнопка на /admin/support)
// --------------------------------------------------------------------------

/**
 * Сказать Telegram, куда слать обновления, и завести подсказку команд.
 * Делается один раз после того, как токен лёг в config.php, и повторно — при
 * смене токена. true — вебхук принят.
 *
 * drop_pending_updates: всё, что люди писали боту до установки, устарело.
 */
function tg_setup_webhook(callable $send, string $token): bool {
    $res = $send('setWebhook', [
        'url'                  => TG_SITE . '/api/tg_webhook.php',
        'secret_token'         => tg_webhook_secret($token),
        'allowed_updates'      => ['message'],
        'drop_pending_updates' => true,
        'max_connections'      => 5,
    ]);
    $send('setMyCommands', ['commands' => [['command' => 'stop', 'description' => 'Отключить уведомления']]]);
    $send('setMyCommands', [
        'commands'      => [['command' => 'stop', 'description' => 'Turn notifications off']],
        'language_code' => 'en',
    ]);
    return is_array($res) && !empty($res['ok']);
}
