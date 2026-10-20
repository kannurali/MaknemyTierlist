-- Хэллоуинский ивент «Тыквенная охота» (api/lib/hunt.php). Запускать один
-- раз на боевой базе. Устройство — docs/superpowers/specs/2026-10-19-halloween-hunt-design.md.
--
-- Пока таблиц нет, api/hunt.php отвечает {"hunt": null}, а индикатор и
-- страница ивента молчат: сайт работает как раньше.

-- --------------------------------------------------------------------------
--  Сезон ивента
-- --------------------------------------------------------------------------
-- status: draft → live → drawn. live с starts_at в будущем — «скоро»,
-- после sales_end_at — «приём закрыт, ждём итогов».
-- prizes_json — [{name, count, icon}] главного розыгрыша, как у розыгрышей.
-- milestones_json — [{at, title_ru, title_en}]: титулы по «всего заработано».
-- claim_days — сколько дней победитель может подтвердить выигрыш.
CREATE TABLE IF NOT EXISTS hunts (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug            VARCHAR(40)     NOT NULL DEFAULT '',
  status          VARCHAR(8)      NOT NULL DEFAULT 'draft',
  title_ru        VARCHAR(120)    NOT NULL DEFAULT '',
  title_en        VARCHAR(120)    NOT NULL DEFAULT '',
  rules_ru        TEXT            NULL,
  rules_en        TEXT            NULL,
  starts_at       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sales_end_at    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  draw_at         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ticket_price    INT UNSIGNED    NOT NULL DEFAULT 50,
  ticket_limit    INT UNSIGNED    NOT NULL DEFAULT 3,
  prizes_json     TEXT            NULL,
  claim_days      INT UNSIGNED    NOT NULL DEFAULT 7,
  milestones_json TEXT            NULL,
  created_at      BIGINT UNSIGNED NOT NULL,
  updated_at      BIGINT UNSIGNED NOT NULL,
  drawn_at        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (status, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Главы: до opens_at задания и секретки главы не отдаются
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS hunt_chapters (
  hunt_id  INT UNSIGNED     NOT NULL,
  n        TINYINT UNSIGNED NOT NULL,
  title_ru VARCHAR(120)     NOT NULL DEFAULT '',
  title_en VARCHAR(120)     NOT NULL DEFAULT '',
  story_ru TEXT             NULL,
  story_en TEXT             NULL,
  opens_at BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY (hunt_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Задания
-- --------------------------------------------------------------------------
-- kind: quiz | tg | link | visit; level: intro | easy | medium | hard;
-- period: once | daily (день — дата по Москве); lang: all | ru | en —
-- кому показывать. grp — ключ группы: задания одной группы вместе дают одну
-- награду (RU- и EN-канал новостей).
-- cfg_json по kind: quiz {q_ru, q_en, options:[{ru, en}], correct, hint_ru,
-- hint_en, explain_ru, explain_en}; tg {channel}; link {url}; visit {page}.
CREATE TABLE IF NOT EXISTS hunt_tasks (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id    INT UNSIGNED     NOT NULL,
  chapter    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sort       INT              NOT NULL DEFAULT 0,
  kind       VARCHAR(8)       NOT NULL DEFAULT 'quiz',
  level      VARCHAR(8)       NOT NULL DEFAULT 'easy',
  period     VARCHAR(8)       NOT NULL DEFAULT 'once',
  lang       VARCHAR(4)       NOT NULL DEFAULT 'all',
  grp        VARCHAR(32)      NOT NULL DEFAULT '',
  reward     INT UNSIGNED     NOT NULL DEFAULT 0,
  title_ru   VARCHAR(160)     NOT NULL DEFAULT '',
  title_en   VARCHAR(160)     NOT NULL DEFAULT '',
  body_ru    TEXT             NULL,
  body_en    TEXT             NULL,
  cfg_json   TEXT             NULL,
  active     TINYINT          NOT NULL DEFAULT 1,
  created_at BIGINT UNSIGNED  NOT NULL,
  updated_at BIGINT UNSIGNED  NOT NULL,
  KEY idx_hunt (hunt_id, chapter, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Секретки на страницах сайта
-- --------------------------------------------------------------------------
-- page и spot — из HUNT_SPOTS в api/lib/hunt.php. hint — видна в журнале до
-- находки; found — текст после находки, в нём подсказка к следующей.
CREATE TABLE IF NOT EXISTS hunt_secrets (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id    INT UNSIGNED     NOT NULL,
  chapter    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  sort       INT              NOT NULL DEFAULT 0,
  page       VARCHAR(16)      NOT NULL DEFAULT '',
  spot       VARCHAR(16)      NOT NULL DEFAULT '',
  sprite     VARCHAR(12)      NOT NULL DEFAULT 'pumpkin',
  level      VARCHAR(8)       NOT NULL DEFAULT 'easy',
  period     VARCHAR(8)       NOT NULL DEFAULT 'once',
  reward     INT UNSIGNED     NOT NULL DEFAULT 0,
  hint_ru    VARCHAR(255)     NOT NULL DEFAULT '',
  hint_en    VARCHAR(255)     NOT NULL DEFAULT '',
  found_ru   TEXT             NULL,
  found_en   TEXT             NULL,
  active     TINYINT          NOT NULL DEFAULT 1,
  created_at BIGINT UNSIGNED  NOT NULL,
  updated_at BIGINT UNSIGNED  NOT NULL,
  KEY idx_page (hunt_id, page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Кошелёк участника — кеш журнала, его меняет та же транзакция
-- --------------------------------------------------------------------------
-- balance — можно потратить; earned — всего заработано, при тратах не
-- уменьшается (шкала приключения); tickets — сколько куплено билетов.
-- src — первый источник перехода (?src= из ссылки партнёра).
-- days — в сколько разных дней по Москве человек что-то получал.
CREATE TABLE IF NOT EXISTS hunt_wallets (
  hunt_id  INT UNSIGNED    NOT NULL,
  user_id  BIGINT UNSIGNED NOT NULL,
  balance  INT             NOT NULL DEFAULT 0,
  earned   INT UNSIGNED    NOT NULL DEFAULT 0,
  tickets  INT UNSIGNED    NOT NULL DEFAULT 0,
  src      VARCHAR(32)     NOT NULL DEFAULT '',
  first_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_day CHAR(10)        NOT NULL DEFAULT '',
  days     INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (hunt_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Журнал тыковок: каждое начисление и списание
-- --------------------------------------------------------------------------
-- ref — что именно: task:12:once, task:15:2026-10-25, grp:news:once,
-- secret:7:once, ticket:2. Уникальный ключ не даёт получить одно и то же
-- дважды — из второй вкладки, двойным кликом, перезагрузкой.
CREATE TABLE IF NOT EXISTS hunt_ledger (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hunt_id    INT UNSIGNED    NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  delta      INT             NOT NULL,
  kind       VARCHAR(8)      NOT NULL DEFAULT '',
  ref        VARCHAR(64)     NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_ref (hunt_id, user_id, ref),
  KEY idx_user (hunt_id, user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Билеты главного розыгрыша
-- --------------------------------------------------------------------------
-- id — номер билета (#0042). seq — какой это билет у человека, 1…лимит:
-- ключ не даёт двум одновременным покупкам превысить лимит. draw_pos —
-- место в перемешанной очереди итогов (0 — итогов ещё не было), по нему
-- «Перевыбрать» берёт следующий билет.
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
--  Выигрыши и их выдача
-- --------------------------------------------------------------------------
-- status: won (получен) → confirmed (данные подтверждены) → queued (ожидает
-- выдачи) → given (выдан); не подтвердил до deadline — expired.
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
--  Обезличенные счётчики воронки (гости): показ приглашения и т. п.
-- --------------------------------------------------------------------------
-- metric: page_v — открыли /halloween, invite_v — увидели приглашение,
-- invite_c — нажали в нём, bar_c — нажали индикатор. day — дата по Москве.
CREATE TABLE IF NOT EXISTS hunt_stats (
  hunt_id INT UNSIGNED NOT NULL,
  day     CHAR(10)     NOT NULL,
  metric  VARCHAR(16)  NOT NULL,
  n       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (hunt_id, day, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
