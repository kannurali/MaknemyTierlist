<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/og.php';
require_once __DIR__ . '/lib/og_render.php';

// Превью-картинка тирлиста для og:image — 1200x630. Кэшируется на диск по
// версии (rev тирлиста, см. api/lib/og.php#og_tierlist_summary) и генерится
// один раз: повторный запрос той же версии отдаёт уже готовый файл, минуя и
// БД, и GD. index.php ссылается сюда как /api/og-tierlist.php?v=<rev>.
//
// GD здесь не покрыт юнит-тестами (см. tests/og_test.php и отчёт по задаче) —
// тестируется только чистая часть в api/lib/og.php. Любая ошибка (GD
// недоступен, каталог не пишется, БД недоступна, данных ещё нет) ловится
// целиком в диспетчере ниже и превращается в редирект на статичный баннер:
// сломанное превью — эстетическая проблема, 500 на тирлисте — нет.

const OG_TIERLIST_CANVAS_W = 1200;
const OG_TIERLIST_CANVAS_H = 630;

// Возвращает путь к готовому PNG либо null — сигнал вызывающей стороне
// откатиться на статичный assets/og-card.jpg. null получается в трёх
// принципиально разных случаях: (1) $rawVersion не прошёл валидацию —
// см. og_build_cache_path(); (2) в тирлисте нет данных для превью — см.
// og_tierlist_summary(); (3) запрошенная версия устарела (не совпадает с
// текущим rev), а кэша для неё никогда не было — рендерить нечего, у нас
// есть только текущий срез данных, а не архив по версиям.
function handle_og_tierlist(PDO $pdo, string $imagesDir, $rawVersion): ?string {
    $cacheDir = rtrim($imagesDir, '/\\') . '/og';
    $cachePath = og_build_cache_path($cacheDir, 'tierlist', $rawVersion);
    if ($cachePath === null) { return null; }
    if (is_file($cachePath)) { return $cachePath; }

    $row = $pdo->query('SELECT data, rev FROM tierlist WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    $summary = og_tierlist_summary($row['data'] ?? null, $row['rev'] ?? null);
    if ($summary === null) { return null; }
    if ($summary['version'] !== og_parse_version($rawVersion)) { return null; }

    if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) { return null; }
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) { return null; }

    og_render_tierlist_png($summary, $cachePath);
    return is_file($cachePath) ? $cachePath : null;
}

// Сама отрисовка: постер сцены фоном (та же картинка, что на самом сайте),
// тёмный скрим сверху (тот же градиент, что .stage::before в css/base.css),
// брендовые марки, крупная плашка топ-тира с его предметами и ценами, дата
// тирлиста внизу.
function og_render_tierlist_png(array $summary, string $outPath): void {
    $w = OG_TIERLIST_CANVAS_W;
    $h = OG_TIERLIST_CANVAS_H;
    $canvas = imagecreatetruecolor($w, $h);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...OG_COLOR_BG));

    og_cover_crop($canvas, __DIR__ . '/../assets/poster/bg-export.jpg', $w, $h, 'top');
    og_apply_scrim($canvas, $w, $h);

    // Шапка: логотип Blox Fruits + вордмарк слева, брендовые марки справа —
    // те же элементы, что в <header> тирлиста и ленты новостей.
    $bf = og_load_image(__DIR__ . '/../assets/poster/logo-bf.png');
    if ($bf !== false) {
        $bfH = 64;
        $bfW = (int)round(imagesx($bf) * $bfH / imagesy($bf));
        imagecopyresampled($canvas, $bf, 56, 40, 0, 0, $bfW, $bfH, imagesx($bf), imagesy($bf));
        og_draw_text($canvas, OG_FONT_DISPLAY, 26, OG_COLOR_CYAN, 56 + $bfW + 22, 86, 'MAKNEMY TIER LIST');
    } else {
        og_draw_text($canvas, OG_FONT_DISPLAY, 26, OG_COLOR_CYAN, 56, 86, 'MAKNEMY TIER LIST');
    }

    $marks = og_load_image(__DIR__ . '/../assets/poster/marks.png');
    if ($marks !== false) {
        $mW = 190;
        $mH = (int)round(imagesy($marks) * $mW / imagesx($marks));
        imagecopyresampled($canvas, $marks, $w - $mW - 56, 40, 0, 0, $mW, $mH, imagesx($marks), imagesy($marks));
    }

    // Крупная плашка топ-тира.
    $tierLabel = $summary['tierLabel'] !== '' ? mb_strtoupper($summary['tierLabel'], 'UTF-8') . '-ТИР' : 'ТОП-ТИР';
    og_draw_text($canvas, OG_FONT_DISPLAY, 64, OG_COLOR_MK, 56, 230, $tierLabel);

    // Его предметы: одна колонка вместо прежних двух. Раньше вторая колонка
    // начиналась на фиксированном x=620 без учёта реальной ширины текста —
    // на живых длинных именах ("Eclipse Dragon (CHROMATIC)", "Permanent
    // Kitsune", "Galaxy (Empyrean Kitsune)") первая колонка залезала во вторую
    // сплошным нечитаемым нагромождением глифов. Две колонки по ~500px не
    // проходят ни при каком разумном размере шрифта: имя такой длины плюс
    // значение — это 700-800px, а на колонку остаётся вдвое меньше (см. отчёт
    // по задаче — обмеры реальным imagettfbbox). Одна колонка на всю ширину
    // контента (1088px) держит эти же имена без обрезки вообще. Меньше строк,
    // зато каждая читается — то, о чём и просили.
    $items = array_slice($summary['items'], 0, 5);
    $itemFontSize = 26;
    $colX0 = 56;
    $colX1 = $w - 56; // 1144 — правая граница колонки, она же правый край значения
    $colWidth = $colX1 - $colX0;
    $nameValueGap = 28; // зазор между концом имени и началом значения
    $rowY = 300;
    $rowH = 50;
    foreach ($items as $i => $it) {
        $y = $rowY + $i * $rowH;
        $value = $it['value'];

        // Значение — то, ради чего картинка вообще существует, поэтому оно
        // получает свою ширину первым и рисуется прижатым к правому краю
        // колонки, чтобы цифры образовывали ровный край. Имя получает то, что
        // осталось: бюджет = ширина колонки минус то, что реально (не
        // предположительно) занимает значение.
        $gap = $value !== '' ? $nameValueGap : 0;
        $valueW = $value !== '' ? og_text_width(OG_FONT_TEXT, $itemFontSize, $value) : 0;
        $nameBudget = $colWidth - $valueW - $gap;
        if ($nameBudget < 0) {
            // Экстремальный hostile-случай: само значение шире колонки.
            // Требование «не пересекать границу колонки» строже, чем
            // «значение видно целиком», поэтому только здесь ужимаем и само
            // значение по ширине — с тем же перемером после обрезки.
            $value = og_truncate_to_width(OG_FONT_TEXT, $itemFontSize, $value, $colWidth);
            $valueW = og_text_width(OG_FONT_TEXT, $itemFontSize, $value);
            $nameBudget = max(0, $colWidth - $valueW - $gap);
        }

        $name = og_truncate_to_width(OG_FONT_TEXT, $itemFontSize, $it['name'], $nameBudget);
        og_draw_text($canvas, OG_FONT_TEXT, $itemFontSize, OG_COLOR_WHITE, $colX0, $y, $name);
        if ($value !== '') {
            og_draw_text_right_aligned($canvas, OG_FONT_TEXT, $itemFontSize, OG_COLOR_WHITE, $colX1, $y, $value);
        }
    }

    // Низ: дата тирлиста слева, адрес сайта справа.
    if ($summary['date'] !== '') {
        og_draw_text($canvas, OG_FONT_TEXT, 22, OG_COLOR_MUTED, 56, $h - 40, 'Обновлено ' . $summary['date']);
    }
    og_draw_text_right_aligned($canvas, OG_FONT_TEXT, 22, OG_COLOR_MUTED, $w - 56, $h - 40, 'maknemy.com');

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
        $cachePath = handle_og_tierlist(db(), $cfg['images_dir'], $_GET['v'] ?? null);
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
        // Настоящая причина — в лог, наружу — просто откат на статичный баннер.
        error_log('og-tierlist.php: ' . $e->getMessage());
    }
    // 302, а не 301: это временный сбой конкретного запроса (нет данных,
    // не удался рендер), а не постоянный переезд адреса.
    header('Location: /assets/og-card.jpg?v=1', true, 302);
    exit;
}
