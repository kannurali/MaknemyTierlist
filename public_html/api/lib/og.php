<?php
// Общие чистые хелперы для превью-картинок og-tierlist.php / og-news.php и
// для мета-тегов index.php / news.php: валидация версии, путь кэша на диске,
// обрезка длинного текста и «выжимка» живых данных в то немногое, что нужно
// превью. Здесь нет PDO и нет обращений к диску — можно require'ить в тестах
// и звать на голых массивах/строках.

// ---------------------------------------------------------------------------
//  Версия — то, что превращает "og-tierlist.php" (замерзает в кэше Телеграма
//  и Дискорда навсегда после первого фетча) в "og-tierlist.php?v=123"
//  (у каждого нового rev/поста — свой адрес, старый уже не спросят повторно).
// ---------------------------------------------------------------------------

// Строго цифры — ни минуса, ни точки, ни пробела, ни ведущего "+". Версия
// идёт прямо в имя файла на диске, поэтому даже одна не-цифра (например,
// "../etc" или "1;rm") должна отклонить всё значение целиком, а не обрезать
// его до цифровой части: (int)"1abc" тихо стало бы 1 и создало файл под
// чужим, непредсказуемым именем.
function og_parse_version($raw): ?int {
    if (is_int($raw)) { return $raw >= 0 ? $raw : null; }
    if (is_string($raw) && $raw !== '' && ctype_digit($raw)) { return (int)$raw; }
    return null;
}

// Имя файла кэша строится ТОЛЬКО из уже провалидированного целого — никогда
// из сырого GET-параметра. Это и есть граница, которая не даёт хостильному
// значению вида "../../config" или "1/../../etc/passwd" выйти за пределы
// images/og/: даже если бы такая строка дошла досюда, og_parse_version()
// выше уже вернула бы null, а не собственно path-фильтрация здесь.
function og_cache_filename(string $prefix, int $version): string {
    return $prefix . '-' . $version . '.png';
}

// Возвращает готовый путь к файлу кэша либо null, если $rawVersion не прошёл
// og_parse_version() — вызывающая сторона в этом случае обязана откатиться
// на статичный баннер, а не пытаться угадать путь из мусора.
function og_build_cache_path(string $dir, string $prefix, $rawVersion): ?string {
    $version = og_parse_version($rawVersion);
    if ($version === null) { return null; }
    return rtrim($dir, '/\\') . '/' . og_cache_filename($prefix, $version);
}

// ---------------------------------------------------------------------------
//  Текст
// ---------------------------------------------------------------------------

// Обрезка по границе слова с многоточием — для og:description и для строк на
// самой картинке (длинный заголовок новости или список топ-тира не должен ни
// вылезать за холст, ни выглядеть обрубленным посреди слова в карточке
// мессенджера). Схлопывает переносы строк и повторные пробелы: тело новости
// хранится с настоящими \n, а превью — одна строка.
function og_truncate(string $text, int $maxLen): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || mb_strlen($text) <= $maxLen) { return $text; }
    $cut = mb_substr($text, 0, $maxLen);
    $lastSpace = mb_strrpos($cut, ' ');
    // Резать по пробелу, только если он не в самом начале — иначе первое же
    // длинное слово обрубается до пустой строки.
    if ($lastSpace !== false && $lastSpace > 0) {
        $cut = mb_substr($cut, 0, $lastSpace);
    }
    return rtrim($cut) . '…';
}

// ---------------------------------------------------------------------------
//  Тирлист: превью строится по столбцу data (JSON) и rev уже прочитанной
//  строки БД. Никакого PDO внутри — вызывающая сторона (index.php,
//  api/og-tierlist.php) сама делает SELECT и передаёт сюда сырые значения.
//  Возвращает null всякий раз, когда живых данных для превью нет: строки нет,
//  data — не JSON-объект, tiers пуст, у верхнего тира нет ни одного предмета
//  с именем, либо rev не проходит og_parse_version(). Это и есть «решение об
//  откате» — вызывающая сторона обязана в этом случае показать статичный
//  assets/og-image.jpg, а не пустую/битую картинку.
// ---------------------------------------------------------------------------
function og_tierlist_summary($rawData, $rawRev): ?array {
    if (!is_string($rawData) || $rawData === '') { return null; }
    $tl = json_decode($rawData, true);
    if (!is_array($tl) || empty($tl['tiers']) || !is_array($tl['tiers'])) { return null; }

    $top = $tl['tiers'][0];
    if (!is_array($top)) { return null; }

    $items = [];
    foreach (($top['items'] ?? []) as $it) {
        if (!is_array($it)) { continue; }
        $name = trim((string)($it['name'] ?? ''));
        if ($name === '') { continue; } // безымянная заготовка предмета — не то, что стоит показывать в превью
        $items[] = [
            'name'  => $name,
            'value' => trim((string)($it['value'] ?? '')),
        ];
    }
    if (!$items) { return null; }

    $version = og_parse_version($rawRev);
    if ($version === null) { return null; }

    return [
        'version'   => $version,
        'tierLabel' => trim((string)($top['label'] ?? '')),
        'date'      => trim((string)($tl['date'] ?? '')),
        'items'     => $items,
    ];
}

// ---------------------------------------------------------------------------
//  og:image тирлиста — общая точка входа для / (home.php) и /tierlist
//  (index.php): корень сайта — самый шаримый адрес, и он обязан рекламировать
//  ТУ ЖЕ живую картинку, что и тирлист, а не собственную копию этой логики.
//  $summary — уже посчитанный og_tierlist_summary()/null, PDO сюда не
//  попадает (как и во весь остальной файл, см. комментарий вверху) —
//  вызывающая сторона сама делает SELECT и сама решает, что откат на null
//  означает "статичный баннер".
// ---------------------------------------------------------------------------
function og_tierlist_image(?array $summary): array {
    if ($summary === null) {
        return [
            'image'       => 'https://maknemytierlist.site/assets/og-image.jpg?v=2',
            'imageWidth'  => 1920,
            'imageHeight' => 1080,
            'imageType'   => 'image/jpeg',
        ];
    }
    return [
        'image'       => 'https://maknemytierlist.site/api/og-tierlist.php?v=' . $summary['version'],
        'imageWidth'  => 1200,
        'imageHeight' => 630,
        'imageType'   => 'image/png',
    ];
}

// ---------------------------------------------------------------------------
//  Новости: превью строится по строке САМОГО СВЕЖЕГО поста (ORDER BY
//  published_at DESC, id DESC LIMIT 1 — тот же порядок, что и в лентe
//  api/news.php). Версия — не rev (у новостей его нет и заводить его означает
//  вернуть тирлисту и ленте ту самую связку, ради разрыва которой таблица и
//  отдельная, см. комментарий в api/news.php), а id и published_at свежего
//  поста, склеенные в одно число: меняется id — другой пост стал свежим;
//  меняется published_at того же поста (админ подвинул дату задним числом,
//  см. news_save.php) — тоже другая версия. Оба всегда положительные целые,
//  так что склейка по построению состоит только из цифр.
// ---------------------------------------------------------------------------
function og_news_summary(?array $row): ?array {
    if ($row === null) { return null; }

    $title = trim((string)($row['title_ru'] ?? ''));
    if ($title === '') { return null; }

    $id = (int)($row['id'] ?? 0);
    $publishedAt = (int)($row['published_at'] ?? 0);
    if ($id <= 0 || $publishedAt <= 0) { return null; }

    $version = og_parse_version($id . $publishedAt);
    if ($version === null) { return null; }

    return [
        'version'     => $version,
        'category'    => (string)($row['category'] ?? ''),
        'title'       => $title,
        'body'        => (string)($row['body_ru'] ?? ''),
        'imageUrl'    => trim((string)($row['image_url'] ?? '')),
        'publishedAt' => $publishedAt,
    ];
}

// ---------------------------------------------------------------------------
//  og:title / og:description — собираются из summary отдельной чистой
//  функцией, а не прямо в index.php/news.php, чтобы текст карточки не
//  расходился, если превью когда-нибудь понадобится ещё в одном месте.
// ---------------------------------------------------------------------------

function og_tierlist_meta(array $summary): array {
    $names = array_map(function ($it) {
        return $it['value'] !== '' ? "{$it['name']} ({$it['value']})" : $it['name'];
    }, $summary['items']);
    $title = $summary['tierLabel'] !== ''
        ? 'Maknemy Tier List — ' . $summary['tierLabel'] . '-тир'
            . ($summary['date'] !== '' ? ' на ' . $summary['date'] : '')
        : 'Maknemy Tier List — трейд-ценности Blox Fruits';
    return [
        'title'       => $title,
        'description' => og_truncate(implode(', ', $names), 200),
    ];
}

function og_news_meta(array $summary): array {
    return [
        'title'       => $summary['title'],
        'description' => og_truncate($summary['body'], 200),
    ];
}
