<?php
// Хэллоуинский ивент «Тыквенная охота»: тыковки за задания и секретки,
// билеты главного розыгрыша за тыковки, итоги и выдача призов.
// Устройство и решения владельца — docs/superpowers/specs/2026-10-19-halloween-hunt-design.md.
//
// Честность наград держит одна вещь: журнал hunt_ledger с уникальным ключом
// (hunt_id, user_id, ref). Любая награда — строка журнала с ref вроде
// task:12:once или secret:7:2026-10-25; повтор из второй вкладки, двойной
// клик, перезагрузка упираются в ключ (SQLSTATE 23000 — одинаков у MySQL и
// SQLite) и ничего не начисляют. hunt_wallets — кеш журнала, его меняет та
// же транзакция: balance — что можно потратить, earned — всего заработано.
// earned при тратах не уменьшается, поэтому покупка билета не откатывает
// шкалу приключения.
//
// Списание — условный UPDATE (balance >= цена И tickets = сколько было):
// без FOR UPDATE, который SQLite не знает, две одновременные покупки не
// уведут баланс в минус и не превысят лимит; второй барьер — уникальный
// (hunt_id, user_id, seq) у билета.
//
// «День» для ежедневных заданий и секреток — дата по Москве. Время в базе —
// секунды UTC, страница показывает его в поясе посетителя.
//
// Правильный ответ вопроса и пояснение к нему наружу не уходят, пока человек
// не ответил верно: hunt_task_public() их вырезает. Ответами всё равно будут
// делиться, поэтому экономика считается так, будто на все вопросы ответят все.
//
// PHP 7.4: без match и без str_contains.

require_once __DIR__ . '/giveaway.php';

const HUNT_TZ            = 'Europe/Moscow';
const HUNT_KINDS         = ['quiz', 'tg', 'link', 'visit'];
const HUNT_LEVELS        = ['intro', 'easy', 'medium', 'hard'];
const HUNT_PERIODS       = ['once', 'daily'];
const HUNT_LANGS         = ['all', 'ru', 'en'];
const HUNT_SECRET_LEVELS = ['easy', 'hint', 'riddle'];
const HUNT_SPRITES       = ['pumpkin', 'ghost', 'candle', 'bat', 'skull'];
// redrawn — просроченный выигрыш, приз которого «Перевыбрать» отдал дальше.
const HUNT_WIN_STATUSES  = ['won', 'confirmed', 'queued', 'given', 'expired', 'redrawn'];
// Куда админка может перевести выигрыш из каждого статуса.
const HUNT_WIN_MOVES     = [
    'won'       => ['confirmed', 'expired'],
    'confirmed' => ['queued', 'expired'],
    'queued'    => ['given'],
];
// Обезличенные счётчики гостей (hunt_stats).
const HUNT_METRICS       = ['page_v', 'invite_v', 'invite_c', 'bar_c'];

// Где на страницах сайта может стоять секретка: ключ страницы → место →
// подпись для админки. Селекторы мест живут в js/hunt-bar.js, тест сверяет
// списки. Ключи страниц — те же, что у заданий «посети раздел».
const HUNT_SPOTS = [
    'halloween' => ['intro' => 'Вводная тыква', 'head' => 'У заголовка', 'foot' => 'У подвала'],
    'home'      => ['head' => 'Первый экран', 'mid' => 'Середина страницы', 'foot' => 'У подвала'],
    'tierlist'  => ['head' => 'Над тирлистом', 'foot' => 'Под тирлистом'],
    'calc'      => ['head' => 'У заголовка', 'foot' => 'Под калькулятором'],
    'trading'   => ['head' => 'Над лентой', 'side' => 'Сбоку от ленты'],
    'stock'     => ['head' => 'У заголовка', 'foot' => 'Под карточками'],
    'news'      => ['head' => 'Над лентой', 'foot' => 'Под лентой'],
    'giveaways' => ['head' => 'У заголовка', 'foot' => 'Под розыгрышами'],
    'profile'   => ['head' => 'У аватара'],
];

// --------------------------------------------------------------------------
//  Таблицы, сезон, главы
// --------------------------------------------------------------------------

function hunt_ready(PDO $pdo): bool {
    try {
        foreach (['hunts', 'hunt_chapters', 'hunt_tasks', 'hunt_secrets', 'hunt_wallets',
                  'hunt_ledger', 'hunt_tickets', 'hunt_wins'] as $t) {
            $pdo->query("SELECT 1 FROM $t LIMIT 1");
        }
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function hunt_is_dup(PDOException $e): bool {
    return (string)$e->getCode() === '23000';
}

function hunt_day(int $now): string {
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(HUNT_TZ))->format('Y-m-d');
}

// Когда по Москве начнётся следующий день — для «обновится через 5 ч».
function hunt_next_day_at(int $now): int {
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(HUNT_TZ))
        ->modify('tomorrow')->getTimestamp();
}

function hunt_period_key(string $period, int $now): string {
    return $period === 'daily' ? hunt_day($now) : 'once';
}

function hunt_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hunts WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Сезон, который видит сайт: идущий (в том числе ещё не начавшийся — страница
// показывает «скоро»), а если такого нет — последний разыгранный, с итогами.
function hunt_current(PDO $pdo, int $now): ?array {
    $row = $pdo->query("SELECT * FROM hunts WHERE status IN ('live', 'drawn')
        ORDER BY CASE WHEN status = 'live' THEN 0 ELSE 1 END, starts_at DESC, id DESC LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// draft — черновик; soon — опубликован, но не начался; open — идёт;
// closed — приём билетов и задания закрыты, ждём итогов; drawn — итоги есть.
function hunt_phase(array $hunt, int $now): string {
    $status = (string)$hunt['status'];
    if ($status === 'drawn') { return 'drawn'; }
    if ($status !== 'live') { return 'draft'; }
    if ($now < (int)$hunt['starts_at']) { return 'soon'; }
    if ($now >= (int)$hunt['sales_end_at']) { return 'closed'; }
    return 'open';
}

function hunt_chapters(PDO $pdo, int $huntId): array {
    $st = $pdo->prepare('SELECT * FROM hunt_chapters WHERE hunt_id = :h ORDER BY n');
    $st->execute([':h' => $huntId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

// Номера открытых глав. Первая глава открыта и без своей строки: сезон из
// одной главы не обязан заводить главы вовсе.
function hunt_open_chapters(array $chapters, int $now): array {
    $open = [];
    $hasFirst = false;
    foreach ($chapters as $c) {
        if ((int)$c['n'] === 1) { $hasFirst = true; }
        if ((int)$c['opens_at'] <= $now) { $open[] = (int)$c['n']; }
    }
    if (!$hasFirst) { $open[] = 1; }
    sort($open);
    return $open;
}

function hunt_json($json): array {
    if (!is_string($json) || $json === '') { return []; }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

function hunt_pair(array $row, string $key): array {
    return ['ru' => (string)($row[$key . '_ru'] ?? ''), 'en' => (string)($row[$key . '_en'] ?? '')];
}

function hunt_fail(int $code, string $status, array $extra = []): array {
    return [$code, ['ok' => false, 'status' => $status] + $extra];
}

function hunt_src_clean($raw): string {
    if (!is_string($raw)) { return ''; }
    $s = strtolower(trim($raw));
    return preg_match('/^[a-z0-9_-]{1,32}$/', $s) ? $s : '';
}

// Telegram, привязанный к аккаунту (tg_links), или null.
function hunt_tg_chat(PDO $pdo, string $uid): ?int {
    try {
        $st = $pdo->prepare('SELECT chat_id FROM tg_links WHERE user_id = :u');
        $st->execute([':u' => $uid]);
        $chat = $st->fetchColumn();
        return $chat === false ? null : (int)$chat;
    } catch (PDOException $e) {
        return null;
    }
}

// --------------------------------------------------------------------------
//  Кошелёк и журнал
// --------------------------------------------------------------------------

function hunt_wallet(PDO $pdo, int $huntId, string $uid): array {
    $st = $pdo->prepare('SELECT balance, earned, tickets, src, first_at, last_at, last_day, days
                           FROM hunt_wallets WHERE hunt_id = :h AND user_id = :u');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['balance' => 0, 'earned' => 0, 'tickets' => 0, 'src' => '', 'first_at' => 0,
                'last_at' => 0, 'last_day' => '', 'days' => 0];
    }
    return [
        'balance'  => (int)$row['balance'],
        'earned'   => (int)$row['earned'],
        'tickets'  => (int)$row['tickets'],
        'src'      => (string)$row['src'],
        'first_at' => (int)$row['first_at'],
        'last_at'  => (int)$row['last_at'],
        'last_day' => (string)$row['last_day'],
        'days'     => (int)$row['days'],
    ];
}

// То, что из кошелька видит страница.
function hunt_wallet_out(array $w): array {
    return ['balance' => $w['balance'], 'earned' => $w['earned'], 'tickets' => $w['tickets']];
}

// Строка кошелька заводится при первом действии. src — первый источник
// перехода; у уже заведённого кошелька он не меняется.
function hunt_wallet_ensure(PDO $pdo, int $huntId, string $uid, int $now, string $src = ''): void {
    try {
        $pdo->prepare('INSERT INTO hunt_wallets (hunt_id, user_id, src, first_at) VALUES (?, ?, ?, ?)')
            ->execute([$huntId, $uid, $src, $now]);
    } catch (PDOException $e) {
        if (!hunt_is_dup($e)) { throw $e; }
    }
}

function hunt_has_ref(PDO $pdo, int $huntId, string $uid, string $ref): bool {
    $st = $pdo->prepare('SELECT 1 FROM hunt_ledger WHERE hunt_id = :h AND user_id = :u AND ref = :r');
    $st->execute([':h' => $huntId, ':u' => $uid, ':r' => $ref]);
    return $st->fetchColumn() !== false;
}

/**
 * Начислить тыковки. ['status' => 'done'|'already', 'wallet' => …]:
 * already — этот ref у человека уже есть, ничего не изменилось.
 *
 * days и last_day — в одном UPDATE, и days стоит первым: MySQL считает
 * присваивания слева направо, и CASE должен видеть прежний last_day.
 */
function hunt_award(PDO $pdo, int $huntId, string $uid, string $kind, string $ref, int $delta, int $now, string $src = ''): array {
    hunt_wallet_ensure($pdo, $huntId, $uid, $now, $src);
    $day = hunt_day($now);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO hunt_ledger (hunt_id, user_id, delta, kind, ref, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$huntId, $uid, $delta, $kind, $ref, $now]);
        $pdo->prepare('UPDATE hunt_wallets SET balance = balance + :d, earned = earned + :e, last_at = :now,
                              days = days + CASE WHEN last_day = :day THEN 0 ELSE 1 END, last_day = :day2
                        WHERE hunt_id = :h AND user_id = :u')
            ->execute([':d' => $delta, ':e' => max(0, $delta), ':now' => $now, ':day' => $day,
                       ':day2' => $day, ':h' => $huntId, ':u' => $uid]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!hunt_is_dup($e)) { throw $e; }
        return ['status' => 'already', 'wallet' => hunt_wallet_out(hunt_wallet($pdo, $huntId, $uid))];
    }
    return ['status' => 'done', 'wallet' => hunt_wallet_out(hunt_wallet($pdo, $huntId, $uid))];
}

/**
 * Общие проверки любого действия участника. [ошибка, сезон]: ошибка —
 * готовый ответ [код, тело] или null, тогда сезон идёт и действие можно
 * выполнять.
 */
function hunt_gate(PDO $pdo, array $cfg, string $uid, int $now): array {
    if ($uid === '') { return [hunt_fail(401, 'login'), null]; }
    if (!hunt_ready($pdo)) { return [hunt_fail(503, 'closed'), null]; }
    if (site_banned($uid, $cfg, $now, $pdo)) { return [hunt_fail(403, 'banned'), null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt || hunt_phase($hunt, $now) !== 'open') { return [hunt_fail(409, 'closed'), null]; }
    return [null, $hunt];
}

function hunt_chapter_open(PDO $pdo, array $hunt, int $chapter, int $now): bool {
    return in_array($chapter, hunt_open_chapters(hunt_chapters($pdo, (int)$hunt['id']), $now), true);
}

// --------------------------------------------------------------------------
//  Задания
// --------------------------------------------------------------------------

function hunt_tasks(PDO $pdo, int $huntId, bool $activeOnly = true): array {
    $st = $pdo->prepare('SELECT * FROM hunt_tasks WHERE hunt_id = :h' . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY chapter, sort, id');
    $st->execute([':h' => $huntId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hunt_task_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hunt_tasks WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Ключ журнала. У задания с группой — ключ группы: RU- и EN-вариант одного
// действия вместе дают одну награду, и смена языка вторую не открывает.
function hunt_task_ref(array $task, int $now): string {
    $period = hunt_period_key((string)$task['period'], $now);
    $grp = (string)$task['grp'];
    return $grp !== '' ? "grp:$grp:$period" : 'task:' . (int)$task['id'] . ':' . $period;
}

// Каналы Telegram по chat_id — чтобы у задания-подписки была ссылка.
function hunt_channel_map(PDO $pdo): array {
    try {
        $map = [];
        foreach ($pdo->query('SELECT * FROM tg_channels')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $map[(string)$c['chat_id']] = $c;
        }
        return $map;
    } catch (PDOException $e) {
        return [];
    }
}

// Задание для страницы: без верного ответа и пояснения.
function hunt_task_public(array $task, array $channels): array {
    $cfg = hunt_json($task['cfg_json'] ?? null);
    $out = [
        'id'      => (int)$task['id'],
        'chapter' => (int)$task['chapter'],
        'kind'    => (string)$task['kind'],
        'level'   => (string)$task['level'],
        'period'  => (string)$task['period'],
        'lang'    => (string)$task['lang'],
        'reward'  => (int)$task['reward'],
        'title'   => hunt_pair($task, 'title'),
        'body'    => hunt_pair($task, 'body'),
    ];
    $kind = $out['kind'];
    if ($kind === 'quiz') {
        $out['q'] = hunt_pair($cfg, 'q');
        $out['options'] = [];
        foreach (is_array($cfg['options'] ?? null) ? $cfg['options'] : [] as $o) {
            $out['options'][] = ['ru' => (string)($o['ru'] ?? ''), 'en' => (string)($o['en'] ?? '')];
        }
    } elseif ($kind === 'tg') {
        $c = $channels[(string)($cfg['channel'] ?? '')] ?? null;
        $out['channel'] = $c ? ['title' => (string)$c['title'], 'url' => gw_channel_url($c)] : null;
    } elseif ($kind === 'link') {
        $out['url'] = (string)($cfg['url'] ?? '');
    } elseif ($kind === 'visit') {
        $out['page'] = (string)($cfg['page'] ?? '');
    }
    return $out;
}

/**
 * Выполнить задание. [код, тело]; тело — {ok, status, wallet?, explain?,
 * hint?}. status: done | already | wrong (вопрос, неверно — подсказка, можно
 * снова) | pending (Telegram не ответил) | tg (нет Telegram) | not_sub |
 * login | banned | closed | bad.
 *
 * Уже выполненное отвечает already сразу, до проверок: повторное нажатие у
 * подписки не идёт в Telegram, а у вопроса возвращает пояснение.
 */
function hunt_do_task(PDO $pdo, array $cfg, callable $send, string $uid, int $taskId, array $in, int $now): array {
    [$err, $hunt] = hunt_gate($pdo, $cfg, $uid, $now);
    if ($err) { return $err; }
    $hid = (int)$hunt['id'];
    $task = $taskId > 0 ? hunt_task_load($pdo, $taskId) : null;
    if (!$task || (int)$task['hunt_id'] !== $hid || (int)$task['active'] !== 1
        || !hunt_chapter_open($pdo, $hunt, (int)$task['chapter'], $now)) {
        return hunt_fail(409, 'closed');
    }
    $tcfg = hunt_json($task['cfg_json'] ?? null);
    $kind = (string)$task['kind'];
    $ref = hunt_task_ref($task, $now);
    $extra = $kind === 'quiz' ? ['explain' => hunt_pair($tcfg, 'explain')] : [];

    if (hunt_has_ref($pdo, $hid, $uid, $ref)) {
        return [200, ['ok' => true, 'status' => 'already', 'wallet' => hunt_wallet_out(hunt_wallet($pdo, $hid, $uid))] + $extra];
    }

    if ($kind === 'quiz') {
        $choice = $in['choice'] ?? null;
        if (is_string($choice) && $choice !== '' && ctype_digit($choice)) { $choice = (int)$choice; }
        if (!is_int($choice)) { return hunt_fail(400, 'bad'); }
        if ($choice !== (int)($tcfg['correct'] ?? -1)) {
            return [200, ['ok' => false, 'status' => 'wrong', 'hint' => hunt_pair($tcfg, 'hint')]];
        }
    } elseif ($kind === 'tg') {
        $chat = hunt_tg_chat($pdo, $uid);
        if ($chat === null) { return hunt_fail(409, 'tg'); }
        $state = gw_member_state($send, (int)($tcfg['channel'] ?? 0), $chat);
        if ($state === 'error') { return hunt_fail(503, 'pending'); }
        if ($state !== 'yes') { return hunt_fail(409, 'not_sub'); }
    } elseif ($kind === 'visit') {
        if ((string)($in['page'] ?? '') !== (string)($tcfg['page'] ?? '')) { return hunt_fail(400, 'bad'); }
    } elseif ($kind !== 'link') {
        return hunt_fail(409, 'closed');
    }

    $a = hunt_award($pdo, $hid, $uid, 'task', $ref, (int)$task['reward'], $now, hunt_src_clean($in['src'] ?? ''));
    return [200, ['ok' => true, 'status' => $a['status'], 'wallet' => $a['wallet']] + $extra];
}

// --------------------------------------------------------------------------
//  Секретки
// --------------------------------------------------------------------------

function hunt_secrets(PDO $pdo, int $huntId, bool $activeOnly = true): array {
    $st = $pdo->prepare('SELECT * FROM hunt_secrets WHERE hunt_id = :h' . ($activeOnly ? ' AND active = 1' : '')
        . ' ORDER BY chapter, sort, id');
    $st->execute([':h' => $huntId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function hunt_secret_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hunt_secrets WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hunt_secret_ref(array $secret, int $now): string {
    return 'secret:' . (int)$secret['id'] . ':' . hunt_period_key((string)$secret['period'], $now);
}

/**
 * Находка секретки. Засчитывается только со своей страницы. Находки у
 * каждого свои: чужая находка у другого ничего не забирает.
 */
function hunt_find_secret(PDO $pdo, array $cfg, string $uid, int $id, string $page, int $now, string $src = ''): array {
    [$err, $hunt] = hunt_gate($pdo, $cfg, $uid, $now);
    if ($err) { return $err; }
    $s = $id > 0 ? hunt_secret_load($pdo, $id) : null;
    if (!$s || (int)$s['hunt_id'] !== (int)$hunt['id'] || (int)$s['active'] !== 1
        || !hunt_chapter_open($pdo, $hunt, (int)$s['chapter'], $now)) {
        return hunt_fail(409, 'closed');
    }
    if ($page !== (string)$s['page']) { return hunt_fail(400, 'bad'); }
    $a = hunt_award($pdo, (int)$hunt['id'], $uid, 'secret', hunt_secret_ref($s, $now), (int)$s['reward'], $now, $src);
    return [200, ['ok' => true, 'status' => $a['status'], 'wallet' => $a['wallet'],
        'reward' => (int)$s['reward'], 'found' => hunt_pair($s, 'found')]];
}

// --------------------------------------------------------------------------
//  Билеты
// --------------------------------------------------------------------------

function hunt_ticket_no(int $id): string {
    return '#' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function hunt_ticket_out(array $row): array {
    return [
        'id'         => (int)$row['id'],
        'no'         => hunt_ticket_no((int)$row['id']),
        'seq'        => (int)$row['seq'],
        'created_at' => (int)$row['created_at'],
        'void'       => (int)$row['void'] === 1,
    ];
}

function hunt_my_tickets(PDO $pdo, int $huntId, string $uid): array {
    $st = $pdo->prepare('SELECT * FROM hunt_tickets WHERE hunt_id = :h AND user_id = :u ORDER BY seq');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    return array_map('hunt_ticket_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Обменять тыковки на билет. $seq — каким по счёту должен стать новый билет
 * (у человека было tickets, значит tickets + 1). Повтор того же запроса —
 * перезагрузка, двойной клик — отвечает already уже купленным билетом.
 * stale — страница отстала (билет купили в другой вкладке): она перечитает
 * кошелёк. Ответ несёт всё, что человек должен увидеть сразу после обмена:
 * номер билета, все его билеты, конец приёма и дату итогов.
 */
function hunt_buy_ticket(PDO $pdo, array $cfg, string $uid, int $seq, int $now): array {
    [$err, $hunt] = hunt_gate($pdo, $cfg, $uid, $now);
    if ($err) { return $err; }
    $hid = (int)$hunt['id'];
    $price = (int)$hunt['ticket_price'];
    $limit = (int)$hunt['ticket_limit'];
    $info = ['limit' => $limit, 'price' => $price,
             'sales_end_at' => (int)$hunt['sales_end_at'], 'draw_at' => (int)$hunt['draw_at']];
    $done = function (string $status) use ($pdo, $hid, $uid, $seq, $info): array {
        $tickets = hunt_my_tickets($pdo, $hid, $uid);
        $mine = null;
        foreach ($tickets as $t) { if ($t['seq'] === $seq) { $mine = $t; } }
        return [200, ['ok' => true, 'status' => $status, 'ticket' => $mine, 'tickets' => $tickets,
            'wallet' => hunt_wallet_out(hunt_wallet($pdo, $hid, $uid))] + $info];
    };

    hunt_wallet_ensure($pdo, $hid, $uid, $now);
    $w = hunt_wallet($pdo, $hid, $uid);
    $have = $w['tickets'];
    if ($seq >= 1 && $seq <= $have) { return $done('already'); }
    if ($have >= $limit) { return hunt_fail(409, 'limit', ['wallet' => hunt_wallet_out($w)] + $info); }
    if ($seq !== $have + 1) { return hunt_fail(409, 'stale', ['wallet' => hunt_wallet_out($w)] + $info); }
    if ($w['balance'] < $price) {
        return hunt_fail(409, 'balance', ['need' => $price - $w['balance'], 'wallet' => hunt_wallet_out($w)] + $info);
    }

    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE hunt_wallets SET balance = balance - :p, tickets = tickets + 1, last_at = :now
                              WHERE hunt_id = :h AND user_id = :u AND balance >= :p2 AND tickets = :have');
        $up->execute([':p' => $price, ':now' => $now, ':h' => $hid, ':u' => $uid, ':p2' => $price, ':have' => $have]);
        if ($up->rowCount() !== 1) {
            $pdo->rollBack();
            return hunt_fail(409, 'stale', ['wallet' => hunt_wallet_out(hunt_wallet($pdo, $hid, $uid))] + $info);
        }
        $pdo->prepare('INSERT INTO hunt_tickets (hunt_id, user_id, seq, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$hid, $uid, $seq, $now]);
        $pdo->prepare('INSERT INTO hunt_ledger (hunt_id, user_id, delta, kind, ref, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$hid, $uid, -$price, 'ticket', 'ticket:' . $seq, $now]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!hunt_is_dup($e)) { throw $e; }
        return $done('already');
    }
    return $done('done');
}

// --------------------------------------------------------------------------
//  Итоги и выдача
// --------------------------------------------------------------------------

// Призы, разложенные по одному: [{idx, name, icon}, …] в порядке списка.
function hunt_prize_slots(array $prizes): array {
    $slots = [];
    foreach ($prizes as $i => $p) {
        for ($k = 0; $k < max(0, (int)$p['count']); $k++) {
            $slots[] = ['idx' => $i, 'name' => (string)$p['name'], 'icon' => (string)$p['icon']];
        }
    }
    return $slots;
}

function hunt_win_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hunt_wins WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hunt_win_insert(PDO $pdo, int $huntId, string $uid, int $ticketId, array $slot, int $deadline, int $now): int {
    $pdo->prepare('INSERT INTO hunt_wins (hunt_id, user_id, ticket_id, prize_idx, prize_name, prize_icon, status,
                          deadline, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$huntId, $uid, $ticketId, $slot['idx'], $slot['name'], $slot['icon'], 'won', $deadline, $now, $now]);
    return (int)$pdo->lastInsertId();
}

function hunt_win_out(array $row): array {
    $status = (string)$row['status'];
    return [
        'id'        => (int)$row['id'],
        'prize'     => ['idx' => (int)$row['prize_idx'], 'name' => (string)$row['prize_name'], 'icon' => (string)$row['prize_icon']],
        'status'    => $status === 'redrawn' ? 'expired' : $status,
        'deadline'  => (int)$row['deadline'],
        'ticket_no' => hunt_ticket_no((int)$row['ticket_id']),
    ];
}

function hunt_my_wins(PDO $pdo, int $huntId, string $uid): array {
    $st = $pdo->prepare('SELECT * FROM hunt_wins WHERE hunt_id = :h AND user_id = :u ORDER BY id');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    return array_map('hunt_win_out', $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Кнопка «Разыграть». Можно только после конца приёма и только раз.
 * Билеты без пометки void, чьи владельцы не забанены, перемешиваются
 * (Фишер—Йетс на $rand, по умолчанию random_int); каждый получает место в
 * очереди draw_pos. Призы по порядку получает первый билет, чей владелец
 * ещё ничего не выиграл: один человек — один приз. Очередь остаётся в базе —
 * по ней «Перевыбрать» отдаёт приз неявившегося следующему.
 *
 * Статус сезона меняется первым в транзакции и условно (WHERE status =
 * 'live'): второе одновременное нажатие ничего не разыграет.
 */
function hunt_draw(PDO $pdo, array $cfg, int $huntId, int $now, ?callable $rand = null): array {
    if (!hunt_ready($pdo)) { return hunt_fail(503, 'closed'); }
    $hunt = hunt_load($pdo, $huntId);
    if (!$hunt) { return hunt_fail(404, 'bad'); }
    if ((string)$hunt['status'] === 'drawn') { return hunt_fail(409, 'drawn'); }
    if ((string)$hunt['status'] !== 'live' || $now < (int)$hunt['sales_end_at']) { return hunt_fail(409, 'not_ended'); }
    $rand = $rand ?? function (int $n): int { return random_int(0, $n - 1); };

    $banned = array_flip(site_banned_ids($cfg, $now, $pdo));
    $st = $pdo->prepare('SELECT id, user_id FROM hunt_tickets WHERE hunt_id = :h AND void = 0 ORDER BY id');
    $st->execute([':h' => $huntId]);
    $queue = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if (!isset($banned[(string)$t['user_id']])) { $queue[] = $t; }
    }
    for ($i = count($queue) - 1; $i > 0; $i--) {
        $j = $rand($i + 1);
        [$queue[$i], $queue[$j]] = [$queue[$j], $queue[$i]];
    }
    $slots = hunt_prize_slots(gw_prizes($hunt['prizes_json'] ?? null));
    $deadline = $now + max(1, (int)$hunt['claim_days']) * 86400;

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("UPDATE hunts SET status = 'drawn', drawn_at = :now, updated_at = :now2
                                WHERE id = :id AND status = 'live'");
        $lock->execute([':now' => $now, ':now2' => $now, ':id' => $huntId]);
        if ($lock->rowCount() !== 1) {
            $pdo->rollBack();
            return hunt_fail(409, 'drawn');
        }
        $pos = $pdo->prepare('UPDATE hunt_tickets SET draw_pos = :p WHERE id = :id');
        $won = [];
        $k = 0;
        foreach ($queue as $n => $t) {
            $pos->execute([':p' => $n + 1, ':id' => (int)$t['id']]);
            $uid = (string)$t['user_id'];
            if ($k < count($slots) && !isset($won[$uid])) {
                hunt_win_insert($pdo, $huntId, $uid, (int)$t['id'], $slots[$k], $deadline, $now);
                $won[$uid] = true;
                $k++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return [200, ['ok' => true, 'winners' => count($won), 'tickets' => count($queue)]];
}

// Не подтвердил выигрыш до срока — expired. Зовётся при чтении: отдельного
// cron нет, а срок — граница, которую видно с первого же запроса после неё.
function hunt_expire_wins(PDO $pdo, int $huntId, int $now): int {
    $st = $pdo->prepare("UPDATE hunt_wins SET status = 'expired', updated_at = :now
                          WHERE hunt_id = :h AND status = 'won' AND deadline > 0 AND deadline < :now2");
    $st->execute([':now' => $now, ':h' => $huntId, ':now2' => $now]);
    return $st->rowCount();
}

/**
 * «Перевыбрать»: приз просроченного выигрыша получает следующий по очереди
 * итогов билет, чей владелец ещё ничего не выигрывал и не забанен. Очередь
 * кончилась — none_left. Просроченный выигрыш становится redrawn, чтобы его
 * приз не отдали дважды.
 */
function hunt_redraw(PDO $pdo, array $cfg, int $winId, int $now): array {
    $win = hunt_win_load($pdo, $winId);
    if (!$win) { return hunt_fail(404, 'bad'); }
    $hid = (int)$win['hunt_id'];
    hunt_expire_wins($pdo, $hid, $now);
    $win = hunt_win_load($pdo, $winId);
    if ((string)$win['status'] !== 'expired') { return hunt_fail(409, 'not_expired'); }
    $hunt = hunt_load($pdo, $hid);

    $st = $pdo->prepare('SELECT MAX(t.draw_pos) FROM hunt_wins w JOIN hunt_tickets t ON t.id = w.ticket_id WHERE w.hunt_id = :h');
    $st->execute([':h' => $hid]);
    $after = (int)$st->fetchColumn();
    $st = $pdo->prepare('SELECT user_id FROM hunt_wins WHERE hunt_id = :h');
    $st->execute([':h' => $hid]);
    $winners = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
    $banned = array_flip(site_banned_ids($cfg, $now, $pdo));
    $st = $pdo->prepare('SELECT id, user_id FROM hunt_tickets WHERE hunt_id = :h AND void = 0 AND draw_pos > :p ORDER BY draw_pos');
    $st->execute([':h' => $hid, ':p' => $after]);
    $next = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $uid = (string)$t['user_id'];
        if (!isset($winners[$uid]) && !isset($banned[$uid])) { $next = $t; break; }
    }
    if ($next === null) { return hunt_fail(409, 'none_left'); }

    $pdo->beginTransaction();
    try {
        $mark = $pdo->prepare("UPDATE hunt_wins SET status = 'redrawn', updated_at = :now WHERE id = :id AND status = 'expired'");
        $mark->execute([':now' => $now, ':id' => $winId]);
        if ($mark->rowCount() !== 1) {
            $pdo->rollBack();
            return hunt_fail(409, 'not_expired');
        }
        $slot = ['idx' => (int)$win['prize_idx'], 'name' => (string)$win['prize_name'], 'icon' => (string)$win['prize_icon']];
        $deadline = $now + max(1, (int)$hunt['claim_days']) * 86400;
        $newId = hunt_win_insert($pdo, $hid, (string)$next['user_id'], (int)$next['id'], $slot, $deadline, $now);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return [200, ['ok' => true, 'win' => hunt_win_out(hunt_win_load($pdo, $newId)), 'user_id' => (string)$next['user_id']]];
}

/**
 * Победитель подтверждает выигрыш: ник Roblox у него уже есть, для связи
 * нужен Telegram. won → confirmed. Уже подтверждённое отвечает already.
 */
function hunt_confirm_win(PDO $pdo, string $uid, int $winId, int $now): array {
    if ($uid === '') { return hunt_fail(401, 'login'); }
    $win = $winId > 0 ? hunt_win_load($pdo, $winId) : null;
    if (!$win || (string)$win['user_id'] !== $uid) { return hunt_fail(404, 'bad'); }
    hunt_expire_wins($pdo, (int)$win['hunt_id'], $now);
    $win = hunt_win_load($pdo, $winId);
    $status = (string)$win['status'];
    if ($status === 'expired' || $status === 'redrawn') { return hunt_fail(409, 'expired', ['win' => hunt_win_out($win)]); }
    if ($status !== 'won') { return [200, ['ok' => true, 'status' => 'already', 'win' => hunt_win_out($win)]]; }
    if (hunt_tg_chat($pdo, $uid) === null) { return hunt_fail(409, 'tg'); }
    $pdo->prepare("UPDATE hunt_wins SET status = 'confirmed', updated_at = :now WHERE id = :id AND status = 'won'")
        ->execute([':now' => $now, ':id' => $winId]);
    return [200, ['ok' => true, 'status' => 'done', 'win' => hunt_win_out(hunt_win_load($pdo, $winId))]];
}

// Админка ведёт выдачу: только переходы из HUNT_WIN_MOVES, назад нельзя.
function hunt_win_set(PDO $pdo, int $winId, string $status, string $note, int $now): array {
    $win = $winId > 0 ? hunt_win_load($pdo, $winId) : null;
    if (!$win) { return hunt_fail(404, 'bad'); }
    $from = (string)$win['status'];
    if (!in_array($status, HUNT_WIN_MOVES[$from] ?? [], true)) { return hunt_fail(409, 'bad_move'); }
    $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note) ?? ''), 0, 255);
    $st = $pdo->prepare('UPDATE hunt_wins SET status = :s, note = :n, updated_at = :now WHERE id = :id AND status = :from');
    $st->execute([':s' => $status, ':n' => $note, ':now' => $now, ':id' => $winId, ':from' => $from]);
    if ($st->rowCount() !== 1) { return hunt_fail(409, 'bad_move'); }
    return [200, ['ok' => true, 'win' => hunt_win_out(hunt_win_load($pdo, $winId))]];
}

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

function hunt_milestones($json): array {
    $out = [];
    foreach (hunt_json($json) as $m) {
        if (!is_array($m)) { continue; }
        $out[] = ['at' => (int)($m['at'] ?? 0), 'title' => hunt_pair($m, 'title')];
    }
    usort($out, function ($a, $b) { return $a['at'] <=> $b['at']; });
    return $out;
}

/**
 * Публичное — одно для всех, поэтому лежит в кеше LiteSpeed (api/hunt.php).
 * Закрытая глава — только заголовок и дата, без заданий и секреток. В
 * журнале у секреток нет страницы: где искать, говорит подсказка. С $page —
 * секретки этой страницы для индикатора: id, место и картинка.
 */
function hunt_public(PDO $pdo, int $now, string $page = ''): array {
    if (!hunt_ready($pdo)) { return ['hunt' => null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt) { return ['hunt' => null]; }
    $hid = (int)$hunt['id'];
    $phase = hunt_phase($hunt, $now);
    $chapters = hunt_chapters($pdo, $hid);
    $open = hunt_open_chapters($chapters, $now);

    $chOut = [];
    $hasFirst = false;
    foreach ($chapters as $c) {
        $isOpen = in_array((int)$c['n'], $open, true);
        if ((int)$c['n'] === 1) { $hasFirst = true; }
        $chOut[] = ['n' => (int)$c['n'], 'title' => hunt_pair($c, 'title'), 'opens_at' => (int)$c['opens_at'], 'open' => $isOpen]
            + ($isOpen ? ['story' => hunt_pair($c, 'story')] : []);
    }
    if (!$hasFirst) {
        array_unshift($chOut, ['n' => 1, 'title' => ['ru' => '', 'en' => ''], 'opens_at' => (int)$hunt['starts_at'],
            'open' => true, 'story' => ['ru' => '', 'en' => '']]);
    }

    $channels = hunt_channel_map($pdo);
    $tasks = [];
    foreach (hunt_tasks($pdo, $hid) as $t) {
        if (in_array((int)$t['chapter'], $open, true)) { $tasks[] = hunt_task_public($t, $channels); }
    }
    $journal = [];
    $pageSecrets = [];
    foreach (hunt_secrets($pdo, $hid) as $s) {
        if (!in_array((int)$s['chapter'], $open, true)) { continue; }
        $journal[] = ['id' => (int)$s['id'], 'chapter' => (int)$s['chapter'], 'level' => (string)$s['level'],
            'period' => (string)$s['period'], 'reward' => (int)$s['reward'], 'hint' => hunt_pair($s, 'hint')];
        if ($page !== '' && $phase === 'open' && (string)$s['page'] === $page) {
            $pageSecrets[] = ['id' => (int)$s['id'], 'spot' => (string)$s['spot'], 'sprite' => (string)$s['sprite']];
        }
    }

    $winners = [];
    if ($phase === 'drawn') {
        $st = $pdo->prepare("SELECT w.prize_idx, w.prize_name, w.prize_icon, w.user_id, u.username, u.display_name
                               FROM hunt_wins w LEFT JOIN users u ON u.roblox_id = w.user_id
                              WHERE w.hunt_id = :h AND w.status NOT IN ('expired', 'redrawn') ORDER BY w.prize_idx, w.id");
        $st->execute([':h' => $hid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $winners[] = ['prize' => ['idx' => (int)$w['prize_idx'], 'name' => (string)$w['prize_name'], 'icon' => (string)$w['prize_icon']],
                'user' => ['id' => (string)$w['user_id'], 'username' => (string)($w['username'] ?? ''),
                           'display_name' => (string)($w['display_name'] ?? '')]];
        }
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM hunt_wallets WHERE hunt_id = :h');
    $st->execute([':h' => $hid]);

    return [
        'hunt' => [
            'id'           => $hid,
            'slug'         => (string)$hunt['slug'],
            'title'        => hunt_pair($hunt, 'title'),
            'rules'        => hunt_pair($hunt, 'rules'),
            'phase'        => $phase,
            'starts_at'    => (int)$hunt['starts_at'],
            'sales_end_at' => (int)$hunt['sales_end_at'],
            'draw_at'      => (int)$hunt['draw_at'],
            'ticket_price' => (int)$hunt['ticket_price'],
            'ticket_limit' => (int)$hunt['ticket_limit'],
            'claim_days'   => (int)$hunt['claim_days'],
            'prizes'       => gw_prizes($hunt['prizes_json'] ?? null),
            'milestones'   => hunt_milestones($hunt['milestones_json'] ?? null),
            'hunters'      => (int)$st->fetchColumn(),
        ],
        'chapters' => $chOut,
        'tasks'    => $tasks,
        'journal'  => $journal,
        'secrets'  => $pageSecrets,
        'winners'  => $winners,
    ];
}

/**
 * Ближайшее действие для индикатора, по важности: won — есть выигрыш, ждёт
 * подтверждения; ticket — хватает на билет; chapter — открылась глава после
 * последнего визита; daily — сегодня ещё ничего не получено; join — ещё не
 * начинал. null — звать некуда.
 */
function hunt_next(array $hunt, array $wallet, array $wins, array $chapters, int $now): ?string {
    foreach ($wins as $w) {
        if ($w['status'] === 'won' && ($w['deadline'] === 0 || $w['deadline'] >= $now)) { return 'won'; }
    }
    if (hunt_phase($hunt, $now) !== 'open') { return null; }
    if ($wallet['first_at'] === 0) { return 'join'; }
    if ($wallet['tickets'] < (int)$hunt['ticket_limit'] && $wallet['balance'] >= (int)$hunt['ticket_price']) { return 'ticket'; }
    foreach ($chapters as $c) {
        $at = (int)$c['opens_at'];
        if ((int)$c['n'] > 1 && $at <= $now && $at > $wallet['last_at']) { return 'chapter'; }
    }
    if ($wallet['last_day'] !== hunt_day($now)) { return 'daily'; }
    return null;
}

/**
 * Личное: кошелёк, что выполнено в текущем периоде, найденные секретки с
 * текстами находок, билеты, выигрыши, есть ли Telegram, ближайшее действие.
 */
function hunt_me(PDO $pdo, array $cfg, string $uid, int $now): array {
    if (!hunt_ready($pdo)) { return ['hunt_id' => null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt) { return ['hunt_id' => null]; }
    $hid = (int)$hunt['id'];
    hunt_expire_wins($pdo, $hid, $now);
    $wallet = hunt_wallet($pdo, $hid, $uid);

    $st = $pdo->prepare('SELECT ref FROM hunt_ledger WHERE hunt_id = :h AND user_id = :u');
    $st->execute([':h' => $hid, ':u' => $uid]);
    $refs = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    $done = [];
    foreach (hunt_tasks($pdo, $hid) as $t) {
        if (isset($refs[hunt_task_ref($t, $now)])) { $done[] = (int)$t['id']; }
    }
    $found = [];
    foreach (hunt_secrets($pdo, $hid) as $s) {
        if (isset($refs[hunt_secret_ref($s, $now)])) {
            $found[] = ['id' => (int)$s['id'], 'page' => (string)$s['page'], 'spot' => (string)$s['spot'],
                        'found' => hunt_pair($s, 'found')];
        }
    }
    $wins = hunt_my_wins($pdo, $hid, $uid);
    return [
        'hunt_id'     => $hid,
        'wallet'      => hunt_wallet_out($wallet),
        'done'        => $done,
        'found'       => $found,
        'tickets'     => hunt_my_tickets($pdo, $hid, $uid),
        'wins'        => $wins,
        'tg'          => hunt_tg_chat($pdo, $uid) !== null,
        'next'        => hunt_next($hunt, $wallet, $wins, hunt_chapters($pdo, $hid), $now),
        'day'         => hunt_day($now),
        'next_day_at' => hunt_next_day_at($now),
    ];
}

/**
 * Поле hunt в api/session.php — для индикатора на каждой странице без
 * второго запроса. null — сезона нет или таблиц нет.
 *
 * Без hunt_ready(): ручку сессии шапка дёргает на каждой странице у каждого
 * вошедшего, и восемь проверочных SELECT на просмотр — лишние. Нет таблиц —
 * первый же запрос бросит PDOException, и это тот же null.
 */
function hunt_session_field(PDO $pdo, array $cfg, string $uid, int $now): ?array {
    try {
        $hunt = hunt_current($pdo, $now);
        if (!$hunt || hunt_phase($hunt, $now) === 'draft') { return null; }
        if (site_banned($uid, $cfg, $now, $pdo)) { return null; }
        $hid = (int)$hunt['id'];
        $wallet = hunt_wallet($pdo, $hid, $uid);
        $next = hunt_next($hunt, $wallet, hunt_my_wins($pdo, $hid, $uid), hunt_chapters($pdo, $hid), $now);
        return hunt_wallet_out($wallet) + ['next' => $next];
    } catch (PDOException $e) {
        return null;
    }
}

// --------------------------------------------------------------------------
//  Счётчики гостей
// --------------------------------------------------------------------------

function hunt_track(PDO $pdo, int $huntId, array $metrics, int $now): int {
    $day = hunt_day($now);
    $n = 0;
    try {
        foreach (array_unique($metrics) as $m) {
            if (!is_string($m) || !in_array($m, HUNT_METRICS, true)) { continue; }
            $up = $pdo->prepare('UPDATE hunt_stats SET n = n + 1 WHERE hunt_id = :h AND day = :d AND metric = :m');
            $up->execute([':h' => $huntId, ':d' => $day, ':m' => $m]);
            if ($up->rowCount() === 0) {
                try {
                    $pdo->prepare('INSERT INTO hunt_stats (hunt_id, day, metric, n) VALUES (?, ?, ?, 1)')
                        ->execute([$huntId, $day, $m]);
                } catch (PDOException $e) {
                    if (!hunt_is_dup($e)) { throw $e; }
                    $up->execute([':h' => $huntId, ':d' => $day, ':m' => $m]);
                }
            }
            $n++;
        }
    } catch (PDOException $e) {
        return $n;
    }
    return $n;
}
