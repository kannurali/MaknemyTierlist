<?php
define('TESTING', 1);
require __DIR__ . '/lib.php';
require __DIR__ . '/../public_html/api/_bootstrap.php';
require __DIR__ . '/../public_html/api/lib/cm/cm.php';
require __DIR__ . '/cm_helpers.php';

// Фильтры и триггеры: api/lib/cm/filters.php, встроенный мат — words.php.

test('ссылки: любые, кроме maknemy.com и доменов чата', function () {
    $d = ['youtube.com'];
    foreach (['зайди на evil.ru', 'https://scam.xyz/free', 't.me/joinchat/abc', 'evil . com', 'www.evil.net',
              'tg://resolve?domain=x'] as $t) {
        assert_eq(true, cm_has_link(['text' => $t], $d), $t);
    }
    foreach (['https://maknemy.com/stock', 'www.maknemy.com', 'смотри youtube.com/watch?v=1', 'm.youtube.com',
              'обычный текст', 'Buddha.Gas', '@username'] as $t) {
        assert_eq(false, cm_has_link(['text' => $t], $d), $t);
    }
    $hidden = ['text' => 'тык', 'entities' => [['type' => 'text_link', 'offset' => 0, 'length' => 3, 'url' => 'https://evil.ru']]];
    assert_eq(true, cm_has_link($hidden, $d), 'ссылка под текстом');
    assert_eq(true, cm_has_link(['caption' => 'фото evil.ru'], $d), 'подпись к фото');
    assert_eq(true, cm_has_link(['text' => 'mаknemy.com'], $d), 'кириллическая а в своём домене');
});

test('мат: корни, двойники, вразрядку; обычные слова не задеваются', function () {
    $rules = chat_filter_compile(CM_MAT_WORDS);
    foreach (['ты сука', 'БЛЯТЬ', 'нахуй', 'пиздец', 'заебал', 'ёбаный', 'с у к а', 'xуй', 'бляяя'] as $t) {
        assert_eq(true, cm_has_mat($t, $rules), $t);
    }
    foreach (['употреблять', 'корабля', 'рубля', 'оскорблять', 'застрахуй машину', 'хлеба', 'небо', 'команда',
              'сукно', 'влюбляться', 'психует', 'Привет всем'] as $t) {
        assert_eq(false, cm_has_mat($t, $rules), $t);
    }
});

test('свои слова чата добавляются к встроенным', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+слово дурак*');
    assert_eq(true, cm_has_mat('нет, дураков', cm_mat_rules($pdo, CM_CHAT)));
    cm_do($ctx, '+слово ДУРАК*');
    assert_eq(cm_t('word_exists', ['word' => 'дурак*']), cm_last_text($log));
    cm_do($ctx, '+слово д');
    assert_eq(cm_t('word_usage'), cm_last_text($log));
    cm_do($ctx, 'слова');
    assert_eq(cm_t('words_head') . "\nдурак*", cm_last_text($log));
    cm_do($ctx, '-слово дурак*');
    assert_eq(false, cm_has_mat('нет, дураков', cm_mat_rules($pdo, CM_CHAT)));
    cm_do($ctx, '-слово дурак*');
    assert_eq(cm_t('word_missing', ['word' => 'дурак*']), cm_last_text($log));
});

test('флуд: N за S секунд или три одинаковых подряд', function () {
    $pdo = cm_test_pdo();
    for ($i = 0; $i < 4; $i++) { cm_msg_log($pdo, CM_CHAT, 200 + $i, 7, 'msg' . $i, CM_NOW - 2); }
    assert_eq(false, cm_is_flood($pdo, CM_CHAT, 7, 'msg3', [5, 10], CM_NOW));
    cm_msg_log($pdo, CM_CHAT, 204, 7, 'msg4', CM_NOW);
    assert_eq(true, cm_is_flood($pdo, CM_CHAT, 7, 'msg4', [5, 10], CM_NOW));
    assert_eq(false, cm_is_flood($pdo, CM_CHAT, 7, 'msg4', [5, 10], CM_NOW + 20), 'окно ушло');
    $p2 = cm_test_pdo();
    foreach ([300, 301, 302] as $id) { cm_msg_log($p2, CM_CHAT, $id, 8, 'КУПИ', CM_NOW - 100); }
    assert_eq(true, cm_is_flood($p2, CM_CHAT, 8, 'купи', [5, 10], CM_NOW));
});

test('капс от 10 букв и 70% заглавных; пересылки', function () {
    assert_eq(true, cm_is_caps('КУПИТЕ ФРУКТЫ ДЁШЕВО'));
    assert_eq(false, cm_is_caps('ОК'));
    assert_eq(false, cm_is_caps('Привет, Как Дела У Вас'));
    assert_eq(true, cm_is_forward(['forward_origin' => ['type' => 'channel']]));
    assert_eq(true, cm_is_forward(['forward_origin' => ['type' => 'chat']]));
    assert_eq(false, cm_is_forward(['forward_origin' => ['type' => 'user']]));
    assert_eq(false, cm_is_forward(['is_automatic_forward' => true, 'forward_origin' => ['type' => 'channel']]));
    assert_eq(true, cm_is_forward(['forward_from_chat' => ['id' => -100]]));
});

test('фильтры по старшинству, выключенный не срабатывает', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'СУКА ЗАЙДИ НА EVIL.RU СРОЧНО');
    assert_eq('links', cm_filter_hit($ctx, $ctx['msg']), 'по умолчанию — только ссылки');
    $s = $ctx['chat']['settings'];
    $s['filters']['links'] = false;
    $s['filters']['caps'] = true;
    $s['filters']['mat'] = true;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    assert_eq('mat', cm_filter_hit($ctx, $ctx['msg']), 'мат раньше капса');
    assert_eq('', cm_filter_hit($ctx, cm_gmsg(7, '', ['sticker' => ['file_id' => 'x']])));
    $s['filters']['stickers'] = true;
    cm_settings_save($pdo, $ctx['chat'], $s, CM_NOW);
    assert_eq('stickers', cm_filter_hit($ctx, cm_gmsg(7, '', ['sticker' => ['file_id' => 'x']])));
});

test('нарушение: по умолчанию удаление молча, с варном — сообщение', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, 7, 'зайди на evil.ru');
    cm_filter_act($ctx, 'links');
    assert_eq([1, 0], [count(cm_calls($log, 'deleteMessage')), count(cm_calls($log, 'sendMessage'))]);
    $o = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($o, 'триггер ссылки: удалить, варн');
    $log2 = [];
    $ctx2 = cm_ctx_for($pdo, $log2, 7, 'evil.ru');
    cm_filter_act($ctx2, 'links');
    assert_eq('🚫 U7: ссылки → варн 1/5', cm_last_text($log2));
    assert_eq(1, (int)$pdo->query('SELECT COUNT(*) FROM cm_queue')->fetchColumn(), 'сообщение временное');
});

test('команды фильтров и автоудаления', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'фильтр мат вкл');
    cm_do($ctx, 'фильтр флуд 7 10');
    $s = cm_chat_row($pdo, CM_CHAT)['settings'];
    assert_eq([true, true, [7, 10]], [$s['filters']['mat'], $s['filters']['flood'], $s['flood']]);
    cm_do($ctx, 'фильтр сало вкл');
    assert_eq(true, strpos(cm_last_text($log), 'Фильтры: спамеры, ссылки') !== false);
    cm_do($ctx, 'фильтр мат 5 5');
    assert_eq(true, strpos(cm_last_text($log), 'Так:') === 0, 'числа — только у флуда');
    cm_do($ctx, 'фильтры');
    $t = cm_last_text($log);
    assert_eq(true, strpos($t, '✅ мат → удалить') !== false);
    assert_eq(true, strpos($t, '✅ флуд → удалить (7 за 10 с)') !== false);
    assert_eq(true, strpos($t, '▫️ капс') !== false);
    cm_do($ctx, 'фильтр ссылки выкл');
    assert_eq(false, cm_chat_row($pdo, CM_CHAT)['settings']['filters']['links']);
    cm_do($ctx, 'автоудаление выкл');
    assert_eq(false, cm_chat_row($pdo, CM_CHAT)['settings']['autodelete']);
    cm_do($ctx, 'автоудаление да');
    assert_eq(cm_t('autodelete_usage'), cm_last_text($log));
});

test('домены', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, '+домен https://YouTube.com/watch?v=1');
    assert_eq(['youtube.com'], cm_chat_row($pdo, CM_CHAT)['settings']['domains']);
    cm_do($ctx, '+домен www.youtube.com');
    assert_eq(cm_t('domain_exists', ['domain' => 'youtube.com']), cm_last_text($log));
    cm_do($ctx, '+домен ерунда');
    assert_eq(cm_t('domain_usage'), cm_last_text($log));
    cm_do($ctx, 'домены');
    assert_eq(cm_t('domains_head') . "\nyoutube.com", cm_last_text($log));
    cm_do($ctx, '-домен youtube.com');
    assert_eq([], cm_chat_row($pdo, CM_CHAT)['settings']['domains']);
    cm_do($ctx, '-домен youtube.com');
    assert_eq(cm_t('domain_missing', ['domain' => 'youtube.com']), cm_last_text($log));
});

test('триггеры: установка, список, сброс', function () {
    $pdo = cm_test_pdo();
    $log = [];
    $ctx = cm_ctx_for($pdo, $log, CM_OWNER);
    cm_do($ctx, 'триггер мат: удалить, мут 1ч');
    assert_eq([['kind' => 'delete', 'secs' => 0], ['kind' => 'mute', 'secs' => 3600]],
        cm_chat_row($pdo, CM_CHAT)['settings']['triggers']['mat']);
    cm_do($ctx, 'триггер сало: удалить');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'триггер варны: варн');
    assert_eq(cm_t('trigger_usage'), cm_last_text($log));
    cm_do($ctx, 'триггеры');
    $t = cm_last_text($log);
    assert_eq(true, strpos($t, 'мат → удалить, мут 1 ч') !== false);
    assert_eq(true, strpos($t, 'ссылки → удалить (по умолчанию)') !== false);
    assert_eq(true, strpos($t, 'спамеры → удалить, бан навсегда (по умолчанию)') !== false);
    cm_do($ctx, '-триггер мат');
    assert_eq(false, isset(cm_chat_row($pdo, CM_CHAT)['settings']['triggers']['mat']));
    assert_eq(cm_t('trigger_reset', ['name' => 'мат', 'actions' => 'удалить']), cm_last_text($log));
});

run_tests();
