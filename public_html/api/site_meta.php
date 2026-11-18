<?php
// robots.txt и sitemap.xml для сайта, которому не подходят файлы из
// репозитория (они написаны под maknemy.com). Сюда запрос приводит .htaccess,
// адреса собираются из config.php этого сайта. Здесь же файл ключа IndexNow
// (/<ключ>.txt, см. api/lib/indexnow.php) — его отдаёт любой сайт.

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/indexnow.php';

function site_meta_robots(array $site): string {
    return "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /images/\nDisallow: /admin\n\n"
        . 'Sitemap: ' . $site['origin'] . "/sitemap.xml\n";
}

function site_meta_sitemap(array $site): string {
    $pages = $site['pages'] === null ? ['tierlist', 'news'] : $site['pages'];
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($pages as $page) {
        foreach (I18N_LANGS as $lang) {
            $loc = $site['origin'] . u('/' . $page, $lang);
            $out .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1, 'UTF-8') . '</loc></url>' . "\n";
        }
    }
    foreach (['/privacy', '/terms'] as $path) {
        $out .= '  <url><loc>' . htmlspecialchars($site['origin'] . $path, ENT_XML1, 'UTF-8') . '</loc></url>' . "\n";
    }
    return $out . "</urlset>\n";
}

if (!defined('TESTING')) {
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    header('Cache-Control: public, max-age=3600');
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=utf-8');
        echo site_meta_sitemap(site_cfg());
    } elseif ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=utf-8');
        echo site_meta_robots(site_cfg());
    } elseif ($path === indexnow_key_path()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo INDEXNOW_KEY;
    } else {
        http_response_code(404);
    }
}
