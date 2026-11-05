<?php
require_once __DIR__ . '/api/lib/admin_page.php';
require_once __DIR__ . '/api/lib/giveaway.php';
admin_page_guard();

// Вкладка «Каналы» — /admin/channels: что бот сам публикует в каком канале.
// Как и /admin/bans, страница печатается сервером целиком, без скриптов: форма
// уходит на /api/tg_routes.php, а тот возвращает сюда с итогом в ?done=.

header('Content-Type: text/html; charset=utf-8');

function ch_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ch_when(int $at): string {
    return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone(GW_TZ))->format('d.m H:i');
}

const CH_DONE = [
    'saved'     => ['is-ok', 'Сохранено.'],
    'not_ready' => ['is-fail', 'Не сохранено: нет таблицы — выполните docs/migrations/2026-11-05-tg-routes.sql.'],
];

// Подпись под галочкой: последний пост или почему он не вышел.
function ch_route_note(array $channel, array $route): string {
    $failed = (int)$route['failed_at'];
    if ($failed > 0) {
        $why = (string)$route['error'] !== '' ? (string)$route['error'] : 'Telegram не ответил';
        return '<small class="ch-fail">' . ch_h(ch_when($failed)) . ' не вышел: ' . ch_h($why) . '</small>';
    }
    $posted = (int)$route['posted_at'];
    if ($posted <= 0) { return ''; }
    $when = ch_h(ch_when($posted));
    $url  = gw_post_url($channel, (int)$route['msg_id']);
    if ($url === '') { return "<small class=\"adm-muted\">пост {$when}</small>"; }
    return '<small class="adm-muted"><a href="' . ch_h($url) . "\" target=\"_blank\" rel=\"noopener\">пост {$when} ↗</a></small>";
}

$done = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';
$cfg  = app_config();
$tg   = tg_config($cfg);

$ready    = false;
$channels = [];
$routes   = [];
try {
    $pdo      = db();
    $ready    = tg_routes_ready($pdo);
    $routes   = tg_routes_all($pdo);
    $channels = gw_channels($pdo);
} catch (Throwable $e) {
    error_log('admin-channels: ' . $e->getMessage());
}
uasort($channels, function (array $a, array $b): int {
    return strcmp(mb_strtolower((string)$a['title']), mb_strtolower((string)$b['title']));
});

$cards = '';
foreach ($channels as $id => $c) {
    $title = ch_h((string)$c['title'] !== '' ? (string)$c['title'] : (string)$id);
    $url   = gw_channel_url($c);
    $name  = $url !== '' ? '<a href="' . ch_h($url) . "\" target=\"_blank\" rel=\"noopener\">{$title}</a>" : "<b>{$title}</b>";
    $kind  = (string)$c['kind'] === 'channel' ? 'канал' : 'чат';
    $user  = (string)$c['username'] !== '' ? '<div class="bn-sub adm-muted">@' . ch_h((string)$c['username']) . '</div>' : '';
    $warn  = tg_route_warning($c);
    $warn  = $warn !== '' ? '<p class="ch-warn">' . ch_h($warn) . '</p>' : '';
    $boxes = '';
    foreach (TG_ROUTE_TOPICS as $topic => $label) {
        $route   = $routes[$id][$topic] ?? null;
        $checked = $route ? ' checked' : '';
        $note    = $route ? ch_route_note($c, $route) : '';
        $boxes  .= <<<HTML
        <label class="ch-topic">
          <span class="ch-topic-row"><input type="checkbox" name="r[{$id}][{$topic}]" value="1"{$checked} /> {$label}</span>
          {$note}
        </label>

HTML;
    }
    $cards .= <<<HTML
    <li class="bn-card">
      <div class="bn-name">{$name} <span class="bn-tag">{$kind}</span>{$user}</div>
      {$warn}
      <div class="ch-topics">
{$boxes}      </div>
    </li>

HTML;
}

$list = $cards === ''
    ? '<p class="adm-muted bn-empty">Бот пока не знает ни одного канала.</p>'
    : <<<HTML
<form method="post" action="/api/tg_routes.php">
    <ul class="bn-list">
{$cards}    </ul>
    <div class="ch-save"><button class="adm-btn primary" type="submit">Сохранить</button></div>
  </form>
HTML;

$note = '';
if (array_key_exists($done, CH_DONE)) {
    [$cls, $text] = CH_DONE[$done];
    $note = "<p class=\"bn-done {$cls}\" role=\"status\">" . ch_h($text) . '</p>';
}
$notReady = $ready ? ''
    : '<p class="bn-done is-fail">Таблицы нет — выполните <code>docs/migrations/2026-11-05-tg-routes.sql</code>. До этого посты в каналы не выходят.</p>';
$botOff = tg_enabled($tg) ? ''
    : '<p class="bn-done is-fail">Бот выключен: в config.php нет токена. Галочки сохранятся, но посты не выйдут.</p>';

$addUrl  = tg_add_channel_url($tg['name']);
$addLink = $addUrl !== '' ? '<a href="' . ch_h($addUrl) . '" target="_blank" rel="noopener">Добавить бота в канал ↗</a> ' : '';

$nav = admin_nav('channels');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Каналы — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/ban-admin.css?v=3" />
<link rel="stylesheet" href="/css/channels-admin.css?v=1" />
</head>
<body class="bn-admin">
{$nav}
<main class="bn-main">
  <h1 class="bn-title">Каналы</h1>
  {$notReady}
  {$botOff}
  {$note}
  <p class="bn-note">Что бот сам публикует в каждом канале. Посты такие же, как уведомления в личке, на русском. Ничего не отмечено — канал молчит.</p>
  <ul class="ch-help">
    <li><b>Тирлист</b> — сменилась цена. Сохранения в течение 30 минут правят один пост.</li>
    <li><b>Сток</b> — каждая смена, обычная и Mirage, все фрукты.</li>
    <li><b>Новости</b> — новый пост.</li>
    <li><b>Розыгрыши</b> — галочка отмечает канал в новом розыгрыше, там её можно снять. Пост и итоги выходят, как раньше.</li>
    <li><b>Ивенты</b> — старт и итоги, в течение часа.</li>
  </ul>
  {$list}
  <p class="bn-note ch-add">{$addLink}Канала нет в списке, а бот там уже админ? Поменяйте ему любое право: тогда Telegram сообщит о канале.</p>
</main>
</body>
</html>
HTML;
