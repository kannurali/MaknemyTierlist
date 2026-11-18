<?php
// IndexNow (indexnow.org): Яндекс, Bing и остальные участники протокола
// узнают о новых и изменённых адресах сразу, не дожидаясь обхода. Нужно
// прежде всего молодому домену вроде voxseas.fun, к которому робот сам
// заходит редко.
//
// Ключ не секрет: протокол требует отдавать его всем по адресу /<ключ>.txt
// (это делает api/site_meta.php), им сайт лишь подтверждает, что список
// адресов прислал владелец домена. Один ключ на все сайты этого кода —
// каждый отдаёт его на своём домене. Отправка — tools/indexnow.php.
const INDEXNOW_KEY = '7b8fc2e02df6d8802d8237255cb33db0';

function indexnow_key_path(): string {
    return '/' . INDEXNOW_KEY . '.txt';
}

// Адреса из sitemap.xml в том виде, в каком их надо отправить.
function indexnow_sitemap_urls(string $xml): array {
    preg_match_all('~<loc>\s*([^<]+?)\s*</loc>~', $xml, $m);
    return array_map(function (string $s): string {
        return html_entity_decode($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }, $m[1]);
}

// Тело POST на https://<поисковик>/indexnow. Пакет, где есть хоть один
// адрес чужого хоста, протокол отвергает целиком, поэтому такие адреса
// отсеиваются здесь, а повторы схлопываются.
function indexnow_payload(string $origin, array $urls): array {
    $origin = rtrim($origin, '/');
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $own = [];
    foreach ($urls as $url) {
        if (!is_string($url) || strtolower((string)parse_url($url, PHP_URL_HOST)) !== $host) { continue; }
        $own[$url] = true;
    }
    return [
        'host'        => $host,
        'key'         => INDEXNOW_KEY,
        'keyLocation' => $origin . indexnow_key_path(),
        'urlList'     => array_keys($own),
    ];
}
