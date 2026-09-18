<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    require_post();
    // Без куки закрывать нечего: не заводим сессию, чтобы тут же её убить.
    if (resume_site_session()) {
        $_SESSION = [];
        session_destroy();
    }
    json_out(['ok' => true], 200);
}
