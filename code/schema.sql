-- =====================================================
-- Hi-Service Chatbot — schema.sql v1
-- Target: MariaDB 10.6 / MySQL 8 · InnoDB · utf8mb4
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS event_log;
DROP TABLE IF EXISTS payments;
DROP TABLE IF EXISTS conversations;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS order_lines;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS slots;
DROP TABLE IF EXISTS delivery_zones;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS addresses;
DROP TABLE IF EXISTS customers;
DROP TABLE IF EXISTS oauth_tokens;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------
-- users — admin login
-- -----------------------------------------------------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(128),
  role ENUM('admin','operator','viewer') DEFAULT 'admin',
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- oauth_tokens — caches Xero/etc access tokens
-- -----------------------------------------------------
CREATE TABLE oauth_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(32) NOT NULL,
  access_token TEXT,
  refresh_token TEXT,
  expires_at TIMESTAMP NULL,
  meta JSON,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- customers — synced from Xero, looked up by phone
-- -----------------------------------------------------
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  xero_contact_id VARCHAR(64) UNIQUE,
  phone VARCHAR(20) NOT NULL UNIQUE,
  full_name VARCHAR(255),
  email VARCHAR(255),
  status ENUM('active','archived','blocked') DEFAULT 'active',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- addresses — one customer can have many
-- -----------------------------------------------------
CREATE TABLE addresses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  type ENUM('street','postal') DEFAULT 'street',
  line1 VARCHAR(255) NOT NULL,
  line2 VARCHAR(255),
  city VARCHAR(128),
  postal_code VARCHAR(10),
  is_default TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
  INDEX idx_postal (postal_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- products — synced from Xero Items, filtered by is_active
-- -----------------------------------------------------
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  xero_item_id VARCHAR(64) UNIQUE,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT,
  price DECIMAL(12,2) NOT NULL,
  in_stock_qty INT DEFAULT 0,
  is_tracked TINYINT(1) DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  category VARCHAR(64) DEFAULT 'gas',
  sort_order INT DEFAULT 100,
  image_url VARCHAR(512),
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active_sort (is_active, sort_order),
  INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- delivery_zones — replaces "Delivery Locations List" sheet
-- -----------------------------------------------------
CREATE TABLE delivery_zones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  postal_code VARCHAR(10) NOT NULL,
  suburb VARCHAR(128),
  city VARCHAR(128),
  is_active TINYINT(1) DEFAULT 1,
  notes VARCHAR(255),
  UNIQUE KEY uniq_postal_suburb (postal_code, suburb)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- slots — replaces Google Sheet, prevents race conditions
-- -----------------------------------------------------
CREATE TABLE slots (
  id INT AUTO_INCREMENT PRIMARY KEY,
  delivery_date DATE NOT NULL,
  time_block ENUM('08:00-12:00','13:00-16:30') NOT NULL,
  capacity INT NOT NULL DEFAULT 6,
  booked_count INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  notes VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_date_block (delivery_date, time_block),
  INDEX idx_date (delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- orders
-- -----------------------------------------------------
CREATE TABLE orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_reference VARCHAR(32) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  address_id INT,
  slot_id INT,
  channel ENUM('whatsapp','web') NOT NULL,
  status ENUM('cart','pending_payment','paid','delivered','cancelled','failed') DEFAULT 'cart',
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payfast_payment_id VARCHAR(64),
  xero_invoice_id VARCHAR(64),
  xero_invoice_number VARCHAR(64),
  paid_at TIMESTAMP NULL,
  delivered_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (address_id) REFERENCES addresses(id),
  FOREIGN KEY (slot_id) REFERENCES slots(id),
  INDEX idx_status (status),
  INDEX idx_channel (channel),
  INDEX idx_paid_at (paid_at),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- order_lines
-- -----------------------------------------------------
CREATE TABLE order_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  qty INT NOT NULL,
  unit_price DECIMAL(12,2) NOT NULL,
  line_total DECIMAL(12,2) NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- sessions — WhatsApp conversation state (replaces n8n Data Table)
-- -----------------------------------------------------
CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL UNIQUE,
  mode ENUM('menu','ordering','general_help','human') NULL,
  current_step VARCHAR(64),
  customer_id INT,
  current_order_id INT,
  state_data JSON,
  expires_at TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  FOREIGN KEY (current_order_id) REFERENCES orders(id) ON DELETE SET NULL,
  INDEX idx_expires (expires_at),
  INDEX idx_mode (mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- conversations — full message log, every channel
-- -----------------------------------------------------
CREATE TABLE conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) NOT NULL,
  direction ENUM('in','out') NOT NULL,
  channel ENUM('whatsapp','web','ghl') NOT NULL,
  message_text TEXT,
  payload_json JSON,
  mode VARCHAR(32),
  current_step VARCHAR(64),
  intent VARCHAR(64),
  taken_over_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_phone_created (phone, created_at),
  INDEX idx_channel (channel),
  INDEX idx_takeover (taken_over_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- payments — PayFast ITN records
-- -----------------------------------------------------
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  payfast_payment_id VARCHAR(64),
  amount DECIMAL(12,2),
  status ENUM('pending','complete','failed','cancelled') DEFAULT 'pending',
  signature_received VARCHAR(64),
  signature_computed VARCHAR(64),
  raw_payload JSON,
  verified_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (order_id) REFERENCES orders(id),
  INDEX idx_payfast_id (payfast_payment_id),
  INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- event_log — audit trail of admin actions + integrations
-- -----------------------------------------------------
CREATE TABLE event_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT,
  action VARCHAR(64) NOT NULL,
  entity_type VARCHAR(32),
  entity_id VARCHAR(64),
  payload JSON,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_action (action),
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================
-- SEED DATA
-- =====================================================

-- One admin user (password gets set via tools/create-admin.php)
INSERT INTO users (username, password_hash, display_name, role) VALUES
  ('shawn', '$2y$12$REPLACE_VIA_create_admin_php_TOOL_AFTER_DEPLOY', 'Shawn Lochner', 'admin');

-- Cape Town / Helderberg / Overberg delivery zones (from existing n8n + hi-servicegas.co.za)
INSERT INTO delivery_zones (postal_code, suburb, city, is_active) VALUES
  ('7140', 'Strand', 'Cape Town', 1),
  ('7150', 'Somerset West', 'Cape Town', 1),
  ('7130', 'Gordons Bay', 'Cape Town', 1),
  ('7195', 'Sir Lowry''s Pass', 'Cape Town', 1),
  ('7975', 'Pringle Bay', 'Overberg', 1),
  ('7194', 'Rooi Els', 'Overberg', 1),
  ('7141', 'Lwandle', 'Cape Town', 1),
  ('7100', 'Stellenbosch', 'Cape Town', 1),
  ('7195', 'Betty''s Bay', 'Overberg', 1),
  ('7195', 'Kleinmond', 'Overberg', 1);

-- Pre-create empty slots for next 14 days (cron will roll this forward)
INSERT INTO slots (delivery_date, time_block, capacity, is_active)
SELECT
  DATE_ADD(CURDATE(), INTERVAL n DAY) AS delivery_date,
  block AS time_block,
  6 AS capacity,
  1 AS is_active
FROM
  (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6
   UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13) days
CROSS JOIN
  (SELECT '08:00-12:00' block UNION SELECT '13:00-16:30') blocks
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

-- Catalog placeholder products (Xero sync will replace these on Day 6)
INSERT INTO products (code, name, price, is_active, category, sort_order) VALUES
  ('LPG-5KG', '5kg LPG Gas Delivered — Incl. Exchange Cylinder', 223.00, 1, 'gas', 10),
  ('LPG-9KG', '9kg LPG Gas Delivered — Incl. Exchange Cylinder', 385.00, 1, 'gas', 20),
  ('LPG-19KG', '19kg LPG Gas Delivered — Incl. Exchange Cylinder', 790.00, 1, 'gas', 30),
  ('LPG-48KG', '48kg LPG Gas Delivered — Incl. Exchange Cylinder', 1950.00, 1, 'gas', 40);

-- =====================================================
-- end schema.sql
-- =====================================================
