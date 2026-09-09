<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/profile-stats.php';
require __DIR__ . '/../public_html/api/profile-about.php';

// Статистика профиля — /api/profile-stats.php и /api/profile-about.php.
//
// Главное, что здесь проверяется: страница НЕ должна выдумывать данные и не
// должна показывать чужие. Прежний график был перенесён из макета с
// нарисованной ломаной — линия шла от 15 до 50, а счётчики под ней стояли на
// нуле. Прежняя карточка читалась из таблицы profile_card на одну строку, то
// есть любой вошедший увидел бы один и тот же чужой профиль.
//
// Таблицы profile_trades в test_db() нет намеренно — её нет и в боевой базе.
// Тесты, которым нужны сделки, заводят её сами.

// Дата «сегодня» передаётся параметром, а не берётся из date(): иначе набор
// был бы зелёным в сентябре и красным в октябре. PS_NOW — то же время в
// секундах; оно нужно только для вычисления статуса и с PS_TODAY не связано.
const PS_TODAY = '2026-09-08';
const PS_NOW   = 1789000000;

// Вымышленные roblox_id из диапазона, который Roblox не выдаёт.
const PS_ME    = '900000001';
const PS_OTHER = '900000002';

// База с одним вошедшим. test_db() заводит users пустой — как schema.sql.
function ps_db(): PDO {
    $pdo = test_db();
    ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN');
    return $pdo;
}

function ps_user(PDO $pdo, string $id, string $name, string $display,
                 string $avatar = '', ?int $seen = null): void {
    $pdo->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                   VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$id, $name, $display, $avatar, PS_NOW - 86400, $seen ?? PS_NOW]);
}

function ps_session(string $id = PS_ME): array { return ['user_id' => $id]; }

// Короткая обёртка: у обработчика шесть параметров, и повторять «сессия,
// адрес, сегодня, сейчас» в каждом тесте — значит прятать за ними то, что
// тест на самом деле проверяет.
function ps_call(PDO $pdo, ?string $month, ?array $session = null, array $get = []): array {
    return handle_profile_stats($pdo, $session ?? ps_session(), $get, $month, PS_TODAY, PS_NOW);
}

function ps_make_table(PDO $pdo): void {
    // Схема повторяет tools/seed-profile-stats.php, но на диалекте SQLite:
    // AUTO_INCREMENT и ENGINE он не понимает. Важны имена и типы колонок —
    // по ним ходят запросы обработчика.
    $pdo->exec('CREATE TABLE profile_trades (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        day     TEXT NOT NULL,
        status  TEXT NOT NULL,
        value   INTEGER NOT NULL DEFAULT 0
    )');
}

function ps_add(PDO $pdo, string $day, string $status, int $value = 0, int $times = 1,
                string $uid = PS_ME): void {
    $st = $pdo->prepare('INSERT INTO profile_trades (user_id, day, status, value) VALUES (?, ?, ?, ?)');
    for ($i = 0; $i < $times; $i++) { $st->execute([$uid, $day, $status, $value]); }
}

// --------------------------------------------------------------------------
//  Разбор ?month=
// --------------------------------------------------------------------------

// Параметр приходит из адресной строки, то есть от кого угодно. Любое
// непонятное значение обязано откатываться на текущий месяц, а не улетать в
// запрос: пустой график и молчание — худший из ответов, потому что человек
// решит, что сделок не было.
test('месяц из адреса разбирается строго', function () {
    assert_eq('2026-09', profile_stats_month(null, PS_TODAY), 'без параметра — текущий');
    assert_eq('2026-09', profile_stats_month('', PS_TODAY), 'пустая строка — текущий');
    assert_eq('2026-07', profile_stats_month('2026-07', PS_TODAY), 'прошлый месяц принимается');
    assert_eq('2026-09', profile_stats_month('2026-09', PS_TODAY), 'текущий принимается');
});

test('мусор и будущее в ?month= откатываются на текущий месяц', function () {
    foreach (['2026-13', '2026-00', 'abcd-ef', '2026', '2026-7', '2026-07-01',
              "2026-07' OR 1=1", '../../etc', '2026-10', '2027-01', '2020-01'] as $bad) {
        assert_eq('2026-09', profile_stats_month($bad, PS_TODAY), "отклонено: $bad");
    }
});

// Якорь \z, а не $. В PCRE $ совпадает и ПЕРЕД завершающим переводом строки,
// поэтому «2026-07\n» проходил проверку целиком, доезжал до запроса как
// '2026-07\n-01' и ронял эндпоинт необработанным PDOException — с полным путём
// к файлу в трейсе. Проверка стоит отдельно от общего списка мусора: там она
// потерялась бы среди двенадцати других строк, а ловит она конкретную правку —
// возврат к $.
test('перевод строки в конце месяца не проходит проверку', function () {
    foreach (["2026-07\n", "2026-07\r\n", "2026-07\n\n", "2026-07 "] as $bad) {
        assert_eq('2026-09', profile_stats_month($bad, PS_TODAY),
            'отклонено: ' . json_encode($bad));
    }
});

test('длина месяца считается верно, включая високосный февраль', function () {
    assert_eq(29, profile_stats_days_in_month('2024-02'), 'февраль 2024 — високосный');
    assert_eq(28, profile_stats_days_in_month('2026-02'), 'февраль 2026');
    assert_eq(30, profile_stats_days_in_month('2026-04'), 'апрель');
    assert_eq(31, profile_stats_days_in_month('2026-01'), 'январь');
});

// --------------------------------------------------------------------------
//  Без таблицы — то, что увидит бой
// --------------------------------------------------------------------------

// В боевой базе profile_trades нет и пока не будет. Отсутствие таблицы —
// штатный ответ, а не 500: страница по нему рисует пустое состояние.
test('без таблицы отдаётся пустое состояние, а не ошибка', function () {
    [$status, $p] = ps_call(ps_db(), null);
    assert_eq(200, $status, 'ответ 200');
    assert_eq(true, $p['ok'], 'ok:true');
    assert_eq(false, $p['available'], 'available:false');
    assert_eq('2026-09', $p['month'], 'текущий месяц');
    assert_eq(30, count($p['days']), 'каркас на все дни сентября');
    assert_eq(0, $p['totals']['sum'], 'оборот ноль');
    assert_eq(0, $p['lifetime']['total'], 'за всё время ноль');
    assert_eq(0, count($p['months']), 'выбирать нечего');
});

// Ось X строится по каркасу, а не по строкам из базы. Без этого дни без
// сделок выпадали бы, и линия склеивала бы 3-е число с 17-м, соврав о
// плотности сделок.
test('в каркасе есть каждый день месяца, включая пустые', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-05', 'ok', 100);
    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(31, count($p['days']), 'июль — 31 день');
    for ($i = 0; $i < 31; $i++) {
        assert_eq($i + 1, $p['days'][$i]['day'], 'дни идут подряд');
    }
    assert_eq(1, $p['days'][4]['ok'], '5-е число несёт сделку');
    assert_eq(0, $p['days'][5]['ok'], '6-е пустое, но присутствует');
});

// --------------------------------------------------------------------------
//  С данными
// --------------------------------------------------------------------------

test('сделки складываются по дням и статусам', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 500, 3);
    ps_add($pdo, '2026-07-10', 'declined', 0, 2);
    ps_add($pdo, '2026-07-11', 'ok', 250, 1);

    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(true, $p['available'], 'available:true');

    $d10 = $p['days'][9];
    assert_eq(3, $d10['ok'], '10-е: три успешных');
    assert_eq(2, $d10['declined'], '10-е: два отказа');
    assert_eq(1500, $d10['sum'], '10-е: оборот 3×500');

    assert_eq(4, $p['totals']['ok'], 'успешных за месяц');
    assert_eq(2, $p['totals']['declined'], 'отказов за месяц');
    assert_eq(1750, $p['totals']['sum'], 'оборот за месяц');
});

// Отменённая сделка не принесла ничего. Складывать её value в оборот —
// приписывать деньги, которых не было.
test('отказы не попадают в оборот', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-02', 'ok', 100);
    ps_add($pdo, '2026-07-02', 'declined', 999999);
    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(100, $p['totals']['sum'], 'в обороте только состоявшаяся сделка');
    assert_eq(1, $p['days'][1]['declined'], 'отказ при этом посчитан как отказ');
});

test('сделки соседних месяцев в выборку не попадают', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-06-30', 'ok', 700);
    ps_add($pdo, '2026-07-01', 'ok', 100);
    ps_add($pdo, '2026-08-01', 'ok', 900);
    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(1, $p['totals']['ok'], 'только июльская сделка');
    assert_eq(100, $p['totals']['sum'], 'оборот только июльский');
});

// Счётчики под графиком — за всё время: подпись под ними говорит «чем больше
// сделок — тем выше опыт», а опыт не обнуляется первого числа.
test('счётчики за всё время не зависят от выбранного месяца', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-06-01', 'ok', 10, 5);
    ps_add($pdo, '2026-07-01', 'ok', 10, 2);
    ps_add($pdo, '2026-07-01', 'declined', 0, 3);

    [, $jul] = ps_call($pdo, '2026-07');
    [, $jun] = ps_call($pdo, '2026-06');
    foreach ([$jul, $jun] as $p) {
        assert_eq(7,  $p['lifetime']['ok'], 'успешных за всё время');
        assert_eq(3,  $p['lifetime']['declined'], 'отказов за всё время');
        assert_eq(10, $p['lifetime']['total'], 'всего заведено');
    }
    assert_eq(2, $jul['totals']['ok'], 'а месячные — разные');
    assert_eq(5, $jun['totals']['ok'], 'а месячные — разные');
});

// Шкала полосы оборота — лучший месяц за историю. Без неё непонятно, много
// это или мало; в макете под полосой стояли «0.3» и «180000» без объяснения.
test('шкала оборота — лучший месяц за всю историю', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-06-01', 'ok', 1000, 10);   // июнь: 10000
    ps_add($pdo, '2026-07-01', 'ok', 1000, 3);    // июль:  3000
    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(3000,  $p['totals']['sum'], 'оборот выбранного месяца');
    assert_eq(10000, $p['totals']['scale'], 'шкала — лучший месяц');
});

// В текущем месяце будущих дней рисовать нельзя: линия падала бы в ноль и шла
// плашмя до конца месяца, и это читалось бы как «сделок не было», а не «день
// не наступил».
test('lastDay обрывает данные на сегодняшнем дне', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-09-03', 'ok', 100);

    [, $cur] = ps_call($pdo, '2026-09');
    assert_eq(30, count($cur['days']), 'ось — весь сентябрь');
    assert_eq(8,  $cur['lastDay'], 'но данные только по 8-е');

    [, $past] = ps_call($pdo, '2026-07');
    assert_eq(31, $past['lastDay'], 'в прошедшем месяце — весь месяц');
});

test('в выборе месяцев только те, где сделки были', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-04-01', 'ok', 1);
    ps_add($pdo, '2026-07-01', 'ok', 1);
    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(['2026-07', '2026-04'], $p['months'], 'новые сверху, пустых месяцев нет');
});

// --------------------------------------------------------------------------
//  Карточка профиля
// --------------------------------------------------------------------------

// Аноним не должен получать ни карточку, ни чужие сделки. И не пустую
// карточку: пустая читалась бы как «у вас ничего нет», хотя правда — «вы не
// вошли».
test('без сессии отдаётся authed:false и ничего больше', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-09-01', 'ok', 500, 3);

    [$status, $p] = ps_call($pdo, null, []);
    assert_eq(200, $status, 'ответ 200, а не 401: страница сама решает, что показать');
    assert_eq(false, $p['authed'], 'authed:false');
    assert_eq(false, $p['available'], 'сделок нет');
    assert_eq(0, $p['totals']['sum'], 'оборот не просочился');
    assert_eq(0, $p['lifetime']['total'], 'счётчики не просочились');
    assert_eq(30, count($p['days']), 'но каркас месяца тот же — формат ответа один');
});

// Личность берётся ТОЛЬКО из сессии. Мусор в ней — это «не вошёл», а не
// повод сходить с ним в запрос.
test('в сессии принимается лишь настоящий roblox_id', function () {
    foreach (['', '0abc', 'abc', '12 34', "1'--", '123456789012345678901',
              null, [], ['user_id' => 1], true, 1.5, new stdClass()] as $bad) {
        assert_eq('', profile_me(['user_id' => $bad]), 'отклонено: ' . var_export($bad, true));
    }
    assert_eq('', profile_me([]), 'пустая сессия');
    assert_eq('7', profile_me(['user_id' => '7']), 'настоящий id принимается');
    assert_eq('42', profile_me(['user_id' => 42]), 'число тоже: сессия переживает выкладки');
});

// Сессия могла пережить удаление пользователя. Для страницы это то же самое,
// что «не вошёл»: показывать нечего.
test('сессия ссылается на исчезнувшего — считаем, что не вошёл', function () {
    [, $p] = ps_call(test_db(), null);
    assert_eq(false, $p['authed'], 'authed:false');
});

// Карточку печатает profile.php, а эндпоинт отдаёт только цифры графика. Два
// источника на одно и то же поле разъехались бы, и на экране половина
// карточки была бы свежей, а половина — из прошлого запроса.
test('в ответе нет карточки — её печатает страница', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-09-01', 'ok', 100);
    foreach ([ps_call($pdo, null)[1], ps_call($pdo, null, [])[1], ps_call(ps_db(), null)[1]] as $p) {
        assert_eq(false, array_key_exists('profile', $p), 'поля profile в ответе нет');
    }
});

// Ни одного из этих полей нет в отдельной таблице профиля: они приходят из
// users, той же, что кормит шапку сайта. Иначе ник в шапке и ник в профиле
// разъехались бы при первой смене имени в Roblox.
//
// profile_card() зовётся напрямую: карточку печатает profile.php, а не
// эндпоинт, и гонять её через handle_profile_stats() значило бы проверять
// не тот код, который её строит.
test('карточка собирается из users', function () {
    $pdo = test_db();
    ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN', 'https://tr.rbxcdn.com/a.png');
    $c = profile_card($pdo, PS_ME, PS_NOW);

    assert_true($c !== null, 'карточка есть');
    assert_eq('MKSVTN', $c['nick'], 'ник — display_name');
    assert_eq('@mksvtn', $c['handle'], 'хендл — username со «собакой»');
    assert_eq('https://tr.rbxcdn.com/a.png', $c['avatar'], 'аватар');
    // Ссылки на Roblox в карточке нет: её печатает меню пользователя в шапке
    // (js/topbar.js), и второе место с тем же адресом было бы лишним.
    assert_eq(false, array_key_exists('roblox', $c), 'лишних полей карточка не несёт');
});

// Карточка чужого человека собирается тем же кодом: своего и чужого профиль
// различает только страница, решая, что вокруг карточки показать.
test('карточка собирается на любого, а не только на себя', function () {
    $pdo = ps_db();
    ps_user($pdo, PS_OTHER, 'daniktor', 'DANIKTOR');
    $c = profile_card($pdo, PS_OTHER, PS_NOW);
    assert_eq('DANIKTOR', $c['nick'], 'ник соседа');
    assert_eq('@daniktor', $c['handle'], 'его хендл');
});

// Человека с таким id может не быть вовсе — ссылка из чужого сообщения
// переживает удаление аккаунта. Это не ошибка, а «профиля нет».
test('несуществующий профиль — это null, а не пустая карточка', function () {
    assert_eq(null, profile_card(ps_db(), '900009999', PS_NOW), 'нет такого');
    assert_eq(null, profile_card(ps_db(), '', PS_NOW), 'пустой id тоже');
});

// display_name у Roblox может быть пустым. Показывать пустой заголовок нельзя
// — ник тогда обязан подставиться из username.
test('пустой display_name заменяется ником', function () {
    $pdo = test_db();
    ps_user($pdo, PS_ME, 'mksvtn', '');
    $c = profile_card($pdo, PS_ME, PS_NOW);
    assert_eq('mksvtn', $c['nick'], 'ник взят из username');
    assert_eq('@mksvtn', $c['handle'], 'хендл на месте');
});

// Аватар уходит в <img src>. roblox_touch_user() кладёт уже проверенный, но
// база — не то место, которому стоит верить на слово при выводе в разметку.
test('аватар с чужого домена не отдаётся странице', function () {
    foreach ([
        'https://evil.example.com/a.png',
        'http://tr.rbxcdn.com/a.png',
        'javascript:alert(1)',
        '/assets/local.png',
        'https://rbxcdn.com.evil.tld/a.png',
        '',
    ] as $bad) {
        $pdo = test_db();
        ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN', $bad);
        assert_eq(null, profile_card($pdo, PS_ME, PS_NOW)['avatar'], 'отклонён аватар: ' . $bad);
    }
    $pdo = test_db();
    ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN', 'https://tr.rbxcdn.com/ok.png');
    assert_eq('https://tr.rbxcdn.com/ok.png', profile_card($pdo, PS_ME, PS_NOW)['avatar'],
        'свой домен проходит');
});

// Статус ВЫЧИСЛЯЕТСЯ из last_login_at, а не хранится: хранимый пришлось бы
// кому-то сбрасывать, и забытое «в сети» врало бы неделями.
test('статус выводится из времени последнего входа', function () {
    foreach ([
        [PS_NOW,                            'online',  'вошёл только что'],
        [PS_NOW - PROFILE_ONLINE_WINDOW,    'online',  'ровно на границе окна — ещё в сети'],
        [PS_NOW - PROFILE_ONLINE_WINDOW - 1,'offline', 'секундой позже — уже нет'],
        [PS_NOW - 86400,                    'offline', 'вчера'],
        [0,                                 'offline', 'не входил ни разу'],
    ] as [$seen, $want, $why]) {
        $pdo = test_db();
        ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN', '', $seen);
        assert_eq($want, profile_card($pdo, PS_ME, PS_NOW)['status'], $why);
    }
});

// Колонки likes/dislikes приезжают миграцией ЧАТОВ, about — миграцией
// профиля. На боевой базе может не быть ни тех ни другой, и это не повод
// ронять страницу: репутация читается нулями, «о себе» — пустотой.
test('карточка переживает отсутствие колонок репутации и «о себе»', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        roblox_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_login_at INTEGER NOT NULL
    )");
    ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN');

    $c = profile_card($pdo, PS_ME, PS_NOW);
    assert_eq('MKSVTN', $c['nick'], 'ник на месте');
    assert_eq(0, $c['likes'], 'лайков ноль');
    assert_eq(0, $c['dislikes'], 'дизлайков ноль');
    assert_eq(null, $c['about'], '«о себе» пусто');
});

test('репутация и «о себе» отдаются, когда колонки есть', function () {
    $pdo = ps_db();
    $pdo->exec('ALTER TABLE users ADD COLUMN likes INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('ALTER TABLE users ADD COLUMN dislikes INTEGER NOT NULL DEFAULT 0');
    $pdo->prepare('UPDATE users SET likes = ?, dislikes = ?, about = ? WHERE roblox_id = ?')
        ->execute([428, 11, 'Торгую с 2024', PS_ME]);

    $c = profile_card($pdo, PS_ME, PS_NOW);
    assert_eq(428, $c['likes'], 'лайки');
    assert_eq(11, $c['dislikes'], 'дизлайки');
    assert_eq('Торгую с 2024', $c['about'], 'о себе');
});

// Пустая строка в базе — это отсутствие значения, а не значение. Иначе на
// странице оказался бы пустой абзац вместо подсказки.
test('пустое «о себе» читается как отсутствие текста', function () {
    $pdo = ps_db();
    $pdo->prepare('UPDATE users SET about = ? WHERE roblox_id = ?')->execute(['   ', PS_ME]);
    assert_eq(null, profile_card($pdo, PS_ME, PS_NOW)['about'], 'пробелы — это пусто');
});

// --------------------------------------------------------------------------
//  Чей профиль смотрим
// --------------------------------------------------------------------------

// ?id= приходит из адресной строки, то есть от кого угодно. Мусор молча
// откатывается на свой профиль: ссылка из чужого сообщения могла оборваться
// при копировании, и это не повод показывать ошибку.
test('?id= разбирается тем же строгим правилом, что и сессия', function () {
    foreach (['', '0abc', 'abc', '12 34', "1'--", '123456789012345678901',
              null, [], true, 1.5] as $bad) {
        assert_eq('', profile_target(['id' => $bad]), 'отклонено: ' . var_export($bad, true));
    }
    assert_eq('', profile_target([]), 'параметра нет — профиль свой');
    assert_eq('900000002', profile_target(['id' => '900000002']), 'настоящий id принимается');
    assert_eq('42', profile_target(['id' => 42]), 'число тоже');

    // Ведущие нули срезаются: иначе ?id=007 и ?id=7 — два адреса одного
    // профиля с разным canonical.
    assert_eq('7', profile_target(['id' => '007']), 'нули срезаны');
    assert_eq('7', profile_target(['id' => '00000000000000000007']), 'даже двадцать знаков');
    assert_eq('', profile_target(['id' => '0']), 'ноль — не id');
    assert_eq('', profile_target(['id' => '000']), 'и одни нули тоже');
    assert_eq('7', profile_me(['user_id' => '007']), 'то же правило и для сессии');
});

// Корневая проверка чужих профилей: сделки обязаны приехать ТОГО, кого
// смотрим, а не того, кто смотрит.
test('?id= переключает график на чужого, не трогая личность зрителя', function () {
    $pdo = ps_db();
    ps_user($pdo, PS_OTHER, 'daniktor', 'DANIKTOR');
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 100, 2, PS_ME);
    ps_add($pdo, '2026-07-10', 'ok', 500, 7, PS_OTHER);

    [, $mine] = ps_call($pdo, '2026-07');
    assert_eq(2, $mine['totals']['ok'], 'без ?id= — свои');

    [, $peer] = ps_call($pdo, '2026-07', null, ['id' => PS_OTHER]);
    assert_eq(true, $peer['authed'], 'зритель по-прежнему вошедший');
    assert_eq(7, $peer['totals']['ok'], 'с ?id= — чужие');
    assert_eq(3500, $peer['totals']['sum'], 'и чужой оборот');
});

// Мусорный ?id= не должен ни ронять ответ, ни показывать пустоту вместо
// своего профиля: человек пришёл по битой ссылке, а не в чужой аккаунт.
test('мусорный ?id= откатывается на свой профиль', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 100, 2, PS_ME);
    foreach ([['id' => 'abc'], ['id' => ['x']], ['id' => ''], []] as $get) {
        [, $p] = ps_call($pdo, '2026-07', null, $get);
        assert_eq(2, $p['totals']['ok'], 'показаны свои сделки: ' . json_encode($get));
    }
});

// Смотреть чужой профиль можно только вошедшему: иначе ?id= превращается в
// способ выкачать статистику по всему списку игроков без единого входа.
test('анониму ?id= ничего не открывает', function () {
    $pdo = ps_db();
    ps_user($pdo, PS_OTHER, 'daniktor', 'DANIKTOR');
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 500, 7, PS_OTHER);

    [, $p] = ps_call($pdo, '2026-07', [], ['id' => PS_OTHER]);
    assert_eq(false, $p['authed'], 'authed:false');
    assert_eq(0, $p['totals']['ok'], 'чужих сделок не видно');
    assert_eq(0, $p['lifetime']['total'], 'и счётчиков тоже');
});

// Профиля с таким id может не быть. Это не ошибка эндпоинта — о пропаже
// сообщает страница; здесь достаточно пустого ответа вместо чьих-то чужих
// цифр.
test('?id= на несуществующего даёт пустую статистику, а не чужую', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 100, 5, PS_ME);

    [, $p] = ps_call($pdo, '2026-07', null, ['id' => '900009999']);
    assert_eq(true, $p['authed'], 'зритель вошёл');
    assert_eq(0, $p['totals']['ok'], 'сделок нет');
    assert_eq(0, $p['lifetime']['total'], 'и за всё время тоже');
});

// --------------------------------------------------------------------------
//  Чужие сделки
// --------------------------------------------------------------------------

// Корневая причина, по которой у profile_trades появился user_id: без него
// каждый вошедший видел бы общую на весь сайт статистику.
test('в профиль попадают только свои сделки', function () {
    $pdo = ps_db();
    ps_user($pdo, PS_OTHER, 'daniktor', 'DANIKTOR');
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-10', 'ok', 100, 2, PS_ME);
    ps_add($pdo, '2026-07-10', 'ok', 999999, 50, PS_OTHER);
    ps_add($pdo, '2026-06-10', 'ok', 888888, 40, PS_OTHER);

    [, $mine] = ps_call($pdo, '2026-07');
    assert_eq(2, $mine['totals']['ok'], 'только свои сделки за месяц');
    assert_eq(200, $mine['totals']['sum'], 'и только свой оборот');
    assert_eq(2, $mine['lifetime']['total'], 'счётчики за всё время тоже свои');
    assert_eq(200, $mine['totals']['scale'], 'шкала — свой лучший месяц, а не общесайтовый');
    assert_eq(['2026-07'], $mine['months'], 'в селекторе только свои месяцы');

    [, $theirs] = ps_call($pdo, '2026-07', ps_session(PS_OTHER));
    assert_eq(50, $theirs['totals']['ok'], 'у соседа своя статистика');
});

// Таблица могла остаться от версии, писавшейся до появления аккаунтов —
// без владельца у строк. Показывать такие сделки вошедшему как свои нельзя.
test('таблица сделок без user_id читается как отсутствие таблицы', function () {
    $pdo = ps_db();
    $pdo->exec('CREATE TABLE profile_trades (
        id INTEGER PRIMARY KEY AUTOINCREMENT, day TEXT NOT NULL,
        status TEXT NOT NULL, value INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec("INSERT INTO profile_trades (day, status, value) VALUES ('2026-09-01', 'ok', 500)");

    [, $p] = ps_call($pdo, null);
    assert_eq(true, $p['authed'], 'вошёл');
    assert_eq(false, $p['available'], 'сделок как будто нет');
    assert_eq(0, $p['totals']['sum'], 'чужой оборот не показан');
});

// --------------------------------------------------------------------------
//  «О себе»: чистка и сохранение
// --------------------------------------------------------------------------

test('текст «о себе» приводится к одному абзацу', function () {
    assert_eq('a b', profile_about_clean("a\n\nb"), 'переводы строк схлопываются');
    assert_eq('a b', profile_about_clean("a \t b"), 'подряд идущие пробелы схлопываются');
    assert_eq('ab', profile_about_clean("a\x00b"), 'управляющие символы вырезаны');
    assert_eq('', profile_about_clean("   \n  "), 'из одних пробелов остаётся пустота');
    assert_eq('привет', profile_about_clean('  привет  '), 'края обрезаны');
});

// Обрезка по СИМВОЛАМ, а не по байтам: кириллица в utf8mb4 занимает два
// байта, и байтовая обрезка разрубила бы букву пополам.
test('«о себе» обрезается по символам до предела', function () {
    $long = str_repeat('я', PROFILE_ABOUT_MAX + 50);
    $cut  = profile_about_clean($long);
    assert_eq(PROFILE_ABOUT_MAX, mb_strlen($cut), 'ровно предел символов');
    assert_true(mb_check_encoding($cut, 'UTF-8'), 'и строка осталась валидным UTF-8');
    assert_eq(str_repeat('я', PROFILE_ABOUT_MAX), $cut, 'обрезано с конца, а не изнутри');
});

test('«о себе» сохраняется только своё и только вошедшим', function () {
    $pdo = ps_db();
    ps_user($pdo, PS_OTHER, 'daniktor', 'DANIKTOR');

    [$status] = handle_profile_about($pdo, [], 'текст');
    assert_eq(401, $status, 'аноним получает 401');

    [$status, $p] = handle_profile_about($pdo, ps_session(), "  меняю  фрукты \n дорого  ");
    assert_eq(200, $status, 'вошедший сохраняет');
    assert_eq('меняю фрукты дорого', $p['about'], 'в ответе — то, что легло в базу');

    $q = $pdo->prepare('SELECT about FROM users WHERE roblox_id = ?');
    $q->execute([PS_ME]);
    assert_eq('меняю фрукты дорого', $q->fetchColumn(), 'и в базе то же самое');

    $q->execute([PS_OTHER]);
    assert_eq(null, $q->fetchColumn(), 'у соседа ничего не поменялось');
});

test('«о себе» не принимает не-строку', function () {
    $pdo = ps_db();
    foreach ([null, 42, ['a'], true] as $bad) {
        [$status] = handle_profile_about($pdo, ps_session(), $bad);
        assert_eq(400, $status, 'отклонено: ' . var_export($bad, true));
    }
});

// Очистка поля кладёт NULL, а не пустую строку: два способа сказать «ничего
// не написано» разъехались бы при первой же выборке.
test('очистка «о себе» кладёт NULL', function () {
    $pdo = ps_db();
    handle_profile_about($pdo, ps_session(), 'что-то');
    [$status, $p] = handle_profile_about($pdo, ps_session(), '   ');
    assert_eq(200, $status, 'принято');
    assert_eq('', $p['about'], 'в ответе пусто');

    $q = $pdo->prepare('SELECT about FROM users WHERE roblox_id = ?');
    $q->execute([PS_ME]);
    assert_eq(null, $q->fetchColumn(), 'в базе NULL');
});

// Колонки about может не быть — миграция профиля не выполнена. Отвечать «ok»
// нельзя: человек увидел бы «сохранено» и потерял текст на перезагрузке.
test('без колонки about сохранение честно отвечает 503', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (
        roblox_id INTEGER PRIMARY KEY,
        username TEXT NOT NULL DEFAULT '',
        display_name TEXT NOT NULL DEFAULT '',
        avatar_url TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_login_at INTEGER NOT NULL
    )");
    ps_user($pdo, PS_ME, 'mksvtn', 'MKSVTN');

    [$status, $p] = handle_profile_about($pdo, ps_session(), 'текст');
    assert_eq(503, $status, 'не 200 и не 500');
    assert_eq(false, $p['ok'], 'ok:false');
});

// Границы месяца собираются строками ($from/$to). Ни один тест не ставил
// сделку в ПОСЛЕДНИЙ день, а именно там ошибка на единицу и живёт: сделка
// 31-го числа выпала бы из июля молча.
test('сделка в последний день месяца попадает в выборку', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-07-31', 'ok', 400);          // последний день июля
    ps_add($pdo, '2026-07-01', 'ok', 100);          // и первый
    ps_add($pdo, '2026-02-28', 'ok', 200);          // последний день короткого месяца
    ps_add($pdo, '2024-02-29', 'ok', 300);          // и високосного

    [, $jul] = ps_call($pdo, '2026-07');
    assert_eq(2, $jul['totals']['ok'], 'оба июльских дня учтены');
    assert_eq(1, $jul['days'][30]['ok'], '31-е число на месте');
    assert_eq(500, $jul['totals']['sum'], 'оборот включает последний день');

    [, $feb] = ps_call($pdo, '2026-02');
    assert_eq(1, $feb['days'][27]['ok'], '28-е февраля на месте');

    [, $leap] = ps_call($pdo, '2024-02');
    assert_eq(29, count($leap['days']), 'високосный февраль — 29 дней');
    assert_eq(1, $leap['days'][28]['ok'], '29-е февраля на месте');
});

// scale считается по успешным сделкам. Если в запрос просочатся отказы,
// шкала раздуется, и полоса оборота станет врать в меньшую сторону.
test('шкала оборота не учитывает отказы', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-06-01', 'ok', 1000, 5);            // июнь: 5000
    ps_add($pdo, '2026-06-02', 'declined', 999999, 3);    // отказы с огромной суммой
    ps_add($pdo, '2026-07-01', 'ok', 1000, 2);            // июль: 2000

    [, $p] = ps_call($pdo, '2026-07');
    assert_eq(2000, $p['totals']['sum'], 'оборот июля');
    assert_eq(5000, $p['totals']['scale'], 'шкала — лучший месяц ПО УСПЕШНЫМ');
    assert_eq(3, $p['lifetime']['declined'], 'отказы при этом посчитаны');
});

// Тест счётчиков должен различать три РАЗНЫХ числа. При равных значениях
// перепутанные местами поля прошли бы незамеченными.
test('счётчики за всё время различимы между собой', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-06-01', 'ok', 10, 7);
    ps_add($pdo, '2026-06-02', 'declined', 0, 2);

    [, $p] = ps_call($pdo, '2026-06');
    $life = $p['lifetime'];
    assert_eq(7, $life['ok'], 'успешных');
    assert_eq(2, $life['declined'], 'отказов');
    assert_eq(9, $life['total'], 'всего');
    // Три числа обязаны быть попарно разными — иначе проверка выше ничего не
    // доказывает: перепутанные поля дали бы те же значения.
    assert_true($life['ok'] !== $life['declined'] && $life['ok'] !== $life['total']
             && $life['declined'] !== $life['total'], 'значения попарно различны');
});

// Показанный месяц обязан присутствовать в списке для селектора, иначе
// <select> не найдёт совпадения, откатится на первый пункт и будет показывать
// один месяц, пока график рисует другой.
test('выбранный месяц всегда есть в списке месяцев', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2026-04-10', 'ok', 100);   // сделки только в апреле

    [, $p] = ps_call($pdo, null);   // а спрашиваем сентябрь
    assert_eq('2026-09', $p['month'], 'показан текущий месяц');
    assert_true(in_array('2026-09', $p['months'], true), 'он есть в списке');
    assert_true(in_array('2026-04', $p['months'], true), 'апрель с данными тоже');
});

// В список не должны попадать месяцы, которые сам эндпоинт обслуживать
// откажется: по ним пришёл бы другой месяц, и человек решил бы, что сломано.
test('в списке нет месяцев вне обслуживаемого диапазона', function () {
    $pdo = ps_db();
    ps_make_table($pdo);
    ps_add($pdo, '2019-05-10', 'ok', 100);   // древнее порога
    ps_add($pdo, '2030-01-10', 'ok', 100);   // из будущего
    ps_add($pdo, '2026-07-10', 'ok', 100);   // нормальный

    [, $p] = ps_call($pdo, '2026-07');
    foreach ($p['months'] as $m) {
        assert_eq($m, profile_stats_month($m, PS_TODAY), "месяц $m эндпоинт принимает");
    }
    assert_eq(false, in_array('2019-05', $p['months'], true), 'древний отброшен');
    assert_eq(false, in_array('2030-01', $p['months'], true), 'будущий отброшен');
});

run_tests();
