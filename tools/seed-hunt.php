<?php
// Демо-сезон ивента «Тыквенная охота» — ТОЛЬКО для локального стенда.
//
// Зачем. Админки ивента ещё нет (PR B), а странице /halloween, индикатору и
// секреткам (PR C) нужны живые данные: сезон, главы, задания всех типов,
// секретки на нескольких страницах. Класть их в код нельзя — выдуманный сезон
// уехал бы на бой, — поэтому они живут в базе разработчика. Путь исполнения
// тот же, что на бою: те же таблицы, те же api/hunt*.php.
//
// Скрипт работает только с SQLite: боевая MySQL-база им не трогается вовсе.
// tools/ не публикуется (.cpanel.yml копирует только public_html/).
//
// Запуск из корня репозитория:
//   php tools/seed-hunt.php                  завести таблицы и демо-сезон
//   php tools/seed-hunt.php --phase=soon     сезон ещё не начался
//   php tools/seed-hunt.php --phase=closed   приём закрыт, ждём итогов
//   php tools/seed-hunt.php --drop           очистить таблицы ивента и выйти
//   php tools/seed-hunt.php --config=/path/to/config.php
//
// Повторный запуск заменяет сезон, а не копит их.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$opts  = getopt('', ['drop', 'config::', 'phase::']);
$phase = isset($opts['phase']) ? (string)$opts['phase'] : 'open';
if (!in_array($phase, ['open', 'soon', 'closed'], true)) {
    fwrite(STDERR, "--phase: open | soon | closed\n");
    exit(1);
}
if (isset($opts['config'])) { define('CONFIG_PATH', (string)$opts['config']); }
require_once __DIR__ . '/../public_html/api/_bootstrap.php';
require_once __DIR__ . '/../tests/hunt_schema.php';

$pdo = db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
    fwrite(STDERR, "Только для SQLite-стенда: config.php смотрит не в SQLite.\n");
    exit(1);
}
hunt_sqlite_tables($pdo);
$tables = ['hunts', 'hunt_chapters', 'hunt_tasks', 'hunt_secrets', 'hunt_wallets', 'hunt_ledger',
           'hunt_tickets', 'hunt_wins', 'hunt_stats'];
foreach ($tables as $t) { $pdo->exec("DELETE FROM $t"); }
if (array_key_exists('drop', $opts)) {
    echo "Таблицы ивента очищены.\n";
    exit(0);
}

$now = time();
$day = 86400;
$starts = $phase === 'soon' ? $now + 2 * $day : $now - 3600;
$salesEnd = $phase === 'closed' ? $now - 60 : $starts + 14 * $day;

function seed_insert(PDO $pdo, string $table, array $row): int {
    $cols = array_keys($row);
    $pdo->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    return (int)$pdo->lastInsertId();
}

function seed_quiz(string $qRu, string $qEn, array $options, int $correct, string $hintRu, string $hintEn, string $exRu, string $exEn): string {
    $opts = [];
    foreach ($options as [$ru, $en]) { $opts[] = ['ru' => $ru, 'en' => $en]; }
    return json_encode(['q_ru' => $qRu, 'q_en' => $qEn, 'options' => $opts, 'correct' => $correct,
        'hint_ru' => $hintRu, 'hint_en' => $hintEn, 'explain_ru' => $exRu, 'explain_en' => $exEn], JSON_UNESCAPED_UNICODE);
}

$hunt = seed_insert($pdo, 'hunts', [
    'slug' => 'halloween-demo', 'status' => 'live',
    'title_ru' => 'Тыквенная охота', 'title_en' => 'Pumpkin Hunt',
    'rules_ru' => "Тыковки дают за задания и находки. 50 тыковок — билет главного розыгрыша, до 3 билетов на человека.\n"
        . "Все билеты равны. Один человек получает не больше одного приза.\n"
        . "Победителей выберет случайная перетасовка всех билетов после конца приёма.\n"
        . "На подтверждение выигрыша — 7 дней, потом приз переходит следующему по очереди.",
    'rules_en' => "Earn tokens for tasks and finds. 50 tokens buy a ticket to the main draw, up to 3 tickets per person.\n"
        . "All tickets are equal. One person wins at most one prize.\n"
        . "Winners are picked by a random shuffle of all tickets after entries close.\n"
        . "You have 7 days to confirm a win, then the prize passes to the next ticket in line.",
    'starts_at' => $starts, 'sales_end_at' => $salesEnd, 'draw_at' => $salesEnd + $day,
    'ticket_price' => 50, 'ticket_limit' => 3, 'claim_days' => 7,
    'prizes_json' => json_encode([
        ['name' => 'Kitsune', 'count' => 1, 'icon' => ''],
        ['name' => 'Dragon', 'count' => 2, 'icon' => ''],
        ['name' => 'Chromatic Box', 'count' => 5, 'icon' => ''],
    ]),
    'milestones_json' => json_encode([
        ['at' => 50, 'title_ru' => 'Новичок', 'title_en' => 'Rookie'],
        ['at' => 150, 'title_ru' => 'Охотник', 'title_en' => 'Hunter'],
        ['at' => 400, 'title_ru' => 'Тыквенный мастер', 'title_en' => 'Pumpkin Master'],
    ], JSON_UNESCAPED_UNICODE),
    'created_at' => $now, 'updated_at' => $now,
]);

foreach ([
    [1, 'Знакомство', 'Introduction', 'Тыквы проснулись и разбежались по сайту. Найди первую — она совсем рядом.',
        'The pumpkins woke up and scattered across the site. Find the first one — it is close by.', $starts],
    [2, 'Исследование', 'Exploration', 'Призраки спрятали подсказки в разделах сайта.',
        'Ghosts hid clues across the site.', $starts + 7 * $day],
    [3, 'Финальная разгадка', 'The final riddle', 'Последняя загадка ждёт тех, кто собрал все подсказки.',
        'The last riddle awaits those who gathered every clue.', $starts + 12 * $day],
] as [$n, $tRu, $tEn, $sRu, $sEn, $at]) {
    seed_insert($pdo, 'hunt_chapters', ['hunt_id' => $hunt, 'n' => $n, 'title_ru' => $tRu, 'title_en' => $tEn,
        'story_ru' => $sRu, 'story_en' => $sEn, 'opens_at' => $at]);
}

$channel = null;
try { $channel = $pdo->query('SELECT chat_id FROM tg_channels WHERE is_admin = 1 LIMIT 1')->fetchColumn() ?: null; }
catch (PDOException $e) { $channel = null; }

$sort = 0;
$task = function (array $row) use ($pdo, $hunt, $now, &$sort): int {
    $sort += 10;
    return seed_insert($pdo, 'hunt_tasks', array_merge([
        'hunt_id' => $hunt, 'chapter' => 1, 'sort' => $sort, 'kind' => 'link', 'level' => 'easy',
        'period' => 'once', 'lang' => 'all', 'grp' => '', 'reward' => 10, 'body_ru' => '', 'body_en' => '',
        'created_at' => $now, 'updated_at' => $now,
    ], $row));
};

$task(['kind' => 'quiz', 'level' => 'intro', 'reward' => 25,
    'title_ru' => 'Как устроена охота', 'title_en' => 'How the hunt works',
    'cfg_json' => seed_quiz('На что меняются тыковки?', 'What can you exchange tokens for?',
        [['На Robux', 'Robux'], ['На билеты розыгрыша', 'Draw tickets'], ['На фрукты напрямую', 'Fruits directly']], 1,
        'Посмотри на строку под заголовком страницы', 'Look at the line under the page title',
        'Тыковки меняются на билеты главного розыгрыша: 50 тыковок — один билет.',
        'Tokens buy tickets to the main draw: 50 tokens per ticket.')]);
$task(['kind' => 'quiz', 'level' => 'easy', 'reward' => 15,
    'title_ru' => 'Вопрос о тирлисте', 'title_en' => 'Tier list question',
    'cfg_json' => seed_quiz('Где на сайте видно, какие фрукты сейчас ценятся выше?', 'Where on the site can you see which fruits are valued higher?',
        [['В тирлисте', 'In the tier list'], ['В чате', 'In the chat'], ['В профиле', 'In the profile']], 0,
        'Он на главной первым пунктом', 'It is the first card on the home page',
        'Тирлист раскладывает фрукты по тирам ценности и обновляется вместе с рынком.',
        'The tier list ranks fruits by value and follows the market.')]);
$task(['kind' => 'visit', 'level' => 'easy', 'period' => 'daily', 'reward' => 10,
    'title_ru' => 'Посчитай трейд', 'title_en' => 'Check a trade',
    'body_ru' => 'Загляни в калькулятор трейдов', 'body_en' => 'Visit the trade calculator',
    'cfg_json' => json_encode(['page' => 'calc'])]);
$task(['kind' => 'visit', 'level' => 'easy', 'reward' => 10,
    'title_ru' => 'Что в стоке', 'title_en' => 'What is in stock',
    'cfg_json' => json_encode(['page' => 'stock'])]);
$task(['kind' => 'link', 'level' => 'easy', 'lang' => 'ru', 'grp' => 'news', 'reward' => 5,
    'title_ru' => 'Загляни в канал новостей', 'title_en' => 'Visit the news channel',
    'cfg_json' => json_encode(['url' => 'https://t.me/maknemy'])]);
$task(['kind' => 'link', 'level' => 'easy', 'lang' => 'en', 'grp' => 'news', 'reward' => 5,
    'title_ru' => 'Канал новостей (EN)', 'title_en' => 'Visit the news channel',
    'cfg_json' => json_encode(['url' => 'https://t.me/maknemy_en'])]);
if ($channel !== null) {
    $task(['kind' => 'tg', 'level' => 'easy', 'reward' => 30,
        'title_ru' => 'Подпишись на канал', 'title_en' => 'Join the channel',
        'cfg_json' => json_encode(['channel' => (int)$channel])]);
}
$task(['kind' => 'quiz', 'level' => 'medium', 'reward' => 30,
    'title_ru' => 'Загадка калькулятора', 'title_en' => 'Calculator riddle',
    'cfg_json' => seed_quiz('Что показывает калькулятор, если обе стороны трейда равны?', 'What does the calculator show when both sides are equal?',
        [['Выгодно', 'Win'], ['Честно', 'Fair'], ['Невыгодно', 'Loss']], 1,
        'Сложи пару одинаковых фруктов с обеих сторон', 'Put the same fruit on both sides',
        'Равные стороны — честный трейд.', 'Equal sides mean a fair trade.')]);
$task(['kind' => 'quiz', 'level' => 'hard', 'chapter' => 2, 'reward' => 50,
    'title_ru' => 'Сложная загадка', 'title_en' => 'Hard riddle',
    'cfg_json' => seed_quiz('Вопрос второй главы', 'Chapter two question', [['А', 'A'], ['Б', 'B']], 0, '', '', '', '')]);

$secret = function (array $row) use ($pdo, $hunt, $now): int {
    return seed_insert($pdo, 'hunt_secrets', array_merge([
        'hunt_id' => $hunt, 'chapter' => 1, 'sort' => 0, 'sprite' => 'pumpkin', 'level' => 'easy',
        'period' => 'once', 'reward' => 15, 'created_at' => $now, 'updated_at' => $now,
    ], $row));
};
$secret(['page' => 'halloween', 'spot' => 'intro', 'reward' => 25,
    'hint_ru' => 'Первая тыква — прямо на странице охоты', 'hint_en' => 'The first pumpkin is right on the hunt page',
    'found_ru' => 'Первая тыква твоя! Ответь на вопрос ниже — и хватит на билет.',
    'found_en' => 'Your first pumpkin! Answer the question below and you will have enough for a ticket.']);
$secret(['page' => 'home', 'spot' => 'head', 'sprite' => 'ghost',
    'hint_ru' => 'Призрак любит первые экраны', 'hint_en' => 'The ghost loves first screens',
    'found_ru' => 'Бу! Следующего ищи там, где фрукты стоят по тирам.',
    'found_en' => 'Boo! Look for the next one where fruits are ranked.']);
$secret(['page' => 'tierlist', 'spot' => 'foot', 'sprite' => 'candle', 'level' => 'hint',
    'hint_ru' => 'Свеча горит под самым низким тиром', 'hint_en' => 'A candle burns below the lowest tier',
    'found_ru' => 'Свеча зажжена. Летучая мышь считает трейды.', 'found_en' => 'Candle lit. A bat is counting trades.']);
$secret(['page' => 'calc', 'spot' => 'head', 'sprite' => 'bat', 'level' => 'hint', 'reward' => 20,
    'hint_ru' => 'Летучая мышь считает трейды', 'hint_en' => 'A bat is counting trades',
    'found_ru' => 'Мышь поймана! Череп читает свежие новости.', 'found_en' => 'Bat caught! A skull reads the latest news.']);
$secret(['page' => 'news', 'spot' => 'foot', 'sprite' => 'skull', 'level' => 'riddle', 'period' => 'daily', 'reward' => 10,
    'hint_ru' => 'Череп читает новости каждый день', 'hint_en' => 'The skull reads the news every day',
    'found_ru' => 'Завтра череп снова будет здесь.', 'found_en' => 'The skull will be back tomorrow.']);
$secret(['page' => 'stock', 'spot' => 'foot', 'sprite' => 'ghost', 'chapter' => 2, 'reward' => 30,
    'hint_ru' => 'Откроется во второй главе', 'hint_en' => 'Opens in chapter two']);

printf("Сезон #%d заведён (%s): задания — %d, секретки — %d.%s\n", $hunt, $phase,
    (int)$pdo->query('SELECT COUNT(*) FROM hunt_tasks')->fetchColumn(),
    (int)$pdo->query('SELECT COUNT(*) FROM hunt_secrets')->fetchColumn(),
    $channel === null ? ' Канала с ботом нет — задания-подписки не заведено.' : '');
