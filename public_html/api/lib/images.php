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

function save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000): string {
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('image too large');
    }
    $ext = image_ext_for($bytes);
    if ($ext === null) {
        throw new RuntimeException('unsupported image type');
    }
    // Downscale BEFORE hashing: the filename must describe the bytes on disk,
    // or the same source would be written again under a new name every upload.
    $bytes = downscale_image_bytes($bytes);
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

// Every place the state stores an image: tier logos, item icons, the ad banner.
// Passing $rewrite over all of them keeps callers from re-walking the structure
// (and from forgetting one of the three when a new caller shows up).
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
