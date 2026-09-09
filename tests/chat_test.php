<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/chat.php';
require __DIR__ . '/../public_html/api/chat.php';

// Чаты — api/lib/chat.php и api/chat.php.
//
// Главное здесь — РАЗГРАНИЧЕНИЕ ДОСТУПА. Номер ветки приходит из адреса, и
// единственное, что отделяет чужую переписку от своей, — проверка участия.
// Ошибка в ней означает, что любой читает и пишет в чужой диалог, подобрав
// число.
//
// Кто пишет — берётся из сессии Roblox, поэтому «сессия» здесь передаётся
// обычным массивом: тесты не поднимают $_SESSION и не зависят от порядка
// запуска.
//
// Схема на SQLite повторяет docs/migrations/2026-09-09-chat.sql; таблицу users
// с ключом roblox_id даёт уже test_db() из lib.php.

const CH_NOW = 1788950000;

function ch_db(): PDO {
    $pdo = test_db();
    // Колонки репутации приходят из test_db(): они описаны в schema.sql, как
    // и все остальные колонки, добавленные миграциями (см. news.likes).
    $pdo->exec('CREATE TABLE chat_threads (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        a_id    INTEGER NOT NULL,
        b_id    INTEGER NOT NULL,
        last_at INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE chat_messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id  INTEGER NOT NULL,
        sender_id  INTEGER NOT NULL,
        body       TEXT NOT NULL,
        created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE TABLE chat_reviews (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        thread_id  INTEGER NOT NULL,
        author_id  INTEGER NOT NULL,
        target_id  INTEGER NOT NULL,
        stars      INTEGER NOT NULL,
        body       TEXT,
        created_at INTEGER NOT NULL,
        UNIQUE (thread_id, author_id)
    )');
    return $pdo;
}

// Пользователь заводится так же, как его завёл бы вход через Roblox.
function ch_user(PDO $p, string $robloxId, string $name, int $seenAgo = 0): string {
    $st = $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at)
                       VALUES (?, ?, ?, ?, ?, ?)');
    $st->execute([$robloxId, strtolower($name), $name, '', CH_NOW - 86400, CH_NOW - $seenAgo]);
    return $robloxId;
}

function ch_thread(PDO $p, string $x, string $y, int $lastAt = 0): int {
    [$a, $b] = chat_pair($x, $y);
    $st = $p->prepare('INSERT INTO chat_threads (a_id, b_id, last_at) VALUES (?, ?, ?)');
    $st->execute([$a, $b, $lastAt]);
    return (int)$p->lastInsertId();
}

// Сессия вошедшего — тем же ключом, что ставит api/roblox_callback.php.
function ch_session(string $id): array { return ['user_id' => $id]; }

// --------------------------------------------------------------------------
//  Пары
// --------------------------------------------------------------------------

// Пара обязана быть упорядоченной, иначе диалог (5,9) и (9,5) окажутся
// разными строками и переписка расползётся по двум веткам.
test('пара пользователей нормализуется по возрастанию', function () {
    assert_eq(['5', '9'], chat_pair('5', '9'), 'уже по порядку');
    assert_eq(['5', '9'], chat_pair('9', '5'), 'перевёрнутая');
    assert_eq(['7', '7'], chat_pair('7', '7'), 'сам с собой');
});

// --------------------------------------------------------------------------
//  Пустой бой
// --------------------------------------------------------------------------

// На боевой базе таблиц нет до запуска миграции. Это штатное состояние: чат
// показывает пустоту, а не 500.
test('без таблиц чат отдаёт пустоту, а не падает', function () {
    $pdo = test_db();
    assert_eq(false, chat_ready($pdo), 'таблиц нет');
    assert_eq('', chat_me([]), 'никто');
    assert_eq([], chat_threads($pdo, '1', CH_NOW), 'диалогов нет');
    assert_eq([], chat_messages($pdo, '1', 1), 'сообщений нет');

    [$st, $p] = handle_chat($pdo, ch_session($me ?? '11'), null, CH_NOW);
    assert_eq(200, $st, 'ответ 200');
    assert_eq(false, $p['ready'], 'ready:false');
    assert_eq(0, count($p['threads']), 'список пуст');
});

// Личность берётся ТОЛЬКО из сессии, и приведений тут быть не должно. Массив
// в сессии дал бы «Array to string conversion» ПЕРЕД телом ответа, после чего
// JSON уже не разбирается, а true молча превратился бы в '1' — пользователя с
// roblox_id 1, вполне существующий номер.
test('в сессии принимается лишь настоящий roblox_id', function () {
    foreach (['', '0abc', 'abc', '12 34', "1'--", '123456789012345678901',
              null, [], ['user_id' => 1], true, false, 1.5, new stdClass()] as $bad) {
        assert_eq('', chat_me(['user_id' => $bad]), 'отклонено: ' . var_export($bad, true));
    }
    assert_eq('7', chat_me(['user_id' => '7']), 'настоящий id принимается');
    assert_eq('42', chat_me(['user_id' => 42]), 'число тоже: сессия переживает выкладки');
});

// --------------------------------------------------------------------------
//  Разграничение доступа
// --------------------------------------------------------------------------

// Ключевая проверка всего файла. Номер ветки — из адреса, и без этой
// проверки достаточно подобрать число, чтобы читать чужое.
test('в чужую ветку не пускают ни читать, ни писать', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME');
    $a = ch_user($pdo, '22', 'A');
    $b = ch_user($pdo, '33', 'B');
    $mine  = ch_thread($pdo, $me, $a);
    $alien = ch_thread($pdo, $a, $b);          // чужая переписка

    assert_true(chat_is_member($pdo, $me, $mine), 'своя ветка — участник');
    assert_eq(false, chat_is_member($pdo, $me, $alien), 'чужая — не участник');
    assert_eq(false, chat_is_member($pdo, $me, 9999), 'несуществующая');
    assert_eq(false, chat_is_member($pdo, '', $mine), '«никто» не участник');

    // В чужой ветке лежит сообщение — оно не должно вернуться.
    $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?,?,?,?)')
        ->execute([$alien, $a, 'секрет', CH_NOW]);
    assert_eq([], chat_messages($pdo, $me, $alien), 'чужие сообщения не читаются');

    [$code] = chat_send($pdo, $me, $alien, 'привет', CH_NOW);
    assert_eq(403, $code, 'в чужую ветку не пишут');

    [$code2] = chat_review($pdo, $me, $alien, 5, '', CH_NOW);
    assert_eq(403, $code2, 'в чужой ветке не оценивают');
});

// Обработчик тоже обязан обнулять чужой номер: иначе страница подсветила бы
// в списке диалог, которого у меня нет.
test('обработчик не открывает чужую ветку из адреса', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A'); $b = ch_user($pdo, '33', 'B');
    ch_thread($pdo, $me, $a, CH_NOW);
    $alien = ch_thread($pdo, $a, $b, CH_NOW);

    [, $p] = handle_chat($pdo, ch_session($me), (string)$alien, CH_NOW);
    assert_eq(0, $p['thread'], 'чужая ветка не выбрана');
    assert_eq(0, count($p['messages']), 'и сообщений нет');
});

test('мусор в ?thread= не выбирает ветку по ошибке', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t = ch_thread($pdo, $me, $a, CH_NOW);
    foreach (['abc', '1abc', '-1', '1.5', "1' OR '1", str_repeat('9', 20)] as $bad) {
        [, $p] = handle_chat($pdo, ch_session($me), $bad, CH_NOW);
        // Откат на свежую ветку — она у меня одна и она моя.
        assert_eq($t, $p['thread'], "мусор «{$bad}» откатился на свою ветку");
    }
});

// --------------------------------------------------------------------------
//  Список диалогов
// --------------------------------------------------------------------------

test('в списке только мои диалоги, свежие сверху', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $b  = ch_user($pdo, '33', 'B');  $c = ch_user($pdo, '44', 'C');

    $old  = ch_thread($pdo, $me, $a, CH_NOW - 1000);
    $new  = ch_thread($pdo, $me, $b, CH_NOW);
    ch_thread($pdo, $b, $c, CH_NOW + 500);          // чужая, самая свежая

    $list = chat_threads($pdo, $me, CH_NOW);
    assert_eq(2, count($list), 'только мои две');
    assert_eq($new, $list[0]['id'], 'свежая первой');
    assert_eq($old, $list[1]['id'], 'старая второй');
    assert_eq('B', $list[0]['peer']['nick'], 'собеседник определён верно');
});

// Статус попадает в атрибут на странице — значение из базы не должно
// проходить непроверенным.
test('неизвестный статус собеседника схлопывается в offline', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME');
    $a  = ch_user($pdo, '22', 'A', 99999);
    ch_thread($pdo, $me, $a, CH_NOW);
    $list = chat_threads($pdo, $me, CH_NOW);
    assert_eq('offline', $list[0]['peer']['status'], 'мусорный статус отброшен');
});

// --------------------------------------------------------------------------
//  Сообщения
// --------------------------------------------------------------------------

test('сообщения отдаются по возрастанию и помечены авторством', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);

    chat_send($pdo, $me, $t, 'первое', CH_NOW);
    $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?,?,?,?)')
        ->execute([$t, $a, 'второе', CH_NOW + 1]);
    chat_send($pdo, $me, $t, 'третье', CH_NOW + 2);

    $m = chat_messages($pdo, $me, $t);
    assert_eq(3, count($m), 'три сообщения');
    assert_eq('первое', $m[0]['body'], 'порядок по возрастанию');
    assert_eq('третье', $m[2]['body'], 'свежее последним');
    assert_eq(true,  $m[0]['mine'], 'моё помечено');
    assert_eq(false, $m[1]['mine'], 'чужое помечено');
});

// LIMIT обязан отрезать СТАРЫЕ сообщения, а не новые: иначе в длинной
// переписке человек видел бы начало и не видел последнего сообщения.
test('в длинной ветке остаются свежие, а не первые', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    $st = $pdo->prepare('INSERT INTO chat_messages (thread_id, sender_id, body, created_at) VALUES (?,?,?,?)');
    for ($i = 1; $i <= CHAT_PAGE_SIZE + 20; $i++) { $st->execute([$t, $me, "msg $i", CH_NOW + $i]); }

    $m = chat_messages($pdo, $me, $t);
    assert_eq(CHAT_PAGE_SIZE, count($m), 'не больше предела');
    assert_eq('msg ' . (CHAT_PAGE_SIZE + 20), $m[count($m) - 1]['body'], 'последнее — самое свежее');
    assert_eq('msg 21', $m[0]['body'], 'отрезаны именно старые');
});

// --------------------------------------------------------------------------
//  Отправка
// --------------------------------------------------------------------------

test('пустое сообщение не отправляется', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    foreach (['', '   ', "\n\t "] as $bad) {
        [$code, $p] = chat_send($pdo, $me, $t, $bad, CH_NOW);
        assert_eq(400, $code, 'отказ');
        assert_eq('empty', $p['error'], 'причина названа');
    }
    assert_eq(0, count(chat_messages($pdo, $me, $t)), 'в базу ничего не легло');
});

test('слишком длинное сообщение отвергается', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    [$code, $p] = chat_send($pdo, $me, $t, str_repeat('я', CHAT_BODY_MAX + 1), CH_NOW);
    assert_eq(400, $code, 'отказ');
    assert_eq('too_long', $p['error'], 'причина названа');
    // Ровно по границе — принимается.
    [$ok] = chat_send($pdo, $me, $t, str_repeat('я', CHAT_BODY_MAX), CH_NOW);
    assert_eq(200, $ok, 'ровно предел проходит');
});

// Номер снимается сразу после вставки. Раньше он брался после UPDATE и
// приходил нулём — то есть сообщение нельзя было отличить от соседнего.
test('отправка возвращает настоящий номер сообщения', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    [, $p1] = chat_send($pdo, $me, $t, 'раз', CH_NOW);
    [, $p2] = chat_send($pdo, $me, $t, 'два', CH_NOW + 1);
    assert_true($p1['message']['id'] > 0, 'номер не ноль');
    assert_true($p2['message']['id'] > $p1['message']['id'], 'номера растут');
});

// Ветка обязана подниматься наверх списка, иначе новая переписка теряется
// среди старых.
test('отправка двигает ветку наверх', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A'); $b = ch_user($pdo, '33', 'B');
    $t1 = ch_thread($pdo, $me, $a, CH_NOW);
    $t2 = ch_thread($pdo, $me, $b, CH_NOW - 100);

    assert_eq($t1, chat_threads($pdo, $me, CH_NOW)[0]['id'], 'сначала сверху первая');
    chat_send($pdo, $me, $t2, 'ау', CH_NOW + 50);
    assert_eq($t2, chat_threads($pdo, $me, CH_NOW)[0]['id'], 'после письма — вторая');
});

// --------------------------------------------------------------------------
//  Отзывы и репутация
// --------------------------------------------------------------------------

test('оценка вне диапазона 1..5 не принимается', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    foreach ([0, -1, 6, 99] as $bad) {
        [$code, $p] = chat_review($pdo, $me, $t, $bad, '', CH_NOW);
        assert_eq(400, $code, "отказ на $bad");
        assert_eq('bad_stars', $p['error'], 'причина названа');
    }
});

// Повторная отправка ПРАВИТ прежний отзыв. Иначе репутацию накручивают,
// отправив форму сто раз.
test('второй отзыв в той же ветке заменяет первый, а не добавляется', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);

    chat_review($pdo, $me, $t, 5, 'отлично', CH_NOW);
    chat_review($pdo, $me, $t, 1, 'передумал', CH_NOW + 10);

    $n = (int)$pdo->query('SELECT COUNT(*) FROM chat_reviews')->fetchColumn();
    assert_eq(1, $n, 'отзыв один');

    $mine = chat_my_review($pdo, $me, $t);
    assert_eq(1, $mine['stars'], 'осталась последняя оценка');
    assert_eq('передумал', $mine['body'], 'и последний текст');
});

// Репутация считается ЗАНОВО, а не инкрементом: смена оценки с плюса на
// минус иначе разъедется при первой же правке.
test('репутация пересчитывается при смене оценки, а не накапливается', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    $rep = function (int $u) use ($pdo) {
        $s = $pdo->prepare('SELECT likes, dislikes FROM users WHERE roblox_id = ?');
        $s->execute([$u]);
        return $s->fetch(PDO::FETCH_ASSOC);
    };

    chat_review($pdo, $me, $t, 5, '', CH_NOW);
    assert_eq(1, (int)$rep($a)['likes'],    'пятёрка — в плюс');
    assert_eq(0, (int)$rep($a)['dislikes'], 'минусов нет');

    chat_review($pdo, $me, $t, 1, '', CH_NOW + 1);
    assert_eq(0, (int)$rep($a)['likes'],    'плюс снят');
    assert_eq(1, (int)$rep($a)['dislikes'], 'единица — в минус');

    // И моя собственная репутация не тронута: я оценивал собеседника.
    assert_eq(0, (int)$rep($me)['likes'], 'себе плюсов не досталось');
});

// Тройка — «ни то ни сё»: на профиле шкала двоичная, и записывать середину
// в минус было бы несправедливо к собеседнику.
test('тройка не идёт ни в плюс, ни в минус', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME'); $a = ch_user($pdo, '22', 'A');
    $t  = ch_thread($pdo, $me, $a, CH_NOW);
    chat_review($pdo, $me, $t, 3, '', CH_NOW);
    $s = $pdo->prepare('SELECT likes, dislikes FROM users WHERE roblox_id = ?');
    $s->execute([$a]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    assert_eq(0, (int)$r['likes'], 'не плюс');
    assert_eq(0, (int)$r['dislikes'], 'и не минус');
});

// Ветка «сам с собой» в норме не создаётся, но проверка стоит копейки, а
// последствие — накрутка собственной репутации.
test('себе отзыв не поставить', function () {
    $pdo = ch_db();
    $me = ch_user($pdo, '11', 'ME');
    $t  = ch_thread($pdo, $me, $me, CH_NOW);
    [$code, $p] = chat_review($pdo, $me, $t, 5, '', CH_NOW);
    assert_eq(400, $code, 'отказ');
    assert_eq('self', $p['error'], 'причина названа');
});

// Колонок репутации на боевой базе может ещё не быть: миграция выполняется
// руками и отдельно от выкладки. Отзыв в этом случае обязан сохраниться —
// он в своей таблице, — а пересчёт репутации промолчать, а не уронить ответ
// пятисоткой посреди отправки.
test('без колонок репутации отзыв всё равно сохраняется', function () {
    $pdo = ch_db();
    $pdo->exec('ALTER TABLE users DROP COLUMN likes');
    $pdo->exec('ALTER TABLE users DROP COLUMN dislikes');
    $me = '11'; $peer = '22';
    ch_user($pdo, $me, 'MKSVTN'); ch_user($pdo, $peer, 'DANIKTOR');
    $tid = ch_thread($pdo, $me, $peer, CH_NOW);

    [$st, $p] = chat_review($pdo, $me, $tid, 5, 'спасибо', CH_NOW);
    assert_eq(200, $st, 'ответ 200, а не пятисотка');
    assert_eq(true, $p['ok'], 'отзыв принят');

    $row = $pdo->query('SELECT stars FROM chat_reviews')->fetch(PDO::FETCH_ASSOC);
    assert_eq(5, (int)$row['stars'], 'и лёг в свою таблицу');
});

run_tests();
