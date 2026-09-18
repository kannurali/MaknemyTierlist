<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_state(PDO $pdo): array {
    $rev   = (int)$pdo->query("SELECT rev FROM tierlist WHERE id = 1")->fetchColumn();
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    // Campaigns carry their own revision so a creative swap does not
    // invalidate the immutable-cached tier list for every visitor. Missing
    // table or row reads as 0, i.e. "no campaigns", never as an error.
    $promoRev = 0;
    try {
        $promoRev = (int)$pdo->query("SELECT rev FROM promo WHERE id = 1")->fetchColumn();
    } catch (PDOException $e) {
        $promoRev = 0;
    }
    return [200, ['rev' => $rev, 'likes' => $likes, 'promoRev' => $promoRev]];
}

if (!defined('TESTING')) {
    [$status, $payload] = handle_state(db());
    // Этот адрес опрашивает каждая открытая вкладка раз в 30 секунд. LiteSpeed
    // держит ответ 5 секунд: сколько бы запросов ни пришло за это время,
    // PHP и база отработают один раз. Лайки и ревизия отстают максимум на эти
    // секунды — при опросе раз в 30 это незаметно. Браузеру по-прежнему
    // no-store. Параметры в адресе .htaccess отбивает 403: с ними каждый
    // запрос был бы новым ключом кеша.
    header('Cache-Control: no-store');
    lscache_public(5);
    json_out($payload, $status);
}
