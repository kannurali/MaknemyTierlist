<?php
// Новость целиком в каналы темы news: заголовок, весь текст с оформлением и все
// картинки. Подключается из tg_after_news() и только когда есть куда постить:
// telegram.php грузится на каждый запрос чата, а блоки и розыгрыши нужны здесь.
//
// Вид поста решает предел Telegram: подпись к фото — 1024 знака.
//   одна картинка, текст влезает в подпись — фото с подписью и кнопкой;
//   иначе — сначала картинки (альбомами до 10 штук), следом текст с кнопкой:
//   у альбома кнопок не бывает.
// Картинки идут в начале, а не между абзацами: иначе пост с тремя картинками
// расползался бы на шесть сообщений. Подписи к ним остаются на месте в тексте.
//
// Картинка уходит файлом, а не адресом: так Telegram не зависит от того, достучится
// ли он до сайта, и не молчит о неудаче, как превью ссылки. WebP пересохраняется
// в JPEG: в его поддержке для фото у Telegram нет уверенности. Файла нет или GD его
// не прочитал (анимация) — остаётся адрес на сайте.

require_once __DIR__ . '/giveaway.php';

// Предел подписи — 1024 знака, запас на эмодзи.
const TG_ROUTE_CAPTION_MAX = 1000;
const TG_ROUTE_ALBUM_MAX = 10;

// Блок новости в HTML Telegram; пусто — нечего показывать. Пустые абзацы редактор
// использует как отступ, в посте они не нужны.
function tg_route_news_block(array $b): string {
    if ($b['t'] === 'code') {
        $code = (string)$b['ru'] !== '' ? (string)$b['ru'] : (string)$b['en'];
        return trim($code) !== '' ? '<pre>' . tg_route_h($code) . '</pre>' : '';
    }
    if ($b['t'] === 'image' || $b['t'] === 'album') {
        $cap = trim(nb_spans_text($b['cap_ru'], $b['cap_en']));
        return $cap !== '' ? '<i>' . tg_route_h($cap) . '</i>' : '';
    }
    $html = gw_block_html($b);
    $seen = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_match('~^[\s\x{00A0}\x{200B}\x{FEFF}]*$~u', $seen) === 1 ? '' : $html;
}

// Тело поста: [куски HTML, адреса картинок]. Пост из блоков — по блокам, старый
// или с кривым body_json — по плоскому body_ru и image_url.
function tg_route_news_body(array $post): array {
    $doc = is_string($post['body_json'] ?? null) ? json_decode($post['body_json'], true) : null;
    $valid = is_array($doc) ? news_blocks_validate($doc) : ['ok' => false, 'blocks' => []];

    $blocks = [];
    $images = [];
    if ($valid['ok']) {
        foreach ($valid['blocks'] as $b) {
            $html = tg_route_news_block($b);
            if ($html !== '') { $blocks[] = $html; }
            if ($b['t'] === 'image') { $images[] = (string)$b['url']; }
            if ($b['t'] === 'album') {
                foreach ($b['items'] as $im) { $images[] = (string)$im['url']; }
            }
        }
    } else {
        $body = (string)$post['body_ru'] !== '' ? (string)$post['body_ru'] : (string)$post['body_en'];
        foreach (preg_split('~\R{2,}~u', $body) ?: [] as $par) {
            if (trim($par) !== '') { $blocks[] = tg_route_h(trim($par)); }
        }
    }
    $images[] = (string)($post['image_url'] ?? '');

    $ok = [];
    foreach ($images as $url) {
        if (preg_match(NB_IMAGE_RE, $url) && !in_array($url, $ok, true)) { $ok[] = $url; }
    }
    return [$blocks, $ok];
}

// JPEG из WebP во временном файле; прозрачное — на белом. null — GD не справился.
function tg_route_webp_to_jpg(string $path): ?string {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) { return null; }
    $bytes = @file_get_contents($path);
    $src = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
    if ($src === false) { return null; }
    $w = imagesx($src);
    $h = imagesy($src);
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);
    $tmp = sys_get_temp_dir() . '/nx-tg-' . bin2hex(random_bytes(8)) . '.jpg';
    $ok = imagejpeg($dst, $tmp, 92);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok ? $tmp : null;
}

// Как отправить картинку: ['file' => путь] или ['url' => адрес]. Временный файл
// дописывается в $temp, его убирает вызывающий.
function tg_route_news_photo(string $url, string $root, array &$temp): array {
    $path = $root . $url;
    if (is_file($path)) {
        if (substr($url, -5) !== '.webp') { return ['file' => $path]; }
        $jpg = tg_route_webp_to_jpg($path);
        if ($jpg !== null) {
            $temp[] = $jpg;
            return ['file' => $jpg];
        }
    }
    return ['url' => TG_SITE . $url];
}

function tg_route_photo_fields(array $photo): array {
    return isset($photo['file']) ? ['_files' => ['photo' => $photo['file']]] : ['photo' => $photo['url']];
}

// Картинки → вызовы: альбомами, одиночный остаток — обычным фото (в альбоме
// должно быть минимум две).
function tg_route_media_calls(array $photos): array {
    $calls = [];
    foreach (array_chunk($photos, TG_ROUTE_ALBUM_MAX) as $chunk) {
        if (count($chunk) === 1) {
            $calls[] = ['sendPhoto', tg_route_photo_fields($chunk[0])];
            continue;
        }
        $media = [];
        $files = [];
        foreach ($chunk as $i => $p) {
            if (isset($p['file'])) {
                $files['p' . $i] = $p['file'];
                $media[] = ['type' => 'photo', 'media' => 'attach://p' . $i];
            } else {
                $media[] = ['type' => 'photo', 'media' => $p['url']];
            }
        }
        $calls[] = ['sendMediaGroup', ['media' => $media] + ($files ? ['_files' => $files] : [])];
    }
    return $calls;
}

// Все вызовы поста; вид — в шапке файла. Не вышло фото с подписью — текст уходит
// без него (третий элемент вызова).
function tg_route_news_calls(array $post, array $blocks, array $photos): array {
    $title = (string)$post['title_ru'] !== '' ? (string)$post['title_ru'] : (string)$post['title_en'];
    $all = array_merge(['📰 <b>' . tg_route_h(tg_clean_line($title, 200)) . '</b>'], $blocks);
    $text = tg_route_text_calls($all, tg_text('readNews', 'ru'), '/news/' . (int)$post['id']);
    if (!$photos) { return $text; }

    $whole = implode("\n\n", $all);
    if (count($photos) === 1 && tg_route_len($whole) <= TG_ROUTE_CAPTION_MAX) {
        $fields = tg_route_photo_fields($photos[0]) + [
            'caption'      => $whole,
            'parse_mode'   => 'HTML',
            'reply_markup' => $text[0][1]['reply_markup'],
        ];
        return [['sendPhoto', $fields, $text]];
    }
    return array_merge(tg_route_media_calls($photos), $text);
}

/**
 * Новый пост новостей — в чаты темы news. Те же правила свежести, что у личной
 * рассылки: перенос старой записи задним числом новостью не считается.
 * $root — public_html, подставляют тесты. → в скольких чатах вышел.
 */
function tg_route_news(PDO $pdo, callable $send, int $newsId, int $now, ?callable $sleep = null, ?string $root = null): int {
    if (!tg_routes_for($pdo, 'news')) { return 0; }
    $post = tg_news_fresh($pdo, $newsId, $now);
    if (!$post) { return 0; }

    [$blocks, $images] = tg_route_news_body($post);
    $root = $root ?? dirname(__DIR__, 2);
    $temp = [];
    $photos = [];
    foreach ($images as $url) { $photos[] = tg_route_news_photo($url, $root, $temp); }
    try {
        return tg_route_post($pdo, $send, 'news', tg_route_news_calls($post, $blocks, $photos), $now, $sleep);
    } finally {
        foreach ($temp as $file) { @unlink($file); }
    }
}
