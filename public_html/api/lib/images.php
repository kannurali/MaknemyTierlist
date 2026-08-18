<?php
// Image helpers: type sniffing, data-url decode, hashed save, embedded extraction.

// Longest side an item icon is stored at.
//
// Measured on the real page: an icon paints at most 133 device px (desktop,
// 1040 px stage, DPR 2) and 123 device px (iPhone, DPR 3). Sources were being
// stored at 400-800 px, so WebKit decoded 800x800x4 = 2.4 MB per icon and kept
// it in the decoded-image cache. Across 113 items that is 63 MB of bitmaps for
// a page that shows them at thumbnail size — enough that Safari starts evicting
// decoded images (icons visibly vanish), re-decodes them on scroll (icons
// appear late) and fails to back composited layers (black rectangles).
// 256 keeps roughly a 2x reserve over the largest real paint size.
const ICON_MAX_SIDE = 256;

// Потолок на число пикселей ИСХОДНИКА, который мы соглашаемся декодировать.
// 16 Мпикс — это 64 МБ битмапа, что переживает даже memory_limit = 128M.
const MAX_SOURCE_PIXELS = 16000000;

// Потолки стороны картинки новости — по размеру, который админ выбрал в
// редакторе (news.js NEWS.IMAGE_SIZES: small/medium/full), а не один общий
// потолок на всех: sz-small рисует картинку на 30% ширины карточки, но до
// этой правки она всё равно декодировалась в полный рост.
//
// Вывод из реальной геометрии карточки (public_html/css/news.css). Связывает
// именно ТЕЛЕФОН, а не десктоп: @media (max-width: 720px) там делает все три
// размера на всю ширину, поэтому телефонный paint у small/medium — не 30%/55%
// шире, а 100%:
//   телефон:  .stage 96vw ≈ 360 CSS, .nw-feed 92cqw ≈ 331 CSS, минус паддинг
//             .nw-card 14px×2 → 303 CSS px, при DPR 3 → 909 device px;
//   десктоп:  .stage 1040, .nw-feed 84.63cqw = 880, минус паддинг 20px×2 →
//             840 CSS px; sz-small 30% → 252 CSS (504 device при DPR 2),
//             sz-medium 55% → 462 CSS (924 device).
// Для small и medium телефонные 909 device px больше обоих десктопных чисел
// (504 и 924), поэтому телефон и определяет потолок — 1024, с запасом над
// 909. full картинка идёт на всю ширину сцены на обеих платформах и остаётся
// на прежних 1280.
const NEWS_IMAGE_SIDE_SMALL  = 1024;
const NEWS_IMAGE_SIDE_MEDIUM = 1024;
const NEWS_IMAGE_SIDE_FULL   = 1280;

// Ключ размера (тот же, что NEWS.IMAGE_SIZES в news.js и NEWS_IMAGE_SIZES в
// news_save.php) → потолок стороны в пикселях.
const NEWS_IMAGE_MAX_SIDE_BY_SIZE = [
    'small'  => NEWS_IMAGE_SIDE_SMALL,
    'medium' => NEWS_IMAGE_SIDE_MEDIUM,
    'full'   => NEWS_IMAGE_SIDE_FULL,
];

// Отсутствующий или нераспознанный ключ откатывается на САМЫЙ МАЛЕНЬКИЙ
// потолок среди новостных размеров, а не на самый большой (1280): смысл
// отката — не разрешить более тяжёлую картинку, чем нужно, если фронт
// прислал не то, чего ждёт сервер, а не молча выдать лучшее качество.
function news_image_max_side(string $size): int {
    return NEWS_IMAGE_MAX_SIDE_BY_SIZE[$size] ?? NEWS_IMAGE_SIDE_SMALL;
}

// Потолок байтов ИСХОДНИКА для картинки новости — отдельный от 500 КБ,
// который save_image_bytes() применяет по умолчанию (и на который завязана
// иконка предмета, ICON_MAX_SIDE = 256, см. tests/images_test.php). Обычный
// скриншот Blox Fruits — это 1-3 МБ PNG ДО сжатия: 500 КБ отсекали его ещё до
// downscale_image_bytes(), то есть до того, как сработает сам потолок в
// 1280 px, и админ получал "image too large" без какого-либо способа
// опубликовать картинку из приложения.
//
// Реальный тормоз по памяти — не этот байтовый лимит, а MAX_SOURCE_PIXELS
// (16 Мпикс) в downscale_image_bytes(): он проверяется ДО imagecreatefromstring
// и держит декодированный битмап под ~64 МБ независимо от веса файла на
// диске (вес и число пикселей не связаны — см. комментарий там же). Значит
// этот байтовый потолок можно держать щедрым: он всего лишь отсекает совсем
// неадекватные тела запроса, а не защищает память. 6 МБ — это дважды больше
// худшего реального скриншота (3 МБ) с запасом на новые телефоны с более
// плотными экранами, но всё ещё далеко от 64 МБ битмапа, которые уже покрыты
// MAX_SOURCE_PIXELS.
const NEWS_IMAGE_MAX_BYTES = 6 * 1024 * 1024;

function image_ext_for(string $bytes): ?string {
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') { return 'webp'; }
    return null;
}

function data_url_to_bytes(string $dataUrl): ?string {
    if (strncmp($dataUrl, 'data:', 5) !== 0) { return null; }
    $comma = strpos($dataUrl, ',');
    if ($comma === false) { return null; }
    $meta = substr($dataUrl, 5, $comma - 5);
    $payload = substr($dataUrl, $comma + 1);
    if (strpos($meta, ';base64') !== false) {
        $decoded = base64_decode($payload, true);
        return $decoded === false ? null : $decoded;
    }
    return rawurldecode($payload);
}

// Shrink an image so its longest side is at most $maxSide, keeping the source
// format and the alpha channel. Images already within the cap are returned
// byte-for-byte: re-encoding them would change their sha1 filename on every
// upload and break both dedup and the immutable cache headers.
//
// Hosts without the matching GD encoder are the one case where the original is
// kept as-is — an oversized icon is a performance problem, a rejected upload
// is a broken admin panel.
function downscale_image_bytes(string $bytes, int $maxSide = ICON_MAX_SIDE): string {
    $size = @getimagesizefromstring($bytes);
    if ($size === false) {
        throw new RuntimeException('unreadable image');
    }
    [$w, $h] = $size;
    if ($w <= $maxSide && $h <= $maxSide) { return $bytes; }

    // Вес файла ничего не говорит о размере распакованного битмапа: однотонный
    // PNG на 252 КБ спокойно объявляет 9000x9000 и требует 324 МБ при декоде.
    // Раньше байты просто клались на диск, теперь их декодирует сервер, и
    // upload.php упирается в memory_limit. Это фатал, а не исключение — catch
    // в upload.php:17 его не видит, и админ получает голый 500 без причины.
    // Проверяем до imagecreatefromstring: иконка рисуется в ~130 px, так что
    // 16 Мпикс (4000x4000) — уже заведомо не иконка.
    if ($w * $h > MAX_SOURCE_PIXELS) {
        throw new RuntimeException('image dimensions too large');
    }

    $ext = image_ext_for($bytes);
    $encoder = ['png' => 'imagepng', 'jpg' => 'imagejpeg', 'webp' => 'imagewebp'][$ext] ?? null;
    if ($encoder === null || !function_exists($encoder)) { return $bytes; }

    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        throw new RuntimeException('unreadable image');
    }
    $scale = $maxSide / max($w, $h);
    $dstW = max(1, (int)round($w * $scale));
    $dstH = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Copy alpha through instead of blending it onto black, otherwise every
    // transparent icon gains a black box.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $w, $h);

    ob_start();
    if ($ext === 'jpg')       { imagejpeg($dst, null, 88); }
    elseif ($ext === 'webp')  { imagewebp($dst, null, 88); }
    else                      { imagepng($dst, null, 8); }
    $out = ob_get_clean();

    return ($out === false || $out === '') ? $bytes : $out;
}

function save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000, int $maxSide = ICON_MAX_SIDE): string {
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('image too large');
    }
    $ext = image_ext_for($bytes);
    if ($ext === null) {
        throw new RuntimeException('unsupported image type');
    }
    // Downscale BEFORE hashing: the filename must describe the bytes on disk,
    // or the same source would be written again under a new name every upload.
    $bytes = downscale_image_bytes($bytes, $maxSide);
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = sha1($bytes) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $name;
    if (!file_exists($path)) {
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('failed to write image');
        }
    }
    return '/images/' . $name;
}

// Every place the state stores an image: tier logos, item icons, the ad banner
// and the donate QR. Passing $rewrite over all of them keeps callers from
// re-walking the structure (and from forgetting one when a new caller shows up).
//
// Missing an entry here is not cosmetic. extract_embedded_images() would leave
// that image inline as a data: URL and eat the 512 KB state budget, and
// downscale_stored_images() builds its orphan list from this same walk — so an
// unvisited file looks unreferenced and a cleanup run deletes it.
function walk_state_images(array $state, callable $rewrite): array {
    if (isset($state['tiers']) && is_array($state['tiers'])) {
        foreach ($state['tiers'] as &$tier) {
            if (isset($tier['logo'])) { $tier['logo'] = $rewrite($tier['logo']); }
            if (isset($tier['items']) && is_array($tier['items'])) {
                foreach ($tier['items'] as &$item) {
                    if (isset($item['icon'])) { $item['icon'] = $rewrite($item['icon']); }
                }
                unset($item);
            }
        }
        unset($tier);
    }
    if (isset($state['ad']['image'])) {
        $state['ad']['image'] = $rewrite($state['ad']['image']);
    }
    if (isset($state['donate']['qr'])) {
        $state['donate']['qr'] = $rewrite($state['donate']['qr']);
    }
    return $state;
}

function extract_embedded_images(array $state, string $dir): array {
    return walk_state_images($state, function ($val) use ($dir) {
        if (is_string($val) && strncmp($val, 'data:', 5) === 0) {
            $bytes = data_url_to_bytes($val);
            if ($bytes !== null) { return save_image_bytes($bytes, $dir); }
        }
        return $val;
    });
}

// Part of the stored data holds icons as absolute URLs
// (https://maknemytierlist.site/images/<name>) rather than /images/<name>.
// Those are still our own files, so they must be migrated too — and made
// relative while we are here: an absolute URL survives a domain change badly
// and turns the PNG export into a cross-origin fetch. A URL is treated as ours
// only when a file with that basename actually exists in $dir, which a foreign
// host's sha1-named file will not.
//
// Returns the local path (starting with /images/) or null if the value is not
// one of our images.
function local_image_path(string $val, string $dir): ?string {
    if (strncmp($val, '/images/', 8) === 0) { $name = basename($val); }
    elseif (preg_match('~^https?://[^/]+/images/([^/?#]+)$~', $val, $m)) { $name = $m[1]; }
    else { return null; }

    // basename() already strips any traversal, but be explicit about it.
    if ($name === '' || strpbrk($name, '/\\') !== false) { return null; }
    return is_file(rtrim($dir, '/\\') . '/' . $name) ? '/images/' . $name : null;
}

// One-off migration of icons uploaded before ICON_MAX_SIDE existed. Oversized
// files are rewritten under a fresh content-hash name and the state is pointed
// at it; the original is left on disk because /images/ is served with an
// immutable cache header, so overwriting a name in place would leave browsers
// on the old bytes forever.
//
// Returns [$state, $stats]. $stats holds: scanned, resized, relativised,
// savedBytes, orphans (files no longer referenced, for the caller to report
// before deleting) and skipped.
//
// With $write = false nothing is written and the state comes back unchanged —
// the same counting pass, so a dry run reports exactly what a real run would do.
function downscale_stored_images(array $state, string $dir, int $maxSide = ICON_MAX_SIDE, bool $write = true): array {
    $stats = ['scanned' => 0, 'resized' => 0, 'relativised' => 0, 'savedBytes' => 0,
              'orphans' => [], 'skipped' => []];
    $dir = rtrim($dir, '/\\');

    $state = walk_state_images($state, function ($val) use ($dir, $maxSide, $write, &$stats) {
        if (!is_string($val)) { return $val; }

        $local = local_image_path($val, $dir);
        if ($local === null) {
            // Only report a missing file for values that clearly meant one of
            // ours; bundled assets and foreign URLs are not our business.
            if (strncmp($val, '/images/', 8) === 0) { $stats['skipped'][] = basename($val) . ' (missing)'; }
            return $val;
        }

        $name = basename($local);
        $wasAbsolute = $local !== $val;
        if ($wasAbsolute) { $stats['relativised']++; }

        $stats['scanned']++;
        // A dry run must leave the state exactly as it found it, including the
        // absolute-to-relative rewrite.
        $keep = $write ? $local : $val;

        $bytes = (string)file_get_contents($dir . '/' . $name);
        try {
            $small = downscale_image_bytes($bytes, $maxSide);
        } catch (RuntimeException $e) {
            $stats['skipped'][] = $name . ' (' . $e->getMessage() . ')';
            return $keep;
        }
        if ($small === $bytes) { return $keep; }

        $newName = sha1($small) . '.' . image_ext_for($small);
        if ($write) {
            $newPath = $dir . '/' . $newName;
            if (!is_file($newPath) && file_put_contents($newPath, $small) === false) {
                $stats['skipped'][] = $name . ' (write failed)';
                return $keep;
            }
        }
        $stats['resized']++;
        $stats['savedBytes'] += strlen($bytes) - strlen($small);
        $stats['orphans'][] = $name;
        return $write ? '/images/' . $newName : $val;
    });

    return [$state, $stats];
}
