-- ============================================================
-- Migration : 2026-07-21_p7_reports_compliance.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P7 - Reporting & compliance
--
-- Tables:
--   reports    - generic report submissions (spec 4.1/4.2/4.3):
--                  monthly                 SK -> PPSK, due by the 10th
--                  interbarangay           PPSK -> DILG (event reports)
--                  minutes                 PPSK -> DILG (meeting minutes)
--                  dismissal_recommendation PPSK -> DILG (3-strike escalation,
--                                            ref_user_id = the SK it concerns)
--   sk_strikes - one row per barangay per missed monthly-report deadline.
--                UNIQUE(sk_user_id, period_month) makes strike-adding
--                idempotent — the compliance cron can run daily without
--                double-counting the same missed month.
--
-- uq_monthly_once relies on MySQL/MariaDB unique-index semantics where NULL
-- values are never considered duplicates of each other: it only constrains
-- rows where period_month IS NOT NULL (i.e. 'monthly' reports), so
-- interbarangay/minutes/dismissal_recommendation rows (period_month NULL)
-- are unaffected.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p7_reports_compliance.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS reports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type ENUM('monthly','interbarangay','minutes','dismissal_recommendation') NOT NULL,
    submitted_by INT UNSIGNED NULL,
    submitted_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    submitted_by_name VARCHAR(150) NULL,
    barangay_id INT UNSIGNED NULL,
    target_role ENUM('ppsk','dilg') NOT NULL,
    period_month CHAR(7) NULL COMMENT 'YYYY-MM, monthly reports only',
    title VARCHAR(160) NOT NULL,
    content TEXT NULL,
    ref_user_id INT UNSIGNED NULL COMMENT 'for dismissal_recommendation: the SK it concerns',
    status ENUM('submitted','reviewed') NOT NULL DEFAULT 'submitted',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monthly_once (barangay_id, type, period_month),
    KEY idx_report_target (target_role, status),
    KEY idx_report_barangay (barangay_id),
    CONSTRAINT fk_report_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sk_strikes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    sk_user_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED NOT NULL,
    period_month CHAR(7) NOT NULL COMMENT 'YYYY-MM, the missed month',
    reason VARCHAR(255) NOT NULL DEFAULT 'Missed monthly report deadline (10th)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_strike_once (sk_user_id, period_month),
    KEY idx_strike_barangay (barangay_id),
    CONSTRAINT fk_strike_user FOREIGN KEY (sk_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_strike_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
