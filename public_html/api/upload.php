<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file, int $maxSide = ICON_MAX_SIDE): array {
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
        $url = save_image_bytes($bytes, $imagesDir, 512000, $maxSide);
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
    // kind=news поднимает потолок стороны: иконка предмета и картинка новости
    // рисуются в совершенно разных размерах. Неизвестное значение молча
    // означает иконку — так добавление третьего вида не сможет случайно
    // распечатать память под чужой потолок.
    $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
    $maxSide = $kind === 'news' ? NEWS_IMAGE_MAX_SIDE : ICON_MAX_SIDE;
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file, $maxSide);
    json_out($payload, $status);
}
