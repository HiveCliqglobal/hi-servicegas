-- =====================================================
-- Migration 004 — Demo flag + better slot tracking
-- =====================================================

-- 1. Mark demo orders separately from live revenue
ALTER TABLE orders
  ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER channel,
  ADD INDEX idx_demo (is_demo);

-- 2. Backfill: anything with "test", "demo", "ghltest" in customer name/email is a demo
UPDATE orders o
  JOIN customers c ON c.id = o.customer_id
   SET o.is_demo = 1
 WHERE LOWER(c.full_name) LIKE '%test%'
    OR LOWER(c.full_name) LIKE '%demo%'
    OR LOWER(c.email)     LIKE '%test%'
    OR LOWER(c.email)     LIKE '%demo%'
    OR LOWER(c.email)     LIKE '%hivecliq%'
    OR LOWER(c.email)     LIKE '%example.com%';

-- 3. Recreate the orders_report view to include is_demo
DROP VIEW IF EXISTS orders_report;
CREATE VIEW orders_report AS
SELECT
  o.id, o.order_reference, o.channel, o.status, o.is_demo, o.total_amount,
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
