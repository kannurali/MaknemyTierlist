-- Уведомления в Telegram. Запускать один раз на боевой базе, ПОСЛЕ
-- 2026-09-09-chat.sql (нужны chat_threads и chat_messages).
--
-- Пока таблиц нет, колокольчик в чате не показывается, а чат работает как
-- раньше: tg_ready() в api/lib/telegram.php отвечает false, и ни один запрос
-- к этим таблицам не роняет переписку.

-- --------------------------------------------------------------------------
--  Привязка аккаунта сайта к Telegram
-- --------------------------------------------------------------------------
-- Одна строка — один аккаунт сайта. chat_id — личный чат человека с ботом;
-- его присылает только сам Telegram (вебхук на /start), от браузера он не
-- принимается никогда.
--
-- chat_id знаковый: у личных чатов он положительный, но Telegram обещает
-- лишь «влезает в 52 бита», а не знак.
--
-- Индекс по chat_id: /stop и ответ «бот заблокирован» ищут привязки по нему.
CREATE TABLE IF NOT EXISTS tg_links (
  user_id   BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  chat_id   BIGINT          NOT NULL,
  tg_name   VARCHAR(64)     NOT NULL DEFAULT '',
  lang      VARCHAR(2)      NOT NULL DEFAULT 'ru',
  linked_at BIGINT UNSIGNED NOT NULL,
  KEY idx_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Одноразовые коды привязки
-- --------------------------------------------------------------------------
-- Колокольчик выдаёт ссылку t.me/<бот>?start=<код>. В базе лежит только
-- sha256 кода: утёкшая таблица не даёт привязать чужой аккаунт к своему
-- Telegram. Живёт код TG_CODE_TTL секунд и гасится первым же /start.
CREATE TABLE IF NOT EXISTS tg_codes (
  code_hash  CHAR(64)        NOT NULL PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  lang       VARCHAR(2)      NOT NULL DEFAULT 'ru',
  expires_at BIGINT UNSIGNED NOT NULL,
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Что человек видел в диалоге
-- --------------------------------------------------------------------------
-- Без этого бот звал бы на каждое сообщение, даже когда человек сидит в этом
-- самом чате. Строку ведёт api/chat.php: каждый показ ветки (и фоновая
-- перечитка раз в 10 секунд, пока вкладка открыта) сдвигает last_read_id на
-- последнее показанное сообщение, а seen_at — на текущее время.
--
-- notified_id — сообщение, о котором уже позвали в Telegram. Пока оно больше
-- last_read_id, человек ещё не заходил прочитать, и новые сообщения той же
-- ветки молчат: одно уведомление на пачку непрочитанного.
CREATE TABLE IF NOT EXISTS chat_reads (
  thread_id    INT UNSIGNED    NOT NULL,
  user_id      BIGINT UNSIGNED NOT NULL,
  last_read_id INT UNSIGNED    NOT NULL DEFAULT 0,
  seen_at      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  notified_id  INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (thread_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
