-- Чат-менеджер: шахматы в мини-приложении. Код — api/lib/chess.php
-- (правила), api/lib/cm/chess.php (партия), api/cm_chess.php (запросы
-- мини-приложения), js/tg-chess.js; описание — docs/chat-manager.md.
-- Запускать один раз, ПОСЛЕ 2026-11-08-chat-manager-games.sql. Пока таблицы
-- нет, «шахматы» отвечают «ещё не настроено», остальной бот работает как
-- раньше. Счёт партий — в cm_game_stats этапа 3 (kind = 'chess').
--
-- Время — в миллисекундах: turn_at — начало текущего хода, due_at — когда у
-- ходящего кончится время или срок первого хода (0 — партия закончена).
-- moves и san — ходы через пробел (e2e4 / e4). draw_by — кто предлагает
-- ничью: w, b или ''. status: on, done; result: 1-0, 0-1, 1/2, abort;
-- reason: mate, resign, time, stalemate, material, fifty, repeat, agreed,
-- abort. rev растёт с каждым изменением: ход пишется условием «rev не
-- менялся».

CREATE TABLE IF NOT EXISTS cm_chess (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  chat_id    BIGINT          NOT NULL,
  white_id   BIGINT          NOT NULL,
  black_id   BIGINT          NOT NULL,
  base_ms    INT UNSIGNED    NOT NULL,
  white_ms   INT             NOT NULL,
  black_ms   INT             NOT NULL,
  fen        VARCHAR(100)    NOT NULL,
  moves      TEXT            NOT NULL,
  san        TEXT            NOT NULL,
  turn_at    BIGINT UNSIGNED NOT NULL,
  due_at     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  draw_by    CHAR(1)         NOT NULL DEFAULT '',
  msg_id     BIGINT          NOT NULL DEFAULT 0,
  status     VARCHAR(4)      NOT NULL DEFAULT 'on',
  result     VARCHAR(5)      NOT NULL DEFAULT '',
  reason     VARCHAR(9)      NOT NULL DEFAULT '',
  rev        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_due (status, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
