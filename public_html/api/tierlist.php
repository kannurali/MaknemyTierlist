<?php
require_once __DIR__ . '/_bootstrap.php';

// Тирлист целиком. Данные отдаются только по адресу текущей ревизии
// ?rev=<n>: такой ответ не меняется никогда, браузер держит его год, а
// LiteSpeed — в своём кеше, и повторный запрос обходится без PHP. Любой
// другой адрес — без ?rev=, со старой ревизией, с мусором — уводится 302 на
// текущую.
//
// Раньше полный ответ с immutable получал любой ?rev=. Случайное число в
// адресе обходило любой кеш, и каждый такой запрос стоил PHP, базы и ~21 КБ
// трафика. Теперь он стоит одного короткого запроса к базе и редиректа.
// Отставший клиент (ревизия сменилась между опросом и загрузкой) получает
// свежие данные на один редирект позже.
//
// rev и data читаются одним запросом: иначе публикация между двумя чтениями
// положила бы новые данные под адрес старой ревизии — навсегда.
function tierlist_route(PDO $pdo, $rawRev): array {
    $row = $pdo->query("SELECT data, rev FROM tierlist WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $rev = $row ? (int)$row['rev'] : 0;
    if (parse_rev_param($rawRev) !== $rev) {
        return [302, ['location' => '/api/tierlist.php?rev=' . $rev]];
    }
    $likes = (int)$pdo->query("SELECT count FROM likes WHERE id = 1")->fetchColumn();
    $tierlist = ($row && $row['data']) ? json_decode($row['data'], true) : null;
    return [200, ['tierlist' => $tierlist, 'likes' => $likes]];
}

if (!defined('TESTING')) {
    [$status, $payload] = tierlist_route(db(), $_GET['rev'] ?? null);
    if ($status === 302) {
        header('Cache-Control: no-store');
        lscache_off();
        header('Location: ' . $payload['location'], true, 302);
        exit;
    }
    header('Cache-Control: public, max-age=31536000, immutable');
    lscache_public(86400);
    json_out($payload, $status);
}
