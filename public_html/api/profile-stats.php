<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/profile.php';

// Статистика сделок для страницы профиля —
// /api/profile-stats.php?month=YYYY-MM&id=<roblox_id>
//
// Карточку (ник, аватар, статус, репутация, «о себе») эндпоинт НЕ отдаёт:
// её печатает сам profile.php из той же users, пока собирает страницу. Так у
// карточки один источник вместо двух и нет промежуточного состояния, в
// котором половина полей ещё подписи-заглушки. Здесь остаются только цифры
// графика — их и правда незачем гнать в разметку, их количество зависит от
// выбранного месяца.
//
// ?id= — чей профиль смотрим; без него свой. Смотреть чужой можно только
// вошедшему: заводить аккаунт всё равно придётся, чтобы торговать, а
// открытый список профилей — это приглашение выкачать его целиком.
// Аноним получает authed:false и предложение войти.
//
// Источник сделок — таблица profile_trades. В боевой базе её НЕТ и пока не
// будет: сделки на сайте не заводятся (ни одного места, которое их пишет, в
// коде нет). Поэтому эндпоинт устроен так, что отсутствие таблицы — не
// ошибка, а штатный ответ available:false, по которому страница рисует пустое
// состояние. Тот же приём, что у api/promo.php с таблицей promo.
//
// Локально таблицу заводит tools/seed-profile-stats.php: он создаёт её и
// набивает выдуманными строками, чтобы график можно было посмотреть живым.
// Фейк живёт ТОЛЬКО в базе разработчика — в коде здесь ни одной выдуманной
// цифры, и путь исполнения ровно тот же, что будет на бою с настоящими
// сделками.

// Пределы. Месяцев назад больше двух лет не отдаём: график в макете
// помесячный, а история глубже этого никому не нужна и превращает
// пользовательский ?month= в способ гонять базу произвольными запросами.
const PROFILE_STATS_MIN_MONTH = '2024-01';

// Разбор ?month=. Строгий: YYYY-MM и настоящая дата, иначе откат на текущий
// месяц. Подставлять непроверенное в запрос нельзя, а молча принимать
// «2026-13» — значит рисовать пустой график и оставлять человека гадать.
//
// Якорь \z, а НЕ $. В PCRE $ совпадает и перед завершающим переводом строки,
// поэтому «2026-07\n» проходил проверку целиком, доезжал до запроса как
// '2026-07\n-01' и ронял эндпоинт необработанным PDOException — с полным
// путём к файлу в трейсе. \z — единственный якорь, который значит «конец
// строки и ничего больше».
function profile_stats_month(?string $raw, string $today): string {
    if ($raw === null || $raw === '') { return substr($today, 0, 7); }
    if (!preg_match('/^(\d{4})-(\d{2})\z/', $raw, $m)) { return substr($today, 0, 7); }
    $y = (int)$m[1]; $mo = (int)$m[2];
    if ($mo < 1 || $mo > 12) { return substr($today, 0, 7); }
    if ($raw < PROFILE_STATS_MIN_MONTH || $raw > substr($today, 0, 7)) { return substr($today, 0, 7); }
    return $raw;
}

function profile_stats_days_in_month(string $month): int {
    [$y, $m] = array_map('intval', explode('-', $month));
    return (int)date('t', mktime(0, 0, 0, $m, 1, $y));
}

// Есть ли таблица сделок — и та ли это таблица. Пробуем именно user_id:
// сделки без владельца остались от версии, писавшейся до появления
// аккаунтов, и показывать их вошедшему как свои было бы враньём. Такая
// таблица читается как «сделок нет», а не роняет ответ; заводится заново
// сеялкой.
//
// information_schema и SHOW TABLES по-разному доступны в MySQL и SQLite
// (тесты гоняются на SQLite), поэтому путь самый переносимый — обычный
// SELECT, и ловим исключение.
function profile_stats_table_exists(PDO $pdo): bool {
    try {
        $pdo->query('SELECT user_id FROM profile_trades LIMIT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

// Пустой каркас месяца. Строится ВСЕГДА и до похода в базу: у графика по оси
// X все дни месяца, включая те, в которые сделок не было. Без этого линия
// склеивала бы 3-е число с 17-м и врала о плотности.
function profile_stats_empty_days(int $days): array {
    $series = [];
    for ($d = 1; $d <= $days; $d++) {
        $series[] = ['day' => $d, 'ok' => 0, 'declined' => 0, 'sum' => 0];
    }
    return $series;
}

// Ответ анониму и тому, чья сессия ссылается на исчезнувшего пользователя.
// Каркас месяца в нём тот же, что у вошедшего: страница не должна знать два
// разных формата ответа, иначе гейт и график разъедутся при первой правке.
function profile_stats_guest(string $month, int $days, int $lastDay): array {
    return [200, [
        'ok'        => true,
        'authed'    => false,
        'available' => false,
        'month'     => $month,
        'lastDay'   => $lastDay,
        'days'      => profile_stats_empty_days($days),
        'totals'    => ['ok' => 0, 'declined' => 0, 'sum' => 0, 'scale' => 0],
        'lifetime'  => ['ok' => 0, 'declined' => 0, 'total' => 0],
        'months'    => [],
    ]];
}

function handle_profile_stats(PDO $pdo, array $session, array $get, ?string $monthRaw, string $today, int $now): array {
    $month = profile_stats_month($monthRaw, $today);
    $days  = profile_stats_days_in_month($month);

    // Последний день, про который вообще есть что сказать. В текущем месяце
    // это сегодня: рисовать будущие числа нулями нельзя — линия падала бы на
    // ноль и шла плашмя до конца месяца, а читалось бы это как «сделок не
    // было», хотя дни просто не наступили. Ось X при этом остаётся во весь
    // месяц, чтобы было видно, где мы в нём находимся.
    $lastDay = (substr($today, 0, 7) === $month) ? (int)substr($today, 8, 2) : $days;

    $me = profile_me($session);
    if ($me === '') { return profile_stats_guest($month, $days, $lastDay); }

    // Таблицы users может не быть вовсе (не выполнен schema.sql) — сайт от
    // этого не падает, а профиль честно отвечает «не вошли», как и шапка.
    try {
        $known = profile_exists($pdo, $me);
    } catch (PDOException $e) {
        $known = false;
    }
    if (!$known) { return profile_stats_guest($month, $days, $lastDay); }

    // Чей профиль смотрим. Проверять существование ЦЕЛИ незачем: у
    // несуществующего человека сделок всё равно нет, и ответ выйдет пустым
    // сам собой. О том, что профиля нет, сообщает страница — она это уже
    // выяснила, когда печатала карточку.
    $target = profile_target($get);
    $who    = $target !== '' ? $target : $me;

    $series = profile_stats_empty_days($days);

    if (!profile_stats_table_exists($pdo)) {
        return [200, [
            'ok'        => true,
            'authed'    => true,
            'available' => false,
            'month'     => $month,
            'lastDay'   => $lastDay,
            'days'      => $series,
            'totals'    => ['ok' => 0, 'declined' => 0, 'sum' => 0, 'scale' => 0],
            'lifetime'  => ['ok' => 0, 'declined' => 0, 'total' => 0],
            'months'    => [],
        ]];
    }

    // Границы месяца считаем в PHP и передаём параметрами: строковое
    // сравнение дат работает одинаково в MySQL и SQLite, в отличие от
    // YEAR()/strftime().
    $from = $month . '-01';
    $to   = sprintf('%s-%02d', $month, $days);

    $stmt = $pdo->prepare(
        'SELECT day, status, COUNT(*) AS n, SUM(value) AS v
           FROM profile_trades
          WHERE user_id = :me AND day >= :from AND day <= :to
       GROUP BY day, status'
    );
    $stmt->execute([':me' => $who, ':from' => $from, ':to' => $to]);

    $totalOk = 0; $totalNo = 0; $totalSum = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = (int)substr((string)$row['day'], 8, 2);
        if ($d < 1 || $d > $days) { continue; }
        $n = (int)$row['n'];
        $v = (int)$row['v'];
        if ((string)$row['status'] === 'ok') {
            $series[$d - 1]['ok'] += $n;
            // Оборот считаем только по состоявшимся сделкам: отменённая не
            // принесла ничего, и включать её сумму — приписывать оборот.
            $series[$d - 1]['sum'] += $v;
            $totalOk  += $n;
            $totalSum += $v;
        } else {
            // += , а не =. Группировка идёт по паре (день, статус), и всё, что
            // не 'ok', попадает сюда несколькими строками, если статусов
            // когда-нибудь станет больше двух. Присваивание оставляло бы в дне
            // только последнюю группу, тогда как итог ниже уже накапливал, —
            // и день расходился бы с месяцем.
            $series[$d - 1]['declined'] += $n;
            $totalNo += $n;
        }
    }

    // Шкала полосы оборота — лучший месяц за всю историю. Ноль означает, что
    // сделок нет вовсе; в этом случае полосу нечем масштабировать, и клиент
    // её не рисует. В макете под полосой стояли «0.3» и «180000» без
    // объяснения, что это; здесь границы — настоящие: 0 и лучший месяц.
    $scale = 0;
    try {
        $best = $pdo->prepare(
            "SELECT SUM(value) AS v FROM profile_trades
              WHERE user_id = :me AND status = 'ok'
           GROUP BY substr(day, 1, 7) ORDER BY v DESC LIMIT 1"
        );
        $best->execute([':me' => $who]);
        $scale = (int)$best->fetchColumn();
    } catch (PDOException $e) {
        $scale = $totalSum;   // диалект не понял substr — деградируем, а не падаем
    }
    if ($scale < $totalSum) { $scale = $totalSum; }

    // Счётчики под графиком — за всё время, а не за месяц: подпись под ними
    // говорит «чем больше сделок — тем выше опыт», а опыт по определению не
    // обнуляется первого числа.
    $life = ['ok' => 0, 'declined' => 0, 'total' => 0];
    $all  = $pdo->prepare('SELECT status, COUNT(*) AS n FROM profile_trades WHERE user_id = :me GROUP BY status');
    $all->execute([':me' => $who]);
    foreach ($all->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $n = (int)$row['n'];
        $life['total'] += $n;
        // Та же причина, что выше: присваивание схлопнуло бы несколько
        // не-'ok' статусов в последний, а total при этом считался бы верно.
        if ((string)$row['status'] === 'ok') { $life['ok'] += $n; } else { $life['declined'] += $n; }
    }

    // Месяцы, в которых сделки вообще были, — из них собирается селектор.
    // Предлагать пустые месяцы значит звать человека туда, где ничего нет.
    $months = [];
    try {
        $sel = $pdo->prepare(
            'SELECT DISTINCT substr(day, 1, 7) AS m FROM profile_trades WHERE user_id = :me ORDER BY m DESC'
        );
        $sel->execute([':me' => $who]);
        $months = array_values(array_map('strval', $sel->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) {
        $months = [];
    }

    // Отбрасываем то, что эндпоинт сам же обслуживать откажется: месяцы
    // древнее порога и будущие (в базе может лежать сделка с кривой датой).
    // Иначе селектор предлагал бы пункт, по выбору которого приходит другой
    // месяц, — и человек решил бы, что сайт сломан.
    $nowMonth = substr($today, 0, 7);
    $months = array_values(array_filter($months, function ($m) use ($nowMonth) {
        return $m >= PROFILE_STATS_MIN_MONTH && $m <= $nowMonth;
    }));

    // Показанный месяц обязан быть в списке. Он может там отсутствовать
    // законно — в текущем месяце ещё не было ни одной сделки, — и тогда
    // <select> не нашёл бы совпадения, откатился на первый пункт и показывал
    // бы один месяц, пока график рисует другой.
    if (!in_array($month, $months, true)) {
        $months[] = $month;
        rsort($months);
    }

    return [200, [
        'ok'        => true,
        'authed'    => true,
        'available' => true,
        'month'     => $month,
        'lastDay'   => $lastDay,
        'days'      => $series,
        'totals'    => [
            'ok'       => $totalOk,
            'declined' => $totalNo,
            'sum'      => $totalSum,
            'scale'    => $scale,
        ],
        'lifetime'  => $life,
        'months'    => $months,
    ]];
}

if (!defined('TESTING')) {
    // no-store, а не no-cache: в ответе личные данные вошедшего, и оседать
    // они не должны нигде — ни в промежуточном кеше, ни в истории браузера.
    // Тот же заголовок, что у api/session.php.
    header('Cache-Control: no-store');
    start_site_session();
    // is_string, а не приведение: ?month[]=x даёт массив, и (string) на нём
    // печатает Warning ПЕРЕД телом ответа — JSON после этого не разбирается.
    $month = (isset($_GET['month']) && is_string($_GET['month'])) ? $_GET['month'] : null;
    [$status, $payload] = handle_profile_stats(db(), $_SESSION, $_GET, $month, date('Y-m-d'), time());
    json_out($payload, $status);
}
