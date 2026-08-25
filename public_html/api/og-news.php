<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/og.php';
require_once __DIR__ . '/lib/og_render.php';

// Превью-картинка последней новости для og:image — 1200x630: категория,
// дата, заголовок и собственная картинка поста, если она есть. Та же схема
// кэширования и отказоустойчивости, что у og-tierlist.php — см. комментарий
// там же; версия здесь — id и published_at свежего поста, склеенные в одно
// число (og_news_summary() в api/lib/og.php).

const OG_NEWS_CANVAS_W = 1200;
const OG_NEWS_CANVAS_H = 630;

function handle_og_news(PDO $pdo, string $imagesDir, $rawVersion): ?string {
    $cacheDir = rtrim($imagesDir, '/\\') . '/og';
    $cachePath = og_build_cache_path($cacheDir, 'news', $rawVersion);
    if ($cachePath === null) { return null; }
    if (is_file($cachePath)) { return $cachePath; }

    $row = $pdo->query(
        'SELECT id, category, title_ru, body_ru, image_url, published_at
           FROM news ORDER BY published_at DESC, id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $summary = og_news_summary($row === false ? null : $row);
    if ($summary === null) { return null; }
    if ($summary['version'] !== og_parse_version($rawVersion)) { return null; }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) { return null; }
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) { return null; }

    og_render_news_png($summary, $imagesDir, $cachePath);
    return is_file($cachePath) ? $cachePath : null;
}

// Ярлык и цвет плашки категории — те же три категории и те же цвета, что
// .nw-cat.c-tierlist/c-game/c-project в css/news.css.
function og_news_category_style(string $cat): array {
    switch ($cat) {
        case 'tierlist': return ['ТИРЛИСТ', OG_COLOR_CYAN];
        case 'game':     return ['ИГРА', OG_COLOR_GAME];
        case 'project':  return ['ПРОЕКТ', OG_COLOR_PROJECT];
        default:         return [mb_strtoupper($cat, 'UTF-8'), OG_COLOR_MUTED];
    }
}

function og_render_news_png(array $summary, string $imagesDir, string $outPath): void {
    $w = OG_NEWS_CANVAS_W;
    $h = OG_NEWS_CANVAS_H;
    $canvas = imagecreatetruecolor($w, $h);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...OG_COLOR_BG));

    // Своя картинка поста — фоном, если она есть; иначе тот же постер сцены,
    // что и на тирлисте, чтобы пост без картинки не превращался в голый текст.
    $bgDrawn = false;
    if ($summary['imageUrl'] !== '') {
        $localPath = rtrim($imagesDir, '/\\') . '/' . basename($summary['imageUrl']);
        $bgDrawn = og_cover_crop($canvas, $localPath, $w, $h, 'center');
    }
    if (!$bgDrawn) {
        og_cover_crop($canvas, __DIR__ . '/../assets/poster/bg-export.jpg', $w, $h, 'top');
    }
    // og_apply_news_scrim(), а не og_apply_scrim(): та тянет один и тот же
    // уклон .stage::before на весь кадр, что годится для постоянного тёмного
    // постера тирлиста, но не для чужой фотографии поста произвольной
    // яркости — см. её комментарий в api/lib/og_render.php.
    og_apply_news_scrim($canvas, $w, $h);

    [$catLabel, $catColor] = og_news_category_style($summary['category']);
    og_draw_pill($canvas, OG_FONT_TEXT, 20, $catColor, OG_COLOR_BG, 56, 48, 18, 10, $catLabel);

    if ($summary['publishedAt'] > 0) {
        $dateText = date('d.m.Y', intdiv($summary['publishedAt'], 1000));
        og_draw_text_right_aligned($canvas, OG_FONT_TEXT, 22, OG_COLOR_MUTED, $w - 56, 86, $dateText);
    }

    // Заголовок — крупно, до трёх строк с переносом по словам; если строк
    // получилось больше, последняя видимая обрезается многоточием, а не
    // молча теряет хвост.
    $rawLines = og_wrap_text(OG_FONT_DISPLAY, 48, mb_strtoupper($summary['title'], 'UTF-8'), $w - 112);
    $titleLines = array_slice($rawLines, 0, 3);
    if (count($rawLines) > 3) {
        $last = end($titleLines);
        $titleLines[count($titleLines) - 1] = rtrim($last) . '…';
    }
    $y = $h - 60 - (count($titleLines) - 1) * 58;
    foreach ($titleLines as $line) {
        og_draw_text($canvas, OG_FONT_DISPLAY, 48, OG_COLOR_WHITE, 56, $y, $line);
        $y += 58;
    }

    $marks = og_load_image(__DIR__ . '/../assets/poster/marks.png');
    if ($marks !== false) {
        $mW = 150;
        $mH = (int)round(imagesy($marks) * $mW / imagesx($marks));
        imagecopyresampled($canvas, $marks, $w - $mW - 56, $h - $mH - 30, 0, 0, $mW, $mH, imagesx($marks), imagesy($marks));
    }

    imagepng($canvas, $outPath, 8);
}

if (!defined('TESTING')) {
    try {
        $cfg = app_config();
        $cachePath = handle_og_news(db(), $cfg['images_dir'], $_GET['v'] ?? null);
        if ($cachePath !== null) {
                        // Любой шум, попавший в вывод до этой точки (notice, warning,
            // deprecation — так уже случалось с imagedestroy на PHP 8.5),
            // приклеился бы ПЕРЕД сигнатурой PNG и сделал бы ответ битой
            // картинкой: файл на диске при этом остаётся целым, а превью в
            // Телеграме молча не появляется. Чистим буфер перед отдачей.
            while (ob_get_level() > 0) { ob_end_clean(); }
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($cachePath);
            exit;
        }
    } catch (Throwable $e) {
        error_log('og-news.php: ' . $e->getMessage());
    }
    header('Location: /assets/og-image.jpg?v=2', true, 302);
    exit;
}
