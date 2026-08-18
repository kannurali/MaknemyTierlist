<?php
require_once __DIR__ . '/_bootstrap.php';

function handle_news_delete(PDO $pdo, array $body): array {
    $id = read_row_id($body);
    if ($id <= 0) { return [400, ['ok' => false, 'error' => 'bad id']]; }

    // Отдельная проверка существования — по той же причине, что и в
    // news_save.php: rowCount() у DELETE переносим, но 404 должен отличаться
    // от «удалили», иначе двойной клик по ✕ выглядит как успех обоих раз.
    $chk = $pdo->prepare("SELECT COUNT(*) FROM news WHERE id = :id");
    $chk->execute([':id' => $id]);
    if ((int)$chk->fetchColumn() === 0) {
        return [404, ['ok' => false, 'error' => 'not found']];
    }

    $pdo->prepare("DELETE FROM news WHERE id = :id")->execute([':id' => $id]);
    return [200, ['ok' => true]];
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    [$status, $payload] = handle_news_delete(db(), read_json_body());
    json_out($payload, $status);
}
