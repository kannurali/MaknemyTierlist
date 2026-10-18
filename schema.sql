-- Run once in phpMyAdmin (or `mysql < schema.sql`) on the production DB.
CREATE TABLE IF NOT EXISTS tierlist (
  id   TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  data LONGTEXT NOT NULL,
  rev  BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS likes (
  id    TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  count INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Advertising campaigns. A separate document from the tier list on purpose:
-- images inside tierlist.data are downscaled to 256 px, saving that blob is
-- last-write-wins, and it is served with a year-long immutable cache that a
-- creative swap would invalidate for every visitor.
--
-- api/promo.php degrades to an empty document if this table is absent, so the
-- site keeps working until this file is run.
CREATE TABLE IF NOT EXISTS promo (
  id   TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  data LONGTEXT NOT NULL,
  rev  BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO tierlist (id, data, rev) VALUES (1, '{}', 0);
INSERT IGNORE INTO likes (id, count) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS news (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category     VARCHAR(16) NOT NULL,
  title_ru     VARCHAR(200) NOT NULL,
  title_en     VARCHAR(200) NOT NULL DEFAULT '',
  body_ru      TEXT NOT NULL,
  -- Без DEFAULT: MySQL запрещает значение по умолчанию у TEXT (ERROR 1101), и с
  -- ним не создаётся вся таблица. SQLite такое проглатывает, поэтому тесты на
  -- нём этого не ловят — проверено только запуском schema.sql на живой MySQL.
  -- Значение всегда приходит из PHP: validate_news_post() кладёт в body_en
  -- пустую строку, если английского варианта нет, и INSERT биндит его всегда.
  body_en      TEXT NOT NULL,
  image_url    VARCHAR(255) NOT NULL DEFAULT '',
  -- Свободная ширина картинки (в процентах ширины карточки, 10..100),
  -- выравнивание и обтекание текстом — заменяют прежние три пресета
  -- image_size (small/medium/full). VARCHAR/TINYINT, а не TEXT: этим
  -- колонкам, как и image_url, нужен DEFAULT, а TEXT его в MySQL не
  -- принимает (см. комментарий у body_en выше — ровно та же ошибка
  -- ERROR 1101, только для другого столбца).
  image_pct    TINYINT UNSIGNED NOT NULL DEFAULT 100,
  image_align  VARCHAR(6) NOT NULL DEFAULT 'center',
  image_wrap   TINYINT(1) NOT NULL DEFAULT 0,
  -- Ширина/высота УЖЕ СОХРАНЁННОЙ (пересжатой) картинки — подсказка для
  -- <img width/height>, чтобы браузер зарезервировал место под неё до
  -- загрузки байтов. NULL без DEFAULT: у поста без картинки, и у поста,
  -- сохранённого до появления этих колонок, значения нет вообще — это не
  -- 0×0 (0 обнулил бы зарезервированную высоту), а "неизвестно", и
  -- cardFor() в news-page.js тогда просто не ставит атрибуты. NULL — не
  -- TEXT, так что DEFAULT здесь ничем не грозит (см. комментарий у
  -- body_en выше), но он и не нужен: не будь его — колонка и так NULL.
  image_width  SMALLINT UNSIGNED NULL,
  image_height SMALLINT UNSIGNED NULL,
  -- Структурированное тело поста: {"v":1,"blocks":[...]} — см.
  -- docs/superpowers/specs/2026-08-29-news-block-editor-design.md. NULL, а не
  -- '': пост, сохранённый до появления колонки, и пост без блоков — это одно
  -- и то же состояние «рисуй по-старому», и cardFor() в news-page.js
  -- обрабатывает их одинаково. Колонки body_ru/body_en/image_url при этом
  -- продолжают заполняться (сервер выводит их из блоков), поэтому og.php и
  -- SSR-мета работают без правок.
  body_json    LONGTEXT NULL,
  published_at BIGINT UNSIGNED NOT NULL,
  -- Анонимный лайк-счётчик поста — своя колонка, а не отдельная таблица:
  -- лента и так уже выбирает строку поста целиком (см. handle_news() в
  -- api/news.php), а join ради одного целого числа ничего бы не выиграл.
  -- INT UNSIGNED, а не TINYINT: у общего счётчика лайков (таблица likes
  -- выше) тот же тип — здесь тот же потолок ожидаем и по той же причине.
  likes        INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_feed (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Посетители, вошедшие через Roblox (api/roblox_callback.php). Пароля здесь
-- нет и быть не может: аутентификацию целиком делает Roblox, сайт хранит
-- только то, что показывает шапка.
--
-- Ключ — сам roblox_id (claim `sub` из userinfo), без своего AUTO_INCREMENT:
-- второго источника личности у нас нет, а лишний суррогатный id пришлось бы
-- всюду тащить рядом с настоящим. Токены Roblox не хранятся вовсе — они
-- нужны ровно на один запрос профиля в момент входа.
--
-- api/session.php переживает отсутствие этой таблицы: без неё никто просто
-- не считается вошедшим (как promo.php без своей таблицы).
CREATE TABLE IF NOT EXISTS users (
  roblox_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL DEFAULT '',
  display_name  VARCHAR(64)  NOT NULL DEFAULT '',
  avatar_url    VARCHAR(255) NOT NULL DEFAULT '',
  created_at    BIGINT UNSIGNED NOT NULL,
  last_login_at BIGINT UNSIGNED NOT NULL,
  -- Присутствие. Отдельно от last_login_at намеренно: вход пишется РАЗ, а
  -- сессия живёт долго, и по времени входа активный посетитель через час
  -- выглядит ушедшим. Эту колонку обновляет api/session.php — запрос, который
  -- шапка делает на каждой странице у каждого вошедшего, — не чаще раза в
  -- минуту (ROBLOX_SEEN_THROTTLE).
  --
  -- DEFAULT 0 — «ещё не отмечали». Профиль в этом случае откатывается на
  -- last_login_at, чтобы только что вошедший не выглядел офлайном.
  --
  -- Для уже созданной боевой базы колонку заводит миграция
  -- docs/migrations/2026-09-10-last-seen.sql; при чистой установке она не нужна.
  last_seen_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- Текст «о себе» со страницы профиля. Пишет его сам человек
  -- (POST /api/profile-about.php), длина ограничена и здесь, и в
  -- PROFILE_ABOUT_MAX. Для уже созданной боевой базы есть отдельная миграция
  -- docs/migrations/2026-09-09-profile.sql; при чистой установке она не нужна.
  --
  -- NULL — «человек ничего не написал». Пустая строка значила бы то же самое
  -- вторым способом, поэтому profile_about_save() кладёт именно NULL.
  about         VARCHAR(280) NULL DEFAULT NULL,
  -- Репутация из чатов: два счётчика, которые показывает профиль. Хранятся
  -- денормализованно рядом с пользователем, а не считаются на лету — профиль
  -- открывают чаще, чем пишут отзывы. Пересчитываются целиком при каждом
  -- отзыве (chat_recount_reputation), а не инкрементом: правка оценки меняет
  -- вклад с плюса на минус.
  --
  -- Для уже созданной боевой базы те же колонки заводит миграция
  -- docs/migrations/2026-09-09-chat.sql; при чистой установке она не нужна.
  likes         INT UNSIGNED NOT NULL DEFAULT 0,
  dislikes      INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- «Запомнить вход». Для уже созданной боевой базы ту же таблицу заводит
-- миграция docs/migrations/2026-09-24-login-tokens.sql.
-- Одна строка — один браузер, где человек вошёл через Roblox. Кука
-- nx_remember держит selector и секрет; в базе лежит только sha256 секрета,
-- и утёкшая таблица не даёт войти ни под кем. Строку гасит выход, истечение
-- срока (скользящие 180 дней) или одиннадцатое устройство того же человека.
-- Логика — api/lib/remember.php.
--
-- Пока таблицы нет, сайт работает как раньше: вход живёт до закрытия
-- браузера.
CREATE TABLE IF NOT EXISTS login_tokens (
  selector   CHAR(24)        NOT NULL PRIMARY KEY,
  token_hash CHAR(64)        NOT NULL,
  user_id    BIGINT UNSIGNED NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT UNSIGNED NOT NULL,
  KEY idx_user (user_id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Трейдинг и центр обращений. Для уже созданной боевой базы те же таблицы
-- заводит миграция docs/migrations/2026-09-23-trading.sql.
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

-- Бан из панели (/admin/bans и кнопка в чате). Для уже созданной боевой базы
-- ту же таблицу заводит миграция docs/migrations/2026-10-10-user-bans.sql.
-- Пока таблицы нет, банят только списком banned_ids в config.php.
-- until_at — конец бана, unix-время; 0 — навсегда. by_id — кто забанил.
CREATE TABLE IF NOT EXISTS user_bans (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  until_at   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  by_id      BIGINT UNSIGNED NOT NULL,
  reason     VARCHAR(200)    NOT NULL DEFAULT '',
  created_at BIGINT UNSIGNED NOT NULL,
  KEY idx_until (until_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Розыгрыши
-- --------------------------------------------------------------------------
-- Для уже созданной боевой базы те же таблицы заводит миграция
-- docs/migrations/2026-10-14-giveaways.sql. Логика — api/lib/giveaway.php.

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
-- conds_json — свои условия [{"ru", "en", "url", "btn_ru", "btn_en"}, …]: текст,
-- ссылка и надпись на кнопке (пусто — «Подписаться»); бот их не проверяет
-- (docs/migrations/2026-10-15-giveaway-conds.sql).
-- test — 1 у тестового: на сайте его видит только открывший /giveaways?test,
-- бот о старте не пишет (docs/migrations/2026-10-15-giveaway-test.sql).
-- max_entries — лимит участников, 0 — без лимита. Последний участник
-- закрывает приём: ends_at становится «сейчас»
-- (docs/migrations/2026-10-15-giveaway-max-entries.sql).
-- drop_unsub — гарантия: 1 — отписавшийся от канала условий выбывает из числа
-- участников и освобождает место в лимите; 0 — остаётся в числе участников и
-- занимает место. Выиграть он не может в обоих случаях
-- (docs/migrations/2026-10-18-giveaway-unsub.sql).
CREATE TABLE IF NOT EXISTS giveaways (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
  status        VARCHAR(8)      NOT NULL DEFAULT 'draft',
  test          TINYINT(1)      NOT NULL DEFAULT 0,
  title_ru      VARCHAR(120)    NOT NULL DEFAULT '',
  title_en      VARCHAR(120)    NOT NULL DEFAULT '',
  image_url     VARCHAR(80)     NOT NULL DEFAULT '',
  image_w       INT UNSIGNED    NOT NULL DEFAULT 0,
  image_h       INT UNSIGNED    NOT NULL DEFAULT 0,
  body_json     MEDIUMTEXT      NULL,
  prizes_json   TEXT            NULL,
  channels_json TEXT            NULL,
  conds_json    TEXT            NULL,
  drop_unsub    TINYINT(1)      NOT NULL DEFAULT 1,
  max_entries   INT UNSIGNED    NOT NULL DEFAULT 0,
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
-- left_at — когда человек отписался от канала условий, 0 — подписан. Строка
-- остаётся следом того, что он вступал подписанным, но в розыгрыш не входит;
-- в числе участников — только у розыгрыша без гарантии (drop_unsub = 0).
-- checked_at — когда бот сам последний раз перепроверил подписку
-- (docs/migrations/2026-10-18-giveaway-unsub.sql).
CREATE TABLE IF NOT EXISTS giveaway_entries (
  giveaway_id INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  chat_id     BIGINT          NOT NULL,
  joined_at   BIGINT UNSIGNED NOT NULL,
  prize       SMALLINT        NULL,
  src         VARCHAR(8)      NOT NULL DEFAULT '',
  left_at     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  checked_at  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (giveaway_id, user_id),
  UNIQUE KEY uq_chat (giveaway_id, chat_id),
  KEY idx_user (user_id),
  KEY idx_chat (chat_id),
  KEY idx_check (left_at, checked_at)
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

-- Показы и переходы карточек розыгрыша по местам сайта (js/gw-card.js →
-- api/gw_stat.php). Места и смысл колонок — в
-- docs/migrations/2026-10-18-giveaway-card-stats.sql.
CREATE TABLE IF NOT EXISTS gw_card_stats (
  giveaway_id INT UNSIGNED NOT NULL,
  place       VARCHAR(8)   NOT NULL,
  views       INT UNSIGNED NOT NULL DEFAULT 0,
  clicks      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (giveaway_id, place)
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

-- Лайки только вошедшим, один на аккаунт: кто уже голосовал за тирлист
-- ('tierlist') или за пост ('news:<id>'). Счётчики остаются в likes.count и
-- news.likes. Для боевой базы — docs/migrations/2026-10-14-like-votes.sql.
CREATE TABLE IF NOT EXISTS like_votes (
  user_id    BIGINT UNSIGNED NOT NULL,
  target     VARCHAR(32)     NOT NULL,
  created_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
