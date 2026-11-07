<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Репорты: api/lib/cm/reports.php.

function rp_reply(): array {
    return ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Нарушитель']]];
}

test('репорт: только ответом, карточка с кнопками в той же группе, не чаще раза в 5 минут', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 30, '', rp_reply());
    cm_do($ctx, 'репорт спам в лс');
    $card = cm_calls($log, 'sendMessage')[0][1];
    assert_eq(CM_CHAT, $card['chat_id']);
    assert_eq(true, strpos($card['text'], "На: Нарушитель\nПричина: спам в лс") !== false);
    assert_eq(true, strpos($card['text'], 'https://t.me/c/1234567890/70') !== false);
    assert_eq('cm:r:1:m', $card['reply_markup']['inline_keyboard'][0][1]['callback_data']);
    assert_eq(cm_t('report_sent'), cm_last_text($log));
    cm_do($ctx, 'репорт ещё');
    assert_eq(cm_t('report_wait'), cm_last_text($log));
    $plain = cm_ctx_for($pdo, $log, 31);
    cm_do($plain, '!репорт');
    assert_eq(cm_t('report_need_reply'), cm_last_text($log));
    $n = count($log);
    cm_do($plain, 'репорт');
    assert_eq($n, count($log), 'без префикса и без ответа — молча');
});

test('на модератора не жалуются', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_lvl($pdo, 20, 1);
    $ctx = cm_ctx_for($pdo, $log, 30, '', rp_reply());
    cm_do($ctx, 'репорт он плохой');
    assert_eq(cm_t('report_staff'), cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_reports')->fetchColumn());
});

test('кнопки: участник не может, модератор мутит, повторно — закрыто', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт спам');
    $card = cm_calls($log, 'sendMessage')[0][1];
    $q = ['id' => 'q', 'from' => ['id' => 40], 'data' => 'cm:r:1:m',
          'message' => ['message_id' => 9000, 'chat' => ['id' => CM_CHAT], 'text' => $card['text']]];
    assert_eq(cm_t('no_rank', ['need' => 2]), cm_report_callback(cm_ctx_for($pdo, $log, 41), $q, 1, 'm'));
    cm_lvl($pdo, 40, 2);
    $mod = cm_ctx_for($pdo, $log, 40);
    assert_eq(cm_t('report_handled'), cm_report_callback($mod, $q, 1, 'm'));
    $r = cm_calls($log, 'restrictChatMember')[0][1];
    assert_eq([20, CM_NOW + 3600], [$r['user_id'], $r['until_date']]);
    $edit = cm_calls($log, 'editMessageText')[0][1];
    assert_eq(9000, $edit['message_id']);
    assert_eq(true, strpos($edit['text'], 'мут 1 ч') !== false);
    assert_eq('done', $pdo->query('SELECT status FROM cm_reports')->fetchColumn());
    assert_eq(cm_t('report_closed'), cm_report_callback($mod, $q, 1, 'b'));
});

test('жалобы в отдельный чат админов по одноразовому коду', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты в чат');
    $code = cm_chat_row($pdo, CM_CHAT)['settings']['report_code']['code'];
    assert_eq(1, preg_match('~^\d{6}$~', $code));
    $admChat = cm_chat_ensure($pdo, ['id' => -3000, 'type' => 'supergroup', 'title' => 'Админы'], CM_NOW);
    $msg = ['message_id' => 5, 'chat' => ['id' => -3000, 'type' => 'supergroup'], 'from' => ['id' => 99, 'first_name' => 'Чужой'], 'text' => ''];
    cm_do(cm_ctx($pdo, cm_cfg(), cm_rec($log), $admChat, $msg, CM_NOW), 'репорты сюда ' . $code);
    assert_eq(cm_t('report_bad_code'), cm_last_text($log), 'код работает только у получившего');
    $msg['from'] = ['id' => CM_OWNER, 'first_name' => 'Owner'];
    cm_do(cm_ctx($pdo, cm_cfg(), cm_rec($log), $admChat, $msg, CM_NOW), 'репорты сюда ' . $code);
    assert_eq(cm_t('report_bound', ['chat' => 'Тестовый чат']), cm_last_text($log));
    assert_eq(-3000, cm_chat_row($pdo, CM_CHAT)['report_chat_id']);
    assert_eq(null, cm_chat_row($pdo, CM_CHAT)['settings']['report_code'], 'код одноразовый');
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт спам');
    assert_eq(-3000, cm_calls($log, 'sendMessage')[0][1]['chat_id']);
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты здесь');
    assert_eq(0, cm_chat_row($pdo, CM_CHAT)['report_chat_id']);
});

test('список открытых жалоб', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 30, '', rp_reply()), 'репорт раз');
    cm_do(cm_ctx_for($pdo, $log, 31, '', rp_reply()), 'репорт два');
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'репорты');
    $t = cm_last_text($log);
    assert_eq(0, strpos($t, cm_t('reports_head')));
    assert_eq(true, strpos($t, '#1') !== false && strpos($t, '#2') !== false && strpos($t, 'два') !== false);
});

run_tests();
