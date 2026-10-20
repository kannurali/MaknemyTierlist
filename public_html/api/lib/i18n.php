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
const I18N_LANGS = ['ru', 'en', 'de', 'fr', 'es', 'it', 'pt', 'pl', 'tr', 'uk'];
const I18N_OG_LOCALES = [
    'ru' => 'ru_RU', 'en' => 'en_US', 'de' => 'de_DE', 'fr' => 'fr_FR', 'es' => 'es_ES',
    'it' => 'it_IT', 'pt' => 'pt_BR', 'pl' => 'pl_PL', 'tr' => 'tr_TR', 'uk' => 'uk_UA',
];
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

// Тег словаря интерфейса для js/i18n.js. Номер версии — по содержимому
// файла: словарь меняется вместе с lang/*.json, и ручной номер легко забыть,
// а js отдаётся с кешем на год. Перевод строки в конце — вызов стоит в
// шаблоне отдельной строкой, а PHP съедает перевод строки после закрывающего
// тега.
function i18n_dict_tag(?string $lang = null, bool $absolute = false): string {
    $lang = $lang === null ? i18n_lang() : $lang;
    if (!in_array($lang, I18N_LANGS, true)) { $lang = I18N_DEFAULT; }
    $file = __DIR__ . '/../../js/i18n/' . $lang . '.js';
    $ver = is_file($file) ? substr((string)md5_file($file), 0, 10) : '0';
    return '<script src="' . ($absolute ? '/' : '') . 'js/i18n/' . $lang . '.js?v=' . $ver
        . '" fetchpriority="high"></script>' . "\n";
}

// Ключи, которые нужны только серверу: заголовки и описания страниц, JSON-LD,
// превью ссылок и тексты /ads. В словари для JS (js/i18n/*.js) они не идут —
// скриптам не нужны, а каждый язык тяжелел бы на десятки килобайт.
const I18N_SERVER_ONLY = ['meta.', 'ld.', 'og.', 'ads.'];

// Строка для подстановки внутрь JSON-литерала (JSON-LD в шаблоне): то, что
// json_encode кладёт между кавычками. JSON_HEX_TAG — чтобы перевод с «</»
// не закрыл тег script с JSON-LD раньше времени.
function tj(string $key, array $vars = []): string {
    $json = json_encode(t($key, $vars), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return substr((string)$json, 1, -1);
}

// Разметка из словаря как есть — только для ключей ads.*, где в тексте есть
// <b>, ссылки и неразрывные пробелы. Словари пишем мы сами, а набор тегов в
// каждом языке сверяет tests/i18n_test.mjs с русским.
function th(string $key, array $vars = []): string {
    return t($key, $vars);
}

// Названия языков в меню — на самих языках: человек ищет свой язык, а не
// перевод его названия на язык страницы.
const I18N_NAMES = [
    'ru' => 'Русский', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español',
    'it' => 'Italiano', 'pt' => 'Português', 'pl' => 'Polski', 'tr' => 'Türkçe', 'uk' => 'Українська',
];

// Текущий адрес без языковой приставки, с query: /en/news/92?x=1 → /news/92?x=1.
function i18n_path(): string {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH);
    $query = parse_url($uri, PHP_URL_QUERY);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $lang = i18n_lang_from_uri($path);
    if ($lang !== I18N_DEFAULT) {
        $path = (string)substr($path, strlen('/' . $lang));
        if ($path === '') { $path = '/'; }
    }
    return $path . (is_string($query) && $query !== '' ? '?' . $query : '');
}

// Меню «Язык ▾» в листе разделов шапки. Пункты — настоящие ссылки на ту же
// страницу на другом языке: работают без JS и видны поисковику. js/i18n.js
// перехватывает нажатие по data-lang-go, ставит куку выбора и переходит;
// открывает и закрывает список js/topbar.js. Атрибут не data-lang, чтобы
// прежний код страниц (он ищет #langSwitch [data-lang]) не трогал ссылки.
function i18n_lang_menu(): string {
    $self = i18n_lang();
    $path = i18n_path();
    $items = '';
    foreach (I18N_LANGS as $l) {
        $items .= '<li><a class="mk-lang-item" href="' . htmlspecialchars(u($path, $l), ENT_QUOTES, 'UTF-8')
            . '" hreflang="' . $l . '" lang="' . $l . '" data-lang-go="' . $l . '"'
            . ($l === $self ? ' aria-current="true"' : '') . '>'
            . htmlspecialchars(I18N_NAMES[$l], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return '<div class="mk-lang" id="langSwitch">'
        . '<button class="mk-lang-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="mkLangList">'
        . '<span class="mk-lang-label" data-i18n="lang.menu">' . te('lang.menu') . '</span>'
        . '<svg class="mk-lang-chev" viewBox="0 0 12 8" aria-hidden="true"><path d="M1 1.5 6 6.5 11 1.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'
        . '</button>'
        . '<ul class="mk-lang-list" id="mkLangList" hidden>' . $items . '</ul>'
        . '</div>';
}
