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

test('upload passes the requested max side through', function () {
    $dir = sys_get_temp_dir() . '/nexus_news_up_' . getmypid();
    @mkdir($dir, 0777, true);

    $src = imagecreatetruecolor(600, 600);
    ob_start(); imagepng($src); $bytes = ob_get_clean();
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    [$status, $p] = handle_upload($dir, $dataUrl, null, 1280);
    assert_eq(200, $status, 'ok status');
    [$w, ] = getimagesizefromstring(file_get_contents($dir . '/' . basename($p['url'])));
    assert_eq(600, $w, 'kept at source size');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
});

run_tests();
