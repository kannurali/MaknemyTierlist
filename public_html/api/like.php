<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_like(PDO $pdo, array $body): array {
    $dir = (isset($body['dir']) && (int)$body['dir'] === -1) ? -1 : 1;
    // Atomic + underflow-safe + portable (MySQL & SQLite): the WHERE guard
    // blocks the update when it would drop below zero.
    $stmt = $pdo->prepare(
        "UPDATE likes SET count = count + :inc WHERE id = 1 AND count + :chk >= 0"
    );
    $stmt->execute([':inc' => $dir, ':chk' => $dir]);
    $count = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    return [200, ['ok' => true, 'likes' => $count]];
}

if (!defined('TESTING')) {
    require_post();
    // Rate limit: the endpoint is unauthenticated by design, so without a cap
    // one script could pump the counter to any value. 20 toggles/hour per IP —
    // far above real use (a visitor flips like/unlike a couple of times).
    // 429 → the client's sendLike() sees !r.ok and rolls the UI back.
    $key = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!rate_limit_allow('like', $key, 20, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }
    [$status, $payload] = handle_like(db(), read_json_body());
    json_out($payload, $status);
}
