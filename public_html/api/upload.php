<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file): array {
    $bytes = null;
    if (is_string($dataUrl) && $dataUrl !== '') {
        $bytes = data_url_to_bytes($dataUrl);
    } elseif ($file && isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
        $bytes = file_get_contents($file['tmp_name']);
    }
    if ($bytes === null || $bytes === false || $bytes === '') {
        return [400, ['error' => 'no image provided']];
    }
    try {
        $url = save_image_bytes($bytes, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['error' => $e->getMessage()]];
    }
    return [200, ['url' => $url]];
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $cfg = app_config();
    $body = read_json_body();
    $dataUrl = is_string($body['data'] ?? null) ? $body['data'] : null;
    $file = $_FILES['image'] ?? null;
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file);
    json_out($payload, $status);
}
