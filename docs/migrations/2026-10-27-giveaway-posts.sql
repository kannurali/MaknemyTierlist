-- Пост розыгрыша в Telegram-канале: бот публикует идущий розыгрыш в каналы,
-- отмеченные в /admin/giveaways («Пост в Telegram»), правит пост, когда
-- розыгрыш правят в админке, и дописывает победителей после итогов. И
-- премиум-эмодзи, которые бот ставит в пост и в сообщения о розыгрыше.
-- Запускать один раз на боевой базе, после 2026-10-14-giveaways.sql.
--
-- Пока таблиц нет, разделов «Пост в Telegram» и «Премиум-эмодзи» в админке
-- нет, а розыгрыши работают как раньше. Логика — api/lib/giveaway.php,
-- разделы «Пост в канале» и «Премиум-эмодзи».
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

-- --------------------------------------------------------------------------
--  Премиум-эмодзи бота
-- --------------------------------------------------------------------------
-- Какой премиум-эмодзи бот ставит вместо обычного: slot — место (gift —
-- название розыгрыша и кнопка «Участвовать», prize — призы и победители,
-- conds — условия, time — срок итогов, win — итоги и поздравление, people —
-- число участников; список — GW_EMOJI в api/lib/giveaway.php), emoji_id —
-- id премиум-эмодзи из Telegram (бот присылает его в ответ на эмодзи в
-- личке). Нет строки — обычный эмодзи. Правится в /admin/giveaways.
CREATE TABLE IF NOT EXISTS tg_emoji (
  slot       VARCHAR(16)     NOT NULL PRIMARY KEY,
  emoji_id   VARCHAR(24)     NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
