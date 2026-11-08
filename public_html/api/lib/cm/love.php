<?php
// Пары и браки (этап 3). У человека в чате — не больше одной пары и одного
// брака; пара и брак друг от друга не зависят: можно и то и другое, с одним
// человеком или с разными. Пара копит опыт от РП (cm_couple_xp, fun.php),
// уровень = 1 + опыт / 50. Развод можно отменить за 3 дня: «вернуть брак»
// продолжает тот же брак с прежней датой. Уход из чата пару и брак не рвёт.

const CM_COUPLE_XP_LEVEL = 50;
const CM_REMARRY_SECS    = 259200;
const CM_LOVE_TOP        = 10;

// Действующая пара или брак человека (строка таблицы, всё — int). $chats —
// чат или список чатов сети (cm_scope): пары и браки общие для сети.
function cm_love_of(PDO $pdo, string $table, $chats, int $userId): ?array {
    $st = $pdo->prepare("SELECT * FROM $table WHERE chat_id IN (" . cm_in($chats) . ") AND ended_at = 0 AND (a_id = :u OR b_id = :u2)
        ORDER BY id DESC LIMIT 1");
    $st->execute([':u' => $userId, ':u2' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? array_map('intval', $r) : null;
}

function cm_couple_of(PDO $pdo, $chats, int $userId): ?array {
    return cm_love_of($pdo, 'cm_couples', $chats, $userId);
}

function cm_marriage_of(PDO $pdo, $chats, int $userId): ?array {
    return cm_love_of($pdo, 'cm_marriages', $chats, $userId);
}

function cm_partner(array $row, int $userId): int {
    return $row['a_id'] === $userId ? $row['b_id'] : $row['a_id'];
}

function cm_couple_level(int $xp): int {
    return 1 + intdiv(max(0, $xp), CM_COUPLE_XP_LEVEL);
}

// Целых суток в $secs: «12 дней».
function cm_days_text(int $secs): string {
    $d = intdiv(max(0, $secs), 86400);
    return $d . ' ' . cm_plural($d, ['день', 'дня', 'дней']);
}

// Имя автора сообщения контекста — как в Telegram сейчас.
function cm_actor_name(array $ctx): string {
    return cm_who_from(is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : []);
}

// Себя и ботов нельзя. '' — можно, иначе ключ текста.
function cm_other_err(array $ctx, array $t): string {
    if (cm_botlike($t)) { return 'target_bot'; }
    if ((int)$t['id'] === (int)$ctx['actor']) { return 'target_self'; }
    return '';
}

// --------------------------------------------------------------------------
//  Пары
// --------------------------------------------------------------------------

function cm_cmd_couple_ask(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $pdo = $ctx['pdo'];
    $chats = cm_scope($ctx);
    $me = (int)$ctx['actor'];
    $mine = cm_couple_of($pdo, $chats, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('couple_have_me', ['name' => cm_user_who($pdo, cm_partner($mine, $me), true)]));
        return;
    }
    if (cm_couple_of($pdo, $chats, (int)$t['id']) !== null) {
        cm_reply($ctx, cm_t('couple_have_them', ['name' => $t['tag']]));
        return;
    }
    cm_offer_send($ctx, 'couple', (int)$t['id'], 0, cm_t('couple_offer', ['to' => $t['who'], 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_couple(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    if (cm_couple_of($pdo, cm_scope($ctx), $o['from_id']) !== null || cm_couple_of($pdo, cm_scope($ctx), $o['to_id']) !== null) {
        return 'couple_busy';
    }
    $pdo->prepare('INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at)
                   VALUES (:c, :a, :b, :n, 0, 0, 0, 0)')
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':n' => (int)$ctx['now']]);
    cm_edit($ctx, $o['msg_id'], cm_t('couple_done', ['a' => cm_user_who($pdo, $o['from_id']), 'b' => cm_user_who($pdo, $o['to_id'])]));
    return '';
}

function cm_cmd_couple_end(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $c = cm_couple_of($pdo, cm_scope($ctx), (int)$ctx['actor']);
    if ($c === null) {
        cm_reply($ctx, cm_t('couple_none_me'));
        return;
    }
    $pdo->prepare('UPDATE cm_couples SET ended_at = :n WHERE id = :id AND ended_at = 0')
        ->execute([':n' => (int)$ctx['now'], ':id' => $c['id']]);
    cm_reply($ctx, cm_t('couple_end', ['a' => cm_user_who($pdo, $c['a_id']), 'b' => cm_user_who($pdo, $c['b_id'])]), false);
}

// «пара» — своя; ответом или с @ником — чужая.
function cm_cmd_couple_show(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_target_or_self($ctx, $args);
    if ($t === null) { return; }
    $pdo = $ctx['pdo'];
    $c = cm_couple_of($pdo, cm_scope($ctx), (int)$t['id']);
    if ($c === null) {
        cm_reply($ctx, cm_t('couple_none', ['name' => $t['tag']]));
        return;
    }
    cm_reply($ctx, cm_t('couple_show', ['a' => cm_user_who($pdo, $c['a_id'], true), 'b' => cm_user_who($pdo, $c['b_id'], true),
        'days' => cm_days_text((int)$ctx['now'] - $c['since']), 'lvl' => cm_couple_level($c['xp']), 'xp' => $c['xp']]));
}

function cm_cmd_couple_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->query('SELECT a_id, b_id, xp FROM cm_couples WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ') AND ended_at = 0
        ORDER BY xp DESC, since, id LIMIT ' . CM_LOVE_TOP);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('couples_line', ['n' => $i + 1, 'a' => cm_user_who($pdo, (int)$r['a_id'], true),
            'b' => cm_user_who($pdo, (int)$r['b_id'], true), 'lvl' => cm_couple_level((int)$r['xp'])]);
    }
    cm_reply($ctx, $lines ? cm_t('couples_head') . "\n" . implode("\n", $lines) : cm_t('couples_empty'), false);
}

// --------------------------------------------------------------------------
//  Браки
// --------------------------------------------------------------------------

function cm_marriage_say(array $ctx, array $m): void {
    $pdo = $ctx['pdo'];
    cm_reply($ctx, cm_t('marry_show', ['a' => cm_user_who($pdo, $m['a_id'], true), 'b' => cm_user_who($pdo, $m['b_id'], true),
        'days' => cm_days_text((int)$ctx['now'] - $m['since'])]));
}

// «брак» без цели — свой брак. С целью (ответом или @ником): цель уже в браке
// — показать её брак, иначе — предложение.
function cm_cmd_marry(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chats = cm_scope($ctx);
    $me = (int)$ctx['actor'];
    $args = $cmd['args'];
    $why = null;
    $t = cm_target($pdo, $ctx['msg'], $args, $why);
    if ($t === null) {
        if ($why === 'unknown') {
            cm_reply($ctx, cm_t('target_unknown'));
            return;
        }
        $m = cm_marriage_of($pdo, $chats, $me);
        if ($m === null) {
            cm_reply($ctx, cm_t('marry_none_me'));
            return;
        }
        cm_marriage_say($ctx, $m);
        return;
    }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $theirs = cm_marriage_of($pdo, $chats, (int)$t['id']);
    if ($theirs !== null) {
        cm_marriage_say($ctx, $theirs);
        return;
    }
    $mine = cm_marriage_of($pdo, $chats, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('marry_have_me', ['name' => cm_user_who($pdo, cm_partner($mine, $me), true)]));
        return;
    }
    cm_offer_send($ctx, 'marry', (int)$t['id'], 0, cm_t('marry_offer', ['to' => $t['who'], 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_marry(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    if (cm_marriage_of($pdo, cm_scope($ctx), $o['from_id']) !== null || cm_marriage_of($pdo, cm_scope($ctx), $o['to_id']) !== null) {
        return 'marry_busy';
    }
    $pdo->prepare('INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES (:c, :a, :b, :n, 0, 0)')
        ->execute([':c' => $o['chat_id'], ':a' => $o['from_id'], ':b' => $o['to_id'], ':n' => (int)$ctx['now']]);
    cm_edit($ctx, $o['msg_id'], cm_t('marry_done', ['a' => cm_user_who($pdo, $o['from_id']), 'b' => cm_user_who($pdo, $o['to_id'])]));
    return '';
}

function cm_cmd_divorce(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $m = cm_marriage_of($pdo, cm_scope($ctx), (int)$ctx['actor']);
    if ($m === null) {
        cm_reply($ctx, cm_t('marry_none_me'));
        return;
    }
    $pdo->prepare('UPDATE cm_marriages SET ended_at = :n, ended_by = :b WHERE id = :id AND ended_at = 0')
        ->execute([':n' => (int)$ctx['now'], ':b' => (int)$ctx['actor'], ':id' => $m['id']]);
    cm_reply($ctx, cm_t('divorce_done', ['a' => cm_user_who($pdo, $m['a_id']), 'b' => cm_user_who($pdo, $m['b_id'])]), false);
}

// Последний брак человека, распавшийся меньше 3 дней назад.
function cm_marriage_recent(PDO $pdo, $chats, int $userId, int $now): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_marriages WHERE chat_id IN (' . cm_in($chats) . ') AND ended_at > :t
        AND (a_id = :u OR b_id = :u2) ORDER BY ended_at DESC, id DESC LIMIT 1');
    $st->execute([':t' => $now - CM_REMARRY_SECS, ':u' => $userId, ':u2' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? array_map('intval', $r) : null;
}

// «вернуть брак»: в течение 3 дней после развода, оба сейчас не в браке.
// Приглашение уходит бывшему супругу; «Принять» продолжает тот же брак.
function cm_cmd_remarry(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chats = cm_scope($ctx);
    $me = (int)$ctx['actor'];
    $mine = cm_marriage_of($pdo, $chats, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('marry_have_me', ['name' => cm_user_who($pdo, cm_partner($mine, $me), true)]));
        return;
    }
    $m = cm_marriage_recent($pdo, $chats, $me, (int)$ctx['now']);
    if ($m === null) {
        cm_reply($ctx, cm_t('remarry_none'));
        return;
    }
    $ex = cm_partner($m, $me);
    if (cm_marriage_of($pdo, $chats, $ex) !== null) {
        cm_reply($ctx, cm_t('remarry_ex_busy', ['name' => cm_user_who($pdo, $ex, true)]));
        return;
    }
    cm_offer_send($ctx, 'remarry', $ex, $m['id'], cm_t('remarry_offer', ['to' => cm_user_who($pdo, $ex), 'from' => cm_actor_name($ctx)]));
}

function cm_offer_yes_remarry(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT * FROM cm_marriages WHERE id = :id AND chat_id IN (' . cm_in(cm_scope($ctx)) . ')');
    $st->execute([':id' => $o['ref_id']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $m = $r ? array_map('intval', $r) : null;
    if ($m === null || $m['ended_at'] === 0 || $m['ended_at'] <= (int)$ctx['now'] - CM_REMARRY_SECS) { return 'remarry_none'; }
    if (cm_marriage_of($pdo, cm_scope($ctx), $o['from_id']) !== null || cm_marriage_of($pdo, cm_scope($ctx), $o['to_id']) !== null) {
        return 'marry_busy';
    }
    $up = $pdo->prepare('UPDATE cm_marriages SET ended_at = 0, ended_by = 0 WHERE id = :id AND ended_at > 0');
    $up->execute([':id' => $m['id']]);
    if ($up->rowCount() === 0) { return 'remarry_none'; }
    cm_edit($ctx, $o['msg_id'], cm_t('remarry_done', ['a' => cm_user_who($pdo, $m['a_id']), 'b' => cm_user_who($pdo, $m['b_id'])]));
    return '';
}

function cm_cmd_marry_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->query('SELECT a_id, b_id, since FROM cm_marriages WHERE chat_id IN (' . cm_in(cm_scope($ctx)) . ') AND ended_at = 0
        ORDER BY since, id LIMIT ' . CM_LOVE_TOP);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('marriages_line', ['n' => $i + 1, 'a' => cm_user_who($pdo, (int)$r['a_id'], true),
            'b' => cm_user_who($pdo, (int)$r['b_id'], true), 'days' => cm_days_text((int)$ctx['now'] - (int)$r['since'])]);
    }
    cm_reply($ctx, $lines ? cm_t('marriages_head') . "\n" . implode("\n", $lines) : cm_t('marriages_empty'), false);
}
