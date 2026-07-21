-- ============================================================
-- Migration : 2026-07-20_p3_profiling.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P3 - Youth profiling
--
-- Adds:
--   1. youth_profiles   - one KK profile row per verified youth, with a
--                         questionnaire_version stamp so year-to-year
--                         question changes keep historical analytics
--                         comparable (Recommendation #6).
--   2. youth_interests  - normalized multi-select advocacy/interest
--                         categories (one row per user+category) so the
--                         prescriptive-analytics engine (P9) can simply
--                         GROUP BY category per barangay.
--   3. points_ledger    - auditable participation-point ledger (P5 infra,
--                         built now because profiling completion awards
--                         points). UNIQUE(user_id, action_type, ref_type,
--                         ref_id) makes one-time awards idempotent.
--
-- Idempotent; safe to re-run.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-20_p3_profiling.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS youth_profiles (
    user_id INT UNSIGNED NOT NULL,
    questionnaire_version INT UNSIGNED NOT NULL DEFAULT 1,
    civil_status VARCHAR(30) NULL,
    youth_classification VARCHAR(50) NULL,
    educational_attainment VARCHAR(60) NULL,
    work_status VARCHAR(40) NULL,
    sk_voter TINYINT(1) NULL,
    national_voter TINYINT(1) NULL,
    attended_kk_assembly TINYINT(1) NULL,
    remarks VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS youth_interests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    category VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_category (user_id, category),
    KEY idx_interest_category (category),
    CONSTRAINT fk_interest_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS points_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(50) NOT NULL,
    points INT NOT NULL DEFAULT 0,
    ref_type VARCHAR(40) NOT NULL DEFAULT '',
    ref_id INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_points_once (user_id, action_type, ref_type, ref_id),
    KEY idx_points_user (user_id),
    CONSTRAINT fk_points_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
