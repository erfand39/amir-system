-- Amir System cPanel database pack 1.1.0; fresh install only.
-- MySQL 8.0.21+ or MariaDB 10.6+. No CREATE DATABASE / USE / GRANT.
SET NAMES utf8mb4;
SET SESSION time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS settings (
  id VARCHAR(100) PRIMARY KEY,
  value MEDIUMTEXT NOT NULL,
  CHECK(JSON_VALID(value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(24) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(24) NOT NULL DEFAULT 'customer',
  active INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  CHECK(role IN ('customer','admin','manager')),
  CHECK(active IN (0,1)),
  CHECK(phone REGEXP '^09[0-9]{9}$'),
  CHECK(CHAR_LENGTH(password_hash)>=60),
  INDEX idx_users_role_active (role,active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(64),
  csrf_token VARCHAR(96) COLLATE utf8mb4_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_sessions_expires_at (expires_at),
  INDEX idx_sessions_user_id (user_id),
  INDEX idx_sessions_idle (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id VARCHAR(64) PRIMARY KEY,
  hits INTEGER NOT NULL,
  window_end DATETIME NOT NULL,
  CHECK(hits>=0),
  INDEX idx_rate_limits_window (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  meta_json MEDIUMTEXT NOT NULL,
  CHECK(JSON_VALID(meta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS brands (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  meta_json MEDIUMTEXT NOT NULL,
  CHECK(JSON_VALID(meta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  meta_json MEDIUMTEXT NOT NULL,
  CHECK(JSON_VALID(meta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(64) PRIMARY KEY,
  slug VARCHAR(190) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NOT NULL,
  category_id VARCHAR(64) NOT NULL,
  brand_id VARCHAR(64) NOT NULL,
  price_toman BIGINT NOT NULL,
  price_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,
  pricing_mode VARCHAR(10) NOT NULL DEFAULT 'fixed',
  stock INTEGER NOT NULL DEFAULT 0,
  featured INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  warranty_months INTEGER,
  specs_json MEDIUMTEXT NOT NULL,
  images_json MEDIUMTEXT NOT NULL,
  tags_json MEDIUMTEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY(category_id) REFERENCES categories(id),
  FOREIGN KEY(brand_id) REFERENCES brands(id),
  CHECK(price_toman>=0),
  CHECK(stock>=0),
  CHECK(pricing_mode IN ('fixed','usd')),
  INDEX idx_products_category_id (category_id),
  INDEX idx_products_brand_id (brand_id),
  INDEX idx_products_active (active),
  INDEX idx_products_price_toman (price_toman),
  CHECK(price_usd>=0 AND discount_usd>=0 AND discount_usd<=price_usd),
  CHECK(featured IN (0,1) AND active IN (0,1)),
  CHECK(warranty_months IS NULL OR warranty_months BETWEEN 0 AND 120),
  CHECK(version>=1),
  INDEX idx_products_browse (active,category_id,price_toman),
  INDEX idx_products_brand_browse (active,brand_id,price_toman),
  CHECK(JSON_VALID(specs_json)),
  CHECK(JSON_VALID(images_json)),
  CHECK(JSON_VALID(tags_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_vehicles (
  id VARCHAR(140) PRIMARY KEY,
  product_id VARCHAR(64) NOT NULL,
  vehicle_id VARCHAR(64) NOT NULL,
  verified INTEGER NOT NULL DEFAULT 0,
  notes MEDIUMTEXT NOT NULL,
  FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  UNIQUE(product_id,vehicle_id),
  INDEX idx_product_vehicles_vehicle_id (vehicle_id),
  CHECK(verified IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bundles (
  id VARCHAR(64) PRIMARY KEY,
  slug VARCHAR(190) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  description MEDIUMTEXT NOT NULL,
  discount_bps INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 0,
  meta_json MEDIUMTEXT NOT NULL,
  CHECK(discount_bps BETWEEN 0 AND 5000),
  CHECK(active IN (0,1)),
  CHECK(JSON_VALID(meta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bundle_items (
  id VARCHAR(140) PRIMARY KEY,
  bundle_id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  qty INTEGER NOT NULL,
  FOREIGN KEY(bundle_id) REFERENCES bundles(id) ON DELETE CASCADE,
  FOREIGN KEY(product_id) REFERENCES products(id),
  UNIQUE(bundle_id,product_id),
  CHECK(qty>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS services (
  id VARCHAR(64) PRIMARY KEY,
  title VARCHAR(160) NOT NULL,
  price_toman BIGINT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  meta_json MEDIUMTEXT NOT NULL,
  CHECK(price_toman>=0),
  CHECK(active IN (0,1)),
  CHECK(JSON_VALID(meta_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id VARCHAR(64) PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  title VARCHAR(160) NOT NULL,
  type VARCHAR(16) NOT NULL,
  value BIGINT NOT NULL,
  min_purchase BIGINT NOT NULL DEFAULT 0,
  max_discount BIGINT,
  usage_limit INTEGER NOT NULL DEFAULT 100,
  used_count INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  starts_at VARCHAR(24),
  expires_at VARCHAR(24),
  CHECK(type IN ('percentage','fixed')),
  CHECK(value>=0),
  CHECK(used_count>=0),
  CHECK(used_count<=usage_limit),
  CHECK(active IN (0,1)),
  CHECK(min_purchase>=0 AND (max_discount IS NULL OR max_discount>=0)),
  CHECK(type<>'percentage' OR value<=100),
  CHECK(usage_limit>=0),
  CHECK(starts_at IS NULL OR expires_at IS NULL OR starts_at<expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS addresses (
  id VARCHAR(64) PRIMARY KEY,
  user_id VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  province VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  address MEDIUMTEXT NOT NULL,
  postal_code VARCHAR(16) NOT NULL,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id VARCHAR(64) PRIMARY KEY,
  tracking_code VARCHAR(64) NOT NULL UNIQUE,
  user_id VARCHAR(64),
  guest_session_id VARCHAR(64),
  customer_name VARCHAR(120) NOT NULL,
  mobile VARCHAR(24) NOT NULL,
  province VARCHAR(80) NOT NULL,
  city VARCHAR(80) NOT NULL,
  address MEDIUMTEXT NOT NULL,
  postal_code VARCHAR(16) NOT NULL,
  status VARCHAR(32) NOT NULL,
  payment_method VARCHAR(24) NOT NULL,
  payment_status VARCHAR(24) NOT NULL DEFAULT 'unpaid',
  subtotal BIGINT NOT NULL,
  coupon_amount BIGINT NOT NULL,
  shipping_amount BIGINT NOT NULL,
  service_amount BIGINT NOT NULL,
  total_amount BIGINT NOT NULL,
  coupon_id VARCHAR(64),
  stock_released INTEGER NOT NULL DEFAULT 0,
  quote_json MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  expires_at DATETIME,
  FOREIGN KEY(user_id) REFERENCES users(id),
  FOREIGN KEY(coupon_id) REFERENCES coupons(id),
  CHECK(total_amount>=0),
  CHECK(subtotal>=0 AND coupon_amount>=0 AND coupon_amount<=subtotal AND shipping_amount>=0 AND service_amount>=0),
  CHECK(total_amount=subtotal-coupon_amount+shipping_amount+service_amount),
  CHECK(stock_released IN (0,1)),
  CHECK(status IN ('pending_payment','confirmed','processing','shipped','delivered','cancelled','expired','payment_review','refunded')),
  CHECK(payment_method IN ('online','cod','transfer')),
  CHECK(payment_status IN ('unpaid','paid','refunded')),
  INDEX idx_orders_user_id (user_id),
  INDEX idx_orders_status (status),
  INDEX idx_orders_created_at (created_at),
  INDEX idx_orders_expiration (status,expires_at),
  INDEX idx_orders_customer_date (user_id,created_at),
  INDEX idx_orders_guest (guest_session_id),
  CHECK(JSON_VALID(quote_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id VARCHAR(100) PRIMARY KEY,
  order_id VARCHAR(64) NOT NULL,
  product_id VARCHAR(64) NOT NULL,
  title VARCHAR(255) NOT NULL,
  qty INTEGER NOT NULL,
  unit_price BIGINT NOT NULL,
  line_total BIGINT NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id),
  FOREIGN KEY(product_id) REFERENCES products(id),
  CHECK(qty>0),
  CHECK(unit_price>=0),
  CHECK(line_total>=0),
  INDEX idx_order_items_order_id (order_id),
  CHECK(line_total<=unit_price*qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stock_movements (
  id VARCHAR(100) PRIMARY KEY,
  product_id VARCHAR(64) NOT NULL,
  order_id VARCHAR(64),
  delta INTEGER NOT NULL,
  balance INTEGER NOT NULL,
  reason VARCHAR(40) NOT NULL,
  event_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  actor_id VARCHAR(64),
  created_at DATETIME NOT NULL,
  FOREIGN KEY(product_id) REFERENCES products(id),
  FOREIGN KEY(order_id) REFERENCES orders(id),
  CHECK(balance>=0),
  INDEX idx_stock_movements_product_id (product_id),
  FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_stock_product_date (product_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  id VARCHAR(190) COLLATE utf8mb4_bin PRIMARY KEY,
  request_hash VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
  order_id VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id VARCHAR(64) PRIMARY KEY,
  order_id VARCHAR(64) NOT NULL,
  authority VARCHAR(100) COLLATE utf8mb4_bin UNIQUE,
  reference VARCHAR(100) COLLATE utf8mb4_bin UNIQUE,
  amount BIGINT NOT NULL,
  status VARCHAR(24) NOT NULL,
  method VARCHAR(24) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id),
  CHECK(amount>0),
  INDEX idx_payments_order_id (order_id),
  CHECK(status IN ('requesting','pending','unknown','paid','refunded','failed','cancelled')),
  CHECK(method IN ('online','cod','transfer','refund')),
  CHECK((method='refund' AND status='refunded') OR method<>'refund'),
  INDEX idx_payments_order_status (order_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_entries (
  id VARCHAR(64) PRIMARY KEY,
  order_id VARCHAR(64),
  event_key VARCHAR(190) COLLATE utf8mb4_bin NOT NULL UNIQUE,
  description MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger_lines (
  id VARCHAR(100) PRIMARY KEY,
  entry_id VARCHAR(64) NOT NULL,
  account VARCHAR(32) NOT NULL,
  debit BIGINT NOT NULL DEFAULT 0,
  credit BIGINT NOT NULL DEFAULT 0,
  FOREIGN KEY(entry_id) REFERENCES ledger_entries(id),
  CHECK((debit>0 AND credit=0) OR (credit>0 AND debit=0)),
  INDEX idx_ledger_lines_entry_id (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id VARCHAR(64) PRIMARY KEY,
  product_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NOT NULL,
  rating INTEGER NOT NULL,
  text MEDIUMTEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  verified INTEGER NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  FOREIGN KEY(product_id) REFERENCES products(id),
  FOREIGN KEY(user_id) REFERENCES users(id),
  UNIQUE(product_id,user_id),
  CHECK(rating BETWEEN 1 AND 5),
  INDEX idx_reviews_status (status),
  INDEX idx_reviews_product_status (product_id,status),
  CHECK(status IN ('pending','approved','rejected')),
  CHECK(verified IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id VARCHAR(64) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(24) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(120) NOT NULL,
  message MEDIUMTEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'new',
  created_at DATETIME NOT NULL,
  INDEX idx_contacts_status_created (status,created_at),
  CHECK(status IN ('new','read','answered'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS warranties (
  id VARCHAR(64) PRIMARY KEY,
  serial VARCHAR(100) NOT NULL UNIQUE,
  product_id VARCHAR(64) NOT NULL,
  order_id VARCHAR(64),
  starts_at VARCHAR(24) NOT NULL,
  expires_at VARCHAR(24) NOT NULL,
  status VARCHAR(24) NOT NULL,
  FOREIGN KEY(product_id) REFERENCES products(id),
  FOREIGN KEY(order_id) REFERENCES orders(id),
  CHECK(status IN ('active','void')),
  CHECK(starts_at<expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
  id VARCHAR(64) PRIMARY KEY,
  slug VARCHAR(190) NOT NULL UNIQUE,
  title VARCHAR(255) NOT NULL,
  summary MEDIUMTEXT NOT NULL,
  body MEDIUMTEXT NOT NULL,
  category VARCHAR(80) NOT NULL,
  published INTEGER NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  CHECK(published IN (0,1)),
  INDEX idx_articles_published_date (published,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id VARCHAR(64) PRIMARY KEY,
  actor_id VARCHAR(64),
  action VARCHAR(80) NOT NULL,
  entity_id VARCHAR(100) NOT NULL,
  details_json MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_logs_created_at (created_at),
  FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_entity (entity_id,created_at),
  CHECK(JSON_VALID(details_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
