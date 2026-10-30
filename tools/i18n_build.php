<?php
// Собирает из public_html/lang/*.json то, что сайт отдаёт статикой:
//   public_html/js/i18n/<язык>.js — словарь страницы для js/i18n.js
//     (с откатом язык → en → ru, как у t() в PHP; без серверных ключей
//     I18N_SERVER_ONLY);
//   public_html/sitemap.xml        — разделы на всех языках с hreflang.
// Запускать после любой правки словарей: php tools/i18n_build.php
// С --check ничего не пишет и выходит с кодом 1, если файлы устарели —
// так его зовёт tests/i18n_lib_test.php.

require __DIR__ . '/../public_html/api/lib/i18n.php';

// Разделы с языковыми версиями: путь, changefreq, priority.
const SITEMAP_PAGES = [
    ['/',           'daily',   '1.0'],
    ['/tierlist',   'daily',   '0.9'],
    ['/news',       'daily',   '0.8'],
    ['/calculator', 'weekly',  '0.7'],
    ['/trading',    'daily',   '0.7'],
    ['/stock',      'hourly',  '0.7'],
    ['/giveaways',  'daily',   '0.6'],
    ['/halloween',  'daily',   '0.6'],
    ['/ads',        'monthly', '0.4'],
];
// Правовые страницы двуязычные на одном адресе — языковых версий у них нет.
const SITEMAP_SINGLE = [
    ['/privacy', 'yearly',  '0.2'],
    ['/terms',   'yearly',  '0.2'],
];

function build_dict_js(string $lang): string {
    $dict = [];
    foreach (i18n_dict($lang) as $key => $value) {
        foreach (I18N_SERVER_ONLY as $prefix) {
            if (strpos($key, $prefix) === 0) { continue 2; }
        }
        $dict[$key] = $value;
    }
    $json = json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '(function(r){(r.I18N_DICTS=r.I18N_DICTS||{})["' . $lang . '"]=' . $json
        . ';})(typeof globalThis!=="undefined"?globalThis:this);' . "\n";
}

function build_sitemap(): string {
    $x = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
       . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n";
    foreach (SITEMAP_PAGES as [$path, $freq, $prio]) {
        $alts = '';
        foreach (I18N_LANGS as $l) {
            $alts .= '    <xhtml:link rel="alternate" hreflang="' . $l . '" href="' . i18n_url($path, $l) . "\" />\n";
        }
        $alts .= '    <xhtml:link rel="alternate" hreflang="x-default" href="' . i18n_url($path, I18N_DEFAULT) . "\" />\n";
        foreach (I18N_LANGS as $l) {
            $x .= "  <url>\n    <loc>" . i18n_url($path, $l) . "</loc>\n" . $alts
                . "    <changefreq>$freq</changefreq>\n    <priority>$prio</priority>\n  </url>\n";
        }
    }
    foreach (SITEMAP_SINGLE as [$path, $freq, $prio]) {
        $x .= "  <url>\n    <loc>" . i18n_url($path, I18N_DEFAULT) . "</loc>\n"
            . "    <changefreq>$freq</changefreq>\n    <priority>$prio</priority>\n  </url>\n";
    }
    return $x . "</urlset>\n";
}

function build_targets(string $pub): array {
    $want = [];
    foreach (I18N_LANGS as $lang) { $want["$pub/js/i18n/$lang.js"] = build_dict_js($lang); }
    $want["$pub/sitemap.xml"] = build_sitemap();
    return $want;
}

$pub = dirname(__DIR__) . '/public_html';
$check = in_array('--check', $argv, true);
$stale = [];
foreach (build_targets($pub) as $path => $content) {
    $have = is_file($path) ? str_replace("\r\n", "\n", (string)file_get_contents($path)) : null;
    if ($have === $content) { continue; }
    $stale[] = substr($path, strlen($pub) + 1);
    if (!$check) {
        if (!is_dir(dirname($path))) { mkdir(dirname($path), 0777, true); }
        file_put_contents($path, $content);
    }
}
foreach ($stale as $p) { echo ($check ? 'устарел: ' : 'записан: ') . $p . "\n"; }
exit($check && $stale ? 1 : 0);
