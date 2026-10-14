-- Розыгрыши: вкладка /admin/giveaways, окно на страницах сайта и страница
-- /giveaways. Запускать один раз на боевой базе, после
-- 2026-09-23-telegram.sql (участие идёт только через привязанный бот).
--
-- Пока таблиц нет, окно не показывается, страница пишет «розыгрышей нет»,
-- а админка — «выполните миграцию». Логика — api/lib/giveaway.php.
--
-- После миграции: /admin/support → «Подключить вебхук» ещё раз (бот начинает
-- получать my_chat_member — так он узнаёт, в какие каналы его добавили, — и
-- chat_member — кто вступил по ссылке розыгрыша и кто отписался), и
-- добавить бота администратором в каналы, подписку на которые проверять,
-- с правом создавать пригласительные ссылки.

-- --------------------------------------------------------------------------
--  Розыгрыш
-- --------------------------------------------------------------------------
-- status: draft — черновик, его видит только админка; live — опубликован
-- (идёт, пока ends_at в будущем, потом ждёт кнопки «Разыграть»); drawn —
-- победители выбраны, розыгрыш в архиве.
--
-- body_json — описание в той же блочной модели, что тело новости
-- (api/lib/news_blocks.php), только абзацы, цитаты и списки.
-- prizes_json — [{"name": "Magnet", "count": 5}, …], порядок = порядок
-- розыгрыша. channels_json — [chat_id, …] из tg_channels; пусто — условий нет.
CREATE TABLE IF NOT EXISTS giveaways (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status        VARCHAR(8)      NOT NULL DEFAULT 'draft',
  title_ru      VARCHAR(120)    NOT NULL DEFAULT '',
  title_en      VARCHAR(120)    NOT NULL DEFAULT '',
  image_url     VARCHAR(80)     NOT NULL DEFAULT '',
  image_w       INT UNSIGNED    NOT NULL DEFAULT 0,
  image_h       INT UNSIGNED    NOT NULL DEFAULT 0,
  body_json     MEDIUMTEXT      NULL,
  prizes_json   TEXT            NULL,
  channels_json TEXT            NULL,
  ends_at       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    BIGINT UNSIGNED NOT NULL,
  updated_at    BIGINT UNSIGNED NOT NULL,
  published_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  drawn_at      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_status (status, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Участники
-- --------------------------------------------------------------------------
-- chat_id — Telegram, с которого человек вступил. Уникален в пределах
-- розыгрыша: один Telegram — одно участие, сколько бы аккаунтов сайта к нему
-- ни было привязано. prize — номер приза в prizes_json, ставится кнопкой
-- «Разыграть»; NULL — не выиграл (или розыгрыша ещё не было).
-- src — откуда человек пришёл участвовать: tg — по кнопке из сообщения
-- бота, pop — из окна на странице сайта, page — со страницы /giveaways.
-- Отписался от канала из условий — строка удаляется (участие снято).
CREATE TABLE IF NOT EXISTS giveaway_entries (
  giveaway_id INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  chat_id     BIGINT          NOT NULL,
  joined_at   BIGINT UNSIGNED NOT NULL,
  prize       SMALLINT        NULL,
  src         VARCHAR(8)      NOT NULL DEFAULT '',
  PRIMARY KEY (giveaway_id, user_id),
  UNIQUE KEY uq_chat (giveaway_id, chat_id),
  KEY idx_user (user_id),
  KEY idx_chat (chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Ссылки-приглашения розыгрыша — учёт подписчиков для рекламодателя
-- --------------------------------------------------------------------------
-- При публикации бот создаёт в каждом канале из условий свою ссылку
-- (createChatInviteLink, имя «Maknemy #<id>»), и кнопка «Подписаться» ведёт
-- на неё. Кто вступил по этой ссылке, Telegram сообщает вебхуку
-- (chat_member с invite_link) — так набирается список giveaway_subs.
-- Рекламодатель видит то же число в статистике этой ссылки у себя в канале.
CREATE TABLE IF NOT EXISTS giveaway_links (
  giveaway_id INT UNSIGNED    NOT NULL,
  channel_id  BIGINT          NOT NULL,
  invite_url  VARCHAR(128)    NOT NULL,
  created_at  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (giveaway_id, channel_id),
  UNIQUE KEY uq_url (invite_url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Подписчики, пришедшие по ссылке розыгрыша. left_at — когда отписался
-- (0 — подписан). tg_name — @логин или имя в Telegram на момент вступления.
CREATE TABLE IF NOT EXISTS giveaway_subs (
  giveaway_id INT UNSIGNED    NOT NULL,
  channel_id  BIGINT          NOT NULL,
  tg_user_id  BIGINT          NOT NULL,
  tg_name     VARCHAR(64)     NOT NULL DEFAULT '',
  joined_at   BIGINT UNSIGNED NOT NULL,
  left_at     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (giveaway_id, channel_id, tg_user_id),
  KEY idx_member (channel_id, tg_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Каналы, где бот — администратор
-- --------------------------------------------------------------------------
-- Строки появляются сами: Telegram шлёт вебхуку my_chat_member, когда бота
-- добавляют в канал или группу, повышают, понижают или удаляют. is_admin = 0
-- — бот больше не админ, проверять подписку там он не может.
-- invite_url — куда ведёт кнопка «Подписаться»: t.me/<username> у публичного
-- канала, ссылка-приглашение от exportChatInviteLink у приватного.
CREATE TABLE IF NOT EXISTS tg_channels (
  chat_id    BIGINT          NOT NULL PRIMARY KEY,
  title      VARCHAR(128)    NOT NULL DEFAULT '',
  username   VARCHAR(64)     NOT NULL DEFAULT '',
  kind       VARCHAR(16)     NOT NULL DEFAULT '',
  invite_url VARCHAR(255)    NOT NULL DEFAULT '',
  is_admin   TINYINT         NOT NULL DEFAULT 0,
  updated_at BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
