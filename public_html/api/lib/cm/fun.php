<?php
// Случайные команды и РП (этап 3): рандом, монетка, выбери, инфа / шанс, шар,
// !кто, !когда, шипперим, РП-действия и 18+. Здесь же общее для всего этапа 3:
// cm_ready3, cm_rand, cm_plural. Таблицы этапа 3 —
// docs/migrations/2026-11-08-chat-manager-games.sql. Приглашения — offers.php,
// кубы — dice.php, дуэль — duel.php, пары и браки — love.php, кланы —
// clans.php, ферма монет — farm.php.

const CM_RAND_MAX   = 1000000000;
const CM_CHOOSE_MAX = 10;
const CM_WHO_DAYS   = 7;
const CM_BALL = ['Да.', 'Нет.', 'Скорее всего.', 'Не думаю.', 'Определённо да.', 'Даже не надейтесь.',
                 'Возможно.', 'Спросите позже.', 'Знаки говорят — да.', 'Сомневаюсь.'];

// Миграция этапа 3 выполняется руками после этапа 2. Пока её нет, команды,
// которым нужны таблицы этапа 3, отвечают «ещё не настроено». Случайным
// командам база не нужна — они работают всегда.
function cm_ready3(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM cm_offers LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Первая строка обработчика, которому нужны таблицы этапа 3.
function cm_need3(array $ctx): bool {
    if (cm_ready3($ctx['pdo'])) { return true; }
    cm_reply($ctx, cm_t('not_ready2'));
    return false;
}

// Случайное целое от $min до $max включительно. Тесты подменяют источник:
// $ctx['rand'] — функция (int $min, int $max): int.
function cm_rand(array $ctx, int $min, int $max): int {
    $f = $ctx['rand'] ?? null;
    return is_callable($f) ? (int)$f($min, $max) : random_int($min, $max);
}

// Слово при числе: формы для 1, 2 и 5 — cm_plural(5, ['победа', 'победы', 'побед']) → «побед».
function cm_plural(int $n, array $forms): string {
    $n = abs($n) % 100;
    if ($n >= 11 && $n <= 19) { return $forms[2]; }
    $d = $n % 10;
    if ($d === 1) { return $forms[0]; }
    return ($d >= 2 && $d <= 4) ? $forms[1] : $forms[2];
}

// --------------------------------------------------------------------------
//  Случайные команды. Ответы временные, кроме «!кто».
// --------------------------------------------------------------------------

// «рандом» — от 1 до 100, «рандом 50» — от 1 до 50, «рандом 10 20» — от 10 до 20.
function cm_cmd_rand(array $ctx, array $cmd): void {
    if (!preg_match('~^(-?[0-9]{1,10})?(?:\s+(-?[0-9]{1,10}))?$~', trim($cmd['args']), $m)) {
        cm_reply($ctx, cm_t('rand_usage'));
        return;
    }
    $one = isset($m[1]) && $m[1] !== '';
    $two = isset($m[2]) && $m[2] !== '';
    $x = $two ? (int)$m[1] : 1;
    $y = $two ? (int)$m[2] : ($one ? (int)$m[1] : 100);
    $lo = min($x, $y);
    $hi = max($x, $y);
    if (max(abs($lo), abs($hi)) > CM_RAND_MAX) {
        cm_reply($ctx, cm_t('rand_usage'));
        return;
    }
    cm_reply($ctx, cm_t('rand_done', ['a' => cm_num($lo), 'b' => cm_num($hi), 'n' => cm_num(cm_rand($ctx, $lo, $hi))]));
}

function cm_cmd_coin(array $ctx, array $cmd): void {
    cm_reply($ctx, cm_t(cm_rand($ctx, 0, 1) === 0 ? 'coin_heads' : 'coin_tails'));
}

// «чай, кофе или сок?» → ['чай', 'кофе', 'сок'].
function cm_choose_options(string $s): array {
    $s = trim((string)preg_replace('~[?!.]+$~u', '', trim($s)));
    $out = [];
    foreach (preg_split('~\s*,\s*|\s+или\s+~iu', $s) as $p) {
        $p = trim((string)$p);
        if ($p !== '') { $out[] = $p; }
    }
    return $out;
}

function cm_cmd_choose(array $ctx, array $cmd): void {
    $opts = cm_choose_options($cmd['args']);
    if (count($opts) < 2 || count($opts) > CM_CHOOSE_MAX) {
        cm_reply($ctx, cm_t('choose_usage'));
        return;
    }
    cm_reply($ctx, cm_t('choose_done', ['pick' => $opts[cm_rand($ctx, 0, count($opts) - 1)]]));
}

// Вероятность 0–100. Тот же текст в тот же день по Москве — то же число:
// иначе её перекидывали бы до нужного.
function cm_chance(string $text, int $now): int {
    $key = cm_norm(trim((string)preg_replace('~\s+~u', ' ', $text)));
    return crc32($key . '|' . cm_day($now)) % 101;
}

function cm_cmd_chance(array $ctx, array $cmd): void {
    $text = trim($cmd['args'] . ' ' . $cmd['body']);
    if ($text === '') {
        cm_reply($ctx, cm_t('chance_usage'));
        return;
    }
    cm_reply($ctx, cm_t('chance_done', ['n' => cm_chance($text, (int)$ctx['now'])]));
}

function cm_cmd_ball(array $ctx, array $cmd): void {
    if (trim($cmd['args'] . $cmd['body']) === '') {
        cm_reply($ctx, cm_t('ball_usage'));
        return;
    }
    cm_reply($ctx, cm_t('ball_done', ['answer' => CM_BALL[cm_rand($ctx, 0, count(CM_BALL) - 1)]]));
}

// «!кто дежурит сегодня?» — случайный участник из писавших за неделю (ботов
// счётчик не считает, ушедших cm_stats_top не отдаёт). Только с префиксом
// (CM_PREFIX_ONLY в parse.php): «кто знает…» в обычной речи — не команда.
// Ответ постоянный: ради него и спрашивали.
function cm_cmd_who(array $ctx, array $cmd): void {
    if (!cm_need2($ctx)) { return; }
    $pdo = $ctx['pdo'];
    $top = cm_stats_top($pdo, (int)$ctx['chat']['chat_id'], CM_WHO_DAYS, (int)$ctx['now'], 1000);
    if (!$top) {
        cm_reply($ctx, cm_t('who_empty'));
        return;
    }
    $name = cm_user_who($pdo, $top[cm_rand($ctx, 0, count($top) - 1)]['user_id']);
    $q = trim((string)preg_replace('~[?]+$~u', '', trim($cmd['args'])));
    cm_reply($ctx, $q === '' ? cm_t('who_any', ['name' => $name]) : cm_t('who_done', ['q' => $q, 'name' => $name]), false);
}

// «бот, когда я стану богатым?» — случайный срок. Как «!кто»: только с
// префиксом или обращением «бот …», ответ постоянный.
const CM_WHEN = ['Сегодня.', 'Завтра.', 'Послезавтра.', 'Скоро.', 'Уже было.', 'Никогда.',
                 'Когда рак на горе свистнет.', 'days', 'weeks', 'months', 'years'];
const CM_WHEN_UNITS = ['days' => [2, 30, ['день', 'дня', 'дней']], 'weeks' => [2, 10, ['неделю', 'недели', 'недель']],
                       'months' => [2, 12, ['месяц', 'месяца', 'месяцев']], 'years' => [2, 50, ['год', 'года', 'лет']]];

function cm_when_answer(array $ctx): string {
    $a = CM_WHEN[cm_rand($ctx, 0, count(CM_WHEN) - 1)];
    $u = CM_WHEN_UNITS[$a] ?? null;
    if ($u === null) { return $a; }
    $n = cm_rand($ctx, $u[0], $u[1]);
    return cm_t('when_in', ['n' => $n, 'unit' => cm_plural($n, $u[2])]);
}

function cm_cmd_when(array $ctx, array $cmd): void {
    $q = trim((string)preg_replace('~[?]+$~u', '', trim($cmd['args'])));
    if ($q === '') {
        cm_reply($ctx, cm_t('when_usage'));
        return;
    }
    cm_reply($ctx, cm_t('when_done', ['q' => $q, 'answer' => cm_when_answer($ctx)]), false);
}

// --------------------------------------------------------------------------
//  Шипперим: пара из двух случайных участников или совместимость с целью
// --------------------------------------------------------------------------

// Совместимость 0–100: та же пара в тот же день по Москве — то же число, как
// у «инфы», иначе её перекидывали бы до нужного. Порядок не важен.
function cm_ship_percent(int $a, int $b, int $now): int {
    return crc32(min($a, $b) . '|' . max($a, $b) . '|' . cm_day($now)) % 101;
}

// Имя пары из половинок имён: «Вася» + «Петя» → «Ватя». Первое слово из букв
// (смайлы и цифры пропускаются); не из чего собрать — ''.
function cm_ship_name(string $a, string $b): string {
    $part = function (string $s): string {
        $s = trim((string)preg_replace('~[^\p{L}\s]+~u', '', $s));
        return (string)(preg_split('~\s+~u', $s)[0] ?? '');
    };
    $x = $part($a);
    $y = $part($b);
    if (mb_strlen($x) < 2 || mb_strlen($y) < 2) { return ''; }
    $head = mb_substr($x, 0, (int)ceil(mb_strlen($x) / 2));
    $tail = mb_strtolower(mb_substr($y, intdiv(mb_strlen($y), 2)));
    $name = $head . $tail;
    return mb_strtoupper(mb_substr($name, 0, 1)) . mb_strtolower(mb_substr($name, 1));
}

function cm_ship_heart(int $p): string {
    if ($p >= 80) { return '💞'; }
    if ($p >= 50) { return '❤️'; }
    if ($p >= 20) { return '💛'; }
    return '💔';
}

/**
 * «шипперим» — пара из двух случайных участников, писавших за неделю (как у
 * «!кто»). Ответом или с @ником — автор и цель, двумя @никами — эти двое.
 * Ответ постоянный, ники с упоминанием: ради этого и шипперят.
 */
function cm_cmd_ship(array $ctx, array $cmd): void {
    $pdo = $ctx['pdo'];
    $me = (int)$ctx['actor'];
    $args = $cmd['args'];
    $why = null;
    $t1 = cm_target($pdo, $ctx['msg'], $args, $why);
    if ($t1 === null && $why === 'unknown') {
        cm_reply($ctx, cm_t('target_unknown'));
        return;
    }
    if ($t1 !== null) {
        $t2 = null;
        if (cm_reply_of($ctx['msg']) === null) {
            $rest = (string)preg_replace('~^(?:и|\+|&|,)\s*~iu', '', $args);
            $t2 = cm_target($pdo, ['text' => $rest], $rest, $why);
            if ($t2 === null && $why === 'unknown') {
                cm_reply($ctx, cm_t('target_unknown'));
                return;
            }
        }
        $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
        $a = $t2 === null ? ['id' => $me, 'name' => cm_display_name($from), 'is_bot' => false] : $t1;
        $b = $t2 ?? $t1;
        if (cm_botlike($a) || cm_botlike($b)) {
            cm_reply($ctx, cm_t('target_bot'));
            return;
        }
        if ((int)$a['id'] === (int)$b['id']) {
            cm_reply($ctx, cm_t('target_self'));
            return;
        }
        cm_ship_say($ctx, (int)$a['id'], (string)$a['name'], (int)$b['id'], (string)$b['name']);
        return;
    }
    if (!cm_need2($ctx)) { return; }
    $top = cm_stats_top($pdo, (int)$ctx['chat']['chat_id'], CM_WHO_DAYS, (int)$ctx['now'], 1000);
    if (count($top) < 2) {
        cm_reply($ctx, cm_t('ship_empty'));
        return;
    }
    $i = cm_rand($ctx, 0, count($top) - 1);
    $j = cm_rand($ctx, 0, count($top) - 2);
    if ($j >= $i) { $j++; }
    $a = $top[$i]['user_id'];
    $b = $top[$j]['user_id'];
    cm_ship_say($ctx, $a, cm_user_name($pdo, $a), $b, cm_user_name($pdo, $b));
}

function cm_ship_say(array $ctx, int $a, string $an, int $b, string $bn): void {
    $p = cm_ship_percent($a, $b, (int)$ctx['now']);
    $name = cm_ship_name($an, $bn);
    $text = cm_t('ship_done', ['a' => cm_who($a, $an), 'b' => cm_who($b, $bn), 'heart' => cm_ship_heart($p), 'n' => $p]);
    if ($name !== '') { $text .= "\n" . cm_t('ship_name', ['name' => $name]); }
    cm_reply($ctx, $text, false);
}

// --------------------------------------------------------------------------
//  РП-действия: «обнять» ответом на сообщение или с @ником
// --------------------------------------------------------------------------

// Фраза → [смайл, «он», «она», пол не указан]. Имена не склоняются.
const CM_RP = [
    'обнять'      => ['🤗', 'обнял', 'обняла', 'обнял(а)'],
    'поцеловать'  => ['😘', 'поцеловал', 'поцеловала', 'поцеловал(а)'],
    'погладить'   => ['🥰', 'погладил', 'погладила', 'погладил(а)'],
    'ударить'     => ['👊', 'ударил', 'ударила', 'ударил(а)'],
    'укусить'     => ['😬', 'укусил', 'укусила', 'укусил(а)'],
    'пнуть'       => ['🦶', 'пнул', 'пнула', 'пнул(а)'],
    'пожать руку' => ['🤝', 'пожал руку', 'пожала руку', 'пожал(а) руку'],
    'похвалить'   => ['👏', 'похвалил', 'похвалила', 'похвалил(а)'],
    'поздравить'  => ['🎉', 'поздравил', 'поздравила', 'поздравил(а)'],
    'накормить'   => ['🍲', 'накормил', 'накормила', 'накормил(а)'],
    'напоить'     => ['🥤', 'напоил', 'напоила', 'напоил(а)'],
    'пощекотать'  => ['🤭', 'пощекотал', 'пощекотала', 'пощекотал(а)'],
    'ущипнуть'    => ['🤏', 'ущипнул', 'ущипнула', 'ущипнул(а)'],
    'убить'       => ['🔪', 'убил', 'убила', 'убил(а)'],
    'шлепнуть'    => ['👋', 'шлёпнул', 'шлёпнула', 'шлёпнул(а)'],
    'дать пять'   => ['🙌', 'дал пять', 'дала пять', 'дал(а) пять'],
    'дать леща'   => ['🐟', 'дал леща', 'дала леща', 'дал(а) леща'],
    'лизнуть'     => ['👅', 'лизнул', 'лизнула', 'лизнул(а)'],
    'понюхать'    => ['👃', 'понюхал', 'понюхала', 'понюхал(а)'],
    'прижать'     => ['🫂', 'прижал к себе', 'прижала к себе', 'прижал(а) к себе'],
];

// 18+ (с мясом и матом, как у Ириса) — свой раздел «18+», по умолчанию
// ВЫКЛЮЧЕН: ранг 6 в CM_COMMANDS. Аудитория Blox Fruits — во многом дети,
// поэтому включает только создатель чата («дк 18+ 0»), сам бот — никогда.
// Слова не перечислены на maknemy.com/bot: страницу видят все и поисковики.
const CM_ADULT = '18+';
const CM_RP18 = [
    'выебать'   => ['😏', 'выебал', 'выебала', 'выебал(а)'],
    'трахнуть'  => ['🔥', 'трахнул', 'трахнула', 'трахнул(а)'],
    'отсосать'  => ['🍌', 'отсосал у', 'отсосала у', 'отсосал(а) у'],
    'отлизать'  => ['👅', 'отлизал у', 'отлизала у', 'отлизал(а) у'],
    'раздеть'   => ['👙', 'раздел', 'раздела', 'раздел(а)'],
    'облапать'  => ['🫳', 'облапал', 'облапала', 'облапал(а)'],
    'засосать'  => ['💋', 'засосал', 'засосала', 'засосал(а)'],
    'нагнуть'   => ['🍑', 'нагнул', 'нагнула', 'нагнул(а)'],
    'отшлепать' => ['🍑', 'отшлёпал', 'отшлёпала', 'отшлёпал(а)'],
    'уебать'    => ['💥', 'уебал', 'уебала', 'уебал(а)'],
    'послать'   => ['🖕', 'послал нахуй', 'послала нахуй', 'послал(а) нахуй'],
];
// С ботом — только это, остальное с ним бессмысленно.
const CM_RP_BOT_OK = ['обнять', 'похвалить', 'дать пять'];
// Опыта паре от РП — не больше стольких в сутки по Москве.
const CM_RP_XP_DAY = 20;

// Бот или служебный аккаунт Telegram (от него приходят автопересылки из канала).
function cm_botlike(array $t): bool {
    return !empty($t['is_bot']) || (int)($t['id'] ?? 0) === CM_TG_SERVICE_ID;
}

// +1 опыт паре $a и $b (в любом порядке), не больше CM_RP_XP_DAY в сутки по
// Москве: новый день обнуляет счёт сегодняшнего. Не пара — ничего.
function cm_couple_xp(PDO $pdo, $chats, int $a, int $b, int $now): void {
    $st = $pdo->prepare('SELECT id FROM cm_couples WHERE chat_id IN (' . cm_in($chats) . ') AND ended_at = 0
        AND ((a_id = :a AND b_id = :b) OR (a_id = :b2 AND b_id = :a2))');
    $st->execute([':a' => $a, ':b' => $b, ':a2' => $a, ':b2' => $b]);
    $id = (int)$st->fetchColumn();
    if ($id === 0) { return; }
    $day = cm_day($now);
    $pdo->prepare('UPDATE cm_couples SET xp_day = :d, xp_today = 0 WHERE id = :id AND xp_day <> :d2')
        ->execute([':d' => $day, ':id' => $id, ':d2' => $day]);
    $pdo->prepare('UPDATE cm_couples SET xp = xp + 1, xp_today = xp_today + 1 WHERE id = :id AND xp_today < ' . CM_RP_XP_DAY)
        ->execute([':id' => $id]);
}

// «🤗 Вася обнял(а) Петя». Форма глагола — по полу из анкеты автора (этап 2).
// Ответ постоянный: это и есть разговор в чате.
function cm_cmd_rp(array $ctx, array $cmd): void {
    if (!cm_need3($ctx)) { return; }
    $args = $cmd['args'];
    $t = cm_need_target($ctx, $args);
    if ($t === null) { return; }
    $me = (int)$ctx['actor'];
    if ((int)$t['id'] === $me) {
        cm_reply($ctx, cm_t('target_self'));
        return;
    }
    $phrase = $cmd['phrase'];
    $bot = cm_botlike($t);
    if ($bot && !in_array($phrase, CM_RP_BOT_OK, true)) {
        cm_reply($ctx, cm_t('target_bot'));
        return;
    }
    $rp = CM_RP[$phrase] ?? CM_RP18[$phrase];
    $g = cm_profile_get($ctx['pdo'], $me)['gender'];
    $from = is_array($ctx['msg']['from'] ?? null) ? $ctx['msg']['from'] : [];
    cm_reply($ctx, cm_t('rp_done', ['emoji' => $rp[0], 'from' => cm_who_from($from),
        'verb' => $rp[$g === 'm' ? 1 : ($g === 'f' ? 2 : 3)], 'to' => $t['who']]), false);
    if (!$bot) { cm_couple_xp($ctx['pdo'], cm_scope($ctx), $me, (int)$t['id'], (int)$ctx['now']); }
}
