<?php
require_once __DIR__ . '/_bootstrap.php';

function verify_admin_password(string $password, string $hash): bool {
    return $hash !== '' && password_verify($password, $hash);
}

if (!defined('TESTING')) {
    require_post();
    start_admin_session();

    $now = time();
    $key = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $wait = throttle_retry_after($key, $now);
    if ($wait > 0) {
        json_out(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $wait], 429);
        exit;
    }

    $body = read_json_body();
    $password = (string)($body['password'] ?? '');
    $cfg = app_config();
    if (verify_admin_password($password, $cfg['admin_hash'] ?? '')) {
        throttle_clear($key);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        json_out(['ok' => true], 200);
    } else {
        // Escalating delay on top of the lockout — slows serial guessing before
        // the lock even engages.
        $fails = throttle_register_failure($key, $now);
        usleep(min($fails, 5) * 400000);
        json_out(['ok' => false], 401);
    }
}
