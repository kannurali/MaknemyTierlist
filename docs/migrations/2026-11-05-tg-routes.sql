-- Вкладка «Каналы» (/admin/channels): что бот сам публикует в каком канале.
-- Запускать один раз на боевой базе, после 2026-10-27-giveaway-posts.sql.
--
-- Строка = в этот чат идёт эта тема. Нет строки — чат молчит. Без таблицы посты
-- в каналы молчат, а новый розыгрыш отмечает каналы прошлого, как раньше.
--
-- topic     — prices, stock, news, giveaways, events (TG_ROUTE_TOPICS).
-- posted_at — когда вышел последний пост. У цен это начало окна склейки: правки
--             его не двигают.
-- failed_at — когда Telegram отказал или промолчал; error — что он ответил,
--             пусто — промолчал. Вышедший пост это стирает.
-- msg_id    — id первого сообщения последнего поста: ссылка на вкладке.
-- msg_log   — у цен: сообщения поста и какие изменения в нём перечислены, JSON.
-- last_ref  — у ивента: какой пост уже вышел здесь, чтобы cron не повторял.
CREATE TABLE IF NOT EXISTS tg_routes (
  chat_id   BIGINT          NOT NULL,
  topic     VARCHAR(16)     NOT NULL,
  posted_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  failed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
  error     VARCHAR(190)    NOT NULL DEFAULT '',
  msg_id    BIGINT          NOT NULL DEFAULT 0,
  msg_log   MEDIUMTEXT      NULL,
  last_ref  VARCHAR(64)     NOT NULL DEFAULT '',
  PRIMARY KEY (chat_id, topic),
  KEY idx_topic (topic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Колонка «Розыгрыши» начинается с того, что и так отмечалось у нового
-- розыгрыша: с каналов последнего розыгрыша с постом.
INSERT IGNORE INTO tg_routes (chat_id, topic)
SELECT chat_id, 'giveaways' FROM giveaway_posts
 WHERE giveaway_id = (SELECT MAX(giveaway_id) FROM giveaway_posts);
