<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

// Один параметр $kind на три вида картинок. Ветки рекламы и новостей пришли
// сюда с двумя разными дискриминаторами ($slot и $kind) на один и тот же
// вопрос «что это за картинка»; оставить оба значило бы, что четвёртый вид
// приведёт третий. Значения:
//
//   ''                     иконка предмета: 256 px, 500 КБ, PNG/JPG/WebP;
//   'news'                 картинка новости: 1280 px, 6 МБ (скриншот из игры
//                          весит куда больше 500 КБ ещё ДО сжатия — см.
//                          NEWS_IMAGE_MAX_BYTES в lib/images.php);
//   ключ CREATIVE_SPECS    рекламный макет: свой размер на слот, анимация
//                          разрешена, ответ сообщает, что реально положили.
//
// Неизвестное значение сюда не доходит — оно отсекается в блоке ниже, а не
// трактуется молча как иконка: тихий фолбэк прятал бы опечатку в слоте до
// того момента, когда рекламодатель увидит обрезанный до 256 px баннер.
function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file, string $kind = ''): array {
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
        // Всё, что не '' и не 'news', обязано быть слотом креатива. Проверка
        // именно такая, а не isset(CREATIVE_SPECS[$kind]): на неизвестном
        // слоте save_creative_bytes() бросает с названием слота в тексте, а
        // молчаливый уход в ветку иконки обрезал бы баннер до 256 px и отдал
        // 200, будто всё хорошо.
        if ($kind !== '' && $kind !== 'news') {
            return [200, save_creative_bytes($bytes, $imagesDir, $kind)];
        }
        $url = $kind === 'news'
            ? save_image_bytes($bytes, $imagesDir, NEWS_IMAGE_MAX_BYTES, ['png', 'jpg', 'webp'], NEWS_IMAGE_MAX_SIDE)
            : save_image_bytes($bytes, $imagesDir);
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
    $kind = (string)($body['kind'] ?? $_POST['kind'] ?? '');
    if ($kind !== '' && $kind !== 'news' && !isset(CREATIVE_SPECS[$kind])) {
        json_out(['error' => 'unknown upload kind'], 400);
        exit;
    }
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file, $kind);
    json_out($payload, $status);
}
