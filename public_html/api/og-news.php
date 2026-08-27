<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/og.php';
require_once __DIR__ . '/lib/og_render.php';

// Превью-картинка новости для og:image — 1200x630: категория, дата,
// заголовок и собственная картинка поста, если она есть. Та же схема
// кэширования и отказоустойчивости, что у og-tierlist.php — см. комментарий
// там же; версия здесь — id и published_at поста, склеенные в одно число
// (og_news_summary() в api/lib/og.php).
//
// Рендерит либо самый свежий пост (как и раньше — $rawId не передан, обратная
// совместимость со старыми ссылками вида ?v=... без &id=), либо конкретный
// пост по id (задача про постоянные ссылки: /news/<id> в public_html/news.php
// строит og:image как og-news.php?id=<id>&v=<версия этого поста>). id и
// версия — намеренно два разных параметра, а не один: версия одна на
// склейку id+published_at и однозначно НЕ восстанавливается обратно в id
// (например, "123000" получается и из id=1+published_at=23000, и из
// id=12+published_at=3000) — без явного id пришлось бы гадать, какой именно
// пост рендерить.

const OG_NEWS_CANVAS_W = 1200;
const OG_NEWS_CANVAS_H = 630;

function handle_og_news(PDO $pdo, string $imagesDir, $rawVersion, $rawId = null): ?string {
    $cacheDir = rtrim($imagesDir, '/\\') . '/og';
    $cachePath = og_build_cache_path($cacheDir, 'news', $rawVersion);
    if ($cachePath === null) { return null; }
    if (is_file($cachePath)) { return $cachePath; }

    if ($rawId === null) {
        // Старое поведение: без явного id — самый свежий пост.
        $row = $pdo->query(
            'SELECT id, category, title_ru, body_ru, image_url, published_at
               FROM news ORDER BY published_at DESC, id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
    } else {
        // id ЗАДАН — значит, это не "гадай сам", а точный запрос конкретного
        // поста; мусор здесь отклоняется целиком (откат на баннер), а не
        // молча трактуется как "id не задан, покажи свежий" — иначе
        // испорченный ?id= в чужой ссылке незаметно подсунул бы совсем не тот
        // пост, о котором думает читающий.
        $id = og_parse_version($rawId);
        if ($id === null) { return null; }
        $stmt = $pdo->prepare(
            'SELECT id, category, title_ru, body_ru, image_url, published_at
               FROM news WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    $summary = og_news_summary($row === false ? null : $row);
    if ($summary === null) { return null; }
    if ($summary['version'] !== og_parse_version($rawVersion)) { return null; }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) { return null; }
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) { return null; }

    og_render_news_png($summary, $imagesDir, $cachePath);
    return is_file($cachePath) ? $cachePath : null;
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

    // Плашки категории здесь больше нет. В карточке мессенджера превью читают
    // мельком, и «ИГРА»/«ПРОЕКТ» отнимали внимание у того единственного, ради
    // чего карточку вообще открывают, — у фотографии поста и его заголовка.
    // Категория никуда не делась из самой ленты (.nw-cat в css/news.css), где
    // у неё есть работа: там по ней фильтруют.
    if ($summary['publishedAt'] > 0) {
        $dateText = date('d.m.Y', intdiv($summary['publishedAt'], 1000));
        og_draw_text_right_aligned($canvas, OG_FONT_TEXT, 22, OG_COLOR_MUTED, $w - 56, 86, $dateText);
    }

    // Заголовок на картинке НЕ рисуется, хотя раньше рисовался внизу крупно.
    // Мессенджер печатает og:title отдельной строкой над самой картинкой, то
    // есть читатель видел заголовок дважды: один раз шрифтом интерфейса и тут
    // же второй раз — врисованным в фотографию. Дубль занимал нижнюю треть
    // кадра и закрывал собой то, ради чего картинка в карточке и нужна.
    //
    // Второй экземпляр был к тому же худшим из двух: GD не умеет 4-байтовые
    // последовательности UTF-8, и эмодзи в начале заголовка (а они там
    // регулярно) превращалось в «Ð» с провалом — тогда как в og:title тот же
    // значок рисует браузер, и там он целый.

    $marks = og_load_image(__DIR__ . '/../assets/poster/marks.png');
    if ($marks !== false) {
        $mW = 150;
        $mH = (int)round(imagesy($marks) * $mW / imagesx($marks));
        imagecopyresampled($canvas, $marks, $w - $mW - 56, $h - $mH - 30, 0, 0, $mW, $mH, imagesx($marks), imagesy($marks));
    }

    // Пишем во временный файл и переименовываем, а не прямо в $outPath.
    // Первую ссылку на свежую версию обычно дёргают несколько краулеров
    // разом (Telegram, Discord, VK), и у всех сразу промах кэша: без этого
    // два imagepng() открывали бы один и тот же путь на запись с усечением
    // и могли оставить недописанный PNG. Дальше is_file() считает его
    // готовым, а отдаётся он с immutable на год — то есть битое превью не
    // чинится само НИКОГДА, только удалением файла руками.
    // rename() в пределах одной ФС атомарен: параллельный запрос видит либо
    // отсутствие файла, либо целый PNG, но не половину. Не удалось
    // переименовать — убираем временный файл и оставляем кэш пустым:
    // вызывающая сторона проверяет is_file() и сама откатится на баннер.
    $tmp = $outPath . '.' . getmypid() . '.tmp';
    imagepng($canvas, $tmp, 8);
    if (!@rename($tmp, $outPath)) { @unlink($tmp); }
}

if (!defined('TESTING')) {
    try {
        $cfg = app_config();
        $cachePath = handle_og_news(db(), $cfg['images_dir'], $_GET['v'] ?? null, $_GET['id'] ?? null);
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
