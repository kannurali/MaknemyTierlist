<?php
require_once __DIR__ . '/_bootstrap.php';

if (!defined('TESTING')) {
    require_post();
    // Сначала ключ входа: иначе resume_site_session() восстановил бы по нему
    // сессию, которую тут же и закрываем.
    remember_logout();
    // Без куки закрывать нечего: не заводим сессию, чтобы тут же её убить.
    if (resume_site_session()) {
        $_SESSION = [];
        session_destroy();
    }
    json_out(['ok' => true], 200);
}
