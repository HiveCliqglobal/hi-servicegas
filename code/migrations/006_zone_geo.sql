-- =====================================================
-- Migration 006 — geo coordinates for delivery zones
-- =====================================================

ALTER TABLE delivery_zones
  ADD COLUMN lat DECIMAL(10,7) NULL AFTER municipality,
  ADD COLUMN lng DECIMAL(10,7) NULL AFTER lat;

-- Backfill approximate coordinates for the Helderberg + Overberg + Stellenbosch suburbs
UPDATE delivery_zones SET lat=-34.1077, lng=18.8232 WHERE suburb='Strand'                   AND postal_code='7140';
UPDATE delivery_zones SET lat=-34.1180, lng=18.8230 WHERE suburb='Strand'                   AND postal_code='7139';
UPDATE delivery_zones SET lat=-34.0853, lng=18.8473 WHERE suburb='Somerset West'            AND postal_code='7130';
UPDATE delivery_zones SET lat=-34.1654, lng=18.8568 WHERE suburb='Gordon''s Bay'            AND postal_code='7140';
UPDATE delivery_zones SET lat=-34.1351, lng=18.9116 WHERE suburb='Sir Lowry''s Pass Village';
UPDATE delivery_zones SET lat=-34.0588, lng=18.7860 WHERE suburb='Firgrove';
UPDATE delivery_zones SET lat=-34.0691, lng=18.7547 WHERE suburb='Macassar';
UPDATE delivery_zones SET lat=-34.0940, lng=18.8155 WHERE suburb='Lwandle';
UPDATE delivery_zones SET lat=-33.9367, lng=18.8602 WHERE suburb='Stellenbosch';
UPDATE delivery_zones SET lat=-33.9492, lng=18.8696 WHERE suburb='Brandwacht';
UPDATE delivery_zones SET lat=-33.9512, lng=18.8634 WHERE suburb='Die Boord';
UPDATE delivery_zones SET lat=-33.9658, lng=18.8794 WHERE suburb='Jamestown';
UPDATE delivery_zones SET lat=-33.9543, lng=18.8497 WHERE suburb='Paradyskloof';
UPDATE delivery_zones SET lat=-34.3486, lng=18.8295 WHERE suburb='Pringle Bay';
UPDATE delivery_zones SET lat=-34.3422, lng=18.9006 WHERE suburb='Palmiet';
UPDATE delivery_zones SET lat=-34.1514, lng=19.0192 WHERE suburb='Grabouw';
UPDATE delivery_zones SET lat=-34.3425, lng=19.0378 WHERE suburb='Kleinmond';
UPDATE delivery_zones SET lat=-34.3673, lng=18.9105 WHERE suburb='Betty''s Bay';
