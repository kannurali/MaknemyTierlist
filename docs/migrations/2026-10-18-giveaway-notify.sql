-- Подписка на розыгрыши и напоминания бота. Запускать один раз на боевой
-- базе, после 2026-10-14-giveaways.sql.
--
-- Пока таблиц нет, всё как раньше: о старте розыгрыша бот пишет всем, кто
-- его подключил, напоминаний нет, а на /giveaways у вошедшего нет кнопки
-- «Настройки уведомлений» (tg_link.php отвечает gw: null).

-- --------------------------------------------------------------------------
--  Что человек хочет знать о розыгрышах
-- --------------------------------------------------------------------------
-- Строка есть, только если человек что-то менял: строки нет — подписан, без
-- напоминаний. Отдельно от tg_links по той же причине, что и tg_prefs:
-- переподключение Telegram пересоздаёт привязку, а выбор должен её пережить.
--
-- subscribed  — писать ли о новых розыгрышах и напоминать ли о конце;
-- time_left   — напомнить, когда до итогов останется столько секунд (0 — нет);
-- places_left — напомнить, когда до лимита участников останется столько мест
--               (0 — нет).
CREATE TABLE IF NOT EXISTS gw_prefs (
  user_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  subscribed  TINYINT(1)      NOT NULL DEFAULT 1,
  time_left   INT UNSIGNED    NOT NULL DEFAULT 0,
  places_left INT UNSIGNED    NOT NULL DEFAULT 0,
  updated_at  INT UNSIGNED    NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
--  Какие напоминания уже ушли
-- --------------------------------------------------------------------------
-- Одно напоминание каждого вида (time / places) на человека и розыгрыш.
-- Строка ставится до отправки: два прохода подряд второе сообщение не
-- пошлют.
CREATE TABLE IF NOT EXISTS gw_reminders (
  giveaway_id INT UNSIGNED    NOT NULL,
  user_id     BIGINT UNSIGNED NOT NULL,
  kind        VARCHAR(8)      NOT NULL,
  sent_at     INT UNSIGNED    NOT NULL,
  PRIMARY KEY (giveaway_id, user_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
