<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/images.php';

// 1x1 PNG (binary), base64 constant for tests.
const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir(): string {
    $d = sys_get_temp_dir() . '/imgtest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

// Solid-colour test image of a given size, encoded as $ext. The top-left
// quadrant is fully transparent so alpha survival can be asserted after a
// resize — a single transparent pixel would just be averaged away by the
// resampler and prove nothing.
function make_image(int $w, int $h, string $ext = 'png'): string {
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 40, 90));
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, intdiv($h, 2) - 1,
        imagecolorallocatealpha($im, 0, 0, 0, 127));
    ob_start();
    if ($ext === 'webp')      { imagewebp($im, null, 90); }
    elseif ($ext === 'jpg')   { imagejpeg($im, null, 90); }
    else                      { imagepng($im); }
    return ob_get_clean();
}

function image_size_of(string $bytes): array {
    $info = getimagesizefromstring($bytes);
    return [$info[0], $info[1]];
}

test('downscale_image_bytes shrinks an oversized icon to the cap', function () {
    [$w, $h] = image_size_of(downscale_image_bytes(make_image(800, 800), 256));
    assert_eq(256, $w, 'width capped');
    assert_eq(256, $h, 'height capped');
});

test('downscale_image_bytes keeps the aspect ratio', function () {
    [$w, $h] = image_size_of(downscale_image_bytes(make_image(800, 400), 256));
    assert_eq(256, $w, 'long side capped');
    assert_eq(128, $h, 'short side scaled proportionally');
});

test('downscale_image_bytes leaves a small image byte-identical', function () {
    // Re-encoding would change the sha1 filename of every already-stored icon
    // and break dedup, so images at or under the cap must pass through untouched.
    $small = make_image(120, 90);
    assert_eq($small, downscale_image_bytes($small, 256), 'untouched below cap');
});

test('downscale_image_bytes never upscales', function () {
    $small = make_image(64, 64);
    assert_eq($small, downscale_image_bytes($small, 256), 'no upscale');
});

test('downscale_image_bytes preserves transparency', function () {
    $out = downscale_image_bytes(make_image(800, 800), 256);
    $im = imagecreatefromstring($out);
    $alpha = (imagecolorat($im, 30, 30) >> 24) & 0x7F;   // inside the transparent quadrant
    $opaque = (imagecolorat($im, 220, 220) >> 24) & 0x7F; // inside the solid quadrant
    assert_eq(127, $alpha, 'transparent area stays fully transparent');
    assert_eq(0, $opaque, 'solid area stays fully opaque');
});

test('downscale_image_bytes keeps the source format', function () {
    assert_eq('webp', image_ext_for(downscale_image_bytes(make_image(800, 800, 'webp'), 256)), 'webp in, webp out');
    assert_eq('png', image_ext_for(downscale_image_bytes(make_image(800, 800), 256)), 'png in, png out');
});

// A PNG header that declares $w x $h honestly but carries no image data at all.
// getimagesizefromstring() reads the size straight out of IHDR without checking
// the CRC, so this reaches the decoder — which is the point: it lets a test aim
// at imagecreatefromstring() rather than tripping an earlier size guard.
function fake_png_header(int $w, int $h): string {
    return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h)
         . "\x08\x06\x00\x00\x00" . "\x00\x00\x00\x00" . str_repeat('garbage', 40);
}

test('downscale_image_bytes rejects bytes it cannot decode', function () {
    // Sane dimensions, garbage payload: this has to fail in the decoder, not in
    // the pixel-count guard, otherwise the test stops covering that branch.
    assert_throws(function () {
        downscale_image_bytes(fake_png_header(300, 300), 256);
    }, 'undecodable throws');
});

test('downscale_image_bytes refuses dimensions that would exhaust memory', function () {
    // A uniform 9000x9000 PNG compresses to ~250 KB, so the byte-size cap in
    // save_image_bytes lets it through — but decoding it costs 4 bytes a pixel,
    // ~324 MB. That overruns memory_limit, and the resulting fatal cannot be
    // caught: upload.php would answer a bare 500 instead of a clean error.
    assert_throws(function () {
        downscale_image_bytes(fake_png_header(9000, 9000), 256);
    }, 'oversized dimensions throw');

    // The cap must not clip real icons: 4000x4000 is 16 Mpx exactly, still fine.
    $ok = make_image(400, 400);
    assert_eq(256, image_size_of(downscale_image_bytes($ok, 256))[0], 'ordinary icon still resized');
});

test('save_image_bytes stores the downscaled image', function () {
    $dir = tmp_dir();
    $url = save_image_bytes(make_image(800, 800), $dir);
    [$w, $h] = image_size_of(file_get_contents($dir . '/' . basename($url)));
    assert_eq(ICON_MAX_SIDE, $w, 'stored width capped');
    assert_eq(ICON_MAX_SIDE, $h, 'stored height capped');
});

test('save_image_bytes names the file after the stored bytes', function () {
    // The hash must match what is on disk, otherwise re-uploading the same
    // source writes a second copy under a different name every time.
    $dir = tmp_dir();
    $url = save_image_bytes(make_image(800, 800), $dir);
    $stored = file_get_contents($dir . '/' . basename($url));
    assert_eq('/images/' . sha1($stored) . '.png', $url, 'name is hash of stored bytes');
});

test('save_image_bytes still dedups equal sources', function () {
    $dir = tmp_dir();
    $src = make_image(800, 800);
    assert_eq(save_image_bytes($src, $dir), save_image_bytes($src, $dir), 'same source -> same url');
});

test('image_ext_for detects png', function () {
    assert_eq('png', image_ext_for(base64_decode(PNG_B64)), 'png magic');
});

test('image_ext_for rejects non-image', function () {
    assert_eq(null, image_ext_for('hello world not an image'), 'text -> null');
});

test('data_url_to_bytes decodes', function () {
    $bytes = data_url_to_bytes('data:image/png;base64,' . PNG_B64);
    assert_eq(base64_decode(PNG_B64), $bytes, 'roundtrip bytes');
});

test('data_url_to_bytes returns null for plain url', function () {
    assert_eq(null, data_url_to_bytes('/images/abc.webp'), 'plain url -> null');
});

test('save_image_bytes writes hashed file and dedups', function () {
    $dir = tmp_dir();
    $bytes = base64_decode(PNG_B64);
    $url1 = save_image_bytes($bytes, $dir);
    $url2 = save_image_bytes($bytes, $dir);
    assert_eq($url1, $url2, 'same bytes -> same url');
    assert_eq('/images/' . sha1($bytes) . '.png', $url1, 'url shape');
    assert_true(file_exists($dir . '/' . sha1($bytes) . '.png'), 'file written');
});

test('save_image_bytes rejects oversize', function () {
    $dir = tmp_dir();
    assert_throws(function () use ($dir) {
        save_image_bytes(base64_decode(PNG_B64), $dir, 10); // 10-byte cap
    }, 'oversize throws');
});

test('extract_embedded_images rewrites data urls, keeps plain urls', function () {
    $dir = tmp_dir();
    $state = [
        'tiers' => [
            ['logo' => 'data:image/png;base64,' . PNG_B64,
             'items' => [
                 ['icon' => 'data:image/png;base64,' . PNG_B64],
                 ['icon' => '/images/existing.webp'],
             ]],
        ],
        'ad' => ['image' => 'data:image/png;base64,' . PNG_B64],
    ];
    $out = extract_embedded_images($state, $dir);
    $expected = '/images/' . sha1(base64_decode(PNG_B64)) . '.png';
    assert_eq($expected, $out['tiers'][0]['logo'], 'logo rewritten');
    assert_eq($expected, $out['tiers'][0]['items'][0]['icon'], 'icon rewritten');
    assert_eq('/images/existing.webp', $out['tiers'][0]['items'][1]['icon'], 'plain url kept');
    assert_eq($expected, $out['ad']['image'], 'ad rewritten');
});

// QR доната — такая же картинка состояния, как логотипы и иконки, но в обходе
// его не было. Пропусти его в walk_state_images — и встроенный data: URL
// останется в JSON (а тот упирается в лимит 512 КБ), а сам файл на диске
// окажется «ничей» и его снесёт как сироту.
test('extract_embedded_images reaches the donate QR', function () {
    $dir = tmp_dir();
    $state = [
        'tiers'  => [],
        'donate' => ['qr' => 'data:image/png;base64,' . PNG_B64],
    ];
    $out = extract_embedded_images($state, $dir);
    assert_eq('/images/' . sha1(base64_decode(PNG_B64)) . '.png', $out['donate']['qr'], 'donate QR rewritten');
});

test('downscale_stored_images does not call the referenced donate QR an orphan', function () {
    $dir = tmp_dir();
    $big = make_image(800, 800);
    $name = sha1($big) . '.png';
    file_put_contents($dir . '/' . $name, $big);

    [$out, $stats] = downscale_stored_images(
        ['tiers' => [], 'donate' => ['qr' => '/images/' . $name]], $dir, 256);

    assert_eq(1, $stats['scanned'], 'QR was visited');
    assert_eq(1, $stats['resized'], 'QR was capped like any other image');
    $newName = basename($out['donate']['qr']);
    assert_true($newName !== $name, 'state repointed at the resized copy');
    assert_eq([$name], $stats['orphans'], 'only the superseded original is orphaned');
});

test('image_ext_for detects jpeg', function () {
    assert_eq('jpg', image_ext_for("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 8)), 'jpeg magic');
});

test('image_ext_for detects webp', function () {
    $webp = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat("\x00", 8);
    assert_eq('webp', image_ext_for($webp), 'webp magic');
});

test('data_url_to_bytes decodes non-base64 percent-encoded', function () {
    assert_eq('hello world', data_url_to_bytes('data:text/plain,hello%20world'), 'percent-encoded');
});

test('save_image_bytes rejects unsupported type', function () {
    $dir = tmp_dir();
    assert_throws(function () use ($dir) {
        save_image_bytes('this is not an image at all', $dir);
    }, 'non-image throws');
});

test('downscale_stored_images repoints oversized icons at a resized copy', function () {
    $dir = tmp_dir();
    $big = make_image(800, 800);
    $bigName = sha1($big) . '.png';
    file_put_contents($dir . '/' . $bigName, $big);

    [$out, $stats] = downscale_stored_images(
        ['tiers' => [['items' => [['icon' => '/images/' . $bigName]]]]], $dir, 256);

    $newName = basename($out['tiers'][0]['items'][0]['icon']);
    assert_true($newName !== $bigName, 'icon url changed');
    assert_true(is_file($dir . '/' . $newName), 'resized copy written');
    assert_eq([256, 256], image_size_of(file_get_contents($dir . '/' . $newName)), 'copy is capped');
    assert_eq(1, $stats['resized'], 'counted one resize');
    assert_eq([$bigName], $stats['orphans'], 'old file reported as orphan');
    assert_true(is_file($dir . '/' . $bigName), 'original left on disk (immutable cache)');
});

test('downscale_stored_images in dry mode counts without touching anything', function () {
    $dir = tmp_dir();
    $big = make_image(800, 800);
    $name = sha1($big) . '.png';
    file_put_contents($dir . '/' . $name, $big);
    $state = ['tiers' => [['items' => [['icon' => '/images/' . $name]]]]];

    [$out, $stats] = downscale_stored_images($state, $dir, 256, false);

    assert_eq($state, $out, 'state unchanged');
    assert_eq(1, $stats['resized'], 'still reports what a real run would resize');
    assert_true($stats['savedBytes'] > 0, 'still reports the saving');
    assert_eq(1, count(scandir($dir)) - 2, 'no new file written');
});

test('downscale_stored_images leaves already-small icons alone', function () {
    $dir = tmp_dir();
    $small = make_image(100, 100);
    $name = sha1($small) . '.png';
    file_put_contents($dir . '/' . $name, $small);

    [$out, $stats] = downscale_stored_images(
        ['tiers' => [['items' => [['icon' => '/images/' . $name]]]]], $dir, 256);

    assert_eq('/images/' . $name, $out['tiers'][0]['items'][0]['icon'], 'url untouched');
    assert_eq(0, $stats['resized'], 'nothing resized');
    assert_eq(1, $stats['scanned'], 'still scanned');
});

test('downscale_stored_images covers tier logos and the ad banner', function () {
    $dir = tmp_dir();
    $big = make_image(600, 600);
    $name = sha1($big) . '.png';
    file_put_contents($dir . '/' . $name, $big);

    [$out, $stats] = downscale_stored_images([
        'tiers' => [['logo' => '/images/' . $name, 'items' => []]],
        'ad'    => ['image' => '/images/' . $name],
    ], $dir, 256);

    assert_true($out['tiers'][0]['logo'] !== '/images/' . $name, 'tier logo repointed');
    assert_true($out['ad']['image'] !== '/images/' . $name, 'ad banner repointed');
    assert_eq(2, $stats['scanned'], 'both references scanned');
});

test('downscale_stored_images reports missing files instead of dropping the url', function () {
    $dir = tmp_dir();
    $state = ['tiers' => [['items' => [['icon' => '/images/gone.webp']]]]];
    [$out, $stats] = downscale_stored_images($state, $dir, 256);
    assert_eq($state, $out, 'state unchanged');
    assert_eq(['gone.webp (missing)'], $stats['skipped'], 'missing file reported');
});

test('downscale_stored_images ignores non-/images/ urls', function () {
    $state = ['tiers' => [['items' => [['icon' => 'assets/icon-sample.png']]]]];
    [$out, $stats] = downscale_stored_images($state, tmp_dir(), 256);
    assert_eq($state, $out, 'bundled asset untouched');
    assert_eq(0, $stats['scanned'], 'not counted');
});

test('downscale_stored_images handles absolute urls pointing at our own images', function () {
    // Part of the live data stores icons as https://maknemytierlist.site/images/…
    // instead of /images/…. Skipping those left them at full resolution, and
    // an absolute URL also makes the PNG export a cross-origin fetch.
    $dir = tmp_dir();
    $big = make_image(800, 800);
    $name = sha1($big) . '.png';
    file_put_contents($dir . '/' . $name, $big);

    [$out, $stats] = downscale_stored_images(
        ['tiers' => [['items' => [['icon' => 'https://maknemytierlist.site/images/' . $name]]]]], $dir, 256);

    $url = $out['tiers'][0]['items'][0]['icon'];
    assert_eq('/images/', substr($url, 0, 8), 'rewritten to a relative path');
    assert_eq([256, 256], image_size_of(file_get_contents($dir . '/' . basename($url))), 'and resized');
    assert_eq(1, $stats['resized'], 'counted');
});

test('downscale_stored_images relativises an absolute url even when already small', function () {
    $dir = tmp_dir();
    $small = make_image(100, 100);
    $name = sha1($small) . '.png';
    file_put_contents($dir . '/' . $name, $small);

    [$out, $stats] = downscale_stored_images(
        ['tiers' => [['items' => [['icon' => 'https://maknemytierlist.site/images/' . $name]]]]], $dir, 256);

    assert_eq('/images/' . $name, $out['tiers'][0]['items'][0]['icon'], 'still made relative');
    assert_eq(0, $stats['resized'], 'no resize needed');
    assert_eq(1, $stats['relativised'], 'reported separately');
});

test('downscale_stored_images leaves foreign absolute urls alone', function () {
    // A file we do not host must never be repointed at one of ours.
    $state = ['tiers' => [['items' => [['icon' => 'https://example.com/images/not-ours.png']]]]];
    [$out, $stats] = downscale_stored_images($state, tmp_dir(), 256);
    assert_eq($state, $out, 'foreign url untouched');
    assert_eq(0, $stats['scanned'], 'not counted');
});

test('extract_embedded_images tolerates missing keys without notices', function () {
    $state = ['tiers' => [
        ['items' => [['name' => 'no-icon-here']]],
        ['logo' => '/images/plain.webp', 'items' => []],
    ]];
    $out = extract_embedded_images($state, tmp_dir());
    assert_eq($state, $out, 'unchanged when nothing to rewrite');
});

test('save_image_bytes honours an explicit max side', function () {
    // Иконка предмета остаётся на 256 px — её потолок мерился под память
    // Safari. Картинка новости идёт во всю ширину карточки, и 256 видно.
    $dir = sys_get_temp_dir() . '/nexus_news_img_' . getmypid();
    @mkdir($dir, 0777, true);

    $src = imagecreatetruecolor(900, 400);
    ob_start(); imagepng($src); $bytes = ob_get_clean();

    $url = save_image_bytes($bytes, $dir, 512000, 1280);
    $saved = file_get_contents($dir . '/' . basename($url));
    [$w, $h] = getimagesizefromstring($saved);
    assert_eq(900, $w, 'under the cap, untouched');
    assert_eq(400, $h, 'height untouched');

    $url2 = save_image_bytes($bytes, $dir, 512000);
    $saved2 = file_get_contents($dir . '/' . basename($url2));
    [$w2, ] = getimagesizefromstring($saved2);
    assert_eq(256, $w2, 'default still caps at ICON_MAX_SIDE');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

// ---------- news_image_max_side (потолок по выбранному в редакторе размеру) ----------

test('news_image_max_side maps each size key to its documented cap', function () {
    assert_eq(1024, news_image_max_side('small'), 'small');
    assert_eq(1024, news_image_max_side('medium'), 'medium');
    assert_eq(1280, news_image_max_side('full'), 'full');
});

test('news_image_max_side falls back to the smallest news cap, not the largest', function () {
    assert_eq(1024, news_image_max_side('huge'), 'unrecognised size');
    assert_eq(1024, news_image_max_side(''), 'missing size');
});

run_tests();
