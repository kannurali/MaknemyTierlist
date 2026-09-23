-- «Запомнить вход». Запускать один раз на боевой базе, после schema.sql.
--
-- Одна строка — один браузер, где человек вошёл через Roblox. Кука
-- nx_remember держит selector и секрет; в базе лежит только sha256 секрета,
-- и утёкшая таблица не даёт войти ни под кем. Строку гасит выход, истечение
-- срока (скользящие 180 дней) или одиннадцатое устройство того же человека.
-- Логика — api/lib/remember.php.
--
-- Пока таблицы нет, сайт работает как раньше: вход живёт до закрытия
-- браузера.

CREATE TABLE IF NOT EXISTS login_tokens (
  selector   CHAR(24)        NOT NULL PRIMARY KEY,
  token_hash CHAR(64)        NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  KEY idx_user (user_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
