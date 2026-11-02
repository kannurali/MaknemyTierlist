<?php
require_once __DIR__ . '/api/lib/admin_page.php';
require_once __DIR__ . '/api/lib/chat_filter.php';
admin_page_guard('moderator');

// Фильтр чата — /admin/chat-filter. Журнал срабатываний открыт модераторам: по
// нему они решают, кого банить. Список слов правят только админы. Как и
// /admin/bans, страница печатается сервером целиком, без скриптов: формы уходят
// на /api/chat_filter.php, а тот возвращает сюда с итогом в ?done=.

header('Content-Type: text/html; charset=utf-8');

function filter_h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function filter_when(int $at): string {
    return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone(BAN_TZ))->format('d.m.Y H:i');
}

const FILTER_DONE = [
    'added'     => ['is-ok', 'Слова добавлены.'],
    'removed'   => ['is-ok', 'Слово убрано.'],
    'bad_word'  => ['is-fail', 'Не добавлено: нужно слово от 2 символов без пробелов, «*» только по краям.'],
    'too_many'  => ['is-fail', 'Не добавлено: в списке уже слишком много слов.'],
    'not_ready' => ['is-fail', 'Таблиц фильтра нет — выполните docs/migrations/2026-11-02-chat-filter.sql.'],
];

const FILTER_KIND_LABELS = ['link' => 'ссылка', 'word' => 'слово'];

$done  = isset($_GET['done']) && is_string($_GET['done']) ? $_GET['done'] : '';
$admin = is_admin();

$ready = false;
$words = [];
$hits  = [];
try {
    $pdo   = db();
    $ready = chat_filter_ready($pdo);
    $words = chat_filter_word_list($pdo);
    $hits  = chat_filter_hits($pdo);
} catch (Throwable $e) {
    error_log('admin-chat-filter: ' . $e->getMessage());
}

$chips = '';
foreach ($words as $word) {
    $w = filter_h((string)$word);
    $x = $admin ? <<<HTML
<form class="cf-chip-x" method="post" action="/api/chat_filter.php">
      <input type="hidden" name="remove" value="{$w}" />
      <button type="submit" aria-label="Убрать {$w}">×</button>
    </form>
HTML : '';
    $chips .= "  <li class=\"cf-chip\">{$w}{$x}</li>\n";
}
$wordsBox = $chips === ''
    ? '<p class="adm-muted bn-empty">Список пуст: ссылки режутся и без него.</p>'
    : "<ul class=\"cf-chips\">\n{$chips}</ul>";

$addForm = '';
if ($admin && $ready) {
    $addForm = <<<HTML
<form class="bn-form cf-add" method="post" action="/api/chat_filter.php">
    <input class="bn-input" type="text" name="word" maxlength="2000" required placeholder="Слова через запятую" aria-label="Новые слова" />
    <button class="adm-btn primary" type="submit">Добавить</button>
  </form>
  <p class="bn-note cf-hint"><code>слово</code> — только целиком, <code>слово*</code> — начало, <code>*слово</code> — конец, <code>*слово*</code> — любая часть. Заглавные, латиница вместо кириллицы, «суууука» и «с у к а» ловятся сами. Новое слово сразу прячет его и в старых сообщениях.</p>
HTML;
}

$rows = '';
foreach ($hits as $h) {
    $id     = filter_h($h['id']);
    $nick   = filter_h($h['nick'] !== '' ? $h['nick'] : 'id ' . $h['id']);
    $handle = $h['handle'] !== '' ? '<span class="adm-muted">@' . filter_h($h['handle']) . '</span>' : '';
    $when   = filter_h(filter_when($h['at']));
    $tags   = '';
    foreach ($h['kinds'] as $kind) {
        if (isset(FILTER_KIND_LABELS[$kind])) {
            $tags .= '<span class="bn-tag">' . FILTER_KIND_LABELS[$kind] . '</span>';
        }
    }
    $body = filter_h($h['body']);
    $ban  = filter_h('/admin/bans?q=' . rawurlencode($h['id']));
    $rows .= <<<HTML
  <li class="bn-card">
    <div class="bn-who">
      <div class="bn-name">
        <a href="/profile?id={$id}" target="_blank" rel="noopener">{$nick}</a> {$handle} {$tags}
        <div class="bn-sub adm-muted">{$when}</div>
      </div>
      <a class="adm-btn bn-ban cf-ban" href="{$ban}">К бану</a>
    </div>
    <p class="cf-text">{$body}</p>
  </li>

HTML;
}
$hitsBox = $rows === ''
    ? '<p class="adm-muted bn-empty">Пока ничего не сработало.</p>'
    : "<ul class=\"bn-list\">\n{$rows}</ul>";

$note = '';
if (array_key_exists($done, FILTER_DONE)) {
    [$cls, $text] = FILTER_DONE[$done];
    $note = "<p class=\"bn-done {$cls}\" role=\"status\">" . filter_h($text) . '</p>';
}
$notReady = $ready ? ''
    : '<p class="bn-done is-fail">Таблиц фильтра нет — выполните <code>docs/migrations/2026-11-02-chat-filter.sql</code>. Ссылки режутся и без неё, а слова и журнал появятся после.</p>';

$count = count($words);
$nav   = admin_nav('filter');
echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="robots" content="noindex,nofollow" />
<title>Фильтр чата — панель управления</title>
<link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48" />
<link rel="stylesheet" href="/css/admin-shell.css?v=4" />
<link rel="stylesheet" href="/css/ban-admin.css?v=2" />
</head>
<body class="bn-admin">
{$nav}
<main class="bn-main">
  <h1 class="bn-title">Фильтр чата</h1>
  {$notReady}
  {$note}
  <p class="bn-note">Все ссылки в чате заменяются на «[ссылка удалена]». Исключение одно — maknemy.com: ссылки на Roblox тоже режутся, через них ловят на фишинг. В базе остаётся исходный текст.</p>
  <h2 class="bn-h2">Слова <span class="adm-muted">{$count}</span></h2>
  {$addForm}
  {$wordsBox}
  <h2 class="bn-h2">Что сработало</h2>
  <p class="bn-note cf-hint">Исходный текст сообщений, последние 100 за 90 дней. Собеседник видел его уже без ссылки и без слова.</p>
  {$hitsBox}
</main>
</body>
</html>
HTML;
