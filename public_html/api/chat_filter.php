<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/lib/chat_filter.php';

// Список слов фильтра чата — обычные формы со страницы /admin/chat-filter:
//
//   POST word=…    — добавить (несколько — через запятую или с новой строки)
//   POST remove=…  — убрать одно
//
// В ответ 303 обратно на страницу с итогом в ?done=. Чужой сайт такую форму не
// отправит: кука сессии SameSite=Lax на межсайтовый POST не идёт.
//
// Только админам: одно слово здесь меняет текст у всех, и общий список — не
// работа модератора. Модератор читает журнал и банит.

if (!defined('TESTING')) {
    header('Cache-Control: no-store');
    require_post();
    require_admin();

    $remove = isset($_POST['remove']) && is_string($_POST['remove']) ? $_POST['remove'] : '';
    $word   = isset($_POST['word']) && is_string($_POST['word']) ? $_POST['word'] : '';
    $done   = $remove !== ''
        ? chat_filter_remove(db(), $remove)
        : chat_filter_add(db(), $word, time());
    header('Location: /admin/chat-filter?done=' . rawurlencode($done), true, 303);
    exit;
}
