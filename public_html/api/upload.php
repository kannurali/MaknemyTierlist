<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file, int $maxSide = ICON_MAX_SIDE, int $maxBytes = 512000): array {
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
        $url = save_image_bytes($bytes, $imagesDir, $maxBytes, $maxSide);
    } catch (RuntimeException $e) {
        return [400, ['error' => $e->getMessage()]];
    }
    $payload = ['url' => $url];
    // Ширина/высота — той картинки, что реально легла на диск (после
    // downscale_image_bytes внутри save_image_bytes), а не исходника:
    // news_save.php кладёт их в БД как подсказку для <img width/height>
    // (задача 2 в 2026-08-03-safari-memory-and-i18n-design.md), и подсказка
    // обязана совпадать с тем, что браузер реально скачает. Читаем файл
    // обратно, а не расширяем возврат save_image_bytes() — та должна
    // остаться string-функцией, на неё пинится images_test.php.
    $stored = @file_get_contents(rtrim($imagesDir, '/\\') . '/' . basename($url));
    $dims = $stored !== false ? @getimagesizefromstring($stored) : false;
    if ($dims !== false) {
        $payload['width'] = $dims[0];
        $payload['height'] = $dims[1];
    }
    return [200, $payload];
}

// Отображает kind+pct тела запроса в потолок стороны. kind !== 'news' — это
// иконка предмета, единственный потолок для неё — ICON_MAX_SIDE, и pct к
// этому пути вообще не относится. kind === 'news' с нераспознанным,
// отсутствующим или вне диапазона pct откатывается на
// news_image_max_side_for_pct() по умолчанию — то есть на самый маленький
// потолок среди допустимых значений, а не на самый большой, ровно как в
// images.php. $pct принимает то, что реально приходит из JSON-тела —
// int|string|null, — а не только string: сам разбор и защита от мусора
// живут внутри news_image_max_side_for_pct().
function resolve_upload_max_side(string $kind, $pct): int {
    return $kind === 'news' ? news_image_max_side_for_pct($pct) : ICON_MAX_SIDE;
}

if (!defined('TESTING')) {
    require_post();
    require_admin();
    $cfg = app_config();
    $body = read_json_body();
    $dataUrl = is_string($body['data'] ?? null) ? $body['data'] : null;
    $file = $_FILES['image'] ?? null;
    // kind=news поднимает потолок веса исходника: иконка предмета и картинка
    // новости рисуются в совершенно разных размерах, а обычный скриншот
    // весит куда больше 500 КБ ещё до downscale (см. NEWS_IMAGE_MAX_BYTES в
    // lib/images.php). Неизвестное значение молча означает иконку — так
    // добавление третьего вида не сможет случайно распечатать память или
    // диск под чужой потолок. pct — свободная ширина картинки в процентах
    // (10..100), выбранная в редакторе; она же определяет потолок стороны
    // внутри news, см. resolve_upload_max_side() выше.
    $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
    $pct = $body['pct'] ?? null;
    $maxSide = resolve_upload_max_side($kind, $pct);
    $maxBytes = $kind === 'news' ? NEWS_IMAGE_MAX_BYTES : 512000;
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file, $maxSide, $maxBytes);
    json_out($payload, $status);
}
