<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/giveaway.php';
require __DIR__ . '/../public_html/api/giveaways.php';
require __DIR__ . '/../public_html/api/giveaway_admin.php';
require __DIR__ . '/../public_html/api/session.php';

// Розыгрыши — api/lib/giveaway.php и его эндпоинты.
//
// В сеть тесты не ходят: Telegram подменён записывающим $send, который
// отвечает на getChatMember по таблице «кто где подписан».
//
// Главные обещания:
//  - участвовать можно только вошедшему, с подключённым ботом и подписанному
//    на все каналы условий; один Telegram — одно участие;
//  - победителей выбирает кнопка после конца; один человек — один приз;
//    отписавшийся, забаненный и отключивший бота не выигрывают;
//  - «Telegram не ответил» никого не лишает приза: розыгрыш просто не пишется;
//  - о старте бот пишет всем подключившим бота, даже выключившим рассылки;
//  - черновик снаружи не виден.

const GW_NOW   = 1790000000;
const GW_TOKEN = '123456789:AAHfakeTokenForTestsOnly_abcdefghij';
const GW_CH_A  = -1001111111111;
const GW_CH_B  = -1002222222222;

function gw_db(): PDO {
    $pdo = test_db();
    $pdo->exec("CREATE TABLE tg_links (
        user_id INTEGER NOT NULL PRIMARY KEY, chat_id INTEGER NOT NULL,
        tg_name TEXT NOT NULL DEFAULT '', lang TEXT NOT NULL DEFAULT 'ru', linked_at INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE tg_codes (
        code_hash TEXT NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL,
        lang TEXT NOT NULL DEFAULT 'ru', expires_at INTEGER NOT NULL)");
    $pdo->exec('CREATE TABLE chat_reads (
        thread_id INTEGER NOT NULL, user_id INTEGER NOT NULL, last_read_id INTEGER NOT NULL DEFAULT 0,
        seen_at INTEGER NOT NULL DEFAULT 0, notified_id INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (thread_id, user_id))');
    $pdo->exec('CREATE TABLE tg_prefs (
        user_id INTEGER NOT NULL PRIMARY KEY, prices INTEGER NOT NULL DEFAULT 1, news INTEGER NOT NULL DEFAULT 1)');
    // Зеркалит docs/migrations/2026-10-14-giveaways.sql.
    $pdo->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft',
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL,
        ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec('CREATE TABLE giveaway_entries (
        giveaway_id INTEGER NOT NULL, user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL,
        joined_at INTEGER NOT NULL, prize INTEGER NULL,
        PRIMARY KEY (giveaway_id, user_id), UNIQUE (giveaway_id, chat_id))');
    $pdo->exec("CREATE TABLE tg_channels (
        chat_id INTEGER NOT NULL PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL)");
    return $pdo;
}

function gw_cfg(array $extra = []): array {
    return array_merge(['tg_bot_token' => GW_TOKEN, 'tg_bot_name' => 'MaknemyBot'], $extra);
}

function gw_user(PDO $p, string $id, string $name, ?int $chat = null, string $lang = 'ru'): string {
    $p->prepare('INSERT INTO users (roblox_id, username, display_name, avatar_url, created_at, last_login_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, $name, ucfirst($name), '', GW_NOW - 86400, GW_NOW - 3600, GW_NOW - 3600]);
    if ($chat !== null) {
        $p->prepare('INSERT INTO tg_links (user_id, chat_id, tg_name, lang, linked_at) VALUES (?, ?, ?, ?, ?)')
          ->execute([$id, $chat, '@' . $name, $lang, GW_NOW - 100]);
    }
    return $id;
}

function gw_channel(PDO $p, int $id, string $title, bool $admin = true, string $username = ''): void {
    $p->prepare('INSERT INTO tg_channels (chat_id, title, username, kind, invite_url, is_admin, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$id, $title, $username, 'channel', $username === '' ? 'https://t.me/+secret' : '', $admin ? 1 : 0, GW_NOW]);
}

function gw_spans(string $s): array { return [['s' => $s]]; }

function gw_body(array $paras): array {
    $blocks = [];
    foreach ($paras as $p) { $blocks[] = ['t' => 'p', 'ru' => gw_spans($p), 'en' => []]; }
    return ['v' => 1, 'blocks' => $blocks];
}

function gw_draft(array $extra = []): array {
    return array_merge([
        'title_ru' => 'Розыгрыш Magnet',
        'title_en' => '',
        'image'    => ['url' => '/images/' . str_repeat('a', 40) . '.png', 'w' => 800, 'h' => 600],
        'body'     => gw_body(['Первый абзац', 'Второй абзац']),
        'prizes'   => [['name' => 'Magnet', 'count' => 2], ['name' => 'Chromatic Box', 'count' => 1]],
        'channels' => [],
        'ends_at'  => GW_NOW + 86400,
    ], $extra);
}

// Сохранить черновик и сразу опубликовать. Возвращает id.
function gw_live(PDO $p, array $extra = []): int {
    [$s, $out] = gw_save($p, gw_draft($extra), GW_NOW - 10);
    assert_eq(200, $s, 'черновик сохранён');
    [$s] = gw_publish($p, $out['id'], GW_NOW - 5);
    assert_eq(200, $s, 'опубликован');
    return $out['id'];
}

// Подставной Telegram. $subs — [канал => [tg-пользователь, …]] подписанных.
// $down — каналы, на которых getChatMember не отвечает.
function gw_tg(array &$log, array $subs = [], array $down = []): callable {
    return function (string $method, array $params) use (&$log, $subs, $down): ?array {
        $log[] = [$method, $params];
        if ($method === 'getChatMember') {
            if (in_array($params['chat_id'], $down, true)) { return null; }
            $in = in_array($params['user_id'], $subs[$params['chat_id']] ?? [], true);
            return ['ok' => true, 'result' => ['status' => $in ? 'member' : 'left']];
        }
        return ['ok' => true, 'result' => []];
    };
}

function gw_sent(array $log, string $method = 'sendMessage'): array {
    return array_values(array_filter($log, function ($c) use ($method) { return $c[0] === $method; }));
}

// --------------------------------------------------------------------------
//  Черновик
// --------------------------------------------------------------------------

test('пустой черновик сохраняется, публикация говорит, чего не хватает', function () {
    $p = gw_db();
    [$s, $out] = gw_save($p, ['title_ru' => '', 'prizes' => [], 'ends_at' => 0], GW_NOW);
    assert_eq(200, $s, 'черновик пустым можно');
    [$s, $out] = gw_publish($p, $out['id'], GW_NOW);
    assert_eq(409, $s, 'публиковать нельзя');
    assert_eq(['no_title', 'no_prizes', 'ends_in_past'], $out['problems'], 'что именно не так');
});

test('проверка полей: призы, картинка, блоки описания', function () {
    $bad = [
        'приз без имени'       => ['prizes' => [['name' => '  ', 'count' => 1]]],
        'приз 0 штук'          => ['prizes' => [['name' => 'Magnet', 'count' => 0]]],
        'приз дробью'          => ['prizes' => [['name' => 'Magnet', 'count' => 1.5]]],
        'картинка чужая'       => ['image' => ['url' => 'https://evil.example/x.png', 'w' => 1, 'h' => 1]],
        'картинка в описании'  => ['body' => ['v' => 1, 'blocks' => [['t' => 'image', 'url' => '/images/' . str_repeat('a', 40) . '.png',
            'w' => 1, 'h' => 1, 'pct' => 100, 'align' => 'center', 'wrap' => false, 'cap_ru' => [], 'cap_en' => []]]]],
        'ссылка javascript:'   => ['body' => ['v' => 1, 'blocks' => [['t' => 'p', 'ru' => [['s' => 'x', 'href' => 'javascript:alert(1)']], 'en' => []]]]],
        'канал не числом'      => ['channels' => ['@channel']],
        'шесть каналов'        => ['channels' => [-1, -2, -3, -4, -5, -6]],
        'длинное название'     => ['title_ru' => str_repeat('я', GW_TITLE_MAX + 1)],
    ];
    foreach ($bad as $why => $patch) {
        assert_eq(false, gw_validate(gw_draft($patch))['ok'], $why);
    }
    $v = gw_validate(gw_draft(['channels' => [(string)GW_CH_A, GW_CH_A, GW_CH_B]]));
    assert_true($v['ok'], 'хороший черновик проходит');
    assert_eq([GW_CH_A, GW_CH_B], $v['row']['channels'], 'каналы без повторов, строкой и числом');
});

test('разыгранный розыгрыш не правится', function () {
    $p = gw_db();
    $id = gw_live($p);
    $p->exec("UPDATE giveaways SET status = 'drawn'");
    [$s, $out] = gw_save($p, gw_draft(['id' => $id]), GW_NOW);
    assert_eq(409, $s, 'правка после итогов');
    assert_eq('drawn', $out['error']);
});

test('автоперевод: пустой EN переводится, неизменённый абзац не гоняется заново', function () {
    $p = gw_db();
    $asked = [];
    // Переводчик получает тексты пачкой через пустую строку — как Google.
    $tr = function (string $text) use (&$asked): ?string {
        $asked[] = $text;
        return implode(TR_SEP, array_map(function ($t) { return 'EN:' . $t; }, explode(TR_SEP, $text)));
    };
    [$s, $out] = gw_save($p, gw_draft(), GW_NOW, $tr);
    assert_eq(200, $s);
    $row = gw_load($p, $out['id']);
    assert_eq('EN:Розыгрыш Magnet', $row['title_en'], 'название переведено');
    $blocks = gw_decode_blocks($row['body_json']);
    assert_eq('EN:Первый абзац', $blocks[0]['en'][0]['s'], 'абзац переведён');

    $asked = [];
    [$s] = gw_save($p, gw_draft(['id' => $out['id'], 'title_en' => $row['title_en'],
        'body' => gw_body(['Первый абзац', 'Новый второй'])]), GW_NOW, $tr);
    assert_eq(200, $s);
    $joined = implode('|', $asked);
    assert_true(strpos($joined, 'Новый второй') !== false, 'новый абзац переведён');
    assert_true(strpos($joined, 'Первый абзац') === false, 'старый взят из прежней версии');
    $blocks = gw_decode_blocks(gw_load($p, $out['id'])['body_json']);
    assert_eq('EN:Первый абзац', $blocks[0]['en'][0]['s'], 'EN старого абзаца на месте');
});

// --------------------------------------------------------------------------
//  Публикация и рассылка
// --------------------------------------------------------------------------

test('публикация: канал без прав бота не пускает', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал', false);
    [$s, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]]), GW_NOW);
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW);
    assert_eq(409, $s);
    assert_eq(['channel_not_admin'], $res['problems']);
});

test('о старте бот пишет всем, даже выключившим рассылки, по разу на Telegram', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502, 'en');
    gw_user($p, '3', 'cat', 501);           // второй аккаунт того же Telegram
    gw_user($p, '4', 'dan');                // бот не подключён
    $p->exec('INSERT INTO tg_prefs (user_id, prices, news) VALUES (2, 0, 0)');
    $id = gw_live($p);
    $log = [];
    $n = gw_announce($p, gw_tg($log), gw_load($p, $id), function () {});
    assert_eq(2, $n, 'два Telegram — два сообщения');
    $msgs = gw_sent($log);
    assert_eq([501, 502], array_map(function ($c) { return $c[1]['chat_id']; }, $msgs), 'кому');
    assert_true(strpos($msgs[0][1]['text'], 'Magnet × 2') !== false, 'призы с количеством');
    assert_true(strpos($msgs[0][1]['text'], 'Chromatic Box') !== false && strpos($msgs[0][1]['text'], 'Box ×') === false,
        'приз на одного — без «× 1»');
    assert_true(strpos($msgs[1][1]['text'], 'New giveaway') !== false, 'английский — по языку привязки');
    assert_eq('https://maknemy.com/giveaways#g' . $id, $msgs[0][1]['reply_markup']['inline_keyboard'][0][0]['url'], 'кнопка ведёт на розыгрыш');
    assert_eq('https://maknemy.com/images/' . str_repeat('a', 40) . '.png', $msgs[0][1]['link_preview_options']['url'], 'картинка превью');
});

test('повторная публикация ничего не шлёт', function () {
    $p = gw_db();
    $id = gw_live($p);
    [$s, , $row] = gw_publish($p, $id, GW_NOW);
    assert_eq(409, $s);
    assert_eq(null, $row, 'рассылки нет');
});

// --------------------------------------------------------------------------
//  Участие
// --------------------------------------------------------------------------

test('участие: вход, бот, подписка, один Telegram', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob');
    gw_user($p, '3', 'cat', 501);
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    $cfg = gw_cfg();
    $log = [];

    assert_eq('login', gw_enter($p, $cfg, gw_tg($log), '', $id, GW_NOW)[1]['error'], 'гость');
    assert_eq('tg', gw_enter($p, $cfg, gw_tg($log), '2', $id, GW_NOW)[1]['error'], 'без бота');

    [$s, $out] = gw_enter($p, $cfg, gw_tg($log), '1', $id, GW_NOW);
    assert_eq(409, $s, 'не подписан');
    assert_eq(['channels', [(string)GW_CH_A]], [$out['error'], $out['missing']], 'какой канал');

    $subs = [GW_CH_A => [501]];
    [$s, $out] = gw_enter($p, $cfg, gw_tg($log, $subs), '1', $id, GW_NOW);
    assert_eq([200, true], [$s, $out['joined']], 'подписался — участвует');
    [$s, $out] = gw_enter($p, $cfg, gw_tg($log, $subs), '1', $id, GW_NOW);
    assert_eq([200, true], [$s, $out['joined']], 'повторно — тоже ok');
    assert_eq('tg_used', gw_enter($p, $cfg, gw_tg($log, $subs), '3', $id, GW_NOW)[1]['error'], 'тот же Telegram с другого аккаунта');
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'одна запись');
});

test('участие: Telegram молчит — ошибка, а не «не подписан»', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    $log = [];
    [$s, $out] = gw_enter($p, gw_cfg(), gw_tg($log, [], [GW_CH_A]), '1', $id, GW_NOW);
    assert_eq([503, 'tg_down'], [$s, $out['error']]);
});

test('участие: черновик, конец, бан', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    [, $draft] = gw_save($p, gw_draft(), GW_NOW);
    $log = [];
    assert_eq('closed', gw_enter($p, gw_cfg(), gw_tg($log), '1', $draft['id'], GW_NOW)[1]['error'], 'черновик');
    $id = gw_live($p);
    assert_eq('closed', gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW + 86400)[1]['error'], 'время вышло');
    assert_eq('banned', gw_enter($p, gw_cfg(['banned_ids' => ['1']]), gw_tg($log), '1', $id, GW_NOW)[1]['error'], 'бан');
    assert_eq('closed', gw_enter($p, gw_cfg(), gw_tg($log), '1', 999, GW_NOW)[1]['error'], 'нет такого');
});

test('без условий хватает бота', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    $id = gw_live($p);
    $log = [];
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW)[0]);
    assert_eq([], gw_sent($log, 'getChatMember'), 'Telegram не спрашивали');
});

// --------------------------------------------------------------------------
//  Розыгрыш
// --------------------------------------------------------------------------

// Пять участников: 1–3 подписаны, 4 отписался, 5 отключил бота.
function gw_crowd(PDO $p, int $id): void {
    foreach (['1' => 501, '2' => 502, '3' => 503, '4' => 504, '5' => 505] as $u => $chat) {
        gw_user($p, $u, 'u' . $u, $chat);
        $p->prepare('INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at) VALUES (?, ?, ?, ?)')
          ->execute([$id, $u, $chat, GW_NOW - 100 + (int)$u]);
    }
    $p->exec('DELETE FROM tg_links WHERE user_id = 5');
}

test('разыграть можно только после конца и один раз', function () {
    $p = gw_db();
    $id = gw_live($p);
    $log = [];
    assert_eq('not ended', gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW)[1]['error'], 'ещё идёт');
    [$s] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    assert_eq(200, $s, 'после конца');
    assert_eq('drawn', gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86401)[1]['error'], 'второй раз нельзя');
});

test('призы по порядку, один человек — один приз, мимо отписавшихся и без бота', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    gw_crowd($p, $id);
    $log = [];
    $subs = [GW_CH_A => [501, 502, 503, 505]];
    // Перемешивание «ничего не меняет»: порядок — по времени участия.
    $same = function (int $n): int { return $n - 1; };
    [$s, $out, $winners] = gw_draw($p, gw_cfg(), gw_tg($log, $subs), $id, GW_NOW + 86400, $same);
    assert_eq(200, $s);
    assert_eq(['1' => 0, '2' => 0, '3' => 1], array_column($winners, 'prize', 'user_id'), 'Magnet ×2, затем Box');
    assert_eq([3, 3, 5], [$out['winners'], $out['slots'], $out['entries']], 'итоги для админки');
    $rows = $p->query('SELECT user_id, prize FROM giveaway_entries ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_eq([1 => 0, 2 => 0, 3 => 1, 4 => null, 5 => null], array_map(function ($v) { return $v === null ? null : (int)$v; }, $rows), 'записано в базу');
    assert_eq('drawn', gw_load($p, $id)['status']);
});

test('участников меньше призов — сколько есть', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    $id = gw_live($p, ['channels' => [GW_CH_A], 'prizes' => [['name' => 'Magnet', 'count' => 10]]]);
    gw_crowd($p, $id);
    $log = [];
    [, $out, $winners] = gw_draw($p, gw_cfg(['banned_ids' => ['2']]), gw_tg($log, [GW_CH_A => [501, 502, 503, 504]]), $id, GW_NOW + 86400);
    $ids = array_column($winners, 'user_id');
    sort($ids);
    assert_eq(['1', '3', '4'], $ids, 'забаненный и без бота мимо');
    assert_eq(10, $out['slots']);
});

test('Telegram не ответил на розыгрыше — ничего не записано', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    gw_crowd($p, $id);
    $log = [];
    [$s, $out] = gw_draw($p, gw_cfg(), gw_tg($log, [], [GW_CH_A]), $id, GW_NOW + 86400);
    assert_eq([503, 'tg_down'], [$s, $out['error']]);
    assert_eq('live', gw_load($p, $id)['status'], 'можно нажать ещё раз');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries WHERE prize IS NOT NULL')->fetchColumn());
});

test('победителям бот пишет их приз', function () {
    $p = gw_db();
    $id = gw_live($p);
    gw_crowd($p, $id);
    $p->exec("UPDATE tg_links SET lang = 'en' WHERE user_id = 3");
    $log = [];
    $same = function (int $n): int { return $n - 1; };
    [, , $winners] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400, $same);
    $log = [];
    assert_eq(3, gw_notify_winners($p, gw_tg($log), gw_load($p, $id), $winners, function () {}));
    $msgs = gw_sent($log);
    assert_true(strpos($msgs[0][1]['text'], 'Ваш приз: Magnet') !== false, 'приз в тексте');
    assert_true(strpos($msgs[2][1]['text'], 'Your prize: Chromatic Box') !== false, 'по-английски');
    assert_eq(503, $msgs[2][1]['chat_id']);
});

// --------------------------------------------------------------------------
//  Что видит посетитель
// --------------------------------------------------------------------------

test('публичный список: черновик скрыт, итоги с победителями', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A', true, 'chan_a');
    gw_save($p, gw_draft(['title_ru' => 'Черновик']), GW_NOW);
    $old = gw_live($p, ['title_ru' => 'Старый']);
    gw_crowd($p, $old);
    $log = [];
    gw_draw($p, gw_cfg(), gw_tg($log), $old, GW_NOW + 86400);
    $new = gw_live($p, ['title_ru' => 'Новый', 'channels' => [GW_CH_A], 'ends_at' => GW_NOW + 3 * 86400]);

    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW + 86400);
    assert_eq(['Новый'], array_map(function ($g) { return $g['title']['ru']; }, $list['live']), 'идёт один');
    assert_eq(['Старый'], array_map(function ($g) { return $g['title']['ru']; }, $list['past']), 'в архиве один');
    assert_eq([['id' => (string)GW_CH_A, 'title' => 'Канал A', 'url' => 'https://t.me/chan_a']], $list['live'][0]['channels'], 'канал с адресом');
    assert_eq(3, count($list['past'][0]['winners']), 'победители видны');
    assert_eq(5, $list['past'][0]['entries'], 'число участников');
    assert_true(!array_key_exists('chat_id', $list['past'][0]['winners'][0]), 'Telegram победителя наружу не уходит');
    assert_eq('drawn', $list['past'][0]['phase']);
});

test('нет таблиц — пустой список, а не ошибка', function () {
    $list = handle_giveaways(function () { return test_db(); }, GW_NOW);
    assert_eq(['live' => [], 'past' => []], $list);
});

test('session.php говорит, где я участвую', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    $id = gw_live($p);
    $log = [];
    gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW);
    $s = handle_session(function () use ($p) { return $p; }, ['user_id' => '1'], gw_cfg(), GW_NOW);
    assert_eq([$id], $s['giveaways']);
    $anon = handle_session(function () use ($p) { return $p; }, [], gw_cfg(), GW_NOW);
    assert_eq([], $anon['giveaways'], 'гостю пусто');
});

// --------------------------------------------------------------------------
//  Каналы
// --------------------------------------------------------------------------

test('бота добавили в канал — канал появился, приватному нужна ссылка', function () {
    $p = gw_db();
    $upd = function (int $id, string $status, string $username = '') {
        $chat = ['id' => $id, 'type' => 'channel', 'title' => "Канал\nновостей"];
        if ($username !== '') { $chat['username'] = $username; }
        return ['update_id' => 1, 'my_chat_member' => [
            'chat' => $chat, 'from' => ['id' => 501],
            'old_chat_member' => ['status' => 'left'], 'new_chat_member' => ['status' => $status],
        ]];
    };
    assert_eq(GW_CH_A, gw_channel_update($p, $upd(GW_CH_A, 'administrator'), GW_NOW), 'приватный — просить ссылку');
    assert_eq(null, gw_channel_update($p, $upd(GW_CH_B, 'administrator', 'public_b'), GW_NOW), 'публичный — адрес есть');
    $chs = gw_admin_channels($p);
    assert_eq(2, count($chs));
    $byId = array_column($chs, null, 'id');
    assert_eq('Канал новостей', $byId[(string)GW_CH_A]['title'], 'название без переводов строк');
    assert_eq('https://t.me/public_b', $byId[(string)GW_CH_B]['url']);
    gw_channel_update($p, $upd(GW_CH_B, 'left', 'public_b'), GW_NOW + 1);
    assert_eq(false, array_column(gw_admin_channels($p), null, 'id')[(string)GW_CH_B]['admin'], 'бота убрали');
    assert_eq(null, gw_channel_update($p, ['message' => ['text' => 'hi']], GW_NOW), 'обычное сообщение мимо');
    $private = $upd(501, 'member');
    $private['my_chat_member']['chat']['type'] = 'private';
    gw_channel_update($p, $private, GW_NOW);
    assert_eq(2, count(gw_admin_channels($p)), 'личный чат с ботом — не канал');
});

test('«Обновить» канал: права бота и ссылка-приглашение', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Старое', false);
    $p->exec("UPDATE tg_channels SET invite_url = ''");
    $log = [];
    $send = function (string $m, array $a) use (&$log): ?array {
        $log[] = $m;
        if ($m === 'getChat') { return ['ok' => true, 'result' => ['id' => GW_CH_A, 'title' => 'Новое', 'type' => 'channel']]; }
        if ($m === 'getChatMember') { return ['ok' => true, 'result' => ['status' => 'administrator']]; }
        if ($m === 'exportChatInviteLink') { return ['ok' => true, 'result' => 'https://t.me/+AbCdEf']; }
        return null;
    };
    assert_true(gw_channel_refresh($p, tg_config(gw_cfg()), $send, GW_CH_A, GW_NOW));
    $c = gw_admin_channels($p)[0];
    assert_eq(['Новое', true, 'https://t.me/+AbCdEf'], [$c['title'], $c['admin'], $c['url']]);
});

// --------------------------------------------------------------------------
//  Эндпоинт админки
// --------------------------------------------------------------------------

test('админка: публикация отдаёт строку для рассылки, розыгрыш — победителей', function () {
    $p = gw_db();
    $cfg = gw_cfg();
    $log = [];
    [$s, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'save'] + gw_draft(), GW_NOW - 10);
    assert_eq(200, $s);
    $id = $out['id'];
    [$s, , $after] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'publish', 'id' => $id], GW_NOW - 5);
    assert_eq([200, 'announce', $id], [$s, $after[0], (int)$after[1]['id']]);
    gw_crowd($p, $id);
    [$s, , $after] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'end', 'id' => $id], GW_NOW);
    assert_eq([200, null], [$s, $after], 'досрочно');
    [$s, $out, $after] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'draw', 'id' => $id], GW_NOW + 1);
    assert_eq([200, 'winners', 3], [$s, $after[0], count($after[2])]);
    [$s, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'get', 'id' => $id], GW_NOW + 2);
    assert_eq(5, $out['giveaway']['entries']);
    assert_eq([0, 0, 1], array_slice(array_column($out['giveaway']['list'], 'prize'), 0, 3), 'победители первыми');
    [$s, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'list'], GW_NOW + 2);
    assert_eq(['drawn', 'MaknemyBot'], [$out['giveaways'][0]['status'], $out['bot']]);
    [$s] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'delete', 'id' => $id], GW_NOW + 3);
    assert_eq(200, $s);
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'участники удалены вместе');
});

test('админка без миграции просит её', function () {
    $log = [];
    [$s, $out] = handle_gw_admin(test_db(), gw_cfg(), gw_tg($log), ['action' => 'list'], GW_NOW);
    assert_eq([503, 'not_ready'], [$s, $out['error']]);
});

// --------------------------------------------------------------------------
//  Где показывается окно
// --------------------------------------------------------------------------

test('окно розыгрыша — на всех страницах, кроме главной, правовых и самой /giveaways', function () {
    $pub = dirname(__DIR__) . '/public_html';
    $with = ['index.php', 'calculator.php', 'news.php', 'trading.php', 'trade-new.php',
             'stock.php', 'chat.php', 'profile.php', 'support.php'];
    foreach ($with as $page) {
        $html = file_get_contents("$pub/$page");
        $blocks = strpos($html, 'js/news-blocks.js?v=');
        $card = strpos($html, 'js/giveaway.js?v=');
        $pop = strpos($html, 'js/giveaway-pop.js?v=');
        assert_true($blocks !== false && $card !== false && $pop !== false, "$page: скрипты окна");
        assert_true($blocks < $card && $card < $pop, "$page: порядок news-blocks → giveaway → giveaway-pop");
        assert_true(strpos($html, 'href="css/giveaway.css?v=') !== false, "$page: стили окна");
    }
    foreach (['home.php', 'privacy.php', 'terms.php', 'giveaways.php'] as $page) {
        assert_eq(false, strpos(file_get_contents("$pub/$page"), 'giveaway-pop.js'), "$page: окна нет");
    }
});

run_tests();
