<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/hunt.php';

// Вкладка «Ивент» (/admin/hunt, js/hunt-admin.js) —
// POST /api/hunt_admin.php {"action": …}. Только admin_ids.
//
//   get                      → сезон, сводка, участники с билетами, выигрыши
//   save {hunt}              → сохранить сезон (после старта дата и награды
//                              не меняются)
//   draw                     → разыграть призы (после конца продажи билетов)
//   win {id, status, note}   → выдача: won → confirmed → queued → given
//   redraw {id}              → приз просроченного — следующему по очереди
//   void {id, reason}        → аннулировать билет
//
// Всё, что меняет публичную картину, сбрасывает кеш api/hunt.php (тег hunt).

function handle_hunt_admin(PDO $pdo, array $cfg, array $body, int $now): array {
    if (!hunt_ready($pdo)) { return [503, ['ok' => false, 'error' => 'not_ready']]; }
    $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
    if ($action === 'get') {
        return [200, hunt_admin_state($pdo, $now)];
    }
    if ($action === 'save') {
        return hunt_admin_save($pdo, is_array($body['hunt'] ?? null) ? $body['hunt'] : [], $now);
    }
    if ($action === 'draw') {
        $hunt = hunt_admin_current($pdo);
        return $hunt ? hunt_draw($pdo, $cfg, (int)$hunt['id'], $now) : hunt_fail(404, 'bad');
    }
    if ($action === 'win') {
        $status = isset($body['status']) && is_string($body['status']) ? $body['status'] : '';
        $note = isset($body['note']) && is_string($body['note']) ? $body['note'] : '';
        return hunt_win_set($pdo, read_row_id($body), $status, $note, $now);
    }
    if ($action === 'redraw') {
        return hunt_redraw($pdo, $cfg, read_row_id($body), $now);
    }
    if ($action === 'void') {
        $reason = isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : '';
        return hunt_void_ticket($pdo, read_row_id($body), $reason);
    }
    return hunt_fail(400, 'bad');
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    require_admin();
    $body = read_json_body();
    try {
        [$status, $payload] = handle_hunt_admin(db(), app_config(), $body, time());
    } catch (Throwable $e) {
        error_log('hunt_admin: ' . $e->getMessage());
        [$status, $payload] = [500, ['ok' => false, 'error' => 'server']];
    }
    if ($status === 200 && ($body['action'] ?? '') !== 'get') {
        header('X-LiteSpeed-Purge: tag=hunt');
    }
    json_out($payload, $status);
}
