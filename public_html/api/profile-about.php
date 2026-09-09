<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/profile.php';

// «О себе» — POST /api/profile-about.php {"about":"..."}
//
// Своё и только своё: чей текст правится, решает сессия, параметра с чужим id
// нет вовсе. Поэтому и проверять «а ваш ли это профиль» негде — вопрос просто
// не возникает.

/**
 * Возвращает [код, тело]. Отдельной функцией, а не прямо в ветке ниже,
 * чтобы тест мог позвать её без сети и заголовков — как handle_session()
 * и handle_profile_stats().
 *
 * Ответ несёт about ровно в том виде, в каком он лёг в базу: страница
 * рисует именно его, а не то, что человек набрал. Иначе после обрезки на
 * 280-м символе экран и база показывали бы разное до перезагрузки.
 */
function handle_profile_about(PDO $pdo, array $session, $raw): array {
    $me = profile_me($session);
    if ($me === '') { return [401, ['ok' => false, 'error' => 'unauthorized']]; }

    // is_string, а не приведение: {"about":{"a":1}} дал бы массив, и (string)
    // на нём печатает Warning перед телом ответа — JSON после этого не
    // разбирается.
    if (!is_string($raw)) { return [400, ['ok' => false, 'error' => 'bad_request']]; }

    $about = profile_about_clean($raw);

    try {
        $card = profile_card($pdo, $me, time());
    } catch (PDOException $e) {
        $card = null;
    }
    if ($card === null) { return [401, ['ok' => false, 'error' => 'unauthorized']]; }

    if (!profile_about_save($pdo, $me, $about)) {
        // Колонки about в базе нет — миграция не выполнена. Пятисотки тут
        // быть не должно (код исправен), но и «ok» тоже: человек увидел бы
        // «сохранено» и потерял текст на первой же перезагрузке.
        return [503, ['ok' => false, 'error' => 'unavailable']];
    }

    return [200, ['ok' => true, 'about' => $about]];
}

if (!defined('TESTING')) {
    require_post();
    start_site_session();

    // Предел по ВОШЕДШЕМУ, а не по адресу: за одним IP сидит целый
    // интернет-клуб, и общий счётчик наказывал бы соседей за чужую
    // активность. Не вошёл — до предела дело не дойдёт, обработчик ответит
    // 401.
    $me = profile_me($_SESSION);
    if ($me !== '' && !rate_limit_allow('profile_about', $me, 30, 3600, time())) {
        json_out(['ok' => false, 'error' => 'rate_limited'], 429);
        exit;
    }

    header('Cache-Control: no-store');
    $body = read_json_body();
    [$status, $payload] = handle_profile_about(db(), $_SESSION, $body['about'] ?? null);
    json_out($payload, $status);
}
