<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/images.php';
require __DIR__ . '/../public_html/api/upload.php';

const PNG_B64_U = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir_u(): string {
    $d = sys_get_temp_dir() . '/uploadtest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

test('accepts data url and returns hashed url', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null);
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1(base64_decode(PNG_B64_U)) . '.png', $p['url'], 'url');
});

test('rejects missing input', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, null, null);
    assert_eq(400, $status, '400 no input');
});

test('rejects non-image data url', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:text/plain;base64,' . base64_encode('hello'), null);
    assert_eq(400, $status, '400 not image');
});

// Minimal valid 24x8 GIF89a, single frame. GD cannot write GIF here, and the
// icon path must reject it regardless of whether the extension is loaded.
function gif_u(int $w = 24, int $h = 8): string {
    return "GIF89a" . pack('v', $w) . pack('v', $h) . chr(0x80) . chr(0) . chr(0)
        . "\x00\x00\x00\xFF\xFF\xFF"
        . "\x2C" . pack('v', 0) . pack('v', 0) . pack('v', $w) . pack('v', $h) . chr(0)
        . "\x02\x03\x4C\x01\x00\x00\x3B";
}

test('the icon path still rejects GIF when no kind is given', function () {
    // image_ext_for learned about GIF for advertising creatives; item icons
    // must keep refusing it, or an animation ends up flattened by GD.
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/gif;base64,' . base64_encode(gif_u()), null);
    assert_eq(400, $status, '400 gif as icon');
    assert_eq('unsupported image type', $p['error'], 'reason');
});

test('a creative kind switches to the creative path and reports the stored size', function () {
    $dir = tmp_dir_u();
    $bytes = gif_u(240, 60);
    [$status, $p] = handle_upload($dir, 'data:image/gif;base64,' . base64_encode($bytes), null, 'strip');
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1($bytes) . '.gif', $p['url'], 'url');
    assert_eq(240, $p['w'], 'width reported');
    assert_eq(60, $p['h'], 'height reported');
    assert_eq(false, $p['anim'], 'single frame is not animation');
});

test('an unknown kind is refused rather than treated as an icon', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null, 'sidebar');
    assert_eq(400, $status, '400 unknown kind');
});

test('the empty kind leaves existing icon uploads byte-identical', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null, '');
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1(base64_decode(PNG_B64_U)) . '.png', $p['url'], 'same url as before');
    assert_eq(['url'], array_keys($p), 'icon response shape unchanged');
});

// Валидная сигнатура PNG + честный IHDR (ширина/высота читаются
// getimagesizefromstring() без проверки CRC — тот же приём, что и
// fake_png_header() в tests/images_test.php), но раздутый лишним хвостом до
// $totalBytes. Ширина/высота держим не больше потолка полной ширины (1280 —
// news_image_cap(100)), поэтому downscale_image_bytes() отдаёт эти байты как
// есть, не пытаясь их по-настоящему декодировать — тесту нужен именно вес
// файла, а не валидный пиксельный формат.
function oversized_png_bytes(int $totalBytes): string {
    $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 1000, 1000)
        . "\x08\x06\x00\x00\x00" . "\x00\x00\x00\x00";
    return $header . str_repeat('x', max(0, $totalBytes - strlen($header)));
}

test('kind=news accepts a source over the icon 500 KB cap; the icon path still rejects it', function () {
    $dir = tmp_dir_u();
    // Обычный скриншот Blox Fruits: больше 512000 (500 КБ, старый общий
    // потолок), но меньше NEWS_IMAGE_MAX_BYTES. pct=100 — самая широкая
    // настройка редактора, потолок стороны 1280 (news_image_cap(100)).
    $bytes = oversized_png_bytes(600000);
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    [$statusNews, $pNews] = handle_upload($dir, $dataUrl, null, 'news', 100);
    assert_eq(200, $statusNews, 'news path accepts a real-world screenshot size');
    assert_true(isset($pNews['url']), 'news path returns a stored url');

    // Пустой kind — путь иконки с дефолтами 512000 / 256 px. Он обязан
    // остаться неизменным: tests/images_test.php пинит поведение иконки на нём.
    [$statusIcon, $pIcon] = handle_upload($dir, $dataUrl, null, '');
    assert_eq(400, $statusIcon, 'icon path still enforces the 500 KB cap');
    assert_eq('image too large', $pIcon['error'] ?? null, 'rejected for size specifically');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

test('kind=news keeps the source size instead of crushing it to 256 px', function () {
    $dir = sys_get_temp_dir() . '/nexus_news_up_' . getmypid();
    @mkdir($dir, 0777, true);

    $src = imagecreatetruecolor(600, 600);
    ob_start(); imagepng($src); $bytes = ob_get_clean();
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    // pct=100 — потолок стороны 1280 (news_image_cap(100)), источник 600px
    // проходит нетронутым.
    [$status, $p] = handle_upload($dir, $dataUrl, null, 'news', 100);
    assert_eq(200, $status, 'ok status');
    [$w, ] = getimagesizefromstring(file_get_contents($dir . '/' . basename($p['url'])));
    assert_eq(600, $w, 'kept at source size');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

// ---------- resolve_upload_max_side (kind + pct -> потолок стороны) ----------

test('resolve_upload_max_side caps a news upload by the selected width', function () {
    assert_eq(news_image_cap(30),  resolve_upload_max_side('news', 30),  '30% cap');
    assert_eq(news_image_cap(55),  resolve_upload_max_side('news', 55),  '55% cap');
    assert_eq(news_image_cap(100), resolve_upload_max_side('news', 100), '100% cap');
});

test('resolve_upload_max_side falls back to the smallest news cap on an unknown, missing or out-of-range width', function () {
    assert_eq(256, resolve_upload_max_side('news', 'huge'), 'non-numeric -> smallest, not largest');
    assert_eq(256, resolve_upload_max_side('news', ''),     'missing -> smallest');
    assert_eq(256, resolve_upload_max_side('news', 150),    'out of range (too big) -> smallest');
    assert_eq(256, resolve_upload_max_side('news', 5),      'out of range (too small) -> smallest');
});

test('resolve_upload_max_side falls back to ICON_MAX_SIDE for an unknown kind', function () {
    assert_eq(ICON_MAX_SIDE, resolve_upload_max_side('', ''), 'empty kind -> icon cap');
    assert_eq(ICON_MAX_SIDE, resolve_upload_max_side('bogus', 100), 'unknown kind -> icon cap, pct ignored');
});

test('a large source uploaded at 30% is stored smaller than the same source at 100%', function () {
    $dir = tmp_dir_u();
    $src = imagecreatetruecolor(2000, 2000);
    ob_start(); imagepng($src); $bytes = ob_get_clean();
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    [, $pSmall] = handle_upload($dir, $dataUrl, null, 'news', 30);
    [$wSmall, ] = getimagesizefromstring(file_get_contents($dir . '/' . basename($pSmall['url'])));
    assert_eq(news_image_cap(30), $wSmall, '30% stored exactly at its cap for an oversized source');

    [, $pFull] = handle_upload($dir, $dataUrl, null, 'news', 100);
    [$wFull, ] = getimagesizefromstring(file_get_contents($dir . '/' . basename($pFull['url'])));
    assert_eq(1280, $wFull, '100% stored exactly at the ceiling for an oversized source');

    [, $pUnknown] = handle_upload($dir, $dataUrl, null, 'news', 'nonsense');
    [$wUnknown, ] = getimagesizefromstring(file_get_contents($dir . '/' . basename($pUnknown['url'])));
    assert_eq(256, $wUnknown, 'unrecognised width falls back to the smallest cap, not the largest');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

test('handle_upload reports the width and height of the stored (already downscaled) image', function () {
    $dir = tmp_dir_u();
    $src = imagecreatetruecolor(2000, 1000);
    ob_start(); imagepng($src); $bytes = ob_get_clean();
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    [$status, $p] = handle_upload($dir, $dataUrl, null, 'news', 30);
    assert_eq(200, $status, 'ok');
    $cap = news_image_cap(30);
    assert_eq($cap, $p['width'], 'width capped and reported');
    assert_eq((int)($cap / 2), $p['height'], 'height scaled proportionally and reported');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

run_tests();
