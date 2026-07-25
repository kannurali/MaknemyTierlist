<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    start_admin_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true], 200);
}
