<?php
// GD-хелперы для og-tierlist.php / og-news.php: загрузка исходника по
// формату, cover-fit кроп в холст, повторяющийся на сайте градиент-скрим,
// перенос текста по словам и сама отрисовка через imagettftext. Всё, что
// касается диска/GD, живёт здесь — в отличие от api/lib/og.php (чистые
// функции без побочных эффектов), поэтому рендеринг картинок сознательно
// не покрыт юнит-тестами (см. tests/og_test.php и отчёт по задаче).
//
// У $canvas СОЗНАТЕЛЬНО нет тайп-хинта GdImage, хотя по смыслу он туда
// просится. Класс GdImage появился только в PHP 8.0: до неё
// imagecreatetruecolor() возвращает resource, и хинт превращал бы КАЖДЫЙ
// вызов в TypeError. Боевой хост сейчас на 7.4 (см. заголовок X-Powered-By),
// а базовая версия проекта объявлена как «7.4+, без синтаксиса 8.x» в
// docs/superpowers/plans/2026-07-25-php-mysql-migration.md. Ошибка была бы
// ТИХОЙ: og-tierlist.php/og-news.php ловят Throwable целиком и откатываются
// на статичный баннер — превью просто молча не появлялось бы, и чинить его
// никто бы не пошёл. Когда хост переедет на 8.0+, хинты возвращаются одним
// движением: типы записаны в @param у каждой функции.

// Шрифты сайта — те же файлы, что подключает css/base.css у @font-face.
// Bootshaus — дисплейный (заголовки/тиры), Proto Sans — текст помельче.
define('OG_FONT_DISPLAY', __DIR__ . '/../../assets/fonts/Bootshaus/Bootshaus-Regular.ttf');
define('OG_FONT_TEXT', __DIR__ . '/../../assets/fonts/ProtoSans56.otf');

// Палитра — один в один из :root в css/base.css, чтобы превью не выглядело
// самодельной заглушкой, а читалось как часть сайта.
const OG_COLOR_BG      = [10, 14, 26];    // --bg-dark #0a0e1a
const OG_COLOR_CYAN     = [79, 214, 255]; // --cyan
const OG_COLOR_MK       = [214, 90, 255]; // --mk
const OG_COLOR_WHITE    = [234, 244, 255];// цвет текста body (#eaf4ff)
const OG_COLOR_MUTED    = [160, 190, 220];

function og_load_image(string $path) {
    if (!is_file($path)) { return false; }
    $info = @getimagesizefromstring((string)file_get_contents($path));
    if ($info === false) { return false; }
    switch ($info[2]) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($path);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($path);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        default: return false;
    }
}

// Рисует $srcPath в холст $canvas по всей его площади ($w x $h), масштабируя
// «cover» (без полос, с обрезкой лишнего) — та же идея, что background-size:
// cover в CSS. $anchor выбирает, какую часть по вертикали оставить в кадре
// после кропа: 'top' — верх исходника (для длинного постера сцены, у
// которого самое узнаваемое — верхняя, «шапочная» часть), 'center' — середину.
/** @param resource|GdImage $canvas холст, куда врисовывается исходник. */
function og_cover_crop($canvas, string $srcPath, int $w, int $h, string $anchor = 'top'): bool {
    $src = og_load_image($srcPath);
    if ($src === false) { return false; }
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) { return false; }

    $scale = max($w / $sw, $h / $sh);
    $drawW = (int)round($sw * $scale);
    $drawH = (int)round($sh * $scale);
    $dstX = (int)round(($w - $drawW) / 2);
    $dstY = $anchor === 'top' ? 0 : (int)round(($h - $drawH) / 2);
    if ($anchor === 'top' && $drawH > $h) { $dstY = 0; }

    imagecopyresampled($canvas, $src, $dstX, $dstY, 0, 0, $drawW, $drawH, $sw, $sh);
    return true;
}

// Тёмный скрим поверх фона — то же уравнение, что .stage::before в
// css/base.css (linear-gradient 180deg: 45% → 12% → 35% альфы rgb(6,8,18)),
// без него текст на светлом участке постера/фотографии нечитаем. Рисуется
// построчно полосами по 4 px — достаточно гладко на итоговом PNG и заметно
// дешевле, чем честный per-pixel градиент на холсте 1200x630.
/** @param resource|GdImage $canvas */
function og_apply_scrim($canvas, int $w, int $h): void {
    imagealphablending($canvas, true);
    $stops = [[0.0, 0.45], [0.30, 0.12], [1.0, 0.35]];
    $band = 4;
    for ($y = 0; $y < $h; $y += $band) {
        $t = $y / max(1, $h - 1);
        $alpha = og_gradient_alpha_at($stops, $t);
        $gdAlpha = (int)round((1 - $alpha) * 127); // GD: 0 непрозрачно, 127 прозрачно
        $color = imagecolorallocatealpha($canvas, 6, 8, 18, max(0, min(127, $gdAlpha)));
        imagefilledrectangle($canvas, 0, $y, $w - 1, min($h - 1, $y + $band - 1), $color);
    }
}

// Локальный скрим под текстовыми полосами — только для og-news.php. Фон
// og-tierlist.php всегда один и тот же тёмный постер сцены, поэтому ему
// подходит og_apply_scrim() выше один в один со .stage::before. У новости
// фон — чужая фотография поста произвольной яркости: тот же растянутый на
// весь кадр уклон либо не спасал текст на светлом кадре (плашка категории
// рисует свою заливку и не пострадала бы, а вот дата в правом верхнем углу —
// голый текст без подложки — оставалась нечитаемой на светлом фото), либо,
// если усилить его целиком, превращал бы фотографию в плоскую серую заливку
// — а весь смысл рисовать именно фото поста в том, что оно остаётся
// узнаваемым фото, а не фоном-заглушкой.
//
// Поэтому здесь две отдельные полосы, каждая короче и заметно темнее, чем
// была одна на весь кадр: одна под плашкой категории и датой сверху, другая
// под заголовком снизу. Обе меряются в пикселях от своего края кадра (не в
// долях высоты, как в og_apply_scrim выше) — так их длина не гуляет вместе с
// высотой холста. Стопы верхней и нижней кривой сходятся к одному и тому же
// низкому значению — именно поэтому середина кадра, где ни один из двух
// списков стопов уже не находит пару (og_gradient_alpha_at() в этом случае
// отдаёт альфу последнего стопа как есть, см. её комментарий), у обеих
// кривых одинакова, и в этой точке нет видимого шва между «верхним» и
// «нижним» затемнением — есть одна общая, почти нейтральная середина.
/** @param resource|GdImage $canvas */
function og_apply_news_scrim($canvas, int $w, int $h): void {
    imagealphablending($canvas, true);
    $topStops = [[0, 0.74], [60, 0.60], [110, 0.34], [160, 0.08]];
    $bottomStops = [[0, 0.78], [70, 0.62], [140, 0.34], [210, 0.08]];
    $band = 4;
    for ($y = 0; $y < $h; $y += $band) {
        $topAlpha = og_gradient_alpha_at($topStops, $y);
        $bottomAlpha = og_gradient_alpha_at($bottomStops, $h - 1 - $y);
        // max, а не сумма: полосы и так не пересекаются на разумной высоте
        // холста (160 + 210 < 630), но сложение всё равно дало бы двойное
        // затемнение ровно в той середине, которая обязана остаться самой
        // светлой частью кадра.
        $alpha = max($topAlpha, $bottomAlpha);
        $gdAlpha = (int)round((1 - $alpha) * 127);
        $color = imagecolorallocatealpha($canvas, 6, 8, 18, max(0, min(127, $gdAlpha)));
        imagefilledrectangle($canvas, 0, $y, $w - 1, min($h - 1, $y + $band - 1), $color);
    }
}

// Линейная интерполяция альфы между соседними стопами градиента (тот же
// метод, что браузер использует для CSS linear-gradient с промежуточными
// стопами) — вынесено отдельной функцией, чтобы og_apply_scrim() не путал
// два разных линейных пространства (t по холсту и t между стопами).
function og_gradient_alpha_at(array $stops, float $t): float {
    for ($i = 0; $i < count($stops) - 1; $i++) {
        [$p0, $a0] = $stops[$i];
        [$p1, $a1] = $stops[$i + 1];
        if ($t >= $p0 && $t <= $p1) {
            $span = $p1 - $p0;
            $local = $span > 0 ? ($t - $p0) / $span : 0;
            return $a0 + ($a1 - $a0) * $local;
        }
    }
    return end($stops)[1];
}

// Перенос текста по словам под пиксельную ширину — imagettfbbox меряет
// реальную геометрию шрифта, а не число символов, поэтому кириллица и Bootshaus
// (у него неравномерная ширина глифов) переносятся без обрезанных букв.
function og_wrap_text(string $font, float $size, string $text, int $maxWidthPx): array {
    $words = preg_split('/\s+/u', trim($text));
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if (og_text_width($font, $size, $candidate) <= $maxWidthPx || $line === '') {
            $line = $candidate;
        } else {
            $lines[] = $line;
            $line = $word;
        }
    }
    if ($line !== '') { $lines[] = $line; }
    return $lines;
}

// GD рисует текст через FreeType, но его обвязка в GD не умеет 4-байтовые
// последовательности UTF-8 — всё, что выше BMP, то есть эмодзи. Байты уходят
// в шрифт поодиночке, и «подарок» превращается в «Ð» плюс пустое место
// (шрифты сайта эмодзи всё равно не содержат, так что дело не только в GD).
// Заголовки новостей регулярно начинаются со значка, и он попадает ровно на
// самое видное место превью — поэтому вырезаем: потерять значок лучше, чем
// показать мусор. В og:title эмодзи остаётся, там его рисует браузер.
//
// Схлопывание пробелов — вторая половина работы: без него от вырезанного
// «(значок) Chromatic» осталась бы строка с провалом в начале.
function og_strip_unrenderable(string $text): string {
    $clean = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    if ($clean === null) { return $text; } // битый UTF-8 — отдаём как есть
    $collapsed = preg_replace('/\s{2,}/u', ' ', $clean);
    return trim($collapsed === null ? $clean : $collapsed);
}

// Замер и отрисовка чистят строку ОДИНАКОВО, каждая у себя: иначе ширина
// считалась бы по одному тексту, а рисовался бы другой, и перенос по словам
// разъехался бы с картинкой.
function og_text_width(string $font, float $size, string $text): float {
    $box = imagettfbbox($size, 0, $font, og_strip_unrenderable($text));
    return abs($box[2] - $box[0]);
}

// Обрезка строки под пиксельную ширину, а не число символов: имя предмета
// («Eclipse Dragon (CHROMATIC)») должно влезть в бюджет колонки вместе с
// многоточием, а посчитать это заранее по mb_strlen нельзя — у Proto Sans
// ширина глифов неравномерная, к тому же кириллица и латиница мерятся
// по-разному. Поэтому бинарным поиском находим максимальную длину среза,
// после которой "срез + …" ещё укладывается в maxWidthPx, и перемеряем
// именно этот, уже готовый к отрисовке вариант — а не исходную строку.
function og_truncate_to_width(string $font, float $size, string $text, float $maxWidthPx): string {
    if ($maxWidthPx <= 0) { return ''; }
    if (og_text_width($font, $size, $text) <= $maxWidthPx) { return $text; }

    $ellipsis = '…';
    if (og_text_width($font, $size, $ellipsis) > $maxWidthPx) { return ''; } // даже одно многоточие не влезает

    $len = mb_strlen($text);
    $lo = 0;
    $hi = $len;
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi + 1, 2);
        $candidate = rtrim(mb_substr($text, 0, $mid)) . $ellipsis;
        if (og_text_width($font, $size, $candidate) <= $maxWidthPx) {
            $lo = $mid;
        } else {
            $hi = $mid - 1;
        }
    }
    return rtrim(mb_substr($text, 0, $lo)) . $ellipsis;
}

// $y — базовая линия (нижний край строчных букв без выносных элементов),
// как того и ждёт imagettftext — сознательно не абстрагируем это в "верхний
// левый угол", чтобы не плодить путаницу в системе координат по всему файлу.
/** @param resource|GdImage $canvas */
function og_draw_text($canvas, string $font, float $size, array $rgb, int $x, int $y, string $text): void {
    $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
    imagettftext($canvas, $size, 0, $x, $y, $color, $font, og_strip_unrenderable($text));
}

/** @param resource|GdImage $canvas */
function og_draw_text_right_aligned($canvas, string $font, float $size, array $rgb, int $rightX, int $y, string $text): void {
    $w = og_text_width($font, $size, $text);
    og_draw_text($canvas, $font, $size, $rgb, (int)round($rightX - $w), $y, $text);
}

