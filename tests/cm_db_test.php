<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Данные чат-менеджера: api/lib/cm/db.php и тексты api/lib/cm/texts.php.

test('без миграции модуль молчит, с ней готов', function () {
    assert_eq(false, cm_ready(test_db()));
    assert_eq(true, cm_ready(cm_test_pdo()));
});

test('выключатель в config', function () {
    assert_eq(true, cm_enabled([]));
    assert_eq(true, cm_enabled(['cm_enabled' => true]));
    assert_eq(false, cm_enabled(['cm_enabled' => false]));
});

test('по умолчанию сами работают только ссылки, спамеры и служебные', function () {
    $s = cm_settings([]);
    $on = array_keys(array_filter($s['filters']));
    sort($on);
    assert_eq(['links', 'service', 'spam'], $on);
    assert_eq(['limit' => 5, 'ttl' => 2592000], $s['warns']);
    assert_eq(true, $s['autodelete']);
});

test('настройки сливаются с умолчаниями, мусор отбрасывается', function () {
    $s = cm_settings(['filters' => ['mat' => true, 'nope' => true], 'warns' => ['limit' => 7],
                      'autodelete' => 'да', 'zzz' => 1]);
    assert_eq(true, $s['filters']['mat']);
    assert_eq(false, isset($s['filters']['nope']));
    assert_eq(7, $s['warns']['limit']);
    assert_eq(2592000, $s['warns']['ttl']);
    assert_eq(true, $s['autodelete'], 'строка вместо bool не принимается');
    assert_eq(false, isset($s['zzz']));
});

test('чат заводится один раз, название обновляется, погашенный оживает', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Первый', 'type' => 'supergroup'], CM_NOW);
    assert_eq([CM_CHAT, 'Первый', 1, false], [$c['chat_id'], $c['title'], $c['active'], $c['private']]);
    $pdo->exec('UPDATE cm_chats SET active = 0');
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Второй', 'username' => 'MyChat'], CM_NOW + 5);
    assert_eq(['Второй', 'mychat', 1], [$c['title'], $c['username'], $c['active']]);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_chats')->fetchColumn());
    assert_eq(true, cm_private_chat(42)['private']);
});

test('настройки сохраняются и читаются', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Т'], CM_NOW);
    $s = $c['settings'];
    $s['filters']['mat'] = true;
    $s['domains'] = ['youtube.com'];
    cm_settings_save($pdo, $c, $s, CM_NOW);
    assert_eq(true, $c['settings']['filters']['mat'], 'в памяти');
    $back = cm_chat_row($pdo, CM_CHAT);
    assert_eq(true, $back['settings']['filters']['mat']);
    assert_eq(['youtube.com'], $back['settings']['domains']);
});

test('ник ищется без учёта регистра и @, ботов не запоминаем', function () {
    $pdo = cm_test_pdo();
    cm_user_touch($pdo, ['id' => 55, 'first_name' => 'Вася', 'last_name' => 'Пупкин', 'username' => 'VasyaP'], CM_NOW);
    assert_eq(['user_id' => 55, 'name' => 'Вася Пупкин'], cm_user_find($pdo, '@vasyap'));
    assert_eq(null, cm_user_find($pdo, 'nobody'));
    assert_eq('Вася Пупкин', cm_user_name($pdo, 55));
    assert_eq('id77', cm_user_name($pdo, 77));
    cm_user_touch($pdo, ['id' => 55, 'first_name' => 'Василий', 'username' => 'vasyap'], CM_NOW + 1);
    assert_eq('Василий', cm_user_name($pdo, 55), 'имя обновляется');
    cm_user_touch($pdo, ['id' => 56, 'is_bot' => true, 'first_name' => 'Bot'], CM_NOW);
    assert_eq('id56', cm_user_name($pdo, 56));
});

test('участник заводится и обновляется по полям', function () {
    $pdo = cm_test_pdo();
    cm_member_touch($pdo, CM_CHAT, 7, CM_NOW, true);
    $m = cm_member($pdo, CM_CHAT, 7);
    assert_eq([0, CM_NOW, CM_NOW], [$m['lvl'], $m['joined_at'], $m['last_seen']]);
    cm_member_set($pdo, CM_CHAT, 7, ['lvl' => 3, 'nope' => 1]);
    cm_member_touch($pdo, CM_CHAT, 7, CM_NOW + 60);
    $m = cm_member($pdo, CM_CHAT, 7);
    assert_eq([3, CM_NOW, CM_NOW + 60], [$m['lvl'], $m['joined_at'], $m['last_seen']], 'вход не перезаписан обычным сообщением');
    assert_eq(null, cm_member($pdo, CM_CHAT, 8));
});

test('сообщения пишутся с отпечатком текста, повтор id не роняет', function () {
    $pdo = cm_test_pdo();
    cm_msg_log($pdo, CM_CHAT, 10, 7, 'Привет', CM_NOW);
    cm_msg_log($pdo, CM_CHAT, 10, 7, 'Привет', CM_NOW);
    $h = $pdo->query('SELECT text_hash FROM cm_msgs')->fetchAll(PDO::FETCH_COLUMN);
    assert_eq([cm_text_hash('привет ')], $h);
    assert_eq('', cm_text_hash('   '));
});

test('группа стала супергруппой — всё переезжает на новый id', function () {
    $pdo = cm_test_pdo();
    $c = cm_chat_ensure($pdo, ['id' => -500, 'title' => 'Старая'], CM_NOW);
    $s = $c['settings'];
    $s['filters']['mat'] = true;
    cm_settings_save($pdo, $c, $s, CM_NOW);
    cm_member_set($pdo, -500, 7, ['lvl' => 2]);
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Новая'], CM_NOW);
    cm_member_set($pdo, CM_CHAT, 7, ['lvl' => 0]);
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(true, cm_chat_row($pdo, CM_CHAT)['settings']['filters']['mat']);
    assert_eq(2, cm_member($pdo, CM_CHAT, 7)['lvl']);
    assert_eq(null, cm_chat_row($pdo, -500));
    cm_migrate($pdo, -500, CM_CHAT);
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_chats')->fetchColumn(), 'повтор безвреден');
});

test('миграция лимита варнов: сохранённые 3 → 5, другое число не трогает', function () {
    $pdo = cm_test_pdo();
    foreach ([-501 => 3, -502 => 7, -503 => 13] as $id => $limit) {
        $c = cm_chat_ensure($pdo, ['id' => $id, 'title' => 'Чат'], CM_NOW);
        $s = $c['settings'];
        $s['warns']['limit'] = $limit;
        $s['maknemy'] = true;
        $s['solo'] = true;   // вне сети: иначе общие настройки уравняли бы лимиты
        cm_settings_save($pdo, $c, $s, CM_NOW);
    }
    $pdo->exec((string)file_get_contents(__DIR__ . '/../docs/migrations/2026-11-08-cm-warn-limit-5.sql'));
    $got = [];
    foreach ([-501, -502, -503] as $id) {
        $s = cm_chat_row($pdo, $id)['settings'];
        $got[$id] = [$s['warns']['limit'], $s['warns']['ttl'], $s['maknemy']];
    }
    assert_eq([-501 => [5, 2592000, true], -502 => [7, 2592000, true], -503 => [13, 2592000, true]], $got);
});

test('тексты подставляют поля', function () {
    assert_eq('⚠️ Вася: варн 1/3 на 30 дней.', cm_t('warn_done', ['name' => 'Вася', 'n' => 1, 'limit' => 3, 'wterm' => 'на 30 дней', 'reason' => '']));
    assert_eq('нет_такого', cm_t('нет_такого'));
    assert_eq('', cm_reason_line(''));
    assert_eq("\nПричина: спам", cm_reason_line('спам'));
    assert_eq(1, preg_match('~^\d\d\.\d\d \d\d:\d\d$~', cm_date(CM_NOW)));
});

run_tests();
