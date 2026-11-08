<?php
// Ферма монет: «ферма» раз в 4 часа даёт от 10 до 160 🪙, чаще всего около 50.
// Монеты пока ни на что не тратятся (решение владельца 08.11.2026: «пусть
// собирается»). Кошелёк один на человека во всех чатах Макнеми, как анкета.
// Таблица — docs/migrations/2026-11-08-cm-coins.sql; пока её нет, команды
// отвечают «ещё не настроено».

const CM_FARM_MIN   = 10;
const CM_FARM_MODE  = 50;
const CM_FARM_MAX   = 160;
const CM_FARM_EVERY = 14400;   // 4 часа между сборами
const CM_COINS_TOP  = 10;

function cm_ready_coins(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_coins LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function cm_need_coins(array $ctx): bool {
    if (cm_ready_coins($ctx['pdo'])) { return true; }
    cm_reply($ctx, cm_t('not_ready2'));
    return false;
}

/**
 * Урожай: треугольное распределение от CM_FARM_MIN до CM_FARM_MAX с пиком на
 * CM_FARM_MODE. Около 50 выпадает чаще всего, к 10 и к 160 шанс падает до
 * нуля линейно; в среднем ~73. $u — случайное от 0 до 1 000 000 (cm_rand).
 */
function cm_farm_amount(int $u): int {
    $a = CM_FARM_MIN;
    $b = CM_FARM_MAX;
    $c = CM_FARM_MODE;
    $p = max(0, min(1000000, $u)) / 1000000;
    $f = ($c - $a) / ($b - $a);
    $x = $p < $f ? $a + sqrt($p * ($b - $a) * ($c - $a)) : $b - sqrt((1 - $p) * ($b - $a) * ($b - $c));
    return max($a, min($b, (int)round($x)));
}

function cm_coins_get(PDO $pdo, int $userId): array {
    $st = $pdo->prepare('SELECT coins, farms, farm_at FROM cm_coins WHERE user_id = :u');
    $st->execute([':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? ['coins' => (int)$r['coins'], 'farms' => (int)$r['farms'], 'farm_at' => (int)$r['farm_at']]
              : ['coins' => 0, 'farms' => 0, 'farm_at' => 0];
}

function cm_coins_text(int $n): string {
    return cm_num($n) . ' ' . cm_plural($n, ['монета', 'монеты', 'монет']);
}

// «2 ч 15 мин», «40 мин», «меньше минуты».
function cm_wait_text(int $secs): string {
    $m = (int)ceil(max(0, $secs) / 60);
    if ($m <= 0) { return cm_t('wait_now'); }
    $h = intdiv($m, 60);
    $m %= 60;
    if ($h === 0) { return $m . ' мин'; }
    return $m === 0 ? $h . ' ч' : $h . ' ч ' . $m . ' мин';
}

/**
 * Сбор. Строка кошелька — заранее (INSERT, а если уже есть — ключ глушим),
 * потом одно UPDATE с условием «4 часа прошло»: двойное нажатие подряд не
 * соберёт дважды — второе UPDATE не найдёт строку. Ответ об урожае постоянный,
 * «ещё растёт» — временный.
 */
function cm_cmd_farm(array $ctx, array $cmd): void {
    if (!cm_need_coins($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $me = (int)$ctx['actor'];
    $now = (int)$ctx['now'];
    try {
        $pdo->prepare('INSERT INTO cm_coins (user_id, coins, farms, farm_at) VALUES (:u, 0, 0, 0)')->execute([':u' => $me]);
    } catch (PDOException $e) {
        // кошелёк уже есть
    }
    $n = cm_farm_amount(cm_rand($ctx, 0, 1000000));
    $st = $pdo->prepare('UPDATE cm_coins SET coins = coins + :n, farms = farms + 1, farm_at = :t
        WHERE user_id = :u AND farm_at <= :edge');
    $st->execute([':n' => $n, ':t' => $now, ':u' => $me, ':edge' => $now - CM_FARM_EVERY]);
    $w = cm_coins_get($pdo, $me);
    if ($st->rowCount() === 0) {
        cm_reply($ctx, cm_t('farm_wait', ['left' => cm_wait_text($w['farm_at'] + CM_FARM_EVERY - $now),
            'coins' => cm_coins_text($w['coins'])]));
        return;
    }
    $g = cm_profile_get($pdo, $me)['gender'];
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_reply($ctx, cm_t('farm_done', ['name' => cm_who_from($from),
        'verb' => $g === 'm' ? 'собрал' : ($g === 'f' ? 'собрала' : 'собрал(а)'), 'n' => cm_num($n),
        'coins' => cm_coins_text($w['coins']), 'every' => cm_wait_text(CM_FARM_EVERY)]), false);
}

// «монеты» — свой кошелёк, ответом или с @ником — чужой.
function cm_cmd_coins(array $ctx, array $cmd): void {
    if (!cm_need_coins($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    if (!empty($t['is_bot'])) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    $w = cm_coins_get($ctx['pdo'], (int)$t['id']);
    cm_reply($ctx, cm_t('coins_show', ['name' => $t['tag'], 'coins' => cm_coins_text($w['coins'])]));
}

// Самые богатые — по всем чатам Макнеми, как и сам кошелёк; ники тихие.
function cm_cmd_coins_top(array $ctx, array $cmd): void {
    if (!cm_need_coins($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $rows = $pdo->query('SELECT user_id, coins FROM cm_coins WHERE coins > 0 ORDER BY coins DESC, user_id LIMIT ' . CM_COINS_TOP)
        ->fetchAll(PDO::FETCH_ASSOC);
    $lines = [];
    foreach ($rows as $i => $r) {
        $lines[] = cm_t('game_top_line', ['n' => $i + 1, 'name' => cm_user_who($pdo, (int)$r['user_id'], true),
            'score' => cm_coins_text((int)$r['coins'])]);
    }
    cm_reply($ctx, $lines ? cm_t('coins_top_head') . "\n" . implode("\n", $lines) : cm_t('coins_top_empty'), false);
}
