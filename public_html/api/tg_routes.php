<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/giveaway.php';

// Галочки вкладки «Каналы» — обычная форма со страницы /admin/channels:
//
//   POST r[<chat_id>][<тема>]=1 — отмечено, чего нет в запросе — снято
//
// В ответ 303 обратно на страницу с итогом в ?done=. Чужой сайт такую форму не
// отправит: кука сессии SameSite=Lax на межсайтовый POST не идёт.

function handle_tg_routes(PDO $pdo, $raw): string {
    try {
        return tg_routes_save($pdo, array_keys(gw_channels($pdo)), $raw);
    } catch (PDOException $e) {
        error_log('tg routes: ' . $e->getMessage());
        return 'not_ready';
    }
}

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    require_admin();
    $done = handle_tg_routes(db(), $_POST['r'] ?? []);
    header('Location: /admin/channels?done=' . rawurlencode($done), true, 303);
    exit;
}
