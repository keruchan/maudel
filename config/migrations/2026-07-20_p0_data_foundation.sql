-- ============================================================
-- Migration : 2026-07-20_p0_data_foundation.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P0 - Data foundation
--
-- Idempotent (safe to re-run) migration for an EXISTING sked_db that
-- already has the `users` table from config/schema.sql. It:
--   1. Creates `barangays` (province -> municipality -> barangay) and
--      seeds Siniloan, Laguna's 20 official barangays.
--   2. Extends `users` with status, barangay_id, and former-official
--      recognition fields, plus the FK to `barangays`.
--   3. Creates `role_history` (per-term audit powering "Former SK/PPSK"
--      badges and DILG's historical record).
--   4. Creates `audit_log` (who did what, when — for sensitive actions
--      like verification, dismissal, turnover).
--
-- MariaDB 10.4+ / MySQL 8+ assumed (uses ADD COLUMN IF NOT EXISTS).
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-20_p0_data_foundation.sql
-- ============================================================

USE sked_db;

-- ------------------------------------------------------------
-- 1. barangays  (province -> municipality -> barangay)
--    Structured for future expansion beyond Siniloan without a migration.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS barangays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    municipality VARCHAR(120) NOT NULL DEFAULT 'Siniloan',
    province VARCHAR(120) NOT NULL DEFAULT 'Laguna',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_barangay_scope (province, municipality, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the 20 official barangays of Siniloan, Laguna (idempotent).
INSERT IGNORE INTO barangays (name, municipality, province) VALUES
    ('Acevida',                    'Siniloan', 'Laguna'),
    ('Bagong Pag-Asa',             'Siniloan', 'Laguna'),
    ('Bagumbarangay',              'Siniloan', 'Laguna'),
    ('Buhay',                      'Siniloan', 'Laguna'),
    ('G. Redor',                   'Siniloan', 'Laguna'),
    ('Gen. Luna',                  'Siniloan', 'Laguna'),
    ('Halayhayin',                 'Siniloan', 'Laguna'),
    ('J. Rizal',                   'Siniloan', 'Laguna'),
    ('Kapatalan',                  'Siniloan', 'Laguna'),
    ('Laguio',                     'Siniloan', 'Laguna'),
    ('Liyang',                     'Siniloan', 'Laguna'),
    ('Llavac',                     'Siniloan', 'Laguna'),
    ('Macatad',                    'Siniloan', 'Laguna'),
    ('Magsaysay',                  'Siniloan', 'Laguna'),
    ('Mayatba',                    'Siniloan', 'Laguna'),
    ('Mendiola',                   'Siniloan', 'Laguna'),
    ('P. Burgos',                  'Siniloan', 'Laguna'),
    ('Pandeno',                    'Siniloan', 'Laguna'),
    ('Salubungan',                 'Siniloan', 'Laguna'),
    ('Wawa',                       'Siniloan', 'Laguna');

-- ------------------------------------------------------------
-- 2. users: add status, barangay scoping, and former-official recognition
-- ------------------------------------------------------------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS status ENUM('pending','active','rejected','retired')
        NOT NULL DEFAULT 'pending' AFTER role,
    ADD COLUMN IF NOT EXISTS barangay_id INT UNSIGNED NULL AFTER status,
    ADD COLUMN IF NOT EXISTS purok VARCHAR(30) NULL AFTER barangay_id,
    ADD COLUMN IF NOT EXISTS former_role_badge VARCHAR(255) NULL AFTER verified,
    ADD COLUMN IF NOT EXISTS term_start DATE NULL AFTER former_role_badge,
    ADD COLUMN IF NOT EXISTS term_end DATE NULL AFTER term_start;

-- Backfill status for rows created before this column existed:
--   verified youth/officials -> active, everyone else -> pending.
UPDATE users SET status = 'active'  WHERE verified = 1 AND status = 'pending';

-- Index + FK for barangay scoping (guarded so re-runs don't error).
SET @has_bgy_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_barangay');
SET @sql := IF(@has_bgy_idx = 0,
    'ALTER TABLE users ADD INDEX idx_users_barangay (barangay_id)',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bgy_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_barangay');
SET @sql := IF(@has_bgy_fk = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. role_history: one row per term a user held a role in a barangay.
--    Powers "Former SK Chairman / Former PPSK President" badges and gives
--    DILG a clean historical record per barangay. term_end NULL = current.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('dilg','ppsk','sk','youth') NOT NULL,
    barangay_id INT UNSIGNED NULL,
    term_start DATE NULL,
    term_end DATE NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_role_history_user (user_id),
    KEY idx_role_history_barangay (barangay_id),
    CONSTRAINT fk_role_history_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_history_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. audit_log: append-only trail of sensitive actions (verification,
--    dismissal, turnover, provisioning). actor_id NULL = system/cron.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NULL,
    entity_id INT UNSIGNED NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_actor (actor_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
