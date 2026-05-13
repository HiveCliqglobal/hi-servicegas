-- =====================================================
-- Migration 003 — Admin features (delivery zones, products, audit, reports)
-- =====================================================

-- 1. Expand delivery_zones to match the Hi-Service Excel data structure.
ALTER TABLE delivery_zones
  ADD COLUMN po_box_code  VARCHAR(10) NULL AFTER postal_code,
  ADD COLUMN municipality VARCHAR(128) NULL AFTER city;

-- 2. Track the source of every event for audit reporting.
ALTER TABLE event_log
  ADD COLUMN source ENUM('admin','whatsapp','web','agent','cron','api','unknown') NOT NULL DEFAULT 'unknown' AFTER action,
  ADD INDEX idx_source (source);

ALTER TABLE agent_activity
  ADD COLUMN source ENUM('admin','whatsapp','web','agent','cron','api','unknown') NOT NULL DEFAULT 'agent' AFTER kind,
  ADD INDEX idx_source (source);

-- 3. Reporting view — orders flattened with customer + slot for fast querying
CREATE OR REPLACE VIEW orders_report AS
SELECT
  o.id, o.order_reference, o.channel, o.status, o.total_amount,
  o.paid_at, o.created_at, o.delivered_at,
  c.full_name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
  a.line1 AS addr_line1, a.line2 AS addr_line2, a.city AS addr_city, a.postal_code AS addr_postal,
  s.delivery_date, s.time_block,
  (SELECT GROUP_CONCAT(CONCAT(ol.qty, ' × ', ol.product_name) SEPARATOR ' · ')
   FROM order_lines ol WHERE ol.order_id = o.id) AS items_summary
FROM orders o
LEFT JOIN customers c ON c.id = o.customer_id
LEFT JOIN addresses a ON a.id = o.address_id
LEFT JOIN slots     s ON s.id = o.slot_id;

-- 4. Helpful index for date-range reports
ALTER TABLE orders ADD INDEX idx_paid_at_status (paid_at, status);
