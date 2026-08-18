-- Миграция таблицы news: заменяет три пресета размера картинки (image_size:
-- small/medium/full) на свободную ширину (image_pct, 10..100% ширины
-- карточки), выравнивание (image_align: left/center/right) и обтекание
-- текстом (image_wrap). Актуальна только для УЖЕ СУЩЕСТВУЮЩЕЙ базы —
-- schema.sql описывает лишь CREATE TABLE IF NOT EXISTS для чистой установки
-- и не запускается повторно поверх боевых данных; этот файл выполняется
-- вручную на проде (контроллер), Claude базу не трогает.

-- 1. Новые колонки с безопасными дефолтами — существующие строки остаются
--    читаемыми и валидными на всё время миграции.
ALTER TABLE news
  ADD COLUMN image_pct   TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER image_url,
  ADD COLUMN image_align VARCHAR(6) NOT NULL DEFAULT 'center' AFTER image_pct,
  ADD COLUMN image_wrap  TINYINT(1) NOT NULL DEFAULT 0 AFTER image_align;

-- 2. Перенос данных из старого пресета: small -> 30%, medium -> 55%,
--    full (и любое иное или NULL) -> 100%. У выравнивания и обтекания
--    раньше не было аналога, поэтому они остаются на дефолтах из шага 1
--    (center / выключено).
UPDATE news
   SET image_pct = CASE image_size
         WHEN 'small'  THEN 30
         WHEN 'medium' THEN 55
         ELSE 100
       END;

-- 3. Старая колонка-пресет больше не нужна.
ALTER TABLE news DROP COLUMN image_size;
