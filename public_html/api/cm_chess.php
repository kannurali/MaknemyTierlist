<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';
require_once __DIR__ . '/lib/cm/cm.php';

// Шахматная партия чат-менеджера в мини-приложении (/tg-app?…=ch<id>,
// js/tg-chess.js). Устройство партии — api/lib/cm/chess.php.
// POST {"init": "<Telegram.WebApp.initData>", "game": <id>}
//   → состояние партии (cm_chess_view); мини-приложение спрашивает его раз
//     в пару секунд, пока партия идёт. Заодно ловит вышедший срок хода.
// POST {…, "move": "e2e4"} — ход (превращение — "e7e8q").
// POST {…, "act": "resign" | "draw" | "draw_yes" | "draw_no"}.
//   Отказ хода или действия — 409 {"ok": false, "error": "illegal" | "turn" |
//   "over", "state": …}: мини-приложение откатывает доску к state.
// POST {…, "photos": true} → {"w": адрес | null, "b": …} — аватарки игроков
//   из Telegram, копией на сайте (как у билета розыгрыша, gw_tg.php).
// 403 — initData не прошли проверку подписи или устарели; 404 — партии нет
// (или чат-менеджер выключен); 429 — слишком часто.
//
// Кто играет, сервер берёт только из initData: их подписывает Telegram
// токеном бота (gw_tg_app_auth). Номер партии — из тела или из start_param.

const CM_CHESS_MOVES_PER_HOUR = 600;
const CM_CHESS_ACTS_PER_HOUR = 60;
const CM_CHESS_PHOTOS_PER_HOUR = 30;

function cm_chess_api_game(array $body, array $auth): int {
    $g = $body['game'] ?? null;
    if (is_int($g) && $g > 0) { return $g; }
    if (is_string($g) && preg_match('/^[1-9][0-9]{0,9}\z/', $g)) { return (int)$g; }
    return preg_match('/^ch([1-9][0-9]{0,9})\z/', (string)$auth['start'], $m) ? (int)$m[1] : 0;
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();

    $cfg = app_config();
    $tg  = tg_config($cfg);
    $body = read_json_body();
    $init = is_string($body['init'] ?? null) ? $body['init'] : '';
    $auth = tg_enabled($tg) ? gw_tg_app_auth($init, $tg['token'], time()) : null;
    if ($auth === null) {
        json_out(['ok' => false, 'error' => 'auth'], 403);
        exit;
    }
    $pdo = db();
    $uid = (int)$auth['user']['id'];
    $id = cm_chess_api_game($body, $auth);
    $g = ($id > 0 && cm_enabled($cfg) && cm_ready($pdo) && cm_chess_ready($pdo)) ? cm_chess_get($pdo, $id) : null;
    if ($g === null) {
        json_out(['ok' => false, 'error' => 'none'], 404);
        exit;
    }

    if (($body['photos'] ?? null) === true) {
        if (!rate_limit_allow('cm_chess_photo', (string)$uid, CM_CHESS_PHOTOS_PER_HOUR, 3600, time())) {
            json_out(['ok' => false, 'error' => 'rate'], 429);
            exit;
        }
        $dir = is_string($cfg['images_dir'] ?? null) ? $cfg['images_dir'] : '';
        $send = tg_http($tg['token']);
        $fetch = gw_tg_file_fetch($tg['token']);
        $out = [];
        foreach (['w' => $g['white_id'], 'b' => $g['black_id']] as $side => $who) {
            $src = gw_tg_user_photo($send, $fetch, $dir, $who, time(), gw_tg_photo_state());
            $out[$side] = $src !== '' ? TG_SITE . $src : null;
        }
        json_out($out);
        exit;
    }

    $nowMs = (int)round(microtime(true) * 1000);
    $err = '';
    $ended = false;
    $move = $body['move'] ?? null;
    $act = $body['act'] ?? null;
    if (is_string($move) || is_string($act)) {
        $bucket = is_string($move) ? 'cm_chess_move' : 'cm_chess_act';
        $max = is_string($move) ? CM_CHESS_MOVES_PER_HOUR : CM_CHESS_ACTS_PER_HOUR;
        if (!rate_limit_allow($bucket, (string)$uid, $max, 3600, time())) {
            json_out(['ok' => false, 'error' => 'rate'], 429);
            exit;
        }
        [$err, $g, $ended] = is_string($move)
            ? cm_chess_play($pdo, $g, $uid, substr($move, 0, 5), $nowMs)
            : cm_chess_act($pdo, $g, $uid, substr($act, 0, 8), $nowMs);
    } else {
        $done = cm_chess_due($pdo, $g, $nowMs);
        if ($done !== null) {
            $g = $done;
            $ended = true;
        }
    }
    $state = cm_chess_view($pdo, $g, $uid, $nowMs);
    if ($err !== '') {
        json_out(['ok' => false, 'error' => $err, 'state' => $state], 409);
    } else {
        json_out($state);
    }
    // Партия закончилась этим запросом — итог в сообщение чата, уже после
    // ответа: мини-приложение не ждёт Telegram.
    if ($ended) {
        tg_finish_response();
        cm_chess_announce($pdo, $cfg, tg_http($tg['token']), $g, time());
    }
}
