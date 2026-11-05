<?php
// Посты бота в каналы и чаты. Что куда идёт, выбирается галочками на вкладке
// «Каналы» (/admin/channels): строка tg_routes — «в этот чат идёт эта тема».
// Нет строки — чат молчит: среди каналов бота есть и каналы рекламодателей.
//
// В канале информация полная (так просил владелец), в отличие от личного
// уведомления: новость целиком, с оформлением и всеми картинками
// (tg_news_post.php), тирлист — все изменения цен и спроса по разделам, ивент —
// правила, призы и все победители. Что не влезает в одно сообщение (4096
// знаков), уходит несколькими: кнопка на сайт — под последним. Пост только на
// русском.
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

// Предел сообщения — 4096 знаков после разбора разметки. Запас — на эмодзи,
// которые Telegram считает за два.
const TG_ROUTE_TEXT_MAX = 3800;

// Пауза между сообщениями одного поста, микросекунды: в один чат Telegram
// пропускает примерно сообщение в секунду.
const TG_ROUTE_PART_GAP = 1000000;

// Спрос — значения поля demand и как их называет пост. Слова — из постов
// владельца («Хороший», «Средний»), а не из легенды тирлиста.
const TG_ROUTE_DEMAND = [
    'neon'   => 'Overpriced',
    'green'  => 'Хороший',
    'yellow' => 'Средний',
    'orange' => 'Ниже среднего',
    'red'    => 'Низкий',
];

// Разделы тирлиста в посте и их порядок (tg_route_group).
const TG_ROUTE_GROUPS = [
    'perms'         => '💵 Permanent Fruits',
    'fruits'        => '🍎 Фрукты',
    'configurators' => '🎨 Конфигураторы',
    'passes'        => '🎟 Пассы и ваучеры',
];

const TG_ROUTE_BUCKETS = [
    'down' => '🔽 Упали:',
    'up'   => '🔼 Выросли:',
    'fix'  => '🔄 Исправили цену:',
    'new'  => '🆕 Добавили:',
];

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

function tg_route_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Поля sendMessage без chat_id: простой текст и кнопка на сайт.
function tg_route_params(string $text, string $button, string $path): array {
    $msg = tg_message_buttons(0, $text, [[$button, TG_SITE . $path]]);
    unset($msg['chat_id']);
    return $msg;
}

// --------------------------------------------------------------------------
//  Длинный пост: несколько сообщений
// --------------------------------------------------------------------------

// Длина так, как её считает Telegram: без тегов, сущности — один знак.
function tg_route_len(string $html): int {
    return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

// Простой текст на куски не длиннее $max: по переводу строки или пробелу, а если
// их нет на второй половине — где придётся.
function tg_route_cut_plain(string $text, int $max): array {
    $out = [];
    $text = trim($text);
    while (mb_strlen($text) > $max) {
        $head = mb_substr($text, 0, $max);
        $at = max((int)mb_strrpos($head, "\n"), (int)mb_strrpos($head, ' '));
        if ($at < $max / 2) { $at = $max; }
        $out[] = rtrim(mb_substr($text, 0, $at));
        $text = ltrim(mb_substr($text, $at));
    }
    if ($text !== '') { $out[] = $text; }
    return $out;
}

// В каждой ли строке теги закрыты: только такой кусок можно резать по строкам.
function tg_route_lines_closed(array $lines): bool {
    foreach ($lines as $line) {
        if (preg_match_all('~<[a-z]~i', $line) !== preg_match_all('~</~', $line)) { return false; }
    }
    return true;
}

/**
 * Кусок HTML, который не влезает в одно сообщение. Список режется по строкам и
 * сохраняет оформление. Иначе (абзац без переводов строк, цитата, код) разметка
 * у этого куска теряется: незакрытый тег Telegram не примет вообще.
 */
function tg_route_pieces(string $html, int $max): array {
    if (tg_route_len($html) <= $max) { return [$html]; }
    $lines = explode("\n", $html);
    if (count($lines) === 1 || !tg_route_lines_closed($lines)) {
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return array_map('tg_route_h', tg_route_cut_plain($plain, $max));
    }
    $out = [];
    $cur = null;
    foreach ($lines as $line) {
        foreach (tg_route_pieces($line, $max) as $piece) {
            $next = $cur === null ? $piece : $cur . "\n" . $piece;
            if ($cur !== null && tg_route_len($next) > $max) {
                $out[] = $cur;
                $cur = $piece;
            } else {
                $cur = $next;
            }
        }
    }
    if ($cur !== null && trim($cur) !== '') { $out[] = $cur; }
    return $out;
}

// Куски HTML по порядку → тексты сообщений. Абзац рвётся, только если сам
// не влезает в сообщение.
function tg_route_split(array $blocks, int $max = TG_ROUTE_TEXT_MAX): array {
    $out = [];
    $cur = '';
    foreach ($blocks as $block) {
        foreach (tg_route_pieces($block, $max) as $piece) {
            $next = $cur === '' ? $piece : $cur . "\n\n" . $piece;
            if ($cur !== '' && tg_route_len($next) > $max) {
                $out[] = $cur;
                $cur = $piece;
            } else {
                $cur = $next;
            }
        }
    }
    if ($cur !== '') { $out[] = $cur; }
    return $out;
}

// Куски HTML → вызовы sendMessage; кнопка на сайт под последним сообщением.
// Превью ссылок выключено: в полном тексте их бывает несколько, и Telegram
// подставил бы над постом случайную.
function tg_route_text_calls(array $blocks, string $button, string $path): array {
    $texts = tg_route_split($blocks);
    $calls = [];
    foreach ($texts as $i => $html) {
        $msg = ['text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
        if ($i === count($texts) - 1) {
            $msg['reply_markup'] = ['inline_keyboard' => [[['text' => $button, 'url' => TG_SITE . $path]]]];
        }
        $calls[] = ['sendMessage', $msg];
    }
    return $calls;
}

// --------------------------------------------------------------------------
//  Отправка
// --------------------------------------------------------------------------

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

// id вышедшего сообщения или 0. sendMediaGroup отвечает списком сообщений.
function tg_route_msg_id(?array $res): int {
    if (!is_array($res) || empty($res['ok']) || !is_array($res['result'] ?? null)) { return 0; }
    $r = $res['result'];
    if (isset($r[0]) && is_array($r[0])) { $r = $r[0]; }
    return (int)($r['message_id'] ?? 0);
}

// Что ответил Telegram; пусто — не ответил.
function tg_route_why(?array $res): string {
    return is_array($res) ? tg_clean_line((string)($res['description'] ?? ''), 190) : '';
}

// Запись в строку маршрута не обязана удаться: пост важнее журнала.
function tg_route_mark_ok(PDO $pdo, int $chat, string $topic, int $now, int $msg): void {
    try {
        $pdo->prepare("UPDATE tg_routes SET posted_at = :n, msg_id = :m, failed_at = 0, error = ''
                        WHERE chat_id = :c AND topic = :t")
            ->execute([':n' => $now, ':m' => $msg, ':c' => $chat, ':t' => $topic]);
    } catch (PDOException $e) {
        error_log('tg route mark: ' . $e->getMessage());
    }
}

// Пустая причина при failed_at — Telegram не ответил.
function tg_route_mark_fail(PDO $pdo, int $chat, string $topic, int $now, string $why): void {
    try {
        $pdo->prepare('UPDATE tg_routes SET failed_at = :n, error = :e WHERE chat_id = :c AND topic = :t')
            ->execute([':n' => $now, ':e' => $why, ':c' => $chat, ':t' => $topic]);
    } catch (PDOException $e) {
        error_log('tg route mark: ' . $e->getMessage());
    }
}

/**
 * Выпустить пост в один чат. $calls — [[метод, поля, чем заменить?], …].
 *
 * Не вышел текст — дальше не идём: остаток без начала не нужен. Не вышла
 * картинка — текст важнее: идём дальше, а если текст был её подписью, вызов
 * держит третьим элементом замену. Причина всё равно видна на вкладке.
 *
 * → ['ids' => вышедшие сообщения, 'answered' => ответил ли Telegram на первый вызов].
 */
function tg_route_deliver(PDO $pdo, callable $send, int $chat, string $topic, array $calls, int $now, callable $sleep): array {
    $ids = [];
    $answered = false;
    $textFail = null;
    $mediaFail = null;
    for ($i = 0; $i < count($calls); $i++) {
        if ($i > 0) { $sleep(TG_ROUTE_PART_GAP); }
        $method = $calls[$i][0];
        $res = tg_route_call($send, $method, ['chat_id' => $chat] + $calls[$i][1], $sleep);
        if ($i === 0) { $answered = $res !== null; }
        $id = tg_route_msg_id($res);
        if ($id > 0) {
            $ids[] = $id;
            continue;
        }
        if ($method === 'sendMessage') {
            $textFail = tg_route_why($res);
            break;
        }
        $why = tg_route_why($res);
        $mediaFail = 'Картинка не вышла: ' . ($why !== '' ? $why : 'Telegram не ответил');
        if (isset($calls[$i][2])) { array_splice($calls, $i + 1, 0, $calls[$i][2]); }
    }
    if ($ids) { tg_route_mark_ok($pdo, $chat, $topic, $now, $ids[0]); }
    if ($textFail !== null) {
        tg_route_mark_fail($pdo, $chat, $topic, $now, $textFail);
    } elseif ($mediaFail !== null) {
        tg_route_mark_fail($pdo, $chat, $topic, $now, $mediaFail);
    }
    return ['ids' => $ids, 'answered' => $answered];
}

/**
 * Опубликовать пост во всех чатах темы → в скольких вышел.
 *
 * $ref — у постов, которые cron предлагает снова и снова (ивент): чат, где этот
 * пост уже вышел или Telegram его окончательно отверг, пропускается. Молчание
 * Telegram окончательным не считается: следующий проход повторит.
 */
function tg_route_post(PDO $pdo, callable $send, string $topic, array $calls, int $now, ?callable $sleep = null, ?string $ref = null): int {
    $sleep = $sleep ?? function (int $us): void { usleep($us); };
    $sent = 0;
    $n = 0;
    foreach (tg_routes_for($pdo, $topic) as $r) {
        if ($ref !== null && (string)($r['last_ref'] ?? '') === $ref) { continue; }
        $chat = (int)$r['chat_id'];
        if ($n++ > 0) { $sleep(TG_BROADCAST_GAP); }
        $done = tg_route_deliver($pdo, $send, $chat, $topic, $calls, $now, $sleep);
        if ($done['ids']) { $sent++; }
        if ($ref !== null && $done['answered']) {
            $pdo->prepare('UPDATE tg_routes SET last_ref = :r WHERE chat_id = :c AND topic = :t')
                ->execute([':r' => $ref, ':c' => $chat, ':t' => $topic]);
        }
    }
    return $sent;
}

// --------------------------------------------------------------------------
//  Цены: все изменения, сохранения подряд правят один пост
// --------------------------------------------------------------------------

// Сменилась ли цена. null — раньше цены не было. «25000» → «25 000» — та же.
function tg_route_moved(?string $from, string $to): bool {
    if ($from === null) { return true; }
    if ($from === $to) { return false; }
    $a = tg_price_number($from);
    $b = tg_price_number($to);
    return !($a !== null && $b !== null && $a == $b);
}

// Раздел тирлиста по типу предмета — как groupOf() в js/app.js.
function tg_route_group(string $type): string {
    if ($type === 'p') { return 'perms'; }
    if (in_array($type, ['gp', 'vh', 'v'], true)) { return 'passes'; }
    if (in_array($type, ['s', 'm', 'cs', 'cm', 'ms', 'cr'], true)) { return 'configurators'; }
    return 'fruits';
}

/**
 * Что поменялось в тирлисте: цены по тем же правилам, что у личной рассылки
 * (tg_price_moves), за ними смена спроса. Каждое изменение — ['key', 'kind' =>
 * price|demand, 'from', 'to', 'name', 'group']; key — вид и id предмета, по нему
 * склеиваются сохранения подряд.
 *
 * Спрос объявляется только у предмета, который был и раньше, и только новый:
 * снятую отметку объявлять нечем.
 */
function tg_route_changes(array $old, array $new): array {
    $before = [];
    foreach (tg_tier_items($old) as $it) { $before[$it['id']] = $it; }
    if (!$before) { return []; }

    $prices = [];
    $demand = [];
    $seen   = [];
    foreach (tg_tier_items($new) as $it) {
        if (isset($seen[$it['id']])) { continue; }
        $seen[$it['id']] = true;
        if ($it['name'] === '') { continue; }

        $prev = $before[$it['id']] ?? null;
        $base = ['name' => $it['name'], 'group' => tg_route_group($it['type'])];
        $was  = $prev !== null && $prev['value'] !== '' ? $prev['value'] : null;
        if ($it['value'] !== '' && tg_route_moved($was, $it['value'])) {
            $prices[] = ['key' => 'p:' . $it['id'], 'kind' => 'price', 'from' => $was, 'to' => $it['value']] + $base;
        }
        if ($prev !== null && $it['demand'] !== '' && $it['demand'] !== $prev['demand']) {
            $demand[] = ['key' => 'd:' . $it['id'], 'kind' => 'demand', 'from' => $prev['demand'], 'to' => $it['demand']] + $base;
        }
    }
    return array_merge($prices, $demand);
}

// Склеить изменения прошлого поста с новыми: «было» — из прошлого, «стало» —
// из нового. Вернули как было — изменение выпадает.
function tg_route_merge(array $prev, array $cur): array {
    $all = [];
    foreach (array_merge($prev, $cur) as $c) {
        if (isset($all[$c['key']])) { $c['from'] = $all[$c['key']]['from']; }
        $all[$c['key']] = $c;
    }
    $out = [];
    foreach ($all as $c) {
        $moved = $c['kind'] === 'price' ? tg_route_moved($c['from'], $c['to']) : $c['from'] !== $c['to'];
        if ($moved) { $out[] = $c; }
    }
    return $out;
}

// Что лежит в msg_log: сообщения поста и какие изменения в нём перечислены.
// Пишем это сами, но кривая строка в базе всё равно не должна уронить пост.
function tg_route_log($raw): array {
    $empty = ['ids' => [], 'changes' => []];
    $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($d) || !is_array($d['ids'] ?? null) || !is_array($d['changes'] ?? null)) { return $empty; }
    $ids = [];
    foreach ($d['ids'] as $id) {
        if (is_int($id) && $id > 0) { $ids[] = $id; }
    }
    $changes = [];
    foreach ($d['changes'] as $c) {
        if (!is_array($c)) { continue; }
        foreach (['key', 'kind', 'to', 'name', 'group'] as $f) {
            if (!is_string($c[$f] ?? null)) { continue 2; }
        }
        $from = $c['from'] ?? null;
        if (!in_array($c['kind'], ['price', 'demand'], true) || ($from !== null && !is_string($from))) { continue; }
        $changes[] = [
            'key'   => $c['key'],
            'kind'  => $c['kind'],
            'from'  => $from,
            'to'    => $c['to'],
            'name'  => $c['name'],
            'group' => isset(TG_ROUTE_GROUPS[$c['group']]) ? $c['group'] : 'fruits',
        ];
    }
    return ['ids' => $ids, 'changes' => $changes];
}

function tg_route_store_log(PDO $pdo, int $chat, array $ids, array $changes): void {
    $pdo->prepare("UPDATE tg_routes SET msg_log = :l WHERE chat_id = :c AND topic = 'prices'")
        ->execute([
            ':l' => json_encode(['ids' => $ids, 'changes' => $changes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':c' => $chat,
        ]);
}

// Оформление — как у постов владельца об обновлении экономики: разделы тирлиста,
// «Упали / Выросли», «Обновили Demand», ссылка на тирлист.
function tg_route_prices_calls(array $changes): array {
    $groups = [];
    $demand = [];
    foreach ($changes as $c) {
        $name = tg_route_h($c['name']);
        if ($c['kind'] === 'demand') {
            $demand[] = '• ' . $name . ' → ' . tg_route_h(TG_ROUTE_DEMAND[$c['to']] ?? $c['to']);
            continue;
        }
        if ($c['from'] === null) {
            $groups[$c['group']]['new'][] = '• ' . $name . ' — ' . tg_route_h($c['to']);
            continue;
        }
        $a = tg_price_number($c['from']);
        $b = tg_price_number($c['to']);
        $bucket = ($a === null || $b === null) ? 'fix' : ($b > $a ? 'up' : 'down');
        $groups[$c['group']][$bucket][] = '• ' . $name . ' — ' . tg_route_h($c['from']) . ' → ' . tg_route_h($c['to']);
    }

    $blocks = ["Ⓜ️ <b>Обновление цен в тирлисте</b>\n\nПересмотрели значения — вот что изменилось."];
    foreach (TG_ROUTE_GROUPS as $g => $title) {
        $head = '<b>' . $title . "</b>\n\n";
        foreach (TG_ROUTE_BUCKETS as $b => $label) {
            if (empty($groups[$g][$b])) { continue; }
            $blocks[] = $head . '<b>' . $label . "</b>\n" . implode("\n", $groups[$g][$b]);
            $head = '';
        }
    }
    if ($demand) { $blocks[] = "<b>✅ Обновили Demand:</b>\n" . implode("\n", $demand); }
    $blocks[] = '<i>Ориентируйтесь в первую очередь на собственное мнение: мы показываем ситуацию на рынке, '
        . 'а не заставляем трейдить строго по нашим ценам.</i>';
    $blocks[] = '📊 ' . TG_SITE . "/tierlist\n\nУдачи вам в трейдах!";
    return tg_route_text_calls($blocks, tg_text('openTierlist', 'ru'), '/tierlist');
}

/**
 * Поправить вышедший пост: сообщения по порядку, лишние удалить, недостающие
 * дослать. null — первое сообщение удалили руками, пост надо выпускать заново.
 * Иначе — ответил ли Telegram на каждую правку.
 */
function tg_route_prices_edit(PDO $pdo, callable $send, int $chat, array $ids, array $changes, callable $sleep): ?bool {
    $calls = tg_route_prices_calls($changes);
    $keep = [];
    $heard = true;
    foreach ($calls as $i => $call) {
        if ($i > 0) { $sleep(TG_ROUTE_PART_GAP); }
        if (isset($ids[$i])) {
            $res = tg_route_call($send, 'editMessageText', ['chat_id' => $chat, 'message_id' => $ids[$i]] + $call[1], $sleep);
            $same = is_array($res) && stripos((string)($res['description'] ?? ''), 'not modified') !== false;
            // Telegram не ответил — правка могла пройти, и второй пост был бы
            // дублем. Склейку запоминаем: следующая правка перепишет пост целиком.
            if ($res === null || $same || !empty($res['ok'])) {
                $keep[] = $ids[$i];
                if ($res === null) { $heard = false; }
                continue;
            }
            if ($i === 0) { return null; }
        }
        $id = tg_route_msg_id(tg_route_call($send, 'sendMessage', ['chat_id' => $chat] + $call[1], $sleep));
        if ($id > 0) { $keep[] = $id; }
    }
    foreach (array_slice($ids, count($calls)) as $extra) {
        tg_route_call($send, 'deleteMessage', ['chat_id' => $chat, 'message_id' => $extra], $sleep);
    }
    tg_route_store_log($pdo, $chat, $keep, $changes);
    return $heard;
}

// Один чат: поправить недавний пост или выпустить новый. true — пост на месте.
function tg_route_prices_one(PDO $pdo, callable $send, array $r, array $changes, int $now, callable $sleep): bool {
    $chat = (int)$r['chat_id'];
    $log  = tg_route_log($r['msg_log'] ?? '');
    if ($log['ids'] && $now - (int)$r['posted_at'] < TG_ROUTE_MERGE) {
        $changes = tg_route_merge($log['changes'], $changes);
        if (!$changes) {
            foreach ($log['ids'] as $id) {
                tg_route_call($send, 'deleteMessage', ['chat_id' => $chat, 'message_id' => $id], $sleep);
            }
            $pdo->prepare("UPDATE tg_routes SET msg_id = 0, posted_at = 0, msg_log = NULL WHERE chat_id = :c AND topic = 'prices'")
                ->execute([':c' => $chat]);
            return true;
        }
        $edited = tg_route_prices_edit($pdo, $send, $chat, $log['ids'], $changes, $sleep);
        if ($edited !== null) { return $edited; }
        // Пост удалили руками — новый, со всем, что накопилось.
    }
    $done = tg_route_deliver($pdo, $send, $chat, 'prices', tg_route_prices_calls($changes), $now, $sleep);
    if (!$done['ids']) { return false; }
    tg_route_store_log($pdo, $chat, $done['ids'], $changes);
    return true;
}

/**
 * Изменения тирлиста — в чаты темы prices. $changes — из tg_route_changes().
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
