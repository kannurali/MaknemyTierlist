<?php
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/giveaway.php';
require_once __DIR__ . '/cm/cm.php';

// Один апдейт Telegram — что бы его ни принёс: вебхук (api/tg_webhook.php)
// или опрос getUpdates по cron (api/tg_poll.php), когда до вебхука Telegram
// достучаться не может. Обработка у обоих одна и та же, отличается только
// доставка ответа:
//  - $respond(?array $reply, bool $purge) — ответ на апдейт. В вебхуке это
//    тело HTTP-ответа (Telegram выполняет его как вызов API), в опросе —
//    обычный вызов API (tg_poll_responder). $purge — у розыгрыша новый
//    участник, кеш выдачи розыгрышей пора сбросить;
//  - $finish() — отпустить Telegram и продолжить в фоне. В опросе ждать
//    некому, там это пустая функция.
//
// База и конфиг берутся лениво, как раньше в вебхуке: если MySQL лежит,
// ответ всё равно уходит, а ошибка пишется в лог. Иначе Telegram повторял бы
// доставку, и один сбой базы превращался бы в очередь повторов.
//
// Сообщения групп и кнопки «cm:» — чат-менеджер (api/lib/cm/,
// docs/chat-manager.md): ответ уходит сразу, вызовы Telegram — после, ошибка
// модуля пишется в лог и остальное не задевает.
function tg_update_process(array $tg, array $update, callable $respond, callable $finish): void {
    if (cm_wants_update($update, $tg['name'])) {
        $respond(null, false);
        $finish();
        try {
            cm_handle_update(db(), app_config(), tg_http($tg['token']), $update, time());
        } catch (Throwable $e) {
            error_log('cm: ' . $e->getMessage());
        }
        return;
    }

    $reply = null;
    $invite = null;
    $left = [];
    $joined = null;
    try {
        // Нажали «Участвовать» под постом розыгрыша в канале — участие прямо
        // в Telegram, ответ всплывающим окном (api/lib/gw_tg.php).
        $joined = gw_tg_callback(db(), app_config(), tg_http($tg['token']), $update, time());
        // Прислали боту премиум-эмодзи — он отвечает их id для админки
        // розыгрышей (раздел «Премиум-эмодзи» в api/lib/giveaway.php).
        // «Забрать приз» и ник в Roblox от победителя — gw_tg_bot_reply().
        // «тирлист», «/stock» в личке — кнопка в мини-приложение (tg_pages.php).
        $reply = $joined['reply'] ?? gw_emoji_reply($update) ?? gw_tg_bot_reply(db(), $update, time())
            ?? tg_handle_update(db(), $update, time(), $tg['name']);
        // Бота добавили в канал или убрали — список каналов для условий
        // розыгрышей (api/lib/giveaway.php). Ссылку-приглашение приватного
        // канала просим уже после ответа Telegram. Добавившему бот сразу
        // пишет, каких прав ему не дали.
        $invite = gw_channel_update(db(), $update, time());
        $reply = $reply ?? gw_channel_reply($update, $tg['name']);
        // Кто-то вступил в канал по ссылке розыгрыша или вышел из канала —
        // учёт подписчиков и снятие участия у отписавшихся.
        $left = gw_member_update(db(), $update, time());
    } catch (Throwable $e) {
        error_log('tg webhook: ' . $e->getMessage());
    }
    // Новый участник меняет число на сайте — кеш выдачи розыгрышей сбрасывается.
    $respond($reply, !empty($joined['after']));
    if ($joined) { gw_tg_after(db(), app_config(), $joined['after'], time()); }
    if ($invite !== null) { gw_after_channel(db(), app_config(), $invite, time()); }
    if ($left) { gw_after_member(db(), app_config(), $left, time()); }
    // Бота добавили в группу или убрали, человек вошёл или вышел — тот же
    // апдейт нужен и чат-менеджеру: знакомство с группой, приветствие.
    if (isset($update['my_chat_member']) || isset($update['chat_member'])) {
        $finish();
        try {
            cm_on_membership(db(), app_config(), tg_http($tg['token']), $update, time());
        } catch (Throwable $e) {
            error_log('cm member: ' . $e->getMessage());
        }
    }
}

// --------------------------------------------------------------------------
//  Опрос getUpdates — запасной путь, когда вебхук недоступен
// --------------------------------------------------------------------------
//
// 12–13.11.2026 фаервол хостинга перестал пускать серверы Telegram к сайту:
// getWebhookInfo — «Connection timed out», в очереди тысячи апдейтов, при
// этом сайт открывается отовсюду, а исходящие запросы к Telegram работают.
// Тогда бот сам забирает апдейты: cron раз в минуту запускает
// api/tg_poll.php, тот ~55 секунд держит длинный опрос и каждый апдейт
// отдаёт в tg_update_process(). Включение и возврат — docs/telegram-notify.md.

// Типы апдейтов — те же, что просит вебхук (tg_setup_webhook).
const TG_POLL_UPDATES = ['message', 'edited_message', 'my_chat_member', 'chat_member', 'callback_query'];
// Сколько секунд Telegram держит один запрос, если апдейтов нет.
const TG_POLL_WAIT = 25;
// Сколько секунд длится один запуск: cron запускает следующий через минуту.
const TG_POLL_BUDGET = 55;
// Апдейтов за один запрос.
const TG_POLL_LIMIT = 100;

// Транспорт для опроса: как tg_http(), но таймаут curl длиннее ожидания
// Telegram — иначе длинный опрос обрывался бы через TG_HTTP_TIMEOUT секунд.
function tg_poll_http(string $token): callable {
    return function (string $method, array $params, int $wait) use ($token): ?array {
        if (!function_exists('curl_init')) { return null; }
        $ch = curl_init(TG_API . $token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => $wait + 10,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if (!is_string($raw)) { return null; }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : null;
    };
}

// Ответ на апдейт в опросе. Вебхук отдал бы его телом HTTP-ответа, а здесь
// это обычный вызов: поле method — имя метода, остальное — параметры.
// Ответ без method (просто {"ok": true}) ничего не делает.
function tg_poll_responder(callable $send): callable {
    return function (?array $reply, bool $purge) use ($send): void {
        if ($reply === null || !is_string($reply['method'] ?? null) || $reply['method'] === '') { return; }
        $method = $reply['method'];
        unset($reply['method']);
        $send($method, $reply);
    };
}

/**
 * Один запуск опроса. Возвращает итог для `tg_poll.php -v`.
 *
 *  $api($method, $params, $wait) — вызов Telegram (tg_poll_http);
 *  $process($update)             — обработка одного апдейта;
 *  $load() / $save($offset)      — где лежит номер следующего апдейта;
 *  $clock()                      — текущее время в секундах;
 *  $ready()                      — жива ли база.
 *
 * База проверяется перед каждым апдейтом. MySQL у хостера иногда пропадает
 * на секунды, а соединение процесса после этого мёртвое до конца запуска.
 * Тогда запуск останавливается, не тронув номер: апдейт остаётся у Telegram,
 * и следующий запуск (с новым соединением) возьмёт его снова. Вебхук в такой
 * сбой апдейты терял — у опроса это бесплатно.
 *
 * Номер следующего апдейта сохраняется ДО обработки: если апдейт уронит
 * процесс, следующий запуск пойдёт дальше, а не будет падать на нём каждую
 * минуту, пока бот молчит. Повторно апдейт не обработается — как и в
 * вебхуке, который отвечает Telegram до обработки.
 *
 * 409 — у бота стоит вебхук, и Telegram не отдаёт апдейты опросом. Вебхук
 * снимается без сброса очереди (drop_pending_updates: false): всё, что
 * накопилось, пока Telegram не мог достучаться, обработается здесь. Номер из
 * файла при этом забывается: он остался от прошлого опроса, а после недели
 * тишины Telegram нумерует апдейты заново, со случайного числа. Старый номер
 * больше нового молча «подтвердил» бы всю очередь, и она пропала бы.
 */
function tg_poll_run(callable $api, callable $process, callable $load, callable $save, callable $clock, callable $ready, int $budget = TG_POLL_BUDGET): array {
    $start = $clock();
    $offset = max(0, (int)$load());
    $done = 0;
    $takeover = false;
    while (true) {
        $wait = min(TG_POLL_WAIT, $start + $budget - $clock() - 5);
        if ($wait < 1) { break; }
        $res = $api('getUpdates', [
            'offset'          => $offset,
            'timeout'         => $wait,
            'limit'           => TG_POLL_LIMIT,
            'allowed_updates' => TG_POLL_UPDATES,
        ], $wait);
        if (!is_array($res)) {
            return ['ok' => false, 'error' => 'no answer', 'updates' => $done, 'offset' => $offset, 'takeover' => $takeover];
        }
        if (empty($res['ok'])) {
            if ((int)($res['error_code'] ?? 0) === 409 && !$takeover) {
                $takeover = true;
                $api('deleteWebhook', ['drop_pending_updates' => false], 0);
                $offset = 0;
                $save(0);
                continue;
            }
            return ['ok' => false, 'error' => (string)($res['description'] ?? 'error'), 'updates' => $done, 'offset' => $offset, 'takeover' => $takeover];
        }
        foreach ((array)($res['result'] ?? []) as $u) {
            if (!is_array($u)) { continue; }
            $id = (int)($u['update_id'] ?? 0);
            if ($id < $offset) { continue; }
            if (!$ready()) {
                return ['ok' => false, 'error' => 'db down', 'updates' => $done, 'offset' => $offset, 'takeover' => $takeover];
            }
            $offset = $id + 1;
            $save($offset);
            $process($u);
            $done++;
        }
    }
    return ['ok' => true, 'updates' => $done, 'offset' => $offset, 'takeover' => $takeover];
}

// Где хранится номер следующего апдейта: рядом с config.php, вне веб-корня.
// Файл переживает перезапуски; нет файла — опрос берёт всю очередь Telegram.
function tg_poll_offset_path(): string {
    return dirname(CONFIG_PATH) . '/tg-poll.offset';
}

// Номер старше шести дней не верим: после недели без апдейтов Telegram
// начинает нумерацию со случайного числа (см. tg_poll_run).
const TG_POLL_OFFSET_TTL = 6 * 86400;

function tg_poll_offset_load(string $path, int $now): int {
    $at = @filemtime($path);
    if ($at === false || $now - $at > TG_POLL_OFFSET_TTL) { return 0; }
    $raw = @file_get_contents($path);
    return is_string($raw) && ctype_digit(trim($raw)) ? (int)trim($raw) : 0;
}

function tg_poll_offset_save(string $path, int $offset): void {
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, (string)$offset) !== false) { @rename($tmp, $path); }
}
