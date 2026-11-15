-- Import after the schema 3 to 4 upgrade. Safe to rerun.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_item_returns (
  id VARCHAR(64) PRIMARY KEY,
  order_item_id VARCHAR(100) NOT NULL,
  quantity INTEGER NOT NULL,
  reason VARCHAR(500) NOT NULL,
  item_condition VARCHAR(24),
  received_at DATETIME,
  refund_payment_id VARCHAR(64) UNIQUE,
  created_at DATETIME NOT NULL,
  FOREIGN KEY(order_item_id) REFERENCES order_items(id),
  FOREIGN KEY(refund_payment_id) REFERENCES payments(id),
  CHECK(quantity>0),
  CHECK((item_condition IS NULL AND received_at IS NULL) OR
        (item_condition IS NOT NULL AND received_at IS NOT NULL AND item_condition IN ('sellable','damaged')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE settings pack JOIN settings version ON version.id='schema_version' AND version.value='4'
  SET pack.value='"1.3.0"' WHERE pack.id='database_pack_version';
UPDATE settings SET value='5' WHERE id='schema_version' AND value='4';
COMMIT;
