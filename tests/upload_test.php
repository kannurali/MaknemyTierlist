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

test('the icon path still rejects GIF when no ad slot is given', function () {
    // image_ext_for learned about GIF for advertising creatives; item icons
    // must keep refusing it, or an animation ends up flattened by GD.
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/gif;base64,' . base64_encode(gif_u()), null);
    assert_eq(400, $status, '400 gif as icon');
    assert_eq('unsupported image type', $p['error'], 'reason');
});

test('an ad slot switches to the creative path and reports the stored size', function () {
    $dir = tmp_dir_u();
    $bytes = gif_u(240, 60);
    [$status, $p] = handle_upload($dir, 'data:image/gif;base64,' . base64_encode($bytes), null, 'strip');
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1($bytes) . '.gif', $p['url'], 'url');
    assert_eq(240, $p['w'], 'width reported');
    assert_eq(60, $p['h'], 'height reported');
    assert_eq(false, $p['anim'], 'single frame is not animation');
});

test('an unknown ad slot is refused rather than treated as an icon', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null, 'sidebar');
    assert_eq(400, $status, '400 unknown slot');
});

test('the default slot leaves existing icon uploads byte-identical', function () {
    $dir = tmp_dir_u();
    [$status, $p] = handle_upload($dir, 'data:image/png;base64,' . PNG_B64_U, null, '');
    assert_eq(200, $status, 'ok');
    assert_eq('/images/' . sha1(base64_decode(PNG_B64_U)) . '.png', $p['url'], 'same url as before');
    assert_eq(['url'], array_keys($p), 'icon response shape unchanged');
});

run_tests();
