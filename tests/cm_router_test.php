<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/telegram.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Сквозной путь апдейта: api/lib/cm/router.php, cron.php, установка вебхука.

function rt_run(PDO $pdo, array $msg, array &$log, array $answers = [], int $now = CM_NOW, string $key = 'message'): void {
    cm_handle_update($pdo, cm_cfg(), cm_rec($log, $answers), ['update_id' => 1, $key => $msg], $now);
}

test('чей апдейт', function () {
    $g = cm_gmsg(7, 'привет');
    $p = ['message_id' => 1, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500], 'text' => 'привет'];
    assert_eq(true, cm_wants_update(['message' => $g], CM_BOT));
    assert_eq(true, cm_wants_update(['edited_message' => $g], CM_BOT));
    assert_eq(false, cm_wants_update(['message' => $p], CM_BOT));
    assert_eq(true, cm_wants_update(['message' => ['text' => '+спамер 123456789'] + $p], CM_BOT));
    assert_eq(false, cm_wants_update(['message' => ['text' => '/start abc'] + $p], CM_BOT));
    assert_eq(true, cm_wants_update(['callback_query' => ['data' => 'cm:r:1:d']], CM_BOT));
    assert_eq(false, cm_wants_update(['callback_query' => ['data' => 'gw:join:5']], CM_BOT));
    assert_eq(false, cm_wants_update(['channel_post' => $g], CM_BOT));
    assert_eq(false, cm_wants_update(['my_chat_member' => []], CM_BOT));
});

test('модуль выключен или нет таблиц — тишина', function () {
    $log = [];
    cm_handle_update(cm_test_pdo(), cm_cfg(['cm_enabled' => false]), cm_rec($log), ['message' => cm_gmsg(7, 'evil.ru')], CM_NOW);
    cm_handle_update(test_db(), cm_cfg(), cm_rec($log), ['message' => cm_gmsg(7, 'evil.ru')], CM_NOW);
    assert_eq([], $log);
});

test('ссылка от участника удаляется молча, от админа Telegram — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'зайди evil.ru'), $log);
    rt_run($pdo, cm_gmsg(CM_ADMIN, 'зайди evil.ru'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    assert_eq(0, count(cm_calls($log, 'sendMessage')));
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_msgs')->fetchColumn(), 'сообщения записаны');
});

test('без ранга: с префиксом — отказ, без префикса — молча, а фильтры работают', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(7, '!бан @vasya'), $log);
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'бан @vasya evil.ru'), $log);
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'ссылка в «команде» без прав удалена');
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
});

test('модератор без префикса: с целью — команда, без цели — обычная речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(10, 'мут @vasya 10м'), $log);
    assert_eq(CM_NOW + 600, cm_calls($log, 'restrictChatMember')[0][1]['until_date']);
    $n = count(cm_calls($log, 'sendMessage'));
    rt_run($pdo, cm_gmsg(10, 'мут бы ему'), $log);
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('команды уровня 0 без префикса — только ровно команда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, "+правила\nБез спама"), $log);
    rt_run($pdo, cm_gmsg(7, 'правила'), $log);
    assert_eq('Без спама', cm_last_text($log));
    $n = count(cm_calls($log, 'sendMessage'));
    rt_run($pdo, cm_gmsg(7, 'правила нужны всем'), $log);
    assert_eq($n, count(cm_calls($log, 'sendMessage')));
});

test('дк 6 выключает команду даже создателю', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(CM_OWNER, 'дк мут 6'), $log);
    rt_run($pdo, cm_gmsg(CM_OWNER, '!мут @vasya'), $log);
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    assert_eq(0, count(cm_calls($log, 'restrictChatMember')));
});

test('спамер из базы: сообщение удалено, бан', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (66, 'скам', '900', 0)");
    rt_run($pdo, cm_gmsg(66, 'привет'), $log);
    assert_eq([1, 66], [count(cm_calls($log, 'deleteMessage')), cm_calls($log, 'banChatMember')[0][1]['user_id']]);
});

test('от имени группы — не трогаем, от имени чужого канала — только удаляем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $bot = ['id' => 136817688, 'is_bot' => true, 'first_name' => 'Channel'];
    rt_run($pdo, cm_gmsg(0, 'evil.ru', ['from' => $bot, 'sender_chat' => ['id' => CM_CHAT, 'type' => 'supergroup']]), $log);
    assert_eq(0, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(0, 'evil.ru', ['from' => $bot, 'sender_chat' => ['id' => -1009, 'type' => 'channel']]), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    assert_eq(0, count(cm_calls($log, 'banChatMember')) + count(cm_calls($log, 'restrictChatMember')));
});

test('правка: ссылку ловим, команду не выполняем', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(7, 'дописал evil.ru'), $log, [], CM_NOW, 'edited_message');
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(10, 'мут @vasya'), $log, [], CM_NOW, 'edited_message');
    assert_eq(0, count(cm_calls($log, 'restrictChatMember')));
});

test('заметка по #имени, вход и переезд группы', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, "+заметка faq\nОтвет"), $log);
    rt_run($pdo, cm_gmsg(7, '#faq'), $log);
    assert_eq('Ответ', cm_last_text($log));
    rt_run($pdo, cm_gmsg(CM_OWNER, "+приветствие\nПривет, {имя}!"), $log);
    rt_run($pdo, cm_gmsg(7, '', ['new_chat_members' => [['id' => 70, 'first_name' => 'Гость']]]), $log);
    assert_eq('Привет, Гость!', cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, '', ['migrate_to_chat_id' => -1009999]), $log);
    assert_eq(null, cm_chat_row($pdo, CM_CHAT));
    assert_eq('Привет, {имя}!', cm_chat_row($pdo, -1009999)['settings']['welcome']);
});

test('кнопка: Telegram всегда получает ответ, действие — по данным', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Нарушитель']]];
    rt_run($pdo, cm_gmsg(30, 'репорт спам', $reply), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => CM_OWNER, 'first_name' => 'Owner'], 'data' => 'cm:r:1:x',
          'message' => ['message_id' => 9000, 'chat' => $chat, 'text' => 'карточка']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = cm_calls($log, 'answerCallbackQuery');
    assert_eq(['q1', cm_t('report_handled')], [$ans[0][1]['callback_query_id'], $ans[0][1]['text']]);
    assert_eq('rejected', $pdo->query('SELECT status FROM cm_reports')->fetchColumn());
    $q['data'] = 'cm:zzz';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    assert_eq('', cm_calls($log, 'answerCallbackQuery')[1][1]['text']);
});

test('личка: +спамер от сотрудника', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $pdo->exec('INSERT INTO tg_links (user_id, chat_id, linked_at) VALUES (900, 500, 0)');
    $p = ['message_id' => 1, 'chat' => ['id' => 500, 'type' => 'private'], 'from' => ['id' => 500, 'first_name' => 'Мод'], 'text' => '+спамер 123456789'];
    rt_run($pdo, $p, $log);
    assert_eq(true, cm_spam_has($pdo, 123456789));
    assert_eq(500, cm_calls($log, 'sendMessage')[0][1]['chat_id']);
});

test('помощь, кто я, id', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'помощь'), $log);
    assert_eq(cm_t('help'), cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq(0, strpos(cm_last_text($log), "👤 U7\nРанг: 0 — участник\n"), 'кто я — профиль');
    rt_run($pdo, cm_gmsg(7, 'id', ['reply_to_message' => ['message_id' => 5, 'from' => ['id' => 20, 'first_name' => 'Вася']]]), $log);
    assert_eq(true, strpos(cm_last_text($log), 'Вася: 20') !== false);
});

test('таймер выполняет команду от имени автора и заново проверяет ранг', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'], CM_NOW);
    cm_lvl($pdo, 10, 1);
    cm_person($pdo, 20, 'vasya');
    $ins = $pdo->prepare('INSERT INTO cm_timers (chat_id, run_at, body, by_id, created_at, done_at) VALUES (:c, :r, :b, 10, 0, 0)');
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'мут @vasya 10м']);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'Всем привет!']);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW + 999, ':b' => 'рано']);
    assert_eq(2, cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')));
    assert_eq(true, in_array('Всем привет!', cm_texts($log), true));
    assert_eq(0, cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW), 'второй раз не срабатывает');
    cm_lvl($pdo, 10, 0);
    $ins->execute([':c' => CM_CHAT, ':r' => CM_NOW - 1, ':b' => 'мут @vasya 10м']);
    cm_timers_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW);
    assert_eq(1, count(cm_calls($log, 'restrictChatMember')), 'снятый модератор не стреляет');
    assert_eq(cm_t('no_rank', ['need' => 1]), cm_last_text($log));
});

test('cron: таймеры и очередь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_queue_add($pdo, CM_CHAT, 1, CM_NOW - 1);
    assert_eq(['timers' => 0, 'deleted' => 1], cm_cron_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
});

test('вебхук: правки сообщений и право удалять в группах', function () {
    $log = [];
    tg_setup_webhook(cm_rec($log), '123456789:AAHfakeTokenForTestsOnly_abcdefghij');
    $hook = cm_calls($log, 'setWebhook')[0][1];
    assert_eq(true, in_array('edited_message', $hook['allowed_updates'], true));
    assert_eq(5, $hook['max_connections']);
    $rights = cm_calls($log, 'setMyDefaultAdministratorRights');
    assert_eq(true, $rights[1][1]['rights']['can_delete_messages']);
    assert_eq(false, isset($rights[0][1]['rights']['can_delete_messages']), 'каналам не нужно');
});

run_tests();
