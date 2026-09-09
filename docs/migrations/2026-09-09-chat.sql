-- Чаты. Запускать один раз на боевой базе, ПОСЛЕ schema.sql.
--
-- Своей таблицы пользователей чат не заводит: users уже есть (её создаёт
-- schema.sql вместе с входом через Roblox), ключ там roblox_id. Второй список
-- людей означал бы два ответа на вопрос «кто это» — ник в переписке и ник в
-- профиле разъехались бы при первой же правке.
--
-- Колонки status в users нет и не добавляется: её пришлось бы кому-то
-- проставлять и вовремя сбрасывать, а last_login_at уже пишется при каждом
-- входе (roblox_touch_user) и врать не может. «В сети» вычисляется из него —
-- см. CHAT_ONLINE_WINDOW в api/lib/chat.php.

-- --------------------------------------------------------------------------
--  Репутация
-- --------------------------------------------------------------------------
-- Два счётчика на странице профиля. Хранятся денормализованно рядом с
-- пользователем, а не считаются на лету: профиль открывают чаще, чем пишут
-- отзывы, и SUM по всем отзывам на каждый показ — лишняя работа.
--
-- Значения ПЕРЕСЧИТЫВАЮТСЯ целиком при каждом отзыве
-- (chat_recount_reputation), а не увеличиваются на единицу: правка оценки
-- меняет вклад с плюса на минус, и инкрементами это разъезжается.
ALTER TABLE users
  ADD COLUMN likes    INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN dislikes INT UNSIGNED NOT NULL DEFAULT 0;

-- --------------------------------------------------------------------------
--  Диалоги
-- --------------------------------------------------------------------------
-- Пара пользователей. Хранится нормализованно: a_id всегда МЕНЬШЕ b_id,
-- поэтому у пары ровно одна строка независимо от того, кто написал первым.
-- Без этого правила диалог (5,9) и (9,5) оказались бы разными, и переписка
-- расползлась бы по двум веткам.
--
-- last_at дублирует время последнего сообщения намеренно: список диалогов
-- сортируется по нему, и без денормализации каждый показ списка требовал бы
-- подзапроса MAX(created_at) по всем сообщениям.
--
-- Внешних ключей на users нет — как и везде в этой базе. Человек, удалённый
-- из users, просто выпадает из списка (chat_threads пропускает ветку без
-- собеседника), а не роняет чужую переписку каскадом.
CREATE TABLE IF NOT EXISTS chat_threads (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  a_id    BIGINT UNSIGNED NOT NULL,
  b_id    BIGINT UNSIGNED NOT NULL,
  last_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_pair (a_id, b_id),
  KEY idx_recent (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Сообщения
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS chat_messages (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id  INT UNSIGNED    NOT NULL,
  sender_id  BIGINT UNSIGNED NOT NULL,
  body       TEXT            NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_thread (thread_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Отзывы
-- --------------------------------------------------------------------------
-- В макете форма отзыва стоит ВНУТРИ чата, а не в профиле: репутация
-- набирается по итогам переписки со сделкой.
--
-- UNIQUE(thread_id, author_id): один отзыв на диалог от одного человека.
-- Повторная отправка правит прежний, а не добавляет второй — иначе репутацию
-- можно накрутить, отправив форму сто раз.
CREATE TABLE IF NOT EXISTS chat_reviews (
  id         INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
  thread_id  INT UNSIGNED     NOT NULL,
  author_id  BIGINT UNSIGNED  NOT NULL,
  target_id  BIGINT UNSIGNED  NOT NULL,
  stars      TINYINT UNSIGNED NOT NULL,
  body       TEXT,
  created_at BIGINT UNSIGNED  NOT NULL,
  UNIQUE KEY uniq_author_thread (thread_id, author_id),
  KEY idx_target (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
