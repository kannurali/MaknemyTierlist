<?php
// Фильтр чата: ссылки и запрещённые слова.
//
// Работает при ПОКАЗЕ, а не при записи: в chat_messages лежит исходный текст.
// Так новое слово из панели сразу прячет и старые сообщения, а модератор видит,
// что человек написал на самом деле, и решает, банить ли его.
//
// Ссылки режутся все, кроме своего сайта. Чёрный список тут бесполезен: скам-
// домены новые каждый день. Roblox в исключения не входит намеренно: через
// ссылки на него игроков ловят на фишинг (поддельный вход, «приватный сервер»).
//
// Подстраховка от обходов — в разборе адресов ниже, но идеальной она не будет:
// «пиши в тг vasya» или ссылку на скриншоте фильтр не увидит.

// Что подставляется вместо ссылки. Страница заменяет метку на подпись на языке
// посетителя (LINK_CUT в js/chat-page.js — тест сверяет, что они совпадают).
const CHAT_LINK_CUT = '[link]';
const CHAT_WORD_CUT = '***';

// Слово в панели: от 2 символов, «*» только по краям. Пробелов внутри нет:
// фильтр сравнивает слова по одному, и фраза никогда бы не сработала.
const CHAT_FILTER_WORD_RE  = '/^\*?[\p{L}\p{N}@$]{2,62}\*?\z/u';
const CHAT_FILTER_WORDS_MAX = 500;

// Журнал срабатываний нужен, чтобы решить, кого банить, а не как архив.
const CHAT_FILTER_LOG_DAYS = 90;

// Единственный разрешённый адрес. Символы пути без точки: иначе за своим
// доменом можно спрятать чужой (maknemy.com/?go=evil.ru), а адресов с точками
// на сайте нет. Сравнение только латиницей: «mаknemy.com» с кириллической «а»
// сюда не пройдёт.
const CHAT_LINK_ALLOWED_RE = '~^(?:https?://)?(?:www\.)?maknemy\.com(?:[/?#][A-Za-z0-9/_?=&#%\-]*)?\z~i';

// Буквы, которые выглядят как латинские. Адрес «rоblох.соm» с кириллицей
// читается как настоящий, и сравнивать его надо с настоящим.
const CHAT_FILTER_LOOKALIKES = [
    'а' => 'a', 'в' => 'b', 'е' => 'e', 'ё' => 'e', 'і' => 'i', 'ї' => 'i', 'ј' => 'j',
    'к' => 'k', 'м' => 'm', 'н' => 'h', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'ѕ' => 's',
    'т' => 't', 'у' => 'y', 'х' => 'x', 'ԁ' => 'd', 'ɡ' => 'g', 'һ' => 'h', 'ӏ' => 'l',
    'ո' => 'n', 'ս' => 'u', 'α' => 'a', 'β' => 'b', 'ε' => 'e', 'ι' => 'i', 'κ' => 'k',
    'ν' => 'v', 'ο' => 'o', 'ρ' => 'p', 'τ' => 't', 'υ' => 'u', 'χ' => 'x', 'ı' => 'i',
];

// Для слов: те же двойники плюс привычные замены. «й» и «ё» приводятся к одной
// букве, чтобы одной записи в списке хватало на обе формы.
const CHAT_FILTER_WORD_SWAPS = ['й' => 'и', '0' => 'o', '@' => 'a', '$' => 's'];

// Доменные зоны: все двухбуквенные и ходовые длинные. Слова вроде .red и .love
// не взяты намеренно: «Buddha.Love» в переписке про фрукты встретится чаще, чем
// такой адрес, а настоящие скам-адреса в них редки.
const CHAT_FILTER_ZONES =
    'ac ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj bm bn bo br bs bt bw by bz '
  . 'ca cc cd cf cg ch ci ck cl cm cn co cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg er es et eu '
  . 'fi fj fk fm fo fr ga gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht hu '
  . 'id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky kz la lb lc li lk lr ls lt lu lv ly '
  . 'ma mc md me mg mh mk ml mm mn mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz '
  . 'om pa pe pf pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si sk sl sm sn so sr st su sv sx sy sz '
  . 'tc td tf tg th tj tk tl tm tn to tr tt tv tw tz ua ug uk us uy uz va vc ve vg vi vn vu wf ws ye yt za zm zw '
  . 'com net org info biz name pro mobi asia tel travel edu gov xxx xyz top site online club shop store live fun '
  . 'icu vip win bid loan link click app dev page cloud website space tech world today art wtf lol monster rest '
  . 'sbs cfd cyou autos boats bar ink kim pics work works network email support help guru best life buzz digital '
  . 'social zone one blog news media games game gift gifts cash money promo sale deals trade market finance bet casino';

// Зоны кириллицей. Часть настоящие (.рф, .рус), «.ру» пишут, чтобы обойти
// фильтр. «.москва» не взята: «г.Москва» пишут куда чаще, чем такой адрес.
const CHAT_FILTER_ZONES_CYR = ['рф', 'ру', 'ком', 'орг', 'рус', 'онлайн', 'сайт', 'укр', 'бел'];

// Где адрес разбит пробелами или скобками — «evil . com», «evil [точка] ru».
// Тут список короткий: сравнивать с обычным текстом рискованнее.
const CHAT_FILTER_ZONES_SPACED = 'com ru su org net xyz gg io site online top shop club info biz link store live fun icu vip ly cc me to';

// Где пробел только с одной стороны точки, это почти обычное предложение:
// «Хороший трейд. Top фрукт». Поэтому только зоны, которые не являются словами.
const CHAT_FILTER_ZONES_NEAR = 'com|ru|su|org|xyz|рф';

function chat_filter_zones(): array {
    static $set = null;
    if ($set === null) { $set = array_flip(explode(' ', CHAT_FILTER_ZONES)); }
    return $set;
}

/** Зона как регулярка, где каждая буква принимает и своих двойников: com → [cс][oоο][mм]. */
function chat_filter_zone_pattern(string $zone): string {
    static $classes = null;
    if ($classes === null) {
        $classes = [];
        foreach (CHAT_FILTER_LOOKALIKES as $fake => $real) {
            $classes[$real] = ($classes[$real] ?? '') . $fake;
        }
    }
    $out = '';
    foreach (str_split($zone) as $ch) {
        $out .= isset($classes[$ch]) ? '[' . $ch . $classes[$ch] . ']' : $ch;
    }
    return $out;
}

/**
 * Регулярки для ссылок, по порядку: [выражение, это ли кандидат в адрес].
 * Кандидата ещё надо проверить (chat_filter_is_host): «Gas.Dough» — не адрес.
 */
function chat_filter_link_steps(): array {
    static $steps = null;
    if ($steps !== null) { return $steps; }

    $start = '(?<![\p{L}\p{N}])';
    $label = '[\p{L}\p{N}\-]{1,63}';
    $end   = '(?![\p{L}\p{N}])(?:[/?#\\\\]\S*)?';

    $spaced = ['рф', 'ру', 'ком', 'орг'];
    foreach (explode(' ', CHAT_FILTER_ZONES_SPACED) as $z) { $spaced[] = chat_filter_zone_pattern($z); }
    $spaced = implode('|', $spaced);

    $dot = '(?:\.|dot|точка)';
    $sep = '\s*(?:\[\s*' . $dot . '\s*\]|\(\s*' . $dot . '\s*\)|\{\s*' . $dot . '\s*\}|<\s*' . $dot . '\s*>)\s*'
         . '|\s+(?:dot|точка|тчк)\s+'
         . '|\s+\.\s+';

    $steps = [
        // Со схемой: https://…, h t t p s : / /…, hxxp://…, любая своя схема
        // (roblox://), а также те, что открывают приложение без двух слешей.
        ['~' . $start . '(?:h\s*[tx*_]\s*[tx*_]\s*p\s*s?\s*[:;]?\s*/\s*/\s*\S*'
            . '|[a-z][a-z0-9+.\-]{1,24}://\S*'
            . '|(?:roblox-player|roblox-studio|javascript|vbscript|tg|discord):\S+)~iu', false],
        ['~' . $start . 'www\s*[.,]\s*\S+~iu', false],
        // Обычный адрес: evil.ru, sub.evil.ru/path, рф-домены. Сам адрес
        // проверяется в chat_filter_is_host().
        ['~' . $start . '[\p{L}\p{N}][\p{L}\p{N}\-]{0,62}(?:\.[\p{L}\p{N}][\p{L}\p{N}\-]{0,62})*\.\p{L}[\p{L}\p{N}\-]{1,23}'
            . '(?![\p{L}\p{N}])(?::\d{1,5})?(?:[/?#\\\\]\S*)?~u', true],
        // Адрес цифрами.
        ['~(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?![\d.])(?::\d{1,5})?(?:/\S*)?~', false],
        // Разбитый: evil . com, evil [.] com, evil точка ру, evil dot gg.
        ['~' . $start . $label . '(?:' . $sep . ')(?:' . $spaced . ')' . $end . '~iu', false],
        // Пробел с одной стороны точки или запятая вместо точки.
        ['~' . $start . $label . '(?:\s+\.|\.\s+|,)(?:' . CHAT_FILTER_ZONES_NEAR . ')' . $end . '~iu', false],
        // Зона вразрядку: evil.c o m.
        ['~' . $start . $label . '\s*\.\s*(?:c\s+o\s+m|r\s+u|n\s+e\s+t|o\s+r\s+g)(?![\p{L}\p{N}])~iu', false],
        // Приглашения без точки: discord gg/abc, t me/abc.
        ['~' . $start . '(?:discord|dsc|t)\s*(?:gg|me)\s*/\s*\S+~iu', false],
    ];
    return $steps;
}

/**
 * Похож ли кандидат на адрес. Зоной считается любая часть после первой, а не
 * только последняя: иначе «evil.com.hello» проходил бы, хотя читается как адрес.
 */
function chat_filter_is_host(string $host): bool {
    $host   = mb_strtolower($host);
    $labels = explode('.', $host);
    $first  = array_shift($labels);
    $zones  = chat_filter_zones();
    $plainHost = null;
    foreach ($labels as $label) {
        // «evil.ru-» и «evil.ru-привет»: дефис в конце зоны адрес не отменяет.
        $zone = explode('-', $label)[0];
        if (in_array($zone, CHAT_FILTER_ZONES_CYR, true)) { return true; }

        $plain = strtr($zone, CHAT_FILTER_LOOKALIKES);
        if (!isset($zones[$plain])) { continue; }
        if ($plain === $zone) {
            // «1.st», «10.am»: число с двухбуквенной зоной — это текст.
            if (strlen($zone) === 2 && count($labels) === 1 && ctype_digit($first)) { continue; }
            return true;
        }
        if (preg_match('/[a-z]/', $zone)) { return true; }
        // Зона целиком кириллицей: «соm» в тексте бывает только адресом, если
        // и весь он собран из двойников. Иначе это «Ну ладно.то есть».
        if ($plainHost === null) { $plainHost = strtr($host, CHAT_FILTER_LOOKALIKES); }
        if (!preg_match('/[^\x00-\x7F]/', $plainHost)) { return true; }
    }
    return false;
}

function chat_filter_cut_token(string $token): string {
    if (preg_match(CHAT_LINK_ALLOWED_RE, $token)) { return $token; }
    // Знаки в конце — это препинание вокруг ссылки, а не её часть.
    if (preg_match('~^(.*?)([.,;:!?)\]}»"\'’”]+)\z~su', $token, $m)) {
        if (preg_match(CHAT_LINK_ALLOWED_RE, $m[1])) { return $token; }
        return CHAT_LINK_CUT . $m[2];
    }
    return CHAT_LINK_CUT;
}

/** Текст с вырезанными ссылками. null — регулярка не справилась. */
function chat_filter_links(string $text): ?string {
    foreach (chat_filter_link_steps() as [$re, $isHost]) {
        $text = preg_replace_callback($re, function (array $m) use ($isHost): string {
            if ($isHost) {
                $host = preg_replace('~[:/?#\\\\].*\z~su', '', $m[0]);
                if (!chat_filter_is_host((string)$host)) { return $m[0]; }
            }
            return chat_filter_cut_token($m[0]);
        }, $text);
        if ($text === null) { return null; }
    }
    return $text;
}

/**
 * Убирает способы спрятать адрес от проверки: широкие буквы, другие точки и
 * невидимые символы. Соединитель и селекторы эмодзи убираем только между буквами,
 * иначе составные эмодзи рассыпались бы.
 */
function chat_filter_fold(string $text): ?string {
    if (class_exists('Normalizer')) {
        $kc = Normalizer::normalize($text, Normalizer::FORM_KC);
        if (is_string($kc)) { $text = $kc; }
    }
    $text = preg_replace(
        '/[\x{00AD}\x{034F}\x{061C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180E}\x{200B}\x{200C}\x{200E}\x{200F}'
        . '\x{202A}-\x{202E}\x{2060}-\x{206F}\x{3164}\x{FEFF}\x{FFA0}]/u',
        '', $text
    );
    if ($text === null) { return null; }
    $text = preg_replace('/(?<=[\p{L}\p{N}.])[\x{200D}\x{FE00}-\x{FE0F}](?=[\p{L}\p{N}.])/u', '', $text);
    if ($text === null) { return null; }
    $text = preg_replace_callback('/[\x{FF01}-\x{FF5E}]/u', function (array $m): string {
        return chr(mb_ord($m[0], 'UTF-8') - 0xFEE0);
    }, $text);
    if ($text === null) { return null; }
    return preg_replace('/[\x{3002}\x{FF61}\x{2024}\x{FE52}\x{06D4}]/u', '.', $text);
}

/** Запись в списке → вид для сравнения: нижний регистр, двойники, замены. */
function chat_filter_word_key(string $word): string {
    return strtr(mb_strtolower($word), CHAT_FILTER_LOOKALIKES + CHAT_FILTER_WORD_SWAPS);
}

/** Список слов → разбор по видам: целиком, начало, конец, любая часть. */
function chat_filter_compile(array $words): array {
    $rules = ['exact' => [], 'prefix' => [], 'suffix' => [], 'part' => []];
    foreach ($words as $raw) {
        $word = (string)$raw;
        $head = strncmp($word, '*', 1) === 0;
        $tail = substr($word, -1) === '*';
        $key  = chat_filter_word_key(trim($word, '*'));
        if ($key === '') { continue; }
        if ($head && $tail)  { $rules['part'][] = $key; }
        elseif ($tail)       { $rules['prefix'][] = $key; }
        elseif ($head)       { $rules['suffix'][] = $key; }
        else                 { $rules['exact'][$key] = true; }
    }
    return $rules;
}

function chat_filter_word_hit(string $token, array $rules): bool {
    $key = chat_filter_word_key($token);
    // Три формы из-за повторов: «суууука» сводится к «сука», а «asss» к «ass».
    // Совсем без учёта двойных букв нельзя: запись «ass» задела бы слово «as».
    $forms = array_unique([
        $key,
        (string)preg_replace('/(.)\1+/u', '$1', $key),
        (string)preg_replace('/(.)\1{2,}/u', '$1$1', $key),
    ]);
    foreach ($forms as $form) {
        if (isset($rules['exact'][$form])) { return true; }
        foreach ($rules['prefix'] as $p) { if (strncmp($form, $p, strlen($p)) === 0) { return true; } }
        foreach ($rules['suffix'] as $s) { if (substr($form, -strlen($s)) === $s) { return true; } }
        foreach ($rules['part'] as $p)   { if (strpos($form, $p) !== false) { return true; } }
    }
    return false;
}

function chat_filter_words(string $text, array $rules): ?string {
    if (!$rules || (!$rules['exact'] && !$rules['prefix'] && !$rules['suffix'] && !$rules['part'])) { return $text; }

    // Вразрядку: «с у к а», «с.у.к.а».
    $text = preg_replace_callback(
        '/(?<![\p{L}\p{N}])\p{L}(?:[\s.\-_*,]+\p{L}(?![\p{L}\p{N}])){2,}/u',
        function (array $m) use ($rules): string {
            $joined = (string)preg_replace('/[\s.\-_*,]+/u', '', $m[0]);
            return chat_filter_word_hit($joined, $rules) ? CHAT_WORD_CUT : $m[0];
        },
        $text
    );
    if ($text === null) { return null; }

    // Метку ссылки пропускаем: слово «link» в списке не должно её портить.
    return preg_replace_callback(
        '/\[link\](*SKIP)(*F)|[\p{L}\p{N}@$]+/u',
        function (array $m) use ($rules): string {
            return chat_filter_word_hit($m[0], $rules) ? CHAT_WORD_CUT : $m[0];
        },
        $text
    );
}

/**
 * Что показать вместо текста. Возвращает ['text', 'link' => бул, 'word' => бул].
 * Ничего не нашлось — текст остаётся прежним до байта, в том числе широкие буквы.
 * Сломалась регулярка — ссылка считается найденной: лучше скрыть сообщение, чем
 * пропустить его непроверенным.
 */
function chat_filter_apply(string $text, array $rules): array {
    $clean = ['text' => $text, 'link' => false, 'word' => false];
    if (chat_sticker_id($text) !== '' || chat_photo_id($text) > 0) { return $clean; }

    $failed = ['text' => CHAT_LINK_CUT, 'link' => true, 'word' => false];
    $folded = chat_filter_fold($text);
    if ($folded === null) { return $failed; }
    $cut = chat_filter_links($folded);
    if ($cut === null) { return $failed; }
    $masked = chat_filter_words($cut, $rules);
    if ($masked === null) { return $failed; }

    if ($cut === $folded && $masked === $cut) { return $clean; }
    return ['text' => $masked, 'link' => $cut !== $folded, 'word' => $masked !== $cut];
}

/** Слова из базы. Таблицы может не быть до миграции — ссылки режутся и без неё. */
function chat_filter_rules(PDO $pdo): array {
    try {
        $words = $pdo->query('SELECT word FROM chat_filter_words')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $words = [];
    }
    return chat_filter_compile($words);
}

/** Сработало при отправке: в журнал для модераторов. Ошибка журнала не мешает сообщению. */
function chat_filter_log(PDO $pdo, int $messageId, string $senderId, array $seen, int $now): void {
    $kinds = array_keys(array_filter(['link' => $seen['link'], 'word' => $seen['word']]));
    if (!$kinds) { return; }
    try {
        $pdo->prepare('INSERT INTO chat_filter_hits (message_id, sender_id, kinds, created_at) VALUES (:m, :s, :k, :at)')
            ->execute([':m' => $messageId, ':s' => $senderId, ':k' => implode(',', $kinds), ':at' => $now]);
        $pdo->prepare('DELETE FROM chat_filter_hits WHERE created_at < :old')
            ->execute([':old' => $now - CHAT_FILTER_LOG_DAYS * 86400]);
    } catch (PDOException $e) {
        // таблицы ещё нет
    }
}

// --- панель: /admin/chat-filter ---------------------------------------------

function chat_filter_ready(PDO $pdo): bool {
    try {
        $pdo->query('SELECT 1 FROM chat_filter_words LIMIT 1');
        $pdo->query('SELECT 1 FROM chat_filter_hits LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function chat_filter_word_list(PDO $pdo): array {
    try {
        return $pdo->query('SELECT word FROM chat_filter_words ORDER BY word')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Добавить слова: через запятую или с новой строки. Возвращает код итога для
 * страницы. Проверка до записи: кривая запись не должна добавить половину списка.
 */
function chat_filter_add(PDO $pdo, string $raw, int $now): string {
    if (!chat_filter_ready($pdo)) { return 'not_ready'; }
    $words = [];
    foreach (preg_split('/[,\r\n]+/u', $raw) ?: [] as $part) {
        $word = mb_strtolower(trim($part));
        if ($word === '') { continue; }
        if (!preg_match(CHAT_FILTER_WORD_RE, $word)) { return 'bad_word'; }
        $words[$word] = true;
    }
    if (!$words) { return 'bad_word'; }

    $have = chat_filter_word_list($pdo);
    $new  = array_diff(array_map('strval', array_keys($words)), $have);
    if (count($have) + count($new) > CHAT_FILTER_WORDS_MAX) { return 'too_many'; }

    $ins = $pdo->prepare('INSERT INTO chat_filter_words (word, created_at) VALUES (:w, :at)');
    foreach ($new as $word) {
        try {
            $ins->execute([':w' => $word, ':at' => $now]);
        } catch (PDOException $e) {
            // Правила сравнения MySQL считают «ё» и «е» одной буквой: такая
            // запись уже есть, и второй не нужно.
        }
    }
    return 'added';
}

function chat_filter_remove(PDO $pdo, string $word): string {
    if (!chat_filter_ready($pdo)) { return 'not_ready'; }
    $pdo->prepare('DELETE FROM chat_filter_words WHERE word = :w')->execute([':w' => $word]);
    return 'removed';
}

/** Последние срабатывания с исходным текстом: страница показывает его модератору. */
function chat_filter_hits(PDO $pdo, int $limit = 100): array {
    try {
        $st = $pdo->query(
            'SELECT h.sender_id, h.kinds, h.created_at, m.body, u.display_name, u.username
               FROM chat_filter_hits h
               JOIN chat_messages m ON m.id = h.message_id
               LEFT JOIN users u ON u.roblox_id = h.sender_id
              ORDER BY h.id DESC LIMIT ' . (int)$limit
        );
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = (string)($r['display_name'] ?? '');
            $out[] = [
                'id'     => (string)$r['sender_id'],
                'nick'   => $name !== '' ? $name : (string)($r['username'] ?? ''),
                'handle' => (string)($r['username'] ?? ''),
                'kinds'  => explode(',', (string)$r['kinds']),
                'body'   => (string)$r['body'],
                'at'     => (int)$r['created_at'],
            ];
        }
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}
