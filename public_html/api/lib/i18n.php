<?php
// Язык страницы и тексты интерфейса.
//
// Язык определяется по приставке адреса: /en/tierlist — английский,
// /tierlist — русский. .htaccess снимает приставку внутренним рероутом, но
// REQUEST_URI остаётся исходным, поэтому PHP видит её и после рероута.
// Тексты лежат в public_html/lang/<язык>.json — те же словари собираются в
// js/i18n/<язык>.js для скриптов (tools/i18n_build.php).
//
// PHP 7.4: без match, str_contains, str_starts_with и именованных аргументов.

const I18N_DEFAULT = 'ru';
const I18N_LANGS = ['ru', 'en'];
const I18N_OG_LOCALES = ['ru' => 'ru_RU', 'en' => 'en_US'];
const I18N_ORIGIN = 'https://maknemy.com';

function i18n_lang_from_uri(string $uri): string {
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) { return I18N_DEFAULT; }
    if (preg_match('~^/([a-z]{2})(?:/|$)~', $path, $m)
        && $m[1] !== I18N_DEFAULT && in_array($m[1], I18N_LANGS, true)) {
        return $m[1];
    }
    return I18N_DEFAULT;
}

function i18n_lang(): string {
    return i18n_lang_from_uri((string)($_SERVER['REQUEST_URI'] ?? '/'));
}

function i18n_read_json(string $path): array {
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

// Словарь языка с откатом: своя строка, иначе английская, иначе русская.
// Склейка через +=, а не array_merge: тот перенумеровал бы ключи-числа.
function i18n_dict(string $lang): array {
    static $cache = [];
    if (!in_array($lang, I18N_LANGS, true)) { $lang = I18N_DEFAULT; }
    if (!isset($cache[$lang])) {
        $out = [];
        foreach (array_unique([$lang, 'en', I18N_DEFAULT]) as $l) {
            $out += i18n_read_json(__DIR__ . '/../../lang/' . $l . '.json');
        }
        $cache[$lang] = $out;
    }
    return $cache[$lang];
}

function t(string $key, array $vars = [], ?string $lang = null): string {
    $dict = i18n_dict($lang === null ? i18n_lang() : $lang);
    $s = array_key_exists($key, $dict) ? (string)$dict[$key] : $key;
    if (!$vars) { return $s; }
    return preg_replace_callback('~\{(\w+)\}~', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $s);
}

// Текст между тегами: кавычки не трогаем, иначе русский вывод перестал бы
// совпадать с прежней разметкой байт в байт.
function te(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ta(string $key, array $vars = []): string {
    return htmlspecialchars(t($key, $vars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function u(string $path, ?string $lang = null): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    if ($lang === I18N_DEFAULT || !in_array($lang, I18N_LANGS, true)) { return $path; }
    if ($path === '/') { return '/' . $lang; }
    $second = substr($path, 1, 1);
    if ($second === '?' || $second === '#') { return '/' . $lang . substr($path, 1); }
    return '/' . $lang . $path;
}

function i18n_url(string $path, ?string $lang = null): string {
    return I18N_ORIGIN . u($path, $lang);
}

function i18n_og_locale(?string $lang = null): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    return I18N_OG_LOCALES[$lang] ?? I18N_OG_LOCALES[I18N_DEFAULT];
}

// Строки для <head> индексируемой страницы: адреса всех языковых версий,
// x-default на русскую и og:locale:alternate для остальных языков.
// Оканчивается переводом строки: в шаблоне вызов стоит отдельной строкой, а
// PHP съедает перевод строки сразу после закрывающего тега.
function i18n_alternates(string $path): string {
    $out = '';
    foreach (I18N_LANGS as $l) {
        $out .= '<link rel="alternate" hreflang="' . $l . '" href="'
            . htmlspecialchars(i18n_url($path, $l), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
    }
    $out .= '<link rel="alternate" hreflang="x-default" href="'
        . htmlspecialchars(i18n_url($path, I18N_DEFAULT), ENT_QUOTES, 'UTF-8') . '" />' . "\n";
    $self = i18n_lang();
    foreach (I18N_LANGS as $l) {
        if ($l === $self) { continue; }
        $out .= '<meta property="og:locale:alternate" content="' . I18N_OG_LOCALES[$l] . '" />' . "\n";
    }
    return $out;
}
