<?php
require_once __DIR__ . '/api/lib/admin_page.php';
require_once __DIR__ . '/api/lib/support.php';
require_once __DIR__ . '/api/lib/telegram.php';
admin_page_guard('moderator');

// Список обращений со страницы /support. Печатается сервером целиком, без
// скриптов: читать и отмечать — всё, что тут нужно. Отметка — обычная форма
// на /api/support_status.php, которая возвращает сюда же.
//
// Страница модераторов: они видят её одну. Админы — тоже, плюс блок бота.
//
// Ответ автору — в личный чат сайта: ссылка «написать» открывает /chat?to=,
// и пишет туда тот же аккаунт Roblox, которым модератор вошёл в панель.

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

// Бот уведомлений. Модераторы — аккаунты сайта из moderator_ids в config.php;
// о новом обращении бот пишет тем из них, кто подключил Telegram колокольчиком
// в чате. Здесь видно, сколько подключилось, и есть кнопка установки вебхука.
// Блок только для админов: вебхук ставит api/tg_setup.php, а он требует админа.
$tg   = tg_config(app_config());
$flag = isset($_GET['tg']) && is_string($_GET['tg']) ? $_GET['tg'] : '';
if (!is_admin()) {
    $bot = '';
} elseif (!tg_enabled($tg)) {
    $bot = '<p class="adm-muted">Бот не настроен: впишите <code>tg_bot_token</code> и <code>tg_bot_name</code> в config.php.</p>';
} else {
    $linked = 0;
    if ($tg['moderators']) {
        try {
            $in = implode(',', array_fill(0, count($tg['moderators']), '?'));
            $st = db()->prepare("SELECT COUNT(*) FROM tg_links WHERE user_id IN ($in)");
            $st->execute($tg['moderators']);
            $linked = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $linked = 0;
        }
    }
    $name  = support_h('@' . $tg['name']);
    $mods  = count($tg['moderators']);
    $note  = '';
    if ($flag === 'ok')   { $note = '<p class="sp-bot-note is-ok">Вебхук подключён.</p>'; }
    if ($flag === 'fail') { $note = '<p class="sp-bot-note is-fail">Telegram не принял вебхук — проверьте токен в config.php.</p>'; }
    $bot = <<<HTML
<p>Бот {$name}. Модераторов в config.php: {$mods}, подключили Telegram: {$linked}.</p>
  <form method="post" action="/api/tg_setup.php">
    <button class="adm-btn" type="submit">Подключить вебхук</button>
  </form>
  {$note}
HTML;
}
$botBox = $bot === '' ? ''
    : "<section class=\"sp-bot\">\n  <h2 class=\"sp-bot-title\">Уведомления в Telegram</h2>\n  {$bot}\n</section>";

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
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/support-admin.css?v=2" />
</head>
<body class="sp-admin">
{$nav}
<main class="sp-main">
  <h1 class="sp-title">Центр обращений <span class="adm-muted">новых: {$fresh}</span></h1>
  {$botBox}
  {$list}
</main>
</body>
</html>
HTML;
