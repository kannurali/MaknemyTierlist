<?php
require_once __DIR__ . '/api/lib/admin_page.php';
admin_page_guard('moderator');

// Баны — /admin/bans. Поиск игрока по нику и бан на выбранный срок, плюс
// список всех, кто забанен сейчас. Открыта модераторам и админам: кого из
// игроков каждый из них может банить, решает ban_allowed() в api/lib/ban.php.
//
// Как и /admin/support, печатается сервером целиком, без скриптов: поиск —
// GET-форма на эту же страницу, бан и разбан — POST-формы на /api/ban.php,
// который возвращает сюда же с итогом в ?done=.

header('Content-Type: text/html; charset=utf-8');

function bans_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Срок по Москве — как его пишут в config.php и как его видит владелец.
function bans_until(int $until): string {
    if ($until === 0) { return 'навсегда'; }
    $at = (new DateTimeImmutable('@' . $until))->setTimezone(new DateTimeZone(BAN_TZ));
    return 'до ' . $at->format('d.m.Y H:i') . ' МСК';
}

function bans_when(int $at): string {
    return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone(BAN_TZ))->format('d.m.Y H:i');
}

const BANS_TERM_LABELS = [
    '1d'      => '1 день',
    '3d'      => '3 дня',
    '7d'      => '7 дней',
    '30d'     => '30 дней',
    'forever' => 'навсегда',
];

const BANS_DONE = [
    'banned'       => ['is-ok', 'Игрок забанен.'],
    'unbanned'     => ['is-ok', 'Бан снят.'],
    'forbidden'    => ['is-fail', 'Этого игрока из панели не забанить: админа не банит никто, модератора — только админ, себя — нельзя.'],
    'no_user'      => ['is-fail', 'Такого игрока на сайте нет.'],
    'not_ready'    => ['is-fail', 'Таблицы банов нет — выполните docs/migrations/2026-10-10-user-bans.sql.'],
    'bad_term'     => ['is-fail', 'Выберите срок бана.'],
    'bad_id'       => ['is-fail', 'Не тот id игрока.'],
    'unauthorized' => ['is-fail', 'Нет прав.'],
];

$me    = is_string($_SESSION['user_id'] ?? null) ? $_SESSION['user_id'] : '';
$cfg   = app_config();
$now   = time();
$query = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$done  = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';

$ready  = false;
$found  = [];
$active = [];
try {
    $pdo    = db();
    $ready  = ban_ready($pdo);
    $found  = $query !== '' ? ban_search($pdo, $cfg, $me, $query, $now) : [];
    $active = ban_active($pdo, $cfg, $me, $now);
} catch (Throwable $e) {
    error_log('admin-bans: ' . $e->getMessage());
}

$qAttr = bans_h($query);
$back  = $query !== '' ? "\n      <input type=\"hidden\" name=\"q\" value=\"{$qAttr}\" />" : '';

$terms = '';
foreach (BANS_TERM_LABELS as $key => $label) {
    $sel = $key === '7d' ? ' selected' : '';
    $terms .= "<option value=\"{$key}\"{$sel}>{$label}</option>";
}

// Кнопки у игрока: забанить, снять бан или объяснение, почему нельзя.
function bans_actions(array $p, string $back, string $terms, bool $ready): string {
    $id = bans_h($p['id']);
    if ($p['config']) {
        return '<p class="bn-note">Бан стоит в config.php — снять его можно только там.</p>';
    }
    if (!$p['can']) {
        return '<p class="bn-note">Из панели не забанить.</p>';
    }
    if (!$ready) { return ''; }
    if ($p['banned']) {
        return <<<HTML
<form class="bn-form" method="post" action="/api/ban.php">
      <input type="hidden" name="id" value="{$id}" />
      <input type="hidden" name="unban" value="1" />{$back}
      <button class="adm-btn" type="submit">Снять бан</button>
    </form>
HTML;
    }
    $max = BAN_REASON_MAX;
    return <<<HTML
<form class="bn-form" method="post" action="/api/ban.php">
      <input type="hidden" name="id" value="{$id}" />{$back}
      <select class="bn-select" name="term" aria-label="Срок бана">{$terms}</select>
      <input class="bn-input" type="text" name="reason" maxlength="{$max}" placeholder="Причина (необязательно)" aria-label="Причина бана" />
      <button class="adm-btn bn-ban" type="submit">Забанить</button>
    </form>
HTML;
}

$results = '';
if ($query !== '') {
    $rows = '';
    foreach ($found as $p) {
        $id     = bans_h($p['id']);
        $nick   = bans_h($p['nick']);
        $handle = bans_h($p['handle']);
        $ava    = $p['avatar'] !== ''
            ? '<img class="bn-ava" src="' . bans_h($p['avatar']) . '" alt="" width="40" height="40" loading="lazy" />'
            : '<span class="bn-ava"></span>';
        $tags = '';
        if ($p['role'] === 'admin')     { $tags .= '<span class="bn-tag">админ</span>'; }
        if ($p['role'] === 'moderator') { $tags .= '<span class="bn-tag">модератор</span>'; }
        if ($p['banned'])               { $tags .= '<span class="bn-tag is-ban">забанен ' . bans_h(bans_until((int)$p['until'])) . '</span>'; }
        $actions = bans_actions($p, $back, $terms, $ready);
        $rows .= <<<HTML
  <li class="bn-card">
    <div class="bn-who">
      {$ava}
      <div class="bn-name">
        <a href="/profile?id={$id}" target="_blank" rel="noopener">{$nick}</a> <span class="adm-muted">{$handle}</span>
        <div class="bn-sub adm-muted">id {$id} {$tags}</div>
      </div>
      <a class="adm-btn bn-chat" href="/chat?to={$id}" target="_blank" rel="noopener">Чат</a>
    </div>
    {$actions}
  </li>

HTML;
    }
    $results = $rows === ''
        ? '<p class="adm-muted bn-empty">Никого не нашлось.</p>'
        : "<ul class=\"bn-list\">\n{$rows}</ul>";
}

$list = '';
foreach ($active as $b) {
    $id   = bans_h($b['id']);
    $nick = bans_h($b['nick']);
    $term = bans_h(bans_until($b['until']));
    $meta = [];
    if ($b['by'] !== '') {
        $meta[] = 'забанил <a href="/profile?id=' . bans_h($b['by']) . '" target="_blank" rel="noopener">' . bans_h($b['byNick']) . '</a> ' . bans_h(bans_when($b['at']));
    }
    if ($b['config']) { $meta[] = 'в config.php'; }
    if ($b['reason'] !== '') { $meta[] = 'причина: ' . bans_h($b['reason']); }
    $metaHtml = $meta ? '<div class="bn-sub adm-muted">' . implode(' · ', $meta) . '</div>' : '';
    $actions = '';
    if (!$b['config'] && $b['can'] && $ready) {
        $actions = <<<HTML
<form class="bn-form" method="post" action="/api/ban.php">
      <input type="hidden" name="id" value="{$id}" />
      <input type="hidden" name="unban" value="1" />{$back}
      <button class="adm-btn" type="submit">Снять бан</button>
    </form>
HTML;
    }
    $list .= <<<HTML
  <li class="bn-card">
    <div class="bn-who">
      <div class="bn-name">
        <a href="/profile?id={$id}" target="_blank" rel="noopener">{$nick}</a> <span class="bn-tag is-ban">{$term}</span>
        {$metaHtml}
      </div>
    </div>
    {$actions}
  </li>

HTML;
}
$activeBox = $list === ''
    ? '<p class="adm-muted bn-empty">Сейчас никто не забанен.</p>'
    : "<ul class=\"bn-list\">\n{$list}</ul>";

$note = '';
if (array_key_exists($done, BANS_DONE)) {
    [$cls, $text] = BANS_DONE[$done];
    $note = "<p class=\"bn-done {$cls}\" role=\"status\">" . bans_h($text) . '</p>';
}
$notReady = $ready ? ''
    : '<p class="bn-done is-fail">Таблицы банов нет — выполните <code>docs/migrations/2026-10-10-user-bans.sql</code>. Пока её нет, банят только через config.php.</p>';

$count = count($active);
$nav   = admin_nav('bans');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Баны — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/ban-admin.css?v=1" />
</head>
<body class="bn-admin">
{$nav}
<main class="bn-main">
  <h1 class="bn-title">Баны <span class="adm-muted">сейчас: {$count}</span></h1>
  {$notReady}
  {$note}
  <form class="bn-search" method="get" action="/admin/bans" role="search">
    <input class="bn-input" type="search" name="q" value="{$qAttr}" maxlength="64" placeholder="Ник, логин Roblox или id" aria-label="Поиск игрока" autofocus />
    <button class="adm-btn primary" type="submit">Найти</button>
  </form>
  {$results}
  <h2 class="bn-h2">Забанены сейчас</h2>
  {$activeBox}
</main>
</body>
</html>
HTML;
