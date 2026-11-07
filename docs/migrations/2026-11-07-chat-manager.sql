-- Чат-менеджер, этап 1 (модерация): api/lib/cm/*, описание — docs/chat-manager.md.
-- Запускать один раз на боевой базе. Пока таблиц нет, модуль молчит
-- (cm_ready в api/lib/cm/db.php) и бот в группах ведёт себя как раньше.
--
-- «Нет срока» и «не снято» — 0, а не NULL. Ранг — lvl: RANK в MySQL 8
-- зарезервирован.

CREATE TABLE IF NOT EXISTS cm_chats (
  chat_id          BIGINT          NOT NULL PRIMARY KEY,
  title            VARCHAR(255)    NOT NULL DEFAULT '',
  username         VARCHAR(64)     NOT NULL DEFAULT '',
  active           TINYINT         NOT NULL DEFAULT 1,
  owner_id         BIGINT          NOT NULL DEFAULT 0,
  tg_owner_id      BIGINT          NOT NULL DEFAULT 0,
  admins           TEXT            NULL,
  admins_at        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  settings         MEDIUMTEXT      NULL,
  report_chat_id   BIGINT          NOT NULL DEFAULT 0,
  rights_warned_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_window      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sent_count       INT UNSIGNED    NOT NULL DEFAULT 0,
  added_at         BIGINT UNSIGNED NOT NULL,
  updated_at       BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_members (
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  lvl        TINYINT         NOT NULL DEFAULT 0,
  joined_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_seen  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  greeted_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  left_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id),
  KEY idx_seen (chat_id, last_seen),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_users (
  user_id    BIGINT          NOT NULL PRIMARY KEY,
  username   VARCHAR(64)     NOT NULL DEFAULT '',
  name       VARCHAR(128)    NOT NULL DEFAULT '',
  updated_at BIGINT UNSIGNED NOT NULL,
  KEY idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Сообщения за 48 часов: чистка по человеку и фильтр флуда. Старше Telegram
-- удалять боту не даёт, поэтому cron их вычищает.
CREATE TABLE IF NOT EXISTS cm_msgs (
  chat_id    BIGINT          NOT NULL,
  message_id BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  text_hash  CHAR(16)        NOT NULL DEFAULT '',
  at         BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, message_id),
  KEY idx_user (chat_id, user_id, at),
  KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_punishments (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  user_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  reason     VARCHAR(500)    NOT NULL DEFAULT '',
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lifted_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  lifted_by  BIGINT          NOT NULL DEFAULT 0,
  KEY idx_target (chat_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_rank_log (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id BIGINT          NOT NULL,
  user_id BIGINT          NOT NULL,
  by_id   BIGINT          NOT NULL DEFAULT 0,
  old_lvl TINYINT         NOT NULL DEFAULT 0,
  new_lvl TINYINT         NOT NULL DEFAULT 0,
  at      BIGINT UNSIGNED NOT NULL,
  KEY idx_chat (chat_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_reports (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id     BIGINT          NOT NULL,
  message_id  BIGINT          NOT NULL,
  reporter_id BIGINT          NOT NULL,
  target_id   BIGINT          NOT NULL,
  reason      VARCHAR(500)    NOT NULL DEFAULT '',
  status      VARCHAR(8)      NOT NULL DEFAULT 'open',
  handled_by  BIGINT          NOT NULL DEFAULT 0,
  created_at  BIGINT UNSIGNED NOT NULL,
  handled_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (chat_id, status),
  KEY idx_reporter (chat_id, reporter_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Общая база спамеров. added_by — Roblox id сотрудника сайта (admin_ids /
-- moderator_ids): вносят только они.
CREATE TABLE IF NOT EXISTS cm_spam (
  user_id  BIGINT          NOT NULL PRIMARY KEY,
  reason   VARCHAR(500)    NOT NULL DEFAULT '',
  added_by VARCHAR(20)     NOT NULL DEFAULT '',
  added_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_words (
  chat_id BIGINT      NOT NULL,
  word    VARCHAR(64) NOT NULL,
  PRIMARY KEY (chat_id, word)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_notes (
  chat_id    BIGINT          NOT NULL,
  name       VARCHAR(32)     NOT NULL,
  body       TEXT            NOT NULL,
  entities   TEXT            NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cm_timers (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  run_at     BIGINT UNSIGNED NOT NULL,
  body       TEXT            NOT NULL,
  by_id      BIGINT          NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  done_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_due (done_at, run_at),
  KEY idx_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Служебные ответы бота, которые удаляются через минуту (cron).
CREATE TABLE IF NOT EXISTS cm_queue (
  chat_id    BIGINT          NOT NULL,
  message_id BIGINT          NOT NULL,
  delete_at  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, message_id),
  KEY idx_due (delete_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
