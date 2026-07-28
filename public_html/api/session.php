<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    start_admin_session();
    json_out(['admin' => is_admin()], 200);
}
