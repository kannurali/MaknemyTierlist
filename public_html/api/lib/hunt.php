<?php
// Хэллоуинский ивент Spooky Tokens: токены за вход на сайт по дням и за время
// на сайте, два титула, билеты лутереи за токены, итоги и выдача призов.
// Устройство и решения владельца — docs/superpowers/specs/2026-10-30-spooky-tokens-design.md.
//
// Честность наград держит журнал hunt_ledger с уникальным ключом
// (hunt_id, user_id, ref). Любая награда — строка журнала: login:3 (вход в
// день 3), hour:3:1 и hour:3:2 (час и два часа в день 3), ticket:1. Повтор из
// второй вкладки, двойной клик, перезагрузка упираются в ключ (SQLSTATE 23000
// — одинаков у MySQL и SQLite) и ничего не начисляют. hunt_wallets — кеш
// журнала, его меняет та же транзакция: balance — что можно потратить,
// earned — всего собрано. earned при тратах не уменьшается: счётчик на сайте
// показывает собранное, покупка билета его не откатывает.
//
// Сезон — 7 суток по Москве от starts_at (полночь по Москве дня 1). В Москве
// нет перевода часов, поэтому день N — это просто intdiv(now − starts_at,
// 86400) + 1. Пропущенный день не догоняется: награда за вход — только за
// сегодняшний номер дня.
//
// Время на сайте копится пульсами вкладки, которая на экране (js/hunt-bar.js,
// раз в минуту). Все вкладки человека пишут в одну строку hunt_time за день:
// сервер засчитывает время с прошлого пульса, не больше минуты, поэтому две
// вкладки дают ту же минуту, а не две. Обновление условное — по прежнему
// beat_at, и два одновременных пульса не засчитают одну минуту дважды.
//
// PHP 7.4: без match и без str_contains.

require_once __DIR__ . '/giveaway.php';
require_once __DIR__ . '/hunt_titles.php';

const HUNT_TZ           = 'Europe/Moscow';
const HUNT_DAYS         = 7;
const HUNT_DAY_SECS     = 86400;
const HUNT_TICKET_LIMIT = 2;
// Сколько секунд за сутки нужно для первой и второй часовой награды.
const HUNT_HOUR_NEED    = [3600, 7200];
// Пульс засчитывает время с прошлого, но не больше минуты с запасом на
// задержку сети; после перерыва дольше двух минут первый пульс только
// ставит отметку. Больше прошедшего времени не засчитывается никогда.
const HUNT_BEAT_MAX     = 65;
const HUNT_BEAT_GAP     = 120;
const HUNT_LOGIN_DEFAULT = [10, 15, 20, 25, 30, 35, 40];
const HUNT_HOURS_DEFAULT = [15, 20];
const HUNT_REWARD_MAX   = 10000;
// redrawn — просроченный выигрыш, приз которого «Перевыбрать» отдал дальше.
const HUNT_WIN_STATUSES = ['won', 'confirmed', 'queued', 'given', 'expired', 'redrawn'];
// Куда админка может перевести выигрыш из каждого статуса.
const HUNT_WIN_MOVES    = [
    'won'       => ['confirmed', 'expired'],
    'confirmed' => ['queued', 'expired'],
    'queued'    => ['given'],
];

// --------------------------------------------------------------------------
//  Таблицы, сезон, дни
// --------------------------------------------------------------------------

function hunt_ready(PDO $pdo): bool {
    try {
        foreach (['hunts', 'hunt_wallets', 'hunt_ledger', 'hunt_time', 'hunt_tickets', 'hunt_wins', 'hunt_titles'] as $t) {
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

// Полночь по Москве того дня, в который попадает $ts.
function hunt_msk_midnight(int $ts): int {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(HUNT_TZ))
        ->setTime(0, 0)->getTimestamp();
}

// Полночь по Москве для даты 'YYYY-MM-DD' или null.
function hunt_date_ts(string $date): ?int {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return null; }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(HUNT_TZ));
    if (!$d || $d->format('Y-m-d') !== $date) { return null; }
    return $d->getTimestamp();
}

function hunt_ts_date(int $ts): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone(HUNT_TZ))->format('Y-m-d');
}

// Когда по Москве начнётся следующий день — для «обновится через 5 ч».
function hunt_next_day_at(int $now): int {
    return (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(HUNT_TZ))
        ->modify('tomorrow')->getTimestamp();
}

function hunt_load(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM hunts WHERE id = :id');
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Сезон, который видит сайт: идущий (в том числе ещё не начавшийся —
// страница показывает «скоро»), а если такого нет — последний разыгранный.
function hunt_current(PDO $pdo, int $now): ?array {
    $row = $pdo->query("SELECT * FROM hunts WHERE status IN ('live', 'drawn')
        ORDER BY CASE WHEN status = 'live' THEN 0 ELSE 1 END, starts_at DESC, id DESC LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hunt_ends_at(array $hunt): int {
    return (int)$hunt['starts_at'] + HUNT_DAYS * HUNT_DAY_SECS;
}

// draft — черновик; soon — опубликован, не начался; open — идут 7 дней,
// токены копятся, билеты продаются; sales — дни кончились, билеты ещё
// продаются; closed — ждём итогов; drawn — итоги есть.
function hunt_phase(array $hunt, int $now): string {
    $status = (string)$hunt['status'];
    if ($status === 'drawn') { return 'drawn'; }
    if ($status !== 'live') { return 'draft'; }
    if ($now < (int)$hunt['starts_at']) { return 'soon'; }
    if ($now < hunt_ends_at($hunt)) { return 'open'; }
    if ($now < (int)$hunt['sales_end_at']) { return 'sales'; }
    return 'closed';
}

// Номер дня ивента, 1…7, или 0 вне семи дней.
function hunt_day_n(array $hunt, int $now): int {
    $start = (int)$hunt['starts_at'];
    if ($now < $start || $now >= hunt_ends_at($hunt)) { return 0; }
    return intdiv($now - $start, HUNT_DAY_SECS) + 1;
}

// Список из $n наград. Кривое содержимое — значения по умолчанию.
function hunt_ints($json, int $n, array $default): array {
    $v = hunt_json($json);
    if (count($v) !== $n) { return $default; }
    $out = [];
    foreach ($v as $x) {
        if (!is_int($x) || $x < 0 || $x > HUNT_REWARD_MAX) { return $default; }
        $out[] = $x;
    }
    return $out;
}

function hunt_login_rewards(array $hunt): array {
    return hunt_ints($hunt['login_json'] ?? null, HUNT_DAYS, HUNT_LOGIN_DEFAULT);
}

function hunt_hour_rewards(array $hunt): array {
    return hunt_ints($hunt['hours_json'] ?? null, count(HUNT_HOUR_NEED), HUNT_HOURS_DEFAULT);
}

// Сколько всего можно собрать за ивент.
function hunt_cap(array $hunt): int {
    return array_sum(hunt_login_rewards($hunt)) + HUNT_DAYS * array_sum(hunt_hour_rewards($hunt));
}

// Цена билета — половина всех токенов: два билета — это всё, что можно собрать.
function hunt_price(array $hunt): int {
    return max(1, intdiv(hunt_cap($hunt), HUNT_TICKET_LIMIT));
}

function hunt_pair(array $row, string $key): array {
    return ['ru' => (string)($row[$key . '_ru'] ?? ''), 'en' => (string)($row[$key . '_en'] ?? '')];
}

function hunt_fail(int $code, string $status, array $extra = []): array {
    return [$code, ['ok' => false, 'status' => $status] + $extra];
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
    $st = $pdo->prepare('SELECT balance, earned, tickets, first_at, last_at FROM hunt_wallets WHERE hunt_id = :h AND user_id = :u');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['balance' => 0, 'earned' => 0, 'tickets' => 0, 'first_at' => 0, 'last_at' => 0];
    }
    return [
        'balance'  => (int)$row['balance'],
        'earned'   => (int)$row['earned'],
        'tickets'  => (int)$row['tickets'],
        'first_at' => (int)$row['first_at'],
        'last_at'  => (int)$row['last_at'],
    ];
}

// То, что из кошелька видит страница.
function hunt_wallet_out(array $w): array {
    return ['balance' => $w['balance'], 'earned' => $w['earned'], 'tickets' => $w['tickets']];
}

function hunt_wallet_ensure(PDO $pdo, int $huntId, string $uid, int $now): void {
    try {
        $pdo->prepare('INSERT INTO hunt_wallets (hunt_id, user_id, first_at) VALUES (?, ?, ?)')
            ->execute([$huntId, $uid, $now]);
    } catch (PDOException $e) {
        if (!hunt_is_dup($e)) { throw $e; }
    }
}

function hunt_refs(PDO $pdo, int $huntId, string $uid): array {
    $st = $pdo->prepare('SELECT ref FROM hunt_ledger WHERE hunt_id = :h AND user_id = :u');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    return array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));
}

function hunt_has_ref(PDO $pdo, int $huntId, string $uid, string $ref): bool {
    $st = $pdo->prepare('SELECT 1 FROM hunt_ledger WHERE hunt_id = :h AND user_id = :u AND ref = :r');
    $st->execute([':h' => $huntId, ':u' => $uid, ':r' => $ref]);
    return $st->fetchColumn() !== false;
}

// Какой титул даёт награда этого вида и сколько таких наград за ивент.
function hunt_title_rule(string $kind): ?array {
    if ($kind === 'login') { return ['login', HUNT_DAYS]; }
    if ($kind === 'hour') { return ['hours', HUNT_DAYS * count(HUNT_HOUR_NEED)]; }
    return null;
}

/**
 * Начислить токены. ['status' => 'done'|'already', 'wallet' => …,
 * 'title' => 'login'|'hours'|null]: already — этот ref у человека уже есть,
 * ничего не изменилось; title — эта награда была последней в своём виде и
 * открыла титул. Строка титула пишется в той же транзакции.
 */
function hunt_award(PDO $pdo, int $huntId, string $uid, string $kind, string $ref, int $delta, int $now): array {
    hunt_wallet_ensure($pdo, $huntId, $uid, $now);
    $title = null;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO hunt_ledger (hunt_id, user_id, delta, kind, ref, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$huntId, $uid, $delta, $kind, $ref, $now]);
        $pdo->prepare('UPDATE hunt_wallets SET balance = balance + :d, earned = earned + :e, last_at = :now
                        WHERE hunt_id = :h AND user_id = :u')
            ->execute([':d' => $delta, ':e' => max(0, $delta), ':now' => $now, ':h' => $huntId, ':u' => $uid]);
        $rule = hunt_title_rule($kind);
        if ($rule) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM hunt_ledger WHERE hunt_id = :h AND user_id = :u AND kind = :k');
            $st->execute([':h' => $huntId, ':u' => $uid, ':k' => $kind]);
            if ((int)$st->fetchColumn() >= $rule[1]) {
                try {
                    $pdo->prepare('INSERT INTO hunt_titles (user_id, hunt_id, kind, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$uid, $huntId, $rule[0], $now]);
                    $title = $rule[0];
                } catch (PDOException $e) {
                    if (!hunt_is_dup($e)) { throw $e; }
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        if (!hunt_is_dup($e)) { throw $e; }
        return ['status' => 'already', 'wallet' => hunt_wallet_out(hunt_wallet($pdo, $huntId, $uid)), 'title' => null];
    }
    return ['status' => 'done', 'wallet' => hunt_wallet_out(hunt_wallet($pdo, $huntId, $uid)), 'title' => $title];
}

function hunt_title_out(array $hunt, string $kind): array {
    return ['kind' => $kind, 'title' => hunt_titles_cfg($hunt)[$kind]];
}

/**
 * Общие проверки любого действия участника. [ошибка, сезон]: ошибка —
 * готовый ответ [код, тело] или null, тогда действие можно выполнять.
 * $phases — в каких фазах сезона действие разрешено.
 */
function hunt_gate(PDO $pdo, array $cfg, string $uid, int $now, array $phases): array {
    if ($uid === '') { return [hunt_fail(401, 'login'), null]; }
    if (!hunt_ready($pdo)) { return [hunt_fail(503, 'closed'), null]; }
    if (site_banned($uid, $cfg, $now, $pdo)) { return [hunt_fail(403, 'banned'), null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt || !in_array(hunt_phase($hunt, $now), $phases, true)) { return [hunt_fail(409, 'closed'), null]; }
    return [null, $hunt];
}

// --------------------------------------------------------------------------
//  Награды: вход и время
// --------------------------------------------------------------------------

/**
 * Награда за вход в сегодняшний день ивента. Зовётся из api/session.php —
 * его шапка дёргает на каждой странице, своего запроса не нужно.
 * ['gains' => [...], 'titles_new' => [...]] — пусто, если сегодня уже
 * получено или день не идёт.
 */
function hunt_login_award(PDO $pdo, array $hunt, string $uid, int $now): array {
    $out = ['gains' => [], 'titles_new' => []];
    $n = hunt_day_n($hunt, $now);
    if ($n === 0 || hunt_phase($hunt, $now) !== 'open') { return $out; }
    $hid = (int)$hunt['id'];
    $ref = 'login:' . $n;
    if (hunt_has_ref($pdo, $hid, $uid, $ref)) { return $out; }
    $delta = hunt_login_rewards($hunt)[$n - 1];
    $a = hunt_award($pdo, $hid, $uid, 'login', $ref, $delta, $now);
    if ($a['status'] === 'done') {
        $out['gains'][] = ['kind' => 'login', 'n' => $n, 'delta' => $delta];
        if ($a['title']) { $out['titles_new'][] = hunt_title_out($hunt, $a['title']); }
    }
    return $out;
}

function hunt_time_row(PDO $pdo, int $huntId, string $uid, int $day): ?array {
    $st = $pdo->prepare('SELECT secs, beat_at FROM hunt_time WHERE hunt_id = :h AND user_id = :u AND day = :d');
    $st->execute([':h' => $huntId, ':u' => $uid, ':d' => $day]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? ['secs' => (int)$row['secs'], 'beat_at' => (int)$row['beat_at']] : null;
}

function hunt_full_secs(): int {
    return HUNT_HOUR_NEED[count(HUNT_HOUR_NEED) - 1];
}

/**
 * Пульс вкладки, которая на экране. [код, тело]; тело — {ok, status, day,
 * secs, full, gains, titles_new, wallet}. status: done | full (за сутки
 * набрано всё, пульсы до завтра не нужны) | closed | login | banned.
 *
 * Время с прошлого пульса засчитывается, только если он был не раньше
 * HUNT_BEAT_GAP назад, и не больше HUNT_BEAT_MAX. Строка обновляется
 * условно, по прежнему beat_at: из двух одновременных пульсов засчитывается
 * один, второй отвечает тем, что уже записано.
 */
function hunt_beat(PDO $pdo, array $cfg, string $uid, int $now): array {
    [$err, $hunt] = hunt_gate($pdo, $cfg, $uid, $now, ['open']);
    if ($err) { return $err; }
    $hid = (int)$hunt['id'];
    $day = hunt_day_n($hunt, $now);
    $full = hunt_full_secs();
    $gains = [];
    $titles = [];
    $reply = function (int $secs) use ($pdo, $hid, $uid, $day, $full, &$gains, &$titles): array {
        return [200, ['ok' => true, 'status' => $secs >= $full ? 'full' : 'done', 'day' => $day, 'secs' => $secs,
            'full' => $secs >= $full, 'gains' => $gains, 'titles_new' => $titles,
            'wallet' => hunt_wallet_out(hunt_wallet($pdo, $hid, $uid))]];
    };

    $row = hunt_time_row($pdo, $hid, $uid, $day);
    if ($row === null) {
        try {
            $pdo->prepare('INSERT INTO hunt_time (hunt_id, user_id, day, secs, beat_at) VALUES (?, ?, ?, 0, ?)')
                ->execute([$hid, $uid, $day, $now]);
        } catch (PDOException $e) {
            if (!hunt_is_dup($e)) { throw $e; }
        }
        return $reply(0);
    }
    $old = $row['secs'];
    if ($old >= $full) { return $reply($old); }
    $gap = $now - $row['beat_at'];
    $credit = $gap > 0 && $gap <= HUNT_BEAT_GAP ? min($gap, HUNT_BEAT_MAX) : 0;
    $new = min($full, $old + $credit);
    $up = $pdo->prepare('UPDATE hunt_time SET secs = :s, beat_at = :now
                          WHERE hunt_id = :h AND user_id = :u AND day = :d AND beat_at = :was');
    $up->execute([':s' => $new, ':now' => $now, ':h' => $hid, ':u' => $uid, ':d' => $day, ':was' => $row['beat_at']]);
    if ($up->rowCount() !== 1) {
        $row = hunt_time_row($pdo, $hid, $uid, $day);
        return $reply($row ? $row['secs'] : $old);
    }

    if ($new >= HUNT_HOUR_NEED[0]) {
        $refs = hunt_refs($pdo, $hid, $uid);
        $rewards = hunt_hour_rewards($hunt);
        foreach (HUNT_HOUR_NEED as $i => $need) {
            $ref = 'hour:' . $day . ':' . ($i + 1);
            if ($new < $need || isset($refs[$ref])) { continue; }
            $a = hunt_award($pdo, $hid, $uid, 'hour', $ref, $rewards[$i], $now);
            if ($a['status'] !== 'done') { continue; }
            $gains[] = ['kind' => 'hour', 'n' => $day, 'k' => $i + 1, 'delta' => $rewards[$i]];
            if ($a['title']) { $titles[] = hunt_title_out($hunt, $a['title']); }
        }
    }
    return $reply($new);
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
 * Обменять токены на билет. $seq — каким по счёту должен стать новый билет
 * (у человека было tickets, значит tickets + 1). Повтор того же запроса —
 * перезагрузка, двойной клик — отвечает already уже купленным билетом.
 * stale — страница отстала (билет купили в другой вкладке): она перечитает
 * кошелёк.
 *
 * Списание — условный UPDATE (balance >= цена И tickets = сколько было):
 * без FOR UPDATE, который SQLite не знает, две одновременные покупки не
 * уведут баланс в минус и не превысят лимит; второй барьер — уникальный
 * (hunt_id, user_id, seq) у билета.
 */
function hunt_buy_ticket(PDO $pdo, array $cfg, string $uid, int $seq, int $now): array {
    [$err, $hunt] = hunt_gate($pdo, $cfg, $uid, $now, ['open', 'sales']);
    if ($err) { return $err; }
    $hid = (int)$hunt['id'];
    $price = hunt_price($hunt);
    $limit = HUNT_TICKET_LIMIT;
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

// Админка аннулирует билет (спорный случай). Токены не возвращаются:
// аннулированный билет просто не участвует в итогах.
function hunt_void_ticket(PDO $pdo, int $ticketId, string $reason): array {
    $reason = mb_substr(trim(preg_replace('/\s+/u', ' ', $reason) ?? ''), 0, 160);
    $st = $pdo->prepare('UPDATE hunt_tickets SET void = 1, void_reason = :r WHERE id = :id AND void = 0');
    $st->execute([':r' => $reason, ':id' => $ticketId]);
    if ($st->rowCount() !== 1) { return hunt_fail(404, 'bad'); }
    return [200, ['ok' => true]];
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
 * Кнопка «Разыграть». Можно только после конца продажи билетов и только раз.
 * Билеты без пометки void, чьи владельцы не забанены, перемешиваются
 * (Фишер—Йетс на $rand, по умолчанию random_int); каждый получает место в
 * очереди draw_pos. Призы по порядку получает первый билет, чей владелец
 * ещё ничего не выиграл: один человек — один приз, два билета — два шанса.
 * Очередь остаётся в базе — по ней «Перевыбрать» отдаёт приз неявившегося
 * следующему.
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
    $deadline = $now + max(1, (int)$hunt['claim_days']) * HUNT_DAY_SECS;

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

/**
 * Итоги сами: первый запрос после draw_at (страница, счётчик, сессия)
 * разыгрывает призы — cron нет, как у розыгрышей. Кнопка в админке остаётся,
 * чтобы разыграть раньше. Гонку двух запросов разводит условный UPDATE в
 * hunt_draw. Ошибка розыгрыша не роняет страницу: сезон остаётся live, и
 * следующий запрос попробует снова. → [сезон после, разыграл ли этот запрос].
 */
function hunt_auto_draw(PDO $pdo, array $cfg, array $hunt, int $now): array {
    $at = (int)$hunt['draw_at'];
    if ((string)$hunt['status'] !== 'live' || $at <= 0 || $now < $at || $now < (int)$hunt['sales_end_at']) {
        return [$hunt, false];
    }
    try {
        [$code] = hunt_draw($pdo, $cfg, (int)$hunt['id'], $now);
    } catch (Throwable $e) {
        error_log('hunt auto draw: ' . $e->getMessage());
        return [$hunt, false];
    }
    return [hunt_load($pdo, (int)$hunt['id']) ?? $hunt, $code === 200];
}

// Ответ api/hunt.php лежит в кеше LiteSpeed: итоги, подведённые другим
// запросом, сбрасывают его, чтобы страница не ждала конца кеша.
function hunt_purge_public(): void {
    if (!defined('TESTING') && !headers_sent()) { header('X-LiteSpeed-Purge: tag=hunt'); }
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
        $deadline = $now + max(1, (int)$hunt['claim_days']) * HUNT_DAY_SECS;
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
//  Титулы
// --------------------------------------------------------------------------

function hunt_my_titles(PDO $pdo, string $uid): array {
    return hunt_titles_for($pdo, [$uid])[$uid] ?? [];
}

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

/**
 * Публичное — одно для всех, поэтому лежит в кеше LiteSpeed (api/hunt.php).
 * После draw_at сначала подводит итоги (hunt_auto_draw). winners — в порядке
 * призов, с номером билета: страница вытягивает их по одному. tickets —
 * сколько билетов в барабане (без аннулированных).
 */
function hunt_public(PDO $pdo, int $now, array $cfg = []): array {
    if (!hunt_ready($pdo)) { return ['hunt' => null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt) { return ['hunt' => null]; }
    [$hunt] = hunt_auto_draw($pdo, $cfg, $hunt, $now);
    $hid = (int)$hunt['id'];
    $phase = hunt_phase($hunt, $now);

    $winners = [];
    if ($phase === 'drawn') {
        $st = $pdo->prepare("SELECT w.prize_idx, w.prize_name, w.prize_icon, w.user_id, w.ticket_id, u.username, u.display_name
                               FROM hunt_wins w LEFT JOIN users u ON u.roblox_id = w.user_id
                              WHERE w.hunt_id = :h AND w.status NOT IN ('expired', 'redrawn') ORDER BY w.prize_idx, w.id");
        $st->execute([':h' => $hid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
            $winners[] = ['prize' => ['idx' => (int)$w['prize_idx'], 'name' => (string)$w['prize_name'], 'icon' => (string)$w['prize_icon']],
                'ticket_no' => hunt_ticket_no((int)$w['ticket_id']),
                'user' => ['id' => (string)$w['user_id'], 'username' => (string)($w['username'] ?? ''),
                           'display_name' => (string)($w['display_name'] ?? '')]];
        }
    }

    $tk = $pdo->prepare('SELECT COUNT(*) FROM hunt_tickets WHERE hunt_id = :h AND void = 0');
    $tk->execute([':h' => $hid]);
    $tickets = (int)$tk->fetchColumn();
    $st = $pdo->prepare('SELECT COUNT(*) FROM hunt_wallets WHERE hunt_id = :h');
    $st->execute([':h' => $hid]);

    return [
        'hunt' => [
            'id'           => $hid,
            'title'        => hunt_pair($hunt, 'title'),
            'rules'        => hunt_pair($hunt, 'rules'),
            'phase'        => $phase,
            'starts_at'    => (int)$hunt['starts_at'],
            'ends_at'      => hunt_ends_at($hunt),
            'sales_end_at' => (int)$hunt['sales_end_at'],
            'draw_at'      => (int)$hunt['draw_at'],
            'drawn_at'     => (int)($hunt['drawn_at'] ?? 0),
            'day'          => hunt_day_n($hunt, $now),
            'days'         => HUNT_DAYS,
            'login'        => hunt_login_rewards($hunt),
            'hours'        => hunt_hour_rewards($hunt),
            'cap'          => hunt_cap($hunt),
            'price'        => hunt_price($hunt),
            'limit'        => HUNT_TICKET_LIMIT,
            'prizes'       => gw_prizes($hunt['prizes_json'] ?? null),
            'titles'       => hunt_titles_cfg($hunt),
            'hunters'      => (int)$st->fetchColumn(),
            'tickets'      => $tickets,
        ],
        'winners' => $winners,
    ];
}

// Ближайшее, куда стоит позвать: won — есть выигрыш, ждёт подтверждения;
// ticket — хватает на билет. null — звать некуда.
function hunt_next(array $hunt, array $wallet, array $wins, int $now): ?string {
    foreach ($wins as $w) {
        if ($w['status'] === 'won' && ($w['deadline'] === 0 || $w['deadline'] >= $now)) { return 'won'; }
    }
    if (!in_array(hunt_phase($hunt, $now), ['open', 'sales'], true)) { return null; }
    if ($wallet['tickets'] < HUNT_TICKET_LIMIT && $wallet['balance'] >= hunt_price($hunt)) { return 'ticket'; }
    return null;
}

// Секунды по дням ивента: [день => secs].
function hunt_my_time(PDO $pdo, int $huntId, string $uid): array {
    $st = $pdo->prepare('SELECT day, secs FROM hunt_time WHERE hunt_id = :h AND user_id = :u');
    $st->execute([':h' => $huntId, ':u' => $uid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $out[(int)$r['day']] = (int)$r['secs']; }
    return $out;
}

/**
 * Календарь семи дней: что получено за вход и за часы. login.state —
 * got | missed (день прошёл без входа) | today (сегодня ещё не получено) |
 * future.
 */
function hunt_calendar(array $hunt, array $refs, array $time, int $now): array {
    $today = hunt_day_n($hunt, $now);
    $over = $now >= hunt_ends_at($hunt);
    $login = hunt_login_rewards($hunt);
    $hours = hunt_hour_rewards($hunt);
    $days = [];
    for ($n = 1; $n <= HUNT_DAYS; $n++) {
        $got = isset($refs['login:' . $n]);
        if ($got) { $state = 'got'; }
        elseif ($n === $today) { $state = 'today'; }
        elseif ($over || ($today > 0 && $n < $today)) { $state = 'missed'; }
        else { $state = 'future'; }
        $h = [];
        foreach (HUNT_HOUR_NEED as $i => $need) {
            $h[] = ['need' => $need, 'reward' => $hours[$i], 'got' => isset($refs['hour:' . $n . ':' . ($i + 1)])];
        }
        $days[] = ['n' => $n, 'login' => ['reward' => $login[$n - 1], 'state' => $state], 'hours' => $h,
                   'secs' => $time[$n] ?? 0];
    }
    return $days;
}

/**
 * Личное: кошелёк, календарь, сегодняшнее время, билеты, выигрыши, титулы,
 * есть ли Telegram.
 */
function hunt_me(PDO $pdo, array $cfg, string $uid, int $now): array {
    if (!hunt_ready($pdo)) { return ['hunt_id' => null]; }
    $hunt = hunt_current($pdo, $now);
    if (!$hunt) { return ['hunt_id' => null]; }
    [$hunt, $drawn] = hunt_auto_draw($pdo, $cfg, $hunt, $now);
    if ($drawn) { hunt_purge_public(); }
    $hid = (int)$hunt['id'];
    hunt_expire_wins($pdo, $hid, $now);
    $wallet = hunt_wallet($pdo, $hid, $uid);
    $time = hunt_my_time($pdo, $hid, $uid);
    $day = hunt_day_n($hunt, $now);
    $secs = $day > 0 ? ($time[$day] ?? 0) : 0;
    return [
        'hunt_id'     => $hid,
        'wallet'      => hunt_wallet_out($wallet),
        'day'         => $day,
        'today'       => ['secs' => $secs, 'full' => $secs >= hunt_full_secs()],
        'days'        => hunt_calendar($hunt, hunt_refs($pdo, $hid, $uid), $time, $now),
        'tickets'     => hunt_my_tickets($pdo, $hid, $uid),
        'wins'        => hunt_my_wins($pdo, $hid, $uid),
        'titles'      => hunt_my_titles($pdo, $uid),
        'tg'          => hunt_tg_chat($pdo, $uid) !== null,
        'next_day_at' => hunt_next_day_at($now),
    ];
}

/**
 * Поле hunt в api/session.php — для счётчика на каждой странице без
 * второго запроса. Здесь же начисляется награда за вход в сегодняшний день:
 * gains и titles_new — что начислил именно этот запрос. null — сезона нет,
 * черновик, забанен или таблиц нет.
 *
 * Без hunt_ready(): ручку сессии шапка дёргает на каждой странице у каждого
 * вошедшего, и семь проверочных SELECT на просмотр — лишние. Нет таблиц —
 * первый же запрос бросит PDOException, и это тот же null.
 */
function hunt_session_field(PDO $pdo, array $cfg, string $uid, int $now): ?array {
    try {
        $hunt = hunt_current($pdo, $now);
        if (!$hunt || hunt_phase($hunt, $now) === 'draft') { return null; }
        if (site_banned($uid, $cfg, $now, $pdo)) { return null; }
        [$hunt, $drawn] = hunt_auto_draw($pdo, $cfg, $hunt, $now);
        if ($drawn) { hunt_purge_public(); }
        $hid = (int)$hunt['id'];
        $award = hunt_login_award($pdo, $hunt, $uid, $now);
        $wallet = hunt_wallet($pdo, $hid, $uid);
        $day = hunt_day_n($hunt, $now);
        $secs = 0;
        if ($day > 0) {
            $row = hunt_time_row($pdo, $hid, $uid, $day);
            $secs = $row ? $row['secs'] : 0;
        }
        return [
            'phase'      => hunt_phase($hunt, $now),
            'day'        => $day,
            'earned'     => $wallet['earned'],
            'balance'    => $wallet['balance'],
            'tickets'    => $wallet['tickets'],
            'cap'        => hunt_cap($hunt),
            'price'      => hunt_price($hunt),
            'limit'      => HUNT_TICKET_LIMIT,
            'secs'       => $secs,
            'full'       => $secs >= hunt_full_secs(),
            'gains'      => $award['gains'],
            'titles_new' => $award['titles_new'],
            'next'       => hunt_next($hunt, $wallet, hunt_my_wins($pdo, $hid, $uid), $now),
        ];
    } catch (PDOException $e) {
        return null;
    }
}

// --------------------------------------------------------------------------
//  Админка
// --------------------------------------------------------------------------

// Сезон, который правит админка: последний заведённый.
function hunt_admin_current(PDO $pdo): ?array {
    $row = $pdo->query('SELECT * FROM hunts ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function hunt_admin_out(array $hunt, int $now): array {
    return [
        'id'           => (int)$hunt['id'],
        'status'       => (string)$hunt['status'],
        'phase'        => hunt_phase($hunt, $now),
        'title_ru'     => (string)$hunt['title_ru'],
        'title_en'     => (string)$hunt['title_en'],
        'rules_ru'     => (string)($hunt['rules_ru'] ?? ''),
        'rules_en'     => (string)($hunt['rules_en'] ?? ''),
        'start_date'   => hunt_ts_date((int)$hunt['starts_at']),
        'starts_at'    => (int)$hunt['starts_at'],
        'ends_at'      => hunt_ends_at($hunt),
        'sales_end_at' => (int)$hunt['sales_end_at'],
        'draw_at'      => (int)$hunt['draw_at'],
        'login'        => hunt_login_rewards($hunt),
        'hours'        => hunt_hour_rewards($hunt),
        'cap'          => hunt_cap($hunt),
        'price'        => hunt_price($hunt),
        'prizes'       => gw_prizes($hunt['prizes_json'] ?? null),
        'claim_days'   => (int)$hunt['claim_days'],
        'titles'       => hunt_titles_cfg($hunt),
        'drawn_at'     => (int)$hunt['drawn_at'],
    ];
}

/**
 * Всё для /admin/hunt: сезон, сводка, участники с билетами, выигрыши.
 */
function hunt_admin_state(PDO $pdo, int $now): array {
    if (!hunt_ready($pdo)) { return ['ok' => false, 'error' => 'not_ready']; }
    $hunt = hunt_admin_current($pdo);
    if (!$hunt) { return ['ok' => true, 'hunt' => null, 'defaults' => hunt_admin_defaults($now)]; }
    $hid = (int)$hunt['id'];
    hunt_expire_wins($pdo, $hid, $now);

    $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(earned), 0) AS e FROM hunt_wallets WHERE hunt_id = :h');
    $st->execute([':h' => $hid]);
    $sum = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare('SELECT kind, COUNT(*) AS n FROM hunt_titles WHERE hunt_id = :h GROUP BY kind');
    $st->execute([':h' => $hid]);
    $titles = ['login' => 0, 'hours' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $titles[(string)$r['kind']] = (int)$r['n']; }
    $day = hunt_day_n($hunt, $now);
    $today = 0;
    if ($day > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM hunt_ledger WHERE hunt_id = :h AND ref = :r');
        $st->execute([':h' => $hid, ':r' => 'login:' . $day]);
        $today = (int)$st->fetchColumn();
    }

    $st = $pdo->prepare('SELECT w.user_id, w.balance, w.earned, w.tickets, w.first_at, w.last_at, u.username, u.display_name
                           FROM hunt_wallets w LEFT JOIN users u ON u.roblox_id = w.user_id
                          WHERE w.hunt_id = :h ORDER BY w.earned DESC, w.first_at LIMIT 300');
    $st->execute([':h' => $hid]);
    $people = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $people[(string)$r['user_id']] = [
            'id' => (string)$r['user_id'], 'username' => (string)($r['username'] ?? ''),
            'display_name' => (string)($r['display_name'] ?? ''),
            'balance' => (int)$r['balance'], 'earned' => (int)$r['earned'], 'tickets' => [],
            'first_at' => (int)$r['first_at'], 'last_at' => (int)$r['last_at'],
        ];
    }
    $st = $pdo->prepare('SELECT * FROM hunt_tickets WHERE hunt_id = :h ORDER BY id');
    $st->execute([':h' => $hid]);
    $ticketsAll = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        if ((int)$t['void'] === 0) { $ticketsAll++; }
        $uid = (string)$t['user_id'];
        if (isset($people[$uid])) {
            $people[$uid]['tickets'][] = hunt_ticket_out($t) + ['void_reason' => (string)$t['void_reason']];
        }
    }

    $st = $pdo->prepare('SELECT w.*, u.username, u.display_name FROM hunt_wins w
                           LEFT JOIN users u ON u.roblox_id = w.user_id WHERE w.hunt_id = :h ORDER BY w.prize_idx, w.id');
    $st->execute([':h' => $hid]);
    $wins = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) {
        $wins[] = hunt_win_out($w) + [
            'raw_status' => (string)$w['status'], 'note' => (string)$w['note'],
            'user' => ['id' => (string)$w['user_id'], 'username' => (string)($w['username'] ?? ''),
                       'display_name' => (string)($w['display_name'] ?? '')],
            'tg' => hunt_tg_chat($pdo, (string)$w['user_id']) !== null,
        ];
    }

    return [
        'ok'     => true,
        'hunt'   => hunt_admin_out($hunt, $now),
        'stats'  => ['hunters' => (int)$sum['n'], 'earned' => (int)$sum['e'], 'tickets' => $ticketsAll,
                     'titles' => $titles, 'day' => $day, 'logins_today' => $today],
        'people' => array_values($people),
        'wins'   => $wins,
    ];
}

// Что предложить в пустой форме: старт завтра, продажа билетов — сутки
// после семи дней, итоги — через час после неё.
function hunt_admin_defaults(int $now): array {
    $start = hunt_next_day_at($now);
    $sales = $start + (HUNT_DAYS + 1) * HUNT_DAY_SECS;
    return [
        'title_ru' => 'Spooky Tokens', 'title_en' => 'Spooky Tokens',
        'rules_ru' => '', 'rules_en' => '',
        'start_date' => hunt_ts_date($start), 'sales_end_at' => $sales, 'draw_at' => $sales + 3600,
        'login' => HUNT_LOGIN_DEFAULT, 'hours' => HUNT_HOURS_DEFAULT,
        'prizes' => [['name' => 'Gas', 'count' => 1, 'icon' => '']], 'claim_days' => 7,
        'titles' => HUNT_TITLES_DEFAULT, 'status' => 'draft',
    ];
}

function hunt_clean_text($v, int $max): ?string {
    if ($v === null) { return ''; }
    if (!is_string($v)) { return null; }
    $v = trim(str_replace("\r\n", "\n", $v));
    return mb_strlen($v) <= $max ? $v : null;
}

function hunt_clean_ints($v, int $n): ?array {
    if (!is_array($v) || count($v) !== $n) { return null; }
    $out = [];
    foreach (array_values($v) as $x) {
        if (is_string($x) && $x !== '' && ctype_digit($x)) { $x = (int)$x; }
        if (!is_int($x) || $x < 0 || $x > HUNT_REWARD_MAX) { return null; }
        $out[] = $x;
    }
    return $out;
}

function hunt_clean_ts($v): ?int {
    if (is_string($v) && $v !== '' && ctype_digit($v)) { $v = (int)$v; }
    return is_int($v) && $v >= 0 ? $v : null;
}

/**
 * Сохранить сезон из формы админки. Сезон один: есть — правится, нет —
 * заводится. После старта не меняются дата начала и награды: у людей уже
 * собраны токены по старым числам, и цена билета от них зависит. Разыгранный
 * сезон не правится вовсе.
 */
function hunt_admin_save(PDO $pdo, array $in, int $now): array {
    $title_ru = hunt_clean_text($in['title_ru'] ?? '', 120);
    $title_en = hunt_clean_text($in['title_en'] ?? '', 120);
    $rules_ru = hunt_clean_text($in['rules_ru'] ?? '', 4000);
    $rules_en = hunt_clean_text($in['rules_en'] ?? '', 4000);
    $start = is_string($in['start_date'] ?? null) ? hunt_date_ts($in['start_date']) : null;
    $sales = hunt_clean_ts($in['sales_end_at'] ?? null);
    $draw = hunt_clean_ts($in['draw_at'] ?? 0);
    $login = hunt_clean_ints($in['login'] ?? null, HUNT_DAYS);
    $hours = hunt_clean_ints($in['hours'] ?? null, count(HUNT_HOUR_NEED));
    $claim = $in['claim_days'] ?? 7;
    if (is_string($claim) && ctype_digit($claim)) { $claim = (int)$claim; }
    $status = $in['status'] ?? 'draft';
    if ($title_ru === null || $title_en === null || $rules_ru === null || $rules_en === null) { return hunt_fail(400, 'bad_text'); }
    if ($start === null) { return hunt_fail(400, 'bad_start'); }
    if ($login === null || $hours === null) { return hunt_fail(400, 'bad_rewards'); }
    if (!is_int($claim) || $claim < 1 || $claim > 60) { return hunt_fail(400, 'bad_claim'); }
    if (!in_array($status, ['draft', 'live'], true)) { return hunt_fail(400, 'bad_status'); }
    $ends = $start + HUNT_DAYS * HUNT_DAY_SECS;
    if ($sales === null || $sales < $ends) { return hunt_fail(400, 'bad_sales'); }
    if ($draw === null || ($draw !== 0 && $draw < $sales)) { return hunt_fail(400, 'bad_draw'); }

    $prizes = [];
    foreach (is_array($in['prizes'] ?? null) ? $in['prizes'] : [] as $p) {
        if (!is_array($p)) { return hunt_fail(400, 'bad_prizes'); }
        $name = hunt_clean_text($p['name'] ?? '', 120);
        $count = $p['count'] ?? 1;
        if (is_string($count) && ctype_digit($count)) { $count = (int)$count; }
        $icon = gw_prize_icon($p['icon'] ?? '');
        if ($name === null || $name === '' || !is_int($count) || $count < 1 || $count > 100 || $icon === null) {
            return hunt_fail(400, 'bad_prizes');
        }
        $prizes[] = ['name' => $name, 'count' => $count, 'icon' => $icon];
    }
    if (!$prizes || count($prizes) > 10) { return hunt_fail(400, 'bad_prizes'); }

    $titles = [];
    $tin = is_array($in['titles'] ?? null) ? $in['titles'] : [];
    foreach (HUNT_TITLE_KINDS as $k) {
        $ru = hunt_clean_text($tin[$k]['ru'] ?? '', 40);
        $en = hunt_clean_text($tin[$k]['en'] ?? '', 40);
        if ($ru === null || $en === null || $ru === '' || $en === '') { return hunt_fail(400, 'bad_titles'); }
        $titles[$k] = ['ru' => $ru, 'en' => $en];
    }

    $row = [
        'title_ru' => $title_ru, 'title_en' => $title_en, 'rules_ru' => $rules_ru, 'rules_en' => $rules_en,
        'starts_at' => $start, 'sales_end_at' => $sales, 'draw_at' => $draw,
        'login_json' => json_encode($login), 'hours_json' => json_encode($hours),
        'prizes_json' => json_encode($prizes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'claim_days' => $claim,
        'titles_json' => json_encode($titles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'status' => $status, 'updated_at' => $now,
    ];

    $cur = hunt_admin_current($pdo);
    if ($cur && (string)$cur['status'] === 'drawn') { return hunt_fail(409, 'drawn'); }
    if ($cur && hunt_phase($cur, $now) !== 'draft' && hunt_phase($cur, $now) !== 'soon') {
        if ($start !== (int)$cur['starts_at'] || $login !== hunt_login_rewards($cur) || $hours !== hunt_hour_rewards($cur)) {
            return hunt_fail(409, 'locked');
        }
        if ($status !== 'live') { return hunt_fail(409, 'locked'); }
    }

    if ($cur) {
        $set = implode(', ', array_map(function ($k) { return "$k = :$k"; }, array_keys($row)));
        $st = $pdo->prepare("UPDATE hunts SET $set WHERE id = :id");
        $params = [':id' => (int)$cur['id']];
        foreach ($row as $k => $v) { $params[":$k"] = $v; }
        $st->execute($params);
        $id = (int)$cur['id'];
    } else {
        $row['slug'] = 'halloween-' . date('Y', $start);
        $row['created_at'] = $now;
        $cols = array_keys($row);
        $st = $pdo->prepare('INSERT INTO hunts (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($row));
        $id = (int)$pdo->lastInsertId();
    }
    return [200, ['ok' => true, 'hunt' => hunt_admin_out(hunt_load($pdo, $id), $now)]];
}
