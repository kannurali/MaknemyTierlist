-- Чат-менеджер: ферма монет («ферма» раз в 4 часа, от 10 до 160 🪙).
-- Код — api/lib/cm/farm.php; описание — docs/chat-manager.md. Запускать один
-- раз, ПОСЛЕ 2026-11-08-chat-manager-games.sql. Пока таблицы нет, «ферма»,
-- «монеты» и «топ монет» отвечают «ещё не настроено», остальной бот работает
-- как раньше.

-- Кошелёк — один на человека во всех чатах Макнеми. farm_at — время
-- последнего сбора (0 — ещё не собирал), farms — сколько раз собирал.
CREATE TABLE IF NOT EXISTS cm_coins (
  user_id BIGINT          NOT NULL PRIMARY KEY,
  coins   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  farms   INT UNSIGNED    NOT NULL DEFAULT 0,
  farm_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_coins (coins)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
