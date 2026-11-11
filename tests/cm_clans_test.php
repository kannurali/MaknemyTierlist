<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Кланы (этап 3): api/lib/cm/clans.php.

function cl_people(PDO $pdo): void {
    cm_person($pdo, 7, 'user7', 'U7');
    cm_person($pdo, 20, 'petya', 'Петя');
    cm_person($pdo, 21, 'kolya', 'Коля');
    cm_person($pdo, 22, 'olya', 'Оля');
    cm_person($pdo, 30, 'user30', 'U30');
}

function cl_do(PDO $pdo, array &$log, int $actor, string $text, int $now = CM_NOW): void {
    cm_do(cm_ctx_for($pdo, $log, $actor, '', [], [], $now), $text);
}

function cl_members(PDO $pdo, int $clanId): array {
    $st = $pdo->prepare('SELECT user_id FROM cm_clan_members WHERE clan_id = :k ORDER BY user_id');
    $st->execute([':k' => $clanId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

test('название: 2–32 символа, буквы, цифры, пробел, дефис', function () {
    assert_eq('Волки Севера', cm_clan_name('  Волки   Севера '));
    assert_eq('Team-42', cm_clan_name('Team-42'));
    foreach (['А', str_repeat('я', 33), 'Волки!', '-Волки', 'Волки-', 'evil.ru', ''] as $bad) {
        assert_eq('', cm_clan_name($bad), $bad);
    }
});

test('+клан: глава — создатель, имя уникально без учёта регистра и ё', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан  Волки   Севера ');
    assert_eq('🛡 Клан «Волки Севера» создан. Глава — U7.', cm_last_text($log));
    assert_eq(0, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'не удаляется');
    $c = cm_clan_of($pdo, CM_CHAT, 7);
    assert_eq(['Волки Севера', 'волки севера', 7, 1], [$c['name'], $c['name_key'], $c['owner_id'], $c['is_open']]);
    cl_do($pdo, $log, 20, '+клан ВОЛКИ СЕВЕРА');
    assert_eq('Клан «ВОЛКИ СЕВЕРА» уже есть.', cm_last_text($log));
    cl_do($pdo, $log, 20, '+клан Ёжики');
    cl_do($pdo, $log, 21, '+клан ежики');
    assert_eq('Клан «ежики» уже есть.', cm_last_text($log));
    cl_do($pdo, $log, 7, '+клан Другой');
    assert_eq('Вы уже в клане «Волки Севера».', cm_last_text($log));
    cl_do($pdo, $log, 21, '+клан evil.ru');
    assert_eq(cm_t('clan_usage'), cm_last_text($log));
    cl_do($pdo, $log, 21, '+клан сука');
    assert_eq(cm_t('clan_text_bad'), cm_last_text($log));
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM cm_clans')->fetchColumn());
});

test('вступить: открытый — сразу, закрытый — нужно приглашение; «в клан» можно писать', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('🛡 U20 теперь в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 21, 'вступить в клан волки');
    assert_eq('🛡 U21 теперь в клане «Волки».', cm_last_text($log));
    assert_eq([7, 20, 21], cl_members($pdo, 1));
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('Вы уже в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан закрыт');
    assert_eq('Клан «Волки» закрыт: вход только по приглашению.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить Волки');
    assert_eq('Клан «Волки» закрыт: нужно приглашение главы.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан открыт');
    assert_eq('Клан «Волки» открыт: вступить может любой.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить Медведи');
    assert_eq('Клана «Медведи» нет.', cm_last_text($log));
    cl_do($pdo, $log, 22, 'вступить');
    assert_eq(cm_t('clan_join_usage'), cm_last_text($log));
    cl_do($pdo, $log, 21, 'клан закрыт');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 30, '+клан Клан Волков');
    cl_do($pdo, $log, 22, 'вступить Клан Волков');
    assert_eq('🛡 U22 теперь в клане «Клан Волков».', cm_last_text($log), 'имя, начатое со слова «клан»');
});

test('пригласить: только глава; приглашение → вступление; занятых и полных — нет', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 7, 'клан закрыт');
    cl_do($pdo, $log, 30, 'пригласить @olya');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @olya');
    assert_eq('🛡 Оля, U7 зовёт вас в клан «Волки».', cm_last_text($log));
    $o = cm_offer_get($pdo, 1);
    assert_eq(['clan', 22, 1, CM_NOW + 300], [$o['kind'], $o['to_id'], $o['ref_id'], $o['expires_at']]);
    assert_eq(cm_t('offer_accepted'), cm_press($pdo, $log, 22, 'cm:p:1:y'));
    $e = cm_calls($log, 'editMessageText');
    assert_eq('🛡 Оля теперь в клане «Волки».', $e[count($e) - 1][1]['text']);
    assert_eq([7, 22], cl_members($pdo, 1));
    cl_do($pdo, $log, 22, 'пригласить @petya');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @olya');
    assert_eq('Оля уже в клане.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'пригласить @kolya');
    cl_do($pdo, $log, 21, '+клан Медведи');
    assert_eq(cm_t('clan_busy'), cm_press($pdo, $log, 21, 'cm:p:2:y'), 'пока ждал — вступил в другой');
    $ins = $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, 1, 0)');
    for ($u = 1000; $u < 1098; $u++) { $ins->execute([':c' => CM_CHAT, ':u' => $u]); }
    assert_eq(100, cm_clan_size($pdo, 1));
    cl_do($pdo, $log, 7, 'пригласить @petya');
    assert_eq('В клане «Волки» уже 100 участников.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'клан открыт');
    cl_do($pdo, $log, 20, 'вступить Волки');
    assert_eq('В клане «Волки» уже 100 участников.', cm_last_text($log));
});

test('исключить и покинуть клан; глава выйти не может', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, 'вступить Волки');
    cl_do($pdo, $log, 7, 'исключить @petya');
    assert_eq('Петя больше не в клане «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 7, 'исключить @petya');
    assert_eq('Петя не в вашем клане.', cm_last_text($log));
    cl_do($pdo, $log, 21, 'исключить @user7');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    $self = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 7, 'first_name' => 'U7']]];
    cm_do(cm_ctx_for($pdo, $log, 7, '', $self), 'исключить');
    assert_eq(cm_t('target_self'), cm_last_text($log));
    cl_do($pdo, $log, 21, 'покинуть клан');
    assert_eq('U21 покидает клан «Волки».', cm_last_text($log));
    cl_do($pdo, $log, 21, 'покинуть клан');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 7, 'покинуть клан');
    assert_eq(cm_t('clan_owner_stays'), cm_last_text($log));
    assert_eq([7], cl_members($pdo, 1));
});

test('клан и кланы: карточка, описание, активность за неделю, топ', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, '+клан Медведи');
    $ins = $pdo->prepare('INSERT INTO cm_stats (chat_id, user_id, day, msgs) VALUES (:c, :u, :d, :m)');
    foreach ([[7, 20260921, 100], [20, 20260915, 34], [20, 20260914, 1000], [21, 20260921, 50], [30, 20260921, 999]] as $r) {
        $ins->execute([':c' => CM_CHAT, ':u' => $r[0], ':d' => $r[1], ':m' => $r[2]]);
    }
    cm_do(cm_ctx_for($pdo, $log, 7), "клан описание Лучшие   трейдеры\nи друзья");
    assert_eq(cm_t('clan_about_done'), cm_last_text($log));
    cl_do($pdo, $log, 30, '!клан волки');
    $card = "🛡 Клан «Волки»\nГлава: U7\nУчастников: 2/100 · открыт\nАктивность за неделю: 134\nО клане: Лучшие трейдеры и друзья";
    assert_eq($card, cm_last_text($log));
    cl_do($pdo, $log, 20, 'клан');
    assert_eq($card, cm_last_text($log), 'свой клан');
    cl_do($pdo, $log, 30, 'клан');
    assert_eq(cm_t('clan_none_me'), cm_last_text($log));
    cl_do($pdo, $log, 30, '!клан Тигры');
    assert_eq('Клана «Тигры» нет.', cm_last_text($log));
    cl_do($pdo, $log, 30, 'кланы');
    assert_eq("🛡 Кланы чата (активность за неделю):\n1. Волки — 134 · 2 чел.\n2. Медведи — 50 · 1 чел.", cm_last_text($log));
    cl_do($pdo, $log, 21, 'клан закрыт');
    cl_do($pdo, $log, 30, '!клан Медведи');
    assert_eq("🛡 Клан «Медведи»\nГлава: Коля\nУчастников: 1/100 · вход по приглашению\nАктивность за неделю: 50", cm_last_text($log));
    foreach (['клан описание', 'клан описание ' . str_repeat('я', 201)] as $bad) {
        cl_do($pdo, $log, 7, $bad);
        assert_eq(cm_t('clan_about_usage'), cm_last_text($log), $bad);
    }
    cl_do($pdo, $log, 7, 'клан описание заходите на evil.ru');
    assert_eq(cm_t('clan_text_bad'), cm_last_text($log));
    cl_do($pdo, $log, 20, 'клан описание моё');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cm_do(cm_ctx_for(cm_test_pdo(), $log, 7), 'кланы');
    assert_eq(cm_t('clans_empty'), cm_last_text($log));
});

test('созвать клан: скрытые упоминания, до 30 человек, ушедших нет, раз в 10 минут', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, 'вступить Волки', CM_NOW + 1);
    cl_do($pdo, $log, 22, 'вступить Волки', CM_NOW + 2);
    cm_member_set($pdo, CM_CHAT, 22, ['left_at' => CM_NOW]);
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 100);
    $m = cm_calls($log, 'sendMessage');
    $p = $m[count($m) - 1][1];
    $head = "📣 U7 созывает клан «Волки»:\n";
    assert_eq($head . 'Петя, Коля', $p['text']);
    assert_eq(29, cm_utf16_len($head), '📣 — две единицы UTF-16');
    assert_eq([['type' => 'text_mention', 'offset' => 3, 'length' => 2, 'user' => ['id' => 7, 'is_bot' => false, 'first_name' => 'U7']],
               ['type' => 'text_mention', 'offset' => 29, 'length' => 4, 'user' => ['id' => 20, 'is_bot' => false, 'first_name' => 'Петя']],
               ['type' => 'text_mention', 'offset' => 35, 'length' => 4, 'user' => ['id' => 21, 'is_bot' => false, 'first_name' => 'Коля']]],
        $p['entities'], 'глава и участники — ники-ссылки');
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 400);
    assert_eq('Созывать клан можно раз в 10 минут. Следующий раз — через 5 мин.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 699);
    assert_eq('Созывать клан можно раз в 10 минут. Следующий раз — через 1 мин.', cm_last_text($log));
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 700);
    assert_eq($head . 'Петя, Коля', cm_last_text($log), 'через 10 минут — снова можно');
    cl_do($pdo, $log, 20, 'созвать клан', CM_NOW + 2000);
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    $ins = $pdo->prepare('INSERT INTO cm_clan_members (chat_id, user_id, clan_id, joined_at) VALUES (:c, :u, 1, :j)');
    for ($u = 1000; $u < 1040; $u++) { $ins->execute([':c' => CM_CHAT, ':u' => $u, ':j' => CM_NOW + 10]); }
    cl_do($pdo, $log, 7, 'созвать клан', CM_NOW + 3000);
    $m = cm_calls($log, 'sendMessage');
    assert_eq(31, count($m[count($m) - 1][1]['entities']), 'глава и 30 участников');
    cl_do($pdo, $log, 30, '+клан Одиночки');
    cl_do($pdo, $log, 30, 'созвать клан');
    assert_eq(cm_t('clan_call_empty'), cm_last_text($log));
});

test('-клан: глава распускает, участники выходят, имя свободно; приглашение в него неактуально', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 7, 'пригласить @kolya');
    cl_do($pdo, $log, 20, '-клан');
    assert_eq(cm_t('clan_owner_only'), cm_last_text($log));
    cl_do($pdo, $log, 7, '-клан');
    assert_eq('💥 Клан «Волки» распущен.', cm_last_text($log));
    assert_eq([0, 0], [(int)$pdo->query('SELECT COUNT(*) FROM cm_clans')->fetchColumn(),
        (int)$pdo->query('SELECT COUNT(*) FROM cm_clan_members')->fetchColumn()]);
    assert_eq(cm_t('clan_gone'), cm_press($pdo, $log, 21, 'cm:p:1:y'));
    cl_do($pdo, $log, 20, '+клан Волки');
    assert_eq('🛡 Клан «Волки» создан. Глава — U20.', cm_last_text($log));
});

test('без префикса — по правилам команд; дк кланы 6; без таблиц — «ещё не настроено»', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    $reply = ['reply_to_message' => ['message_id' => 70, 'from' => ['id' => 20, 'first_name' => 'Петя']]];
    $run = function (string $text, array $extra = []) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text, $extra), cm_parse($text, CM_BOT));
    };
    foreach (['+клан Волки', 'клан', 'кланы', 'клан открыт', 'созвать клан', 'пригласить @petya', 'покинуть клан', 'вступить Медведи'] as $t) {
        assert_eq(true, $run($t), $t);
    }
    assert_eq(true, $run('исключить', $reply));
    foreach (['клан крутой у нас', 'кланы рулят', 'вступить в спор?', 'клан открыт для всех', 'созвать клан срочно'] as $t) {
        assert_eq(false, $run($t), $t);
    }
    assert_eq(false, $run('пригласить бы его', $reply));
    cm_do(cm_ctx_for($pdo, $log, CM_OWNER), 'дк кланы 6');
    assert_eq(false, $run('!кланы'));
    assert_eq(cm_t('cmd_off'), cm_last_text($log));
    $pdo = cm_test_pdo();
    $pdo->exec('DROP TABLE cm_offers');
    cm_do(cm_ctx_for($pdo, $log, 7), 'кланы');
    assert_eq(cm_t('not_ready2'), cm_last_text($log));
});

test('-клан Название: чужой распускает только ранг 5, свой — глава', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cm_lvl($pdo, 21, 4);
    cl_do($pdo, $log, 21, '-клан Волки');
    assert_eq('Чужой клан распускает только ранг 5. Свой — «-клан».', cm_last_text($log));
    cl_do($pdo, $log, CM_OWNER, '-клан Медведи');
    assert_eq('Клана «Медведи» нет.', cm_last_text($log));
    cl_do($pdo, $log, CM_OWNER, 'удалить клан волки');
    assert_eq('💥 Клан «Волки» распущен.', cm_last_text($log));
    assert_eq([0, 0], [(int)$pdo->query('SELECT COUNT(*) FROM cm_clans')->fetchColumn(),
        (int)$pdo->query('SELECT COUNT(*) FROM cm_clan_members')->fetchColumn()]);
    cl_do($pdo, $log, 22, '+клан Ёжики');
    cl_do($pdo, $log, 22, 'распустить клан Ежики');
    assert_eq('💥 Клан «Ёжики» распущен.', cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, CM_OWNER, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(false, $run('удалить клан нельзя?'), 'обычная речь');
});

test('выйти из клана — синонимами и в личке бота; глава не уходит', function () {
    $pdo = cm_test_pdo();
    $log = [];
    cl_people($pdo);
    cl_do($pdo, $log, 7, '+клан Волки');
    cl_do($pdo, $log, 20, 'вступить Волки');
    cl_do($pdo, $log, 21, 'вступить Волки');
    cl_do($pdo, $log, 20, 'выйти из клана');
    assert_eq('U20 покидает клан «Волки».', cm_last_text($log));
    $log = [];
    $p = ['message_id' => 1, 'chat' => ['id' => 21, 'type' => 'private'], 'from' => ['id' => 21, 'first_name' => 'Коля'], 'text' => 'уйти из клана'];
    assert_eq(true, cm_wants_update(['message' => $p], CM_BOT));
    cm_handle_update($pdo, cm_cfg(), cm_rec($log), ['message' => $p], CM_NOW);
    assert_eq([21, 'Коля покидает клан «Волки».'], [cm_calls($log, 'sendMessage')[0][1]['chat_id'], cm_last_text($log)]);
    assert_eq([7], cl_members($pdo, 1));
    cl_do($pdo, $log, 7, 'выйти с клана');
    assert_eq(cm_t('clan_owner_stays'), cm_last_text($log));
    $run = function (string $text) use ($pdo, &$log): bool {
        return cm_run_command(cm_ctx_for($pdo, $log, 7, $text), cm_parse($text, CM_BOT));
    };
    assert_eq(false, $run('выйти из клана хочу'), 'обычная речь');
});

run_tests();
