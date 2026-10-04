<?php
// Хэллоуин-копии стилей: css/<файл> → css/hw/<файл>. Подробности, зачем и как
// они подключаются, — в public_html/api/lib/halloween.php.
//
//   php tools/halloween_css.php           пересобрать все копии
//   php tools/halloween_css.php --check   только проверить, что копии свежие
//
// Копия — тот же файл байт в байт, кроме трёх вещей:
//   1. синие цвета становятся фиолетовыми (hw_color()): два цвета фирменного
//      градиента и свечение подвала — точно как в макете, остальные синие —
//      поворотом оттенка в фиолетовый диапазон с той же насыщенностью и
//      светлотой, чтобы светлый синий остался светлым, а тёмно-синий — тёмным;
//   2. синие картинки (фон, подвал, постер тирлиста) заменяются на
//      assets/halloween/ (HW_ASSETS);
//   3. остальные url(../…) получают лишний ../, потому что копия лежит на
//      уровень глубже.
// Цвета меняются только внутри блоков объявлений: в селекторах решётка — это
// id, а не цвет.

const HW_CSS_FILES = [
    'base.css', 'calculator.css', 'chat.css', 'design-page.css', 'home.css',
    'legal.css', 'news-design.css', 'news.css', 'profile.css', 'promo-popup.css',
    'stock.css', 'styles.css', 'topbar.css', 'trading.css',
];

const HW_ASSETS = [
    'design/page-bg.webp'          => 'halloween/page-bg.webp',
    'design/page-bg-m.webp'        => 'halloween/page-bg-m.webp',
    'design/foot/foot-bg.webp'     => 'halloween/foot-bg.webp',
    'poster/bg-tile.webp'          => 'halloween/bg-tile.webp',
    'poster/bg-tile-m.webp'        => 'halloween/bg-tile-m.webp',
    'poster/bg-tile-export.jpg'    => 'halloween/bg-tile-export.jpg',
    'poster/petals-tile.webp'      => 'halloween/petals-tile.webp',
    'poster/petals-tile-m.webp'    => 'halloween/petals-tile-m.webp',
    'poster/petals-tile-export.png' => 'halloween/petals-tile-export.png',
    'poster/band.webp'             => 'halloween/band.webp',
    'poster/band.png'              => 'halloween/band.png',
];

// Точные замены из макета: Figma-страница «хелоуин» против «основной».
const HW_EXACT = [
    '61b5e9' => [132, 0, 255],
    '2d4aed' => [187, 0, 255],
    '008cff' => [102, 0, 255],
    '0096ff' => [115, 0, 255],
];

function hw_rgb_to_hsl(int $r, int $g, int $b): array {
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max == $min) { return [0.0, 0.0, $l]; }
    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
    if ($max == $r)      { $h = ($g - $b) / $d + ($g < $b ? 6 : 0); }
    elseif ($max == $g)  { $h = ($b - $r) / $d + 2; }
    else                 { $h = ($r - $g) / $d + 4; }
    return [$h * 60, $s, $l];
}

function hw_hsl_to_rgb(float $h, float $s, float $l): array {
    $h = fmod($h, 360) / 360;
    if ($s == 0) { $v = (int)round($l * 255); return [$v, $v, $v]; }
    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
    $p = 2 * $l - $q;
    $f = function (float $t) use ($p, $q): float {
        if ($t < 0) { $t += 1; }
        if ($t > 1) { $t -= 1; }
        if ($t < 1 / 6) { return $p + ($q - $p) * 6 * $t; }
        if ($t < 1 / 2) { return $q; }
        if ($t < 2 / 3) { return $p + ($q - $p) * (2 / 3 - $t) * 6; }
        return $p;
    };
    return [(int)round($f($h + 1 / 3) * 255), (int)round($f($h) * 255), (int)round($f($h - 1 / 3) * 255)];
}

// Синий → фиолетовый, или null, если цвет не синий и остаётся как был.
// Синий — оттенок 185–250° при заметной насыщенности; он ложится в 268–292°,
// где и живут оба цвета макетного градиента (#8400ff — 271°, #bb00ff — 284°).
function hw_color(int $r, int $g, int $b): ?array {
    $hex = sprintf('%02x%02x%02x', $r, $g, $b);
    if (isset(HW_EXACT[$hex])) { return HW_EXACT[$hex]; }
    [$h, $s, $l] = hw_rgb_to_hsl($r, $g, $b);
    if ($s < 0.2 || $l < 0.04 || $l > 0.97 || $h < 185 || $h > 250) { return null; }
    return hw_hsl_to_rgb(268 + ($h - 185) * 24 / 65, $s, $l);
}

function hw_hex(array $c): string {
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
}

function hw_values(string $block): string {
    $block = preg_replace_callback('~(?<!url\()(?<!url\(["\'])(%23|#)([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3,4})\b~', function ($m) {
        $h = $m[2];
        $alpha = '';
        if (strlen($h) === 3 || strlen($h) === 4) {
            $alpha = strlen($h) === 4 ? str_repeat($h[3], 2) : '';
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        } elseif (strlen($h) === 8) {
            $alpha = substr($h, 6);
            $h = substr($h, 0, 6);
        }
        $c = hw_color(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)));
        if ($c === null) { return $m[0]; }
        return $m[1] . substr(hw_hex($c), 1) . strtolower($alpha);
    }, $block);

    $block = preg_replace_callback('~\b(rgba?)\(\s*(\d{1,3})(\s*,\s*|\s+)(\d{1,3})(\s*,\s*|\s+)(\d{1,3})~i', function ($m) {
        $c = hw_color((int)$m[2], (int)$m[4], (int)$m[6]);
        if ($c === null) { return $m[0]; }
        return $m[1] . '(' . $c[0] . $m[3] . $c[1] . $m[5] . $c[2];
    }, $block);

    return preg_replace_callback('~url\((["\']?)\.\./assets/([^"\')?]+)([^"\')]*)\1\)~', function ($m) {
        $path = HW_ASSETS[$m[2]] ?? $m[2];
        return 'url(' . $m[1] . '../../assets/' . $path . $m[3] . $m[1] . ')';
    }, $block);
}

function hw_transform_css(string $css): string {
    return preg_replace_callback('~\{([^{}]*)\}~', function ($m) {
        return '{' . hw_values($m[1]) . '}';
    }, $css);
}

function hw_css_dir(): string {
    return dirname(__DIR__) . '/public_html/css';
}

// Список [имя, ожидаемое содержимое копии, текущее содержимое копии или null].
function hw_css_plan(): array {
    $dir = hw_css_dir();
    $plan = [];
    foreach (HW_CSS_FILES as $name) {
        $src = file_get_contents("$dir/$name");
        if ($src === false) { throw new RuntimeException("нет css/$name"); }
        $have = is_file("$dir/hw/$name") ? file_get_contents("$dir/hw/$name") : null;
        $plan[] = [$name, hw_transform_css($src), $have];
    }
    return $plan;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $check = in_array('--check', $argv, true);
    $stale = 0;
    foreach (hw_css_plan() as [$name, $want, $have]) {
        if ($want === $have) { continue; }
        $stale++;
        if ($check) {
            fwrite(STDERR, "устарела css/hw/$name\n");
            continue;
        }
        if (!is_dir(hw_css_dir() . '/hw')) { mkdir(hw_css_dir() . '/hw', 0755); }
        file_put_contents(hw_css_dir() . "/hw/$name", $want);
        echo "css/hw/$name\n";
    }
    exit($check && $stale ? 1 : 0);
}
