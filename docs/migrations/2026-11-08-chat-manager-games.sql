-- Чат-менеджер, этап 3 (игры и отношения): приглашения, дуэли, кубы, пары,
-- браки, кланы. Код — api/lib/cm/fun.php, offers.php, dice.php, duel.php,
-- love.php, clans.php; описание — docs/chat-manager.md. Запускать один раз,
-- ПОСЛЕ 2026-11-08-chat-manager-activity.sql. Пока этих таблиц нет, команды
-- этапа 3 отвечают «ещё не настроено» (cm_ready3 в api/lib/cm/fun.php), а
-- остальной бот работает как раньше.
--
-- «Нет», «не закончено» — 0, а не NULL. Открыт ли клан — is_open: OPEN —
-- ключевое слово MySQL (курсоры), имя столбца с ним не рискуем.

-- Приглашения с кнопками «Принять» / «Отказаться». kind: duel, dice, couple,
-- marry, remarry, clan; ref_id — клан (clan) или прежний брак (remarry).
-- status: open, yes, no, gone.
CREATE TABLE IF NOT EXISTS cm_offers (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  from_id    BIGINT          NOT NULL,
  to_id      BIGINT          NOT NULL,
  ref_id     INT UNSIGNED    NOT NULL DEFAULT 0,
  msg_id     BIGINT          NOT NULL DEFAULT 0,
  expires_at BIGINT UNSIGNED NOT NULL,
  status     VARCHAR(4)      NOT NULL DEFAULT 'open',
  KEY idx_open (chat_id, status, expires_at),
  KEY idx_due (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Идущие дуэли. state — JSON прицелов {"a":0,"b":0}; status: on, done.
CREATE TABLE IF NOT EXISTS cm_games (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  a_id       BIGINT          NOT NULL,
  b_id       BIGINT          NOT NULL,
  turn_id    BIGINT          NOT NULL,
  state      VARCHAR(255)    NOT NULL DEFAULT '{}',
  msg_id     BIGINT          NOT NULL DEFAULT 0,
  status     VARCHAR(4)      NOT NULL DEFAULT 'on',
  winner_id  BIGINT          NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL,
  KEY idx_due (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Счёт дуэлей и кубов. kind: duel, dice.
CREATE TABLE IF NOT EXISTS cm_game_stats (
  chat_id BIGINT       NOT NULL,
  user_id BIGINT       NOT NULL,
  kind    VARCHAR(8)   NOT NULL,
  wins    INT UNSIGNED NOT NULL DEFAULT 0,
  losses  INT UNSIGNED NOT NULL DEFAULT 0,
  draws   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (chat_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Пары. Опыт — от РП, не больше 20 в сутки по Москве: xp_day (ГГГГММДД) и
-- xp_today. ended_at 0 — вместе.
CREATE TABLE IF NOT EXISTS cm_couples (
  id       INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id  BIGINT            NOT NULL,
  a_id     BIGINT            NOT NULL,
  b_id     BIGINT            NOT NULL,
  since    BIGINT UNSIGNED   NOT NULL,
  xp       INT UNSIGNED      NOT NULL DEFAULT 0,
  xp_day   INT UNSIGNED      NOT NULL DEFAULT 0,
  xp_today SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ended_at BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  KEY idx_a (chat_id, a_id),
  KEY idx_b (chat_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Браки. ended_at 0 — в браке; ended_by — кто развёлся.
CREATE TABLE IF NOT EXISTS cm_marriages (
  id       INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id  BIGINT          NOT NULL,
  a_id     BIGINT          NOT NULL,
  b_id     BIGINT          NOT NULL,
  since    BIGINT UNSIGNED NOT NULL,
  ended_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ended_by BIGINT          NOT NULL DEFAULT 0,
  KEY idx_a (chat_id, a_id),
  KEY idx_b (chat_id, b_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кланы. name_key — имя в нижнем регистре и с «е» вместо «ё»: имя уникально
-- в чате без учёта регистра.
CREATE TABLE IF NOT EXISTS cm_clans (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  name       VARCHAR(32)     NOT NULL,
  name_key   VARCHAR(32)     NOT NULL,
  owner_id   BIGINT          NOT NULL,
  about      VARCHAR(200)    NOT NULL DEFAULT '',
  is_open    TINYINT         NOT NULL DEFAULT 1,
  called_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_name (chat_id, name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Кто в каком клане: в чате человек — не больше чем в одном.
CREATE TABLE IF NOT EXISTS cm_clan_members (
  chat_id   BIGINT          NOT NULL,
  user_id   BIGINT          NOT NULL,
  clan_id   INT UNSIGNED    NOT NULL,
  joined_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (chat_id, user_id),
  KEY idx_clan (clan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
