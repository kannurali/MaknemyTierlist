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
  about         VARCHAR(280) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
