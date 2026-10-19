<?php
// Собирает из public_html/lang/*.json то, что сайт отдаёт статикой:
//   public_html/js/i18n/<язык>.js — словарь страницы для js/i18n.js
//     (с откатом язык → en → ru, как у t() в PHP).
// Запускать после любой правки словарей: php tools/i18n_build.php
// С --check ничего не пишет и выходит с кодом 1, если файлы устарели —
// так его зовёт tests/i18n_lib_test.php.

require __DIR__ . '/../public_html/api/lib/i18n.php';

function build_dict_js(string $lang): string {
    $json = json_encode(i18n_dict($lang), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return '(function(r){(r.I18N_DICTS=r.I18N_DICTS||{})["' . $lang . '"]=' . $json
        . ';})(typeof globalThis!=="undefined"?globalThis:this);' . "\n";
}

function build_targets(string $pub): array {
    $want = [];
    foreach (I18N_LANGS as $lang) { $want["$pub/js/i18n/$lang.js"] = build_dict_js($lang); }
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
