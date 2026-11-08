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

test('без ранга: команда с целью — отказ и без точки, а фильтры работают', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'vasya');
    rt_run($pdo, cm_gmsg(7, '!бан @vasya'), $log);
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log));
    rt_run($pdo, cm_gmsg(7, 'бан @vasya evil.ru'), $log);
    assert_eq(0, count(cm_calls($log, 'banChatMember')));
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'ссылка в «команде» без прав удалена');
    assert_eq(2, count(cm_calls($log, 'sendMessage')), 'без точки — тот же отказ');
    rt_run($pdo, cm_gmsg(7, '!бан @vasya evil.ru'), $log);
    assert_eq(2, count(cm_calls($log, 'deleteMessage')), 'и с префиксом ссылка мимо фильтра не проходит');
    rt_run($pdo, cm_gmsg(7, 'бан', ['reply_to_message' => ['message_id' => 5, 'from' => ['id' => 20, 'first_name' => 'Вася']]]), $log);
    assert_eq(cm_t('no_rank', ['need' => 3]), cm_last_text($log), 'ответом без точки — отказ, а не тишина');
    $n = count(cm_calls($log, 'sendMessage'));
    rt_run($pdo, cm_gmsg(7, 'бан бы ему'), $log);
    assert_eq($n, count(cm_calls($log, 'sendMessage')), 'без цели — обычная речь');
});

test('ответом без точки: бан модератора и РП участника', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 10, 3);
    $reply = ['reply_to_message' => ['message_id' => 5, 'from' => ['id' => 20, 'is_bot' => false, 'first_name' => 'Вася']]];
    rt_run($pdo, cm_gmsg(10, 'бан', $reply), $log);
    assert_eq(1, count(cm_calls($log, 'banChatMember')));
    $p = cm_calls($log, 'sendMessage')[0][1];
    assert_eq('⛔ Вася: бан навсегда.', $p['text']);
    assert_eq([['type' => 'text_mention', 'offset' => 2, 'length' => 4, 'user' => ['id' => 20, 'is_bot' => false, 'first_name' => 'Вася']]],
        $p['entities'], 'ник — ссылка на профиль');
    rt_run($pdo, cm_gmsg(7, 'обнять', $reply), $log);
    $p = cm_calls($log, 'sendMessage')[1][1];
    assert_eq('🤗 U7 обнял(а) Вася', $p['text']);
    assert_eq([7, 20], array_map(function ($e) { return $e['user']['id']; }, $p['entities']), 'оба ника — ссылки');
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
    assert_eq(cm_plain(cm_help_text()), cm_last_text($log));
    $p = cm_calls($log, 'sendMessage')[0][1];
    assert_eq(0, strpos($p['text'], "🤖 Чат-менеджер Maknemy\nКоманды пишутся прямо в чат — точка, ! и / не нужны."));
    $types = array_count_values(array_column($p['entities'], 'type'));
    assert_eq(1, $types['expandable_blockquote'] ?? 0, 'разделы — под раскрывающейся цитатой');
    assert_eq(1, $types['text_link'] ?? 0, 'ссылка на инструкцию');
    assert_eq(true, substr($p['text'], -strlen('maknemy.com/bot')) === 'maknemy.com/bot');
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
    assert_eq(['timers' => 0, 'deleted' => 1, 'games' => 0], cm_cron_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
});

test('статистика: сообщение и команда считаются, правка и спамер — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'привет'), $log);
    rt_run($pdo, cm_gmsg(7, 'помощь'), $log);
    rt_run($pdo, cm_gmsg(7, 'привет!'), $log, [], CM_NOW, 'edited_message');
    $pdo->exec("INSERT INTO cm_spam (user_id, reason, added_by, added_at) VALUES (66, 'скам', '900', 0)");
    rt_run($pdo, cm_gmsg(66, 'купи'), $log);
    assert_eq(2, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_NOW));
    assert_eq(0, cm_stats_msgs($pdo, CM_CHAT, 66, 0, CM_NOW));
    assert_eq(true, strpos(cm_plain(cm_help_text()), 'стата') !== false, 'помощь знает этап 2');
});

test('ответ «спасибо» — голос: раньше команд и фильтров', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(CM_OWNER, 'фильтр мат вкл'), $log);
    rt_run($pdo, cm_gmsg(CM_OWNER, '+слово спасибо'), $log);
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    rt_run($pdo, cm_gmsg(7, 'спасибо', $reply), $log);
    assert_eq(0, count(cm_calls($log, 'deleteMessage')), 'до фильтра мата не дошло');
    assert_eq('👍 U7 → Петя: +1 (репутация 1)', cm_last_text($log));
    assert_eq(1, cm_stats_msgs($pdo, CM_CHAT, 7, 0, CM_NOW), 'голос — тоже сообщение');
    rt_run($pdo, cm_gmsg(8, 'спасибо', $reply), $log, [], CM_NOW, 'edited_message');
    assert_eq(1, cm_rep_score($pdo, CM_CHAT, 20), 'правка — не голос');
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'правку видят фильтры');
    rt_run($pdo, cm_gmsg(7, 'спасибо огромное', $reply), $log);
    assert_eq(2, count(cm_calls($log, 'deleteMessage')), 'не голос — фильтры как обычно');
});

test('кто я — профиль, и само сообщение уже посчитано', function () {
    $pdo = cm_test_pdo();
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 1 (сегодня 1), место 1\nВ чате с: 21.09.2026\nРепутация: 0\nВарны: 0/3",
        cm_last_text($log));
});

test('без таблиц этапа 2: модерация как раньше', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_stats');
    $log = [];
    rt_run($pdo, cm_gmsg(7, 'зайди evil.ru'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')));
    rt_run($pdo, cm_gmsg(7, '+', ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]]), $log);
    rt_run($pdo, cm_gmsg(7, 'кто я'), $log);
    assert_eq(['Ваш ранг: 0 — участник. Варны: 0/3.'], cm_texts($log));
});

test('кнопка приглашения этапа 3 идёт через вебхук', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    rt_run($pdo, cm_gmsg(7, 'кубы @petya'), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => 30, 'first_name' => 'Чужой'], 'data' => 'cm:p:1:y',
          'message' => ['message_id' => cm_offer_get($pdo, 1)['msg_id'], 'chat' => $chat, 'text' => 'приглашение']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $q['from'] = ['id' => 20, 'first_name' => 'Петя'];
    $q['data'] = 'cm:p:1:n';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = array_map(function ($c) { return $c[1]['text']; }, cm_calls($log, 'answerCallbackQuery'));
    assert_eq([cm_t('not_yours'), 'Петя отказывается.'], $ans);
    assert_eq('no', cm_offer_get($pdo, 1)['status']);
});

test('кнопка хода дуэли идёт через вебхук', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    rt_run($pdo, cm_gmsg(7, 'дуэль @petya'), $log);
    $chat = ['id' => CM_CHAT, 'type' => 'supergroup', 'title' => 'Тестовый чат'];
    $q = ['id' => 'q1', 'from' => ['id' => 20, 'first_name' => 'Петя'], 'data' => 'cm:p:1:y',
          'message' => ['message_id' => cm_offer_get($pdo, 1)['msg_id'], 'chat' => $chat, 'text' => 'вызов']];
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $q['from'] = ['id' => 30, 'first_name' => 'Чужой'];
    $q['data'] = 'cm:d:1:s';
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['callback_query' => $q], CM_NOW);
    $ans = array_map(function ($c) { return $c[1]['text']; }, cm_calls($log, 'answerCallbackQuery'));
    assert_eq([cm_t('offer_accepted'), cm_t('not_yours')], $ans);
    assert_eq('on', cm_duel_get($pdo, 1)['status']);
});

test('«кто» без префикса — обычная речь и фильтры, «!кто» — ответ; помощь знает этап 3', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'фильтр мат вкл');
    rt_run($pdo, cm_gmsg(7, 'кто знает, сука, где ключи'), $log);
    assert_eq(1, count(cm_calls($log, 'deleteMessage')), 'не команда — сработал фильтр мата');
    rt_run($pdo, cm_gmsg(7, '!кто дежурит?'), $log);
    assert_eq('🎯 Кто дежурит? Думаю, U7.', cm_last_text($log));
    foreach (['дуэль', 'кубы', 'обнять', 'встречаться', 'вернуть брак', '+клан', 'созвать клан'] as $w) {
        assert_eq(true, strpos(cm_plain(cm_help_text()), $w) !== false, $w);
    }
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
