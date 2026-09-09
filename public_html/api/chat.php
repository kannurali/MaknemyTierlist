<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';

// Данные страницы чата — /api/chat.php[?thread=N]
//
// Без параметра отдаёт список диалогов, с параметром — ещё и переписку
// выбранной ветки вместе с моим отзывом о собеседнике. Одним ответом, а не
// двумя запросами: экран открывается сразу с выбранным диалогом, и вторая
// поездка только добавила бы мигание.

function handle_chat(PDO $pdo, array $session, ?string $threadRaw, int $now): array {
    $me = chat_me($session);

    // Не вошёл — отдаём пустоту и признак. Страница по нему предлагает войти
    // через Roblox, а не делает вид, что переписки нет.
    if ($me === '') {
        return [200, [
            'ok' => true, 'ready' => chat_ready($pdo), 'authed' => false,
            'me' => '', 'threads' => [], 'thread' => 0, 'messages' => [], 'review' => null,
        ]];
    }

    $threads = chat_threads($pdo, $me, $now);
    foreach ($threads as &$t) {
        if ($t['last']) { $t['last']['mine'] = ($t['last']['sender'] === $me); }
    }
    unset($t);

    // Номер ветки приходит из адреса. Строгая проверка: только цифры, иначе
    // ветка не выбрана. В запрос непроверенное не уезжает — всё через
    // плейсхолдеры, — но принимать «12abc» за 12 незачем.
    $thread = 0;
    if ($threadRaw !== null && preg_match('/^\d{1,10}\z/', $threadRaw)) {
        $thread = (int)$threadRaw;
    }

    // Ветка по умолчанию — самая свежая. Открывать чат ни на чём, когда
    // переписки есть, значит показывать пустоту вместо содержимого.
    if ($thread === 0 && $threads) { $thread = $threads[0]['id']; }

    // Чужую ветку не отдаём: chat_messages сам проверяет участие, но номер
    // тоже обнуляем — иначе страница подсветила бы в списке диалог, которого
    // у меня нет.
    if ($thread !== 0 && !chat_is_member($pdo, $me, $thread)) { $thread = 0; }

    return [200, [
        'ok'       => true,
        'ready'    => chat_ready($pdo),
        'authed'   => true,
        'me'       => $me,
        'threads'  => $threads,
        'thread'   => $thread,
        'messages' => $thread ? chat_messages($pdo, $me, $thread) : [],
        'review'   => $thread ? chat_my_review($pdo, $me, $thread) : null,
    ]];
}

if (!defined('TESTING')) {
    // Переписка меняется постоянно — кешировать нечего. no-store, а не
    // no-cache: содержимое приватное, и оседать в кеше прокси ему незачем.
    header('Cache-Control: no-store');
    start_site_session();
    $raw = isset($_GET['thread']) && is_string($_GET['thread']) ? $_GET['thread'] : null;
    [$status, $payload] = handle_chat(db(), $_SESSION, $raw, time());
    json_out($payload, $status);
}
