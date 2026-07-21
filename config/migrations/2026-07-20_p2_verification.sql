-- ============================================================
-- Migration : 2026-07-20_p2_verification.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P2 - Youth verification
--
-- Adds the `notifications` table (per-user notification center used first
-- for verification outcomes; the interactive bell/center UI follows in
-- P10). Verification actions themselves also write to `audit_log` (P0).
-- Idempotent; safe to re-run.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-20_p2_verification.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(500) NOT NULL,
    link VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_user_unread (user_id, is_read),
    KEY idx_notif_created (created_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
