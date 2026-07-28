<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_tierlist(PDO $pdo): array {
    $raw   = $pdo->query("SELECT data FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    $tierlist = $raw ? json_decode($raw, true) : null;
    return [200, ['tierlist' => $tierlist, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    // Each rev is immutable, so a request tagged ?rev=N can cache forever.
    if (isset($_GET['rev']) && $_GET['rev'] !== '') {
        header('Cache-Control: public, max-age=31536000, immutable');
    } else {
        header('Cache-Control: no-cache');
    }
    [$status, $payload] = handle_tierlist(db());
    json_out($payload, $status);
}
