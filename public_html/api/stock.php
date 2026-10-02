<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/stock.php';

// Сток для страницы /stock — GET /api/stock.php:
//   {"ok":true, "source":"Fool's eyes",
//    "normal":{"fruits":[{"key","name","price"}…], "ends":unix|null, "seen":unix} | null,
//    "mirage":{…} | null,
//    "catalog":[{"key","name","icon"}…]}
//
// Одинаков для всех, поэтому LiteSpeed держит его 30 секунд. Открытая вкладка
// между сменами спрашивает раз в пять минут, к моменту смены просыпается сама
// и спрашивает каждые 20 секунд, пока не увидит новый сток. Параметры в
// адресе .htaccess отбивает 403: с ними каждый запрос был бы новым ключом кеша.

if (!defined('TESTING')) {
    $pdo = db();
    header('Cache-Control: no-store');
    lscache_public(30);
    json_out(stock_public($pdo, tg_tierlist_state($pdo)));
}
