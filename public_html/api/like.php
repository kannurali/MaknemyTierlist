<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/likes.php';

// Лайк тирлиста — только вошедшему и один на аккаунт (api/lib/likes.php).
// Голос и счётчик меняются в одной транзакции: счётчик сдвигается, только
// если голос действительно поставлен или снят.
function handle_like(PDO $pdo, string $me, array $body, int $now): array {
    if ($me === '') { return [401, ['ok' => false, 'error' => 'not_logged_in']]; }
    $dir = (isset($body['dir']) && (int)$body['dir'] === -1) ? -1 : 1;

    $pdo->beginTransaction();
    try {
        if (like_vote($pdo, $me, LIKE_TIERLIST, $dir, $now)) {
            // Atomic + underflow-safe + portable (MySQL & SQLite): the WHERE guard
            // blocks the update when it would drop below zero.
            $pdo->prepare("UPDATE likes SET count = count + :inc WHERE id = 1 AND count + :chk >= 0")
                ->execute([':inc' => $dir, ':chk' => $dir]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        return [503, ['ok' => false, 'error' => 'not_ready']];
    }
    $count = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    return [200, ['ok' => true, 'likes' => $count, 'liked' => $dir === 1]];
}

if (!defined('TESTING')) {
    require_post();
    // Rate limit: 20 toggles/hour per IP — far above real use (a visitor flips
    // like/unlike a couple of times). 429 → the client rolls the UI back.
    if (!rate_limit_allow('like', client_key(), 20, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }
    $me = resume_site_session() ? profile_me($_SESSION) : '';
    [$status, $payload] = handle_like(db(), $me, read_json_body(), time());
    json_out($payload, $status);
}
