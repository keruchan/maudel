-- ============================================================
-- Migration : 2026-07-21_p6_charters_polls.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P6 - Charters & polls
--
-- Tables:
--   project_charters - SK's CBYDP-style project tracker (upcoming / current
--                       / past). budget_amount is an INFO-ONLY field (spec
--                       4.3: "no approval workflow") — a plain number the SK
--                       records, nothing more. Optionally links to one event
--                       (event_id) so "success rate" can be derived live from
--                       that event's evaluations (sked_event_rating(), P4) —
--                       not stored/denormalized, consistent with how activity
--                       levels are derived on read (see P5 D-013).
--   polls            - SK-created community polls, barangay-scoped.
--   poll_options      - normalized choices (not JSON), matching the
--                       youth_interests normalization precedent from P3.
--   poll_responses    - one vote per youth per poll (UNIQUE constraint).
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p6_charters_polls.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS project_charters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    budget_amount DECIMAL(12,2) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM('upcoming','ongoing','completed') NOT NULL DEFAULT 'upcoming',
    event_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    created_by_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_charter_barangay (barangay_id),
    KEY idx_charter_status (status),
    KEY idx_charter_dates (start_date, end_date),
    CONSTRAINT fk_charter_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE,
    CONSTRAINT fk_charter_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polls (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    question VARCHAR(300) NOT NULL,
    status ENUM('draft','open','closed') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    created_by_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_poll_barangay (barangay_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- barangay_id FK added separately: MariaDB doesn't allow IF NOT EXISTS on
-- inline FK constraints uniformly across versions the way it does columns,
-- so the CREATE above stays FK-light and this block is the idempotent guard.
SET @has_poll_bgy_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'polls' AND CONSTRAINT_NAME = 'fk_poll_barangay');
SET @sql := IF(@has_poll_bgy_fk = 0,
    'ALTER TABLE polls ADD CONSTRAINT fk_poll_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS poll_options (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    poll_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(150) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_option_poll (poll_id),
    CONSTRAINT fk_option_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_responses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    poll_id INT UNSIGNED NOT NULL,
    option_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_poll_user (poll_id, user_id),
    KEY idx_response_option (option_id),
    CONSTRAINT fk_response_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_option FOREIGN KEY (option_id) REFERENCES poll_options(id) ON DELETE CASCADE,
    CONSTRAINT fk_response_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
