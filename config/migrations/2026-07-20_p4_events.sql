-- ============================================================
-- Migration : 2026-07-20_p4_events.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P4 - Events & engagement (schema)
--
-- Tables:
--   events              - one row per event. scope drives who can see it:
--                           'barangay'      -> single barangay_id (SK)
--                           'interbarangay' -> a set of barangays (PPSK),
--                                              listed in event_barangays
--                           'municipal'     -> whole municipality (PPSK)
--                         type: 'interested' (join, no cap) vs 'register'
--                         (has capacity/slots). share_token = public link.
--   event_barangays     - barangays targeted by an interbarangay event.
--   event_participants  - youth join/register/attendance records.
--   event_evaluations   - post-event youth evaluations (feeds success rate
--                         + points; wired in P4 slice B / P6).
--
-- created_by is intentionally NOT foreign-keyed: demo officials (ids 1-4)
-- aren't persisted in `users`, and they must still be able to create
-- events. created_by_name is stored for robust display.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-20_p4_events.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    category VARCHAR(50) NULL,
    scope ENUM('barangay','interbarangay','municipal') NOT NULL DEFAULT 'barangay',
    barangay_id INT UNSIGNED NULL,
    municipality VARCHAR(120) NOT NULL DEFAULT 'Siniloan',
    province VARCHAR(120) NOT NULL DEFAULT 'Laguna',
    type ENUM('interested','register') NOT NULL DEFAULT 'interested',
    is_team_sport TINYINT(1) NOT NULL DEFAULT 0,
    location VARCHAR(200) NULL,
    event_date DATE NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    registration_deadline DATE NULL,
    min_participants INT UNSIGNED NOT NULL DEFAULT 0,
    capacity INT UNSIGNED NULL,
    share_token CHAR(32) NOT NULL,
    status ENUM('draft','published','confirmed','ongoing','completed','cancelled','evaluation','closed')
        NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    created_by_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_share (share_token),
    KEY idx_event_scope (scope, barangay_id),
    KEY idx_event_status (status),
    KEY idx_event_date (event_date),
    CONSTRAINT fk_event_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_barangays (
    event_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, barangay_id),
    KEY idx_eb_barangay (barangay_id),
    CONSTRAINT fk_eb_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eb_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_participants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    status ENUM('interested','registered','attended','cancelled','no_show') NOT NULL DEFAULT 'registered',
    team_name VARCHAR(100) NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    attended_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_user (event_id, user_id),
    KEY idx_ep_user (user_id),
    KEY idx_ep_status (event_id, status),
    CONSTRAINT fk_ep_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_ep_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_evaluations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comments VARCHAR(500) NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eval_event_user (event_id, user_id),
    KEY idx_eval_event (event_id),
    CONSTRAINT fk_eval_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eval_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
