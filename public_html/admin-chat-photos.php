<?php
require_once __DIR__ . '/api/lib/admin_page.php';
require_once __DIR__ . '/api/lib/chat.php';
admin_page_guard('moderator');

// Жалобы на фото из чата — /admin/chat-photos. Страница печатается сервером
// целиком, без скриптов, как /admin/bans. Само фото скрыто в <details> и грузится
// только по нажатию: модератор не обязан видеть его, открыв список.

header('Content-Type: text/html; charset=utf-8');

function photos_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function photos_when(int $at): string {
    return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone(BAN_TZ))->format('d.m.Y H:i');
}

const PHOTOS_DONE = [
    'removed'    => ['is-ok', 'Фото удалено.'],
    'kept'       => ['is-ok', 'Жалоба закрыта, фото оставлено.'],
    'already'    => ['is-ok', 'Эту жалобу уже разобрали.'],
    'not_found'  => ['is-fail', 'Жалоба не найдена.'],
    'bad_action' => ['is-fail', 'Неизвестное действие.'],
];

$done = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';

$ready   = false;
$reports = [];
try {
    $pdo     = db();
    $ready   = chat_photo_ready($pdo);
    $reports = chat_photo_open_reports($pdo);
} catch (Throwable $e) {
    error_log('admin-chat-photos: ' . $e->getMessage());
}

$rows = '';
foreach ($reports as $r) {
    $id       = (int)$r['id'];
    $senderId = photos_h($r['sender']['id']);
    $sender   = photos_h($r['sender']['nick']);
    $reporter = photos_h($r['reporter']['nick']);
    $repId    = photos_h($r['reporter']['id']);
    $sent     = photos_h(photos_when($r['sentAt']));
    $at       = photos_h(photos_when($r['at']));
    $ban      = photos_h('/admin/bans?q=' . rawurlencode($r['sender']['id']));
    $rows .= <<<HTML
  <li class="bn-card">
    <div class="bn-who">
      <div class="bn-name">
        <a href="/profile?id={$senderId}" target="_blank" rel="noopener">{$sender}</a>
        <div class="bn-sub adm-muted">отправлено {$sent}</div>
        <div class="bn-sub adm-muted">жалоба {$at}, пожаловался <a href="/profile?id={$repId}" target="_blank" rel="noopener">{$reporter}</a></div>
      </div>
      <a class="adm-btn bn-ban cf-ban" href="{$ban}">К бану</a>
    </div>
    <details class="cp-photo">
      <summary class="adm-btn">Показать фото</summary>
      <img src="/api/chat_photo.php?id={$id}" alt="Фото из жалобы" loading="lazy" />
    </details>
    <form class="cp-actions" method="post" action="/api/chat_photo_review.php">
      <input type="hidden" name="id" value="{$id}" />
      <button class="adm-btn bn-ban" type="submit" name="action" value="remove">Удалить фото</button>
      <button class="adm-btn" type="submit" name="action" value="keep">Оставить</button>
    </form>
  </li>

HTML;
}
$list = $rows === ''
    ? '<p class="adm-muted bn-empty">Жалоб нет.</p>'
    : "<ul class=\"bn-list\">\n{$rows}</ul>";

$note = '';
if (array_key_exists($done, PHOTOS_DONE)) {
    [$cls, $text] = PHOTOS_DONE[$done];
    $note = "<p class=\"bn-done {$cls}\" role=\"status\">" . photos_h($text) . '</p>';
}
$notReady = $ready ? ''
    : '<p class="bn-done is-fail">Таблицы нет — выполните <code>docs/migrations/2026-11-02-chat-photos.sql</code>.</p>';

$count = count($reports);
$nav   = admin_nav('photos');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Жалобы на фото — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/ban-admin.css?v=3" />
</head>
<body class="bn-admin">
{$nav}
<main class="bn-main">
  <h1 class="bn-title">Жалобы на фото <span class="adm-muted">{$count}</span></h1>
  {$notReady}
  {$note}
  <p class="bn-note cf-hint">Здесь только фото, на которые пожаловался получатель. Удалённое фото стирается с диска сразу. Пока жалоба открыта, отправитель не может слать новые фото в этот диалог, а его фото не удаляется по сроку.</p>
  {$list}
</main>
</body>
</html>
HTML;
