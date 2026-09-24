<?php
// Уведомления в Telegram: «вам написали в чате», «новое обращение в
// поддержку» для модераторов и две рассылки всем подключившимся — изменения
// цен в тирлисте и новые новости (их можно выключить в профиле, см. tg_prefs).
//
// Как человек подключается. Колокольчик в /chat или в профиле просит у api/tg_link.php
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
//  Что присылать: настройки из колокольчика в профиле
// --------------------------------------------------------------------------

// О чём, кроме чата, бот пишет всем подключившимся. Ключ — колонка tg_prefs.
// Строки нет — человек ничего не выключал, и всё включено: подключил
// Telegram, значит, хочет знать.
//
// Настройки живут отдельно от tg_links: переподключение (новый /start после
// /stop или после блокировки бота) пересоздаёт привязку, а выбор человека
// должен пережить это. И выставить их можно ещё до подключения.
const TG_TOPICS = ['prices', 'news'];

/**
 * Что человек хочет получать. null — таблицы нет (миграция
 * 2026-09-25-tg-prefs.sql не выполнена): переключатели не показываются,
 * рассылки молчат.
 */
function tg_prefs_get(PDO $pdo, string $me): ?array {
    try {
        $st = $pdo->prepare('SELECT prices, news FROM tg_prefs WHERE user_id = :u');
        $st->execute([':u' => $me]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    $out = [];
    foreach (TG_TOPICS as $t) { $out[$t] = $row ? (int)$row[$t] === 1 : true; }
    return $out;
}

/**
 * Переключатели из профиля: {"prices": true, "news": false}. Не пришедший
 * ключ остаётся как был. Только настоящие true/false: «0» или «нет» из
 * кривого запроса не должны молча выключить рассылку.
 */
function tg_prefs_set(PDO $pdo, string $me, array $body): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    $prefs = tg_prefs_get($pdo, $me);
    if ($prefs === null) { return [503, ['ok' => false, 'error' => 'not_ready']]; }

    foreach (TG_TOPICS as $t) {
        if (!array_key_exists($t, $body)) { continue; }
        if (!is_bool($body[$t])) { return [400, ['ok' => false, 'error' => 'bad_prefs']]; }
        $prefs[$t] = $body[$t];
    }

    $st = $pdo->prepare('SELECT 1 FROM users WHERE roblox_id = :u');
    $st->execute([':u' => $me]);
    if ($st->fetchColumn() === false) { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }

    $args = [':u' => $me, ':p' => $prefs['prices'] ? 1 : 0, ':n' => $prefs['news'] ? 1 : 0];
    $has = $pdo->prepare('SELECT 1 FROM tg_prefs WHERE user_id = :u');
    $has->execute([':u' => $me]);
    if ($has->fetchColumn() === false) {
        try {
            $pdo->prepare('INSERT INTO tg_prefs (user_id, prices, news) VALUES (:u, :p, :n)')->execute($args);
            return [200, ['ok' => true, 'prefs' => $prefs]];
        } catch (PDOException $e) {
            // Второй переключатель, нажатый следом, вставил строку первым —
            // дальше обычное обновление.
        }
    }
    $pdo->prepare('UPDATE tg_prefs SET prices = :p, news = :n WHERE user_id = :u')->execute($args);
    return [200, ['ok' => true, 'prefs' => $prefs]];
}

// Для /admin/support: сколько людей подключили Telegram и сколько из них
// получают каждую рассылку. null — таблиц нет.
function tg_audience(PDO $pdo): ?array {
    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS linked,
                    SUM(CASE WHEN p.user_id IS NULL OR p.prices = 1 THEN 1 ELSE 0 END) AS prices,
                    SUM(CASE WHEN p.user_id IS NULL OR p.news = 1 THEN 1 ELSE 0 END) AS news
               FROM tg_links l LEFT JOIN tg_prefs p ON p.user_id = l.user_id'
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    return ['linked' => (int)$row['linked'], 'prices' => (int)$row['prices'], 'news' => (int)$row['news']];
}

// Колокольчик в профиле: то же, что в чате, и вдобавок переключатели.
function tg_profile_status(PDO $pdo, array $tg, string $me): array {
    $s = tg_status($pdo, $tg, $me);
    $s['prefs'] = $s['on'] ? tg_prefs_get($pdo, $me) : null;
    return $s;
}

// --------------------------------------------------------------------------
//  Входящие от Telegram
// --------------------------------------------------------------------------

function tg_text(string $key, string $lang): string {
    $t = [
        'ru' => [
            'linked'  => 'Готово! Напишу, когда вам ответят на maknemy.com, а ещё — об изменениях цен в тирлисте и новостях. Что присылать, выбирается колокольчиком в профиле на сайте. Отключить всё — /stop.',
            'expired' => 'Ссылка устарела. Откройте профиль на maknemy.com и нажмите колокольчик ещё раз.',
            'stopped' => 'Уведомления отключены. Включить снова — колокольчик в профиле на maknemy.com.',
            'hello'   => 'Я сообщаю о новых сообщениях в чате, изменениях цен в тирлисте и новостях maknemy.com. Чтобы включить уведомления, откройте профиль на сайте и нажмите колокольчик.',
            'openSite'=> 'Открыть профиль',
            'chat'    => 'Новое сообщение от %s',
            'openChat'=> 'Открыть чат',
            'support' => 'Новое обращение в поддержку от %s',
            'openTickets' => 'Открыть обращения',
            'prices'  => 'Обновились цены в тирлисте',
            'pricesMore'   => '…и ещё %d',
            'openTierlist' => 'Открыть тирлист',
            'readNews'     => 'Читать на сайте',
            'settings'     => 'Настроить уведомления',
        ],
        'en' => [
            'linked'  => 'Done! I will let you know when someone messages you on maknemy.com, and also about tier list price changes and news. Choose what to receive with the bell on your profile on the site. To turn everything off, send /stop.',
            'expired' => 'This link has expired. Open your profile on maknemy.com and tap the bell again.',
            'stopped' => 'Notifications are off. To turn them back on, use the bell on your profile on maknemy.com.',
            'hello'   => 'I let you know about new chat messages, tier list price changes and news on maknemy.com. To turn notifications on, open your profile on the site and tap the bell.',
            'openSite'=> 'Open profile',
            'chat'    => 'New message from %s',
            'openChat'=> 'Open chat',
            'support' => 'New support request from %s',
            'openTickets' => 'Open requests',
            'prices'  => 'Tier list prices updated',
            'pricesMore'   => '…and %d more',
            'openTierlist' => 'Open tier list',
            'readNews'     => 'Read on the site',
            'settings'     => 'Notification settings',
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

// То же с несколькими кнопками, каждая своей строкой: [[подпись, адрес], …].
function tg_message_buttons(int $chatId, string $text, array $buttons): array {
    $rows = [];
    foreach ($buttons as $b) { $rows[] = [['text' => $b[0], 'url' => $b[1]]]; }
    $out = ['chat_id' => $chatId, 'text' => $text];
    if ($rows) { $out['reply_markup'] = ['inline_keyboard' => $rows]; }
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
            + tg_message($chatId, tg_text('expired', $lang), tg_text('openSite', $lang), TG_SITE . '/profile');
    }

    if (preg_match('~^/stop(?:@\w+)?\z~', $text)) {
        $pdo->prepare('DELETE FROM tg_links WHERE chat_id = :c')->execute([':c' => $chatId]);
        return ['method' => 'sendMessage'] + tg_message($chatId, tg_text('stopped', $lang));
    }

    return ['method' => 'sendMessage']
        + tg_message($chatId, tg_text('hello', $lang), tg_text('openSite', $lang), TG_SITE . '/profile');
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

// --------------------------------------------------------------------------
//  Рассылки: цены в тирлисте и новости
// --------------------------------------------------------------------------

// Сколько предметов перечисляется в уведомлении о ценах; дальше — «…и ещё
// N». Длинный список на экране телефона всё равно никто не дочитает.
const TG_PRICE_LINES = 15;

// Пауза между сообщениями рассылки, микросекунды. Telegram пропускает боту
// около 30 сообщений в секунду, дальше отвечает 429.
const TG_BROADCAST_GAP = 40000;

// Пост, датированный раньше этого (секунды назад), при публикации не
// рассылается: это перенос старой записи задним числом, а не новость.
const TG_NEWS_FRESH = 172800;

// Сколько символов текста идёт под заголовком новости.
const TG_NEWS_EXCERPT = 220;

/**
 * Разослать всем подключившимся, кто не выключил тему $topic. Возвращает,
 * скольким ушло.
 *
 * $build($lang) → [текст, [[подпись, адрес], …]] — собирается раз на язык.
 * Telegram-чат, привязанный к двум аккаунтам сайта, получает одно сообщение.
 * $sleep — пауза в микросекундах; тесты подставляют пустую.
 *
 * На 429 Telegram говорит, сколько подождать: ждём (не дольше 30 секунд) и
 * повторяем один раз. Заблокировавшие бота отвязываются, как и в чате.
 * Нет таблицы tg_prefs — запрос падает, и рассылка молчит до миграции.
 */
function tg_broadcast(PDO $pdo, array $tg, callable $send, string $topic, callable $build, ?callable $sleep = null): int {
    if (!in_array($topic, TG_TOPICS, true)) { return 0; }
    if (!tg_enabled($tg) || !tg_ready($pdo)) { return 0; }
    $sleep = $sleep ?? function (int $us): void { usleep($us); };

    try {
        $rows = $pdo->query(
            "SELECT l.chat_id, l.lang FROM tg_links l
               LEFT JOIN tg_prefs p ON p.user_id = l.user_id
              WHERE p.user_id IS NULL OR p.$topic = 1
              ORDER BY l.linked_at, l.user_id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return 0;
    }

    $made = [];
    $seen = [];
    $sent = 0;
    foreach ($rows as $r) {
        $chatId = (int)$r['chat_id'];
        if (isset($seen[$chatId])) { continue; }
        $seen[$chatId] = true;

        $lang = tg_lang($r['lang']);
        if (!isset($made[$lang])) { $made[$lang] = $build($lang); }
        $msg = tg_message_buttons($chatId, $made[$lang][0], $made[$lang][1]);

        if (count($seen) > 1) { $sleep(TG_BROADCAST_GAP); }
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

// Строка из чужого ввода для текста уведомления: без управляющих символов и
// переводов строк (иначе одно имя разорвало бы список), не длиннее $max.
function tg_clean_line(string $s, int $max): string {
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

// Тирлист как он лежит в базе — до сохранения, чтобы было с чем сравнить.
function tg_tierlist_state(PDO $pdo): array {
    try {
        $raw = $pdo->query('SELECT data FROM tierlist WHERE id = 1')->fetchColumn();
    } catch (PDOException $e) {
        return [];
    }
    $d = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [];
}

// Предметы тирлиста — [id, имя, цена] по порядку тиров. Состояние присылает
// админка, поэтому каждое поле проверяется, а не берётся на веру.
function tg_tier_items(array $state): array {
    $out = [];
    $tiers = is_array($state['tiers'] ?? null) ? $state['tiers'] : [];
    foreach ($tiers as $tier) {
        if (!is_array($tier) || !is_array($tier['items'] ?? null)) { continue; }
        foreach ($tier['items'] as $it) {
            if (!is_array($it)) { continue; }
            $id = $it['id'] ?? '';
            if (!is_string($id) || $id === '') { continue; }
            $name  = is_string($it['name'] ?? null) ? $it['name'] : '';
            $value = $it['value'] ?? '';
            $value = is_string($value) || is_int($value) || is_float($value) ? (string)$value : '';
            $out[] = ['id' => $id, 'name' => tg_clean_line($name, 64), 'value' => tg_clean_line($value, 24)];
        }
    }
    return $out;
}

// Число из цены так, как его читает тирлист (parseVal в js/app.js): пробелы
// долой, запятая — точка, «k»/«к» — тысячи, «kk»/«кк» — миллионы. null — не
// число.
function tg_price_number(string $v): ?float {
    $s = mb_strtolower(preg_replace('/\s+/u', '', $v) ?? '');
    $s = preg_replace('/,/', '.', $s, 1) ?? '';
    $mult = 1.0;
    while (preg_match('/(kk|кк)$/u', $s)) { $mult *= 1e6; $s = mb_substr($s, 0, -2); }
    while (preg_match('/(k|к)$/u', $s))   { $mult *= 1e3; $s = mb_substr($s, 0, -1); }
    $s = preg_replace('/[^\d.\-]/', '', $s) ?? '';
    if (!preg_match('/^-?\d*\.?\d+/', $s, $m)) { return null; }
    return (float)$m[0] * $mult;
}

/**
 * Что поменялось в ценах: [['name', 'from', 'to'], …] по порядку тирлиста.
 * from = null — раньше цены не было (новый предмет или пустое поле).
 *
 * Предмет узнаётся по id: переименование и перенос в другой тир ценой не
 * считаются, удалённый предмет молчит. «25000» → «25 000» — та же цена, и
 * о ней тоже молчим.
 *
 * Пустое прежнее состояние — самое первое сохранение: «новыми» оказались бы
 * все предметы разом, поэтому молчим и здесь.
 */
function tg_price_changes(array $old, array $new): array {
    $before = [];
    foreach (tg_tier_items($old) as $it) { $before[$it['id']] = $it['value']; }
    if (!$before) { return []; }

    $out  = [];
    $seen = [];
    foreach (tg_tier_items($new) as $it) {
        if (isset($seen[$it['id']])) { continue; }
        $seen[$it['id']] = true;
        if ($it['value'] === '' || $it['name'] === '') { continue; }

        $was = $before[$it['id']] ?? '';
        if ($was === $it['value']) { continue; }
        if ($was !== '') {
            $a = tg_price_number($was);
            $b = tg_price_number($it['value']);
            if ($a !== null && $b !== null && $a == $b) { continue; }
        }
        $out[] = ['name' => $it['name'], 'from' => $was === '' ? null : $was, 'to' => $it['value']];
    }
    return $out;
}

function tg_prices_text(array $changes, string $lang): string {
    $lines = [];
    foreach (array_slice($changes, 0, TG_PRICE_LINES) as $c) {
        if ($c['from'] === null) {
            $lines[] = '🆕 ' . $c['name'] . ': ' . $c['to'];
            continue;
        }
        $a = tg_price_number($c['from']);
        $b = tg_price_number($c['to']);
        $mark = '✏️';
        if ($a !== null && $b !== null) { $mark = $b > $a ? '📈' : '📉'; }
        $lines[] = $mark . ' ' . $c['name'] . ': ' . $c['from'] . ' → ' . $c['to'];
    }
    $more = count($changes) - count($lines);
    if ($more > 0) { $lines[] = sprintf(tg_text('pricesMore', $lang), $more); }
    return '📊 ' . tg_text('prices', $lang) . "\n\n" . implode("\n", $lines);
}

// Заголовок и начало текста новости на языке человека. Английского нет —
// русский: пустое сообщение хуже непереведённого.
function tg_news_text(array $post, string $lang): string {
    $pick = function (string $ru, string $en) use ($lang): string {
        return ($lang === 'en' && trim($en) !== '') ? $en : $ru;
    };
    $title = tg_clean_line($pick((string)$post['title_ru'], (string)$post['title_en']), 200);
    $body  = tg_clean_line($pick((string)$post['body_ru'], (string)$post['body_en']), 2000);

    if (mb_strlen($body) > TG_NEWS_EXCERPT) {
        $cut   = mb_substr($body, 0, TG_NEWS_EXCERPT);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > TG_NEWS_EXCERPT * 0.6) { $cut = mb_substr($cut, 0, $space); }
        $body = (preg_replace('/[\s.,;:!?—–-]+$/u', '', $cut) ?? $cut) . '…';
    }
    return '📰 ' . $title . ($body !== '' ? "\n\n" . $body : '');
}

function tg_notify_prices(PDO $pdo, array $tg, callable $send, array $changes, ?callable $sleep = null): int {
    if (!$changes) { return 0; }
    return tg_broadcast($pdo, $tg, $send, 'prices', function (string $lang) use ($changes): array {
        return [tg_prices_text($changes, $lang), [
            [tg_text('openTierlist', $lang), TG_SITE . '/tierlist'],
            [tg_text('settings', $lang), TG_SITE . '/profile'],
        ]];
    }, $sleep);
}

// Новый пост → всем, кто не выключил новости. $now — секунды.
function tg_notify_news(PDO $pdo, array $tg, callable $send, int $newsId, int $now, ?callable $sleep = null): int {
    $st = $pdo->prepare('SELECT id, title_ru, title_en, body_ru, body_en, published_at FROM news WHERE id = :id');
    $st->execute([':id' => $newsId]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post) { return 0; }
    // published_at — миллисекунды.
    if ((int)$post['published_at'] < ($now - TG_NEWS_FRESH) * 1000) { return 0; }

    return tg_broadcast($pdo, $tg, $send, 'news', function (string $lang) use ($post): array {
        return [tg_news_text($post, $lang), [
            [tg_text('readNews', $lang), TG_SITE . '/news/' . (int)$post['id']],
            [tg_text('settings', $lang), TG_SITE . '/profile'],
        ]];
    }, $sleep);
}

// Рассылка идёт после ответа и тянется дольше обычного запроса: паузы между
// сообщениями плюс ожидание Telegram. Лимит времени поднимается только ей.
function tg_long_run(): void {
    if (function_exists('set_time_limit')) { @set_time_limit(600); }
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

// Админка сохранила тирлист. $old — состояние из базы до сохранения.
// $send и $sleep подставляют тесты; на бою — настоящий Telegram.
function tg_after_prices(PDO $pdo, array $cfg, array $old, array $new, ?callable $send = null, ?callable $sleep = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    $changes = tg_price_changes($old, $new);
    if (!$changes) { return; }
    tg_finish_response();
    tg_long_run();
    try {
        tg_notify_prices($pdo, $tg, $send ?? tg_http($tg['token']), $changes, $sleep);
    } catch (Throwable $e) {
        error_log('tg prices notify: ' . $e->getMessage());
    }
}

// Админка опубликовала новый пост (правка старого сюда не приходит).
function tg_after_news(PDO $pdo, array $cfg, int $newsId, int $now, ?callable $send = null, ?callable $sleep = null): void {
    $tg = tg_config($cfg);
    if (!tg_enabled($tg)) { return; }
    tg_finish_response();
    tg_long_run();
    try {
        tg_notify_news($pdo, $tg, $send ?? tg_http($tg['token']), $newsId, $now, $sleep);
    } catch (Throwable $e) {
        error_log('tg news notify: ' . $e->getMessage());
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
