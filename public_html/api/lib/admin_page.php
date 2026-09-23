<?php
// Shared shell for the admin pages (/admin and /admin/promo).
//
// The login used to live on the public page: a floating "Войти" button in
// index.html plus the whole editing toolbar sitting in the same markup,
// revealed by JS once /api/session.php confirmed the cookie. Visitors were
// downloading an editor they could never use, and the role was decided after
// the page had already rendered. Now the role is decided here, before a single
// byte of the editor is written to the response.

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/metrika.php';
require_once __DIR__ . '/roblox_oauth.php';

// Admin pages are per-session and must never sit in a proxy or a bfcache.
function admin_page_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
}

// Пускает того, чья роль подходит ($need: 'admin' — вся панель, 'moderator' —
// обращения, туда же пускают и админов), остальным отдаёт экран входа и
// заканчивает запрос. Модератора, открывшего страницу админа, уводит к
// обращениям — другой панели у него нет.
//
// Экран входа отвечает 200, а не 401/403: без WWW-Authenticate 401 — кривой
// ответ, а часть хостингов подменяет тело ошибок своим ErrorDocument, и вместо
// экрана человек увидел бы стандартную страницу хостера.
function admin_page_guard(string $title, string $need = 'admin'): void {
    // Без куки прав быть не может — экран отдаётся без новой сессии, иначе
    // каждый заход на /admin оставлял бы на сервере файл сессии.
    resume_site_session();
    admin_page_headers();
    $role = current_role();
    if ($role === 'admin' || ($role === 'moderator' && $need === 'moderator')) {
        // Возврат с Roblox приходит с меткой ?login=ok. Страницы панели, кроме
        // тирлиста, js/topbar.js не грузят, и снять метку из адреса некому.
        if (isset($_GET['login'])) {
            header('Location: ' . admin_return_path(), true, 303);
            exit;
        }
        return;
    }
    if ($role === 'moderator') {
        header('Location: /admin/support', true, 303);
        exit;
    }
    admin_login_page($title, (string)($_SESSION['user_id'] ?? ''));
    exit;
}

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

// Куда вернуть человека после входа через Roblox: на ту же страницу панели,
// без параметров (в них могла остаться метка прошлой неудачной попытки).
function admin_return_path(): string {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return roblox_safe_return(is_string($path) && $path !== '' ? $path : '/admin');
}

// Экран вместо панели. Пароля нет: кнопка ведёт на вход через Roblox
// (api/roblox_start.php), и возврат приходит обратно сюда же, где страж
// сверяет Roblox id со списками в config.php.
//
// $uid — кто уже вошёл на сайт, но прав не имеет. Ему экран показывает его
// Roblox id: именно это число владелец вписывает в admin_ids или
// moderator_ids, а узнать его иначе человеку негде.
function admin_login_page(string $title, string $uid): void {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    if ($uid !== '') {
        $id   = htmlspecialchars($uid, ENT_QUOTES, 'UTF-8');
        $body = <<<HTML
  <h1>Нет доступа</h1>
  <p class="adm-muted">Этот аккаунт Roblox не админ и не модератор сайта.</p>
  <p class="adm-muted">Roblox ID: <b class="adm-gate-id">{$id}</b>. Доступ выдаёт владелец сайта — вписывает этот номер в config.php.</p>
  <form method="post" action="/admin/logout">
    <button class="adm-btn" type="submit">Выйти из аккаунта</button>
  </form>
  <a class="adm-btn" href="/">На сайт</a>
HTML;
    } elseif (!roblox_oauth_enabled(app_config())) {
        $body = <<<HTML
  <h1>{$t}</h1>
  <p class="adm-err">Вход через Roblox не настроен: в config.php нет ключей приложения Roblox.</p>
HTML;
    } else {
        $flags = [
            'cancelled' => 'Вход отменён.',
            'expired'   => 'Вход занял слишком много времени — попробуйте ещё раз.',
            'error'     => 'Не удалось войти — попробуйте ещё раз.',
        ];
        $flag = isset($_GET['login']) && is_string($_GET['login']) ? $_GET['login'] : '';
        $err  = isset($flags[$flag]) ? "\n  <p class=\"adm-err\">{$flags[$flag]}</p>" : '';
        $href = htmlspecialchars('/api/roblox_start.php?return=' . rawurlencode(admin_return_path()), ENT_QUOTES, 'UTF-8');
        $body = <<<HTML
  <h1>{$t}</h1>
  <p class="adm-muted">Панель открывается аккаунтом Roblox администратора или модератора.</p>{$err}
  <a class="adm-btn primary" href="{$href}">Войти через Roblox</a>
HTML;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Вход — {$t}</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
</head>
<body class="adm-gate-body">
<main class="adm-gate">
  <div class="adm-gate-brand">MAKNEMY<b>ADMIN</b></div>
{$body}
</main>
</body>
</html>
HTML;
}
