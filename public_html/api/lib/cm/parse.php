<?php
// Разбор команд чат-менеджера: префикс, слово команды, цель, срок, причина.

// фраза => [обработчик, раздел для «дк» или null, ранг по умолчанию,
//           exact — без префикса только ровно команда,
//           target — нужна цель: без префикса и без цели это не команда].
// Фразы в нижнем регистре и с «е» вместо «ё» (cm_norm).
const CM_COMMANDS = [
    '+модер'             => ['rank_set', 'ранги', 4, false, true],
    'повысить'           => ['rank_up', 'ранги', 4, false, true],
    'понизить'           => ['rank_down', 'ранги', 4, false, true],
    '-модер'             => ['rank_clear', 'ранги', 4, false, true],
    'снять модера'       => ['rank_clear', 'ранги', 4, false, true],
    'модеры'             => ['rank_list', null, 0, true, false],
    'журнал рангов'      => ['rank_log', 'ранги', 4, false, false],
    'передать создателя' => ['owner_pass', null, 5, false, true],
    'вернуть создателя'  => ['owner_restore', null, 0, true, false],
    'дк'                 => ['access', null, 5, false, false],
    'варн'               => ['warn', 'варны', 1, false, true],
    '-варн'              => ['unwarn', 'варны', 1, false, true],
    'варны'              => ['warns', 'варны', 1, false, false],
    'снять варны'        => ['unwarn_all', 'варны', 1, false, true],
    'варны лимит'        => ['warn_limit', 'триггеры', 3, false, false],
    'варны срок'         => ['warn_ttl', 'триггеры', 3, false, false],
    'варны наказание'    => ['warn_action', 'триггеры', 3, false, false],
    'мут'                => ['mute', 'мут', 1, false, true],
    'размут'             => ['unmute', 'мут', 1, false, true],
    'муты'               => ['mutes', 'мут', 1, false, false],
    'кик'                => ['kick', 'кик', 2, false, true],
    'бан'                => ['ban', 'бан', 3, false, true],
    'разбан'             => ['unban', 'бан', 3, false, true],
    'баны'               => ['bans', 'бан', 3, false, false],
    'фильтр'             => ['filter_set', 'фильтры', 3, false, false],
    'фильтры'            => ['filter_list', null, 0, true, false],
    '+слово'             => ['word_add', 'фильтры', 3, false, false],
    '-слово'             => ['word_del', 'фильтры', 3, false, false],
    'слова'              => ['word_list', 'фильтры', 3, false, false],
    '+домен'             => ['domain_add', 'фильтры', 3, false, false],
    '-домен'             => ['domain_del', 'фильтры', 3, false, false],
    'домены'             => ['domain_list', 'фильтры', 3, false, false],
    'автоудаление'       => ['autodelete', 'фильтры', 3, false, false],
    'триггер'            => ['trigger_set', 'триггеры', 3, false, false],
    '-триггер'           => ['trigger_clear', 'триггеры', 3, false, false],
    'триггеры'           => ['trigger_list', 'триггеры', 3, false, false],
    '+приветствие'       => ['welcome_set', 'приветствие', 3, false, false],
    '-приветствие'       => ['welcome_del', 'приветствие', 3, false, false],
    'приветствие'        => ['welcome_show', 'приветствие', 3, false, false],
    'сброс приветствий'  => ['greet_reset', 'приветствие', 3, false, false],
    '+правила'           => ['rules_set', 'правила', 3, false, false],
    '-правила'           => ['rules_del', 'правила', 3, false, false],
    'правила'            => ['rules_show', null, 0, true, false],
    'репорт'             => ['report', null, 0, false, false],
    'репорты'            => ['report_list', 'репорты', 2, false, false],
    'репорты в чат'      => ['report_code', 'репорты', 4, false, false],
    'репорты сюда'       => ['report_bind', null, 0, false, false],
    'репорты здесь'      => ['report_home', 'репорты', 4, false, false],
    '+спамер'            => ['spam_add', null, 0, false, true],
    '-спамер'            => ['spam_del', null, 0, false, true],
    'проверить'          => ['spam_check', null, 0, false, true],
    'чистка'             => ['purge', 'чистка', 3, false, false],
    'кик удаленных'      => ['kick_deleted', 'чистка', 3, false, false],
    'кик неактивных'     => ['kick_inactive', 'чистка', 3, false, false],
    'кик новых'          => ['kick_new', 'чистка', 3, false, false],
    '+заметка'           => ['note_add', 'заметки', 2, false, false],
    '-заметка'           => ['note_del', 'заметки', 2, false, false],
    'заметки'            => ['note_list', null, 0, true, false],
    'таймер'             => ['timer_add', 'таймеры', 1, false, false],
    'таймеры'            => ['timer_list', 'таймеры', 1, false, false],
    '-таймер'            => ['timer_del', 'таймеры', 1, false, false],
    'стата'              => ['stats_top', 'статистика', 0, false, false],
    'моя стата'          => ['stats_me', 'статистика', 0, false, false],
    '+реп'               => ['rep_plus', 'репутация', 0, false, true],
    '-реп'               => ['rep_minus', 'репутация', 0, false, true],
    'репа'               => ['rep_show', 'репутация', 0, false, false],
    'топ репы'           => ['rep_top', 'репутация', 0, true, false],
    '+награда'           => ['award_add', 'награды', 3, false, true],
    '-награда'           => ['award_del', 'награды', 3, false, false],
    'награды'            => ['awards_show', null, 0, false, false],
    'профиль'            => ['profile', null, 0, false, false],
    'кто ты'             => ['profile_of', null, 0, false, true],
    '+пол'               => ['profile_set', 'анкета', 0, false, false],
    '+город'             => ['profile_set', 'анкета', 0, false, false],
    '+др'                => ['profile_set', 'анкета', 0, false, false],
    '+о себе'            => ['profile_set', 'анкета', 0, false, false],
    '-пол'               => ['profile_clear', 'анкета', 0, true, false],
    '-город'             => ['profile_clear', 'анкета', 0, true, false],
    '-др'                => ['profile_clear', 'анкета', 0, true, false],
    '-о себе'            => ['profile_clear', 'анкета', 0, true, false],
    'рандом'             => ['rand', 'игры', 0, false, false],
    'монетка'            => ['coin', 'игры', 0, true, false],
    'выбери'             => ['choose', 'игры', 0, false, false],
    'инфа'               => ['chance', 'игры', 0, false, false],
    'шанс'               => ['chance', 'игры', 0, false, false],
    'шар'                => ['ball', 'игры', 0, false, false],
    'кто'                => ['who', 'игры', 0, false, false],
    'обнять'             => ['rp', 'рп', 0, false, true],
    'поцеловать'         => ['rp', 'рп', 0, false, true],
    'погладить'          => ['rp', 'рп', 0, false, true],
    'ударить'            => ['rp', 'рп', 0, false, true],
    'укусить'            => ['rp', 'рп', 0, false, true],
    'пнуть'              => ['rp', 'рп', 0, false, true],
    'пожать руку'        => ['rp', 'рп', 0, false, true],
    'похвалить'          => ['rp', 'рп', 0, false, true],
    'поздравить'         => ['rp', 'рп', 0, false, true],
    'накормить'          => ['rp', 'рп', 0, false, true],
    'напоить'            => ['rp', 'рп', 0, false, true],
    'пощекотать'         => ['rp', 'рп', 0, false, true],
    'ущипнуть'           => ['rp', 'рп', 0, false, true],
    'убить'              => ['rp', 'рп', 0, false, true],
    'кубы'               => ['dice', 'игры', 0, false, false],
    'кубы исход'         => ['dice_outcome', 'триггеры', 3, false, false],
    'дуэль'              => ['duel', 'игры', 0, false, true],
    'дуэли'              => ['duel_top', 'игры', 0, true, false],
    'дуэли исход'        => ['duel_outcome', 'триггеры', 3, false, false],
    'встречаться'        => ['couple_ask', 'отношения', 0, false, true],
    'расстаться'         => ['couple_end', 'отношения', 0, true, false],
    'пара'               => ['couple_show', 'отношения', 0, false, false],
    'пары'               => ['couple_top', 'отношения', 0, true, false],
    'брак'               => ['marry', 'отношения', 0, false, false],
    'развод'             => ['divorce', 'отношения', 0, true, false],
    'вернуть брак'       => ['remarry', 'отношения', 0, true, false],
    'браки'              => ['marry_top', 'отношения', 0, true, false],
    '+клан'              => ['clan_add', 'кланы', 0, false, false],
    '-клан'              => ['clan_del', 'кланы', 0, true, false],
    'клан'               => ['clan_show', 'кланы', 0, false, false],
    'кланы'              => ['clan_top', 'кланы', 0, true, false],
    'вступить'           => ['clan_join', 'кланы', 0, false, false],
    'покинуть клан'      => ['clan_leave', 'кланы', 0, true, false],
    'пригласить'         => ['clan_invite', 'кланы', 0, false, true],
    'исключить'          => ['clan_kick', 'кланы', 0, false, true],
    'клан открыт'        => ['clan_open', 'кланы', 0, true, false],
    'клан закрыт'        => ['clan_open', 'кланы', 0, true, false],
    'клан описание'      => ['clan_about', 'кланы', 0, false, false],
    'созвать клан'       => ['clan_call', 'кланы', 0, true, false],
    'помощь'             => ['help', null, 0, true, false],
    'команды'            => ['help', null, 0, true, false],
    'кто я'              => ['whoami', null, 0, true, false],
    'id'                 => ['id', null, 0, true, false],
    'ид'                 => ['id', null, 0, true, false],
];

const CM_PREFIXES = ['!', '.', '/'];

// Команды этапа 2, у которых аргументы есть по смыслу, а цель необязательна.
// Без префикса такая команда — только если второй строки нет, а остаток
// первой без цели (cm_target) целиком подходит под шаблон (после cm_norm).
// Иначе это обычная речь: «стата норм», «награды раздали».
const CM_BARE_ARGS = [
    'stats_top'   => '~^(?:(?:[0-9]{1,2}|день|неделя|месяц|все)(?: +|$)){0,2}$~u',
    'stats_me'    => '~^$~',
    'rep_show'    => '~^$~',
    'awards_show' => '~^$~',
    'profile'     => '~^$~',
    'profile_of'  => '~^$~',
    'rand'        => '~^(?:-?[0-9]{1,10}(?: +-?[0-9]{1,10})?)?$~',
    'choose'      => '~\sили\s|,~u',
    'chance'      => '~\S~u',
    'ball'        => '~\S~u',
    'rp'          => '~^$~',
    'dice'        => '~^$~',
    'duel'        => '~^$~',
    'couple_ask'  => '~^$~',
    'couple_show' => '~^$~',
    'marry'       => '~^$~',
    'clan_show'   => '~^$~',
    'clan_join'   => '~^[\p{L}\p{N}][\p{L}\p{N} -]{1,40}$~u',
    'clan_invite' => '~^$~',
    'clan_kick'   => '~^$~',
];

// Команды только с префиксом: без него это слово обычной речи («кто знает…»).
// Проверяет первая строка cm_run_command (router.php).
const CM_PREFIX_ONLY = ['who'];

function cm_bare_ok(array $ctx, array $cmd): bool {
    $re = CM_BARE_ARGS[$cmd['handler']] ?? null;
    if ($re === null) { return true; }
    if (trim($cmd['body']) !== '') { return false; }
    $a = $cmd['args'];
    cm_target($ctx['pdo'], $ctx['msg'], $a);
    return preg_match($re, cm_norm(trim($a))) === 1;
}

function cm_norm(string $s): string {
    return str_replace('ё', 'е', mb_strtolower($s));
}

// Длинные фразы раньше коротких: «варны лимит» прежде «варны», «кик новых»
// прежде «кик».
function cm_phrases(): array {
    static $p = null;
    if ($p === null) {
        $p = array_keys(CM_COMMANDS);
        usort($p, function ($a, $b) { return strlen($b) - strlen($a); });
    }
    return $p;
}

/**
 * Команда из текста сообщения или null. args — остаток первой строки
 * (исходный регистр), body — всё со второй строки как есть: от него
 * отсчитывается разметка заметок и правил (cm_body_entities).
 */
function cm_parse(string $text, string $botName): ?array {
    $nl    = strpos($text, "\n");
    $first = $nl === false ? $text : substr($text, 0, $nl);
    $body  = $nl === false ? '' : substr($text, $nl + 1);
    $line  = trim($first);
    if ($line === '') { return null; }
    $prefixed = in_array($line[0], CM_PREFIXES, true);
    if ($prefixed) { $line = ltrim(substr($line, 1)); }
    // /бан@ИмяБота — чужому боту не отвечаем.
    if ($prefixed && preg_match('~^([^\s@]+)@(\w+)(.*)\z~su', $line, $m)) {
        if ($botName === '' || strcasecmp($m[2], $botName) !== 0) { return null; }
        $line = $m[1] . $m[3];
    }
    // Длина в байтах у $norm и $line в начале строки совпадает: в фразах
    // только кириллица, латиница и «+-», а их нижний регистр той же длины.
    $norm = cm_norm($line);
    foreach (cm_phrases() as $phrase) {
        $len = strlen($phrase);
        if (strncmp($norm, $phrase, $len) !== 0) { continue; }
        $tail = substr($norm, $len);
        if ($tail !== '' && !preg_match('~^[\s:]~u', $tail)) { continue; }
        [$handler, $section, $level, $exact, $target] = CM_COMMANDS[$phrase];
        return ['phrase' => $phrase, 'handler' => $handler, 'section' => $section, 'level' => $level,
                'exact' => $exact, 'target' => $target, 'prefixed' => $prefixed,
                'args' => trim(substr($line, $len)), 'body' => $body];
    }
    return null;
}

// --------------------------------------------------------------------------
//  Сроки
// --------------------------------------------------------------------------

// [регулярка единицы, секунд]. Внутри — длинные формы раньше коротких.
const CM_UNITS = [
    ['секунд[аы]?|секунду|сек|с', 1],
    ['минут[аыу]?|мин|м', 60],
    ['часа|часов|час|ч', 3600],
    ['дней|дня|день|дн|д', 86400],
    ['недел[июья]|нед|н', 604800],
    ['месяцев|месяца|месяц|мес', 2592000],
    ['года|год|лет|г', 31536000],
];
// Telegram считает срок меньше 30 секунд или больше 366 дней бессрочным.
const CM_MIN_TERM = 30;
const CM_MAX_TERM = 31622400;

/**
 * Срок в начале строки; съедается из $rest. null — срока нет, 0 — «навсегда»,
 * -1 — срок есть, но вне пределов Telegram. Голое число — минуты. Единица без
 * числа — только словом от трёх букв («час», «неделя»), иначе «м» в начале
 * причины считалось бы минутой.
 */
function cm_take_term(string &$rest): ?int {
    $s = ltrim($rest);
    $n = cm_norm($s);
    if (preg_match('~^навсегда(?![\p{L}\p{N}])~u', $n, $m)) {
        $rest = ltrim(substr($s, strlen($m[0])));
        return 0;
    }
    foreach (CM_UNITS as $u) {
        if (!preg_match('~^(\d{1,4})?\s*(?:' . $u[0] . ')(?![\p{L}\p{N}])~u', $n, $m)) { continue; }
        $hasNum = isset($m[1]) && $m[1] !== '';
        if (!$hasNum && mb_strlen($m[0]) < 3) { continue; }
        $rest = ltrim(substr($s, strlen($m[0])));
        $t = ($hasNum ? (int)$m[1] : 1) * $u[1];
        return ($t < CM_MIN_TERM || $t > CM_MAX_TERM) ? -1 : $t;
    }
    if (preg_match('~^(\d{1,4})(?![\p{L}\p{N}])~u', $n, $m)) {
        $rest = ltrim(substr($s, strlen($m[0])));
        $t = (int)$m[1] * 60;
        return ($t < CM_MIN_TERM || $t > CM_MAX_TERM) ? -1 : $t;
    }
    return null;
}

function cm_term_text(int $secs): string {
    if ($secs <= 0) { return 'навсегда'; }
    if ($secs % 86400 === 0) { return intdiv($secs, 86400) . ' дн.'; }
    if ($secs % 3600 === 0) { return intdiv($secs, 3600) . ' ч'; }
    if ($secs % 60 === 0) { return intdiv($secs, 60) . ' мин'; }
    return $secs . ' с';
}

function cm_reason(string $args, string $body): string {
    return mb_substr(trim(trim($args) . "\n" . trim($body)), 0, 500);
}

// --------------------------------------------------------------------------
//  Разметка: Telegram считает смещения в единицах UTF-16
// --------------------------------------------------------------------------

function cm_utf16_len(string $s): int {
    return intdiv(strlen((string)mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
}

function cm_utf16_substr(string $s, int $off, int $len): string {
    $u = (string)mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    $part = (string)substr($u, $off * 2, $len * 2);
    return $part === '' ? '' : (string)mb_convert_encoding($part, 'UTF-8', 'UTF-16LE');
}

// Разметка текста со второй строки, со смещениями от начала тела.
function cm_body_entities(array $msg): array {
    $text = (string)($msg['text'] ?? '');
    $nl = strpos($text, "\n");
    if ($nl === false) { return []; }
    $shift = cm_utf16_len(substr($text, 0, $nl + 1));
    $out = [];
    foreach ((array)($msg['entities'] ?? []) as $e) {
        if (!is_array($e) || (int)($e['offset'] ?? 0) < $shift) { continue; }
        $e['offset'] = (int)$e['offset'] - $shift;
        $out[] = $e;
    }
    return $out;
}

// --------------------------------------------------------------------------
//  Цель
// --------------------------------------------------------------------------

// Сообщение, на которое ответили. В теме форума каждое сообщение формально
// отвечает на корень темы — это не ответ.
function cm_reply_of(array $msg): ?array {
    $r = $msg['reply_to_message'] ?? null;
    if (!is_array($r) || isset($r['forum_topic_created'])) { return null; }
    if (!empty($msg['is_topic_message']) && (int)($r['message_id'] ?? 0) === (int)($msg['message_thread_id'] ?? -1)) {
        return null;
    }
    return $r;
}

/**
 * Цель команды по старшинству: ответ → упоминание без ника → @ник / t.me/ник →
 * id. Найденная цель вырезается из $args (кроме ответа). Перед целью может
 * стоять ранг («+модер 2 @ник») — он остаётся в $args. Цифра любая, не только
 * 0–4: «+модер 5 @ник» должен дойти до ответа «укажите ранг 0–4», а не
 * споткнуться о цель. null — цели нет; если ник незнаком, $why = 'unknown'.
 * При неудаче $args не меняется.
 */
function cm_target(PDO $pdo, array $msg, string &$args, ?string &$why = null): ?array {
    $why = null;
    $reply = cm_reply_of($msg);
    if ($reply !== null && is_array($reply['from'] ?? null)) {
        $f = $reply['from'];
        return ['id' => (int)($f['id'] ?? 0), 'name' => cm_display_name($f), 'is_bot' => !empty($f['is_bot']),
                'msg_id' => (int)($reply['message_id'] ?? 0)];
    }
    $text = (string)($msg['text'] ?? '');
    foreach ((array)($msg['entities'] ?? []) as $e) {
        if (!is_array($e) || ($e['type'] ?? '') !== 'text_mention' || !is_array($e['user'] ?? null)) { continue; }
        $label = cm_utf16_substr($text, (int)($e['offset'] ?? 0), (int)($e['length'] ?? 0));
        $pos = $label === '' ? false : strpos($args, $label);
        if ($pos !== false) { $args = trim(substr($args, 0, $pos) . substr($args, $pos + strlen($label))); }
        $u = $e['user'];
        return ['id' => (int)($u['id'] ?? 0), 'name' => cm_display_name($u), 'is_bot' => !empty($u['is_bot']), 'msg_id' => 0];
    }
    $orig = $args;
    $lead = '';
    if (preg_match('~^(\d)\s+~', $args, $lm)) {
        $lead = $lm[1];
        $args = substr($args, strlen($lm[0]));
    }
    $found = null;
    if (preg_match('~^(?:@|(?:https?://)?t\.me/)([A-Za-z]\w{3,31})(?!\w)~iu', $args, $m)) {
        $u = cm_user_find($pdo, $m[1]);
        if ($u === null) {
            $why = 'unknown';
        } else {
            $found = ['id' => $u['user_id'], 'name' => $u['name'], 'is_bot' => false, 'msg_id' => 0];
            $args = ltrim(substr($args, strlen($m[0])));
        }
    } elseif (preg_match('~^(\d{5,15})(?!\d)~', $args, $m)) {
        $id = (int)$m[1];
        $found = ['id' => $id, 'name' => cm_user_name($pdo, $id), 'is_bot' => false, 'msg_id' => 0];
        $args = ltrim(substr($args, strlen($m[0])));
    }
    if ($found === null) {
        $args = $orig;
        return null;
    }
    $args = trim($lead . ' ' . $args);
    return $found;
}
