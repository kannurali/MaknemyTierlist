-- Дополнение к 2026-08-18-image-customisation.sql. Та миграция добавила
-- image_pct/image_align/image_wrap, но пропустила ещё две колонки, приехавшие
-- в schema.sql тем же коммитом (1278569): image_width и image_height. Код
-- требует их безусловно — SELECT в api/news.php и INSERT/UPDATE в
-- api/news_save.php перечисляют обе поимённо. На боевой базе это давало
-- ER_BAD_FIELD_ERROR (1054): news_dispatch() отвечал 503
-- temporarily_unavailable, и /news показывала «Не удалось загрузить новости»
-- при полностью живой БД (20.08.2026 — тирлист, лайки и промо в тот момент
-- отдавались нормально).
--
-- Выполняется вручную на проде (контроллер), после
-- 2026-08-18-image-customisation.sql. Для чистой установки не нужна:
-- schema.sql описывает обе колонки в CREATE TABLE.

-- Ширина/высота УЖЕ СОХРАНЁННОЙ (пересжатой) картинки — подсказка для
-- <img width/height>, чтобы браузер зарезервировал место под неё до загрузки
-- байтов. NULL без DEFAULT: у поста без картинки и у поста, сохранённого до
-- этой миграции, значения нет вообще — это «неизвестно», а не 0×0 (ноль
-- обнулил бы зарезервированную высоту). cardFor() в news-page.js в этом
-- случае просто не ставит атрибуты, оба состояния для него одинаковы.
ALTER TABLE news
  ADD COLUMN image_width  SMALLINT UNSIGNED NULL AFTER image_wrap,
  ADD COLUMN image_height SMALLINT UNSIGNED NULL AFTER image_width;
