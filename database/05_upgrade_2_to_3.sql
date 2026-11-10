-- Upgrade an existing Amir System schema 2 without reimporting the catalog.
-- This file is safe to rerun. Back up the database before the upgrade.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS order_shipments (
  order_id VARCHAR(64) PRIMARY KEY,
  carrier VARCHAR(120) NOT NULL,
  tracking_code VARCHAR(100) NOT NULL,
  shipped_at DATETIME,
  delivered_at DATETIME,
  FOREIGN KEY(order_id) REFERENCES orders(id),
  UNIQUE(carrier,tracking_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_transfer_receipts (
  order_id VARCHAR(64) PRIMARY KEY,
  reference VARCHAR(100) NOT NULL,
  submitted_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_receipt_details (
  event_key VARCHAR(190) COLLATE utf8mb4_bin PRIMARY KEY,
  notes VARCHAR(500) NOT NULL,
  FOREIGN KEY(event_key) REFERENCES stock_movements(event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
UPDATE settings pack JOIN settings version ON version.id='schema_version' AND version.value='2'
  SET pack.value='"1.1.0"' WHERE pack.id='database_pack_version';
UPDATE settings SET value='3' WHERE id='schema_version' AND value='2';
COMMIT;
