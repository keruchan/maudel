-- ============================================================
-- Migration : 2026-07-21_p16_sk_officials.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P16 - Barangay SK officials roster
--
-- Adds a barangay-scoped roster for SK officers/council members. Officers may
-- be linked to an existing youth/community account, but they do not need a
-- separate auth role. Katitikan attendance can reference this roster while
-- still storing the historical name/designation printed in each minutes file.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p16_sk_officials.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS sk_officials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    position VARCHAR(80) NOT NULL,
    committee VARCHAR(120) NULL,
    contact_no VARCHAR(30) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    term_start DATE NULL,
    term_end DATE NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sk_officials_barangay (barangay_id, status),
    KEY idx_sk_officials_user (user_id),
    CONSTRAINT fk_sk_officials_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    CONSTRAINT fk_sk_officials_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE katitikan_attendees
    ADD COLUMN IF NOT EXISTS sk_official_id INT UNSIGNED NULL AFTER katitikan_id;

CREATE INDEX IF NOT EXISTS idx_katitikan_attendee_official
    ON katitikan_attendees (sk_official_id);

SET @has_attendee_official_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'katitikan_attendees' AND CONSTRAINT_NAME = 'fk_attendee_sk_official');
SET @sql := IF(@has_attendee_official_fk = 0,
    'ALTER TABLE katitikan_attendees ADD CONSTRAINT fk_attendee_sk_official FOREIGN KEY (sk_official_id) REFERENCES sk_officials(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_attendee_official_unique := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'katitikan_attendees' AND INDEX_NAME = 'uq_katitikan_official');
SET @sql := IF(@has_attendee_official_unique = 0,
    'ALTER TABLE katitikan_attendees ADD UNIQUE KEY uq_katitikan_official (katitikan_id, sk_official_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
