-- ============================================================
-- Migration : 2026-07-21_p8_turnover.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P8 - Turnover of power
--
-- Adds:
--   1. 'turnover' to reports.type, plus nullable new_officer_* columns
--      carrying the incoming PPSK's identity (spec 6.1 step 1: outgoing
--      PPSK submits new PPSK identity + full incoming SK roster together).
--   2. turnover_roster - the incoming SK Chairman roster attached to a
--      turnover report, one row per barangay. provisioned_user_id links to
--      the actual account once the new PPSK generates/promotes it.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p8_turnover.sql
-- ============================================================

USE sked_db;

ALTER TABLE reports
    MODIFY COLUMN type ENUM('monthly','interbarangay','minutes','dismissal_recommendation','turnover') NOT NULL;

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS new_officer_name VARCHAR(150) NULL AFTER ref_user_id,
    ADD COLUMN IF NOT EXISTS new_officer_email VARCHAR(190) NULL AFTER new_officer_name,
    ADD COLUMN IF NOT EXISTS new_officer_mobile VARCHAR(20) NULL AFTER new_officer_email;

CREATE TABLE IF NOT EXISTS turnover_roster (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    mobile VARCHAR(20) NULL,
    status ENUM('pending','provisioned') NOT NULL DEFAULT 'pending',
    provisioned_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roster_report_barangay (report_id, barangay_id),
    KEY idx_roster_status (status),
    CONSTRAINT fk_roster_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    CONSTRAINT fk_roster_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    CONSTRAINT fk_roster_provisioned FOREIGN KEY (provisioned_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
