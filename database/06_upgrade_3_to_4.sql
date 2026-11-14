-- Import into an existing Amir System schema 3 after backup. Safe to rerun.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS product_inventory (
  product_id VARCHAR(64) PRIMARY KEY,
  sku VARCHAR(64) UNIQUE,
  shelf VARCHAR(120) NOT NULL DEFAULT '',
  reorder_level INTEGER NOT NULL DEFAULT 3,
  FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
  CHECK(reorder_level BETWEEN 0 AND 1000000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE settings pack JOIN settings version ON version.id='schema_version' AND version.value='3'
  SET pack.value='"1.2.0"' WHERE pack.id='database_pack_version';
UPDATE settings SET value='4' WHERE id='schema_version' AND value='3';
COMMIT;
