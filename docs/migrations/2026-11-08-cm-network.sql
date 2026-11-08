-- Чат-менеджер: одна сеть чатов Макнеми (решение владельца 08.11.2026).
-- Код — api/lib/cm/db.php (cm_net, cm_scope, cm_settings_save); описание —
-- docs/chat-manager.md, «Сеть чатов Макнеми». Запускать один раз после мержа.
--
-- Новых таблиц нет: настройки общие с этого кода, но у уже стоящих чатов они
-- записаны каждая своя. Запрос 1 делает настройки «Maknemy Trades» такими же,
-- как у «Чат Maknemy» (приветствие, правила, фильтры, триггеры, «дк», варны),
-- и тот же чат жалоб. Дальше любая правка в одном чате сети уходит во все.
-- Если в Trades стоят свои приветствие или правила, которые надо оставить, —
-- после запроса задать их заново командой в любом чате сети.
--
-- Запросы 2–3 выводят «Тестим» из сети (solo): бот там работает, но
-- настройки, ранги, наказания и статистика у него свои и боевые чаты он не
-- трогает. Второй запрос — если ключа solo ещё нет, третий — если код уже
-- успел записать "solo":false.

-- 1. Trades ← настройки и чат жалоб «Чат Maknemy».
UPDATE cm_chats t
  JOIN cm_chats s ON s.chat_id = -1001878704741
   SET t.settings = s.settings, t.report_chat_id = s.report_chat_id
 WHERE t.chat_id = -1004335662196;

-- 2–3. «Тестим» — вне сети.
UPDATE cm_chats
   SET settings = REPLACE(settings, '"maknemy":true', '"maknemy":true,"solo":true')
 WHERE chat_id = -1003923660772 AND settings NOT LIKE '%"solo":%';

UPDATE cm_chats
   SET settings = REPLACE(settings, '"solo":false', '"solo":true')
 WHERE chat_id = -1003923660772;
