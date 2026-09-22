<?php
require_once __DIR__ . '/api/lib/admin_page.php';
require_once __DIR__ . '/api/lib/support.php';
admin_page_guard('Центр обращений');

// Список обращений со страницы /support. Печатается сервером целиком, без
// скриптов: читать и отмечать — всё, что тут нужно. Отметка — обычная форма
// на /api/support_status.php, которая возвращает сюда же.
//
// Ответ автору — в личный чат сайта: ссылка «написать» открывает /chat?to=.
// Для этого администратор должен быть вошедшим и через Roblox тоже — вход по
// паролю админки личности в чате не даёт.

header('Content-Type: text/html; charset=utf-8');

$tickets = [];
$ready   = false;
try {
    $pdo     = db();
    $ready   = support_ready($pdo);
    $tickets = $ready ? support_list($pdo) : [];
} catch (Throwable $e) {
    error_log('admin-support: ' . $e->getMessage());
}

function support_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$fresh = 0;
foreach ($tickets as $t) { if ($t['status'] === 'new') { $fresh++; } }

$rows = '';
foreach ($tickets as $t) {
    $when   = support_h(date('d.m.Y H:i', $t['at']));
    $nick   = support_h($t['nick']);
    $handle = support_h($t['handle']);
    $body   = nl2br(support_h($t['body']));
    $user   = support_h($t['user']);
    $done   = $t['status'] === 'done';
    $next   = $done ? 'new' : 'done';
    $label  = $done ? 'Вернуть в новые' : 'Решено';
    $cls    = $done ? ' is-done' : '';
    $rows  .= <<<HTML
  <li class="sp-ticket{$cls}">
    <div class="sp-ticket-head">
      <a class="sp-ticket-who" href="/profile?id={$user}" target="_blank" rel="noopener">{$nick} <span>{$handle}</span></a>
      <time>{$when}</time>
    </div>
    <p class="sp-ticket-body">{$body}</p>
    <div class="sp-ticket-actions">
      <a class="adm-btn" href="/chat?to={$user}" target="_blank" rel="noopener">Написать в чат</a>
      <form method="post" action="/api/support_status.php">
        <input type="hidden" name="id" value="{$t['id']}" />
        <input type="hidden" name="status" value="{$next}" />
        <button class="adm-btn" type="submit">{$label}</button>
      </form>
    </div>
  </li>

HTML;
}

if (!$ready) {
    $list = '<p class="adm-muted sp-empty">Таблицы обращений нет — выполните docs/migrations/2026-09-23-trading.sql.</p>';
} elseif ($rows === '') {
    $list = '<p class="adm-muted sp-empty">Обращений пока нет.</p>';
} else {
    $list = "<ul class=\"sp-list\">\n{$rows}</ul>";
}

$nav = admin_nav('support');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Обращения — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=3" />
<link rel="stylesheet" href="/css/support-admin.css?v=1" />
</head>
<body class="sp-admin">
{$nav}
<main class="sp-main">
  <h1 class="sp-title">Центр обращений <span class="adm-muted">новых: {$fresh}</span></h1>
  {$list}
</main>
</body>
</html>
HTML;
