<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

// $slot === '' keeps the icon path exactly as it was: 256 px, PNG/JPG/WebP
// only. A known ad slot switches to the creative path, which keeps the banner
// at its real size, allows animation, and reports back what it stored.
function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file, string $slot = ''): array {
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
        if ($slot !== '') {
            return [200, save_creative_bytes($bytes, $imagesDir, $slot)];
        }
        $url = save_image_bytes($bytes, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['error' => $e->getMessage()]];
    }
    return [200, ['url' => $url]];
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    // Admin-only, but an admin session plus a stuck retry loop can still fill
    // the disk with sha1-named files; nothing throttled this endpoint before.
    if (!rate_limit_allow('upload', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 60, 60, time())) {
        json_out(['error' => 'rate_limited'], 429);
        exit;
    }
    $cfg = app_config();
    $body = read_json_body();
    $dataUrl = is_string($body['data'] ?? null) ? $body['data'] : null;
    $file = $_FILES['image'] ?? null;
    $slot = (string)($body['slot'] ?? $_POST['slot'] ?? '');
    if ($slot !== '' && !isset(CREATIVE_SPECS[$slot])) {
        json_out(['error' => 'unknown ad slot'], 400);
        exit;
    }
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file, $slot);
    json_out($payload, $status);
}
