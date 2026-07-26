-- ============================================================
-- File     : config/schema.sql
-- Project  : SKed - Youth Profiling System for Event Management
-- Purpose  : Canonical fresh-install schema for the sked_db database.
--
-- This file builds a NEW database from scratch. For an existing
-- database, apply the incremental scripts in config/migrations/
-- instead (they are idempotent and preserve data).
--
-- Demo accounts (usernames "1"-"4") are NOT stored here on purpose —
-- they remain a hardcoded, no-DB shortcut (see sked_demo_users()) so
-- the four role dashboards stay reachable even if the database is
-- unavailable. Real self-registered accounts get ids starting at 1000
-- so the two id spaces never collide.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root < config/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS sked_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sked_db;

-- ------------------------------------------------------------
-- barangays  (province -> municipality -> barangay)
-- Structured for future expansion beyond Siniloan without a migration.
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

-- Siniloan, Laguna's 20 official barangays.
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
-- users  (single table for all roles, keyed by role + status)
--   status : pending  -> awaiting SK verification (new youth)
--            active   -> in good standing / current official
--            rejected -> verification denied
--            retired  -> former official, reverted to youth
--   former_role_badge : e.g. "Former SK Chairman - Wawa (2023-2025)"
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role ENUM('dilg', 'ppsk', 'sk', 'youth') NOT NULL DEFAULT 'youth',
    status ENUM('pending','active','rejected','retired') NOT NULL DEFAULT 'pending',
    barangay_id INT UNSIGNED NULL,
    purok VARCHAR(30) NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL,
    mobile VARCHAR(20) NULL,
    birthdate DATE NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    former_role_badge VARCHAR(255) NULL,
    term_start DATE NULL,
    term_end DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_barangay (barangay_id),
    CONSTRAINT fk_users_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci AUTO_INCREMENT=1000;

-- ------------------------------------------------------------
-- role_history  (one row per term a user held a role in a barangay)
-- Powers "Former SK/PPSK" badges + DILG's historical record.
-- term_end NULL = current term.
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
-- audit_log  (append-only trail of sensitive actions)
-- actor_id NULL = system/cron action.
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
