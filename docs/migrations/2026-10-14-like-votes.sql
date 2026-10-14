-- Лайки только вошедшим, один на аккаунт. Запускать один раз на боевой базе.
--
-- Строка — голос человека за цель: 'tierlist' (общий счётчик likes.count) или
-- 'news:<id>' (счётчик news.likes этого поста). Сами счётчики остаются, где
-- были, и с прежними числами: таблица только помнит, кто уже лайкнул, чтобы
-- второй голос с того же аккаунта не прошёл, а снять можно было только свой.
-- Логика — api/lib/likes.php.
--
-- Пока таблицы нет, кнопки лайка отвечают 503 и счётчики не меняются.

CREATE TABLE IF NOT EXISTS like_votes (
  user_id    BIGINT UNSIGNED NOT NULL,
  target     VARCHAR(32)     NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
