<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Пары и браки (этап 3): api/lib/cm/love.php.

const LV_DAY = 86400;

function lv_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    cm_person($pdo, 30, 'user30', 'U30');
}

// «Принять» последнее приглашение от имени $who.
function lv_accept(PDO $pdo, array &$log, int $who, int $now = CM_NOW): string {
    $id = (int)$pdo->query('SELECT MAX(id) FROM cm_offers')->fetchColumn();
    return cm_press($pdo, $log, $who, 'cm:p:' . $id . ':y', [], $now);
}

function lv_last_edit(array $log): string {
    $e = cm_calls($log, 'editMessageText');
    return (string)$e[count($e) - 1][1]['text'];
}

function lv_row(PDO $pdo, string $table, int $a, int $b, int $since, int $xp = 0, int $ended = 0): void {
    $xpCols = $table === 'cm_couples' ? ', xp, xp_day, xp_today' : '';
    $xpVals = $table === 'cm_couples' ? ', ' . $xp . ', 0, 0' : '';
    $pdo->exec("INSERT INTO $table (chat_id, a_id, b_id, since$xpCols, ended_at) VALUES (" . CM_CHAT . ", $a, $b, $since$xpVals, $ended)");
}

test('встречаться → «Принять» → пара; «пара» своя и чужая', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @petya');
    assert_eq('💞 Петя, U7 предлагает встречаться. Согласны?', cm_last_text($log));
    $o = cm_offer_get($pdo, 1);
    assert_eq(['couple', CM_NOW + 600], [$o['kind'], $o['expires_at']]);
    assert_eq(cm_t('offer_accepted'), lv_accept($pdo, $log, 20));
    assert_eq('💞 U7 и Петя теперь пара!', lv_last_edit($log));
    $c = cm_couple_of($pdo, CM_CHAT, 20);
    assert_eq([7, 20, CM_NOW, 0], [$c['a_id'], $c['b_id'], $c['since'], $c['xp']]);
    $pdo->exec('UPDATE cm_couples SET xp = 117');
    $later = CM_NOW + 12 * LV_DAY + 100;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $later), 'пара');
    assert_eq('💞 U7 и Петя вместе 12 дней, уровень 3 (опыт 117)', cm_last_text($log));
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $reply, [], $later), 'пара');
    assert_eq('💞 U7 и Петя вместе 12 дней, уровень 3 (опыт 117)', cm_last_text($log), 'чужая — ответом');
    cm_do(cm_ctx_for($pdo, $log, 21), 'пара');
    assert_eq('U21 ни с кем не встречается.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21), '!пара @olya');
    assert_eq('Оля ни с кем не встречается.', cm_last_text($log));
    assert_eq([1, 2, 5], [cm_couple_level(0), cm_couple_level(50), cm_couple_level(249)]);
});

test('одна пара на человека: отказ до приглашения и при «Принять»; себя и ботов нельзя', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @kolya');
    assert_eq('У вас уже есть пара: Петя.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21), 'встречаться @petya');
    assert_eq('У Петя уже есть пара.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_offers')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 21), 'встречаться @olya');
    cm_do(cm_ctx_for($pdo, $log, 30), 'встречаться @olya');
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 22, 'cm:p:2:y'));
    assert_eq(cm_t('couple_busy'), cm_press($pdo, $log, 22, 'cm:p:1:y'), 'Оля уже в паре с U30');
    assert_eq([cm_t('couple_busy'), 'gone'], [lv_last_edit($log), cm_offer_get($pdo, 1)['status']]);
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_couples')->fetchColumn());
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 21, 'first_name' => 'Коля']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $self), 'встречаться');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    $bot = ['reply_to_message' => ['message_id' => 71, 'from' => ['id' => 555, 'is_bot' => true, 'first_name' => 'Бот']]];
    cm_do(cm_ctx_for($pdo, $log, 21, '', $bot), 'встречаться');
    assert_eq(cm_t('target_bot'), cm_last_text($log));
});

test('расстаться: пара закрыта, можно новую', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW - LV_DAY);
    cm_do(cm_ctx_for($pdo, $log, 20), 'расстаться');
    assert_eq('💔 U7 и Петя расстались.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    assert_eq(CM_NOW, (int)$pdo->query('SELECT ended_at FROM cm_couples')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 20), 'расстаться');
    assert_eq(cm_t('couple_none_me'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'встречаться @kolya');
    assert_eq('💞 Коля, U7 предлагает встречаться. Согласны?', cm_last_text($log));
});

test('пары — топ по уровню, расставшихся нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_couples', 21, 22, CM_NOW, 30);
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW, 120);
    lv_row($pdo, 'cm_couples', 30, 31, CM_NOW, 900, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'пары');
    assert_eq("💞 Пары чата:\n1. U7 и Петя — уровень 3\n2. Коля и Оля — уровень 1", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'пары');
    assert_eq(cm_t('couples_empty'), cm_last_text($log));
});

test('брак: предложение, «брак» свой и чужой, развод', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @petya');
    assert_eq('💍 Петя, U7 делает вам предложение. Согласны?', cm_last_text($log));
    assert_eq(['marry', CM_NOW + 600], [cm_offer_get($pdo, 1)['kind'], cm_offer_get($pdo, 1)['expires_at']]);
    lv_accept($pdo, $log, 20);
    assert_eq('💍 U7 и Петя теперь в браке!', lv_last_edit($log));
    $later = CM_NOW + 125 * LV_DAY;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $later), 'брак');
    assert_eq('💍 U7 и Петя в браке 125 дней', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 21, '', [], [], $later), 'брак @petya');
    assert_eq('💍 U7 и Петя в браке 125 дней', cm_last_text($log), 'цель в браке — показать, а не звать');
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_offers')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 21), 'брак');
    assert_eq(cm_t('marry_none_me'), cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @kolya');
    assert_eq('Вы уже в браке с Петя.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], $later), 'развод');
    assert_eq('💔 U7 и Петя развелись. Передумали — «вернуть брак» в течение 3 дней.', cm_last_text($log));
    $m = $pdo->query('SELECT ended_at, ended_by FROM cm_marriages')->fetch(PDO::FETCH_NUM);
    assert_eq([$later, 20], [(int)$m[0], (int)$m[1]]);
    cm_do(cm_ctx_for($pdo, $log, 20), 'развод');
    assert_eq(cm_t('marry_none_me'), cm_last_text($log));
});

test('брак: занятые — отказ при «Принять»; пара и брак друг от друга не зависят', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    cm_do(cm_ctx_for($pdo, $log, 21), 'брак @olya');
    cm_do(cm_ctx_for($pdo, $log, 30), 'брак @olya');
    lv_accept($pdo, $log, 22);
    assert_eq(cm_t('marry_busy'), cm_press($pdo, $log, 22, 'cm:p:1:y'));
    lv_row($pdo, 'cm_couples', 7, 20, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'брак @kolya');
    lv_accept($pdo, $log, 21);
    assert_eq('💍 U7 и Коля теперь в браке!', lv_last_edit($log), 'пара с Петей браку не мешает');
    cm_do(cm_ctx_for($pdo, $log, 7), 'расстаться');
    assert_eq(21, cm_partner(cm_marriage_of($pdo, CM_CHAT, 7), 7), 'расставание не трогает брак');
});

test('вернуть брак: на 3-й день можно, на 4-й — нет; тот же брак с прежней датой', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 100 * LV_DAY);
    cm_do(cm_ctx_for($pdo, $log, 7), 'развод');
    $day3 = CM_NOW + 2 * LV_DAY + 3600;
    cm_do(cm_ctx_for($pdo, $log, 20, '', [], [], $day3), 'вернуть брак');
    assert_eq('💍 U7, U20 предлагает вернуть брак. Согласны?', cm_last_text($log), 'от кого — имя из сообщения');
    $o = cm_offer_get($pdo, 1);
    assert_eq(['remarry', 7, 1], [$o['kind'], $o['to_id'], $o['ref_id']]);
    lv_accept($pdo, $log, 7, $day3);
    assert_eq('💍 U7 и Петя снова в браке!', lv_last_edit($log));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_marriages')->fetchColumn());
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $day3), 'брак');
    assert_eq('💍 U7 и Петя в браке 102 дня', cm_last_text($log), 'дата — прежняя');
    $t2 = CM_NOW + 10 * LV_DAY;
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2), 'развод');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2 + 3 * LV_DAY + 3600), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), cm_last_text($log), '4-й день — поздно');
    cm_do(cm_ctx_for($pdo, $log, 7, '', [], [], $t2 + 3 * LV_DAY - 120), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), lv_accept($pdo, $log, 20, $t2 + 3 * LV_DAY + 60), '«Принять» тоже проверяет 3 дня');
    assert_eq(['gone', cm_t('remarry_none')], [cm_offer_get($pdo, 2)['status'], lv_last_edit($log)]);
    cm_do(cm_ctx_for($pdo, $log, 21), 'вернуть брак');
    assert_eq(cm_t('remarry_none'), cm_last_text($log), 'не разводился — нечего вернуть');
});

test('вернуть брак: бывший уже в другом браке, сам в браке', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 10 * LV_DAY, 0, CM_NOW - 3600);
    lv_row($pdo, 'cm_marriages', 20, 22, CM_NOW - 60);
    cm_do(cm_ctx_for($pdo, $log, 7), 'вернуть брак');
    assert_eq('Петя уже в другом браке.', cm_last_text($log));
    cm_do(cm_ctx_for($pdo, $log, 20), 'вернуть брак');
    assert_eq('Вы уже в браке с Оля.', cm_last_text($log));
});

test('браки — топ по длительности, распавшихся нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    lv_row($pdo, 'cm_marriages', 21, 22, CM_NOW - 10 * LV_DAY);
    lv_row($pdo, 'cm_marriages', 7, 20, CM_NOW - 300 * LV_DAY);
    lv_row($pdo, 'cm_marriages', 30, 31, CM_NOW - 999 * LV_DAY, 0, CM_NOW);
    cm_do(cm_ctx_for($pdo, $log, 7), 'браки');
    assert_eq("💍 Самые долгие браки:\n1. U7 и Петя — 300 дней\n2. Коля и Оля — 10 дней", cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'браки');
    assert_eq(cm_t('marriages_empty'), cm_last_text($log));
});

test('без префикса — только ровно команда; дк отношения 6; без таблиц — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    lv_people($pdo);
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    foreach (['встречаться @petya', 'пара', 'пары', 'брак @kolya', 'брак', 'браки', 'расстаться', 'развод', 'вернуть брак'] as $t) {
        assert_eq(true, $run($t), $t);
    }
    foreach (['встречаться бы', 'пара минут', 'брак это серьёзно', 'развод мостов', 'браки бывают разные'] as $t) {
        assert_eq(false, $run($t), $t);
    }
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк отношения 6');
    assert_eq(false, $run('!пара'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'пара');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

run_tests();
