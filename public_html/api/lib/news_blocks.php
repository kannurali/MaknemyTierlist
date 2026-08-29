<?php
// Серверная половина модели блочного тела поста. Обязана принимать и
// отклонять РОВНО то же, что js/news-blocks.js: клиентская проверка — это
// удобство редактора, а не защита, и любое расхождение здесь становится
// дырой. Каждая константа ниже имеет двойника в том файле; правится пара.
//
// PHP 7.4: без match, без enum, без промоушена конструктора — на бою стоит
// именно эта версия (см. ветку fix/php74-compat-and-og-hardening).

const NB_DOC_VERSION  = 1;
const NB_LIMIT_BLOCKS = 200;       // LIMITS.blocks
const NB_LIMIT_ALBUM  = 10;        // LIMITS.albumItems
const NB_LIMIT_JSON   = 65536;     // LIMITS.json
const NB_LIMIT_LIST   = 100;       // LIMITS.listItems
const NB_LIMIT_SPANS  = 200;       // LIMITS.spans
const NB_LIMIT_CODE   = 4096;
const NB_LIMIT_HREF   = 2048;

const NB_BLOCK_TYPES = ['p', 'quote', 'list', 'code', 'image', 'album'];
const NB_SPAN_FLAGS  = ['b', 'i', 'u', 'st', 'c', 'sp'];
const NB_ALIGNS      = ['left', 'center', 'right'];

// Тот же белый список формы, что NEWS_IMAGE_RE в api/news_save.php.
const NB_IMAGE_RE = '#^/images/[0-9a-f]{40}\.(png|jpg|webp)$#';

const NB_BLOCK_KEYS = [
    'p'     => ['t', 'ru', 'en'],
    'quote' => ['t', 'ru', 'en', 'collapsible'],
    'list'  => ['t', 'ordered', 'items'],
    'code'  => ['t', 'ru', 'en'],
    'image' => ['t', 'url', 'w', 'h', 'pct', 'align', 'wrap', 'cap_ru', 'cap_en'],
    'album' => ['t', 'items', 'cap_ru', 'cap_en'],
];

function nb_keys_allowed(array $a, array $allowed): bool {
    foreach (array_keys($a) as $k) {
        if (!in_array($k, $allowed, true)) { return false; }
    }
    return true;
}

// Целое в границах. json_decode отдаёт числа int или float — 10.0 приходит
// float-ом и обязано пройти, а 10.5 — нет.
function nb_is_int_between($v, int $lo, int $hi): bool {
    if (is_bool($v)) { return false; }
    if (is_int($v)) { return $v >= $lo && $v <= $hi; }
    if (is_float($v) && $v == (int)$v) { $n = (int)$v; return $n >= $lo && $n <= $hi; }
    return false;
}

// Схема ссылки. Регистр не важен: "JavaScript:" браузер выполнит так же, как
// "javascript:", поэтому проверка — белый список http/https, а не чёрный
// список опасного.
function nb_is_safe_href($v): bool {
    if (!is_string($v) || strlen($v) > NB_LIMIT_HREF) { return false; }
    return (bool)preg_match('#^https?://#i', $v);
}

// Список, а не словарь: json_decode отдаёт объект JSON тоже массивом, и без
// этой проверки {"0":...} прошло бы там, где ждут [...].
function nb_is_list($v): bool {
    return is_array($v) && $v === array_values($v);
}

function nb_valid_spans($v): bool {
    if (!nb_is_list($v) || count($v) > NB_LIMIT_SPANS) { return false; }
    $allowed = array_merge(['s', 'href'], NB_SPAN_FLAGS);
    foreach ($v as $sp) {
        if (!is_array($sp) || !isset($sp['s']) || !is_string($sp['s'])) { return false; }
        if (!nb_keys_allowed($sp, $allowed)) { return false; }
        foreach (NB_SPAN_FLAGS as $flag) {
            if (array_key_exists($flag, $sp) && !is_bool($sp[$flag])) { return false; }
        }
        if (array_key_exists('href', $sp) && !nb_is_safe_href($sp['href'])) { return false; }
    }
    return true;
}

function nb_valid_image_item($im): bool {
    return is_array($im)
        && nb_keys_allowed($im, ['url', 'w', 'h'])
        && isset($im['url']) && is_string($im['url']) && preg_match(NB_IMAGE_RE, $im['url'])
        && nb_is_int_between($im['w'] ?? null, 1, 65535)
        && nb_is_int_between($im['h'] ?? null, 1, 65535);
}

function nb_valid_block($b): bool {
    if (!is_array($b) || !isset($b['t']) || !is_string($b['t'])) { return false; }
    if (!in_array($b['t'], NB_BLOCK_TYPES, true)) { return false; }
    if (!nb_keys_allowed($b, NB_BLOCK_KEYS[$b['t']])) { return false; }

    if ($b['t'] === 'p') {
        return nb_valid_spans($b['ru'] ?? null) && nb_valid_spans($b['en'] ?? null);
    }
    if ($b['t'] === 'quote') {
        return nb_valid_spans($b['ru'] ?? null) && nb_valid_spans($b['en'] ?? null)
            && array_key_exists('collapsible', $b) && is_bool($b['collapsible']);
    }
    if ($b['t'] === 'code') {
        return isset($b['ru'], $b['en']) && is_string($b['ru']) && is_string($b['en'])
            && strlen($b['ru']) <= NB_LIMIT_CODE && strlen($b['en']) <= NB_LIMIT_CODE;
    }
    if ($b['t'] === 'list') {
        if (!array_key_exists('ordered', $b) || !is_bool($b['ordered'])) { return false; }
        $items = $b['items'] ?? null;
        if (!nb_is_list($items) || count($items) === 0 || count($items) > NB_LIMIT_LIST) { return false; }
        foreach ($items as $it) {
            if (!is_array($it) || !nb_keys_allowed($it, ['ru', 'en'])) { return false; }
            if (!nb_valid_spans($it['ru'] ?? null) || !nb_valid_spans($it['en'] ?? null)) { return false; }
        }
        return true;
    }
    if ($b['t'] === 'image') {
        return nb_valid_image_item(['url' => $b['url'] ?? null, 'w' => $b['w'] ?? null, 'h' => $b['h'] ?? null])
            && nb_is_int_between($b['pct'] ?? null, 10, 100)
            && isset($b['align']) && in_array($b['align'], NB_ALIGNS, true)
            && array_key_exists('wrap', $b) && is_bool($b['wrap'])
            && nb_valid_spans($b['cap_ru'] ?? null) && nb_valid_spans($b['cap_en'] ?? null);
    }
    // album
    $items = $b['items'] ?? null;
    if (!nb_is_list($items) || count($items) < 2 || count($items) > NB_LIMIT_ALBUM) { return false; }
    foreach ($items as $im) {
        if (!nb_valid_image_item($im)) { return false; }
    }
    return nb_valid_spans($b['cap_ru'] ?? null) && nb_valid_spans($b['cap_en'] ?? null);
}

// Возвращает ['ok','error','blocks'] — той же формы, что validate_news_post()
// в api/news_save.php, чтобы вызывающий обрабатывал обе одинаково.
function news_blocks_validate($doc): array {
    if (!is_array($doc) || !nb_keys_allowed($doc, ['v', 'blocks'])) {
        return ['ok' => false, 'error' => 'bad body_json', 'blocks' => []];
    }
    if (($doc['v'] ?? null) !== NB_DOC_VERSION) {
        return ['ok' => false, 'error' => 'bad body_json version', 'blocks' => []];
    }
    $blocks = $doc['blocks'] ?? null;
    if (!nb_is_list($blocks)) {
        return ['ok' => false, 'error' => 'bad blocks', 'blocks' => []];
    }
    if (count($blocks) > NB_LIMIT_BLOCKS) {
        return ['ok' => false, 'error' => 'too many blocks', 'blocks' => []];
    }
    foreach ($blocks as $i => $b) {
        if (!nb_valid_block($b)) {
            return ['ok' => false, 'error' => 'bad block at ' . $i, 'blocks' => []];
        }
    }
    return ['ok' => true, 'error' => '', 'blocks' => $blocks];
}

// Текст спанов с откатом на второй язык — то же правило, что pickLang() в
// js/news.js и descFor() в js/content.js: наполовину переведённый пост
// показывает хоть что-то.
function nb_spans_text($primary, $fallback): string {
    $use = (is_array($primary) && count($primary)) ? $primary : (is_array($fallback) ? $fallback : []);
    $out = '';
    foreach ($use as $sp) { $out .= $sp['s']; }
    return $out;
}

function nb_block_text(array $b, string $lang): string {
    $self  = $lang === 'en' ? 'en' : 'ru';
    $other = $self === 'ru' ? 'en' : 'ru';
    if ($b['t'] === 'p' || $b['t'] === 'quote') {
        return nb_spans_text($b[$self], $b[$other]);
    }
    if ($b['t'] === 'code') {
        return $b[$self] !== '' ? $b[$self] : $b[$other];
    }
    if ($b['t'] === 'list') {
        $lines = [];
        foreach ($b['items'] as $it) { $lines[] = nb_spans_text($it[$self], $it[$other]); }
        return implode("\n", $lines);
    }
    $capSelf  = $self === 'ru' ? 'cap_ru' : 'cap_en';
    $capOther = $self === 'ru' ? 'cap_en' : 'cap_ru';
    return nb_spans_text($b[$capSelf], $b[$capOther]);
}

// Плоский текст всего поста — он уезжает в body_ru/body_en и оттуда в превью
// ссылки (api/lib/og.php) и в noscript-тело news.php.
function news_blocks_plain(array $blocks, string $lang): string {
    $parts = [];
    foreach ($blocks as $b) {
        $t = trim(nb_block_text($b, $lang));
        if ($t !== '') { $parts[] = $t; }
    }
    return implode("\n\n", $parts);
}

function news_blocks_first_image(array $blocks): ?array {
    foreach ($blocks as $b) {
        if ($b['t'] === 'image') {
            return ['url' => $b['url'], 'w' => (int)$b['w'], 'h' => (int)$b['h']];
        }
        if ($b['t'] === 'album' && count($b['items'])) {
            $im = $b['items'][0];
            return ['url' => $im['url'], 'w' => (int)$im['w'], 'h' => (int)$im['h']];
        }
    }
    return null;
}
