<?php
// Тестовые сделки для графика в профиле — ТОЛЬКО для локального стенда.
//
// Зачем отдельный скрипт, а не заглушка в коде. Сделок в системе нет, и
// api/profile-stats.php на боевой базе честно отвечает available:false —
// страница рисует пустое состояние. Чтобы посмотреть график живым, нужны
// данные; класть их в код нельзя (выдуманные цифры уехали бы на бой вместе с
// файлом), поэтому фейк живёт в базе разработчика. Путь исполнения при этом
// ровно тот же, что будет на бою с настоящими сделками: тот же SELECT, тот
// же JSON, тот же рендер.
//
// Скрипт лежит в tools/ — эта папка не публикуется (.cpanel.yml копирует
// только public_html/), так что на сервер он не попадёт физически.
//
// Сделки заводятся КАЖДОМУ, кто есть в users, — включая вас, если вы уже
// входили на стенде через Roblox. Профиль показывает статистику вошедшего, и
// строки без владельца он не увидит вовсе.
//
// Запуск из корня репозитория:
//   php tools/seed-profile-stats.php              6 месяцев данных
//   php tools/seed-profile-stats.php --months=12  глубже история
//   php tools/seed-profile-stats.php --drop       снести таблицу и выйти
//   php tools/seed-profile-stats.php --config=/path/to/config.php
//
// Повторный запуск перезаписывает данные, а не накапливает их.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$opts    = getopt('', ['months::', 'drop', 'config::', 'seed::']);
$months  = isset($opts['months']) ? max(1, min(36, (int)$opts['months'])) : 6;
$drop    = array_key_exists('drop', $opts);

if (isset($opts['config'])) { define('CONFIG_PATH', (string)$opts['config']); }
require_once __DIR__ . '/../public_html/api/_bootstrap.php';

// Ряд воспроизводимый: без фиксированного зерна каждый запуск давал бы другой
// график, и «а вчера линия шла иначе» превращалось бы в отладку призрака.
mt_srand(isset($opts['seed']) ? (int)$opts['seed'] : 20260908);

try {
    $pdo = db();
} catch (Throwable $e) {
    fwrite(STDERR, "Не подключиться к базе: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Проверьте config.php или передайте --config=/path/to/config.php\n");
    exit(1);
}

if ($drop) {
    $pdo->exec('DROP TABLE IF EXISTS profile_trades');
    // Наследство прежней версии сеялки: карточка профиля жила отдельной
    // таблицей, пока на сайте не было аккаунтов. Теперь ник, аватар и статус
    // приезжают из users, и эта таблица только путала бы.
    $pdo->exec('DROP TABLE IF EXISTS profile_card');
    echo "Таблица profile_trades удалена — профиль снова покажет пустое состояние.\n";
    exit(0);
}
$pdo->exec('DROP TABLE IF EXISTS profile_card');

// «О себе» из миграции docs/migrations/2026-09-09-profile.sql. Заводим по
// одной колонке и молча пропускаем уже существующую: скрипт обязан переживать
// повторный запуск.
try { $pdo->exec("ALTER TABLE users ADD COLUMN about VARCHAR(280) NULL DEFAULT NULL"); }
catch (PDOException $e) { /* колонка уже есть */ }

$now = time();
$ids = $pdo->query('SELECT roblox_id FROM users ORDER BY roblox_id')->fetchAll(PDO::FETCH_COLUMN);

if (!$ids) {
    // Вымышленный roblox_id из диапазона, который Roblox не выдаёт: настоящий
    // id — это claim `sub` живого игрока, и подставлять чужой номер нельзя.
    $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['900000001', 'mksvtn', 'MKSVTN', '', $now - 86400 * 30, $now]);
    $ids = ['900000001'];
    echo "В users было пусто — заведён один пользователь mksvtn (roblox_id 900000001).\n";
}
$ids = array_slice(array_map('strval', $ids), 0, 10);

$about = 'Торгую фруктами с 2024 года. Пишите в чат — отвечаю быстро, '
       . 'мутации и перманенты меняю только по тирлисту.';
$setAbout = $pdo->prepare('UPDATE users SET about = ? WHERE roblox_id = ? AND (about IS NULL OR about = ?)');
foreach ($ids as $id) { $setAbout->execute([$about, $id, '']); }

// Схема здесь MySQL-ная (AUTO_INCREMENT, KEY, ENGINE) — сеялка работает с
// локальной базой разработчика, а она MySQL, как и бой. На SQLite этот
// скрипт не рассчитан и не должен быть: зеркало той же схемы на диалекте
// SQLite живёт в tests/profile_stats_test.php, где тесты и гоняются.
//
// Общее у обеих схем — типы колонок, по которым ходят запросы обработчика:
// day хранится строкой-датой, чтобы сравнение границ месяца работало
// одинаково в обоих движках (см. handle_profile_stats), а status обычной
// строкой, а не ENUM.
//
// user_id — roblox_id владельца сделки. Без него профиль показывал бы каждому
// вошедшему одну и ту же чужую статистику.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS profile_trades (
       id      INTEGER PRIMARY KEY AUTO_INCREMENT,
       user_id BIGINT UNSIGNED NOT NULL,
       day     DATE        NOT NULL,
       status  VARCHAR(8)  NOT NULL,
       value   INT UNSIGNED NOT NULL DEFAULT 0,
       KEY idx_user_day (user_id, day)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Таблица могла остаться от прежней версии сеялки — без user_id. Тогда её
// проще завести заново, чем чинить: данные в ней всё равно выдуманные.
try {
    $pdo->query('SELECT user_id FROM profile_trades LIMIT 1');
} catch (PDOException $e) {
    $pdo->exec('DROP TABLE profile_trades');
    $pdo->exec(
        "CREATE TABLE profile_trades (
           id      INTEGER PRIMARY KEY AUTO_INCREMENT,
           user_id BIGINT UNSIGNED NOT NULL,
           day     DATE        NOT NULL,
           status  VARCHAR(8)  NOT NULL,
           value   INT UNSIGNED NOT NULL DEFAULT 0,
           KEY idx_user_day (user_id, day)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Таблица profile_trades была без user_id — заведена заново.\n";
}
$pdo->exec('DELETE FROM profile_trades');

$ins = $pdo->prepare('INSERT INTO profile_trades (user_id, day, status, value) VALUES (:u, :d, :s, :v)');

$today = new DateTimeImmutable('today');
$rows  = 0;

$pdo->beginTransaction();
foreach ($ids as $u => $uid) {
    // Каждому свой темп: одинаковые графики у всех читались бы как заглушка.
    $own = 0.5 + (($u % 4) * 0.35);

    for ($back = $months - 1; $back >= 0; $back--) {
        $monthStart = $today->modify("first day of -$back month");
        $daysIn     = (int)$monthStart->format('t');
        // В текущем месяце будущих дней быть не должно — иначе график рисовал
        // бы сделки, которых ещё не случилось.
        $lastDay = ($back === 0) ? (int)$today->format('j') : $daysIn;

        // Активность гуляет по месяцам: ровный шум читался бы как заглушка, а
        // не как поведение живого трейдера.
        $tempo = (0.4 + (mt_rand(0, 100) / 100) * 1.4) * $own;

        for ($d = 1; $d <= $lastDay; $d++) {
            $date = $monthStart->modify('+' . ($d - 1) . ' day')->format('Y-m-d');

            // Выходные оживлённее — игровые сделки идут, когда люди не на учёбе.
            $dow  = (int)$monthStart->modify('+' . ($d - 1) . ' day')->format('N');
            $peak = ($dow >= 6) ? 1.7 : 1.0;

            $ok = (int)round(mt_rand(0, 9) * $tempo * $peak);
            $no = (int)round(mt_rand(0, 3) * $tempo);
            if ($ok === 0 && $no === 0) { continue; }   // день без сделок — нормальный день

            for ($i = 0; $i < $ok; $i++) {
                $ins->execute([':u' => $uid, ':d' => $date, ':s' => 'ok', ':v' => mt_rand(50, 9000)]);
                $rows++;
            }
            for ($i = 0; $i < $no; $i++) {
                $ins->execute([':u' => $uid, ':d' => $date, ':s' => 'declined', ':v' => 0]);
                $rows++;
            }
        }
    }
}
$pdo->commit();

printf("Засеяно %d строк за %d мес. на %d пользователей.\n", $rows, $months, count($ids));
foreach ($ids as $uid) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM profile_trades WHERE user_id = ? AND status = 'ok'");
    $q->execute([$uid]);
    $okN = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM profile_trades WHERE user_id = ? AND status = 'ok'");
    $q->execute([$uid]);
    printf("  %s: успешных %d, оборот %s\n", $uid, $okN, number_format((int)$q->fetchColumn(), 0, '.', ' '));
}
echo "Войдите на стенде и откройте /profile — график покажет данные вашего аккаунта.\n";
echo "Убрать: php tools/seed-profile-stats.php --drop\n";
