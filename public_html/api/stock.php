<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/stock.php';

// Сток для страницы /stock — GET /api/stock.php:
//   {"ok":true, "source":"Fool's eyes",
//    "normal":{"fruits":[{"key","name","price"}…], "ends":unix|null, "seen":unix} | null,
//    "mirage":{…} | null,
//    "catalog":[{"key","name","icon"}…]}
//
// Одинаков для всех, поэтому LiteSpeed держит его 30 секунд: открытая вкладка
// спрашивает раз в минуту, а после смены стока — чаще, пока не увидит новый.
// cron кладёт новый сток раз в минуту, так что 30 секунд кеша почти ничего не
// добавляют к задержке. Параметры в адресе .htaccess отбивает 403: с ними
// каждый запрос был бы новым ключом кеша.

if (!defined('TESTING')) {
    $pdo = db();
    header('Cache-Control: no-store');
    lscache_public(30);
    json_out(stock_public($pdo, tg_tierlist_state($pdo)));
}
