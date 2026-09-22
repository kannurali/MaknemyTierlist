-- Трейдинг и центр обращений. Запускать один раз на боевой базе, ПОСЛЕ
-- schema.sql и миграций чата/профиля (2026-09-09-*.sql, 2026-09-10-last-seen.sql).
--
-- Своей таблицы пользователей трейдинг не заводит: автор объявления — это
-- users.roblox_id, тот же, что у чата и профиля.

-- --------------------------------------------------------------------------
--  Объявления
-- --------------------------------------------------------------------------
-- Одна строка — одно объявление с ленты /trading: что человек отдаёт и что
-- хочет взамен. Стороны хранятся JSON-массивом id предметов тирлиста
-- (["idmqeau8kci10et", ...], до четырёх на сторону, повторы допустимы — как
-- в калькуляторе). Названия и цены НЕ копируются: лента показывает текущие
-- цены тирлиста, а не те, что были в момент публикации.
--
-- status: open → done (сделка состоялась) | cancelled (автор снял) |
-- removed (снял администратор). VARCHAR, а не ENUM: тесты гоняются на
-- SQLite, и схемы обязаны вести себя одинаково.
--
-- replied_at — когда по объявлению впервые написали из чата (сообщение,
-- отправленное из чата, открытого с карточки). Без отклика объявление уходит
-- из ленты через четыре дня (TRADE_QUIET_TTL в api/lib/trade.php), с
-- откликом — через две недели (TRADE_TTL). Статус при этом не меняется:
-- истечение — не отмена, и в «отменённые» профиля оно не идёт.
CREATE TABLE IF NOT EXISTS trade_offers (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  give       VARCHAR(255)    NOT NULL,
  want       VARCHAR(255)    NOT NULL,
  status     VARCHAR(10)     NOT NULL DEFAULT 'open',
  created_at BIGINT UNSIGNED NOT NULL,
  replied_at BIGINT UNSIGNED NULL,
  closed_at  BIGINT UNSIGNED NULL,
  KEY idx_feed (status, id),
  KEY idx_quota (user_id, created_at),
  KEY idx_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Журнал сделок для профиля
-- --------------------------------------------------------------------------
-- Его читает api/profile-stats.php (график «успешно/отказ» и полоса
-- оборота). До трейдинга таблицу заводила только сеялка
-- tools/seed-profile-stats.php, на бою её не было. Теперь в неё пишет
-- закрытие объявления: «сделка состоялась» → status 'ok' и value = сумма
-- отданного по ценам тирлиста на момент закрытия, «отменить» → 'declined'.
CREATE TABLE IF NOT EXISTS profile_trades (
  id      INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  day     DATE            NOT NULL,
  status  VARCHAR(8)      NOT NULL,
  value   INT UNSIGNED    NOT NULL DEFAULT 0,
  KEY idx_user_day (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Центр обращений
-- --------------------------------------------------------------------------
-- Обращения со страницы /support. Писать может только вошедший через Roblox:
-- ответ приходит в личный чат сайта, и без аккаунта отвечать было бы некуда.
-- Администратор читает их на /admin/support.
CREATE TABLE IF NOT EXISTS support_tickets (
  id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  body       TEXT            NOT NULL,
  status     VARCHAR(10)     NOT NULL DEFAULT 'new',
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
