<?php
// Shared shell for the admin pages (/admin, /admin/news, /admin/promo,
// /admin/support).
//
// The login used to live on the public page: a floating "Войти" button in
// index.html plus the whole editing toolbar sitting in the same markup,
// revealed by JS once /api/session.php confirmed the cookie. Visitors were
// downloading an editor they could never use, and the role was decided after
// the page had already rendered. Now the role is decided here, before a single
// byte of the editor is written to the response.

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/metrika.php';

// Admin pages are per-session and must never sit in a proxy or a bfcache.
function admin_page_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
}

// Пускает того, чья роль подходит ($need: 'admin' — вся панель, 'moderator' —
// обращения, туда же пускают и админов). Модератора, открывшего страницу
// админа, уводит к обращениям — другой панели у него нет.
//
// Всем остальным — и анониму, и вошедшему игроку без роли — панели просто нет:
// ответ тот же, что на любой несуществующий адрес. Ни кнопки входа, ни
// «нет доступа»: обычному посетителю незачем знать, что здесь что-то есть.
// Админы и модераторы входят на сайт через Roblox, как все, и попадают в
// панель из меню аватара (js/topbar.js), которое показывает ссылку только им.
function admin_page_guard(string $need = 'admin'): void {
    // Без куки прав быть не может — отказ без новой сессии, иначе каждый
    // заход на /admin оставлял бы на сервере файл сессии.
    resume_site_session();
    $role = current_role();
    if ($role === 'admin' || ($role === 'moderator' && $need === 'moderator')) {
        admin_page_headers();
        return;
    }
    if ($role === 'moderator') {
        admin_page_headers();
        header('Location: /admin/support', true, 303);
        exit;
    }
    admin_not_found();
    exit;
}

// Ровно то, что LiteSpeed на maknemy.com отдаёт на несуществующий адрес:
// тело байт в байт (два CR в нём — оттуда же), те же Cache-Control и
// Content-Type, без X-Powered-By, которого у статического ответа нет.
function admin_not_found(): void {
    http_response_code(404);
    header_remove('X-Powered-By');
    header('Cache-Control: private, no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // Иначе PHP сам допишет «; charset=UTF-8», а у хостера его нет.
    ini_set('default_charset', '');
    header('Content-Type: text/html');
    // Сначала к LF: на Windows git выписывает этот файл с CRLF.
    $html = str_replace("\r\n", "\n", ADMIN_NOT_FOUND_HTML);
    echo str_replace("Not Found\n</", "Not Found\r\n</", $html), "\n";
}

const ADMIN_NOT_FOUND_HTML = <<<'HTML'
<!DOCTYPE html>
<html style="height:100%">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<title> 404 Not Found
</title><style>@media (prefers-color-scheme:dark){body{background-color:#000!important}}</style></head>
<body style="color: #444; margin:0;font: normal 14px/20px Arial, Helvetica, sans-serif; height:100%; background-color: #fff;">
<div style="height:auto; min-height:100%; ">     <div style="text-align: center; width:800px; margin-left: -400px; position:absolute; top: 30%; left:50%;">
        <h1 style="margin:0; font-size:150px; line-height:150px; font-weight:bold;">404</h1>
<h2 style="margin-top:20px;font-size: 30px;">Not Found
</h2>
<p>The resource requested could not be found on this server!</p>
</div></div><div style="color:#f0f0f0; font-size:12px;margin:auto;padding:0px 30px 0px 30px;position:relative;clear:both;height:100px;margin-top:-101px;background-color:#474747;border-top: 1px solid rgba(0,0,0,0.15);box-shadow: 0 1px 0 rgba(255, 255, 255, 0.3) inset;">
<br>Proudly powered by LiteSpeed Web Server<p>Please be advised that LiteSpeed Technologies Inc. is not a web hosting company and, as such, has no control over content found on this site.</p></div></body></html>
HTML;

// Отдаёт РЕАЛЬНУЮ разметку публичной страницы ($file — index.php или
// news.php), а не её копию: /admin и /admin/news не хранят второй экземпляр
// вёрстки (см. комментарии в admin.php и admin-news.php), поэтому единственный
// способ получить актуальный HTML — исполнить ту же самую страницу и забрать
// то, что она печатает. file_get_contents() тут не годится в принципе: он
// вернул бы PHP-исходник, а не отрендеренный вывод.
//
// NX_ADMIN_RENDER глушит побочные эффекты паблик-страницы на время захвата:
// index.php/news.php за флагом TESTING умеют пропускать свой собственный
// header('Cache-Control: ...') и поход в БД за og:* — тот же флаг здесь не
// использован (это не тестовый прогон), поэтому у обеих страниц отдельная
// проверка на NX_ADMIN_RENDER. Без неё их Cache-Control переписал бы более
// мягкое значение поверх admin_page_headers() (no-store), а лишний запрос к
// БД ради og:title/description, которые админка всё равно не показывает,
// не нужен и не должен ронять панель, если БД в этот момент недоступна —
// хотя даже без глушения он бы не уронил: index.php/news.php сами ловят
// Throwable вокруг db() и откатываются на статичный превью.
//
// is_file() до require — принципиально: `require` на несуществующий файл
// падает необрабатываемой fatal-ошибкой (в отличие от исключения, try/catch
// её не ловит), а именно с исчезновением файла и случилась эта регрессия
// (index.html/news.html переехали в .php, но админка ещё звала старое имя).
// Возвращаем null и даём вызывающей стороне решить, что делать: сейчас обе
// админ-страницы отвечают 500 с понятным текстом вместо пустой оболочки.
function admin_render_public_page(string $file): ?string {
    if (!is_file($file)) { return null; }
    if (!defined('NX_ADMIN_RENDER')) { define('NX_ADMIN_RENDER', true); }

    ob_start();
    try {
        require_once $file;
    } catch (Throwable $e) {
        ob_end_clean();
        error_log('admin_render_public_page(' . $file . '): ' . $e->getMessage());
        return null;
    }
    $html = ob_get_clean();
    if ($html === false || trim($html) === '') { return null; }
    // Счётчик Метрики снимается здесь, а не в вызывающих admin*.php: раньше
    // он вырезался только в admin.php, и появление второй админ-страницы
    // (/admin/news) молча вернуло бы клики редактора в статистику сайта.
    // Здесь это свойство всей конструкции «админка исполняет публичную
    // страницу», а не привычка отдельного файла.
    return metrika_strip($html);
}

// Top bar shared by every panel. $active is 'tier', 'news', 'promo' or 'support'.
// Модератор видит одну вкладку — обращения: других страниц ему не открыть.
// Logout is a plain form POST, not a fetch: it has to work identically on the
// tier editor (which loads app.js) and on the ad panel (which does not).
function admin_nav(string $active): string {
    $tabs = is_admin()
        ? [
            'tier'    => ['/admin', 'Тирлист'],
            'news'    => ['/admin/news', 'Новости'],
            'promo'   => ['/admin/promo', 'Реклама'],
            'support' => ['/admin/support', 'Обращения'],
        ]
        : ['support' => ['/admin/support', 'Обращения']];
    $links = '';
    foreach ($tabs as $key => [$href, $label]) {
        $on = $key === $active ? ' is-active' : '';
        $links .= "  <a class=\"adm-nav-tab{$on}\" href=\"{$href}\">{$label}</a>\n";
    }
    return <<<HTML
<nav class="adm-nav">
  <span class="adm-nav-brand">MAKNEMY<b>ADMIN</b></span>
{$links}  <span class="adm-nav-gap"></span>
  <a class="adm-nav-out" href="/" target="_blank" rel="noopener">Сайт ↗</a>
  <form class="adm-nav-exit" method="post" action="/admin/logout">
    <button class="adm-nav-out" type="submit">Выйти</button>
  </form>
</nav>
HTML;
}
