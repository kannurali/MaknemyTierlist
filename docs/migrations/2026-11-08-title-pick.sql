-- ==========================================================================
--  Главный титул (api/lib/hunt_titles.php, api/title-pick.php)
--
--  Решение владельца 08.11.2026: у ника виден один титул, полный список — в
--  профиле, там же человек выбирает, какой показывать.
--
--  Выполнить один раз на боевой базе после мержа (phpMyAdmin → SQL).
--  Повторный запуск безопасен: CREATE TABLE IF NOT EXISTS. До запуска сайт
--  работает: у ника первый титул по порядку, а кнопка выбора в профиле
--  отвечает «пока не сохраняется».
-- ==========================================================================

-- kind — вид титула, как в hunt_titles.kind и HUNT_TITLES_GRANTED (login,
-- hours, frost, hellfire). Титула у человека больше нет — выбор молча
-- игнорируется, главным становится первый.
CREATE TABLE IF NOT EXISTS title_picks (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  kind       VARCHAR(16)     NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
