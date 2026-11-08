-- Чат-менеджер, этап 2 (активность): статистика, анкета, репутация, награды.
-- Код — api/lib/cm/stats.php, profile.php, rep.php, awards.php; описание —
-- docs/chat-manager.md. Запускать один раз, ПОСЛЕ 2026-11-07-chat-manager.sql.
-- Пока этих таблиц нет, команды этапа 2 отвечают «ещё не настроено»
-- (cm_ready2 в api/lib/cm/stats.php), а модерация работает как раньше.
--
-- «Нет срока» и «не снято» — 0, а не NULL. LEVEL в MySQL не зарезервирован.

-- Сообщения за день. day — дата по Москве числом ГГГГММДД.
CREATE TABLE IF NOT EXISTS cm_stats (
  chat_id BIGINT       NOT NULL,
  user_id BIGINT       NOT NULL,
  day     INT UNSIGNED NOT NULL,
  msgs    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id, day),
  KEY idx_day (chat_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Анкета — одна на человека для всех чатов. gender: '' / 'm' / 'f';
-- birthday: «ДД.ММ» или «ДД.ММ.ГГГГ».
CREATE TABLE IF NOT EXISTS cm_profiles (
  user_id    BIGINT          NOT NULL PRIMARY KEY,
  gender     CHAR(1)         NOT NULL DEFAULT '',
  city       VARCHAR(64)     NOT NULL DEFAULT '',
  birthday   VARCHAR(10)     NOT NULL DEFAULT '',
  about      VARCHAR(200)    NOT NULL DEFAULT '',
  updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Репутация в чате.
CREATE TABLE IF NOT EXISTS cm_rep (
  chat_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  score   INT    NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кто кому и когда голосовал — для ограничений «раз в 24 часа на пару» и
-- «20 голосов за 24 часа».
CREATE TABLE IF NOT EXISTS cm_rep_log (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT          NOT NULL,
  from_id BIGINT          NOT NULL,
  to_id   BIGINT          NOT NULL,
  delta   TINYINT         NOT NULL,
  at      BIGINT UNSIGNED NOT NULL,
  KEY idx_from (chat_id, from_id, at),
  KEY idx_pair (chat_id, from_id, to_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Награды: level 1–8, until_at 0 — навсегда, removed_at 0 — действует.
CREATE TABLE IF NOT EXISTS cm_awards (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  level      TINYINT         NOT NULL,
  reason     VARCHAR(100)    NOT NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  removed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_user (chat_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
