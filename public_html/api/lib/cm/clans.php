<?php
// Кланы (этап 3). Человек в чате — не больше чем в одном клане. Глава —
// создатель; передачи главенства нет, и выйти глава не может — только
// распустить клан («-клан»). Управляет кланом только глава. Рейтинг —
// сообщения участников за 7 дней (cm_stats этапа 2), экономики нет. Уход из
// чата из клана не выводит, но созыв таких не зовёт.

const CM_CLAN_MAX       = 100;
const CM_CLAN_ABOUT_MAX = 200;
const CM_CLAN_CALL_MAX  = 30;
const CM_CLAN_CALL_GAP  = 600;
const CM_CLAN_DAYS      = 7;
const CM_CLAN_TOP       = 10;

// «  Волки   Севера » → «Волки Севера»; '' — не подходит: 2–32 символа,
// буквы, цифры, пробел, дефис, по краям — буква или цифра.
function cm_clan_name(string $raw): string {
    $n = trim((string)preg_replace('~\s+~u', ' ', $raw));
    $len = mb_strlen($n);
    if ($len < 2 || $len > 32 || !preg_match('~^[\p{L}\p{N}](?:[\p{L}\p{N} -]*[\p{L}\p{N}])?$~u', $n)) { return ''; }
    return $n;
}

function cm_clan_decode(array $r): array {
    foreach (['id', 'chat_id', 'owner_id', 'is_open', 'called_at', 'created_at'] as $k) { $r[$k] = (int)$r[$k]; }
    foreach (['name', 'name_key', 'about'] as $k) { $r[$k] = (string)$r[$k]; }
    return $r;
}

function cm_clan_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_clans WHERE id = :id');
    $st->execute([':id' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

// Клан по названию — без учёта регистра и ё/е.
function cm_clan_by_name(PDO $pdo, int $chatId, string $name): ?array {
    $st = $pdo->prepare('SELECT * FROM cm_clans WHERE chat_id = :c AND name_key = :k');
    $st->execute([':c' => $chatId, ':k' => cm_norm($name)]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

// Клан, в котором человек состоит; null — ни в каком.
function cm_clan_of(PDO $pdo, int $chatId, int $userId): ?array {
    $st = $pdo->prepare('SELECT c.* FROM cm_clan_members m JOIN cm_clans c ON c.id = m.clan_id
        WHERE m.chat_id = :c AND m.user_id = :u');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? cm_clan_decode($r) : null;
}

function cm_clan_size(PDO $pdo, int $clanId): int {
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_clan_members WHERE clan_id = :k');
    $st->execute([':k' => $clanId]);
    return (int)$st->fetchColumn();
}

// Сообщения участников клана за неделю, сегодня включительно.
function cm_clan_week(PDO $pdo, array $clan, int $now): int {
    $st = $pdo->prepare('SELECT COALESCE(SUM(s.msgs), 0) FROM cm_clan_members m
        JOIN cm_stats s ON s.chat_id = m.chat_id AND s.user_id = m.user_id AND s.day >= :d
        WHERE m.clan_id = :k');
    $st->execute([':d' => cm_day_from($now, CM_CLAN_DAYS), ':k' => $clan['id']]);
    return (int)$st->fetchColumn();
}

// Название и описание — без ссылок и мата: их видно всем, а сообщение-команду
// фильтры уже не проверяют.
function cm_clan_text_ok(string $s): bool {
    return !cm_has_link(['text' => $s], []) && !cm_has_mat($s, chat_filter_compile(CM_MAT_WORDS));
}

// В клан. false — человек уже в каком-то клане (ключ chat_id + user_id).
function cm_clan_add_member(PDO $pdo, array $clan, int $userId, int $now): bool {
    try {
        $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, :k, :n)')
            ->execute([':c' => $clan['chat_id'], ':u' => $userId, ':k' => $clan['id'], ':n' => $now]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Клан, которым правит автор контекста; иначе — подсказка и null.
function cm_clan_owned(array $ctx): ?array {
    $c = cm_clan_of($ctx['pdo'], (int)$ctx['chat']['chat_id'], (int)$ctx['actor']);
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_none_me'));
        return null;
    }
    if ($c['owner_id'] !== (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('clan_owner_only'));
        return null;
    }
    return $c;
}

function cm_clan_card(array $ctx, array $c): string {
    $pdo = $ctx['pdo'];
    return cm_t('clan_info', ['name' => $c['name'], 'owner' => cm_user_who($pdo, $c['owner_id'], true),
            'n' => cm_clan_size($pdo, $c['id']), 'max' => CM_CLAN_MAX,
            'state' => cm_t($c['is_open'] ? 'clan_state_open' : 'clan_state_closed'),
            'msgs' => cm_num(cm_clan_week($pdo, $c, (int)$ctx['now']))])
        . ($c['about'] !== '' ? cm_t('clan_about_line', ['about' => $c['about']]) : '');
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

function cm_cmd_clan_add(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $name = cm_clan_name($cmd['args']);
    if ($name === '') {
        cm_reply($ctx, cm_t('clan_usage'));
        return;
    }
    if (!cm_clan_text_ok($name)) {
        cm_reply($ctx, cm_t('clan_text_bad'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $me = (int)$ctx['actor'];
    $mine = cm_clan_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('clan_have_me', ['name' => $mine['name']]));
        return;
    }
    $now = (int)$ctx['now'];
    try {
        $pdo->prepare("INSERT INTO cm_clans (chat_id, name, name_key, owner_id, about, is_open, called_at, created_at)
                       VALUES (:c, :n, :k, :o, '', 1, 0, :t)")
            ->execute([':c' => $chatId, ':n' => $name, ':k' => cm_norm($name), ':o' => $me, ':t' => $now]);
    } catch (PDOException $e) {
        cm_reply($ctx, cm_t('clan_taken', ['name' => $name]));   // уникальный ключ (chat_id, name_key)
        return;
    }
    cm_clan_add_member($pdo, cm_clan_get($pdo, (int)$pdo->lastInsertId()), $me, $now);
    cm_reply($ctx, cm_t('clan_created', ['name' => $name, 'owner' => cm_actor_name($ctx)]), false);
}

// «-клан»: глава распускает клан, участники выходят.
function cm_cmd_clan_del(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $pdo = $ctx['pdo'];
    $pdo->prepare('DELETE FROM cm_clan_members WHERE clan_id = :k')->execute([':k' => $c['id']]);
    $pdo->prepare('DELETE FROM cm_clans WHERE id = :k')->execute([':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_deleted', ['name' => $c['name']]), false);
}

// «клан» — свой; «!клан Название» — любой.
function cm_cmd_clan_show(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $raw = trim($cmd['args']);
    if ($raw === '') {
        $c = cm_clan_of($pdo, $chatId, (int)$ctx['actor']);
        if ($c === null) {
            cm_reply($ctx, cm_t('clan_none_me'));
            return;
        }
    } else {
        $c = cm_clan_by_name($pdo, $chatId, cm_clan_name($raw));
        if ($c === null) {
            cm_reply($ctx, cm_t('clan_missing', ['name' => mb_substr($raw, 0, 32)]));
            return;
        }
    }
    cm_reply($ctx, cm_clan_card($ctx, $c), false);
}

// Топ-10 по сообщениям участников за неделю. LIMIT — числом в строке.
function cm_cmd_clan_top(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $st = $pdo->prepare('SELECT c.id, c.name, COALESCE(SUM(s.msgs), 0) AS n,
            (SELECT COUNT(*) FROM cm_clan_members mm WHERE mm.clan_id = c.id) AS members
        FROM cm_clans c
        LEFT JOIN cm_clan_members m ON m.clan_id = c.id
        LEFT JOIN cm_stats s ON s.chat_id = m.chat_id AND s.user_id = m.user_id AND s.day >= :d
        WHERE c.chat_id = :c GROUP BY c.id, c.name ORDER BY n DESC, c.id LIMIT ' . CM_CLAN_TOP);
    $st->execute([':d' => cm_day_from((int)$ctx['now'], CM_CLAN_DAYS), ':c' => $ctx['chat']['chat_id']]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
        $lines[] = cm_t('clans_line', ['n' => $i + 1, 'name' => (string)$r['name'], 'msgs' => cm_num((int)$r['n']),
            'members' => (int)$r['members']]);
    }
    cm_reply($ctx, $lines ? cm_t('clans_head') . "\n" . implode("\n", $lines) : cm_t('clans_empty'), false);
}

// «вступить Волки», «вступить в клан Волки». Закрытый — только по приглашению.
function cm_cmd_clan_join(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $raw = trim($cmd['args']);
    $name = cm_clan_name($raw);
    $c = $name === '' ? null : cm_clan_by_name($pdo, $chatId, $name);
    if ($c === null) {
        $name = cm_clan_name((string)preg_replace('~^(?:в\s+)?клан\s+~iu', '', $raw));
        $c = $name === '' ? null : cm_clan_by_name($pdo, $chatId, $name);
    }
    if ($name === '') {
        cm_reply($ctx, cm_t('clan_join_usage'));
        return;
    }
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_missing', ['name' => $name]));
        return;
    }
    $me = (int)$ctx['actor'];
    $mine = cm_clan_of($pdo, $chatId, $me);
    if ($mine !== null) {
        cm_reply($ctx, cm_t('clan_have_me', ['name' => $mine['name']]));
        return;
    }
    if (!$c['is_open']) {
        cm_reply($ctx, cm_t('clan_closed', ['name' => $c['name']]));
        return;
    }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) {
        cm_reply($ctx, cm_t('clan_full', ['name' => $c['name'], 'max' => CM_CLAN_MAX]));
        return;
    }
    if (!cm_clan_add_member($pdo, $c, $me, (int)$ctx['now'])) {
        cm_reply($ctx, cm_t('clan_busy'));
        return;
    }
    cm_reply($ctx, cm_t('clan_joined', ['name' => cm_actor_name($ctx), 'clan' => $c['name']]), false);
}

function cm_cmd_clan_leave(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $me = (int)$ctx['actor'];
    $c = cm_clan_of($pdo, (int)$ctx['chat']['chat_id'], $me);
    if ($c === null) {
        cm_reply($ctx, cm_t('clan_none_me'));
        return;
    }
    if ($c['owner_id'] === $me) {
        cm_reply($ctx, cm_t('clan_owner_stays'));
        return;
    }
    $pdo->prepare('DELETE FROM cm_clan_members WHERE chat_id = :c AND user_id = :u')
        ->execute([':c' => $c['chat_id'], ':u' => $me]);
    cm_reply($ctx, cm_t('clan_left', ['name' => cm_actor_name($ctx), 'clan' => $c['name']]), false);
}

// «пригласить @ник» (глава) → приглашение → вступление, даже в закрытый клан.
function cm_cmd_clan_invite(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $err = cm_other_err($ctx, $t);
    if ($err !== '') {
        cm_reply($ctx, cm_t($err));
        return;
    }
    $pdo = $ctx['pdo'];
    if (cm_clan_of($pdo, $c['chat_id'], (int)$t['id']) !== null) {
        cm_reply($ctx, cm_t('clan_them_in', ['name' => $t['tag']]));
        return;
    }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) {
        cm_reply($ctx, cm_t('clan_full', ['name' => $c['name'], 'max' => CM_CLAN_MAX]));
        return;
    }
    cm_offer_send($ctx, 'clan', (int)$t['id'], $c['id'],
        cm_t('clan_offer', ['to' => $t['who'], 'from' => cm_actor_name($ctx), 'clan' => $c['name']]));
}

// «Принять» приглашение в клан: клан ещё есть, человек ни в каком, место есть.
function cm_offer_yes_clan(array $ctx, array $o): string {
    $pdo = $ctx['pdo'];
    $c = cm_clan_get($pdo, $o['ref_id']);
    if ($c === null || $c['chat_id'] !== $o['chat_id']) { return 'clan_gone'; }
    if (cm_clan_of($pdo, $o['chat_id'], $o['to_id']) !== null) { return 'clan_busy'; }
    if (cm_clan_size($pdo, $c['id']) >= CM_CLAN_MAX) { return 'clan_full_short'; }
    if (!cm_clan_add_member($pdo, $c, $o['to_id'], (int)$ctx['now'])) { return 'clan_busy'; }
    cm_edit($ctx, $o['msg_id'], cm_t('clan_joined', ['name' => cm_user_who($pdo, $o['to_id']), 'clan' => $c['name']]));
    return '';
}

function cm_cmd_clan_kick(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    if ((int)$t['id'] === (int)$ctx['actor']) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $pdo = $ctx['pdo'];
    $theirs = cm_clan_of($pdo, $c['chat_id'], (int)$t['id']);
    if ($theirs === null || $theirs['id'] !== $c['id']) {
        cm_reply($ctx, cm_t('clan_not_member', ['name' => $t['tag']]));
        return;
    }
    $pdo->prepare('DELETE FROM cm_clan_members WHERE chat_id = :c AND user_id = :u AND clan_id = :k')
        ->execute([':c' => $c['chat_id'], ':u' => (int)$t['id'], ':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_kicked', ['name' => $t['who'], 'clan' => $c['name']]), false);
}

// «клан открыт» / «клан закрыт».
function cm_cmd_clan_open(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $open = $cmd['phrase'] === 'клан открыт' ? 1 : 0;
    $ctx['pdo']->prepare('UPDATE cm_clans SET is_open = :o WHERE id = :k')->execute([':o' => $open, ':k' => $c['id']]);
    cm_reply($ctx, cm_t($open ? 'clan_opened' : 'clan_shut', ['name' => $c['name']]), false);
}

// «клан описание текст» — одной строкой, до 200 символов, без ссылок и мата.
function cm_cmd_clan_about(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $text = trim((string)preg_replace('~\s+~u', ' ', $cmd['args'] . ' ' . $cmd['body']));
    if ($text === '' || mb_strlen($text) > CM_CLAN_ABOUT_MAX) {
        cm_reply($ctx, cm_t('clan_about_usage'));
        return;
    }
    if (!cm_clan_text_ok($text)) {
        cm_reply($ctx, cm_t('clan_text_bad'));
        return;
    }
    $ctx['pdo']->prepare('UPDATE cm_clans SET about = :a WHERE id = :k')->execute([':a' => $text, ':k' => $c['id']]);
    cm_reply($ctx, cm_t('clan_about_done'));
}

// «созвать клан» (глава): одно сообщение со скрытыми упоминаниями
// (text_mention, смещения в UTF-16) до 30 участников, кто ещё в чате. Не чаще
// раза в 10 минут: called_at ставится условием, второй созыв внахлёст не пройдёт.
function cm_cmd_clan_call(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $c = cm_clan_owned($ctx);
    if ($c === null) { return; }
    $pdo = $ctx['pdo'];
    $now = (int)$ctx['now'];
    $st = $pdo->prepare('SELECT m.user_id FROM cm_clan_members m
        LEFT JOIN cm_members mb ON mb.chat_id = m.chat_id AND mb.user_id = m.user_id
        WHERE m.clan_id = :k AND m.user_id <> :o AND (mb.left_at IS NULL OR mb.left_at = 0)
        ORDER BY m.joined_at, m.user_id LIMIT ' . CM_CLAN_CALL_MAX);
    $st->execute([':k' => $c['id'], ':o' => $c['owner_id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$ids) {
        cm_reply($ctx, cm_t('clan_call_empty'));
        return;
    }
    $up = $pdo->prepare('UPDATE cm_clans SET called_at = :n WHERE id = :k AND called_at <= :t');
    $up->execute([':n' => $now, ':k' => $c['id'], ':t' => $now - CM_CLAN_CALL_GAP]);
    if ($up->rowCount() === 0) {
        $left = $c['called_at'] + CM_CLAN_CALL_GAP - $now;
        cm_reply($ctx, cm_t('clan_call_wait', ['min' => max(1, intdiv($left + 59, 60))]));
        return;
    }
    $who = [];
    foreach ($ids as $uid) { $who[] = cm_user_who($pdo, $uid); }
    cm_reply($ctx, cm_t('clan_call', ['owner' => cm_actor_name($ctx), 'clan' => $c['name']]) . "\n" . implode(', ', $who), false);
}
