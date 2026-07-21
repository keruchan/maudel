-- ============================================================
-- Migration : 2026-07-21_p17_report_attachments.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Adds optional gated attachments to the shared reports table
--             so SK/PPSK submissions are visible to PPSK/DILG review and
--             export flows without exposing upload files directly.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p17_report_attachments.sql
-- ============================================================

USE sked_db;

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS attachment_file_path VARCHAR(255) NULL AFTER content,
    ADD COLUMN IF NOT EXISTS attachment_file_original_name VARCHAR(255) NULL AFTER attachment_file_path,
    ADD COLUMN IF NOT EXISTS attachment_uploaded_at DATETIME NULL AFTER attachment_file_original_name,
    ADD COLUMN IF NOT EXISTS attachment_uploaded_by INT UNSIGNED NULL AFTER attachment_uploaded_at;
