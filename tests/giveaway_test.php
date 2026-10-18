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
//  - черновик снаружи не виден;
//  - тестовый виден только по ?test=1, и о его старте бот не пишет никому;
//  - при лимите участников последний вступивший закрывает приём, лишних нет.

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
    // Зеркалит docs/migrations/2026-10-14-giveaways.sql, 2026-10-15-giveaway-conds.sql,
    // 2026-10-15-giveaway-test.sql, 2026-10-15-giveaway-max-entries.sql и
    // 2026-10-18-giveaway-drop-unsub.sql.
    $pdo->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft', test INTEGER NOT NULL DEFAULT 0,
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL, conds_json TEXT NULL,
        drop_unsub INTEGER NOT NULL DEFAULT 1, max_entries INTEGER NOT NULL DEFAULT 0, ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    $pdo->exec("CREATE TABLE giveaway_entries (
        giveaway_id INTEGER NOT NULL, user_id INTEGER NOT NULL, chat_id INTEGER NOT NULL,
        joined_at INTEGER NOT NULL, prize INTEGER NULL, src TEXT NOT NULL DEFAULT '',
        PRIMARY KEY (giveaway_id, user_id), UNIQUE (giveaway_id, chat_id))");
    $pdo->exec('CREATE TABLE giveaway_links (
        giveaway_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, invite_url TEXT NOT NULL, created_at INTEGER NOT NULL,
        PRIMARY KEY (giveaway_id, channel_id), UNIQUE (invite_url))');
    $pdo->exec("CREATE TABLE giveaway_subs (
        giveaway_id INTEGER NOT NULL, channel_id INTEGER NOT NULL, tg_user_id INTEGER NOT NULL,
        tg_name TEXT NOT NULL DEFAULT '', joined_at INTEGER NOT NULL, left_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (giveaway_id, channel_id, tg_user_id))");
    $pdo->exec("CREATE TABLE tg_channels (
        chat_id INTEGER NOT NULL PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0,
        missing TEXT NOT NULL DEFAULT '', updated_at INTEGER NOT NULL)");
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

// Бот-админ канала со всеми нужными правами, кроме $without.
function gw_bot_admin(array $without = []): array {
    $m = ['status' => 'administrator', 'user' => ['id' => (int)strtok(GW_TOKEN, ':'), 'is_bot' => true], 'can_manage_chat' => true];
    foreach (tg_admin_rights(true) as $k) { $m[$k] = !in_array($k, $without, true); }
    return $m;
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
        if ($method === 'createChatInviteLink') {
            return ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+gw' . abs($params['chat_id']) . md5($params['name'])]];
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

test('иконка приза: картинка сайта, адрес от корня, чужое не проходит', function () {
    $sha = str_repeat('b', 40);
    $v = gw_validate(gw_draft(['prizes' => [
        ['name' => 'Permanent Magnet', 'count' => 5, 'icon' => "https://maknemy.com/images/$sha.png"],
        ['name' => 'Kitsune', 'count' => 1, 'icon' => "/images/$sha.webp"],
        ['name' => 'Box', 'count' => 1],
    ]]));
    assert_true($v['ok']);
    assert_eq(["/images/$sha.png", "/images/$sha.webp", ''], array_column($v['row']['prizes'], 'icon'), 'путь от корня, пусто — без иконки');
    foreach (['https://evil.example/images/' . $sha . '.png', 'javascript:alert(1)', "/images/$sha.svg", ['x']] as $bad) {
        assert_eq(false, gw_validate(gw_draft(['prizes' => [['name' => 'X', 'count' => 1, 'icon' => $bad]]]))['ok'], 'не пускаем: ' . json_encode($bad));
    }
    $p = gw_db();
    $p->exec("INSERT INTO giveaways (status, title_ru, prizes_json, channels_json, ends_at, created_at, updated_at, published_at)
              VALUES ('live', 'Старый', '[{\"name\":\"Gas\",\"count\":2}]', '[]', " . (GW_NOW + 100) . ', 1, 1, 1)');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([['name' => 'Gas', 'count' => 2, 'icon' => '']], $list['live'][0]['prizes'], 'старый приз без иконки — пустая');
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
//  Свои условия: текст и ссылка
// --------------------------------------------------------------------------

const GW_YT = 'https://youtube.com/@maknemy?sub_confirmation=1';

function gw_cond(string $ru, string $url = GW_YT, string $en = '', string $btn = '', string $btnEn = '', string $chat = ''): array {
    return ['ru' => $ru, 'en' => $en, 'url' => $url, 'btn_ru' => $btn, 'btn_en' => $btnEn, 'chat' => $chat];
}

test('свои условия: текст обязателен, ссылка только http(s)', function () {
    $bad = [
        'без текста'          => [gw_cond('  ')],
        'длинный текст'       => [gw_cond(str_repeat('я', GW_COND_TEXT_MAX + 1))],
        'без ссылки'          => [gw_cond('YouTube', '')],
        'javascript:'         => [gw_cond('YouTube', 'javascript:alert(1)')],
        'без протокола'       => [gw_cond('YouTube', 'youtube.com/@maknemy')],
        'кавычка в ссылке'    => [gw_cond('YouTube', 'https://a.b/"onmouseover=x')],
        'пробел в ссылке'     => [gw_cond('YouTube', 'https://a.b/x y')],
        'без хоста'           => [gw_cond('YouTube', 'https:///x')],
        'длинная ссылка'      => [gw_cond('YouTube', 'https://a.b/' . str_repeat('x', GW_COND_URL_MAX))],
        'длинная кнопка'      => [gw_cond('YouTube', GW_YT, '', str_repeat('я', GW_COND_BTN_MAX + 1))],
        'не список'           => ['ru' => 'YouTube'],
        'шесть условий'       => array_fill(0, GW_CONDS_MAX + 1, gw_cond('YouTube')),
    ];
    foreach ($bad as $why => $conds) {
        assert_eq(false, gw_validate(gw_draft(['conds' => $conds]))['ok'], $why);
    }
    $v = gw_validate(gw_draft(['conds' => [gw_cond(' Подписаться  на YouTube ', ' ' . GW_YT . ' ', 'Subscribe')]]));
    assert_true($v['ok'], 'хорошее условие проходит');
    assert_eq([gw_cond('Подписаться на YouTube', GW_YT, 'Subscribe')], $v['row']['conds'], 'пробелы срезаны');
    assert_eq([], gw_validate(gw_draft())['row']['conds'], 'нет условий — пустой список');
    $v = gw_validate(gw_draft(['conds' => [gw_cond('Лайк', GW_YT, '', ' Лайкнуть ', 'Like'), gw_cond('Канал', GW_YT, '', '', 'Join')]]));
    assert_eq([['Лайкнуть', 'Like'], ['', '']], array_map(function ($c) { return [$c['btn_ru'], $c['btn_en']]; }, $v['row']['conds']),
        'надпись кнопки; без русской — и английской нет, будет «Подписаться»');
});

test('свои условия видны в админке и на сайте, участию не мешают', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    $id = gw_live($p, ['conds' => [gw_cond('Подписаться на YouTube', GW_YT, 'Subscribe on YouTube', 'Смотреть', 'Watch')]]);
    assert_eq([gw_cond('Подписаться на YouTube', GW_YT, 'Subscribe on YouTube', 'Смотреть', 'Watch')],
        gw_admin_item($p, gw_load($p, $id), GW_NOW)['conds'], 'админка');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([['text' => ['ru' => 'Подписаться на YouTube', 'en' => 'Subscribe on YouTube'], 'url' => GW_YT,
        'btn' => ['ru' => 'Смотреть', 'en' => 'Watch'], 'chat' => '']], $list['live'][0]['conds'], 'сайт');
    $log = [];
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW)[0], 'участие без проверки условия');
    assert_true(strpos(gw_announce_text(gw_load($p, $id), 'ru'), 'Условия — в карточке') !== false, 'рассылка про условия');

    $p->exec("UPDATE giveaways SET conds_json = '[{\"ru\":\"x\",\"url\":\"javascript:alert(1)\"},{\"ru\":\"y\",\"url\":\"https://y.example\"}]'");
    assert_eq([gw_cond('y', 'https://y.example')], gw_admin_item($p, gw_load($p, $id), GW_NOW)['conds'], 'кривое из базы пропущено');
});

test('свои условия: EN переводится, неизменённое не гоняется заново', function () {
    $p = gw_db();
    $asked = [];
    $tr = function (string $text) use (&$asked): ?string {
        $asked[] = $text;
        return implode(TR_SEP, array_map(function ($t) { return 'EN:' . $t; }, explode(TR_SEP, $text)));
    };
    [$s, $out] = gw_save($p, gw_draft(['title_en' => 'T', 'body' => ['v' => 1, 'blocks' => []],
        'conds' => [gw_cond('Подписаться на YouTube'), gw_cond('Лайк', GW_YT, 'Like it')]]), GW_NOW, $tr);
    assert_eq([200, 0], [$s, $out['translate_failed']]);
    $conds = gw_conds(gw_load($p, $out['id'])['conds_json']);
    assert_eq(['EN:Подписаться на YouTube', 'Like it'], array_column($conds, 'en'), 'пустой переведён, ручной не тронут');

    // Админка присылает EN, который сама получила; русский второго поменяли.
    $asked = [];
    gw_save($p, gw_draft(['id' => $out['id'], 'title_en' => 'T', 'body' => ['v' => 1, 'blocks' => []],
        'conds' => [gw_cond('Подписаться на YouTube'), gw_cond('Лайк и коммент', GW_YT, 'Like it')]]), GW_NOW, $tr);
    assert_eq(['Лайк и коммент'], $asked, 'переведён только изменённый');
    $conds = gw_conds(gw_load($p, $out['id'])['conds_json']);
    assert_eq(['EN:Подписаться на YouTube', 'EN:Лайк и коммент'], array_column($conds, 'en'), 'старый EN не остался под новым русским');

    // Надпись на кнопке переводится так же: админка шлёт только русскую.
    $asked = [];
    gw_save($p, gw_draft(['id' => $out['id'], 'title_en' => 'T', 'body' => ['v' => 1, 'blocks' => []],
        'conds' => [gw_cond('Подписаться на YouTube', GW_YT, '', 'Смотреть'), gw_cond('Лайк и коммент')]]), GW_NOW, $tr);
    assert_eq(['Смотреть'], $asked, 'переведена только надпись');
    $conds = gw_conds(gw_load($p, $out['id'])['conds_json']);
    assert_eq([['Смотреть', 'EN:Смотреть'], ['', '']], array_map(function ($c) { return [$c['btn_ru'], $c['btn_en']]; }, $conds));
    $asked = [];
    gw_save($p, gw_draft(['id' => $out['id'], 'title_en' => 'T', 'body' => ['v' => 1, 'blocks' => []],
        'conds' => [gw_cond('Подписаться на YouTube', GW_YT, '', 'Смотреть')]]), GW_NOW, $tr);
    assert_eq([], $asked, 'надпись не менялась — переводчика не зовут');
    assert_eq('EN:Смотреть', gw_conds(gw_load($p, $out['id'])['conds_json'])[0]['btn_en']);

    $fail = function (): ?string { return null; };
    [$s, $out] = gw_save($p, gw_draft(['title_en' => 'T', 'body' => ['v' => 1, 'blocks' => []],
        'conds' => [gw_cond('Новое')]]), GW_NOW, $fail);
    assert_eq([200, 1], [$s, $out['translate_failed']], 'переводчик молчит — сохранено, EN пустой');
});

const GW_STOCK = 'https://t.me/+dtbxvZn9baA1OTVi';

test('своё условие с каналом: бот проверяет подписку, как у отмеченных', function () {
    $bad = [
        'канал не числом' => [gw_cond('Сток', GW_STOCK, '', '', '', '@stock')],
        'канал ноль'      => [gw_cond('Сток', GW_STOCK, '', '', '', '0')],
    ];
    foreach ($bad as $why => $conds) {
        assert_eq('bad cond chat', gw_validate(gw_draft(['conds' => $conds]))['error'], $why);
    }
    $six = gw_validate(gw_draft(['channels' => [-1, -2, -3, -4, -5], 'conds' => [gw_cond('Ещё', GW_STOCK, '', '', '', '-6')]]));
    assert_eq('bad channels', $six['error'], 'проверяемых каналов вместе — не больше пяти');
    $v = gw_validate(gw_draft(['channels' => [GW_CH_A], 'conds' => [
        gw_cond('Сток', GW_STOCK, '', '', '', (string)GW_CH_B), gw_cond('Тоже A', GW_STOCK, '', '', '', (string)GW_CH_A)]]));
    assert_true($v['ok'], 'канал у условия и тот же, что отмечен, — можно');

    $p = gw_db();
    gw_channel($p, GW_CH_B, 'Сток');
    gw_user($p, '1', 'ann', 501);
    $id = gw_live($p, ['conds' => [gw_cond('Подписаться на сток', GW_STOCK, '', '', '', (string)GW_CH_B), gw_cond('YouTube')]]);
    assert_eq((string)GW_CH_B, gw_admin_item($p, gw_load($p, $id), GW_NOW)['conds'][0]['chat'], 'админка видит канал');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([(string)GW_CH_B, ''], array_column($list['live'][0]['conds'], 'chat'), 'сайт: по какому каналу отметить условие');
    assert_eq([], $list['live'][0]['channels'], 'в каналах условия не дублируется');
    assert_eq([GW_CH_B], gw_checked_channels(gw_load($p, $id)), 'проверяется канал условия');

    $log = [];
    [$s, $out] = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW);
    assert_eq([409, 'channels', [(string)GW_CH_B]], [$s, $out['error'], $out['missing']], 'не подписан — не пускает');
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log, [GW_CH_B => [501]]), '1', $id, GW_NOW)[0], 'подписался — участвует');
    assert_true(strpos(gw_joined_text(gw_load($p, $id), 'ru'), 'участие снимется автоматически') !== false, 'бот предупреждает об отписке');
    assert_true(strpos(gw_announce_text(gw_load($p, $id), 'ru'), 'подпишитесь на каналы из условий') !== false, 'рассылка про каналы');

    $notes = gw_member_update($p, gw_member(GW_CH_B, 501, 'left'), GW_NOW + 10);
    assert_eq([501, 'Сток'], [$notes[0]['chat_id'] ?? null, $notes[0]['channel'] ?? null], 'отписался — участие снято, бот пишет');
    assert_eq(0, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn());

    gw_enter($p, gw_cfg(), gw_tg($log, [GW_CH_B => [501]]), '1', $id, GW_NOW + 20);
    [$s, $out, $winners] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    assert_eq([200, 0, 1], [$s, $out['winners'], $out['skipped']], 'к итогам отписался — приз не получает');
});

test('своё условие с каналом: публикация проверяет права бота в нём', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_B, 'Сток', false);
    [, $out] = gw_save($p, gw_draft(['conds' => [gw_cond('Сток', GW_STOCK, '', '', '', (string)GW_CH_B)]]), GW_NOW);
    $log = [];
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW, gw_tg($log));
    assert_eq([409, ['channel_not_admin']], [$s, $res['problems']], 'без прав нельзя');
    [, $out] = gw_save($p, gw_draft(['conds' => [gw_cond('Сток', GW_STOCK, '', '', '', '-1009999999999')]]), GW_NOW);
    assert_eq(['channel_not_admin'], gw_publish($p, $out['id'], GW_NOW)[1]['problems'], 'неизвестный боту канал — тоже');

    $p->exec('UPDATE tg_channels SET is_admin = 1');
    [, $out] = gw_save($p, gw_draft(['conds' => [gw_cond('Сток', GW_STOCK, '', '', '', (string)GW_CH_B)]]), GW_NOW);
    $log = [];
    [$s] = gw_publish($p, $out['id'], GW_NOW, gw_tg($log));
    assert_eq(200, $s, 'с правами — публикуется');
    assert_eq([], gw_sent($log, 'createChatInviteLink'), 'своя ссылка: приглашение розыгрыша не заводится');
});

test('идущий розыгрыш: убранное условие бот больше не требует', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_channel($p, GW_CH_B, 'Сток');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502);
    $conds = [gw_cond('Сток', GW_STOCK, '', '', '', (string)GW_CH_B), gw_cond('YouTube')];
    $id = gw_live($p, ['channels' => [GW_CH_A], 'conds' => $conds]);
    $log = [];
    $subsB = [GW_CH_B => [501, 502]];
    assert_eq([(string)GW_CH_A], gw_enter($p, gw_cfg(), gw_tg($log, $subsB), '1', $id, GW_NOW)[1]['missing'], 'пока канал A в условиях — требует');
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log, [GW_CH_A => [502]] + $subsB), '2', $id, GW_NOW)[0], 'bob подписан на оба');

    [$s] = gw_save($p, gw_draft(['id' => $id, 'channels' => [], 'conds' => $conds]), GW_NOW);
    assert_eq(200, $s, 'идущий розыгрыш правится');
    assert_eq('live', gw_load($p, $id)['status'], 'и остаётся идущим');
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log, $subsB), '1', $id, GW_NOW)[0], 'канал A убран — не требует');
    assert_eq([], gw_member_update($p, gw_member(GW_CH_A, 502, 'left'), GW_NOW + 5), 'отписка от убранного канала участие не снимает');

    [$s] = gw_save($p, gw_draft(['id' => $id, 'channels' => [], 'conds' => [gw_cond('YouTube')]]), GW_NOW + 10);
    assert_eq(200, $s);
    assert_eq([], gw_checked_channels(gw_load($p, $id)), 'своё условие с каналом убрано — проверять нечего');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW + 10);
    assert_eq([[], 1], [$list['live'][0]['channels'], count($list['live'][0]['conds'])], 'карточка показывает только оставшиеся');
    $log = [];
    [$s, $out] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    assert_eq([200, 2], [$s, $out['winners']], 'на итогах убранные условия не проверяются');
    assert_eq([], gw_sent($log, 'getChatMember'), 'Telegram не спрашивали');
});

test('условие, сохранённое до надписей на кнопке, читается с пустой надписью', function () {
    $p = gw_db();
    $id = gw_live($p);
    $p->exec("UPDATE giveaways SET conds_json = '[{\"ru\":\"Старое\",\"en\":\"Old\",\"url\":\"https://y.example\"}]'");
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq(['ru' => '', 'en' => ''], $list['live'][0]['conds'][0]['btn']);
});

test('до миграции conds_json: без своих условий сохраняется, с ними — просит миграцию', function () {
    $p = gw_db();
    $p->exec('ALTER TABLE giveaways RENAME TO giveaways_new');
    $p->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft',
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL,
        ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    [$s, $out] = gw_save($p, gw_draft(['conds' => [gw_cond('YouTube')]]), GW_NOW);
    assert_eq([409, 'conds_migrate'], [$s, $out['error']]);
    $id = gw_live($p);
    [$s] = gw_save($p, gw_draft(['id' => $id, 'title_ru' => 'Правка']), GW_NOW);
    assert_eq(200, $s, 'правка без условий');
    assert_eq([], gw_admin_item($p, gw_load($p, $id), GW_NOW)['conds']);
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq(['Правка', []], [$list['live'][0]['title']['ru'], $list['live'][0]['conds']], 'сайт показывает');
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
    assert_eq('https://maknemy.com/giveaways#g' . $id . '-tg', $msgs[0][1]['reply_markup']['inline_keyboard'][0][0]['url'],
        'кнопка ведёт на розыгрыш с пометкой «из бота»');
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

// --------------------------------------------------------------------------
//  Тестовый розыгрыш
// --------------------------------------------------------------------------

test('тестовый: посетители не видят, ?test=1 видит, о старте бот молчит', function () {
    $p = gw_db();
    $cfg = gw_cfg();
    $log = [];
    $real = gw_live($p, ['title_ru' => 'Настоящий']);
    [$s, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'save', 'test' => true] + gw_draft(['title_ru' => 'Тест']), GW_NOW - 10);
    assert_eq(200, $s);
    $test = $out['id'];
    [$s, , $after] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'publish', 'id' => $test], GW_NOW - 5);
    assert_eq([200, null], [$s, $after], 'опубликован, рассылки нет');

    $db = function () use ($p) { return $p; };
    $titles = function (array $list): array { return array_map(function ($g) { return $g['title']['ru']; }, $list); };
    assert_eq(['Настоящий'], $titles(handle_giveaways($db, GW_NOW)['live']), 'посетителю — только настоящий');
    $all = handle_giveaways($db, GW_NOW, true)['live'];
    assert_eq(['Тест', 'Настоящий'], $titles($all), 'по ?test=1 — оба');
    assert_eq([true, false], array_column($all, 'test'), 'тестовый помечен');

    gw_crowd($p, $test);
    gw_draw($p, $cfg, gw_tg($log), $test, GW_NOW + 86400);
    assert_eq(['Настоящий'], $titles(handle_giveaways($db, GW_NOW + 86400)['past']), 'итоги тестового посетителю не видны');
    assert_eq(['Тест', 'Настоящий'], $titles(handle_giveaways($db, GW_NOW + 86400, true)['past']), 'по ?test=1 — видны');

    [, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'list'], GW_NOW);
    assert_eq([$real => false, $test => true], array_column($out['giveaways'], 'test', 'id'), 'админка видит пометку');
    [, $out] = handle_gw_admin($p, $cfg, gw_tg($log), ['action' => 'get', 'id' => $real], GW_NOW);
    assert_eq(false, $out['giveaway']['test']);
});

test('тестовый: кнопки бота ведут на /giveaways?test', function () {
    $p = gw_db();
    gw_user($p, '9', 'zed', 509);
    $id = gw_live($p, ['test' => true]);
    $log = [];
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '9', $id, GW_NOW, 'page');
    assert_eq(200, $r[0], 'участвовать в тестовом можно');
    $log = [];
    gw_after_join($p, gw_cfg(), $r[2], gw_tg($log));
    $url = 'https://maknemy.com/giveaways?test#g' . $id;
    assert_eq($url, gw_sent($log)[0][1]['reply_markup']['inline_keyboard'][0][0]['url'], 'подтверждение');
    gw_crowd($p, $id);
    [, , $winners] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    $log = [];
    gw_notify_winners($p, gw_tg($log), gw_load($p, $id), $winners, function () {});
    assert_eq($url, gw_sent($log)[0][1]['reply_markup']['inline_keyboard'][0][0]['url'], 'поздравление');
    assert_eq('https://maknemy.com/giveaways#g7', gw_link(7), 'у настоящего — без ?test');
});

test('пометка «тестовый» меняется только у черновика', function () {
    $p = gw_db();
    [, $out] = gw_save($p, gw_draft(['test' => true]), GW_NOW - 20);
    $id = $out['id'];
    gw_save($p, gw_draft(['id' => $id, 'test' => false]), GW_NOW - 15);
    assert_eq(false, gw_is_test(gw_load($p, $id)), 'черновик — снимается');
    gw_save($p, gw_draft(['id' => $id, 'test' => true]), GW_NOW - 12);
    gw_publish($p, $id, GW_NOW - 10);
    [$s] = gw_save($p, gw_draft(['id' => $id, 'test' => false, 'title_ru' => 'Правка']), GW_NOW);
    assert_eq(200, $s);
    $row = gw_load($p, $id);
    assert_eq(['Правка', true], [$row['title_ru'], gw_is_test($row)], 'идущий тестом и остался');
    $real = gw_live($p);
    gw_save($p, gw_draft(['id' => $real, 'test' => true]), GW_NOW);
    assert_eq(false, gw_is_test(gw_load($p, $real)), 'идущий настоящий тестом не стал');
    [, $out] = gw_save($p, gw_draft(['test' => 1]), GW_NOW);
    assert_eq(false, gw_is_test(gw_load($p, $out['id'])), 'только настоящее true');
});

test('до миграции test: настоящий сохраняется и виден, тестовый просит миграцию', function () {
    $p = gw_db();
    $p->exec('DROP TABLE giveaways');
    $p->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft',
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL, conds_json TEXT NULL,
        ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    [$s, $out] = gw_save($p, gw_draft(['test' => true]), GW_NOW);
    assert_eq([409, 'test_migrate'], [$s, $out['error']]);
    $id = gw_live($p);
    $db = function () use ($p) { return $p; };
    assert_eq([$id], array_column(handle_giveaways($db, GW_NOW)['live'], 'id'), 'сайт показывает');
    assert_eq([false], array_column(handle_giveaways($db, GW_NOW, true)['live'], 'test'));
    $log = [];
    [, $out] = gw_save($p, gw_draft(), GW_NOW - 10);
    [, , $after] = handle_gw_admin($p, gw_cfg(), gw_tg($log), ['action' => 'publish', 'id' => $out['id']], GW_NOW - 5);
    assert_eq('announce', $after[0], 'настоящий рассылается как раньше');
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
//  Лимит участников
// --------------------------------------------------------------------------

test('лимит: проверка поля', function () {
    foreach ([-1, 1.5, '10', GW_ENTRIES_MAX + 1, true] as $bad) {
        assert_eq('bad max_entries', gw_validate(gw_draft(['max_entries' => $bad]))['error'], 'не пускаем: ' . json_encode($bad));
    }
    assert_eq(0, gw_validate(gw_draft())['row']['max_entries'], 'нет поля — без лимита');
    assert_eq(0, gw_validate(gw_draft(['max_entries' => null]))['row']['max_entries'], 'null — без лимита');
    assert_eq(500, gw_validate(gw_draft(['max_entries' => 500]))['row']['max_entries']);
});

test('лимит: последний участник закрывает приём, следующий получает full', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502, 'en');
    gw_user($p, '3', 'cat', 503);
    $id = gw_live($p, ['max_entries' => 3, 'prizes' => [['name' => 'Magnet', 'count' => 1]]]);
    $log = [];

    $r = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW);
    assert_eq([200, false], [$r[0], $r[2]['last']], 'первый — не последний');
    assert_eq('live', gw_phase(gw_load($p, $id), GW_NOW), 'идёт');
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '2', $id, GW_NOW + 1);
    assert_eq([200, false], [$r[0], $r[2]['last']]);

    $p->exec('UPDATE giveaways SET max_entries = 2');
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '3', $id, GW_NOW + 2);
    assert_eq([409, 'full'], [$r[0], $r[1]['error']], 'лимит опустили напрямую — места нет');
    assert_eq(GW_NOW + 2, (int)gw_load($p, $id)['ends_at'], 'и приём закрылся');
    $p->exec('UPDATE giveaways SET max_entries = 3, ends_at = ' . (GW_NOW + 86400));

    $r = gw_enter($p, gw_cfg(), gw_tg($log), '3', $id, GW_NOW + 5);
    assert_eq([200, true], [$r[0], $r[2]['last']], 'третий занял последнее место');
    assert_eq(['ok' => true, 'joined' => true, 'last' => true], $r[1], 'окно узнаёт, что приём закрыт');
    $row = gw_load($p, $id);
    assert_eq([GW_NOW + 5, 'ended'], [(int)$row['ends_at'], gw_phase($row, GW_NOW + 5)], 'приём закрыт сразу');
    assert_eq(GW_NOW + 5, (int)$r[2]['row']['ends_at'], 'боту — строка с новым концом');

    gw_user($p, '4', 'dan', 504);
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '4', $id, GW_NOW + 6);
    assert_eq([409, 'full'], [$r[0], $r[1]['error']], 'четвёртому — места нет');
    assert_eq(3, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'лишних нет');
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW + 6);
    assert_eq([409, 'full'], [$r[0], $r[1]['error']], 'приём закрыт для всех');

    [$s, $out] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 7);
    assert_eq([200, 1, 3], [$s, $out['winners'], $out['entries']], 'разыгрывается сразу, не дожидаясь даты');
});

test('лимит: бот пишет о нём в рассылке и в «вы участвуете»', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502, 'en');
    $id = gw_live($p, ['max_entries' => 2, 'prizes' => [['name' => 'Magnet', 'count' => 1]]]);
    $row = gw_load($p, $id);
    assert_true(strpos(gw_announce_text($row, 'ru'), 'или раньше, когда участников станет 2') !== false, 'рассылка RU');
    assert_true(strpos(gw_announce_text($row, 'en'), 'once the entry count reaches 2') !== false, 'рассылка EN');
    assert_true(strpos(gw_announce_text(gw_load($p, gw_live($p)), 'ru'), 'или раньше') === false, 'без лимита — как раньше');

    $log = [];
    $first = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW)[2];
    $last = gw_enter($p, gw_cfg(), gw_tg($log), '2', $id, GW_NOW + 1)[2];
    $log = [];
    gw_after_join($p, gw_cfg(), $first, gw_tg($log));
    gw_after_join($p, gw_cfg(), $last, gw_tg($log));
    $msgs = gw_sent($log);
    assert_true(strpos($msgs[0][1]['text'], 'когда участников станет 2') !== false, 'первому — дата и лимит');
    assert_true(strpos($msgs[1][1]['text'], 'You took the last spot: 2 entries') !== false, 'последнему — приём закрыт');
    assert_true(strpos($msgs[1][1]['text'], 'Results:') === false, 'и без прошедшей даты');
});

test('лимит: опустили в админке ниже числа участников — приём закрыт', function () {
    $p = gw_db();
    $id = gw_live($p, ['max_entries' => 10]);
    gw_crowd($p, $id);
    [$s] = gw_save($p, gw_draft(['id' => $id, 'max_entries' => 8]), GW_NOW);
    assert_eq(200, $s);
    assert_eq('live', gw_phase(gw_load($p, $id), GW_NOW), 'выше числа участников — идёт');
    [$s] = gw_save($p, gw_draft(['id' => $id, 'max_entries' => 5]), GW_NOW + 1);
    assert_eq(200, $s);
    $row = gw_load($p, $id);
    assert_eq([5, 'ended'], [gw_max($row), gw_phase($row, GW_NOW + 1)], 'набран — закрыт');
    [$s] = gw_save($p, gw_draft(['id' => $id, 'max_entries' => 0, 'ends_at' => GW_NOW + 86400]), GW_NOW + 2);
    assert_eq('live', gw_phase(gw_load($p, $id), GW_NOW + 2), 'лимит сняли и продлили — снова идёт');
});

test('лимит: меньше числа призов — публиковать нельзя', function () {
    $p = gw_db();
    [, $out] = gw_save($p, gw_draft(['max_entries' => 2]), GW_NOW);
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW);
    assert_eq([409, ['max_below_prizes']], [$s, $res['problems']], 'Magnet ×2 и Box — три места на двоих');
    gw_save($p, gw_draft(['id' => $out['id'], 'max_entries' => 3]), GW_NOW);
    assert_eq(200, gw_publish($p, $out['id'], GW_NOW)[0], 'ровно по числу призов — можно');
});

test('лимит: виден на сайте и в админке', function () {
    $p = gw_db();
    $id = gw_live($p, ['max_entries' => 50]);
    $free = gw_live($p);
    gw_crowd($p, $id);
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([$free => 0, $id => 50], array_column($list['live'], 'max_entries', 'id'), 'карточке');
    assert_eq(5, array_column($list['live'], 'entries', 'id')[$id]);
    $log = [];
    [, $out] = handle_gw_admin($p, gw_cfg(), gw_tg($log), ['action' => 'list'], GW_NOW);
    assert_eq([$free => 0, $id => 50], array_column($out['giveaways'], 'max', 'id'), 'списку админки');
    [, $out] = handle_gw_admin($p, gw_cfg(), gw_tg($log), ['action' => 'get', 'id' => $id], GW_NOW);
    assert_eq(50, $out['giveaway']['max_entries'], 'редактору');
});

// --------------------------------------------------------------------------
//  Когда итоги: по времени или по числу участников
// --------------------------------------------------------------------------

test('end_by: по времени — лимита нет, по числу участников — даты нет', function () {
    $v = gw_validate(gw_draft(['end_by' => 'time', 'max_entries' => 500]));
    assert_eq([GW_NOW + 86400, 0], [$v['row']['ends_at'], $v['row']['max_entries']], 'по времени лимит отбрасывается');
    $v = gw_validate(gw_draft(['end_by' => 'entries', 'max_entries' => 500]));
    assert_eq([GW_NO_DEADLINE, 500], [$v['row']['ends_at'], $v['row']['max_entries']], 'по числу дата отбрасывается');
    $v = gw_validate(gw_draft(['max_entries' => 500]));
    assert_eq([GW_NOW + 86400, 500], [$v['row']['ends_at'], $v['row']['max_entries']], 'без end_by — как раньше, оба');
    foreach (['', 'both', 1, true] as $bad) {
        assert_eq('bad end_by', gw_validate(gw_draft(['end_by' => $bad]))['error'], 'не пускаем: ' . json_encode($bad));
    }
});

test('end_by: по числу участников — публикуется без даты, идёт до лимита', function () {
    $p = gw_db();
    [, $out] = gw_save($p, gw_draft(['end_by' => 'entries', 'max_entries' => 0]), GW_NOW);
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW);
    assert_eq([409, ['no_max']], [$s, $res['problems']], 'без лимита итогов не будет никогда');

    foreach (['1' => 501, '2' => 502, '3' => 503] as $u => $chat) { gw_user($p, $u, 'u' . $u, $chat); }
    $id = gw_live($p, ['end_by' => 'entries', 'max_entries' => 3, 'prizes' => [['name' => 'Kitsune', 'count' => 1]]]);
    $far = GW_NOW + 400 * 86400;
    assert_eq('live', gw_phase(gw_load($p, $id), $far), 'через год всё ещё идёт');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    $g = array_column($list['live'], null, 'id')[$id];
    assert_eq(['entries', 3], [$g['end_by'], $g['max_entries']], 'карточке — лимит вместо даты');
    $log = [];
    [, $out] = handle_gw_admin($p, gw_cfg(), gw_tg($log), ['action' => 'list'], GW_NOW);
    assert_eq('entries', array_column($out['giveaways'], 'end_by', 'id')[$id], 'списку админки');

    $log = [];
    foreach (['1', '2'] as $u) { assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log), $u, $id, GW_NOW)[0]); }
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '3', $id, GW_NOW + 50);
    assert_true($r[2]['last'], 'третий закрыл приём');
    $row = gw_load($p, $id);
    assert_eq([GW_NOW + 50, 'ended', 'time'], [(int)$row['ends_at'], gw_phase($row, GW_NOW + 50), gw_end_by($row)],
        'лимит набран — у розыгрыша появилась настоящая дата конца');
});

test('end_by: правка закончившегося «по числу» приём не открывает', function () {
    $p = gw_db();
    $id = gw_live($p, ['end_by' => 'entries', 'max_entries' => 50]);
    [$s] = gw_end($p, $id, GW_NOW);
    assert_eq(200, $s, 'завершили досрочно');
    [$s] = gw_save($p, gw_draft(['id' => $id, 'end_by' => 'entries', 'max_entries' => 50, 'title_ru' => 'Поправили']), GW_NOW + 5);
    assert_eq(200, $s);
    $row = gw_load($p, $id);
    assert_eq([GW_NOW, 'ended'], [(int)$row['ends_at'], gw_phase($row, GW_NOW + 5)], 'остался закончившимся');

    $id = gw_live($p, ['end_by' => 'time']);
    gw_save($p, gw_draft(['id' => $id, 'end_by' => 'entries', 'max_entries' => 20]), GW_NOW);
    $row = gw_load($p, $id);
    assert_eq([GW_NO_DEADLINE, 20], [(int)$row['ends_at'], gw_max($row)], 'идущий можно переключить на число участников');
    gw_save($p, gw_draft(['id' => $id, 'end_by' => 'time', 'ends_at' => GW_NOW + 7200]), GW_NOW);
    $row = gw_load($p, $id);
    assert_eq([GW_NOW + 7200, 0], [(int)$row['ends_at'], gw_max($row)], 'и обратно на время');
});

test('end_by: бот пишет лимит вместо даты', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502, 'en');
    $id = gw_live($p, ['end_by' => 'entries', 'max_entries' => 1000, 'channels' => [GW_CH_A]]);
    $row = gw_load($p, $id);
    assert_true(strpos(gw_announce_text($row, 'ru'), 'Итоги: когда участников станет 1000.') !== false, 'рассылка RU');
    assert_true(strpos(gw_announce_text($row, 'en'), 'Results: once 1000 people have entered.') !== false, 'рассылка EN');
    assert_true(strpos(gw_announce_text($row, 'ru'), '2100') === false, 'без заглушки-даты');
    assert_true(strpos(gw_joined_text($row, 'ru'), 'Итоги — когда участников станет 1000.') !== false, '«вы участвуете»');
    assert_true(strpos(gw_left_text($row, 'Канал A', 'ru', GW_NOW), 'на сайте пока есть места.') !== false, 'отписка RU');
    assert_true(strpos(gw_left_text($row, 'Канал A', 'en', GW_NOW), 'while spots remain.') !== false, 'отписка EN');
});

// --------------------------------------------------------------------------
//  Автоитоги
// --------------------------------------------------------------------------

function gw_lock_file(): string {
    $f = tempnam(sys_get_temp_dir(), 'gwlock');
    file_put_contents($f, '');
    return $f;
}

test('автоитоги: закончившиеся разыгрываются сами, победителям пишет бот', function () {
    $p = gw_db();
    $ended = gw_live($p, ['title_ru' => 'Закончился']);
    gw_crowd($p, $ended);
    $running = gw_live($p, ['title_ru' => 'Идёт', 'ends_at' => GW_NOW + 3 * 86400]);
    $p->exec("INSERT INTO giveaway_entries (giveaway_id, user_id, chat_id, joined_at) VALUES ($running, 1, 501, " . GW_NOW . ')');
    gw_save($p, gw_draft(['title_ru' => 'Черновик', 'ends_at' => GW_NOW]), GW_NOW);
    $at = GW_NOW + 86400;

    assert_eq([$ended], gw_due_ids($p, $at), 'ждёт итогов только закончившийся');
    assert_true(gw_has_due($p, $at));
    assert_eq(false, gw_has_due($p, GW_NOW), 'до конца — нечего');
    assert_eq(false, gw_has_due(test_db(), $at), 'нет таблиц — нечего');

    $log = [];
    $res = gw_after_due($p, gw_cfg(), $at, gw_tg($log), gw_lock_file(), function () {});
    assert_eq(['drawn' => 1, 'failed' => 0], $res);
    assert_eq(['drawn', 'live'], [gw_load($p, $ended)['status'], gw_load($p, $running)['status']], 'идущий не тронут');
    assert_eq(3, count(gw_sent($log)), 'бот поздравил трёх победителей');
    assert_eq(false, gw_has_due($p, $at), 'больше ждать нечего');
    $list = handle_giveaways(function () use ($p) { return $p; }, $at);
    $past = array_column($list['past'], null, 'id');
    assert_eq(['drawn', 3], [$past[$ended]['phase'], count($past[$ended]['winners'])], 'на сайте — итоги с победителями');
});

test('автоитоги: лимит набран — итоги сразу, не дожидаясь даты', function () {
    $p = gw_db();
    foreach (['1' => 501, '2' => 502] as $u => $chat) { gw_user($p, $u, 'u' . $u, $chat); }
    $id = gw_live($p, ['max_entries' => 2, 'prizes' => [['name' => 'Magnet', 'count' => 1]]]);
    $log = [];
    gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW);
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '2', $id, GW_NOW + 1);
    assert_true($r[2]['last'], 'последний');
    $res = gw_after_due($p, gw_cfg(), GW_NOW + 1, gw_tg($log), gw_lock_file(), function () {});
    assert_eq(1, $res['drawn']);
    assert_eq('drawn', gw_load($p, $id)['status'], 'итоги подведены через секунду после последнего');
});

test('автоитоги: Telegram молчит — ждут, повтор не раньше чем через минуту', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    gw_crowd($p, $id);
    $lock = gw_lock_file();
    $at = GW_NOW + 86400;
    $log = [];
    $res = gw_after_due($p, gw_cfg(), $at, gw_tg($log, [], [GW_CH_A]), $lock, function () {});
    assert_eq(['drawn' => 0, 'failed' => 1], $res, 'не вышло');
    assert_eq('live', gw_load($p, $id)['status'], 'ждёт итогов дальше');
    $log = [];
    $res = gw_after_due($p, gw_cfg(), $at + 30, gw_tg($log, [GW_CH_A => [501, 502, 503]]), $lock, function () {});
    assert_eq(['skipped' => 'retry'], $res, 'через 30 с не пробует');
    assert_eq([], $log, 'Telegram не трогали');
    $res = gw_after_due($p, gw_cfg(), $at + GW_AUTO_RETRY, gw_tg($log, [GW_CH_A => [501, 502, 503]]), $lock, function () {});
    assert_eq(['drawn' => 1, 'failed' => 0], $res, 'через минуту — вышло');
    assert_eq('', file_get_contents($lock), 'метка неудачи стёрта');
});

test('автоитоги: второй проход, пока идёт первый, выходит сразу; без бота — ничего', function () {
    $p = gw_db();
    $id = gw_live($p);
    gw_crowd($p, $id);
    $lock = gw_lock_file();
    $h = fopen($lock, 'c+');
    flock($h, LOCK_EX);
    $log = [];
    assert_eq(['skipped' => 'busy'], gw_after_due($p, gw_cfg(), GW_NOW + 86400, gw_tg($log), $lock));
    flock($h, LOCK_UN);
    fclose($h);
    assert_eq('live', gw_load($p, $id)['status']);
    assert_eq(['skipped' => 'off'], gw_after_due($p, [], GW_NOW + 86400, gw_tg($log), $lock), 'бот не настроен');
    $log = [];
    gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    assert_eq(['drawn' => 0, 'failed' => 0], gw_draw_due($p, gw_cfg(), gw_tg($log), GW_NOW + 86401), 'разыгранный кнопкой второй раз не трогается');
});

test('до миграции max_entries: без лимита всё как раньше, с лимитом — просит миграцию', function () {
    $p = gw_db();
    $p->exec('DROP TABLE giveaways');
    $p->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft', test INTEGER NOT NULL DEFAULT 0,
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL, conds_json TEXT NULL,
        ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    [$s, $out] = gw_save($p, gw_draft(['max_entries' => 10]), GW_NOW);
    assert_eq([409, 'max_migrate'], [$s, $out['error']]);
    $id = gw_live($p);
    gw_user($p, '1', 'ann', 501);
    $log = [];
    assert_eq(200, gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW)[0], 'участие без замка');
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([0, 1], [$list['live'][0]['max_entries'], $list['live'][0]['entries']], 'сайт показывает');
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
            'old_chat_member' => ['status' => 'left'],
            'new_chat_member' => $status === 'administrator' ? gw_bot_admin() : ['status' => $status],
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
        if ($m === 'getChatMember') { return ['ok' => true, 'result' => gw_bot_admin()]; }
        if ($m === 'exportChatInviteLink') { return ['ok' => true, 'result' => 'https://t.me/+AbCdEf']; }
        return null;
    };
    assert_true(gw_channel_refresh($p, tg_config(gw_cfg()), $send, GW_CH_A, GW_NOW));
    $c = gw_admin_channels($p)[0];
    assert_eq(['Новое', true, 'https://t.me/+AbCdEf'], [$c['title'], $c['admin'], $c['url']]);
});

// --------------------------------------------------------------------------
//  Права бота в канале
// --------------------------------------------------------------------------

// my_chat_member: бот в канале был $old, стал $new (объекты ChatMember).
function gw_promo_upd(array $old, array $new, array $from = ['id' => 501, 'language_code' => 'ru']): array {
    return ['update_id' => 1, 'my_chat_member' => [
        'chat' => ['id' => GW_CH_A, 'type' => 'channel', 'title' => 'Канал A'], 'from' => $from,
        'old_chat_member' => $old, 'new_chat_member' => $new,
    ]];
}

test('нужные права — те, что на скриншоте владельца', function () {
    assert_eq(['can_post_messages', 'can_post_stories', 'can_restrict_members', 'can_invite_users', 'can_manage_direct_messages'],
        tg_admin_rights(true));
    assert_eq(['can_restrict_members', 'can_invite_users'], tg_admin_rights(false), 'у групп нет публикаций и историй');
    assert_eq('https://t.me/MaknemyBot?startchannel&admin=post_messages+post_stories+restrict_members+invite_users+manage_direct_messages',
        tg_add_channel_url('MaknemyBot'));
    assert_eq('', tg_add_channel_url(''), 'бота нет — ссылки нет');
});

test('админ без нужных прав — канал не годится и админка говорит, чего нет', function () {
    $p = gw_db();
    $upd = gw_promo_upd(['status' => 'left'], gw_bot_admin(['can_post_stories', 'can_manage_direct_messages']));
    assert_eq(null, gw_channel_update($p, $upd, GW_NOW), 'ссылку без прав не просим');
    $c = gw_admin_channels($p)[0];
    assert_eq([false, ['Публикация историй', 'Доступ к сообщениям канала']], [$c['admin'], $c['missing']]);
    [, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]]), GW_NOW);
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW);
    assert_eq([409, ['channel_not_admin']], [$s, $res['problems']], 'в условия не ставится');

    assert_eq(GW_CH_A, gw_channel_update($p, gw_promo_upd(gw_bot_admin(['can_post_stories']), gw_bot_admin()), GW_NOW + 1),
        'права выдали — теперь и ссылку');
    $c = gw_admin_channels($p)[0];
    assert_eq([true, []], [$c['admin'], $c['missing']]);
});

test('бот пишет добавившему, каких прав не хватает', function () {
    $r = gw_channel_reply(gw_promo_upd(['status' => 'left'], gw_bot_admin(['can_invite_users'])), 'MaknemyBot');
    assert_eq(['sendMessage', 501], [$r['method'], $r['chat_id']]);
    assert_true(strpos($r['text'], '• Добавление подписчиков') !== false, 'право по-русски, как в Telegram');
    assert_true(strpos($r['text'], 'Публикация историй') === false, 'выданные не перечисляет');
    assert_eq(tg_add_channel_url('MaknemyBot'), $r['reply_markup']['inline_keyboard'][0][0]['url'], 'кнопка «Выдать права»');

    $en = gw_channel_reply(gw_promo_upd(['status' => 'left'], gw_bot_admin(['can_invite_users']), ['id' => 502, 'language_code' => 'de']), 'MaknemyBot');
    assert_true(strpos($en['text'], '• Add Subscribers') !== false, 'не русскоязычному — по-английски');

    $ok = gw_channel_reply(gw_promo_upd(gw_bot_admin(['can_invite_users']), gw_bot_admin()), 'MaknemyBot');
    assert_true(strpos($ok['text'], '✅') === 0, 'все права есть — канал готов');
    assert_eq(false, isset($ok['reply_markup']), 'без кнопки');

    assert_eq(null, gw_channel_reply(gw_promo_upd(gw_bot_admin(), gw_bot_admin() + ['can_change_info' => true]), 'MaknemyBot'),
        'поменяли ненужное право — молчит');
    assert_eq(null, gw_channel_reply(gw_promo_upd(gw_bot_admin(), ['status' => 'left']), 'MaknemyBot'), 'бота убрали — молчит');
    assert_eq(null, gw_channel_reply(gw_promo_upd(['status' => 'left'], gw_bot_admin(), ['id' => 77, 'is_bot' => true]), 'MaknemyBot'),
        'добавил другой бот — молчит');
    assert_eq(null, gw_channel_reply(['message' => ['text' => 'hi']], 'MaknemyBot'));
});

test('«Обновить» видит, каких прав не хватает', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    $send = function (string $m, array $a): ?array {
        if ($m === 'getChat') { return ['ok' => true, 'result' => ['id' => GW_CH_A, 'title' => 'Канал A', 'type' => 'channel']]; }
        if ($m === 'getChatMember') { return ['ok' => true, 'result' => gw_bot_admin(['can_restrict_members'])]; }
        return null;
    };
    assert_true(gw_channel_refresh($p, tg_config(gw_cfg()), $send, GW_CH_A, GW_NOW));
    $c = gw_admin_channels($p)[0];
    assert_eq([false, ['Блокировка пользователей']], [$c['admin'], $c['missing']]);
});

test('перед публикацией права перечитываются у Telegram', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    [, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]]), GW_NOW);
    $bot = (int)strtok(GW_TOKEN, ':');
    $tg = function (array $me) use ($bot): callable {
        return function (string $m, array $a) use ($me, $bot): ?array {
            if ($m === 'getChatMember' && $a['user_id'] === $bot) { return $me; }
            if ($m === 'createChatInviteLink') { return ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+gwA']]; }
            return ['ok' => true, 'result' => []];
        };
    };
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW, $tg(['ok' => true, 'result' => gw_bot_admin(['can_post_messages'])]), $bot);
    assert_eq([409, ['channel_not_admin']], [$s, $res['problems']], 'канал записан как готовый, а прав уже нет');
    assert_eq(['Отправка публикаций'], gw_admin_channels($p)[0]['missing']);

    [$s] = gw_publish($p, $out['id'], GW_NOW, $tg(['ok' => false, 'error_code' => 403]), $bot);
    assert_eq(409, $s, 'бота выгнали из канала');
    assert_eq(false, gw_admin_channels($p)[0]['admin']);

    [$s] = gw_publish($p, $out['id'], GW_NOW, $tg(['ok' => true, 'result' => gw_bot_admin()]), $bot);
    assert_eq(200, $s, 'права вернули — публикуется');
});

test('Telegram не ответил при публикации — права остаются как записаны', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    [, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]]), GW_NOW);
    $send = function (string $m, array $a): ?array {
        if ($m === 'getChatMember') { return null; }
        if ($m === 'createChatInviteLink') { return ['ok' => true, 'result' => ['invite_link' => 'https://t.me/+gwA']]; }
        return ['ok' => true, 'result' => []];
    };
    [$s] = gw_publish($p, $out['id'], GW_NOW, $send, (int)strtok(GW_TOKEN, ':'));
    assert_eq(200, $s);
});

test('до миграции прав каналы всё равно пишутся, а без прав не годятся', function () {
    $p = gw_db();
    $p->exec('DROP TABLE tg_channels');
    $p->exec("CREATE TABLE tg_channels (
        chat_id INTEGER NOT NULL PRIMARY KEY, title TEXT NOT NULL DEFAULT '', username TEXT NOT NULL DEFAULT '',
        kind TEXT NOT NULL DEFAULT '', invite_url TEXT NOT NULL DEFAULT '', is_admin INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL)");
    gw_channel_update($p, gw_promo_upd(['status' => 'left'], gw_bot_admin(['can_post_stories'])), GW_NOW);
    $c = gw_admin_channels($p)[0];
    assert_eq(['Канал A', false, []], [$c['title'], $c['admin'], $c['missing']], 'каких прав нет — не знаем, но канал неготов');
    assert_eq(GW_CH_A, gw_channel_update($p, gw_promo_upd(gw_bot_admin(['can_post_stories']), gw_bot_admin()), GW_NOW + 1));
    assert_eq(true, gw_admin_channels($p)[0]['admin']);
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
//  Учёт подписчиков для рекламодателя
// --------------------------------------------------------------------------

// Обновление chat_member: $tgUser вступил в канал (по ссылке $link) или вышел.
function gw_member(int $channel, int $tgUser, string $status, string $link = '', string $username = 'newbie'): array {
    $m = [
        'chat' => ['id' => $channel, 'type' => 'channel', 'title' => 'Канал'],
        'from' => ['id' => $tgUser],
        'date' => GW_NOW,
        'old_chat_member' => ['status' => $status === 'member' ? 'left' : 'member', 'user' => ['id' => $tgUser]],
        'new_chat_member' => ['status' => $status, 'user' => ['id' => $tgUser, 'is_bot' => false, 'first_name' => 'N', 'username' => $username]],
    ];
    if ($link !== '') { $m['invite_link'] = ['invite_link' => $link, 'name' => 'Maknemy']; }
    return ['update_id' => 1, 'chat_member' => $m];
}

// Опубликовать через админку (с созданием ссылок). Возвращает [id, ссылка канала A].
function gw_live_links(PDO $p, array $extra = []): array {
    $log = [];
    [$s, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]] + $extra), GW_NOW - 10);
    [$s] = gw_publish($p, $out['id'], GW_NOW - 5, gw_tg($log));
    assert_eq(200, $s, 'опубликован со ссылками');
    return [$out['id'], gw_links($p, [$out['id']])[$out['id']][GW_CH_A]];
}

test('публикация заводит свою ссылку-приглашение в каждом канале', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_channel($p, GW_CH_B, 'Канал B', true, 'public_b');
    [, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A, GW_CH_B]]), GW_NOW);
    $log = [];
    [$s] = gw_publish($p, $out['id'], GW_NOW, gw_tg($log));
    assert_eq(200, $s);
    $calls = gw_sent($log, 'createChatInviteLink');
    assert_eq(2, count($calls), 'по ссылке на канал');
    assert_eq('Maknemy #' . $out['id'], $calls[0][1]['name'], 'имя ссылки видно в канале');
    $links = gw_links($p, [$out['id']])[$out['id']];
    $list = handle_giveaways(function () use ($p) { return $p; }, GW_NOW);
    assert_eq([$links[GW_CH_A], $links[GW_CH_B]], array_column($list['live'][0]['channels'], 'url'),
        'кнопка «Подписаться» ведёт на ссылку розыгрыша, даже у публичного канала');
});

test('нет права на ссылки — публикация не проходит и говорит, где', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    [, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A]]), GW_NOW);
    $deny = function (string $m, array $a): ?array {
        return $m === 'createChatInviteLink' ? ['ok' => false, 'error_code' => 400, 'description' => 'not enough rights'] : ['ok' => true];
    };
    [$s, $res] = gw_publish($p, $out['id'], GW_NOW, $deny);
    assert_eq([409, ['invite_link'], ['Канал A']], [$s, $res['problems'], $res['channels']]);
    assert_eq('draft', gw_load($p, $out['id'])['status'], 'остался черновиком');
});

test('участие запоминает, откуда человек пришёл, и бот подтверждает', function () {
    $p = gw_db();
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502, 'en');
    $id = gw_live($p);
    $log = [];
    $r = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW, 'tg');
    assert_eq(501, $r[2]['chat_id'], 'кому подтвердить');
    $again = gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW, 'tg');
    assert_eq(null, $again[2] ?? null, 'повторно — без второго сообщения');
    gw_enter($p, gw_cfg(), gw_tg($log), '2', $id, GW_NOW, 'hack');
    assert_eq(['1' => 'tg', '2' => ''], array_map('strval', $p->query('SELECT user_id, src FROM giveaway_entries ORDER BY user_id')->fetchAll(PDO::FETCH_KEY_PAIR)),
        'чужое значение источника не пишется');

    $log = [];
    gw_after_join($p, gw_cfg(), $r[2], gw_tg($log));
    $msg = gw_sent($log)[0][1];
    assert_eq(501, $msg['chat_id']);
    assert_true(strpos($msg['text'], 'Вы участвуете в розыгрыше «Розыгрыш Magnet»') !== false, 'подтверждение');
    assert_true(strpos($msg['text'], 'отпишетесь') === false, 'без каналов — без предупреждения об отписке');
    $row = gw_load($p, $id);
    $row['channels_json'] = '[' . GW_CH_A . ']';
    assert_true(strpos(gw_joined_text($row, 'ru'), 'участие снимется автоматически') !== false, 'с каналами — предупреждение');
});

test('вступил по ссылке розыгрыша — в списке; по чужой ссылке — нет', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    [$id, $link] = gw_live_links($p);
    assert_eq([], gw_member_update($p, gw_member(GW_CH_A, 901, 'member', $link), GW_NOW));
    gw_member_update($p, gw_member(GW_CH_A, 902, 'member', 'https://t.me/+someoneelse'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_A, 903, 'member'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_B, 904, 'member', $link), GW_NOW);
    $subs = $p->query('SELECT tg_user_id, tg_name FROM giveaway_subs')->fetchAll(PDO::FETCH_KEY_PAIR);
    assert_eq([901 => '@newbie'], array_map('strval', $subs), 'только пришедший по нашей ссылке в наш канал');
});

test('отписался — участие снято, бот пишет, в отчёте видно', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_channel($p, GW_CH_B, 'Канал B');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502);
    [$id, $link] = gw_live_links($p);
    $other = gw_live($p, ['title_ru' => 'Без канала A']);
    $log = [];
    $subs = [GW_CH_A => [501, 502]];
    gw_member_update($p, gw_member(GW_CH_A, 501, 'member', $link, 'ann'), GW_NOW);
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '1', $id, GW_NOW, 'pop');
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '2', $id, GW_NOW, 'tg');
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '1', $other, GW_NOW, 'page');

    gw_member_update($p, gw_member(GW_CH_B, 501, 'left'), GW_NOW + 5);
    assert_eq(3, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'вышел из чужого канала — ничего не снято');

    $notes = gw_member_update($p, gw_member(GW_CH_A, 501, 'left'), GW_NOW + 10);
    assert_eq(1, count($notes), 'одно уведомление');
    assert_eq([501, 'Канал A', $id], [$notes[0]['chat_id'], $notes[0]['channel'], (int)$notes[0]['row']['id']]);
    $left = $p->query('SELECT giveaway_id, user_id FROM giveaway_entries ORDER BY giveaway_id, user_id')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[$id, 2], [$other, 1]], array_map(function ($r) { return array_map('intval', $r); }, $left),
        'снято только там, где канал A в условиях');
    assert_eq([$other], gw_joined_ids($p, '1', GW_NOW), 'окно снова позовёт в розыгрыш');

    $log = [];
    gw_after_member($p, gw_cfg(), $notes, GW_NOW + 10, gw_tg($log));
    $msg = gw_sent($log)[0][1];
    assert_true(strpos($msg['text'], 'отписались от канала «Канал A»') !== false && strpos($msg['text'], 'подпишитесь снова') !== false, 'текст отписки');

    $rep = gw_report($p, gw_load($p, $id));
    assert_eq(['tg' => 1, 'pop' => 0, 'page' => 0, '' => 0], $rep['sources'], 'источники оставшихся участников');
    $ch = $rep['channels'][0];
    assert_eq([1, 0, $link], [$ch['joined'], $ch['stayed'], $ch['link']], 'пришёл 1, остался 0');
    assert_eq(['501', '@ann', GW_NOW + 10, '1', false], [$ch['list'][0]['tg_id'], $ch['list'][0]['tg_name'], $ch['list'][0]['left_at'],
        $ch['list'][0]['site_id'], $ch['list'][0]['entered']], 'кто, когда ушёл, аккаунт сайта');

    gw_member_update($p, gw_member(GW_CH_A, 501, 'member', $link, 'ann'), GW_NOW + 20);
    assert_eq(1, gw_report($p, gw_load($p, $id))['channels'][0]['stayed'], 'вернулся по ссылке — снова подписан');
});

test('после итогов отписка участие не трогает', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    [$id] = gw_live_links($p);
    $log = [];
    gw_enter($p, gw_cfg(), gw_tg($log, [GW_CH_A => [501]]), '1', $id, GW_NOW);
    gw_draw($p, gw_cfg(), gw_tg($log, [GW_CH_A => [501]]), $id, GW_NOW + 86400);
    assert_eq([], gw_member_update($p, gw_member(GW_CH_A, 501, 'left'), GW_NOW + 90000));
    assert_eq(1, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'победитель остался в списке');
});

test('галочка снята: отписка участие не снимает, бот молчит, итоги без перепроверки', function () {
    assert_true(gw_validate(gw_draft())['row']['drop_unsub'], 'по умолчанию отписка снимает участие');
    assert_eq(false, gw_validate(gw_draft(['drop_unsub' => false]))['row']['drop_unsub'], 'галочку можно снять');

    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502);
    [$id, $link] = gw_live_links($p, ['drop_unsub' => false]);
    assert_eq(false, gw_admin_item($p, gw_load($p, $id), GW_NOW)['drop_unsub'], 'админка видит снятую галочку');
    $log = [];
    $subs = [GW_CH_A => [501, 502]];
    gw_member_update($p, gw_member(GW_CH_A, 501, 'member', $link, 'ann'), GW_NOW);
    assert_eq(409, gw_enter($p, gw_cfg(), gw_tg($log), '1', $id, GW_NOW)[0], 'при участии подписку всё равно проверяют');
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '1', $id, GW_NOW);
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '2', $id, GW_NOW);
    assert_true(strpos(gw_joined_text(gw_load($p, $id), 'ru'), 'отпишетесь') === false, 'бот не пугает отпиской');

    assert_eq([], gw_member_update($p, gw_member(GW_CH_A, 501, 'left'), GW_NOW + 10), 'отписался — бот молчит');
    assert_eq(2, (int)$p->query('SELECT COUNT(*) FROM giveaway_entries')->fetchColumn(), 'участие осталось');
    assert_eq(0, gw_report($p, gw_load($p, $id))['channels'][0]['stayed'], 'рекламодатель всё равно видит отписку');

    $log = [];
    [$s, $out] = gw_draw($p, gw_cfg(), gw_tg($log), $id, GW_NOW + 86400);
    assert_eq([200, 2, 0], [$s, $out['winners'], $out['skipped']], 'отписавшиеся выигрывают');
    assert_eq([], gw_sent($log, 'getChatMember'), 'на итогах Telegram не спрашивали');
});

test('галочку меняют у идущего розыгрыша: со следующей отписки снимает', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    gw_user($p, '2', 'bob', 502);
    $id = gw_live($p, ['channels' => [GW_CH_A], 'drop_unsub' => false]);
    $log = [];
    $subs = [GW_CH_A => [501, 502]];
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '1', $id, GW_NOW);
    gw_enter($p, gw_cfg(), gw_tg($log, $subs), '2', $id, GW_NOW);
    assert_eq([], gw_member_update($p, gw_member(GW_CH_A, 501, 'left'), GW_NOW + 5), 'без галочки ann осталась');

    [$s] = gw_save($p, gw_draft(['id' => $id, 'channels' => [GW_CH_A]]), GW_NOW + 10);
    assert_eq([200, 'live', true], [$s, gw_load($p, $id)['status'], gw_drops_unsub(gw_load($p, $id))], 'галочку поставили, розыгрыш идёт');
    $notes = gw_member_update($p, gw_member(GW_CH_A, 502, 'left'), GW_NOW + 20);
    assert_eq([502], array_column($notes, 'chat_id'), 'теперь отписка снимает, бот пишет');

    $log = [];
    [$s, $out] = gw_draw($p, gw_cfg(), gw_tg($log, [GW_CH_A => []]), $id, GW_NOW + 86400);
    assert_eq([200, 0, 1], [$s, $out['winners'], $out['skipped']], 'на итогах ann уже перепроверяют');
});

test('до миграции drop_unsub: отписка снимает участие, снять галочку нельзя', function () {
    $p = gw_db();
    $p->exec('DROP TABLE giveaways');
    $p->exec("CREATE TABLE giveaways (
        id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'draft', test INTEGER NOT NULL DEFAULT 0,
        title_ru TEXT NOT NULL DEFAULT '', title_en TEXT NOT NULL DEFAULT '',
        image_url TEXT NOT NULL DEFAULT '', image_w INTEGER NOT NULL DEFAULT 0, image_h INTEGER NOT NULL DEFAULT 0,
        body_json TEXT NULL, prizes_json TEXT NULL, channels_json TEXT NULL, conds_json TEXT NULL,
        max_entries INTEGER NOT NULL DEFAULT 0, ends_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
        published_at INTEGER NOT NULL DEFAULT 0, drawn_at INTEGER NOT NULL DEFAULT 0)");
    gw_channel($p, GW_CH_A, 'Канал A');
    gw_user($p, '1', 'ann', 501);
    [$s, $out] = gw_save($p, gw_draft(['channels' => [GW_CH_A], 'drop_unsub' => false]), GW_NOW);
    assert_eq([409, 'drop_migrate'], [$s, $out['error']]);
    $id = gw_live($p, ['channels' => [GW_CH_A]]);
    assert_eq(true, gw_admin_item($p, gw_load($p, $id), GW_NOW)['drop_unsub'], 'админка: галочка стоит');
    $log = [];
    gw_enter($p, gw_cfg(), gw_tg($log, [GW_CH_A => [501]]), '1', $id, GW_NOW);
    assert_eq([501], array_column(gw_member_update($p, gw_member(GW_CH_A, 501, 'left'), GW_NOW + 5), 'chat_id'), 'снимает, как раньше');
});

test('ссылка своего условия: обрезанную Telegram ссылку узнаём по началу', function () {
    $cases = [
        'целиком'              => [GW_STOCK, true],
        'обрезана'             => ['https://t.me/+dtbxvZn9…', true],
        'обрезана тремя точками' => ['https://t.me/+dtbxvZn9...', true],
        'другая ссылка'        => ['https://t.me/+zzzzzzzz…', false],
        'слишком коротко'      => ['https://t.me/+dtb…', false],
        'другой хвост целиком' => ['https://t.me/+dtbxvZn9baA1OTVj', false],
        'регистр хеша важен'   => ['https://t.me/+DTBXVZN9…', false],
    ];
    foreach ($cases as $why => [$seen, $want]) {
        assert_eq($want, gw_invite_match($seen, GW_STOCK), $why);
    }
    assert_true(gw_invite_match('https://t.me/+dtbxvZn9…', 'http://telegram.me/joinchat/dtbxvZn9baA1OTVi/'), 'старая запись joinchat — то же приглашение');
    assert_eq(false, gw_invite_match('https://t.me/maknemy…', 'https://t.me/maknemy'), 'публичный адрес — не приглашение');
});

test('вступил по ссылке своего условия — в отчёте рекламодателю', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_B, 'Сток');
    gw_channel($p, GW_CH_A, 'Публичный', true, 'pub_a');
    $id = gw_live($p, ['conds' => [gw_cond('Сток', GW_STOCK, '', '', '', (string)GW_CH_B),
        gw_cond('Публичный', 'https://t.me/pub_a', '', '', '', (string)GW_CH_A)]]);
    $other = gw_live($p, ['title_ru' => 'Без условий']);

    gw_member_update($p, gw_member(GW_CH_B, 901, 'member', 'https://t.me/+dtbxvZn9…', 'ann'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_B, 902, 'member', 'https://t.me/+someoneelse…'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_B, 903, 'member'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_A, 904, 'member', 'https://t.me/+dtbxvZn9…'), GW_NOW);
    gw_member_update($p, gw_member(GW_CH_B, 905, 'member', 'https://t.me/+dtbxvZn9…'), GW_NOW + 86400);
    $subs = $p->query('SELECT giveaway_id, tg_user_id FROM giveaway_subs')->fetchAll(PDO::FETCH_NUM);
    assert_eq([[$id, 901]], array_map(function ($r) { return array_map('intval', $r); }, $subs),
        'только по ссылке условия, в канал условия, в этот розыгрыш и пока он идёт');

    gw_member_update($p, gw_member(GW_CH_B, 901, 'left'), GW_NOW + 10);
    $rep = gw_report($p, gw_load($p, $id));
    assert_eq(['Сток', 'Публичный'], array_column($rep['channels'], 'title'), 'каналы своих условий в отчёте');
    $ch = $rep['channels'][0];
    assert_eq([GW_STOCK, true, true, 1, 0, '@ann'], [$ch['link'], $ch['own'], $ch['counts'], $ch['joined'], $ch['stayed'], $ch['list'][0]['tg_name']],
        'ссылка условия, пришёл 1, ушёл');
    assert_eq(['https://t.me/pub_a', false], [$rep['channels'][1]['link'], $rep['channels'][1]['counts']], 'публичный адрес не считается — отчёт об этом говорит');
    assert_eq([], gw_report($p, gw_load($p, $other))['channels'], 'у розыгрыша без условий каналов нет');
});

test('удаление розыгрыша чистит ссылки и подписчиков', function () {
    $p = gw_db();
    gw_channel($p, GW_CH_A, 'Канал A');
    [$id, $link] = gw_live_links($p);
    gw_member_update($p, gw_member(GW_CH_A, 901, 'member', $link), GW_NOW);
    gw_delete($p, $id);
    assert_eq([0, 0], [(int)$p->query('SELECT COUNT(*) FROM giveaway_links')->fetchColumn(),
        (int)$p->query('SELECT COUNT(*) FROM giveaway_subs')->fetchColumn()]);
});

// --------------------------------------------------------------------------
//  Где показывается окно
// --------------------------------------------------------------------------

test('окно розыгрыша — на всех страницах, кроме главной, правовых и самой /giveaways', function () {
    $pub = dirname(__DIR__) . '/public_html';
    $with = ['index.php', 'calculator.php', 'news.php', 'trading.php', 'trade-new.php',
             'stock.php', 'chat.php', 'profile.php', 'support.php', 'promote.php'];
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
