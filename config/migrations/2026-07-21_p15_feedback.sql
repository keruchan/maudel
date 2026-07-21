-- ============================================================
-- Migration : 2026-07-21_p15_feedback.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P15 - Youth Feedback / Concerns. The public landing page has
--             promised this as one of SKed's four core services since the
--             very first pass ("Send suggestions straight to your SK
--             Council") but nothing backed the nav item — this closes that
--             gap: a youth submits a private note to their own barangay's
--             SK, who marks it reviewed once addressed.
--
-- created_by is intentionally NOT foreign-keyed on the reviewer side either
-- (same rule as events/charters/cbydp/abyip/katitikan — demo officials
-- ids 1-4 aren't real `users` rows; see 2026-07-21_p12_cbydp_abyip.sql).
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p15_feedback.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS youth_feedback (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED NOT NULL,
    message VARCHAR(2000) NOT NULL,
    status ENUM('open','reviewed') NOT NULL DEFAULT 'open',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_feedback_barangay (barangay_id, status),
    KEY idx_feedback_user (user_id),
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_feedback_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
