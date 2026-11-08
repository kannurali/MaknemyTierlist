<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Сеть чатов Макнеми (решение владельца 08.11.2026): общее всё, кроме игр и
// создателя. CM_CHAT и NW_TRADE — сеть, NW_SOLO — чат Макнеми вне сети
// (тестовый), NW_ALIEN — чужой.
const NW_TRADE = -1005550021;
const NW_SOLO  = -1005550022;
const NW_ALIEN = -1005550023;

function nw_seed(PDO $pdo): void {
    cm_seed_chat($pdo, NW_TRADE, true);
    cm_seed_chat($pdo, NW_ALIEN, false);
    $pdo->prepare('INSERT INTO cm_chats (chat_id, title, settings, added_at, updated_at) VALUES (:c, :t, :s, :n, :n)')
        ->execute([':c' => NW_SOLO, ':t' => 'Тестим', ':s' => '{"maknemy":true,"solo":true}', ':n' => CM_NOW]);
}

function nw_ctx(PDO $pdo, array &$log, int $actor, int $chatId, string $text = ''): array {
    return cm_ctx_for($pdo, $log, $actor, $text, ['chat' => ['id' => $chatId, 'type' => 'supergroup', 'title' => 'Чат ' . $chatId]]);
}

function nw_chats(array $log, string $method): array {
    $ids = array_map(function ($c) { return (int)$c[1]['chat_id']; }, cm_calls($log, $method));
    sort($ids);
    return $ids;
}

test('сеть: в неё входят чаты Макнеми без solo; solo видит только себя', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    assert_eq([CM_CHAT, NW_TRADE], cm_net($pdo, NW_TRADE));
    assert_eq([NW_SOLO], cm_net($pdo, NW_SOLO));
    $log = [];
    assert_eq([NW_ALIEN], cm_scope(nw_ctx($pdo, $log, 1, NW_ALIEN)), 'чужой чат — только он');
});

test('сеть: настройки общие, свои ключи — нет; solo в стороне', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    $c = cm_chat_row($pdo, CM_CHAT);
    $s = $c['settings'];
    $s['welcome'] = 'Привет';
    $s['filters']['mat'] = true;
    $s['access']['кик'] = 3;
    $s['call_at'] = 123;
    cm_settings_save($pdo, $c, $s, CM_NOW);
    $t = cm_chat_row($pdo, NW_TRADE)['settings'];
    assert_eq(['Привет', true, 3, 0], [$t['welcome'], $t['filters']['mat'], $t['access']['кик'], $t['call_at']],
        'приветствие, фильтр и «дк» — в трейд-чате; отметка созыва — нет');
    assert_eq('', cm_chat_row($pdo, NW_SOLO)['settings']['welcome'], 'solo не трогаем');
    assert_eq('', cm_chat_row($pdo, NW_ALIEN)['settings']['welcome'], 'чужой не трогаем');
    $solo = cm_chat_row($pdo, NW_SOLO);
    $ss = $solo['settings'];
    $ss['welcome'] = 'Тест';
    cm_settings_save($pdo, $solo, $ss, CM_NOW);
    assert_eq('Привет', cm_chat_row($pdo, CM_CHAT)['settings']['welcome'], 'правка в solo в сеть не уходит');
    $log = [];
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), "+правила\nНе флудить");
    assert_eq('Не флудить', cm_chat_row($pdo, CM_CHAT)['settings']['rules']['text'], 'правила из трейд-чата — и в главном');
});

test('сеть: новый чат Макнеми берёт общие настройки и чат жалоб', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_row($pdo, CM_CHAT);
    $s = $c['settings'];
    $s['welcome'] = 'Привет';
    cm_settings_save($pdo, $c, $s, CM_NOW);
    $pdo->exec('UPDATE cm_chats SET report_chat_id = -777 WHERE chat_id = ' . CM_CHAT);
    $new = cm_chat_ensure($pdo, ['id' => NW_TRADE, 'title' => 'Трейд'], CM_NOW);
    $ns = $new['settings'];
    $ns['call_at'] = 55;
    cm_settings_save($pdo, $new, $ns, CM_NOW);
    cm_mark_ours($pdo, $new, CM_NOW + 1);
    $row = cm_chat_row($pdo, NW_TRADE);
    assert_eq(['Привет', 55, true, -777], [$row['settings']['welcome'], $row['settings']['call_at'], $row['settings']['maknemy'],
        $row['report_chat_id']]);
    assert_eq('Привет', cm_chat_row($pdo, CM_CHAT)['settings']['welcome'], 'сеть новым чатом не затёрта');
});

test('сеть: бан и мут — во всех чатах сети, снятие тоже; кик — только здесь', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    cm_person($pdo, 20, 'vasya', 'Вася');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'бан @vasya');
    assert_eq([CM_CHAT, NW_TRADE], nw_chats($log, 'banChatMember'));
    assert_eq(true, strpos(cm_last_text($log), 'во всех чатах Maknemy') !== false);
    $log = [];
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'баны');
    assert_eq(true, strpos(cm_last_text($log), 'Вася') !== false, 'бан виден из трейд-чата');
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'разбан @vasya');
    assert_eq([CM_CHAT, NW_TRADE], nw_chats($log, 'unbanChatMember'));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'баны');
    assert_eq(cm_t('bans_head') . "\n" . cm_t('list_empty'), cm_last_text($log), 'снят везде');
    $log = [];
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'мут @vasya 1ч');
    assert_eq([CM_CHAT, NW_TRADE], nw_chats($log, 'restrictChatMember'));
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'кик @vasya');
    assert_eq([CM_CHAT], nw_chats($log, 'banChatMember'), 'кик — только из этого чата');
    $log = [];
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_SOLO), 'бан @vasya');
    assert_eq([NW_SOLO], nw_chats($log, 'banChatMember'), 'в solo — только он');
    assert_eq(false, strpos(cm_last_text($log), 'во всех чатах') !== false);
});

test('сеть: ошибка в соседнем чате не мешает бану здесь', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    cm_person($pdo, 20, 'vasya', 'Вася');
    $log = [];
    $answers = ['banChatMember' => function ($p) {
        return (int)$p['chat_id'] === NW_TRADE ? ['ok' => false, 'description' => 'Bad Request: not enough rights'] : ['ok' => true, 'result' => true];
    }];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER, '', [], $answers);
    cm_do($ctx, 'бан @vasya');
    $rows = $pdo->query("SELECT chat_id FROM cm_punishments WHERE kind = 'ban'")->fetchAll(PDO::FETCH_COLUMN);
    assert_eq([CM_CHAT], array_map('intval', $rows), 'запись — где получилось');
    assert_eq(true, strpos(cm_last_text($log), 'бан') !== false);
});

test('сеть: варны считаются по всей сети, лимит — бан везде', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    cm_person($pdo, 20, 'vasya', 'Вася');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'варны лимит 2');
    assert_eq(2, cm_chat_row($pdo, NW_TRADE)['settings']['warns']['limit'], 'лимит общий');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'варн @vasya');
    assert_eq(1, cm_warns_active(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 20), 'варн из главного виден в трейд-чате');
    $log = [];
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'варн @vasya');
    assert_eq(true, strpos(cm_last_text($log), '2/2') !== false);
    assert_eq([CM_CHAT, NW_TRADE], nw_chats($log, 'banChatMember'), 'лимит — бан во всей сети');
    assert_eq(0, cm_warns_active(cm_ctx_for($pdo, $log, CM_OWNER), 20), 'варны сняты везде');
});

test('сеть: заметки, свои слова и таймеры общие', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), "+заметка трейды\nКак торговать");
    assert_eq(true, cm_note_try(nw_ctx($pdo, $log, 30, NW_TRADE, '#трейды')), 'заметка из главного — в трейд-чате');
    assert_eq('Как торговать', cm_calls($log, 'sendMessage')[count(cm_calls($log, 'sendMessage')) - 1][1]['text']);
    assert_eq(false, cm_note_try(nw_ctx($pdo, $log, 30, NW_SOLO, '#трейды')), 'в solo её нет');
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), "+заметка трейды\nНовый текст");
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_notes')->fetchColumn(), 'то же имя правит ту же заметку');
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), '+слово кринж');
    $c = cm_chat_row($pdo, CM_CHAT);
    $s = $c['settings'];
    $s['filters']['mat'] = true;
    cm_settings_save($pdo, $c, $s, CM_NOW);
    $msg = cm_gmsg(30, 'это кринж');
    assert_eq('mat', cm_filter_hit(cm_ctx_for($pdo, $log, 30), $msg), 'слово из трейд-чата режет в главном');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+слово кринж');
    assert_eq(cm_t('word_exists', ['word' => 'кринж']), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), "таймер через 2ч\nВсем привет");
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'таймеры');
    assert_eq(true, strpos(cm_last_text($log), 'Всем привет') !== false, 'таймер виден из трейд-чата');
    $tick = [];
    assert_eq(1, cm_timers_tick($pdo, cm_cfg(), cm_rec($tick), CM_NOW + 7300));
    $to = array_map(function ($c) { return (int)$c[1]['chat_id']; }, cm_calls($tick, 'sendMessage'));
    sort($to);
    assert_eq([CM_CHAT, NW_TRADE], $to, 'таймер пишет в оба чата сети');
});

test('сеть: награды, пары, браки и кланы общие', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    cm_person($pdo, 20, 'vasya', 'Вася');
    cm_person($pdo, 21, 'petya', 'Петя');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), '+награда 3 @vasya за помощь');
    cm_do(nw_ctx($pdo, $log, 30, NW_TRADE), 'награды @vasya');
    assert_eq(true, strpos(cm_last_text($log), 'за помощь') !== false, 'награда видна в трейд-чате');
    $pdo->exec('INSERT INTO cm_couples (chat_id, a_id, b_id, since, xp, xp_day, xp_today, ended_at) VALUES ('
        . CM_CHAT . ', 20, 21, ' . CM_NOW . ', 0, 0, 0, 0)');
    $pdo->exec('INSERT INTO cm_marriages (chat_id, a_id, b_id, since, ended_at, ended_by) VALUES ('
        . CM_CHAT . ', 20, 21, ' . CM_NOW . ', 0, 0)');
    $trade = nw_ctx($pdo, $log, 20, NW_TRADE);
    assert_eq(21, cm_partner(cm_couple_of($pdo, cm_scope($trade), 20), 20), 'пара из главного — в трейд-чате');
    assert_eq(21, cm_partner(cm_marriage_of($pdo, cm_scope($trade), 20), 20), 'брак из главного — в трейд-чате');
    assert_eq(null, cm_couple_of($pdo, cm_scope(nw_ctx($pdo, $log, 20, NW_SOLO)), 20), 'в solo пары нет');
    cm_couple_xp($pdo, cm_scope($trade), 21, 20, CM_NOW);
    assert_eq(1, (int)$pdo->query('SELECT xp FROM cm_couples')->fetchColumn(), 'РП в трейд-чате растит пару');
    cm_do(cm_ctx_for($pdo, $log, 20), '+клан Волки');
    cm_do(nw_ctx($pdo, $log, 21, NW_TRADE), '+клан Волки');
    assert_eq(cm_t('clan_taken', ['name' => 'Волки']), cm_last_text($log), 'название одно на сеть');
    cm_do(nw_ctx($pdo, $log, 21, NW_TRADE), 'вступить Волки');
    assert_eq(true, strpos(cm_last_text($log), 'Волки') !== false && strpos(cm_last_text($log), 'теперь в клане') !== false);
    $cl = cm_clan_of($pdo, cm_scope(cm_ctx_for($pdo, $log, 21)), 21);
    assert_eq('Волки', $cl['name'], 'из главного видно, что Петя в клане');
});

test('сеть: чат жалоб общий', function () {
    $pdo = cm_test_pdo();
    nw_seed($pdo);
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты в чат');
    $code = cm_chat_row($pdo, CM_CHAT)['settings']['report_code']['code'];
    assert_eq(null, cm_chat_row($pdo, NW_TRADE)['settings']['report_code'], 'код — свой у чата');
    cm_seed_chat($pdo, -1005550099, true);
    $pdo->exec("UPDATE cm_chats SET settings = '{\"maknemy\":true,\"solo\":true}' WHERE chat_id = -1005550099");
    cm_do(nw_ctx($pdo, $log, CM_OWNER, -1005550099), 'репорты сюда ' . $code);
    assert_eq([-1005550099, -1005550099, 0], [cm_chat_row($pdo, CM_CHAT)['report_chat_id'], cm_chat_row($pdo, NW_TRADE)['report_chat_id'],
        cm_chat_row($pdo, NW_SOLO)['report_chat_id']]);
    cm_do(nw_ctx($pdo, $log, CM_OWNER, NW_TRADE), 'репорты здесь');
    assert_eq([0, 0], [cm_chat_row($pdo, CM_CHAT)['report_chat_id'], cm_chat_row($pdo, NW_TRADE)['report_chat_id']]);
});

run_tests();
