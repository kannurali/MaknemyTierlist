-- Сток фруктов: страница /stock и «напиши в Telegram, когда в стоке
-- Kitsune». Запускать один раз на боевой базе, ПОСЛЕ 2026-09-23-telegram.sql.
--
-- Пока таблиц нет, /stock показывает пустой сток, а cron (api/stock_pull.php)
-- ничего не забирает: stock_ready() в api/lib/stock.php отвечает false.

-- --------------------------------------------------------------------------
--  Текущий сток
-- --------------------------------------------------------------------------
-- Одна строка на вид: normal — обычный продавец, mirage — продавец на
-- острове Mirage. Истории нет: на странице только то, что в продаже сейчас.
--
-- fruits — JSON [{"key":"spike","name":"Spike","price":180000}, …] в порядке
-- сообщения. ends_at — когда смена (unix, секунды; 0 — неизвестно).
-- seen_at — когда наш бот это прочитал. message_id — сообщение Discord,
-- из которого сток взят.
CREATE TABLE IF NOT EXISTS stock (
  kind       VARCHAR(8)      NOT NULL PRIMARY KEY,
  fruits     TEXT            NOT NULL,
  ends_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  seen_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  message_id VARCHAR(24)     NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Докуда прочитан канал Discord
-- --------------------------------------------------------------------------
-- Одна строка (id = 1), её заводит первый проход cron. last_id — последнее
-- прочитанное сообщение канала, alerted_id — последнее нераспознанное, о
-- котором уже сказали админу (чтобы не повторять каждую минуту).
CREATE TABLE IF NOT EXISTS stock_feed (
  id         TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  last_id    VARCHAR(24)      NOT NULL DEFAULT '0',
  alerted_id VARCHAR(24)      NOT NULL DEFAULT '0',
  polled_at  BIGINT UNSIGNED  NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Кто какие фрукты ждёт
-- --------------------------------------------------------------------------
-- Одна строка — один отмеченный фрукт одного аккаунта сайта. fruit — ключ
-- фрукта (stock_fruit_key: «T-Rex» → «trex»). Отдельно от tg_links:
-- переподключение Telegram пересоздаёт привязку, а выбор должен её пережить.
CREATE TABLE IF NOT EXISTS stock_watch (
  user_id BIGINT UNSIGNED NOT NULL,
  fruit   VARCHAR(24)     NOT NULL,
  PRIMARY KEY (user_id, fruit),
  KEY idx_fruit (fruit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
