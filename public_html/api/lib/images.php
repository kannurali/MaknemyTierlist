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

// Sniffing only — it says what the bytes ARE, not what a caller may store.
// GIF is recognised here for advertising creatives; the icon path keeps
// rejecting it through save_image_bytes()'s $allow whitelist.
function image_ext_for(string $bytes): ?string {
    if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) { return 'png'; }
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) { return 'jpg'; }
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') { return 'webp'; }
    if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0) { return 'gif'; }
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

// $allow is the storable-format whitelist, deliberately narrower than what
// image_ext_for() can recognise. Icons must keep rejecting GIF: they go
// through GD, which flattens an animation to its first frame, and they are
// downscaled to 256 px where an animation makes no sense anyway. Existing
// callers pass nothing and keep their exact behaviour.
function save_image_bytes(string $bytes, string $dir, int $maxBytes = 512000,
                          array $allow = ['png', 'jpg', 'webp']): string {
    if (strlen($bytes) > $maxBytes) {
        throw new RuntimeException('image too large');
    }
    $ext = image_ext_for($bytes);
    if ($ext === null || !in_array($ext, $allow, true)) {
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

// ============================================================================
//  Advertising creatives
// ============================================================================
//
// A separate storage path from icons, on purpose. save_image_bytes() always
// runs downscale_image_bytes() with ICON_MAX_SIDE = 256, so a 1200x300 banner
// pushed through it comes back 256 px wide and is then stretched by CSS across
// the whole block. That is what happens to the current ad banner today.
//
// Creatives also may be animated, and GD has no concept of animation: any
// imagecreatefromstring/imagecopyresampled round trip silently returns frame
// one. So the animated path must never touch GD at all.

// One source of truth for the code AND for the spec table an advertiser is
// handed. Change a number here and the media kit must change with it.
const CREATIVE_SPECS = [
    'strip' => ['maxW' => 1200, 'maxH' => 400,  'maxBytes' => 400000, 'maxAnimBytes' => 900000],
    'rail'  => ['maxW' => 320,  'maxH' => 1200, 'maxBytes' => 300000, 'maxAnimBytes' => 700000],
    'popup' => ['maxW' => 900,  'maxH' => 900,  'maxBytes' => 400000, 'maxAnimBytes' => 900000],
];
const CREATIVE_FORMATS = ['png', 'jpg', 'webp', 'gif'];

// Walks the GIF block structure instead of counting Graphic Control Extension
// signatures. The popular "substr_count($bytes, "\x21\xF9\x04") > 1" trick is
// wrong: that byte sequence occurs inside LZW-compressed pixel data by chance,
// so single-frame GIFs get reported as animated and are then refused a resize
// they actually needed. Any malformed offset ends the walk and answers false —
// a creative we cannot parse is treated as a still image, and the dimension
// gate downstream still protects us.
function is_animated_gif(string $bytes): bool {
    $len = strlen($bytes);
    // 6 byte header + 7 byte Logical Screen Descriptor.
    if ($len < 13) { return false; }
    $packed = ord($bytes[10]);
    $pos = 13;
    if ($packed & 0x80) { $pos += 3 * (1 << (($packed & 0x07) + 1)); }

    $frames = 0;
    while ($pos < $len) {
        $block = ord($bytes[$pos]);
        if ($block === 0x3B) { break; }                 // trailer
        if ($block === 0x21) {                          // extension: label + sub-blocks
            $pos += 2;
            $pos = gif_skip_subblocks($bytes, $pos);
            if ($pos < 0) { return false; }
            continue;
        }
        if ($block === 0x2C) {                          // image descriptor = one frame
            $frames++;
            if ($frames > 1) { return true; }
            if ($pos + 10 > $len) { return false; }
            $lpacked = ord($bytes[$pos + 9]);
            $pos += 10;                                 // 1 separator + 9 descriptor
            if ($lpacked & 0x80) { $pos += 3 * (1 << (($lpacked & 0x07) + 1)); }
            $pos += 1;                                  // LZW minimum code size
            $pos = gif_skip_subblocks($bytes, $pos);
            if ($pos < 0) { return false; }
            continue;
        }
        return false;                                   // not a structure we understand
    }
    return $frames > 1;
}

// Sub-block chain: [len][len bytes]...[0x00]. Returns the offset just past the
// terminator, or -1 when the chain runs off the end of the buffer.
function gif_skip_subblocks(string $bytes, int $pos): int {
    $len = strlen($bytes);
    while ($pos < $len) {
        $n = ord($bytes[$pos]);
        $pos += 1 + $n;
        if ($n === 0) { return $pos; }
    }
    return -1;
}

// Animated WebP is always the extended (VP8X) format, and the ANIMATION bit is
// bit 1 of the first flags byte of that chunk. Layout: RIFF(4) size(4) WEBP(4)
// then the first chunk FourCC(4) size(4), so the flags byte sits at offset 20.
function is_animated_webp(string $bytes): bool {
    if (strlen($bytes) < 21) { return false; }
    if (strncmp($bytes, 'RIFF', 4) !== 0 || substr($bytes, 8, 4) !== 'WEBP') { return false; }
    if (substr($bytes, 12, 4) === 'VP8X') {
        return (ord($bytes[20]) & 0x02) !== 0;
    }
    // A plain VP8/VP8L file cannot be animated; anything else, fall back to
    // looking for a real animation frame chunk.
    return strpos($bytes, 'ANMF') !== false;
}

function is_animated_bytes(string $bytes): bool {
    $ext = image_ext_for($bytes);
    if ($ext === 'gif')  { return is_animated_gif($bytes); }
    if ($ext === 'webp') { return is_animated_webp($bytes); }
    return false;
}

// Stores an advertising creative and reports back what was actually stored.
// Returns ['url' => '/images/<sha1>.<ext>', 'w' => int, 'h' => int, 'anim' => bool].
//
// Oversized STATIC creatives are fixed silently. Oversized ANIMATED creatives
// are rejected, naming both the delivered and the allowed size: there is no way
// to resize an animation without a dedicated encoder, and "no Composer / no
// external PHP packages" is a project rule. The message is meant to be
// forwarded to the advertiser verbatim.
function save_creative_bytes(string $bytes, string $dir, string $slot): array {
    if (!isset(CREATIVE_SPECS[$slot])) {
        throw new RuntimeException('unknown ad slot: ' . $slot);
    }
    $spec = CREATIVE_SPECS[$slot];

    $ext = image_ext_for($bytes);
    if ($ext === null || !in_array($ext, CREATIVE_FORMATS, true)) {
        throw new RuntimeException('unsupported creative type (allowed: PNG, JPG, WebP, GIF)');
    }

    $anim = is_animated_bytes($bytes);
    $cap = $anim ? $spec['maxAnimBytes'] : $spec['maxBytes'];
    if (strlen($bytes) > $cap) {
        throw new RuntimeException(sprintf(
            'creative too large: %d bytes, max %d for %s %s',
            strlen($bytes), $cap, $slot, $anim ? 'animation' : 'still'
        ));
    }

    // getimagesizefromstring is core PHP, not GD, so dimensions are validated
    // even on a host with the extension switched off.
    $size = @getimagesizefromstring($bytes);
    if ($size === false) { throw new RuntimeException('unreadable image'); }
    [$w, $h] = $size;
    if ($w < 1 || $h < 1) { throw new RuntimeException('unreadable image'); }
    if ($w * $h > MAX_SOURCE_PIXELS) { throw new RuntimeException('image dimensions too large'); }

    $fit = min($spec['maxW'] / $w, $spec['maxH'] / $h);
    if ($fit < 1) {
        if ($anim) {
            throw new RuntimeException(sprintf(
                'animated creative must be delivered at the exact size: got %dx%d, max %dx%d for %s',
                $w, $h, $spec['maxW'], $spec['maxH'], $slot
            ));
        }
        // downscale_image_bytes fits a SQUARE box, so translate the
        // width-and-height limit into the longest side that satisfies both.
        $bytes = downscale_image_bytes($bytes, max(1, (int)floor(max($w, $h) * $fit)));
        $size = @getimagesizefromstring($bytes);
        if ($size !== false) { [$w, $h] = $size; }
    }

    // Hash after any resize: the filename must describe the bytes on disk, or
    // dedup and the immutable cache header both break.
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = sha1($bytes) . '.' . $ext;
    $path = rtrim($dir, '/\\') . '/' . $name;
    if (!file_exists($path)) {
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('failed to write image');
        }
    }
    return ['url' => '/images/' . $name, 'w' => (int)$w, 'h' => (int)$h, 'anim' => $anim];
}

// The promo document's counterpart to walk_state_images(). Same warning
// applies: an image field missing from this walk stays inline as a data: URL
// and looks unreferenced to any future cleanup pass.
//
// НЕ переносить креативы в walk_state_images(): там они снова попадут под
// 256-пиксельный downscale и под tools/downscale-images.php.
function walk_promo_images(array $doc, callable $rewrite): array {
    if (!isset($doc['campaigns']) || !is_array($doc['campaigns'])) { return $doc; }
    foreach ($doc['campaigns'] as &$camp) {
        if (!is_array($camp) || !isset($camp['creatives']) || !is_array($camp['creatives'])) { continue; }
        foreach ($camp['creatives'] as &$cre) {
            if (!is_array($cre)) { continue; }
            if (isset($cre['src']))    { $cre['src']    = $rewrite($cre['src']); }
            if (isset($cre['poster'])) { $cre['poster'] = $rewrite($cre['poster']); }
        }
        unset($cre);
    }
    unset($camp);
    return $doc;
}

// Every place the state stores an image: tier logos, item icons, the ad banner
// and the donate QR. Passing $rewrite over all of them keeps callers from
// re-walking the structure (and from forgetting one when a new caller shows up).
//
// Ad CREATIVES are deliberately absent: they live in the promo document and are
// walked by walk_promo_images() instead. Adding them here would route them
// through save_image_bytes() -> 256 px, which is exactly the defect the
// separate creative path exists to avoid.
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
