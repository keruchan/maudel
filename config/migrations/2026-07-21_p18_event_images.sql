-- ============================================================
-- Migration : 2026-07-21_p18_event_images.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Adds optional image attachments to events/announcements.
--
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u u763192172_sked --password=Sked12345! u763192172_sked < config/migrations/2026-07-21_p18_event_images.sql
-- ============================================================

USE u763192172_sked;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS image_file_path VARCHAR(255) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS image_file_original_name VARCHAR(255) NULL AFTER image_file_path,
    ADD COLUMN IF NOT EXISTS image_uploaded_at DATETIME NULL AFTER image_file_original_name,
    ADD COLUMN IF NOT EXISTS image_uploaded_by INT UNSIGNED NULL AFTER image_uploaded_at;
