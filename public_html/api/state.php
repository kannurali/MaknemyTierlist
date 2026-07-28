<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_state(PDO $pdo): array {
    $rev   = (int)$pdo->query("SELECT rev FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    return [200, ['rev' => $rev, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    [$status, $payload] = handle_state(db());
    json_out($payload, $status);
}
