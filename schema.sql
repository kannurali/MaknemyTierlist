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
