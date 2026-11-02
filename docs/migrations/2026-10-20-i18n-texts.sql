-- Переводы контента на языки сайта, кроме русского и английского у новостей
-- и розыгрышей (их EN живёт в своих колонках). Запускать один раз на боевой
-- базе, потом заполнить уже опубликованное:
--   php tools/i18n_backfill.php --config=/home/maknemyt/config.php
--
-- Пока таблицы нет, сайт работает как до неё: на нерусских страницах — EN,
-- без него RU; фоновый перевод после сохранения в админке молча ничего не
-- делает. Логика — public_html/api/lib/i18n_content.php.
--
-- kind     — news (пост), gw (розыгрыш), item (предмет тирлиста);
-- ref_id   — id поста или розыгрыша, у предмета — его id из тирлиста;
-- field    — какое место записи: title; b3 — абзац, цитата или подпись
--            картинки блока 3; b3.1 — пункт 1 списка в блоке 3; p0 — абзац
--            поста старого формата; c2 и c2.btn — текст и кнопка своего
--            условия розыгрыша; desc и terms — описание и условия предмета;
-- lang     — язык перевода (de, fr, es, it, pt, pl, tr, uk; у предмета ещё
--            en, когда ручного EN нет);
-- src_hash — sha1 русского исходника: перевод прежней версии текста не
--            показывается, а догоняется заново;
-- text     — перевод: строка, у полей b… — JSON спанов (с жирным, ссылками).
CREATE TABLE IF NOT EXISTS i18n_texts (
  kind       VARCHAR(8)      NOT NULL,
  ref_id     VARCHAR(40)     NOT NULL,
  field      VARCHAR(16)     NOT NULL,
  lang       CHAR(2)         NOT NULL,
  src_hash   CHAR(40)        NOT NULL,
  text       MEDIUMTEXT      NOT NULL,
  updated_at BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (kind, ref_id, field, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
