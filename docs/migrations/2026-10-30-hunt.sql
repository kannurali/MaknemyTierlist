-- ==========================================================================
--  Хэллоуинский ивент Spooky Tokens (api/lib/hunt.php)
--  Спека: docs/superpowers/specs/2026-10-30-spooky-tokens-design.md
--
--  Выполнить один раз на боевой базе после мержа (phpMyAdmin → SQL).
--  Повторный запуск безопасен: только CREATE TABLE IF NOT EXISTS.
-- ==========================================================================

-- --------------------------------------------------------------------------
--  Сезон ивента
-- --------------------------------------------------------------------------
-- status: draft → live → drawn. starts_at — полночь по Москве дня 1, дальше
-- 7 суток. sales_end_at — до какого момента продаются билеты (не раньше конца
-- семи дней), draw_at — когда итоги (текст на странице).
-- login_json — 7 наград за вход по дням, hours_json — 2 награды за час и два
-- часа на сайте в сутки. Цена билета = половина всех наград, билетов 2.
-- prizes_json — [{name, count, icon}], как у розыгрышей.
-- titles_json — {login:{ru,en}, hours:{ru,en}}: тексты двух титулов.
-- claim_days — сколько дней победитель может подтвердить выигрыш.
CREATE TABLE IF NOT EXISTS hunts (
  id           INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug         VARCHAR(40)     NOT NULL DEFAULT '',
  status       VARCHAR(8)      NOT NULL DEFAULT 'draft',
  title_ru     VARCHAR(120)    NOT NULL DEFAULT '',
  title_en     VARCHAR(120)    NOT NULL DEFAULT '',
  rules_ru     TEXT            NULL,
  rules_en     TEXT            NULL,
  starts_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sales_end_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  draw_at      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  login_json   VARCHAR(255)    NOT NULL DEFAULT '',
  hours_json   VARCHAR(255)    NOT NULL DEFAULT '',
  prizes_json  TEXT            NULL,
  claim_days   INT UNSIGNED    NOT NULL DEFAULT 7,
  titles_json  TEXT            NULL,
  created_at   BIGINT UNSIGNED NOT NULL,
  updated_at   BIGINT UNSIGNED NOT NULL,
  drawn_at     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (status, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Кошелёк участника — кеш журнала, меняется в той же транзакции
-- --------------------------------------------------------------------------
-- balance — что можно потратить, earned — всего собрано (при тратах не
-- уменьшается), tickets — сколько билетов куплено.
CREATE TABLE IF NOT EXISTS hunt_wallets (
  hunt_id  INT UNSIGNED    NOT NULL,
  user_id  BIGINT UNSIGNED NOT NULL,
  balance  INT             NOT NULL DEFAULT 0,
  earned   INT UNSIGNED    NOT NULL DEFAULT 0,
  tickets  INT UNSIGNED    NOT NULL DEFAULT 0,
  first_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (hunt_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Журнал наград и трат
-- --------------------------------------------------------------------------
-- ref — login:<день>, hour:<день>:<1|2>, ticket:<номер>. Уникальный ключ
-- (hunt_id, user_id, ref) не даёт начислить одну награду дважды.
CREATE TABLE IF NOT EXISTS hunt_ledger (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id    INT UNSIGNED    NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  delta      INT             NOT NULL,
  kind       VARCHAR(8)      NOT NULL DEFAULT '',
  ref        VARCHAR(64)     NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_ref (hunt_id, user_id, ref),
  KEY idx_kind (hunt_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Время на сайте по дням ивента
-- --------------------------------------------------------------------------
-- secs — сколько засчитано за день (не больше 7200), beat_at — время
-- последнего пульса любой вкладки человека.
CREATE TABLE IF NOT EXISTS hunt_time (
  hunt_id INT UNSIGNED     NOT NULL,
  user_id BIGINT UNSIGNED  NOT NULL,
  day     TINYINT UNSIGNED NOT NULL,
  secs    INT UNSIGNED     NOT NULL DEFAULT 0,
  beat_at BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (hunt_id, user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Билеты лутереи. id — номер билета («#0042»)
-- --------------------------------------------------------------------------
-- seq — 1 или 2: какой по счёту билет у человека. UNIQUE (hunt_id, user_id,
-- seq) — гонка двух покупок не превысит лимит. draw_pos — место в очереди
-- итогов, по ней «Перевыбрать» отдаёт приз следующему.
CREATE TABLE IF NOT EXISTS hunt_tickets (
  id          INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id     INT UNSIGNED     NOT NULL,
  user_id     BIGINT UNSIGNED  NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL,
  created_at  BIGINT UNSIGNED  NOT NULL,
  void        TINYINT          NOT NULL DEFAULT 0,
  void_reason VARCHAR(160)     NOT NULL DEFAULT '',
  draw_pos    INT UNSIGNED     NOT NULL DEFAULT 0,
  UNIQUE KEY uq_seq (hunt_id, user_id, seq),
  KEY idx_hunt (hunt_id, draw_pos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Выигрыши: won → confirmed → queued → given; expired; redrawn
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hunt_wins (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id    INT UNSIGNED    NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  ticket_id  INT UNSIGNED    NOT NULL,
  prize_idx  SMALLINT        NOT NULL DEFAULT 0,
  prize_name VARCHAR(120)    NOT NULL DEFAULT '',
  prize_icon VARCHAR(80)     NOT NULL DEFAULT '',
  status     VARCHAR(10)     NOT NULL DEFAULT 'won',
  deadline   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  note       VARCHAR(255)    NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_ticket (hunt_id, ticket_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Титулы: все 7 наград за вход → login, все 14 часовых → hours
-- --------------------------------------------------------------------------
-- Остаются навсегда, видны в профиле, чате и трейдинге. Текст — titles_json
-- своего сезона.
CREATE TABLE IF NOT EXISTS hunt_titles (
  user_id    BIGINT UNSIGNED NOT NULL,
  hunt_id    INT UNSIGNED    NOT NULL,
  kind       VARCHAR(8)      NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, hunt_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
