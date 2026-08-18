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

// Admin pages are per-session and must never sit in a proxy or a bfcache.
function admin_page_headers(): void {
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Robots-Tag: noindex, nofollow');
}

// Lets an administrator through; hands anyone else the login form and ends the
// request. Deliberately answers 200 rather than 401: a 401 without a
// WWW-Authenticate header is malformed, and some shared hosts swap the body
// for their own ErrorDocument, which would replace the form with a stock page.
function admin_page_guard(string $title): void {
    start_admin_session();
    admin_page_headers();
    if (is_admin()) { return; }
    admin_login_page($title);
    exit;
}

// Top bar shared by every panel. $active is 'tier', 'news' or 'promo'.
// Logout is a plain form POST, not a fetch: it has to work identically on the
// tier editor (which loads app.js) and on the ad panel (which does not).
function admin_nav(string $active): string {
    $tier  = $active === 'tier'  ? ' is-active' : '';
    $news  = $active === 'news'  ? ' is-active' : '';
    $promo = $active === 'promo' ? ' is-active' : '';
    return <<<HTML
<nav class="adm-nav">
  <span class="adm-nav-brand">MAKNEMY<b>ADMIN</b></span>
  <a class="adm-nav-tab{$tier}" href="/admin">Тирлист</a>
  <a class="adm-nav-tab{$news}" href="/admin/news">Новости</a>
  <a class="adm-nav-tab{$promo}" href="/admin/promo">Реклама</a>
  <span class="adm-nav-gap"></span>
  <a class="adm-nav-out" href="/" target="_blank" rel="noopener">Сайт ↗</a>
  <form class="adm-nav-exit" method="post" action="/admin/logout">
    <button class="adm-nav-out" type="submit">Выйти</button>
  </form>
</nav>
HTML;
}

// Standalone login page. The password goes to /api/login.php — the same
// endpoint as before, so the lockout after five misses still applies — and on
// success we simply reload: the guard above then renders the real page.
function admin_login_page(string $title): void {
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
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
<link rel="stylesheet" href="/css/admin-shell.css?v=1" />
</head>
<body class="adm-gate-body">
<form class="adm-gate" id="gateForm" autocomplete="on">
  <div class="adm-gate-brand">MAKNEMY<b>ADMIN</b></div>
  <h1>{$t}</h1>
  <p class="adm-muted">Введите пароль администратора.</p>
  <input type="password" id="gatePass" autocomplete="current-password" placeholder="Пароль" autofocus />
  <button class="adm-btn primary" type="submit" id="gateGo">Войти</button>
  <div class="adm-err" id="gateErr" hidden></div>
</form>
<script>
(function () {
  var form = document.getElementById("gateForm");
  var pass = document.getElementById("gatePass");
  var go   = document.getElementById("gateGo");
  var err  = document.getElementById("gateErr");
  function fail(msg) { err.hidden = false; err.textContent = msg; go.disabled = false; pass.select(); }
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    err.hidden = true;
    go.disabled = true;
    fetch("/api/login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ password: pass.value })
    })
      .then(function (r) { return r.json().catch(function () { return {}; })
        .then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (res) {
        if (res.j && res.j.error === "too_many_attempts") {
          fail("Слишком много попыток. Подождите " + (res.j.retry_after || 300) + " с.");
          return;
        }
        if (res.status !== 200 || !res.j || !res.j.ok) { fail("Неверный пароль."); return; }
        // Перезагрузка, а не показ панели из JS: разметку редактора отдаёт
        // сервер, и до входа её в этой вкладке просто нет.
        location.reload();
      })
      .catch(function () { fail("Сервер недоступен. Панель работает только там, где отвечает PHP."); });
  });
})();
</script>
</body>
</html>
HTML;
}
