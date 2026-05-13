-- =====================================================
-- Migration 005 — Driver accounts + delivery assignments
-- =====================================================

CREATE TABLE IF NOT EXISTS drivers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(128) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(255),
  vehicle_reg VARCHAR(32),
  pin_hash VARCHAR(255) NOT NULL,
  avatar_color VARCHAR(7) DEFAULT '#0f7a52',
  is_active TINYINT(1) DEFAULT 1,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE orders
  ADD COLUMN assigned_driver_id INT NULL AFTER slot_id,
  ADD COLUMN driver_notes TEXT NULL AFTER xero_invoice_number,
  ADD COLUMN delivery_proof_url VARCHAR(512) NULL AFTER driver_notes,
  ADD CONSTRAINT fk_orders_driver FOREIGN KEY (assigned_driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
  ADD INDEX idx_assigned_driver (assigned_driver_id);

-- Seed 3 demo drivers (PIN = 1234 for all)
-- bcrypt of '1234' generated server-side after migration
INSERT IGNORE INTO drivers (name, phone, email, vehicle_reg, pin_hash, avatar_color) VALUES
  ('Daniel Mokoena',   '27821234001', 'daniel@hiservice.co.za',   'CA 145-678', 'PIN_REPLACE_DANIEL',   '#d62828'),
  ('Pieter Joubert',   '27821234002', 'pieter@hiservice.co.za',   'CA 245-789', 'PIN_REPLACE_PIETER',   '#0f7a52'),
  ('Sipho Khumalo',    '27821234003', 'sipho@hiservice.co.za',    'CA 345-890', 'PIN_REPLACE_SIPHO',    '#7c3aed');

-- Refresh the orders_report view to include driver info
DROP VIEW IF EXISTS orders_report;
CREATE VIEW orders_report AS
SELECT
  o.id, o.order_reference, o.channel, o.status, o.is_demo, o.total_amount,
  o.paid_at, o.created_at, o.delivered_at, o.driver_notes, o.assigned_driver_id,
  c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
  a.line1 AS addr_line1, a.line2 AS addr_line2, a.city AS addr_city, a.postal_code AS addr_postal,
  s.delivery_date, s.time_block,
  d.name AS driver_name, d.phone AS driver_phone,
  (SELECT GROUP_CONCAT(CONCAT(ol.qty, ' × ', ol.product_name) SEPARATOR ' · ')
   FROM order_lines ol WHERE ol.order_id = o.id) AS items_summary
FROM orders o
LEFT JOIN customers c ON c.id = o.customer_id
LEFT JOIN addresses a ON a.id = o.address_id
LEFT JOIN slots     s ON s.id = o.slot_id
LEFT JOIN drivers   d ON d.id = o.assigned_driver_id;
