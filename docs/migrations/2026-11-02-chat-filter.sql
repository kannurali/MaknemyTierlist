-- Фильтр чата: список запрещённых слов и журнал срабатываний.
-- Запускать один раз на боевой базе.
--
-- Ссылки режутся и без этой миграции: правило зашито в api/lib/chat_filter.php
-- и списка не требует. Таблицы нужны для слов и для /admin/chat-filter.

-- word — как ввели в панели, со звёздочками: «слово», «слово*», «*слово*».
CREATE TABLE IF NOT EXISTS chat_filter_words (
  word       VARCHAR(64)     NOT NULL PRIMARY KEY,
  created_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Журнал для модераторов: кто отправил сообщение, на котором сработал фильтр.
-- Текста здесь нет: исходное сообщение лежит в chat_messages без изменений.
-- kinds — «link», «word» или «link,word». Старше 90 дней вычищается при записи.
CREATE TABLE IF NOT EXISTS chat_filter_hits (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED    NOT NULL,
  sender_id  BIGINT UNSIGNED NOT NULL,
  kinds      VARCHAR(16)     NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
