<?php
// Сообщить поисковикам по протоколу IndexNow (Яндекс, Bing, Seznam, Naver,
// Yep) обо всех адресах из sitemap.xml сайта. Запускать после деплоя, когда
// на сайте появились новые страницы или сильно сменилось содержимое; часто
// слать одно и то же не надо — поисковики режут квоту за повторы.
//
//   php tools/indexnow.php https://voxseas.fun          отправить
//   php tools/indexnow.php https://voxseas.fun --dry    только показать список
//
// Перед отправкой проверяет, что сайт уже отдаёт файл ключа: без него
// поисковик молча выкинет весь пакет (ключ — api/lib/indexnow.php, файл
// отдаёт api/site_meta.php, значит, ветка с ними должна быть на бою).

require __DIR__ . '/../public_html/api/lib/indexnow.php';

$origin = rtrim((string)($argv[1] ?? ''), '/');
$dry = in_array('--dry', $argv, true);
if (!preg_match('~^https://[a-z0-9.-]+$~i', $origin)) {
    fwrite(STDERR, "usage: php tools/indexnow.php https://<домен> [--dry]\n");
    exit(2);
}

function indexnow_http(string $method, string $url, ?string $json = null): array {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_USERAGENT      => 'indexnow-submit/1',
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = $json;
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

[$code, $key, $err] = indexnow_http('GET', $origin . indexnow_key_path());
if ($code !== 200 || trim($key) !== INDEXNOW_KEY) {
    fwrite(STDERR, 'файл ключа не отдаётся: ' . $origin . indexnow_key_path() . " → $code $err\n");
    exit(1);
}

[$code, $xml, $err] = indexnow_http('GET', $origin . '/sitemap.xml');
if ($code !== 200) {
    fwrite(STDERR, "sitemap.xml → $code $err\n");
    exit(1);
}

$payload = indexnow_payload($origin, indexnow_sitemap_urls($xml));
echo count($payload['urlList']) . " адресов для {$payload['host']}\n";
if (!$payload['urlList']) { exit(1); }
if ($dry) {
    echo implode("\n", $payload['urlList']) . "\n";
    exit(0);
}

// Участники протокола пересылают пакет друг другу, но Яндекс и общий узел
// получают его напрямую: так не зависим от того, дошла ли пересылка.
$fail = false;
foreach (['https://yandex.com/indexnow', 'https://api.indexnow.org/indexnow'] as $endpoint) {
    [$code, $body, $err] = indexnow_http('POST', $endpoint, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    // 200 — принято, 202 — принято, ключ ещё проверяется.
    $ok = $code === 200 || $code === 202;
    $fail = $fail || !$ok;
    echo $endpoint . ' → ' . $code . ($err !== '' ? " $err" : '') . ($ok ? '' : ' ' . trim(substr($body, 0, 300))) . "\n";
}
exit($fail ? 1 : 0);
