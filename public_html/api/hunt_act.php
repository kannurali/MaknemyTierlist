<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/hunt.php';

// Действия участника ивента — POST /api/hunt_act.php:
//   {"act":"task",   "id":12, "page"?:"calc", "src"?:"partner"}  — задание
//   {"act":"answer", "id":12, "choice":2}                         — ответ на вопрос
//   {"act":"secret", "id":7,  "page":"calc"}                      — находка
//   {"act":"ticket", "seq":1}                                      — обмен на билет
//   {"act":"confirm_win", "id":3}                                  — подтвердить выигрыш
// → {"ok", "status", "wallet"?, …}; status — см. api/lib/hunt.php.
//
// Нажатия считаются: 120 действий в час на человека, ответов на вопросы —
// 60 в час сверх того, чтобы варианты не перебирали скриптом. Подписка в
// Telegram — запрос к Telegram на каждое нажатие, его тоже держит общий счёт.

const HUNT_ACT_PER_HOUR    = 120;
const HUNT_ANSWER_PER_HOUR = 60;

function handle_hunt_act(PDO $pdo, array $cfg, callable $send, string $me, array $body, int $now): array {
    $act = isset($body['act']) && is_string($body['act']) ? $body['act'] : '';
    $id = read_row_id($body);
    $page = isset($body['page']) && is_string($body['page']) ? $body['page'] : '';
    if ($act === 'task' || $act === 'answer') {
        return hunt_do_task($pdo, $cfg, $send, $me, $id, $body, $now);
    }
    if ($act === 'secret') {
        return hunt_find_secret($pdo, $cfg, $me, $id, $page, $now, hunt_src_clean($body['src'] ?? ''));
    }
    if ($act === 'ticket') {
        $seq = $body['seq'] ?? 0;
        return hunt_buy_ticket($pdo, $cfg, $me, is_int($seq) ? $seq : 0, $now);
    }
    if ($act === 'confirm_win') {
        return hunt_confirm_win($pdo, $me, $id, $now);
    }
    return hunt_fail(400, 'bad');
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    $session = resume_site_session() ? $_SESSION : [];
    $me = chat_me($session);
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $body = read_json_body();
    $now = time();
    if ($me !== '') {
        $limited = !rate_limit_allow('hunt_act', $me, HUNT_ACT_PER_HOUR, 3600, $now)
            || (($body['act'] ?? '') === 'answer' && !rate_limit_allow('hunt_ans', $me, HUNT_ANSWER_PER_HOUR, 3600, $now));
        if ($limited) {
            json_out(['ok' => false, 'status' => 'rate_limited'], 429);
            exit;
        }
    }
    $cfg = app_config();
    $tg = tg_config($cfg);
    $send = tg_enabled($tg) ? tg_http($tg['token']) : function () { return null; };
    try {
        [$code, $out] = handle_hunt_act(db(), $cfg, $send, $me, $body, $now);
    } catch (Throwable $e) {
        error_log('hunt_act: ' . $e->getMessage());
        [$code, $out] = hunt_fail(503, 'closed');
    }
    json_out($out, $code);
}
