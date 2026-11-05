<?php
// Посты бота в каналы и чаты. Что куда идёт, выбирается галочками на вкладке
// «Каналы» (/admin/channels): строка tg_routes — «в этот чат идёт эта тема».
// Нет строки — чат молчит: среди каналов бота есть и каналы рекламодателей.
//
// Пост — тот же текст, что и личное уведомление (так просил владелец), на
// русском и с одной кнопкой на сайт. Одна кнопка не случайно: только с ней
// Telegram показывает кнопку в полосе закреплённого сообщения.
//
// Откуда зовётся: цены и новости — tg_after_prices() и tg_after_news(), сток —
// stock_pull_and_notify(), ивент — hunt_post_tick() из cron. Розыгрыши идут
// своим путём (gw_post_sync): у них свой пост и итоги в нём, а тема giveaways
// только подставляет галочки в новый розыгрыш (gw_post_last).
//
// Ничего из этого не имеет права уронить сохранение: таблицы нет — постов нет,
// Telegram отказал — причина видна на вкладке, а личная рассылка идёт дальше.
//
// Бота не спрашиваем заранее, есть ли у него права: tg_channels может отставать
// от жизни, а Telegram отвечает точно. Отказ записывается в строку маршрута.

const TG_ROUTE_TOPICS = [
    'prices'    => 'Тирлист',
    'stock'     => 'Сток',
    'news'      => 'Новости',
    'giveaways' => 'Розыгрыши',
    'events'    => 'Ивенты',
];

// Сохранения подряд не плодят посты: столько секунд после поста о ценах бот
// правит его, а не шлёт новый.
const TG_ROUTE_MERGE = 1800;

function tg_routes_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM tg_routes LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Строки одной темы. Таблицы нет — пусто: до миграции бот в каналы не пишет.
function tg_routes_for(PDO $pdo, string $topic): array {
    try {
        $st = $pdo->prepare('SELECT * FROM tg_routes WHERE topic = :t ORDER BY chat_id');
        $st->execute([':t' => $topic]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Вся таблица для вкладки: [chat_id => [тема => строка]].
function tg_routes_all(PDO $pdo): array {
    $out = [];
    try {
        foreach ($pdo->query('SELECT * FROM tg_routes')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['chat_id']][(string)$r['topic']] = $r;
        }
    } catch (PDOException $e) {
        return [];
    }
    return $out;
}

/**
 * Сохранить галочки вкладки. $raw — $_POST['r']: [chat_id => [тема => 1]].
 * Форма присылает только отмеченное, поэтому снятая галочка — это отсутствие
 * ключа. Чаты берутся из $chats (то, что знает бот), а не из запроса. Строка
 * оставшейся галочки не пересоздаётся: в ней склейка постов и журнал.
 * → код итога для ?done=.
 */
function tg_routes_save(PDO $pdo, array $chats, $raw): string {
    if (!tg_routes_ready($pdo)) { return 'not_ready'; }
    $raw  = is_array($raw) ? $raw : [];
    $have = tg_routes_all($pdo);
    $add  = $pdo->prepare('INSERT INTO tg_routes (chat_id, topic) VALUES (:c, :t)');
    $del  = $pdo->prepare('DELETE FROM tg_routes WHERE chat_id = :c AND topic = :t');
    $pdo->beginTransaction();
    try {
        foreach ($chats as $chat) {
            $chat = (int)$chat;
            $on   = isset($raw[$chat]) && is_array($raw[$chat]) ? $raw[$chat] : [];
            foreach (array_keys(TG_ROUTE_TOPICS) as $topic) {
                $was = isset($have[$chat][$topic]);
                if (isset($on[$topic]) && !$was) { $add->execute([':c' => $chat, ':t' => $topic]); }
                if (!isset($on[$topic]) && $was) { $del->execute([':c' => $chat, ':t' => $topic]); }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return 'saved';
}

// Поля sendMessage без chat_id: текст и кнопка на сайт.
function tg_route_params(string $text, string $button, string $path): array {
    $msg = tg_message_buttons(0, $text, [[$button, TG_SITE . $path]]);
    unset($msg['chat_id']);
    return $msg;
}

// Вызов Telegram с одним повтором на 429 — как в рассылке.
function tg_route_call(callable $send, string $method, array $params, callable $sleep): ?array {
    $res = $send($method, $params);
    if (is_array($res) && (int)($res['error_code'] ?? 0) === 429) {
        $wait = (int)($res['parameters']['retry_after'] ?? 1);
        $sleep(max(1, min(30, $wait)) * 1000000);
        $res = $send($method, $params);
    }
    return $res;
}

/**
 * Записать ответ Telegram в строку маршрута → id вышедшего сообщения или 0.
 * Пустая причина при failed_at — Telegram не ответил. Запись не обязана
 * удаться: пост важнее журнала.
 */
function tg_route_mark(PDO $pdo, int $chat, string $topic, int $now, ?array $res): int {
    $ok  = is_array($res) && !empty($res['ok']);
    $mid = $ok ? (int)($res['result']['message_id'] ?? 0) : 0;
    try {
        if ($ok) {
            $pdo->prepare("UPDATE tg_routes SET posted_at = :n, msg_id = :m, failed_at = 0, error = ''
                            WHERE chat_id = :c AND topic = :t")
                ->execute([':n' => $now, ':m' => $mid, ':c' => $chat, ':t' => $topic]);
        } else {
            $why = is_array($res) ? tg_clean_line((string)($res['description'] ?? ''), 190) : '';
            $pdo->prepare('UPDATE tg_routes SET failed_at = :n, error = :e WHERE chat_id = :c AND topic = :t')
                ->execute([':n' => $now, ':e' => $why, ':c' => $chat, ':t' => $topic]);
        }
    } catch (PDOException $e) {
        error_log('tg route mark: ' . $e->getMessage());
    }
    return $mid;
}

/**
 * Опубликовать пост во всех чатах темы → сколько вышло.
 *
 * $ref — у постов, которые cron предлагает снова и снова (ивент): чат, где этот
 * пост уже вышел или Telegram его окончательно отверг, пропускается. Молчание
 * Telegram окончательным не считается: следующий проход повторит.
 */
function tg_route_post(PDO $pdo, callable $send, string $topic, array $params, int $now, ?callable $sleep = null, ?string $ref = null): int {
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $sent = 0;
    $n = 0;
    foreach (tg_routes_for($pdo, $topic) as $r) {
        if ($ref !== null && (string)($r['last_ref'] ?? '') === $ref) { continue; }
        $chat = (int)$r['chat_id'];
        if ($n++ > 0) { $sleep(TG_BROADCAST_GAP); }
        $res = tg_route_call($send, 'sendMessage', ['chat_id' => $chat] + $params, $sleep);
        if (tg_route_mark($pdo, $chat, $topic, $now, $res) > 0) { $sent++; }
        if ($ref !== null && $res !== null) {
            $pdo->prepare('UPDATE tg_routes SET last_ref = :r WHERE chat_id = :c AND topic = :t')
                ->execute([':r' => $ref, ':c' => $chat, ':t' => $topic]);
        }
    }
    return $sent;
}

// --------------------------------------------------------------------------
//  Цены: сохранения подряд правят один пост
// --------------------------------------------------------------------------

// Сменилась ли цена. null — раньше цены не было. «25000» → «25 000» — та же.
function tg_route_moved(?string $from, string $to): bool {
    if ($from === null) { return true; }
    if ($from === $to) { return false; }
    $a = tg_price_number($from);
    $b = tg_price_number($to);
    return !($a !== null && $b !== null && $a == $b);
}

// Склеить изменения прошлого поста с новыми: «было» — из прошлого, «стало» —
// из нового. Вернули как было — изменение выпадает.
function tg_route_merge(array $prev, array $cur): array {
    $all = [];
    foreach (array_merge($prev, $cur) as $c) {
        if (isset($all[$c['id']])) { $c['from'] = $all[$c['id']]['from']; }
        $all[$c['id']] = $c;
    }
    $out = [];
    foreach ($all as $c) {
        if (tg_route_moved($c['from'], $c['to'])) { $out[] = $c; }
    }
    return $out;
}

// Изменения прошлого поста из msg_log. Пишем их сами, но кривая строка в базе
// всё равно не должна уронить пост.
function tg_route_log($raw): array {
    $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($d)) { return []; }
    $out = [];
    foreach ($d as $c) {
        if (!is_array($c) || !is_string($c['id'] ?? null) || !is_string($c['name'] ?? null) || !is_string($c['to'] ?? null)) { continue; }
        $from = $c['from'] ?? null;
        if ($from !== null && !is_string($from)) { continue; }
        $out[] = ['id' => $c['id'], 'name' => $c['name'], 'from' => $from, 'to' => $c['to']];
    }
    return $out;
}

function tg_route_store_log(PDO $pdo, int $chat, array $changes): void {
    $pdo->prepare("UPDATE tg_routes SET msg_log = :l WHERE chat_id = :c AND topic = 'prices'")
        ->execute([':l' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':c' => $chat]);
}

function tg_route_prices_params(array $changes): array {
    return tg_route_params(tg_prices_text($changes, 'ru'), tg_text('openTierlist', 'ru'), '/tierlist');
}

// Один чат: поправить недавний пост или выпустить новый. true — пост на месте.
function tg_route_prices_one(PDO $pdo, callable $send, array $r, array $changes, int $now, callable $sleep): bool {
    $chat = (int)$r['chat_id'];
    $msg  = (int)$r['msg_id'];
    if ($msg > 0 && $now - (int)$r['posted_at'] < TG_ROUTE_MERGE) {
        $changes = tg_route_merge(tg_route_log($r['msg_log'] ?? ''), $changes);
        if (!$changes) {
            tg_route_call($send, 'deleteMessage', ['chat_id' => $chat, 'message_id' => $msg], $sleep);
            $pdo->prepare("UPDATE tg_routes SET msg_id = 0, posted_at = 0, msg_log = NULL WHERE chat_id = :c AND topic = 'prices'")
                ->execute([':c' => $chat]);
            return true;
        }
        $res = tg_route_call($send, 'editMessageText', ['chat_id' => $chat, 'message_id' => $msg] + tg_route_prices_params($changes), $sleep);
        $same = is_array($res) && stripos((string)($res['description'] ?? ''), 'not modified') !== false;
        // Telegram не ответил — правка могла пройти, и второй пост был бы
        // дублем. Склейку запоминаем: следующая правка перепишет пост целиком.
        if ($res === null || $same || !empty($res['ok'])) {
            tg_route_store_log($pdo, $chat, $changes);
            return $res !== null;
        }
        // Пост удалили руками — новый, со всем, что накопилось.
    }
    $res = tg_route_call($send, 'sendMessage', ['chat_id' => $chat] + tg_route_prices_params($changes), $sleep);
    if (tg_route_mark($pdo, $chat, 'prices', $now, $res) <= 0) { return false; }
    tg_route_store_log($pdo, $chat, $changes);
    return true;
}

/**
 * Изменения цен — в чаты темы prices. $changes — из tg_price_moves().
 * → в скольких чатах пост на месте.
 */
function tg_route_prices(PDO $pdo, callable $send, array $changes, int $now, ?callable $sleep = null): int {
    if (!$changes) { return 0; }
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $done = 0;
    $n = 0;
    foreach (tg_routes_for($pdo, 'prices') as $r) {
        if ($n++ > 0) { $sleep(TG_BROADCAST_GAP); }
        if (tg_route_prices_one($pdo, $send, $r, $changes, $now, $sleep)) { $done++; }
    }
    return $done;
}

// Новый пост новостей — в чаты темы news. Те же правила свежести, что у личной
// рассылки: перенос старой записи задним числом новостью не считается.
function tg_route_news(PDO $pdo, callable $send, int $newsId, int $now, ?callable $sleep = null): int {
    $post = tg_news_fresh($pdo, $newsId, $now);
    if (!$post) { return 0; }
    $params = tg_route_params(tg_news_text($post, 'ru'), tg_text('readNews', 'ru'), '/news/' . (int)$post['id']);
    return tg_route_post($pdo, $send, 'news', $params, $now, $sleep);
}

// --------------------------------------------------------------------------
//  Для вкладки
// --------------------------------------------------------------------------

// Что мешает постам в этом чате, по тому, что о нём знает бот. Другие
// недостающие права нужны только условиям розыгрышей, постам они не мешают.
function tg_route_warning(array $channel): string {
    if ((int)$channel['is_admin'] === 1) { return ''; }
    $missing = gw_split_rights($channel['missing'] ?? '');
    if (in_array('can_post_messages', $missing, true)) {
        return 'У бота нет права «Отправка публикаций» — посты сюда не выйдут.';
    }
    return $missing ? '' : 'Бот здесь не админ — посты могут не выйти.';
}
