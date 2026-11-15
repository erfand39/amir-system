CREATE TABLE IF NOT EXISTS settings (id VARCHAR(100) PRIMARY KEY,value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS users (id VARCHAR(64) PRIMARY KEY,name VARCHAR(120) NOT NULL,phone VARCHAR(24) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(24) NOT NULL DEFAULT 'customer',active INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(24) NOT NULL,updated_at VARCHAR(24) NOT NULL,CHECK(role IN ('customer','admin','manager')));
CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(64) PRIMARY KEY,user_id VARCHAR(64),csrf_token VARCHAR(96) NOT NULL,expires_at VARCHAR(24) NOT NULL,last_seen VARCHAR(24) NOT NULL,created_at VARCHAR(24) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS rate_limits (id VARCHAR(64) PRIMARY KEY,hits INTEGER NOT NULL,window_end VARCHAR(24) NOT NULL);
CREATE TABLE IF NOT EXISTS categories (id VARCHAR(64) PRIMARY KEY,title VARCHAR(160) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,meta_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS brands (id VARCHAR(64) PRIMARY KEY,title VARCHAR(160) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,meta_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS vehicles (id VARCHAR(64) PRIMARY KEY,title VARCHAR(160) NOT NULL,slug VARCHAR(190) NOT NULL UNIQUE,meta_json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS products (id VARCHAR(64) PRIMARY KEY,slug VARCHAR(190) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,description TEXT NOT NULL,category_id VARCHAR(64) NOT NULL,brand_id VARCHAR(64) NOT NULL,price_toman BIGINT NOT NULL,price_usd DECIMAL(12,2) NOT NULL DEFAULT 0,discount_usd DECIMAL(12,2) NOT NULL DEFAULT 0,pricing_mode VARCHAR(10) NOT NULL DEFAULT 'fixed',stock INTEGER NOT NULL DEFAULT 0,featured INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,warranty_months INTEGER,specs_json TEXT NOT NULL,images_json TEXT NOT NULL,tags_json TEXT NOT NULL,version INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(24) NOT NULL,updated_at VARCHAR(24) NOT NULL,FOREIGN KEY(category_id) REFERENCES categories(id),FOREIGN KEY(brand_id) REFERENCES brands(id),CHECK(price_toman>=0),CHECK(stock>=0),CHECK(pricing_mode IN ('fixed','usd')));
CREATE TABLE IF NOT EXISTS product_vehicles (id VARCHAR(140) PRIMARY KEY,product_id VARCHAR(64) NOT NULL,vehicle_id VARCHAR(64) NOT NULL,verified INTEGER NOT NULL DEFAULT 0,notes TEXT NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,UNIQUE(product_id,vehicle_id));
CREATE TABLE IF NOT EXISTS bundles (id VARCHAR(64) PRIMARY KEY,slug VARCHAR(190) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,description TEXT NOT NULL,discount_bps INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 0,meta_json TEXT NOT NULL,CHECK(discount_bps BETWEEN 0 AND 5000));
CREATE TABLE IF NOT EXISTS bundle_items (id VARCHAR(140) PRIMARY KEY,bundle_id VARCHAR(64) NOT NULL,product_id VARCHAR(64) NOT NULL,qty INTEGER NOT NULL,FOREIGN KEY(bundle_id) REFERENCES bundles(id) ON DELETE CASCADE,FOREIGN KEY(product_id) REFERENCES products(id),UNIQUE(bundle_id,product_id),CHECK(qty>0));
CREATE TABLE IF NOT EXISTS services (id VARCHAR(64) PRIMARY KEY,title VARCHAR(160) NOT NULL,price_toman BIGINT NOT NULL,active INTEGER NOT NULL DEFAULT 1,meta_json TEXT NOT NULL,CHECK(price_toman>=0));
CREATE TABLE IF NOT EXISTS coupons (id VARCHAR(64) PRIMARY KEY,code VARCHAR(64) NOT NULL UNIQUE,title VARCHAR(160) NOT NULL,type VARCHAR(16) NOT NULL,value BIGINT NOT NULL,min_purchase BIGINT NOT NULL DEFAULT 0,max_discount BIGINT,usage_limit INTEGER NOT NULL DEFAULT 100,used_count INTEGER NOT NULL DEFAULT 0,active INTEGER NOT NULL DEFAULT 1,starts_at VARCHAR(24),expires_at VARCHAR(24),CHECK(type IN ('percentage','fixed')),CHECK(value>=0),CHECK(used_count>=0),CHECK(used_count<=usage_limit));
CREATE TABLE IF NOT EXISTS addresses (id VARCHAR(64) PRIMARY KEY,user_id VARCHAR(64) NOT NULL,name VARCHAR(120) NOT NULL,phone VARCHAR(24) NOT NULL,province VARCHAR(80) NOT NULL,city VARCHAR(80) NOT NULL,address TEXT NOT NULL,postal_code VARCHAR(16) NOT NULL,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS orders (id VARCHAR(64) PRIMARY KEY,tracking_code VARCHAR(64) NOT NULL UNIQUE,user_id VARCHAR(64),guest_session_id VARCHAR(64),customer_name VARCHAR(120) NOT NULL,mobile VARCHAR(24) NOT NULL,province VARCHAR(80) NOT NULL,city VARCHAR(80) NOT NULL,address TEXT NOT NULL,postal_code VARCHAR(16) NOT NULL,status VARCHAR(32) NOT NULL,payment_method VARCHAR(24) NOT NULL,payment_status VARCHAR(24) NOT NULL DEFAULT 'unpaid',subtotal BIGINT NOT NULL,coupon_amount BIGINT NOT NULL,shipping_amount BIGINT NOT NULL,service_amount BIGINT NOT NULL,total_amount BIGINT NOT NULL,coupon_id VARCHAR(64),stock_released INTEGER NOT NULL DEFAULT 0,quote_json TEXT NOT NULL,created_at VARCHAR(24) NOT NULL,updated_at VARCHAR(24) NOT NULL,expires_at VARCHAR(24),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(coupon_id) REFERENCES coupons(id),CHECK(total_amount>=0),CHECK(subtotal>=0 AND coupon_amount>=0 AND coupon_amount<=subtotal AND shipping_amount>=0 AND service_amount>=0),CHECK(total_amount=subtotal-coupon_amount+shipping_amount+service_amount),CHECK(stock_released IN (0,1)),CHECK(status IN ('pending_payment','confirmed','processing','shipped','delivered','cancelled','expired','payment_review','refunded')),CHECK(payment_method IN ('online','cod','transfer')),CHECK(payment_status IN ('unpaid','paid','refunded')));
CREATE TABLE IF NOT EXISTS order_items (id VARCHAR(100) PRIMARY KEY,order_id VARCHAR(64) NOT NULL,product_id VARCHAR(64) NOT NULL,title VARCHAR(255) NOT NULL,qty INTEGER NOT NULL,unit_price BIGINT NOT NULL,line_total BIGINT NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id),FOREIGN KEY(product_id) REFERENCES products(id),CHECK(qty>0),CHECK(unit_price>=0),CHECK(line_total>=0));
CREATE TABLE IF NOT EXISTS stock_movements (id VARCHAR(100) PRIMARY KEY,product_id VARCHAR(64) NOT NULL,order_id VARCHAR(64),delta INTEGER NOT NULL,balance INTEGER NOT NULL,reason VARCHAR(40) NOT NULL,event_key VARCHAR(190) NOT NULL UNIQUE,actor_id VARCHAR(64),created_at VARCHAR(24) NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(order_id) REFERENCES orders(id),CHECK(balance>=0));
CREATE TABLE IF NOT EXISTS idempotency_keys (id VARCHAR(190) PRIMARY KEY,request_hash VARCHAR(64) NOT NULL,order_id VARCHAR(64) NOT NULL,created_at VARCHAR(24) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id));
CREATE TABLE IF NOT EXISTS payments (id VARCHAR(64) PRIMARY KEY,order_id VARCHAR(64) NOT NULL,authority VARCHAR(100) UNIQUE,reference VARCHAR(100) UNIQUE,amount BIGINT NOT NULL,status VARCHAR(24) NOT NULL,method VARCHAR(24) NOT NULL,created_at VARCHAR(24) NOT NULL,updated_at VARCHAR(24) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id),CHECK(amount>0));
CREATE TABLE IF NOT EXISTS ledger_entries (id VARCHAR(64) PRIMARY KEY,order_id VARCHAR(64),event_key VARCHAR(190) NOT NULL UNIQUE,description TEXT NOT NULL,created_at VARCHAR(24) NOT NULL,FOREIGN KEY(order_id) REFERENCES orders(id));
CREATE TABLE IF NOT EXISTS ledger_lines (id VARCHAR(100) PRIMARY KEY,entry_id VARCHAR(64) NOT NULL,account VARCHAR(32) NOT NULL,debit BIGINT NOT NULL DEFAULT 0,credit BIGINT NOT NULL DEFAULT 0,FOREIGN KEY(entry_id) REFERENCES ledger_entries(id),CHECK((debit>0 AND credit=0) OR (credit>0 AND debit=0)));
CREATE TABLE IF NOT EXISTS reviews (id VARCHAR(64) PRIMARY KEY,product_id VARCHAR(64) NOT NULL,user_id VARCHAR(64) NOT NULL,rating INTEGER NOT NULL,text TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',verified INTEGER NOT NULL DEFAULT 0,created_at VARCHAR(24) NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(user_id) REFERENCES users(id),UNIQUE(product_id,user_id),CHECK(rating BETWEEN 1 AND 5));
CREATE TABLE IF NOT EXISTS contacts (id VARCHAR(64) PRIMARY KEY,name VARCHAR(120) NOT NULL,phone VARCHAR(24) NOT NULL,email VARCHAR(190) NOT NULL,subject VARCHAR(120) NOT NULL,message TEXT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'new',created_at VARCHAR(24) NOT NULL);
CREATE TABLE IF NOT EXISTS warranties (id VARCHAR(64) PRIMARY KEY,serial VARCHAR(100) NOT NULL UNIQUE,product_id VARCHAR(64) NOT NULL,order_id VARCHAR(64),starts_at VARCHAR(24) NOT NULL,expires_at VARCHAR(24) NOT NULL,status VARCHAR(24) NOT NULL,FOREIGN KEY(product_id) REFERENCES products(id),FOREIGN KEY(order_id) REFERENCES orders(id));
CREATE TABLE IF NOT EXISTS articles (id VARCHAR(64) PRIMARY KEY,slug VARCHAR(190) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,summary TEXT NOT NULL,body TEXT NOT NULL,category VARCHAR(80) NOT NULL,published INTEGER NOT NULL DEFAULT 1,created_at VARCHAR(24) NOT NULL);
CREATE TABLE IF NOT EXISTS audit_logs (id VARCHAR(64) PRIMARY KEY,actor_id VARCHAR(64),action VARCHAR(80) NOT NULL,entity_id VARCHAR(100) NOT NULL,details_json TEXT NOT NULL,created_at VARCHAR(24) NOT NULL);
CREATE INDEX IF NOT EXISTS idx_orders_user_id ON orders(user_id);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_products_category_id ON products(category_id);
CREATE INDEX IF NOT EXISTS idx_products_brand_id ON products(brand_id);
CREATE INDEX IF NOT EXISTS idx_products_active ON products(active);
CREATE INDEX IF NOT EXISTS idx_products_price_toman ON products(price_toman);
CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);
CREATE INDEX IF NOT EXISTS idx_stock_movements_product_id ON stock_movements(product_id);
CREATE INDEX IF NOT EXISTS idx_reviews_status ON reviews(status);
CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_order_items_order_id ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_payments_order_id ON payments(order_id);
CREATE INDEX IF NOT EXISTS idx_ledger_lines_entry_id ON ledger_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_product_vehicles_vehicle_id ON product_vehicles(vehicle_id);
CREATE INDEX IF NOT EXISTS idx_orders_expiration ON orders(status,expires_at);
CREATE INDEX IF NOT EXISTS idx_reviews_product_status ON reviews(product_id,status);
CREATE INDEX IF NOT EXISTS idx_contacts_status_created ON contacts(status,created_at);
CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);

CREATE TABLE IF NOT EXISTS order_shipments (
  order_id VARCHAR(64) PRIMARY KEY,
  carrier VARCHAR(120) NOT NULL,
  tracking_code VARCHAR(100) NOT NULL,
  shipped_at DATETIME,
  delivered_at DATETIME,
  FOREIGN KEY(order_id) REFERENCES orders(id),
  UNIQUE(carrier,tracking_code)
);

CREATE TABLE IF NOT EXISTS order_transfer_receipts (
  order_id VARCHAR(64) PRIMARY KEY,
  reference VARCHAR(100) NOT NULL,
  submitted_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES orders(id)
);

CREATE TABLE IF NOT EXISTS stock_receipt_details (
  event_key VARCHAR(190) PRIMARY KEY,
  notes VARCHAR(500) NOT NULL,
  FOREIGN KEY(event_key) REFERENCES stock_movements(event_key)
);

CREATE TABLE IF NOT EXISTS product_inventory (
  product_id VARCHAR(64) PRIMARY KEY,
  sku VARCHAR(64) UNIQUE,
  shelf VARCHAR(120) NOT NULL DEFAULT '',
  reorder_level INTEGER NOT NULL DEFAULT 3,
  FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE,
  CHECK(reorder_level BETWEEN 0 AND 1000000)
);

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
);

CREATE TABLE IF NOT EXISTS shipment_exceptions (
  order_id VARCHAR(64) PRIMARY KEY,
  state VARCHAR(24) NOT NULL,
  notes VARCHAR(500) NOT NULL,
  updated_at DATETIME NOT NULL,
  FOREIGN KEY(order_id) REFERENCES order_shipments(order_id),
  CHECK(state IN ('failed','returned','resumed'))
);
