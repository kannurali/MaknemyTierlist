<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/images.php';

// Один параметр $kind на три вида картинок. Ветки рекламы и новостей пришли
// сюда с двумя разными дискриминаторами ($slot и $kind) на один и тот же
// вопрос «что это за картинка»; оставить оба значило бы, что четвёртый вид
// приведёт третий. Значения:
//
//   ''                     иконка предмета: 256 px, 500 КБ, PNG/JPG/WebP;
//   'news'                 картинка новости: свободная ширина (image_pct,
//                          10..100%) определяет потолок стороны — см.
//                          resolve_upload_max_side() ниже, 6 МБ (скриншот из
//                          игры весит куда больше 500 КБ ещё ДО сжатия — см.
//                          NEWS_IMAGE_MAX_BYTES в lib/images.php);
//   ключ CREATIVE_SPECS    рекламный макет: свой размер на слот, анимация
//                          разрешена, ответ сообщает, что реально положили.
//
// Неизвестное значение сюда не доходит — оно отсекается в блоке ниже, а не
// трактуется молча как иконка: тихий фолбэк прятал бы опечатку в слоте до
// того момента, когда рекламодатель увидит обрезанный до 256 px баннер.
//
// $pct — сырое значение image_pct из тела запроса, актуально только для
// kind='news' (см. resolve_upload_max_side() ниже); для иконки и креатива
// игнорируется.
function handle_upload(string $imagesDir, ?string $dataUrl, ?array $file, string $kind = '', $pct = null): array {
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
        $maxSide = resolve_upload_max_side($kind, $pct);
        $url = $kind === 'news'
            ? save_image_bytes($bytes, $imagesDir, NEWS_IMAGE_MAX_BYTES, ['png', 'jpg', 'webp'], $maxSide)
            : save_image_bytes($bytes, $imagesDir);
    } catch (RuntimeException $e) {
        return [400, ['error' => $e->getMessage()]];
    }
    $payload = ['url' => $url];
    // Ширина/высота — только для news: news_save.php кладёт их в БД как
    // подсказку для <img width/height> (задача 2 в
    // 2026-08-03-safari-memory-and-i18n-design.md), это её единственный
    // потребитель. Иконка предмета этих полей не ждёт — tests/upload_test.php
    // пинит форму её ответа как ['url'] и только, добавлять сюда лишние ключи
    // безвредно для JS, но ломает этот тест без единой причины на стороне
    // продукта.
    //
    // Значения — той картинки, что реально легла на диск (после
    // downscale_image_bytes внутри save_image_bytes), а не исходника:
    // подсказка обязана совпадать с тем, что браузер реально скачает. Читаем
    // файл обратно, а не расширяем возврат save_image_bytes() — та должна
    // остаться string-функцией, на неё пинится images_test.php.
    if ($kind === 'news') {
        $stored = @file_get_contents(rtrim($imagesDir, '/\\') . '/' . basename($url));
        $dims = $stored !== false ? @getimagesizefromstring($stored) : false;
        if ($dims !== false) {
            $payload['width'] = $dims[0];
            $payload['height'] = $dims[1];
        }
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
    // pct — свободная ширина картинки в процентах (10..100), выбранная в
    // редакторе; см. resolve_upload_max_side() выше.
    $pct = $body['pct'] ?? null;
    [$status, $payload] = handle_upload($cfg['images_dir'], $dataUrl, $file, $kind, $pct);
    json_out($payload, $status);
}
