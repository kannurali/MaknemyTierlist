-- Фото в чате и жалобы на них. Запускать один раз на боевой базе.
--
-- До этой миграции кнопки фото в чате нет, остальная переписка работает как раньше.
--
-- Файлы лежат не в базе и не в public_html: каталог chat-photos рядом с config.php
-- (chat_photos_dir в config.php переопределяет). PHP заводит его сам.
--
-- file        — случайное имя файла; миниатюра рядом, с суффиксом .t
-- bytes       — сколько занимают оба файла: по сумме считается потолок на диске
-- reported_at — когда получатель пожаловался, 0 — жалобы нет
-- reviewed_at — когда модератор разобрал жалобу, 0 — ещё не разобрал
-- removed_at  — когда файл стёрт модератором или уборкой через 30 дней, 0 — файл на месте
CREATE TABLE IF NOT EXISTS chat_photos (
  id          INT UNSIGNED      NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id   INT UNSIGNED      NOT NULL,
  sender_id   BIGINT UNSIGNED   NOT NULL,
  file        VARCHAR(64)       NOT NULL,
  w           SMALLINT UNSIGNED NOT NULL,
  h           SMALLINT UNSIGNED NOT NULL,
  bytes       INT UNSIGNED      NOT NULL,
  created_at  BIGINT UNSIGNED   NOT NULL,
  reported_at BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  reviewed_at BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  reviewed_by BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  removed_at  BIGINT UNSIGNED   NOT NULL DEFAULT 0,
  KEY idx_sender (sender_id, created_at),
  KEY idx_thread (thread_id, sender_id),
  KEY idx_reports (reported_at, reviewed_at),
  KEY idx_sweep (removed_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
