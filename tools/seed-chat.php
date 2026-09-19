<?php
// Тестовые диалоги для /chat — ТОЛЬКО для локального стенда.
//
// Зачем скрипт, а не заглушка в коде: выдуманные переписки в исходниках уехали
// бы на бой вместе с файлом. Здесь фейк живёт в базе разработчика, а путь
// исполнения ровно тот же, что будет с настоящими сообщениями — тот же SELECT,
// тот же JSON, тот же рендер.
//
// tools/ не публикуется (.cpanel.yml копирует только public_html), так что на
// сервер скрипт не попадёт физически.
//
// Запуск из корня репозитория:
//   php tools/seed-chat.php               завести таблицы и набить диалоги
//   php tools/seed-chat.php --drop        снести таблицы чата и выйти
//   php tools/seed-chat.php --config=/path/to/config.php
//
// Пользователей берёт из users. Если там пусто — заводит шестерых с
// вымышленными roblox_id: настоящих взять неоткуда, пока вход через Roblox
// не настроен (в config.php нет client_id).

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$opts = getopt('', ['drop', 'config::']);
if (isset($opts['config'])) { define('CONFIG_PATH', (string)$opts['config']); }
require_once __DIR__ . '/../public_html/api/_bootstrap.php';
require_once __DIR__ . '/../public_html/api/lib/chat.php';

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Не подключиться к базе: " . $e->getMessage() . "\n");
    exit(1);
}

if (array_key_exists('drop', $opts)) {
    foreach (['chat_reviews', 'chat_messages', 'chat_threads'] as $t) {
        $pdo->exec("DROP TABLE IF EXISTS $t");
    }
    echo "Таблицы чата удалены — страница снова покажет пустое состояние.\n";
    exit(0);
}

// Схема повторяет docs/migrations/2026-09-09-chat.sql.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS chat_threads (
       id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
       a_id    BIGINT UNSIGNED NOT NULL,
       b_id    BIGINT UNSIGNED NOT NULL,
       last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
       UNIQUE KEY uniq_pair (a_id, b_id),
       KEY idx_recent (last_at)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS chat_messages (
       id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
       thread_id  INT UNSIGNED    NOT NULL,
       sender_id  BIGINT UNSIGNED NOT NULL,
       body       TEXT            NOT NULL,
       created_at BIGINT UNSIGNED NOT NULL,
       KEY idx_thread (thread_id, id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS chat_reviews (
       id         INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
       thread_id  INT UNSIGNED     NOT NULL,
       author_id  BIGINT UNSIGNED  NOT NULL,
       target_id  BIGINT UNSIGNED  NOT NULL,
       stars      TINYINT UNSIGNED NOT NULL,
       body       TEXT,
       created_at BIGINT UNSIGNED  NOT NULL,
       UNIQUE KEY uniq_author_thread (thread_id, author_id),
       KEY idx_target (target_id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Колонки репутации — из той же миграции. Добавляем по одной и молча
// пропускаем уже существующую: скрипт обязан переживать повторный запуск.
foreach (['likes', 'dislikes'] as $col) {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN $col INT UNSIGNED NOT NULL DEFAULT 0"); }
    catch (PDOException $e) { /* колонка уже есть */ }
}

$now  = time();
$have = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($have === 0) {
    // Вымышленные roblox_id из диапазона, который Roblox не выдаёт: настоящие
    // id — это claim `sub` живого игрока, и подставлять чужой номер нельзя.
    $people = [
        ['900000001', 'mksvtn',   'MKSVTN',   0],
        ['900000002', 'daniktor', 'DANIKTOR', 30],
        ['900000003', 'glh',      'GLH',      120],
        ['900000004', 'thefool',  'The Fool', 90000],
        ['900000005', 'netfruit', 'NETFRUIT', 45],
        ['900000006', 'magnetik', 'MAGNETIK', 200000],
    ];
    $ins = $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                          VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($people as $p) {
        $ins->execute([$p[0], $p[1], $p[2], '', $now - 86400 * 30, $now - $p[3]]);
    }
    echo "Заведено пользователей: " . count($people) . "\n";
}

$ids = $pdo->query('SELECT roblox_id FROM users ORDER BY roblox_id LIMIT 6')->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) < 2) {
    fwrite(STDERR, "В users меньше двух человек — диалог не из кого составить.\n");
    exit(1);
}
$me = (string)$ids[0];

$pdo->exec('DELETE FROM chat_reviews');
$pdo->exec('DELETE FROM chat_messages');
$pdo->exec('DELETE FROM chat_threads');

$talks = [
    [1, [[0, 'Привет! Меняю Дракона на Лео + доплата, интересно?'],
         [1, 'Привет. Лео есть, доплату чем закроешь?'],
         [0, 'Могу перманентом или робуксами по тирлисту.'],
         [1, 'Давай перманент. Кидай трейд.'],
         [0, 'Кинул, проверяй.'],
         [1, 'Принял, спасибо! Всё честно.']], 5],
    [2, [[0, 'Сколько сейчас стоит Мамонт по вашему тирлисту?'],
         [1, 'Смотри раздел «Тирлист», обновляли вчера.'],
         [0, 'Понял, спасибо.']], 4],
    [3, [[1, 'Нашёл баг: калькулятор не считает мутации.'],
         [0, 'Спасибо, чиню.'],
         [1, 'Обновил — работает.']], 5],
    [4, [[0, 'Меняю Спирит на Кицуне.'],
         [1, 'Не равноценно, добавляй геймпасс.'],
         [0, 'Тогда нет, спасибо.']], 3],
    [5, [[1, 'Ты кинул меня на трейде.'],
         [0, 'Разберёмся, напиши в центр обращений.']], 1],
];

$insT = $pdo->prepare('INSERT INTO chat_threads (a_id, b_id, last_at) VALUES (?, ?, ?)');
$insM = $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, ?)');
$insR = $pdo->prepare('INSERT INTO chat_reviews (thread_id, author_id, target_id, stars, body, created_at) VALUES (?, ?, ?, ?, ?, ?)');

$msgN = 0;
$made = 0;
foreach ($talks as $i => [$peerIdx, $lines, $stars]) {
    if (!isset($ids[$peerIdx])) { continue; }
    $peer = (string)$ids[$peerIdx];
    [$a, $b] = chat_pair($me, $peer);
    // Свежие диалоги сверху: чем меньше индекс, тем недавнее.
    $base = $now - ($i * 7200) - 600;
    $insT->execute([$a, $b, $base + count($lines) * 60]);
    $tid = (int)$pdo->lastInsertId();
    $made++;

    foreach ($lines as $j => [$who, $text]) {
        $insM->execute([$tid, $who === 0 ? $me : $peer, $text, $base + $j * 60]);
        $msgN++;
    }
    // Отзыв ставит собеседник МНЕ — иначе репутация осталась бы нулевой.
    $insR->execute([$tid, $peer, $me, $stars, '', $base + 3600]);
}

// Репутацию считает тот же код, что и на бою: сеялка не должна расходиться с
// рантаймом.
chat_recount_reputation($pdo, $me);
$r = $pdo->prepare('SELECT display_name, likes, dislikes FROM users WHERE roblox_id = ?');
$r->execute([$me]);
$u = $r->fetch(PDO::FETCH_ASSOC);

printf("Диалогов: %d, сообщений: %d, отзывов: %d\n", $made, $msgN, $made);
printf("Репутация %s: +%d / -%d\n", $u['display_name'], $u['likes'], $u['dislikes']);
echo "«Я» в чате — roblox_id $me. Чтобы увидеть переписку, нужно войти этим\n";
echo "пользователем: без сессии страница честно предложит вход через Roblox.\n";
