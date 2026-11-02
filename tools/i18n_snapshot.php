<?php
// Снимки отрисованных публичных страниц — сравнить разметку до и после
// правки шаблонов:
//   php tools/i18n_snapshot.php <папка> [язык]
//   diff -r <папка-до> <папка-после>

require __DIR__ . '/lib_render.php';

const SNAPSHOT_PAGES = [
    'home'       => ['/',                      'home.php',       []],
    'tierlist'   => ['/tierlist',              'index.php',      []],
    'calculator' => ['/calculator',            'calculator.php', []],
    'news'       => ['/news',                  'news.php',       []],
    'news-post'  => ['/news/1',                'news.php',       ['id' => '1']],
    'trading'    => ['/trading',               'trading.php',    []],
    'trade-new'  => ['/trading/new',           'trade-new.php',  []],
    'stock'      => ['/stock',                 'stock.php',      []],
    'giveaways'  => ['/giveaways',             'giveaways.php',  []],
    'profile'    => ['/profile?id=2841062255', 'profile.php',    ['id' => '2841062255']],
    'chat'       => ['/chat',                  'chat.php',       []],
    'support'    => ['/support',               'support.php',    []],
    'ads'        => ['/ads',                   'promote.php',    []],
];

$out  = $argv[1] ?? '';
$lang = $argv[2] ?? 'ru';
if ($out === '') {
    fwrite(STDERR, "usage: php tools/i18n_snapshot.php <out-dir> [lang]\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "cannot create $out\n");
    exit(2);
}
foreach (SNAPSHOT_PAGES as $name => $page) {
    [$uri, $file, $get] = $page;
    $req = $lang === 'ru' ? $uri : '/' . $lang . ($uri === '/' ? '' : $uri);
    $html = nx_render_page($file, $req, $get);
    file_put_contents("$out/$name.html", $html);
    printf("%-11s %7d bytes\n", $name, strlen($html));
}
