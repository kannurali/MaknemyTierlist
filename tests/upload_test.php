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

// Валидная сигнатура PNG + честный IHDR (ширина/высота читаются
// getimagesizefromstring() без проверки CRC — тот же приём, что и
// fake_png_header() в tests/images_test.php), но раздутый лишним хвостом до
// $totalBytes. Ширина/высота держим не больше NEWS_IMAGE_MAX_SIDE, поэтому
// downscale_image_bytes() отдаёт эти байты как есть, не пытаясь их
// по-настоящему декодировать — тесту нужен именно вес файла, а не валидный
// пиксельный формат.
function oversized_png_bytes(int $totalBytes): string {
    $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 1000, 1000)
        . "\x08\x06\x00\x00\x00" . "\x00\x00\x00\x00";
    return $header . str_repeat('x', max(0, $totalBytes - strlen($header)));
}

test('kind=news accepts a source over the icon 500 KB cap; the icon path still rejects it', function () {
    $dir = tmp_dir_u();
    // Обычный скриншот Blox Fruits: больше 512000 (500 КБ, старый общий
    // потолок), но меньше NEWS_IMAGE_MAX_BYTES.
    $bytes = oversized_png_bytes(600000);
    $dataUrl = 'data:image/png;base64,' . base64_encode($bytes);

    [$statusNews, $pNews] = handle_upload($dir, $dataUrl, null, NEWS_IMAGE_MAX_SIDE, NEWS_IMAGE_MAX_BYTES);
    assert_eq(200, $statusNews, 'news path accepts a real-world screenshot size');
    assert_true(isset($pNews['url']), 'news path returns a stored url');

    // Путь иконки не передаёт maxBytes явно — используется дефолт 512000,
    // тот же самый, что и до этой правки. Он обязан остаться неизменным:
    // tests/images_test.php пинит поведение 256px-иконки на нём.
    [$statusIcon, $pIcon] = handle_upload($dir, $dataUrl, null, ICON_MAX_SIDE);
    assert_eq(400, $statusIcon, 'icon path still enforces the 500 KB cap');
    assert_eq('image too large', $pIcon['error'] ?? null, 'rejected for size specifically');

    array_map('unlink', glob($dir . '/*'));
    @rmdir($dir);
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
