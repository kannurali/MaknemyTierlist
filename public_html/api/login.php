<?php
require_once __DIR__ . '/_bootstrap.php';

// Сколько проверок пароля в минуту пропускает сайт целиком, со всех адресов
// вместе. Проверка bcrypt — это доли секунды чистого процессора, а ядро на
// тарифе одно: без общего потолка поток неверных паролей с сотни адресов
// съел бы его весь, и лёг бы сайт, а не только вход. Настоящему админу и
// двадцати попыток в минуту хватит с избытком.
const LOGIN_CHECKS_PER_MINUTE = 20;

function verify_admin_password(string $password, string $hash): bool {
    return $hash !== '' && password_verify($password, $hash);
}

if (!defined('TESTING')) {
    require_post();

    $now = time();
    $key = client_key();
    $wait = throttle_retry_after($key, $now);
    if ($wait > 0) {
        json_out(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => $wait], 429);
        exit;
    }
    if (!rate_limit_allow('login_checks', 'all', LOGIN_CHECKS_PER_MINUTE, 60, $now)) {
        json_out(['ok' => false, 'error' => 'too_many_attempts', 'retry_after' => 60], 429);
        exit;
    }

    $body = read_json_body();
    $password = (string)($body['password'] ?? '');
    $cfg = app_config();
    if (verify_admin_password($password, $cfg['admin_hash'] ?? '')) {
        throttle_clear($key);
        // Сессия заводится только после верного пароля. Раньше она стартовала
        // до проверки, и каждый неверный запрос оставлял файл сессии.
        start_site_session();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        json_out(['ok' => true], 200);
    } else {
        // Без задержки. Прежняя пауза до 2 секунд держала процесс PHP, а их на
        // тарифе 20: двадцать параллельных неверных паролей занимали все, и сайт
        // отвечал 508 всем. От подбора защищает блокировка после пяти ошибок.
        throttle_register_failure($key, $now);
        json_out(['ok' => false], 401);
    }
}
