-- Удаление диалога у себя. Запускать один раз на боевой базе, ПОСЛЕ
-- 2026-09-09-chat.sql (нужны chat_threads и chat_messages).
--
-- Пока таблицы нет, чат работает как раньше, а кнопка удаления честно
-- отвечает «не получилось»: chat_cleared() в api/lib/chat.php переживает её
-- отсутствие, chat_clear() отвечает 503.

-- --------------------------------------------------------------------------
--  Что человек удалил у себя
-- --------------------------------------------------------------------------
-- Одна строка — одна ветка одного участника. Сообщения с id не больше
-- cleared_id этот участник больше не видит, а ветка пропадает из его списка,
-- пока в ней не появится новое сообщение. Сами сообщения не удаляются:
-- удаление одностороннее, у собеседника переписка остаётся.
--
-- cleared_id назад не двигается (chat_clear): повторное удаление из старой
-- вкладки не возвращает уже удалённое.
CREATE TABLE IF NOT EXISTS chat_clears (
  thread_id  INT UNSIGNED    NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  cleared_id INT UNSIGNED    NOT NULL DEFAULT 0,
  cleared_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (thread_id, user_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
