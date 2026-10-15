-- Бан из панели: модераторы и админы банят игрока кнопкой в чате или на
-- /admin/bans, не трогая config.php. Запускать один раз на боевой базе,
-- после schema.sql.
--
-- Пока таблицы нет, сайт работает по одному списку banned_ids в config.php,
-- а кнопки бана отвечают «таблицы нет». Логика — api/lib/ban.php.

-- Одна строка — один забаненный. Повторный бан заменяет строку, снятие
-- удаляет её. Истёкшие строки ничему не мешают: ban_store_list() берёт
-- только действующие.
CREATE TABLE IF NOT EXISTS user_bans (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  -- Конец бана, unix-время; 0 — навсегда.
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- Кто забанил — Roblox id модератора или админа.
  by_id      BIGINT UNSIGNED NOT NULL,
  reason     VARCHAR(200)    NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_until (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
