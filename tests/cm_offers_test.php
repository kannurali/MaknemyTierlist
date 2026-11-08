<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Приглашения с кнопками (этап 3): api/lib/cm/offers.php. Вид — кубы
// (dice.php): у него нет своих условий, поэтому видно само приглашение.

function of_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 30, 'user30', 'U30');
}

function of_last(PDO $pdo): array {
    return cm_offer_get($pdo, (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn());
}

test('приглашение: кнопки «Принять» и «Отказаться», сообщение постоянное', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $sent = cm_calls($log, 'sendMessage')[0][1];
    assert_eq('🎲 Петя, U7 предлагает бросить кубы!', $sent['text']);
    assert_eq([[['text' => 'Принять', 'callback_data' => 'cm:p:1:y'], ['text' => 'Отказаться', 'callback_data' => 'cm:p:1:n']]],
        $sent['reply_markup']['inline_keyboard']);
    $o = of_last($pdo);
    assert_eq(['dice', 7, 20, 'open', CM_NOW + 300], [$o['kind'], $o['from_id'], $o['to_id'], $o['status'], $o['expires_at']]);
    assert_eq(true, $o['msg_id'] > 5000, 'запомнили сообщение с кнопками');
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
});

test('чужое нажатие — «не для вас», приглашение ждёт', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 30, 'cm:p:1:y'));
    assert_eq(cm_t('not_yours'), cm_press($pdo, $log, 7, 'cm:p:1:n'), 'и автору тоже');
    assert_eq('open', of_last($pdo)['status']);
    assert_eq([], cm_calls($log, 'editMessageText'));
    assert_eq('', cm_press($pdo, $log, 20, 'cm:p:1:z'), 'неизвестная буква');
});

test('отказ: сообщение правится без кнопок, повтор — «уже неактуально»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    $mid = of_last($pdo)['msg_id'];
    assert_eq('Петя отказывается.', cm_press($pdo, $log, 20, 'cm:p:1:n'));
    $e = cm_calls($log, 'editMessageText')[0][1];
    assert_eq([$mid, 'Петя отказывается.', false], [$e['message_id'], $e['text'], isset($e['reply_markup'])]);
    assert_eq('no', of_last($pdo)['status']);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y'));
    assert_eq(1, count(cm_calls($log, 'editMessageText')), 'устаревшая кнопка — без сообщений в чат');
    assert_eq(1, count(cm_calls($log, 'sendMessage')));
});

test('срок: через 5 минут кнопка неактуальна, cron закрывает остальные', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y', [], CM_NOW + 300));
    assert_eq('gone', cm_offer_get($pdo, 1)['status']);
    assert_eq(cm_t('offer_expired'), cm_calls($log, 'editMessageText')[0][1]['text']);
    cm_do(cm_ctx_for($pdo, $log, 21), 'кубы @petya');
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 299));
    assert_eq(1, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 300));
    assert_eq('gone', cm_offer_get($pdo, 2)['status']);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([2, cm_offer_get($pdo, 2)['msg_id'], cm_t('offer_expired')], [count($e), $e[1][1]['message_id'], $e[1][1]['text']]);
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW + 400), 'второй раз не трогает');
});

test('новое приглашение того же вида закрывает старое, другого вида — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    cm_chat_ensure($pdo, ['id' => CM_CHAT, 'title' => 'Тестовый чат'], CM_NOW);
    $pdo->exec("INSERT INTO cm_offers (chat_id, kind, from_id, to_id, ref_id, msg_id, expires_at, status)
        VALUES (" . CM_CHAT . ", 'duel', 7, 20, 0, 77, " . (CM_NOW + 300) . ", 'open')");
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @kolya');
    $st = array_map(function ($id) use ($pdo) { return cm_offer_get($pdo, $id)['status']; }, [1, 2, 3]);
    assert_eq(['open', 'gone', 'open'], $st);
    $e = cm_calls($log, 'editMessageText');
    assert_eq([1, cm_offer_get($pdo, 2)['msg_id'], cm_t('offer_replaced')], [count($e), $e[0][1]['message_id'], $e[0][1]['text']]);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:2:y'));
});

test('бюджет сообщений кончился — приглашения нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    of_people($pdo);
    $ctx = cm_ctx_for($pdo, $log, 7);
    $pdo->exec('UPDATE cm_chats SET sent_window = ' . intdiv(CM_NOW, 60) . ', sent_count = 15');
    cm_do($ctx, 'кубы @petya');
    assert_eq([], cm_calls($log, 'sendMessage'));
    assert_eq('gone', of_last($pdo)['status']);
});

test('нет таблиц этапа 3: кнопка неактуальна, cron молчит, команда — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    $log = [];
    of_people($pdo);
    assert_eq(cm_t('offer_stale'), cm_press($pdo, $log, 20, 'cm:p:1:y'));
    assert_eq(0, cm_games_tick($pdo, cm_cfg(), cm_rec($log), CM_NOW));
    cm_do(cm_ctx_for($pdo, $log, 7), 'кубы @petya');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
