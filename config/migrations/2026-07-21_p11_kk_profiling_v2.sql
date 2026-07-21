-- ============================================================
-- Migration : 2026-07-21_p11_kk_profiling_v2.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P11 - KK Profiling v2 (real Brgy. J.P. Rizal KK Profiling
--             Google Form, replacing the P3 placeholder questionnaire)
--
-- 1. users: adds split-name + sex-assigned-at-birth columns. These are
--    "basic account info" collected once at registration, then shown
--    read-only (autofilled + locked) on the KK profiling form — same
--    governance rule already applied to name/email/barangay
--    (pages/account/settings.php).
--
-- 2. youth_profiles: adds the v2 fields that don't need their own table
--    (consent, gender identity, LGBTQIA flag, Facebook name, number of
--    children, valid ID, voted-last-election, KK Assembly follow-ups,
--    "Others" free text for scholarship/preferred programs). Drops
--    youth_classification (was a single VARCHAR; the real form allows
--    multiple classifications per youth, so it moves to its own table
--    like youth_interests already does for advocacy categories).
--
-- 3. Four new normalized child tables (one row per selected checkbox),
--    matching the existing youth_interests pattern — no JSON columns.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p11_kk_profiling_v2.sql
-- ============================================================

USE sked_db;

-- ------------------------------------------------------------
-- 1. users: identity fields split out of the single `name` column.
-- ------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS surname VARCHAR(60) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS given_name VARCHAR(60) NULL AFTER surname,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(60) NULL AFTER given_name,
    ADD COLUMN IF NOT EXISTS sex_assigned_at_birth ENUM('male','female') NULL AFTER middle_name;

-- ------------------------------------------------------------
-- 2. youth_profiles: v2 fields.
-- ------------------------------------------------------------
ALTER TABLE youth_profiles
    ADD COLUMN IF NOT EXISTS consent_agreed TINYINT(1) NOT NULL DEFAULT 0 AFTER questionnaire_version,
    ADD COLUMN IF NOT EXISTS consent_agreed_at DATETIME NULL AFTER consent_agreed,
    ADD COLUMN IF NOT EXISTS gender_identity ENUM('man','woman') NULL AFTER civil_status,
    ADD COLUMN IF NOT EXISTS lgbtqia_member TINYINT(1) NULL AFTER gender_identity,
    ADD COLUMN IF NOT EXISTS facebook_name VARCHAR(100) NULL AFTER lgbtqia_member,
    ADD COLUMN IF NOT EXISTS num_children TINYINT UNSIGNED NULL AFTER facebook_name,
    ADD COLUMN IF NOT EXISTS valid_id VARCHAR(100) NULL AFTER work_status,
    ADD COLUMN IF NOT EXISTS voted_last_election TINYINT(1) NULL AFTER national_voter,
    ADD COLUMN IF NOT EXISTS kk_assembly_times TINYINT UNSIGNED NULL AFTER attended_kk_assembly,
    ADD COLUMN IF NOT EXISTS kk_assembly_absence_reason VARCHAR(80) NULL AFTER kk_assembly_times,
    ADD COLUMN IF NOT EXISTS scholarship_other VARCHAR(100) NULL AFTER remarks,
    ADD COLUMN IF NOT EXISTS preferred_programs_other VARCHAR(150) NULL AFTER scholarship_other,
    DROP COLUMN IF EXISTS youth_classification;

-- ------------------------------------------------------------
-- 3. New normalized child tables (multi-select checkbox questions).
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS youth_classifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    classification VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_classification (user_id, classification),
    CONSTRAINT fk_classification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS youth_specific_needs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    need_type VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_need (user_id, need_type),
    CONSTRAINT fk_need_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS youth_scholarships (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    scholarship_type VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_scholarship (user_id, scholarship_type),
    CONSTRAINT fk_scholarship_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS youth_preferred_programs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    program VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_program (user_id, program),
    CONSTRAINT fk_program_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
