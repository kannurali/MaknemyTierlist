<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat.php';
require_once __DIR__ . '/lib/hunt.php';

// Действия участника ивента — POST /api/hunt_act.php:
//   {"act":"beat"}                 — пульс вкладки на экране (время на сайте)
//   {"act":"ticket", "seq":1}      — обмен токенов на билет
//   {"act":"confirm_win", "id":3}  — подтвердить выигрыш
// → {"ok", "status", …}; status — см. api/lib/hunt.php.
//
// Пульс приходит раз в минуту из каждой вкладки на экране. Лимит пульсов —
// 180 в час на человека: три вкладки, переключаемые туда-сюда, в него
// укладываются, а скрипт, который долбит чаще, — нет (время он всё равно не
// накрутит: больше минуты за минуту сервер не засчитывает). Прочих действий —
// 60 в час.

const HUNT_BEAT_PER_HOUR = 180;
const HUNT_ACT_PER_HOUR  = 60;

function handle_hunt_act(PDO $pdo, array $cfg, string $me, array $body, int $now): array {
    $act = isset($body['act']) && is_string($body['act']) ? $body['act'] : '';
    if ($act === 'beat') {
        return hunt_beat($pdo, $cfg, $me, $now);
    }
    if ($act === 'ticket') {
        $seq = $body['seq'] ?? 0;
        return hunt_buy_ticket($pdo, $cfg, $me, is_int($seq) ? $seq : 0, $now);
    }
    if ($act === 'confirm_win') {
        return hunt_confirm_win($pdo, $me, read_row_id($body), $now);
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
        $beat = ($body['act'] ?? '') === 'beat';
        $allowed = $beat
            ? rate_limit_allow('hunt_beat', $me, HUNT_BEAT_PER_HOUR, 3600, $now)
            : rate_limit_allow('hunt_act', $me, HUNT_ACT_PER_HOUR, 3600, $now);
        if (!$allowed) {
            json_out(['ok' => false, 'status' => 'rate_limited'], 429);
            exit;
        }
    }
    try {
        [$code, $out] = handle_hunt_act(db(), app_config(), $me, $body, $now);
    } catch (Throwable $e) {
        error_log('hunt_act: ' . $e->getMessage());
        [$code, $out] = hunt_fail(503, 'closed');
    }
    json_out($out, $code);
}
