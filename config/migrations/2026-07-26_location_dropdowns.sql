-- ============================================================
-- Migration : 2026-07-26_location_dropdowns.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Align address dropdown data with PSGC names and store purok.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-26_location_dropdowns.sql
-- ============================================================

USE sked_db;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS purok VARCHAR(30) NULL AFTER barangay_id;

UPDATE barangays
   SET name = 'Bagong Pag-Asa'
 WHERE province = 'Laguna'
   AND municipality = 'Siniloan'
   AND name = 'Bagong Pag-asa (Poblacion)';

UPDATE barangays
   SET name = 'Bagumbarangay'
 WHERE province = 'Laguna'
   AND municipality = 'Siniloan'
   AND name = 'Bagumbarangay (Poblacion)';

UPDATE barangays
   SET name = 'G. Redor'
 WHERE province = 'Laguna'
   AND municipality = 'Siniloan'
   AND name = 'G. Redor (Poblacion)';

UPDATE barangays
   SET name = 'J. Rizal'
 WHERE province = 'Laguna'
   AND municipality = 'Siniloan'
   AND name = 'J. Rizal (Poblacion)';

INSERT IGNORE INTO barangays (name, municipality, province) VALUES
    ('Acevida',        'Siniloan', 'Laguna'),
    ('Bagong Pag-Asa', 'Siniloan', 'Laguna'),
    ('Bagumbarangay',  'Siniloan', 'Laguna'),
    ('Buhay',          'Siniloan', 'Laguna'),
    ('Gen. Luna',      'Siniloan', 'Laguna'),
    ('Halayhayin',     'Siniloan', 'Laguna'),
    ('Mendiola',       'Siniloan', 'Laguna'),
    ('Kapatalan',      'Siniloan', 'Laguna'),
    ('Laguio',         'Siniloan', 'Laguna'),
    ('Liyang',         'Siniloan', 'Laguna'),
    ('Llavac',         'Siniloan', 'Laguna'),
    ('Pandeno',        'Siniloan', 'Laguna'),
    ('Magsaysay',      'Siniloan', 'Laguna'),
    ('Macatad',        'Siniloan', 'Laguna'),
    ('Mayatba',        'Siniloan', 'Laguna'),
    ('P. Burgos',      'Siniloan', 'Laguna'),
    ('G. Redor',       'Siniloan', 'Laguna'),
    ('Salubungan',     'Siniloan', 'Laguna'),
    ('Wawa',           'Siniloan', 'Laguna'),
    ('J. Rizal',       'Siniloan', 'Laguna');
