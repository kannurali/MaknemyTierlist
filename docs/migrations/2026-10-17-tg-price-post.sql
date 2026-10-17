-- Пост о ценах в чат «Башня Шута». Запускать один раз на боевой базе, после
-- 2026-10-14-giveaways.sql (там создаётся tg_channels).
--
-- Бот публикует изменения цен тирлиста в чат «Башня Шута» (tg_post_prices в
-- api/lib/telegram.php). Эти колонки помнят последний пост: id сообщения,
-- когда он ушёл и какие изменения в нём перечислены. Сохранение в течение
-- 30 минут после поста правит его, а не шлёт новый.
--
-- Без миграции посты всё равно идут, только каждое сохранение — новый пост.

ALTER TABLE tg_channels
  ADD COLUMN prices_msg BIGINT          NOT NULL DEFAULT 0,
  ADD COLUMN prices_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN prices_log MEDIUMTEXT      NULL;
