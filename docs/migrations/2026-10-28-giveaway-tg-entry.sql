-- Участие в розыгрыше прямо в Telegram, без входа на сайт: кнопка
-- «Участвовать» под постом розыгрыша в канале. Запускать один раз на боевой
-- базе, после 2026-10-27-giveaway-posts.sql.
--
-- После миграции: /admin/support → «Подключить вебхук» ещё раз — бот
-- начинает получать нажатия кнопок (callback_query). Без этого кнопка под
-- постом крутит часики и ничего не делает.
--
-- Пока таблицы нет, под постом прежняя кнопка-ссылка на сайт. Логика —
-- api/lib/gw_tg.php.
--
-- Сам участник лежит в giveaway_entries, как все: user_id у него —
-- 9000000000000000000 + id в Telegram (GW_TG_UID_BASE), аккаунта Roblox нет.
-- Поэтому в giveaway_entries ничего не меняется.

-- --------------------------------------------------------------------------
--  Люди из Telegram
-- --------------------------------------------------------------------------
-- Кто вступил в розыгрыш кнопкой в канале: имя и @ник — для админки, поста
-- с итогами и сайта; lang — на каком языке ему отвечать.
-- roblox_nick — ник в Roblox, который победитель прислал боту для приза.
-- await_gw — бот ждёт ник для этого розыгрыша (0 — не ждёт): следующее
-- сообщение человека в личку бота считается ником.
CREATE TABLE IF NOT EXISTS tg_people (
  chat_id     BIGINT          NOT NULL PRIMARY KEY,
  name        VARCHAR(80)     NOT NULL DEFAULT '',
  username    VARCHAR(32)     NOT NULL DEFAULT '',
  lang        VARCHAR(5)      NOT NULL DEFAULT 'ru',
  roblox_nick VARCHAR(20)     NOT NULL DEFAULT '',
  await_gw    INT UNSIGNED    NOT NULL DEFAULT 0,
  updated_at  BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
