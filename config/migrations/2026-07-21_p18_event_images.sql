-- ============================================================
-- Migration : 2026-07-21_p18_event_images.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Adds optional image attachments to events/announcements.
--
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p18_event_images.sql
-- ============================================================

USE sked_db;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS image_file_path VARCHAR(255) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS image_file_original_name VARCHAR(255) NULL AFTER image_file_path,
    ADD COLUMN IF NOT EXISTS image_uploaded_at DATETIME NULL AFTER image_file_original_name,
    ADD COLUMN IF NOT EXISTS image_uploaded_by INT UNSIGNED NULL AFTER image_uploaded_at;
