<?php
// Advertising creative storage: format sniffing, animation detection and the
// per-slot size gates. Run: php -d extension=gd tests/creatives_test.php
//
// The animation detectors and every rejection path are pure PHP and pass with
// GD switched off; only the downscale cases need the extension.
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/lib/images.php';

const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

function tmp_dir_c(): string {
    $d = sys_get_temp_dir() . '/creativetest_' . bin2hex(random_bytes(4));
    mkdir($d, 0777, true);
    return $d;
}

function assert_throws_msg(callable $fn, string $needle, string $msg = ''): void {
    $GLOBALS['__asserts']++;
    try {
        $fn();
    } catch (Throwable $e) {
        if (strpos($e->getMessage(), $needle) !== false) { return; }
        $GLOBALS['__fails']++;
        fwrite(STDERR, "  ASSERT FAIL $msg\n    message lacks '$needle': " . $e->getMessage() . "\n");
        return;
    }
    $GLOBALS['__fails']++;
    fwrite(STDERR, "  ASSERT FAIL $msg - expected an exception, none thrown\n");
}

// --------------------------------------------------------------------------
// Hand-built fixtures. Built byte by byte rather than with GD on purpose:
// GD cannot write an animated GIF, and these must be parseable with the
// extension unavailable.
// --------------------------------------------------------------------------

// $pixels lands inside an LZW sub-block, so it can safely carry bytes that
// look like block markers - which is the whole point of one of the tests.
function gif_bytes(int $frames, int $w = 1, int $h = 1, string $pixels = "\x4C\x01\x00", int $padTo = 0): string {
    $out  = "GIF89a";
    $out .= pack('v', $w) . pack('v', $h);
    $out .= chr(0x80) . chr(0) . chr(0);          // global colour table, 2 entries
    $out .= "\x00\x00\x00\xFF\xFF\xFF";           // the table itself

    for ($i = 0; $i < $frames; $i++) {
        $out .= "\x21\xF9\x04\x00\x0A\x00\x00\x00";                       // graphic control ext
        $out .= "\x2C" . pack('v', 0) . pack('v', 0)
              . pack('v', $w) . pack('v', $h) . chr(0x00);                // image descriptor
        $out .= "\x02" . chr(strlen($pixels)) . $pixels;                  // LZW size + one sub-block
        // Pad the last frame's sub-block chain until the file passes $padTo.
        if ($padTo > 0 && $i === $frames - 1) {
            $chunk = str_repeat("\xAA", 255);
            while (strlen($out) < $padTo) { $out .= "\xFF" . $chunk; }
        }
        $out .= "\x00";                                                   // end of sub-blocks
    }
    return $out . "\x3B";
}

function webp_vp8x(bool $anim): string {
    // VP8X payload: flags(1) reserved(3) canvasW-1(3) canvasH-1(3).
    // 0x02 is the ANIMATION bit; 0x10 is ALPHA, used as a non-animated control.
    $payload = chr($anim ? 0x02 : 0x10) . "\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    $chunk = 'VP8X' . pack('V', strlen($payload)) . $payload;
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
}

function webp_plain(): string {
    $payload = str_repeat("\x00", 16);
    $chunk = 'VP8 ' . pack('V', strlen($payload)) . $payload;
    return 'RIFF' . pack('V', 4 + strlen($chunk)) . 'WEBP' . $chunk;
}

function gd_image(int $w, int $h, string $ext = 'png'): string {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 200, 40, 90));
    ob_start();
    if ($ext === 'webp')    { imagewebp($im, null, 90); }
    elseif ($ext === 'jpg') { imagejpeg($im, null, 90); }
    else                    { imagepng($im); }
    return ob_get_clean();
}

// --------------------------------------------------------------------------
// Format sniffing
// --------------------------------------------------------------------------

test('image_ext_for recognises both GIF signatures', function () {
    assert_eq('gif', image_ext_for(gif_bytes(1)), 'GIF89a');
    assert_eq('gif', image_ext_for('GIF87a' . str_repeat("\x00", 20)), 'GIF87a');
});

test('image_ext_for still reports the pre-existing formats and rejects junk', function () {
    assert_eq('png', image_ext_for(base64_decode(PNG_1X1)), 'png');
    assert_eq('webp', image_ext_for(webp_plain()), 'webp');
    assert_eq(null, image_ext_for('hello world'), 'text is not an image');
    assert_eq(null, image_ext_for('GIF88a' . str_repeat("\x00", 20)), 'near-miss signature');
});

test('save_image_bytes still refuses GIF for icons', function () {
    // Regression guard: teaching image_ext_for about GIF must not open the
    // icon path to animation. The whitelist, not the sniffer, is the gate.
    $dir = tmp_dir_c();
    assert_throws_msg(function () use ($dir) { save_image_bytes(gif_bytes(1), $dir); },
        'unsupported image type', 'icons reject gif');
});

test('save_image_bytes accepts GIF only when a caller opts in', function () {
    $dir = tmp_dir_c();
    $url = save_image_bytes(gif_bytes(1), $dir, 512000, ['png', 'jpg', 'webp', 'gif']);
    assert_eq('/images/' . sha1(gif_bytes(1)) . '.gif', $url, 'stored under its hash');
});

// --------------------------------------------------------------------------
// Animation detection
// --------------------------------------------------------------------------

test('is_animated_gif counts real frames', function () {
    assert_eq(false, is_animated_gif(gif_bytes(1)), 'single frame');
    assert_eq(true, is_animated_gif(gif_bytes(2)), 'two frames');
    assert_eq(true, is_animated_gif(gif_bytes(9)), 'nine frames');
});

test('is_animated_gif is not fooled by block markers inside pixel data', function () {
    // The usual shortcut - counting "\x21\xF9\x04" occurrences - reports this
    // still image as animated, and it would then be refused the resize it
    // needs. Compressed pixel data contains arbitrary bytes.
    $sneaky = gif_bytes(1, 1, 1, "\x21\xF9\x04\x00\x21\xF9\x04\x00");
    assert_true(substr_count($sneaky, "\x21\xF9\x04") > 1, 'fixture really does contain the marker twice');
    assert_eq(false, is_animated_gif($sneaky), 'block walk sees one frame');
});

test('is_animated_gif answers false for anything it cannot walk', function () {
    assert_eq(false, is_animated_gif(''), 'empty');
    assert_eq(false, is_animated_gif('GIF89a'), 'header only');
    assert_eq(false, is_animated_gif(substr(gif_bytes(2), 0, 30)), 'truncated mid-file');
    assert_eq(false, is_animated_gif(base64_decode(PNG_1X1)), 'not a gif at all');
});

test('is_animated_webp reads the VP8X animation flag', function () {
    assert_eq(true, is_animated_webp(webp_vp8x(true)), 'animation bit set');
    assert_eq(false, is_animated_webp(webp_vp8x(false)), 'animation bit clear');
    assert_eq(false, is_animated_webp(webp_plain()), 'plain lossy webp');
});

test('is_animated_webp answers false for truncated or foreign bytes', function () {
    assert_eq(false, is_animated_webp(substr(webp_vp8x(true), 0, 20)), 'truncated riff');
    assert_eq(false, is_animated_webp('RIFF' . str_repeat("\x00", 30)), 'riff but not webp');
    assert_eq(false, is_animated_webp(base64_decode(PNG_1X1)), 'png');
});

test('is_animated_bytes dispatches on the sniffed format', function () {
    assert_eq(true, is_animated_bytes(gif_bytes(3)), 'animated gif');
    assert_eq(true, is_animated_bytes(webp_vp8x(true)), 'animated webp');
    assert_eq(false, is_animated_bytes(base64_decode(PNG_1X1)), 'png is never animated');
    assert_eq(false, is_animated_bytes('hello'), 'junk');
});

// --------------------------------------------------------------------------
// save_creative_bytes
// --------------------------------------------------------------------------

test('save_creative_bytes stores a within-spec still and reports its size', function () {
    $dir = tmp_dir_c();
    $bytes = gd_image(1200, 300);
    $r = save_creative_bytes($bytes, $dir, 'strip');
    assert_eq('/images/' . sha1($bytes) . '.png', $r['url'], 'hashed url');
    assert_eq(1200, $r['w'], 'width');
    assert_eq(300, $r['h'], 'height');
    assert_eq(false, $r['anim'], 'not animated');
    assert_true(is_file($dir . '/' . basename($r['url'])), 'written to disk');
});

test('save_creative_bytes keeps a banner at full size instead of crushing it to 256', function () {
    // This is the whole reason creatives do not go through save_image_bytes().
    $dir = tmp_dir_c();
    $r = save_creative_bytes(gd_image(1200, 300), $dir, 'strip');
    assert_eq(1200, $r['w'], 'no icon-sized downscale');
});

test('save_creative_bytes shrinks an oversized still and hashes the new bytes', function () {
    $dir = tmp_dir_c();
    $src = gd_image(2400, 600);
    $r = save_creative_bytes($src, $dir, 'strip');
    assert_eq(1200, $r['w'], 'width fitted');
    assert_eq(300, $r['h'], 'height fitted');
    assert_true($r['url'] !== '/images/' . sha1($src) . '.png', 'filename describes the resized bytes');
    assert_eq('/images/' . sha1((string)file_get_contents($dir . '/' . basename($r['url']))) . '.png',
        $r['url'], 'name matches the bytes on disk');
});

test('save_creative_bytes fits BOTH dimensions, not just the longest side', function () {
    // 1000x800 has a longest side inside the 1200 cap but is twice as tall as
    // the 400 px the strip allows. A plain square-box downscale would pass it
    // through untouched and the banner would blow the poster layout apart.
    $dir = tmp_dir_c();
    $r = save_creative_bytes(gd_image(1000, 800), $dir, 'strip');
    assert_true($r['h'] <= 400, 'height within spec, got ' . $r['h']);
    assert_true($r['w'] <= 1200, 'width within spec, got ' . $r['w']);
});

test('save_creative_bytes applies each slot its own box', function () {
    $dir = tmp_dir_c();
    $rail = save_creative_bytes(gd_image(640, 2400), $dir, 'rail');
    assert_eq(320, $rail['w'], 'rail width');
    assert_eq(1200, $rail['h'], 'rail height');
    $pop = save_creative_bytes(gd_image(1800, 1800), $dir, 'popup');
    assert_eq(900, $pop['w'], 'popup width');
});

test('save_creative_bytes stores an in-spec animation untouched', function () {
    $dir = tmp_dir_c();
    $anim = gif_bytes(4, 1200, 300);
    $r = save_creative_bytes($anim, $dir, 'strip');
    assert_eq(true, $r['anim'], 'reported as animated');
    assert_eq('/images/' . sha1($anim) . '.gif', $r['url'], 'bytes stored verbatim');
    assert_eq($anim, (string)file_get_contents($dir . '/' . basename($r['url'])), 'not re-encoded');
});

test('save_creative_bytes refuses to silently flatten an oversized animation', function () {
    // GD would return frame one and the advertiser would get a still image
    // they did not buy. The message names both sizes so it can be forwarded.
    $dir = tmp_dir_c();
    assert_throws_msg(function () use ($dir) {
        save_creative_bytes(gif_bytes(3, 1400, 350), $dir, 'strip');
    }, '1400x350', 'names the delivered size');
    assert_throws_msg(function () use ($dir) {
        save_creative_bytes(gif_bytes(3, 1400, 350), $dir, 'strip');
    }, '1200x400', 'names the allowed size');
});

test('save_creative_bytes enforces the byte caps, separately for stills and animation', function () {
    $dir = tmp_dir_c();
    // Animated: over the 900 KB strip allowance.
    assert_throws_msg(function () use ($dir) {
        save_creative_bytes(gif_bytes(2, 1200, 300, "\x4C\x01\x00", 950000), $dir, 'strip');
    }, 'creative too large', 'animation over cap');
    // Still: the same file size is over the tighter 400 KB still allowance.
    assert_throws_msg(function () use ($dir) {
        save_creative_bytes(gif_bytes(1, 1200, 300, "\x4C\x01\x00", 450000), $dir, 'strip');
    }, 'creative too large', 'still over cap');
    // ...and a still of that size is fine for a slot that allows it.
    $ok = gif_bytes(1, 1200, 300, "\x4C\x01\x00", 380000);
    $r = save_creative_bytes($ok, $dir, 'strip');
    assert_eq(false, $r['anim'], 'accepted below the still cap');
});

test('save_creative_bytes rejects unknown slots and unsupported formats', function () {
    $dir = tmp_dir_c();
    assert_throws_msg(function () use ($dir) { save_creative_bytes(gif_bytes(1), $dir, 'sidebar'); },
        'unknown ad slot', 'unknown slot');
    assert_throws_msg(function () use ($dir) { save_creative_bytes('not an image', $dir, 'strip'); },
        'unsupported creative type', 'unsupported format');
});

test('every slot in CREATIVE_SPECS allows animation more room than a still', function () {
    foreach (CREATIVE_SPECS as $slot => $spec) {
        assert_true($spec['maxAnimBytes'] > $spec['maxBytes'], "$slot animation allowance");
        assert_true($spec['maxW'] > 0 && $spec['maxH'] > 0, "$slot has a box");
    }
});

// --------------------------------------------------------------------------
// walk_promo_images
// --------------------------------------------------------------------------

test('walk_promo_images visits every creative source and poster', function () {
    $doc = ['campaigns' => [
        ['id' => 'a', 'creatives' => [
            'strip' => ['src' => 's1', 'poster' => 'p1'],
            'rail'  => ['src' => 's2'],
        ]],
        ['id' => 'b', 'creatives' => ['popup' => ['src' => 's3', 'poster' => 'p3']]],
    ]];
    $seen = [];
    walk_promo_images($doc, function ($v) use (&$seen) { $seen[] = $v; return $v; });
    sort($seen);
    assert_eq(['p1', 'p3', 's1', 's2', 's3'], $seen, 'all image fields visited');
});

test('walk_promo_images rewrites in place and tolerates a broken document', function () {
    $doc = ['campaigns' => [['id' => 'a', 'creatives' => ['strip' => ['src' => 'x', 'poster' => 'y']]]]];
    $out = walk_promo_images($doc, function ($v) { return $v . '!'; });
    assert_eq('x!', $out['campaigns'][0]['creatives']['strip']['src'], 'src rewritten');
    assert_eq('y!', $out['campaigns'][0]['creatives']['strip']['poster'], 'poster rewritten');

    $identity = function ($v) { return $v; };
    assert_eq($doc, walk_promo_images($doc, $identity), 'identity leaves the document alone');
    assert_eq([], walk_promo_images([], $identity), 'empty document');
    assert_eq(['campaigns' => 'nope'], walk_promo_images(['campaigns' => 'nope'], $identity), 'junk campaigns');
    assert_eq(['campaigns' => [['id' => 'a']]],
        walk_promo_images(['campaigns' => [['id' => 'a']]], $identity), 'campaign with no creatives');
});

run_tests();
