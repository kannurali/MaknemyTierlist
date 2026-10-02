<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/stock.php';
require __DIR__ . '/../public_html/api/stock_watch.php';

// Сток фруктов — api/lib/stock.php, api/stock.php, api/stock_watch.php,
// api/stock_pull.php и страница /stock.
//
// В сеть тесты не ходят: Discord и Telegram подставляются записывающими
// функциями. Сообщения Vulcan — настоящие, снятые из канала #stock-feed
// (tests/fixtures/stock-vulcan-*.json, без данных людей).
//
// Главные обещания:
//  - сток разбирается из обоих видов сообщений Vulcan: ответа на /stock и
//    автопоста при смене;
//  - о смене бот пишет один раз и только тем, чей отмеченный фрукт в ней
//    есть; ответ на /stock посреди смены второй рассылки не делает;
//  - старое сообщение (канал читается после простоя) не рассылается и
//    свежий сток не затирает;
//  - без токена, без канала, без таблиц ничего не падает.

const SK_NOW = 1790897000;
const SK_TG_TOKEN = '123456789:AAHfakeTokenForTestsOnly_abcdefghij';
const SK_DC_TOKEN = 'MTAwMDAwMDAwMDAwMDAwMDAwMA.Gabcde.fakeDiscordTokenForTestsOnly_0123456789ab';
const SK_CHANNEL = '1555347352636887191';

function sk_fixture(string $name): array {
    $raw = file_get_contents(__DIR__ . '/fixtures/stock-vulcan-' . $name . '.json');
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) { throw new RuntimeException("fixture $name"); }
    return $d;
}

function sk_db(bool $withStock = true, bool $withTg = true): PDO {
    $pdo = test_db();
    if ($withStock) {
        // Зеркалит docs/migrations/2026-10-02-stock.sql.
        $pdo->exec("CREATE TABLE stock (
            kind       TEXT NOT NULL PRIMARY KEY,
            fruits     TEXT NOT NULL,
            ends_at    INTEGER NOT NULL DEFAULT 0,
            seen_at    INTEGER NOT NULL DEFAULT 0,
            message_id TEXT NOT NULL DEFAULT ''
        )");
        $pdo->exec("CREATE TABLE stock_feed (
            id         INTEGER NOT NULL PRIMARY KEY,
            last_id    TEXT NOT NULL DEFAULT '0',
            alerted_id TEXT NOT NULL DEFAULT '0',
            polled_at  INTEGER NOT NULL DEFAULT 0
        )");
        $pdo->exec("CREATE TABLE stock_watch (
            user_id INTEGER NOT NULL,
            fruit   TEXT NOT NULL,
            PRIMARY KEY (user_id, fruit)
        )");
    }
    if ($withTg) {
        // Зеркалит docs/migrations/2026-09-23-telegram.sql.
        $pdo->exec("CREATE TABLE tg_links (
            user_id   INTEGER NOT NULL PRIMARY KEY,
            chat_id   INTEGER NOT NULL,
            tg_name   TEXT NOT NULL DEFAULT '',
            lang      TEXT NOT NULL DEFAULT 'ru',
            linked_at INTEGER NOT NULL
        )");
        $pdo->exec("CREATE TABLE tg_codes (
            code_hash  TEXT NOT NULL PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            lang       TEXT NOT NULL DEFAULT 'ru',
            expires_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE TABLE chat_reads (
            thread_id    INTEGER NOT NULL,
            user_id      INTEGER NOT NULL,
            last_read_id INTEGER NOT NULL DEFAULT 0,
            seen_at      INTEGER NOT NULL DEFAULT 0,
            notified_id  INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (thread_id, user_id)
        )');
    }
    return $pdo;
}

function sk_user(PDO $p, string $id, ?int $chatId = null, string $lang = 'ru'): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, 'user' . $id, '', '', SK_NOW - 86400, SK_NOW, SK_NOW]);
    if ($chatId !== null) {
        $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, lang, linked_at) VALUES (?, ?, ?, ?, ?)')
          ->execute([$id, $chatId, '@u' . $id, $lang, SK_NOW]);
    }
    return $id;
}

function sk_watch(PDO $p, string $user, array $fruits): void {
    foreach ($fruits as $f) {
        [$code] = stock_watch_set($p, $user, ['fruit' => $f, 'on' => true]);
        assert_eq(200, $code, "отметка $f");
    }
}

function sk_cfg(array $extra = []): array {
    return array_merge([
        'discord_bot_token'     => SK_DC_TOKEN,
        'discord_stock_channel' => SK_CHANNEL,
        'tg_bot_token'          => SK_TG_TOKEN,
        'tg_bot_name'           => 'MaknemyBot',
    ], $extra);
}

// Discord понарошку: отдаёт заданный список сообщений и запоминает пути.
function sk_discord(array $messages, array &$paths, int $code = 200): callable {
    return function (string $path) use ($messages, &$paths, $code): array {
        $paths[] = $path;
        return [$code, $code === 200 ? $messages : ['message' => 'nope']];
    };
}

function sk_telegram(array &$log): callable {
    return function (string $method, array $params) use (&$log): array {
        $log[] = [$method, $params];
        return ['ok' => true, 'result' => []];
    };
}

function sk_no_sleep(): callable {
    return function (int $us): void {};
}

// Сообщение-ответ на /stock с подменёнными id, временем и метками смены.
function sk_command(string $id, int $at, ?int $normalEnds = null, ?int $mirageEnds = null): array {
    $m = sk_fixture('command');
    $m['id'] = $id;
    $m['timestamp'] = gmdate('Y-m-d\TH:i:s', $at) . '.000000+00:00';
    $json = json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($normalEnds !== null) { $json = str_replace('<t:1790899211:R>', '<t:' . $normalEnds . ':R>', $json); }
    if ($mirageEnds !== null) { $json = str_replace('<t:1790899272:R>', '<t:' . $mirageEnds . ':R>', $json); }
    return json_decode($json, true);
}

// --------------------------------------------------------------------------
//  Разбор
// --------------------------------------------------------------------------

test('ответ Vulcan на /stock разбирается: оба вида, цены и время смены', function () {
    $s = stock_parse_message(sk_fixture('command'));
    assert_eq(['normal', 'mirage'], array_keys($s), 'оба вида');
    assert_eq([
        ['key' => 'spike', 'name' => 'Spike', 'price' => 180000],
        ['key' => 'ice', 'name' => 'Ice', 'price' => 350000],
        ['key' => 'light', 'name' => 'Light', 'price' => 650000],
        ['key' => 'gravity', 'name' => 'Gravity', 'price' => 2500000],
    ], $s['normal']['fruits'], 'обычный сток');
    assert_eq(1790899211, $s['normal']['ends'], 'метка смены обычного');
    assert_eq(['bomb', 'dark', 'diamond', 'rubber', 'buddha', 'pain'],
        array_column($s['mirage']['fruits'], 'key'), 'Mirage');
    assert_eq(2300000, $s['mirage']['fruits'][5]['price'], 'цена Pain');
    assert_eq(1790899272, $s['mirage']['ends'], 'метка смены Mirage');
});

// Автопост при смене — новые компоненты Discord (flags 32768): контейнер
// type 17 с текстовыми блоками type 10, заголовок «Current Normal Stock», по
// одному виду на сообщение. Снят из канала 2026-10-02 в 00:02 UTC.
test('настоящий автопост Vulcan разбирается: один вид, фрукты и время смены', function () {
    $s = stock_parse_message(sk_fixture('autopost'));
    assert_eq(['normal'], array_keys($s), 'только обычный сток');
    assert_eq([
        ['key' => 'flame', 'name' => 'Flame', 'price' => 250000],
        ['key' => 'eagle', 'name' => 'Eagle', 'price' => 550000],
    ], $s['normal']['fruits'], 'фрукты');
    assert_eq(1790913612, $s['normal']['ends'], 'смена через четыре часа');
});

// Тот же формат с заголовком Mirage, упоминанием роли и кнопкой в контейнере
// (так выглядел автопост в основном канале).
test('автопост в новых компонентах Discord разбирается так же', function () {
    $msg = [
        'id' => '1', 'author' => ['bot' => true], 'content' => '', 'embeds' => [],
        'components' => [[
            'type' => 17,
            'components' => [
                ['type' => 10, 'content' => '### Current Mirage Stock'],
                ['type' => 14],
                ['type' => 10, 'content' => "<:blade:1> **Blade** • <:money:2> `30,000`\n<:smoke:3> **Smoke** • <:money:2> `100,000`\n<:magma:4> **Magma** • <:money:2> `960,000`"],
                ['type' => 14],
                ['type' => 10, 'content' => '-# <:clock:5> **Stock Change in** - <t:1790906400:R> <@&123>'],
                ['type' => 1, 'components' => [['type' => 2, 'style' => 5, 'label' => 'Trade your fruits', 'url' => 'https://example.com']]],
            ],
        ]],
    ];
    $s = stock_parse_message($msg);
    assert_eq(['mirage'], array_keys($s), 'только Mirage');
    assert_eq(['blade', 'smoke', 'magma'], array_column($s['mirage']['fruits'], 'key'), 'фрукты');
    assert_eq(960000, $s['mirage']['fruits'][2]['price'], 'цена');
    assert_eq(1790906400, $s['mirage']['ends'], 'время смены');
});

test('строка без цены, кнопка и болтовня фруктами не становятся', function () {
    $s = stock_parse_text("NORMAL STOCK\nhello there\n**Stock Change in** - <t:1790899211:R>\nJoin • server");
    assert_eq([], $s, 'ни одного фрукта — вида нет');
    assert_eq([], stock_parse_text("Spike • `180,000`"), 'фрукт без заголовка вида не засчитывается');
});

test('ключ фрукта не зависит от регистра, дефисов и скобок', function () {
    assert_eq('trex', stock_fruit_key('T-Rex'));
    assert_eq('trex', stock_fruit_key('T-rex'));
    assert_eq('dragon', stock_fruit_key('Dragon (West + East)'));
    assert_eq('lightning', stock_fruit_key('Lighting'), 'опечатка тирлиста сводится к Lightning');
});

// --------------------------------------------------------------------------
//  Смены
// --------------------------------------------------------------------------

test('первый сток — новая смена, тот же сток с той же меткой — нет', function () {
    $pdo = sk_db();
    $p = stock_parse_message(sk_fixture('command'));
    assert_eq(['normal', 'mirage'], array_keys(stock_apply($pdo, $p, '10', SK_NOW)), 'оба вида новые');
    assert_eq([], stock_apply($pdo, $p, '11', SK_NOW + 60), 'повтор той же смены');
    $p['mirage']['ends'] += 7200;
    assert_eq(['mirage'], array_keys(stock_apply($pdo, $p, '12', SK_NOW + 7200)), 'Mirage сменился');
    assert_eq(1790899272 + 7200, stock_read($pdo)['mirage']['ends'], 'в базе новая метка');
});

test('сообщение со старой сменой свежий сток не затирает', function () {
    $pdo = sk_db();
    $p = stock_parse_message(sk_fixture('command'));
    stock_apply($pdo, $p, '20', SK_NOW);
    $old = $p;
    $old['normal']['ends'] -= 14400;
    $old['normal']['fruits'] = [['key' => 'rocket', 'name' => 'Rocket', 'price' => 5000]];
    assert_eq([], stock_apply($pdo, $old, '19', SK_NOW), 'старая смена не новая');
    assert_eq('spike', stock_read($pdo)['normal']['fruits'][0]['key'], 'сток прежний');
});

// --------------------------------------------------------------------------
//  Сбор из Discord
// --------------------------------------------------------------------------

test('без токена или канала сбор выключен и в Discord не ходит', function () {
    $pdo = sk_db();
    $paths = [];
    $r = stock_pull($pdo, stock_config(['discord_bot_token' => '', 'discord_stock_channel' => SK_CHANNEL]),
        sk_discord([], $paths), SK_NOW);
    assert_eq('off', $r['error'], 'выключено');
    $r = stock_pull($pdo, stock_config(['discord_bot_token' => SK_DC_TOKEN, 'discord_stock_channel' => 'abc']),
        sk_discord([], $paths), SK_NOW);
    assert_eq('off', $r['error'], 'кривой канал — то же, что пустой');
    assert_eq([], $paths, 'запросов не было');
});

test('без таблиц сбор молчит, а не падает', function () {
    $pdo = sk_db(false);
    $paths = [];
    $r = stock_pull($pdo, stock_config(sk_cfg()), sk_discord([], $paths), SK_NOW);
    assert_eq('not_ready', $r['error'], 'not_ready');
    assert_eq([], $paths, 'в Discord не ходили');
    assert_eq(['normal' => null, 'mirage' => null], stock_read($pdo), 'страница видит пустой сток');
});

test('канал читается с последнего прочитанного сообщения, по порядку', function () {
    $pdo = sk_db();
    $paths = [];
    $sc = stock_config(sk_cfg());
    // Discord отдаёт новые первыми.
    $msgs = [sk_command('1555358551919300713', SK_NOW - 30), sk_command('1555358551919300700', SK_NOW - 90)];
    $r = stock_pull($pdo, $sc, sk_discord($msgs, $paths), SK_NOW);
    assert_true($r['ok'], 'проход удался');
    assert_eq(2, $r['read'], 'прочитано два');
    assert_eq('/channels/' . SK_CHANNEL . '/messages?limit=50', $paths[0], 'первый раз — последние сообщения');
    assert_eq(['normal', 'mirage'], array_keys($r['new']), 'обе смены новые и свежие');

    stock_pull($pdo, $sc, sk_discord([], $paths), SK_NOW + 60, true);
    assert_eq('/channels/' . SK_CHANNEL . '/messages?limit=50&after=1555358551919300713', $paths[1],
        'дальше — только после последнего прочитанного');
});

test('после простоя старые сообщения в базу ложатся, но не рассылаются', function () {
    $pdo = sk_db();
    $paths = [];
    $r = stock_pull($pdo, stock_config(sk_cfg()), sk_discord([sk_command('500', SK_NOW - 3600)], $paths), SK_NOW);
    assert_eq([], $r['new'], 'рассылать нечего');
    assert_eq('spike', stock_read($pdo)['normal']['fruits'][0]['key'], 'но сток на странице есть');
});

test('Discord ответил ошибкой — курсор не двигается', function () {
    $pdo = sk_db();
    $paths = [];
    $r = stock_pull($pdo, stock_config(sk_cfg()), sk_discord([], $paths, 429), SK_NOW);
    assert_eq('discord_429', $r['error'], 'ошибка видна');
    assert_eq('0', stock_feed_get($pdo)['last_id'], 'курсор на месте');
});

// Сток меняется по расписанию, и время смены известно точно: между сменами
// бот в Discord не ходит, с наступления смены — ходит, пока не придёт новый.
test('бот спит до смены и просыпается в её момент', function () {
    $ends  = SK_NOW + 3600;
    $stock = [
        'normal' => ['fruits' => [], 'ends' => $ends + 7200],
        'mirage' => ['fruits' => [], 'ends' => $ends],
    ];
    assert_eq(false, stock_due($stock, $ends - 60, 0), 'за минуту до смены — спит');
    assert_eq(true, stock_due($stock, $ends, $ends - 10), 'смена наступила — идёт, даже если только что ходил');
    assert_eq(true, stock_due($stock, $ends + STOCK_WINDOW, $ends + STOCK_WINDOW - 60), 'ждёт новый сток до конца окна');
    assert_eq(false, stock_due($stock, $ends + STOCK_WINDOW + 60, $ends + STOCK_WINDOW), 'Vulcan молчит — не каждую минуту');
    assert_eq(true, stock_due($stock, $ends + STOCK_WINDOW + 400, $ends + STOCK_WINDOW), '…а раз в пять минут');
});

test('без расписания бот не засыпает навсегда', function () {
    $none = ['normal' => null, 'mirage' => null];
    assert_eq(true, stock_due($none, SK_NOW, SK_NOW), 'стока нет совсем — идёт сразу');
    $half = ['normal' => ['fruits' => [], 'ends' => SK_NOW + 3600], 'mirage' => null];
    assert_eq(false, stock_due($half, SK_NOW, SK_NOW - 60), 'одного вида нет — не каждую минуту');
    assert_eq(true, stock_due($half, SK_NOW, SK_NOW - 400), '…а раз в пять минут');
    $far = ['normal' => ['fruits' => [], 'ends' => SK_NOW + 86400], 'mirage' => ['fruits' => [], 'ends' => SK_NOW + 86400]];
    assert_eq(true, stock_due($far, SK_NOW, SK_NOW - 400), 'смена неправдоподобно далеко — заглядывает раз в пять минут');
});

test('между сменами проход в Discord не ходит, -f ходит', function () {
    $pdo = sk_db();
    $paths = [];
    $sc = stock_config(sk_cfg());
    stock_pull($pdo, $sc, sk_discord([sk_command('900', SK_NOW - 30)], $paths), SK_NOW);
    $r = stock_pull($pdo, $sc, sk_discord([], $paths), SK_NOW + 60);
    assert_eq(true, $r['skipped'] ?? false, 'пропущено');
    assert_eq(1, count($paths), 'второго запроса в Discord не было');
    stock_pull($pdo, $sc, sk_discord([], $paths), SK_NOW + 60, true);
    assert_eq(2, count($paths), 'с -f — был');
    $r = stock_pull($pdo, $sc, sk_discord([], $paths), 1790899272 + 30);
    assert_eq(false, isset($r['skipped']), 'смена Mirage наступила — идёт');
    assert_eq(3, count($paths), 'запрос ушёл');
});

// --------------------------------------------------------------------------
//  Рассылка
// --------------------------------------------------------------------------

test('о смене пишут только тем, чей фрукт в ней есть, — одним сообщением', function () {
    $pdo = sk_db();
    sk_user($pdo, '1', 111);
    sk_user($pdo, '2', 222, 'en');
    sk_user($pdo, '3', 333);
    sk_user($pdo, '4');
    sk_watch($pdo, '1', ['dark', 'pain', 'kitsune']);
    sk_watch($pdo, '2', ['gravity']);
    sk_watch($pdo, '3', ['kitsune']);
    sk_watch($pdo, '4', ['dark']);

    $log = [];
    $paths = [];
    $r = stock_pull_and_notify($pdo, sk_cfg(), SK_NOW,
        sk_discord([sk_command('600', SK_NOW - 20)], $paths), sk_telegram($log), sk_no_sleep());
    assert_eq(2, $r['sent'], 'ушло двоим');
    assert_eq(2, count($log), 'два сообщения');

    $by = [];
    foreach ($log as [$method, $p]) { $by[$p['chat_id']] = $p; }
    assert_true(!isset($by[333]), 'Kitsune в стоке нет — третьему не пишем');
    assert_eq("🔔 Сток Mirage\nDark — 500 000\nPain — 2 300 000\n\n⏳ Смена через 38 мин",
        $by[111]['text'], 'одно сообщение со всеми совпадениями вида');
    assert_eq("🔔 Normal stock\nGravity — 2,500,000\n\n⏳ Next change in 37 min", $by[222]['text'], 'по-английски');
    assert_eq('https://maknemy.com/stock#watch', $by[111]['reply_markup']['inline_keyboard'][1][0]['url'],
        'кнопка настройки ведёт к отметкам');

    $log = [];
    $r = stock_pull_and_notify($pdo, sk_cfg(), SK_NOW + 120,
        sk_discord([sk_command('601', SK_NOW + 100)], $paths), sk_telegram($log), sk_no_sleep(), true);
    assert_eq(1, $r['read'], 'сообщение прочитано');
    assert_eq(0, $r['sent'], 'ответ на /stock посреди смены — второй рассылки нет');
    assert_eq([], $log, 'Telegram молчит');
});

test('один Telegram на два аккаунта получает одно сообщение', function () {
    $pdo = sk_db();
    sk_user($pdo, '1', 777);
    sk_user($pdo, '2', 777);
    sk_watch($pdo, '1', ['spike']);
    sk_watch($pdo, '2', ['ice']);
    $log = [];
    $s = stock_parse_message(sk_fixture('command'))['normal'];
    assert_eq(1, stock_notify($pdo, tg_config(sk_cfg()), sk_telegram($log), 'normal', $s, SK_NOW, sk_no_sleep()), 'одно');
    assert_true(strpos($log[0][1]['text'], "Spike — 180 000\nIce — 350 000") !== false, 'с фруктами обоих');
});

test('нераспознанный пост бота — одно предупреждение админу, не каждую минуту', function () {
    $pdo = sk_db();
    sk_user($pdo, '9', 999);
    $weird = ['id' => '700', 'timestamp' => gmdate('c', SK_NOW), 'author' => ['bot' => true], 'content' => '',
              'embeds' => [['title' => 'Stock', 'description' => 'something new and shiny']]];
    $log = [];
    $paths = [];
    $cfg = sk_cfg(['admin_ids' => ['9']]);
    $r = stock_pull_and_notify($pdo, $cfg, SK_NOW, sk_discord([$weird], $paths), sk_telegram($log), sk_no_sleep());
    assert_eq(['700'], $r['unparsed'], 'замечено');
    assert_eq(1, count($log), 'админ предупреждён');
    assert_eq(999, $log[0][1]['chat_id'], 'именно админ');

    $log = [];
    stock_feed_save($pdo, '0', '700', SK_NOW);
    $r = stock_pull_and_notify($pdo, $cfg, SK_NOW + 60, sk_discord([$weird], $paths), sk_telegram($log), sk_no_sleep());
    assert_eq([], $r['unparsed'], 'о том же сообщении второй раз не говорим');
    assert_eq([], $log, 'тишина');
});

// --------------------------------------------------------------------------
//  Отметки
// --------------------------------------------------------------------------

test('отметки: только вошедшим, только настоящий true/false, только ключ фрукта', function () {
    $pdo = sk_db();
    sk_user($pdo, '1');
    assert_eq(401, stock_watch_set($pdo, '', ['fruit' => 'dark', 'on' => true])[0], 'гость');
    assert_eq(400, stock_watch_set($pdo, '1', ['fruit' => 'dark', 'on' => 1])[0], '1 вместо true');
    assert_eq(400, stock_watch_set($pdo, '1', ['fruit' => 'Dark Fruit', 'on' => true])[0], 'не ключ');
    [$code, $out] = stock_watch_set($pdo, '1', ['fruit' => 'dark', 'on' => true]);
    assert_eq([200, ['dark']], [$code, $out['watch']], 'отмечено');
    [, $out] = stock_watch_set($pdo, '1', ['fruit' => 'dark', 'on' => true]);
    assert_eq(['dark'], $out['watch'], 'повторная отметка ничего не дублирует');
    [, $out] = stock_watch_set($pdo, '1', ['fruit' => 'dark', 'on' => false]);
    assert_eq([], $out['watch'], 'снято');
    assert_eq(401, stock_watch_set($pdo, '42', ['fruit' => 'dark', 'on' => true])[0], 'аккаунта нет в users');
});

test('без таблицы отметок — 503, а страница прячет блок', function () {
    $pdo = sk_db(false);
    sk_user($pdo, '1');
    assert_eq(null, stock_watch_get($pdo, '1'), 'null');
    assert_eq(503, handle_stock_watch($pdo, sk_cfg(), '1', ['action' => 'get'])[0], 'get');
    assert_eq(503, stock_watch_set($pdo, '1', ['fruit' => 'dark', 'on' => true])[0], 'set');
});

test('get отдаёт отметки и состояние Telegram', function () {
    $pdo = sk_db();
    sk_user($pdo, '1', 111);
    sk_watch($pdo, '1', ['pain', 'dark']);
    [$code, $out] = handle_stock_watch($pdo, sk_cfg(), '1', ['action' => 'get']);
    assert_eq(200, $code, '200');
    assert_eq(['dark', 'pain'], $out['watch'], 'отметки');
    assert_eq(true, $out['tg']['on'], 'бот включён');
    assert_eq(true, $out['tg']['linked'], 'Telegram подключён');
    assert_eq(401, handle_stock_watch($pdo, sk_cfg(), '', ['action' => 'get'])[0], 'гостю — 401');
});

// --------------------------------------------------------------------------
//  Что видит страница
// --------------------------------------------------------------------------

test('список фруктов — из пермов тирлиста, картинка — перма', function () {
    $tier = ['tiers' => [['items' => [
        ['name' => 'Kitsune Fruit', 'type' => 'f', 'icon' => 'https://maknemy.com/images/kitsune-fruit.webp'],
        ['name' => 'Permanent Kitsune', 'type' => 'p', 'icon' => '/images/kitsune-perm.webp'],
        ['name' => 'Permanent Spike', 'type' => 'p', 'icon' => '/images/spike.png'],
        ['name' => 'Permanent T-rex', 'type' => 'p', 'icon' => '/images/trex.webp'],
        ['name' => 'Permanent Lighting', 'type' => 'p', 'icon' => '/images/light.webp'],
        ['name' => 'Permanent Dragon (West + East)', 'type' => 'p', 'icon' => '/images/dragon.webp'],
        ['name' => 'Dragon Token (Permanent)', 'type' => 'p', 'icon' => '/images/token.webp'],
        ['name' => 'Permanent Evil', 'type' => 'p', 'icon' => 'https://evil.example/x.png'],
    ]]]];
    $stock = ['normal' => ['fruits' => [['key' => 'yeti', 'name' => 'Yeti', 'price' => 1]]], 'mirage' => null];
    $cat = stock_catalog($tier, $stock);
    $by = [];
    foreach ($cat as $c) { $by[$c['key']] = $c; }
    assert_eq(['dragon', 'kitsune', 'trex', 'lightning', 'spike', 'yeti', 'evil'], array_column($cat, 'key'),
        'дорогие сверху, без жетона; цена из стока важнее таблицы; без цены — в конце');
    assert_eq('mythical', $by['dragon']['rarity'], 'Dragon — мифический');
    assert_eq('legendary', $by['lightning']['rarity'], 'Lightning — легендарный');
    assert_eq('common', $by['spike']['rarity'], 'Spike — обычный');
    assert_eq('', $by['evil']['rarity'], 'без цены — без редкости');
    assert_eq('/images/kitsune-perm.webp', $by['kitsune']['icon'], 'у всех фруктов картинка перма');
    assert_eq('/images/spike.png', $by['spike']['icon'], 'у дешёвого — тоже перм');
    assert_eq('T-Rex', $by['trex']['name'], 'имя приведено');
    assert_eq('Lightning', $by['lightning']['name'], 'опечатка исправлена');
    assert_eq('Dragon', $by['dragon']['name'], 'без пояснения в скобках');
    assert_eq('', $by['evil']['icon'], 'чужой адрес картинки не проходит');
    assert_eq('', $by['yeti']['icon'], 'новый фрукт из стока — без картинки, но в списке');
});

test('редкость считается по цене так же, как в игре', function () {
    assert_eq('common', stock_rarity(180000), 'Spike');
    assert_eq('uncommon', stock_rarity(250000), 'Flame — граница');
    assert_eq('rare', stock_rarity(650000), 'Light — граница');
    assert_eq('legendary', stock_rarity(1000000), 'Quake — граница');
    assert_eq('mythical', stock_rarity(2500000), 'Gravity — граница');
    assert_eq('', stock_rarity(null), 'цена неизвестна');
});

test('ответ api/stock.php: сток, подпись источника, список фруктов', function () {
    $pdo = sk_db();
    stock_apply($pdo, stock_parse_message(sk_fixture('command')), '10', SK_NOW);
    $out = stock_public($pdo, []);
    assert_eq("Fool's eyes", $out['source'], 'источник — наш бот');
    assert_eq(1790899211, $out['normal']['ends'], 'метка смены');
    assert_eq(SK_NOW, $out['normal']['seen'], 'когда видели');
    assert_eq(false, isset($out['normal']['message_id']), 'id сообщения Discord наружу не уходит');
    assert_eq(10, count($out['catalog']), 'список из фруктов стока, когда тирлиста нет');
    assert_eq('mythical', $out['normal']['fruits'][3]['rarity'], 'у фрукта стока есть редкость');
    assert_eq(14400, $out['normal']['period'], 'длина смены обычного — 4 часа');
    assert_eq(7200, $out['mirage']['period'], 'Mirage — 2 часа');
});

// --------------------------------------------------------------------------
//  Файлы
// --------------------------------------------------------------------------

test('api/stock_pull.php из браузера не запускается', function () {
    $src = file_get_contents(__DIR__ . '/../public_html/api/stock_pull.php');
    $guard = strpos($src, "if (isset(\$_SERVER['REQUEST_METHOD'])) {");
    $boot  = strpos($src, "require_once __DIR__ . '/_bootstrap.php';");
    assert_true($guard !== false && $boot !== false && $guard < $boot, 'проверка раньше всего остального');
    $ht = file_get_contents(__DIR__ . '/../public_html/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^api/stock_pull\.php$ - [R=404,L]') !== false, '.htaccess прячет его');
});

test('страница /stock: маршрут, подпись бота, свой скрипт без встроенного кода', function () {
    $pub = __DIR__ . '/../public_html';
    $ht = file_get_contents($pub . '/.htaccess');
    assert_true(strpos($ht, 'RewriteRule ^stock$ /stock.php [L]') !== false, 'маршрут /stock');
    $page = file_get_contents($pub . '/stock.php');
    assert_true(strpos($page, "<b>Fool's eyes</b>") !== false, 'подпись источника');
    assert_true(strpos($page, 'id="watch"') !== false, 'якорь для кнопки из Telegram');
    assert_true((bool)preg_match('~<script src="js/stock-page\.js\?v=\d+"~', $page), 'скрипт страницы с версией');
    assert_true((bool)preg_match('~<link rel="stylesheet" href="css/stock\.css\?v=\d+"~', $page), 'стили с версией');
    assert_true(strpos(file_get_contents($pub . '/sitemap.xml'), 'https://maknemy.com/stock') !== false, 'в sitemap');
});

// Реклама как на трейдинге: борта по бокам, нижняя плашка на телефоне,
// всплывашка — и вдобавок баннер по центру, как в тирлисте (слот strip).
test('на /stock стоят все рекламные места', function () {
    $pub  = __DIR__ . '/../public_html';
    $page = file_get_contents($pub . '/stock.php');
    foreach (['id="skRail"', 'id="skRailR"', 'id="skMid"', 'id="promoDock"', 'id="promoPop"'] as $slot) {
        assert_true(strpos($page, $slot) !== false, "место $slot");
    }
    foreach (['promo.js', 'promo-feed.js', 'promo-dock.js', 'promo-popup.js'] as $js) {
        assert_true((bool)preg_match('~<script src="js/' . preg_quote($js, '~') . '\?v=\d+"~', $page), "подключён $js");
    }
    assert_true(strpos($page, '<script src="js/promo.js') < strpos($page, '<script src="js/stock-page.js'), 'реклама раньше скрипта страницы');
    $js = file_get_contents($pub . '/js/stock-page.js');
    assert_true(strpos($js, 'NX_PROMO_FEED.banner(') !== false, 'баннер по центру — из модуля лент');
    assert_true(strpos($js, 'NX_PROMO_DOCK.render(') !== false, 'нижняя плашка');
    assert_true(strpos($js, 'NX_PROMO_POPUP.mount(') !== false, 'всплывашка');
});

test('подписи страницы есть в обоих языках словаря', function () {
    $i18n = file_get_contents(__DIR__ . '/../public_html/js/i18n.js');
    $page = file_get_contents(__DIR__ . '/../public_html/stock.php') . file_get_contents(__DIR__ . '/../public_html/js/stock-page.js');
    preg_match_all('~(?:data-i18n(?:-label)?="|\bt\("|fmt\(")((?:stock|nav)\.[A-Za-z]+)(?=")~', $page, $m);
    $keys = array_unique($m[1]);
    assert_true(count($keys) > 15, 'ключи нашлись');
    // Ключи редкости собираются в скрипте из кусков («stock.r.» + редкость),
    // поверка выше их не видит.
    foreach (['common', 'uncommon', 'rare', 'legendary', 'mythical'] as $r) {
        $keys[] = 'stock.r.' . $r;
        $keys[] = 'stock.g.' . $r;
    }
    $keys[] = 'stock.g.other';
    foreach ($keys as $k) {
        assert_eq(2, substr_count($i18n, '"' . $k . '":'), "ключ $k в ru и en");
    }
});

run_tests();
