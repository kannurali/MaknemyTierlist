<?php
// Фильтры сообщений и триггеры: что ловить и что делать с нарушителем.
// Сам, без команды модератора, бот трогает только ссылки и спамеров
// (CM_DEFAULTS в db.php). Поиск ссылок и нормализация слов — из фильтра
// сайта (api/lib/chat_filter.php); его поведение здесь не меняется.

const CM_FILTERS = ['спамеры' => 'spam', 'ссылки' => 'links', 'пересылки' => 'forwards', 'мат' => 'mat',
    'флуд' => 'flood', 'капс' => 'caps', 'стикеры' => 'stickers', 'гифки' => 'gifs', 'голосовые' => 'voice'];
const CM_TRIGGER_NAMES = CM_FILTERS + ['варны' => 'warns'];
// Срабатывает первый подошедший, действия разных фильтров не складываются.
// «спамеры» проверяются раньше всех, ещё до команд (router.php).
const CM_FILTER_ORDER = ['links', 'forwards', 'mat', 'flood', 'caps', 'stickers', 'gifs', 'voice'];
const CM_WORDS_MAX   = 200;
const CM_DOMAINS_MAX = 50;

function cm_text_of(array $msg): string {
    return (string)($msg['text'] ?? $msg['caption'] ?? '');
}

function cm_entities_of(array $msg): array {
    return (array)($msg['entities'] ?? $msg['caption_entities'] ?? []);
}

// --------------------------------------------------------------------------
//  Ссылки
// --------------------------------------------------------------------------

// Адрес кандидата: без схемы, «www , evil» → «www.evil», до первого «:/?#\» или пробела.
function cm_url_host(string $token): string {
    $t = (string)preg_replace('~^[a-z][a-z0-9+.\-]*:(?://)?~i', '', trim($token));
    $t = (string)preg_replace('~^www\s*[.,]\s*~i', 'www.', $t);
    return strtolower((string)preg_replace('~[:/?#\\\\\s].*\z~su', '', $t));
}

// maknemy.com разрешён всегда; домен чата — вместе с поддоменами.
function cm_domain_allowed(string $host, array $domains): bool {
    $host = rtrim(strtolower($host), '.');
    if (strncmp($host, 'www.', 4) === 0) { $host = substr($host, 4); }
    if ($host === '') { return false; }
    foreach (array_merge(['maknemy.com'], $domains) as $d) {
        $d = (string)$d;
        if ($d !== '' && ($host === $d || substr($host, -strlen($d) - 1) === '.' . $d)) { return true; }
    }
    return false;
}

function cm_domain_norm(string $raw): string {
    $h = cm_url_host(mb_strtolower(trim($raw)));
    if (strncmp($h, 'www.', 4) === 0) { $h = substr($h, 4); }
    return preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+\z~', $h) ? $h : '';
}

// Есть ли ссылка, кроме разрешённых: ссылки под текстом (text_link) и всё,
// что ищет фильтр сайта, включая обходы вроде «evil . ru». Регулярка не
// справилась с текстом — считаем, что ссылка есть: лучше удалить, чем пропустить.
function cm_has_link(array $msg, array $domains): bool {
    foreach (cm_entities_of($msg) as $e) {
        if (is_array($e) && ($e['type'] ?? '') === 'text_link'
            && !cm_domain_allowed(cm_url_host((string)($e['url'] ?? '')), $domains)) {
            return true;
        }
    }
    $text = cm_text_of($msg);
    if ($text === '') { return false; }
    $folded = chat_filter_fold($text);
    if ($folded === null) { return true; }
    foreach (chat_filter_link_steps() as $step) {
        [$re, $isHost] = $step;
        if (!preg_match_all($re, $folded, $mm)) { continue; }
        foreach ($mm[0] as $tok) {
            $host = cm_url_host((string)$tok);
            if ($isHost && !chat_filter_is_host($host)) { continue; }
            if (cm_domain_allowed($host, $domains)) { continue; }
            return true;
        }
    }
    return false;
}

// --------------------------------------------------------------------------
//  Мат
// --------------------------------------------------------------------------

function cm_mat_rules(PDO $pdo, int $chatId): array {
    $st = $pdo->prepare('SELECT word FROM cm_words WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    return chat_filter_compile(array_merge(CM_MAT_WORDS, $st->fetchAll(PDO::FETCH_COLUMN)));
}

function cm_mat_except(): array {
    static $keys = null;
    if ($keys === null) { $keys = array_map('chat_filter_word_key', CM_MAT_EXCEPT); }
    return $keys;
}

function cm_mat_token_hit(string $tok, array $rules): bool {
    $key = chat_filter_word_key($tok);
    $flat = (string)preg_replace('/(.)\1+/u', '$1', $key);
    foreach (cm_mat_except() as $x) {
        if (strpos($key, $x) !== false || strpos($flat, $x) !== false) { return false; }
    }
    return chat_filter_word_hit($tok, $rules);
}

function cm_has_mat(string $text, array $rules): bool {
    $f = chat_filter_fold($text);
    if ($f === null || $f === '') { return false; }
    if (preg_match_all('/[\p{L}\p{N}@$]+/u', $f, $m)) {
        foreach ($m[0] as $tok) {
            if (cm_mat_token_hit((string)$tok, $rules)) { return true; }
        }
    }
    // Вразрядку: «с у к а», «с.у.к.а».
    if (preg_match_all('/(?<![\p{L}\p{N}])\p{L}(?:[\s.\-_*,]+\p{L}(?![\p{L}\p{N}])){2,}/u', $f, $m)) {
        foreach ($m[0] as $sp) {
            if (cm_mat_token_hit((string)preg_replace('/[\s.\-_*,]+/u', '', (string)$sp), $rules)) { return true; }
        }
    }
    return false;
}

// --------------------------------------------------------------------------
//  Флуд, капс, пересылки
// --------------------------------------------------------------------------

// Текущее сообщение к этому моменту уже записано в cm_msgs (router.php).
function cm_is_flood(PDO $pdo, int $chatId, int $userId, string $text, array $flood, int $now): bool {
    $n = max(2, (int)($flood[0] ?? 5));
    $secs = max(2, (int)($flood[1] ?? 10));
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_msgs WHERE chat_id = :c AND user_id = :u AND at > :s');
    $st->execute([':c' => $chatId, ':u' => $userId, ':s' => $now - $secs]);
    if ((int)$st->fetchColumn() >= $n) { return true; }
    $h = cm_text_hash($text);
    if ($h === '') { return false; }
    $st = $pdo->prepare('SELECT text_hash FROM cm_msgs WHERE chat_id = :c AND user_id = :u ORDER BY message_id DESC LIMIT 3');
    $st->execute([':c' => $chatId, ':u' => $userId]);
    $last = $st->fetchAll(PDO::FETCH_COLUMN);
    return count($last) === 3 && count(array_unique($last)) === 1 && (string)$last[0] === $h;
}

function cm_is_caps(string $text): bool {
    $letters = (int)preg_match_all('/\p{L}/u', $text);
    if ($letters < 10) { return false; }
    return (int)preg_match_all('/\p{Lu}/u', $text) / $letters >= 0.7;
}

// Пересланное из канала или чужой группы. Автопересылка из привязанного
// канала — не пересылка.
function cm_is_forward(array $msg): bool {
    if (!empty($msg['is_automatic_forward'])) { return false; }
    $o = $msg['forward_origin'] ?? null;
    if (is_array($o)) { return in_array((string)($o['type'] ?? ''), ['channel', 'chat'], true); }
    return isset($msg['forward_from_chat']);
}

// --------------------------------------------------------------------------
//  Проверка и действия
// --------------------------------------------------------------------------

function cm_filter_hit(array $ctx, array $msg): string {
    $f = $ctx['chat']['settings']['filters'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $text = cm_text_of($msg);
    foreach (CM_FILTER_ORDER as $k) {
        if (empty($f[$k])) { continue; }
        if ($k === 'links' && cm_has_link($msg, (array)$ctx['chat']['settings']['domains'])) { return $k; }
        if ($k === 'forwards' && cm_is_forward($msg)) { return $k; }
        if ($k === 'mat' && $text !== '' && cm_has_mat($text, cm_mat_rules($ctx['pdo'], $chatId))) { return $k; }
        if ($k === 'flood' && empty($ctx['edited'])
            && cm_is_flood($ctx['pdo'], $chatId, (int)($msg['from']['id'] ?? 0), $text, (array)$ctx['chat']['settings']['flood'], (int)$ctx['now'])) {
            return $k;
        }
        if ($k === 'caps' && cm_is_caps($text)) { return $k; }
        if ($k === 'stickers' && isset($msg['sticker'])) { return $k; }
        if ($k === 'gifs' && isset($msg['animation'])) { return $k; }
        if ($k === 'voice' && (isset($msg['voice']) || isset($msg['video_note']))) { return $k; }
    }
    return '';
}

// Действия триггера к автору сообщения контекста. Только удаление — молча:
// в живом чате иначе пойдёт поток извещений.
function cm_filter_act(array $ctx, string $key): void {
    $msg = $ctx['msg'];
    $from = is_array($msg['from'] ?? null) ? $msg['from'] : [];
    $name = (string)array_search($key, CM_FILTERS, true);
    $done = cm_apply_actions($ctx, (int)($from['id'] ?? 0), (int)($msg['message_id'] ?? 0),
        cm_trigger_actions($ctx['chat'], $key), cm_t('reason_filter', ['filter' => $name]), 0);
    if ($done === '') { return; }
    cm_say($ctx, (int)$ctx['chat']['chat_id'],
        cm_t('filter_notice', ['name' => cm_who_from($from), 'filter' => $name, 'done' => $done]), ['temp' => true]);
}

// --------------------------------------------------------------------------
//  Команды
// --------------------------------------------------------------------------

// По ссылке: обработчик дальше читает уже новые настройки.
function cm_save(array &$ctx, array $settings): void {
    cm_settings_save($ctx['pdo'], $ctx['chat'], $settings, (int)$ctx['now']);
}

function cm_cmd_filter_set(array $ctx, array $cmd): void {
    $a = cm_norm(trim($cmd['args']));
    $usage = cm_t('filter_usage', ['names' => implode(', ', array_keys(CM_FILTERS))]);
    if (!preg_match('~^(\S+)\s+(?:(вкл|выкл)|(\d{1,2})\s+(\d{1,3}))$~u', $a, $m) || !isset(CM_FILTERS[$m[1]])) {
        cm_reply($ctx, $usage);
        return;
    }
    $key = CM_FILTERS[$m[1]];
    $s = $ctx['chat']['settings'];
    if (isset($m[3]) && $m[3] !== '') {
        $n = (int)$m[3];
        $sec = (int)$m[4];
        if ($key !== 'flood' || $n < 2 || $n > 30 || $sec < 2 || $sec > 120) {
            cm_reply($ctx, $usage);
            return;
        }
        $s['flood'] = [$n, $sec];
        $s['filters']['flood'] = true;
        cm_save($ctx, $s);
        cm_reply($ctx, cm_t('flood_done', ['n' => $n, 's' => $sec]), false);
        return;
    }
    $on = $m[2] === 'вкл';
    $s['filters'][$key] = $on;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('filter_done', ['name' => $m[1], 'state' => cm_t($on ? 'state_on' : 'state_off')]), false);
}

function cm_cmd_filter_list(array $ctx, array $cmd): void {
    $s = $ctx['chat']['settings'];
    $lines = [];
    foreach (CM_FILTERS as $name => $key) {
        if (empty($s['filters'][$key])) {
            $lines[] = '▫️ ' . $name;
            continue;
        }
        $line = '✅ ' . $name . ' → ' . cm_actions_text(cm_trigger_actions($ctx['chat'], $key));
        if ($key === 'flood') { $line .= ' (' . (int)$s['flood'][0] . ' за ' . (int)$s['flood'][1] . ' с)'; }
        $lines[] = $line;
    }
    cm_reply($ctx, cm_t('filters_head') . "\n" . implode("\n", $lines), false);
}

function cm_cmd_word_add(array $ctx, array $cmd): void {
    $w = mb_strtolower(trim($cmd['args']));
    if (!preg_match(CHAT_FILTER_WORD_RE, $w)) {
        cm_reply($ctx, cm_t('word_usage'));
        return;
    }
    $pdo = $ctx['pdo'];
    $chatId = (int)$ctx['chat']['chat_id'];
    $st = $pdo->prepare('SELECT COUNT(*) FROM cm_words WHERE chat_id = :c');
    $st->execute([':c' => $chatId]);
    if ((int)$st->fetchColumn() >= CM_WORDS_MAX) {
        cm_reply($ctx, cm_t('words_full', ['max' => CM_WORDS_MAX]));
        return;
    }
    try {
        $pdo->prepare('INSERT INTO cm_words (chat_id, word) VALUES (:c, :w)')->execute([':c' => $chatId, ':w' => $w]);
    } catch (PDOException $e) {
        cm_reply($ctx, cm_t('word_exists', ['word' => $w]));
        return;
    }
    cm_reply($ctx, cm_t('word_added', ['word' => $w]), false);
}

function cm_cmd_word_del(array $ctx, array $cmd): void {
    $w = mb_strtolower(trim($cmd['args']));
    $st = $ctx['pdo']->prepare('DELETE FROM cm_words WHERE chat_id = :c AND word = :w');
    $st->execute([':c' => $ctx['chat']['chat_id'], ':w' => $w]);
    cm_reply($ctx, cm_t($st->rowCount() > 0 ? 'word_removed' : 'word_missing', ['word' => $w]), $st->rowCount() === 0);
}

function cm_cmd_word_list(array $ctx, array $cmd): void {
    $st = $ctx['pdo']->prepare('SELECT word FROM cm_words WHERE chat_id = :c ORDER BY word');
    $st->execute([':c' => $ctx['chat']['chat_id']]);
    $words = $st->fetchAll(PDO::FETCH_COLUMN);
    cm_reply($ctx, $words ? cm_t('words_head') . "\n" . implode(', ', $words) : cm_t('words_empty'), false);
}

function cm_cmd_domain_add(array $ctx, array $cmd): void {
    $d = cm_domain_norm($cmd['args']);
    if ($d === '') {
        cm_reply($ctx, cm_t('domain_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    if (in_array($d, $s['domains'], true)) {
        cm_reply($ctx, cm_t('domain_exists', ['domain' => $d]));
        return;
    }
    if (count($s['domains']) >= CM_DOMAINS_MAX) {
        cm_reply($ctx, cm_t('domains_full', ['max' => CM_DOMAINS_MAX]));
        return;
    }
    $s['domains'][] = $d;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('domain_added', ['domain' => $d]), false);
}

function cm_cmd_domain_del(array $ctx, array $cmd): void {
    $d = cm_domain_norm($cmd['args']);
    $s = $ctx['chat']['settings'];
    if ($d === '' || !in_array($d, $s['domains'], true)) {
        cm_reply($ctx, cm_t('domain_missing', ['domain' => $d !== '' ? $d : trim($cmd['args'])]));
        return;
    }
    $s['domains'] = array_values(array_diff($s['domains'], [$d]));
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('domain_removed', ['domain' => $d]), false);
}

function cm_cmd_domain_list(array $ctx, array $cmd): void {
    $d = $ctx['chat']['settings']['domains'];
    cm_reply($ctx, $d ? cm_t('domains_head') . "\n" . implode("\n", $d) : cm_t('domains_empty'), false);
}

function cm_cmd_autodelete(array $ctx, array $cmd): void {
    $a = cm_norm(trim($cmd['args']));
    if ($a !== 'вкл' && $a !== 'выкл') {
        cm_reply($ctx, cm_t('autodelete_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['autodelete'] = $a === 'вкл';
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('autodelete_done', ['state' => cm_t($a === 'вкл' ? 'state_on' : 'state_off')]), false);
}

function cm_cmd_trigger_set(array $ctx, array $cmd): void {
    $acts = null;
    $name = '';
    if (preg_match('~^(\S+?)\s*:?\s+(.+)$~su', trim($cmd['args']), $m)) {
        $name = cm_norm(rtrim($m[1], ':'));
        $key = CM_TRIGGER_NAMES[$name] ?? '';
        $acts = $key === '' ? null : cm_parse_actions($m[2], $key === 'warns');
    }
    if ($acts === null) {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    $s['triggers'][CM_TRIGGER_NAMES[$name]] = $acts;
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('trigger_done', ['name' => $name, 'actions' => cm_actions_text($acts)]), false);
}

function cm_cmd_trigger_clear(array $ctx, array $cmd): void {
    $name = cm_norm(trim($cmd['args'], " \t:"));
    $key = CM_TRIGGER_NAMES[$name] ?? '';
    if ($key === '') {
        cm_reply($ctx, cm_t('trigger_usage'));
        return;
    }
    $s = $ctx['chat']['settings'];
    unset($s['triggers'][$key]);
    cm_save($ctx, $s);
    cm_reply($ctx, cm_t('trigger_reset', ['name' => $name, 'actions' => cm_actions_text(cm_trigger_actions($ctx['chat'], $key))]), false);
}

function cm_cmd_trigger_list(array $ctx, array $cmd): void {
    $lines = [];
    foreach (CM_TRIGGER_NAMES as $name => $key) {
        $own = !empty($ctx['chat']['settings']['triggers'][$key]);
        $lines[] = $name . ' → ' . cm_actions_text(cm_trigger_actions($ctx['chat'], $key)) . ($own ? '' : cm_t('trigger_default'));
    }
    cm_reply($ctx, cm_t('triggers_head') . "\n" . implode("\n", $lines), false);
}
