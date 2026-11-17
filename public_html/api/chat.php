<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/telegram.php';

// Данные страницы чата — /api/chat.php[?thread=N]
//
// Без параметра отдаёт список диалогов, с параметром — ещё и переписку
// выбранной ветки вместе с моим отзывом о собеседнике. Одним ответом, а не
// двумя запросами: экран открывается сразу с выбранным диалогом, и вторая
// поездка только добавила бы мигание.

// $tg — настройки бота (tg_config). По ним в ответ уходит состояние
// колокольчика; без них колокольчик выключен.
//
// $pick = false — не выбирать самую свежую ветку, когда номер не передан.
// Так страница просит список после неудачного «Написать»: иначе человек,
// собравшийся писать новому собеседнику, молча попадал в последний старый
// диалог и думал, что сайт его туда «кидает».
function handle_chat(PDO $pdo, array $session, ?string $threadRaw, int $now, array $tg = [], bool $pick = true): array {
    $me = chat_me($session);

    // Не вошёл — отдаём пустоту и признак. Страница по нему предлагает войти
    // через Roblox, а не делает вид, что переписки нет.
    if ($me === '') {
        return [200, [
            'ok' => true, 'ready' => chat_ready($pdo), 'authed' => false,
            'me' => '', 'threads' => [], 'thread' => 0, 'messages' => [], 'review' => null,
            'unread' => 0,
            'photos' => false,
            'tg' => tg_status($pdo, $tg, ''),
        ]];
    }

    // Номер ветки приходит из адреса. Строгая проверка: только цифры, иначе
    // ветка не выбрана. В запрос непроверенное не уезжает — всё через
    // плейсхолдеры, — но принимать «12abc» за 12 незачем.
    $thread = 0;
    if ($threadRaw !== null && preg_match('/^\d{1,10}\z/', $threadRaw)) {
        $thread = (int)$threadRaw;
    }

    // Запрошенную ветку список держит, даже если человек удалил её у себя:
    // он открыл её сам (например, «Написать» в профиле), и писать в неё можно.
    $threads = chat_threads($pdo, $me, $now, $thread);
    foreach ($threads as &$t) {
        if ($t['last']) { $t['last']['mine'] = ($t['last']['sender'] === $me); }
    }
    unset($t);

    // Ветка по умолчанию — самая свежая. Открывать чат ни на чём, когда
    // переписки есть, значит показывать пустоту вместо содержимого.
    if ($thread === 0 && $threads && $pick) { $thread = $threads[0]['id']; }

    // Чужую ветку не отдаём: chat_messages сам проверяет участие, но номер
    // тоже обнуляем — иначе страница подсветила бы в списке диалог, которого
    // у меня нет.
    if ($thread !== 0 && !chat_is_member($pdo, $me, $thread)) { $thread = 0; }

    $messages = $thread ? chat_messages($pdo, $me, $thread, $now) : [];

    // Ветку, которую отдаём, человек сейчас видит: отметка «прочитано» и «в
    // диалоге». По ней бот в Telegram молчит, пока человек здесь. Фоновая
    // перечитка идёт только из открытой вкладки, так что отметка не врёт.
    if ($thread) {
        $last = $messages ? $messages[count($messages) - 1]['id'] : 0;
        chat_mark_read($pdo, $thread, $me, $last, $now);
    }

    // Непрочитанное считается ПОСЛЕ отметки: открытая ветка уже прочитана, и
    // значок в шапке не должен показывать её ещё десять секунд до перечитки.
    $unread = chat_unread($pdo, $me);
    foreach ($threads as &$t) { $t['unread'] = $unread[$t['id']] ?? 0; }
    unset($t);

    return [200, [
        'ok'       => true,
        'ready'    => chat_ready($pdo),
        'authed'   => true,
        'me'       => $me,
        'threads'  => $threads,
        'thread'   => $thread,
        'messages' => $messages,
        'review'   => $thread ? chat_my_review($pdo, $me, $thread) : null,
        'unread'   => count($unread),
        'photos'   => chat_photo_ready($pdo),
        'tg'       => tg_status($pdo, $tg, $me),
    ]];
}

if (!defined('TESTING')) {
    // Переписка меняется постоянно — кешировать нечего. no-store, а не
    // no-cache: содержимое приватное, и оседать в кеше прокси ему незачем.
    header('Cache-Control: no-store');
    start_site_session();
    $raw  = isset($_GET['thread']) && is_string($_GET['thread']) ? $_GET['thread'] : null;
    $pick = !(isset($_GET['pick']) && $_GET['pick'] === '0');
    [$status, $payload] = handle_chat(db(), $_SESSION, $raw, time(), tg_config(app_config()), $pick);
    // Модератору и админу в шапке переписки стоит кнопка бана собеседника
    // (api/ban.php сам проверит, кого из них можно банить).
    $payload['mod'] = !empty($payload['authed']) && is_moderator();
    json_out($payload, $status);
}
