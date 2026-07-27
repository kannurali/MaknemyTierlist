<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';
require_once __DIR__ . '/lib/validate.php';

function handle_save(PDO $pdo, array $state, string $imagesDir, int $revMs): array {
    // Structure only at this point. The size cap must NOT run yet: a client
    // that skipped the upload step sends images inline, and base64 inflates
    // them ~33% — capping here would 400 a save that extraction fixes.
    $v = validate_structure($state);
    if (!$v['ok']) { return [400, ['ok' => false, 'error' => $v['error']]]; }

    try {
        $state = extract_embedded_images($state, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['ok' => false, 'error' => $e->getMessage()]];
    }

    // Size enforced AFTER extraction, when the blob carries URLs only.
    $v2 = validate_state($state);
    if (!$v2['ok']) { return [400, ['ok' => false, 'error' => $v2['error']]]; }

    $state['_rev'] = $revMs;
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare("UPDATE tierlist SET data = :d, rev = :r WHERE id = 1");
    $stmt->execute([':d' => $json, ':r' => $revMs]);
    return [200, ['ok' => true, 'rev' => $revMs]];
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $cfg = app_config();
    $revMs = (int)round(microtime(true) * 1000);
    [$status, $payload] = handle_save(db(), read_json_body(), $cfg['images_dir'], $revMs);
    json_out($payload, $status);
}
