<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_state(PDO $pdo): array {
    $rev   = (int)$pdo->query("SELECT rev FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    // Campaigns carry their own revision so a creative swap does not
    // invalidate the immutable-cached tier list for every visitor. Missing
    // table or row reads as 0, i.e. "no campaigns", never as an error.
    $promoRev = 0;
    try {
        $promoRev = (int)$pdo->query("SELECT rev FROM promo WHERE id = 1")->fetchColumn();
    } catch (PDOException $e) {
        $promoRev = 0;
    }
    return [200, ['rev' => $rev, 'likes' => $likes, 'promoRev' => $promoRev]];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    [$status, $payload] = handle_state(db());
    json_out($payload, $status);
}
