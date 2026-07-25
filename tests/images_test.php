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

run_tests();
