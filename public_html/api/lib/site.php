<?php
// Какой это сайт. Один и тот же код публикуется в несколько каталогов
// (см. deploy_targets() в api/deploy.php), у каждого свой config.php уровнем
// выше веб-корня: своя база, свои картинки, свой набор страниц.
//
// Ключ 'site' в config.php:
//   'site' => [
//       'origin' => 'https://voxseas.fun',   // канонический адрес, без слэша
//       'name'   => 'Vox Seas Tier List',    // og:site_name, заголовки
//       'skin'   => 'vx',                    // шаблоны из public_html/vx/
//       'pages'  => ['tierlist', 'news'],    // всё прочее отвечает 404
//   ],
// Ключа нет — это основной сайт: все страницы, прежний вид, maknemy.com.

const SITE_DEFAULT_ORIGIN = 'https://maknemy.com';
const SITE_DEFAULT_NAME   = 'Maknemy Tier List';
const SITE_SKINS = ['vx'];

// Что открыто на сайте с ограниченным набором страниц. Путь — от веб-корня.
// Скрипт, которого нет ни здесь, ни в SITE_CORE_SCRIPTS, на таком сайте
// отвечает 404: чат, трейдинг, розыгрыши и бот там не существуют, хотя их
// файлы лежат рядом — код у сайтов общий.
const SITE_PAGE_SCRIPTS = [
    'tierlist' => [
        'index.php', 'admin.php',
        'api/state.php', 'api/tierlist.php', 'api/save.php', 'api/upload.php', 'api/like.php', 'api/promo.php',
    ],
    'news' => [
        'news.php', 'admin-news.php',
        'api/news.php', 'api/news_save.php', 'api/news_delete.php', 'api/news_like.php', 'api/upload.php', 'api/promo.php',
    ],
];

// Вход, сессия, деплой, служебные файлы и юридические страницы (их требует
// Roblox у OAuth-приложения) нужны любому сайту.
const SITE_CORE_SCRIPTS = [
    'admin-logout.php',
    'api/session.php', 'api/login.php', 'api/logout.php',
    'api/roblox_start.php', 'api/roblox_callback.php',
    'api/deploy.php', 'api/site_meta.php',
    'privacy.php', 'terms.php',
];

function site_normalize(array $raw): array {
    $origin = rtrim((string)($raw['origin'] ?? ''), '/');
    if (!preg_match('~^https?://[a-z0-9.-]+(:[0-9]+)?$~i', $origin)) { $origin = SITE_DEFAULT_ORIGIN; }
    $name = trim((string)($raw['name'] ?? ''));
    $skin = (string)($raw['skin'] ?? '');
    $pages = null;
    if (isset($raw['pages']) && is_array($raw['pages'])) {
        $pages = array_values(array_intersect(array_keys(SITE_PAGE_SCRIPTS), array_map('strval', $raw['pages'])));
    }
    return [
        'origin' => $origin,
        'name'   => $name !== '' ? $name : SITE_DEFAULT_NAME,
        'skin'   => in_array($skin, SITE_SKINS, true) ? $skin : null,
        'pages'  => $pages,
    ];
}

function site_cfg(): array {
    static $site = null;
    // Тесты подставляют сайт через $GLOBALS['__SITE__'] и не кешируются.
    if (isset($GLOBALS['__SITE__']) && is_array($GLOBALS['__SITE__'])) {
        return site_normalize($GLOBALS['__SITE__']);
    }
    if ($site === null) {
        $raw = [];
        // Без config.php (юнит-тесты, поломанная установка) это основной сайт.
        if (defined('CONFIG_PATH') && function_exists('app_config') && is_file(CONFIG_PATH)) {
            $cfg = app_config();
            if (isset($cfg['site']) && is_array($cfg['site'])) { $raw = $cfg['site']; }
        }
        $site = site_normalize($raw);
    }
    return $site;
}

function site_origin(): string { return site_cfg()['origin']; }
function site_name(): string   { return site_cfg()['name']; }
function site_skin(): ?string  { return site_cfg()['skin']; }

// Есть ли на этом сайте раздел. У основного сайта есть всё.
function site_has(string $page): bool {
    $pages = site_cfg()['pages'];
    return $pages === null || in_array($page, $pages, true);
}

// Адрес первой страницы сайта: туда уходит «/» там, где главной нет.
function site_first_path(array $site): string {
    $first = $site['pages'][0] ?? 'tierlist';
    return '/' . $first;
}

// Решение по одному запросу. Чистая функция — её и проверяют тесты.
//   ['allow' => true]                      пропустить
//   ['allow' => false, 'redirect' => '/x'] отправить на первую страницу
//   ['allow' => false]                     404
function site_gate_decision(string $script, array $site): array {
    if ($site['pages'] === null) { return ['allow' => true]; }
    $script = ltrim(str_replace('\\', '/', $script), '/');
    if (in_array($script, SITE_CORE_SCRIPTS, true)) { return ['allow' => true]; }
    foreach ($site['pages'] as $page) {
        if (in_array($script, SITE_PAGE_SCRIPTS[$page] ?? [], true)) { return ['allow' => true]; }
    }
    if ($script === 'home.php') { return ['allow' => false, 'redirect' => site_first_path($site)]; }
    return ['allow' => false];
}

// Путь исполняемого скрипта от веб-корня: "index.php", "api/state.php".
function site_script_path(array $server): string {
    $root = str_replace('\\', '/', (string)realpath(__DIR__ . '/../..'));
    $file = str_replace('\\', '/', (string)realpath((string)($server['SCRIPT_FILENAME'] ?? '')));
    if ($root === '' || $file === '' || strpos($file, $root . '/') !== 0) { return ''; }
    return substr($file, strlen($root) + 1);
}

// Вызывается из _bootstrap.php при подключении, то есть раньше любого кода
// страницы или эндпоинта. В тестах и из командной строки (cron, tools/) не
// работает: там нет запроса, который можно было бы не пустить.
function site_gate(): void {
    if (defined('TESTING') || PHP_SAPI === 'cli') { return; }
    $site = site_cfg();
    if ($site['pages'] === null) { return; }
    $verdict = site_gate_decision(site_script_path($_SERVER), $site);
    if ($verdict['allow']) { return; }
    if (isset($verdict['redirect'])) {
        // Постоянный: главной на таком сайте нет, и поисковик должен склеить
        // корень домена с первой страницей. На 302 Яндекс держал бы в выдаче
        // пустой «/», а вес ссылок на корень не доставался бы тирлисту. Адрес
        // зависит только от языка в самом URL (u()), так что кеш браузера
        // запомнит верную цель.
        header('Location: ' . u($verdict['redirect']), true, 301);
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>404 Not Found</title></head>'
        . '<body><h1>404 Not Found</h1></body></html>';
    exit;
}
