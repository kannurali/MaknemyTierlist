<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Заметки (notes.php) и команды таймеров (timers.php).

test('заметка с разметкой: сохранить, вызвать #имя, список, удалить', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $text = "+заметка Трейды\nКак торговать: читай";
    $o = cm_ctx_for($pdo, $log, CM_OWNER, $text, ['entities' => [['type' => 'bold', 'offset' => 16, 'length' => 3]]]);
    cm_do($o, $text);
    assert_eq(cm_t('note_saved', ['name' => 'трейды']), cm_last_text($log));
    $r = cm_ctx_for($pdo, $log, 30, '#трейды');
    assert_eq(true, cm_note_try($r));
    $sent = cm_calls($log, 'sendMessage');
    $last = $sent[count($sent) - 1][1];
    assert_eq(['Как торговать: читай', [['type' => 'bold', 'offset' => 0, 'length' => 3]], $r['msg']['message_id']],
        [$last['text'], $last['entities'], $last['reply_parameters']['message_id']]);
    assert_eq(true, cm_note_try(cm_ctx_for($pdo, $log, 30, '#ТРЕЙДЫ пожалуйста')));
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, '#нет')));
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, 'текст #трейды')));
    cm_do($o, 'заметки');
    assert_eq(cm_t('notes_head') . "\n#трейды", cm_last_text($log));
    cm_do($o, '-заметка трейды');
    assert_eq(false, cm_note_try(cm_ctx_for($pdo, $log, 30, '#трейды')));
    cm_do($o, '-заметка трейды');
    assert_eq(cm_t('note_missing', ['name' => 'трейды']), cm_last_text($log));
});

test('заметки: имя, пустой текст и лимит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, "+заметка плохое имя!\nтекст");
    assert_eq(cm_t('note_usage'), cm_last_text($log));
    cm_do($o, '+заметка пусто');
    assert_eq(cm_t('note_usage'), cm_last_text($log));
    $ins = $pdo->prepare('INSERT INTO cm_notes (chat_id, name, body, by_id, updated_at) VALUES (:c, :n, :b, 1, 0)');
    for ($i = 0; $i < 50; $i++) { $ins->execute([':c' => CM_CHAT, ':n' => 'n' . $i, ':b' => 'x']); }
    cm_do($o, "+заметка новая\nтекст");
    assert_eq(cm_t('notes_full', ['max' => 50]), cm_last_text($log));
    cm_do($o, "+заметка n1\nновый текст");
    assert_eq(cm_t('note_saved', ['name' => 'n1']), cm_last_text($log), 'старую обновить можно и на лимите');
});

test('когда: через, дата по Москве, прошедшая дата — через год, пределы', function () {
    assert_eq(CM_NOW + 7200, cm_parse_when('через 2ч', CM_NOW));
    assert_eq(-1, cm_parse_when('через 30с', CM_NOW));
    assert_eq(-1, cm_parse_when('через навсегда', CM_NOW));
    $d = new DateTime('@' . CM_NOW);
    $d->setTimezone(new DateTimeZone('Europe/Moscow'));
    $t = clone $d;
    $t->modify('+1 day');
    $t->setTime(10, 0);
    assert_eq($t->getTimestamp(), cm_parse_when($t->format('d.m') . ' 10:00', CM_NOW));
    $y = clone $d;
    $y->modify('-1 day');
    $y->setTime(10, 0);
    $y2 = clone $y;
    $y2->modify('+1 year');
    assert_eq($y2->getTimestamp(), cm_parse_when($y->format('d.m') . ' 10:00', CM_NOW));
    assert_eq(-1, cm_parse_when($y->format('d.m.Y') . ' 10:00', CM_NOW), 'прошлое с годом');
    assert_eq(-1, cm_parse_when('31.02 10:00', CM_NOW));
    assert_eq(-1, cm_parse_when('25.12 25:00', CM_NOW));
    assert_eq(-1, cm_parse_when('завтра', CM_NOW));
});

test('таймер: добавить, список, отменить, не больше пяти', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, "таймер через 2ч\nмут @vasya 1ч");
    $row = $pdo->query('SELECT id, run_at, body, by_id, done_at FROM cm_timers')->fetch(PDO::FETCH_ASSOC);
    assert_eq([1, CM_NOW + 7200, 'мут @vasya 1ч', CM_OWNER, 0],
        [(int)$row['id'], (int)$row['run_at'], $row['body'], (int)$row['by_id'], (int)$row['done_at']]);
    assert_eq(cm_t('timer_added', ['id' => 1, 'date' => cm_date(CM_NOW + 7200)]), cm_last_text($log));
    cm_do($o, 'таймер через 2ч');
    assert_eq(cm_t('timer_usage'), cm_last_text($log));
    cm_do($o, 'таймеры');
    assert_eq(cm_t('timers_head') . "\n#1 · " . cm_date(CM_NOW + 7200) . ' · мут @vasya 1ч', cm_last_text($log));
    for ($i = 0; $i < 4; $i++) { cm_do($o, "таймер через 3ч\nтекст $i"); }
    cm_do($o, "таймер через 4ч\nлишний");
    assert_eq(cm_t('timer_full', ['max' => 5]), cm_last_text($log));
    cm_do($o, '-таймер 1');
    assert_eq(CM_NOW, (int)$pdo->query('SELECT done_at FROM cm_timers WHERE id = 1')->fetchColumn());
    cm_do($o, '-таймер #1');
    assert_eq(cm_t('timer_missing', ['id' => 1]), cm_last_text($log));
});

run_tests();
