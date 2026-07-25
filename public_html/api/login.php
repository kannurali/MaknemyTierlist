<?php
require_once __DIR__ . '/_bootstrap.php';

function verify_admin_password(string $password, string $hash): bool {
    return $hash !== '' && password_verify($password, $hash);
}

if (!defined('TESTING')) {
    start_admin_session();
    $body = read_json_body();
    $password = (string)($body['password'] ?? '');
    $cfg = app_config();
    if (verify_admin_password($password, $cfg['admin_hash'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        json_out(['ok' => true], 200);
    } else {
        usleep(400000); // ~0.4s throttle on failure
        json_out(['ok' => false], 401);
    }
}
