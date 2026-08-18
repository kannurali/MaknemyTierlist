<?php
// Редактор тирлиста. Отдельный адрес /admin — на публичной странице ни кнопки
// входа, ни тулбара больше нет.
//
// Разметку сцены НЕ дублируем: постер, модалки и легенда живут в index.html в
// единственном экземпляре, а эта страница отдаёт тот же файл, дописав к нему
// шапку админки и флаг NX_ADMIN_PAGE. Копия index.html разъехалась бы с
// оригиналом на первой же правке вёрстки.
//
// Адрес намеренно без завершающего слэша (его снимает 301 в .htaccess):
// страница живёт на нулевой глубине, поэтому относительные пути внутри
// index.html и app.js ("assets/dot-green.png", "js/html2canvas.min.js")
// разрешаются от корня и работают без единой правки. На /admin/ они уехали бы
// в /admin/assets/ и вернули бы 404.
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard('Редактор тирлиста');

$html = @file_get_contents(__DIR__ . '/index.html');
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'index.html не читается';
    exit;
}

// Заголовок вкладки: рядом с открытым сайтом их надо различать.
$html = preg_replace('~<title>.*?</title>~su', '<title>Редактор тирлиста — MAKNEMY</title>', $html, 1);

// Индексация. Заголовок X-Robots-Tag уже ушёл из admin_page_guard(), но мета
// нужна на случай, если страницу сохранят на диск и откроют локально.
$html = preg_replace(
    '~<meta\s+name="robots"[^>]*>~i',
    '<meta name="robots" content="noindex,nofollow" />',
    $html,
    1
);

// Метрика. Свои же клики и вебвизор в статистике сайта — мусор.
$html = preg_replace(
    '~<!-- Yandex\.Metrika counter -->.*?<!-- /Yandex\.Metrika counter -->~su',
    '<!-- Метрика вырезана: админку в статистику сайта не считаем. -->',
    $html,
    1
);

$html = str_replace(
    '</head>',
    '<link rel="stylesheet" href="/css/admin-shell.css?v=1" />' . "\n</head>",
    $html
);

// Шапка — первым узлом body, над прилипающим тулбаром.
$html = preg_replace('~<body>~', "<body>\n" . admin_nav('tier'), $html, 1);

// Флаг ставим ДО app.js: его читает checkSession() на старте. Без флага
// app.js остаётся в гостевом режиме, даже когда кука админа жива, — именно
// поэтому редактировать с публичной страницы больше нельзя.
$html = str_replace(
    '<script src="js/app.js',
    "<script>window.NX_ADMIN_PAGE = true;</script>\n  <script src=\"js/app.js",
    $html
);

header('Content-Type: text/html; charset=utf-8');
echo $html;
