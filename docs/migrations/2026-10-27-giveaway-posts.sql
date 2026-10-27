-- Пост розыгрыша в Telegram-канале: бот публикует идущий розыгрыш в каналы,
-- отмеченные в /admin/giveaways («Пост в Telegram»), правит пост, когда
-- розыгрыш правят в админке, и дописывает победителей после итогов. Запускать
-- один раз на боевой базе, после 2026-10-14-giveaways.sql.
--
-- Пока таблицы нет, раздела «Пост в Telegram» в админке нет, а розыгрыши
-- работают как раньше. Логика — api/lib/giveaway.php, раздел «Пост в канале».
--
-- Вебхук переподключать не нужно: бот только пишет в каналы, ничего нового
-- от Telegram он не ждёт. Право «Отправка публикаций» у бота в канале уже
-- входит в обязательные (TG_CHANNEL_RIGHTS в api/lib/telegram.php).

-- --------------------------------------------------------------------------
--  Посты розыгрышей
-- --------------------------------------------------------------------------
-- Строка ставится, когда канал отметили у розыгрыша. message_id = 0 — пост
-- ещё не вышел (черновик или Telegram не ответил); после публикации — номер
-- сообщения в канале, по нему бот правит пост.
-- results_at — когда в пост дописаны итоги (0 — ещё нет). Telegram отказал
-- насовсем (пост удалили руками) — тоже ставится, чтобы не стучаться вечно.
CREATE TABLE IF NOT EXISTS giveaway_posts (
  giveaway_id INT UNSIGNED    NOT NULL,
  chat_id     BIGINT          NOT NULL,
  message_id  INT UNSIGNED    NOT NULL DEFAULT 0,
  posted_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  results_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (giveaway_id, chat_id),
  KEY idx_results (results_at, message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
