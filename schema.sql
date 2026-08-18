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
  published_at BIGINT UNSIGNED NOT NULL,
  KEY idx_feed (published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
