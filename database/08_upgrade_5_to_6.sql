-- Import after the schema 4 to 5 upgrade. Safe to rerun.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS shipment_exceptions (
  order_id VARCHAR(64) PRIMARY KEY,
  state VARCHAR(24) NOT NULL,
  notes VARCHAR(500) NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES order_shipments(order_id),
  CHECK(state IN ('failed','returned','resumed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE settings pack JOIN settings version ON version.id='schema_version' AND version.value='5'
  SET pack.value='"1.4.0"' WHERE pack.id='database_pack_version';
UPDATE settings SET value='6' WHERE id='schema_version' AND value='5';
COMMIT;
