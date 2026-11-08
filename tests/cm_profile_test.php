<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Анкета и профиль (этап 2): api/lib/cm/profile.php, «кто я» в router.php.

test('анкета: пол, город, о себе; одна на все чаты', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+пол Ж');
    assert_eq(cm_t('profile_saved'), cm_last_text($log));
    cm_do($ctx, '+город  Нижний   Новгород ');
    cm_do($ctx, "+о себе Люблю трейды\nи Blox Fruits");
    assert_eq(['gender' => 'f', 'city' => 'Нижний Новгород', 'birthday' => '', 'about' => 'Люблю трейды и Blox Fruits'],
        cm_profile_get($pdo, 7));
    cm_do($ctx, '+пол мужской');
    assert_eq('m', cm_profile_get($pdo, 7)['gender']);
    cm_do($ctx, '+пол кот');
    assert_eq(cm_t('profile_gender_usage'), cm_last_text($log));
    cm_do($ctx, '-город');
    assert_eq(cm_t('profile_cleared'), cm_last_text($log));
    assert_eq('', cm_profile_get($pdo, 7)['city']);
    assert_eq(['gender' => '', 'city' => '', 'birthday' => '', 'about' => ''], cm_profile_get($pdo, 8), 'пустая анкета');
});

test('анкету меняет только хозяин: ответ на чужое сообщение не цель', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply), '+город Казань');
    assert_eq('Казань', cm_profile_get($pdo, 7)['city']);
    assert_eq('', cm_profile_get($pdo, 20)['city']);
});

test('день рождения: проверка даты, 29.02, год 1900 — нынешний', function () {
    assert_eq('25.12', cm_birthday('25.12', CM_NOW));
    assert_eq('05.01.2005', cm_birthday('5.1.2005', CM_NOW));
    assert_eq('29.02', cm_birthday('29.02', CM_NOW), 'без года 29 февраля можно');
    assert_eq('29.02.2004', cm_birthday('29.02.2004', CM_NOW));
    assert_eq('', cm_birthday('29.02.2005', CM_NOW));
    assert_eq('', cm_birthday('31.04', CM_NOW));
    assert_eq('', cm_birthday('01.01.1899', CM_NOW));
    assert_eq('01.01.2026', cm_birthday('01.01.2026', CM_NOW));
    assert_eq('', cm_birthday('01.01.2027', CM_NOW));
    assert_eq('', cm_birthday('завтра', CM_NOW));
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), '+др 30.02');
    assert_eq(cm_t('profile_birthday_usage'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), '+др 29.02');
    assert_eq('29.02', cm_profile_get($pdo, 7)['birthday']);
});

test('город и о себе: длина, без ссылок и мата', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7);
    cm_do($ctx, '+город ' . str_repeat('я', 65));
    assert_eq(cm_t('profile_city_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе ' . str_repeat('я', 201));
    assert_eq(cm_t('profile_about_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе');
    assert_eq(cm_t('profile_about_usage'), cm_last_text($log));
    cm_do($ctx, '+о себе заходи на evil.ru');
    assert_eq(cm_t('profile_text_bad'), cm_last_text($log));
    cm_do($ctx, '+город сука');
    assert_eq(cm_t('profile_text_bad'), cm_last_text($log));
    cm_do($ctx, '+о себе мой сайт maknemy.com');
    assert_eq('мой сайт maknemy.com', cm_profile_get($pdo, 7)['about'], 'maknemy.com можно');
});

test('профиль: все строки по порядку', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_lvl($pdo, 20, 2);
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    $ins->execute([':c' => CM_CHAT, ':u' => 20, ':d' => 20261107, ':m' => 1219]);
    $ins->execute([':c' => CM_CHAT, ':u' => 20, ':d' => 20261108, ':m' => 15]);
    $ins->execute([':c' => CM_CHAT, ':u' => 30, ':d' => 20261108, ':m' => 5000]);
    $ins->execute([':c' => CM_CHAT, ':u' => 31, ':d' => 20261108, ':m' => 4000]);
    $pdo->exec('INSERT INTO cm_rep (chat_id, user_id, score) VALUES (' . CM_CHAT . ', 20, 15)');
    $now = 1794157200;   // 08.11.2026 20:00 МСК
    $o = cm_ctx_for($pdo, $log, CM_OWNER, '', [], [], $now);
    cm_do($o, '+награда 5 @petya Лучший трейдер');
    cm_do($o, '+награда 2 @petya Помощь новичкам');
    cm_do($o, 'варн @petya флуд');
    $me = cm_ctx_for($pdo, $log, 20, '', [], [], $now);
    cm_do($me, '+пол м');
    cm_do($me, '+город Москва');
    cm_do($me, '+др 25.12');
    cm_do($me, '+о себе Трейжу фрукты');
    $queued = (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn();
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $now), 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 2 — старший модератор\nСообщений: 1 234 (сегодня 15), место 3\nВ чате с: 07.11.2026\n"
        . "Репутация: 15\nНаграды: 🥇 Лучший трейдер, 🏅 Помощь новичкам\nВарны: 1/3\n"
        . "Пол: мужской · Город: Москва · День рождения: 25.12\nО себе: Трейжу фрукты", cm_last_text($log));
    assert_eq($queued, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'профиль постоянный');
});

test('профиль пустого: только ранг, сообщения, репутация, варны', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль');
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
});

test('выключенный раздел прячет свои строки, награды видны всегда', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_person($pdo, 20, 'petya', 'Петя');
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, '+награда 3 @petya Старожил');
    foreach (['статистика', 'репутация', 'анкета', 'награды'] as $s) { cm_do($o, 'дк ' . $s . ' 6'); }
    cm_do(cm_ctx_for($pdo, $log, 20), '+город Москва');
    $ctx = cm_ctx_for($pdo, $log, 7, '', [], []);
    cm_do($ctx, 'профиль @petya');
    assert_eq("👤 Петя\nРанг: 0 — участник\nНаграды: 🥉 Старожил\nВарны: 0/3", cm_last_text($log));
});

test('кто ты: только с целью; бот — отказ', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $reply), 'кто ты');
    assert_eq(0, strpos(cm_last_text($log), "👤 Петя\nРанг: 0"));
    cm_do(cm_ctx_for($pdo, $log, 7), '!кто ты');
    assert_eq(cm_t('need_target'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $bot), 'профиль');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('без префикса: «профиль» и «кто ты» ответом — команды, с лишними словами — речь', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text, $extra), cm_parse($text, CM_BOT));
    };
    assert_eq(true, $run('профиль'));
    assert_eq(true, $run('кто ты', $reply));
    assert_eq(false, $run('кто ты'));
    assert_eq(false, $run('кто ты такой', $reply));
    assert_eq(false, $run('профиль заполни'));
    assert_eq(true, $run('+город Тверь'));
    assert_eq(false, $run('-город срочно'));
});

test('кто я — тот же профиль; без таблиц этапа 2 — как раньше', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cm_do(cm_ctx_for($pdo, $log, 7), 'кто я');
    assert_eq("👤 U7\nРанг: 0 — участник\nСообщений: 0 (сегодня 0)\nРепутация: 0\nВарны: 0/3", cm_last_text($log));
    $pdo->exec('DROP TABLE cm_stats');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кто я');
    assert_eq('Ваш ранг: 0 — участник. Варны: 0/3.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'профиль');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
