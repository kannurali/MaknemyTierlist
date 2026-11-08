<?php
// Чат-менеджер — модерация групп нашим ботом (описание: docs/chat-manager.md).
// Подключается из api/tg_webhook.php и api/cm_cron.php. Части — по одной
// задаче в файле; порядок неважен для функций, важен для констант.

require_once __DIR__ . '/../chat_filter.php';

foreach (['db', 'texts', 'parse', 'send', 'queue', 'ranks', 'punish', 'words', 'filters', 'spam',
          'welcome', 'reports', 'cleanup', 'notes', 'timers', 'stats', 'rep', 'awards', 'profile',
          'fun', 'router', 'cron'] as $cm_part) {
    require_once __DIR__ . '/' . $cm_part . '.php';
}
unset($cm_part);
