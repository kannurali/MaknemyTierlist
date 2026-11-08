<?php
// Статистика сообщений (этап 2): счётчик за день по Москве, топы, «моя стата».
// Считается с момента, когда бот появился в чате: прошлую переписку Telegram
// боту не отдаёт. Таблицы — docs/migrations/2026-11-08-chat-manager-activity.sql.

// Слово периода (после cm_norm) → дней; 0 — за всё время.
const CM_STATS_PERIODS = ['день' => 1, 'неделя' => 7, 'месяц' => 30, 'все' => 0];
const CM_STATS_TOP_MAX = 50;

// Суммы сообщений по людям, кто не покинул чат. Параметры :c и :d.
const CM_STATS_SUMS = 'SELECT s.user_id AS user_id, SUM(s.msgs) AS n FROM cm_stats s
    LEFT JOIN cm_members m ON m.chat_id = s.chat_id AND m.user_id = s.user_id
    WHERE s.chat_id = :c AND s.day >= :d AND (m.left_at IS NULL OR m.left_at = 0)
    GROUP BY s.user_id';

// Миграция этапа 2 выполняется руками после этапа 1. Пока её нет, команды
// этапа 2 отвечают «ещё не настроено», счётчик молчит, модерация работает.
function cm_ready2(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_stats LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Первая строка каждого обработчика этапа 2: нет таблиц — ответить и выйти.
function cm_need2(array $ctx): bool {
    if (cm_ready2($ctx['pdo'])) { return true; }
    cm_reply($ctx, cm_t('not_ready2'));
    return false;
}

// Дата по Москве числом ГГГГММДД.
function cm_day(int $ts): int {
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone(CM_TZ));
    return (int)$d->format('Ymd');
}

// Первый день периода из $days дней, сегодня включительно; 0 — с начала.
function cm_day_from(int $now, int $days): int {
    if ($days <= 0) { return 0; }
    $d = new DateTime('@' . $now);
    $d->setTimezone(new DateTimeZone(CM_TZ));
    $d->modify('-' . ($days - 1) . ' days');
    return (int)$d->format('Ymd');
}

// 20261107 → «07.11.2026».
function cm_day_text(int $day): string {
    return sprintf('%02d.%02d.%04d', $day % 100, intdiv($day, 100) % 100, intdiv($day, 10000));
}

// 1234567 → «1 234 567».
function cm_num(int $n): string {
    return number_format($n, 0, '', ' ');
}

// Цель команды, а без неё — сам автор. Незнакомый @ник — подсказка и null.
function cm_target_or_self(array $ctx, string &$args): ?array {
    $why = null;
    $t = cm_target($ctx['pdo'], $ctx['msg'], $args, $why);
    if ($t !== null) { return $t; }
    if ($why === 'unknown') {
        cm_reply($ctx, cm_t('target_unknown'));
        return null;
    }
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    return ['id' => (int)$ctx['actor'], 'name' => cm_display_name($from), 'is_bot' => false, 'msg_id' => 0];
}

// --------------------------------------------------------------------------
//  Счётчик и выборки
// --------------------------------------------------------------------------

// +1 сообщение автору за сегодня. Нет таблиц этапа 2 — молча мимо.
function cm_stats_hit(PDO $pdo, int $chatId, int $userId, int $now): void {
    $p = [':c' => $chatId, ':u' => $userId, ':d' => cm_day($now)];
    try {
        $up = $pdo->prepare('UPDATE cm_stats SET msgs = msgs + 1 WHERE chat_id = :c AND user_id = :u AND day = :d');
        $up->execute($p);
        if ($up->rowCount() > 0) { return; }
        try {
            $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, 1)')->execute($p);
        } catch (PDOException $e) {
            $up->execute($p);   // параллельное сообщение уже завело строку
        }
    } catch (PDOException $e) {
        // таблиц этапа 2 ещё нет
    }
}

// Сообщения человека за $days дней (0 — за всё время). Им же этап 3 считает
// активность кланов.
function cm_stats_msgs(PDO $pdo, int $chatId, int $userId, int $days, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(msgs), 0) FROM cm_stats WHERE chat_id = :c AND user_id = :u AND day >= :d');
    $st->execute([':c' => $chatId, ':u' => $userId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn();
}

// Всего сообщений в чате за период — вместе с ушедшими.
function cm_stats_total(PDO $pdo, int $chatId, int $days, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(msgs), 0) FROM cm_stats WHERE chat_id = :c AND day >= :d');
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn();
}

// Топ: [['user_id' => int, 'msgs' => int], …], больше — выше, при равенстве
// меньший id. Ушедших (left_at > 0) нет. LIMIT — числом в строке: эмуляция
// подготовленных запросов MySQL взяла бы параметр в кавычки.
function cm_stats_top(PDO $pdo, int $chatId, int $days, int $now, int $limit): array {
    $st = $pdo->prepare(CM_STATS_SUMS . ' ORDER BY n DESC, s.user_id LIMIT ' . max(1, $limit));
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['user_id' => (int)$r['user_id'], 'msgs' => (int)$r['n']];
    }
    return $out;
}

// Место в том же топе; 0 — сообщений нет или человек ушёл. Числа — в строке:
// SQLite сравнивает SUM() с параметром-строкой как текст.
function cm_stats_place(PDO $pdo, int $chatId, int $userId, int $days, int $now): int {
    $mine = cm_stats_msgs($pdo, $chatId, $userId, $days, $now);
    $m = cm_member($pdo, $chatId, $userId);
    if ($mine === 0 || ($m !== null && $m['left_at'] > 0)) { return 0; }
    $st = $pdo->prepare('SELECT COUNT(*) FROM (' . CM_STATS_SUMS . ') t WHERE t.n > ' . $mine
        . ' OR (t.n = ' . $mine . ' AND t.user_id < ' . $userId . ')');
    $st->execute([':c' => $chatId, ':d' => cm_day_from($now, $days)]);
    return (int)$st->fetchColumn() + 1;
}

// Первый день в статистике чата — для «В чате с»; 0 — строк нет.
function cm_stats_first_day(PDO $pdo, int $chatId, int $userId): int {
    $st = $pdo->prepare('SELECT MIN(day) FROM cm_stats WHERE chat_id = :c AND user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    return (int)$st->fetchColumn();
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// «15 месяц» → [мест, дней]; null — не разобралось. Шаблон тот же, что для
// команды без префикса (CM_BARE_ARGS в parse.php).
function cm_stats_args(string $args): ?array {
    $a = cm_norm(trim($args));
    if (!preg_match(CM_BARE_ARGS['stats_top'], $a)) { return null; }
    $limit = null;
    $days = null;
    foreach (preg_split('~ +~', $a, -1, PREG_SPLIT_NO_EMPTY) as $tok) {
        if (ctype_digit($tok)) {
            $limit = $limit ?? (int)$tok;
        } else {
            $days = $days ?? CM_STATS_PERIODS[$tok];
        }
    }
    $limit = $limit ?? 10;
    return ($limit < 1 || $limit > CM_STATS_TOP_MAX) ? null : [$limit, $days ?? 1];
}

function cm_cmd_stats_top(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $a = cm_stats_args($cmd['args']);
    if ($a === null) {
        cm_reply($ctx, cm_t('stats_usage'));
        return;
    }
    [$limit, $days] = $a;
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $period = cm_t('stats_p_' . $days);
    $top = cm_stats_top($pdo, $chatId, $days, $now, $limit);
    if (!$top) {
        cm_reply($ctx, cm_t('stats_empty', ['period' => $period]), false);
        return;
    }
    $lines = [cm_t('stats_head', ['period' => $period, 'total' => cm_num(cm_stats_total($pdo, $chatId, $days, $now))])];
    foreach ($top as $i => $r) {
        $lines[] = cm_t('stats_line', ['n' => $i + 1, 'name' => cm_user_name($pdo, $r['user_id']), 'msgs' => cm_num($r['msgs'])]);
    }
    cm_reply($ctx, implode("\n", $lines), false);
}

function cm_cmd_stats_me(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $now = (int)$ctx['now'];
    $uid = (int)$t['id'];
    $place = cm_stats_place($pdo, $chatId, $uid, 0, $now);
    cm_reply($ctx, cm_t('stats_me', [
        'name'  => $t['name'],
        'd'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 1, $now)),
        'w'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 7, $now)),
        'm'     => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 30, $now)),
        'all'   => cm_num(cm_stats_msgs($pdo, $chatId, $uid, 0, $now)),
        'place' => $place > 0 ? cm_t('stats_place', ['n' => $place]) : '',
    ]), false);
}
