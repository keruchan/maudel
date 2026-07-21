-- ============================================================
-- Migration : 2026-07-21_p13_katitikan.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P13 - Katitikan (Minutes of the Meeting), matching the real
--             sample the user supplied (Barangay J.P. Rizal SK monthly
--             regular session minutes: attendance roll, numbered roman-
--             numeral sections I-IX, Secretary's Certification + Approved
--             By signature block).
--
-- SK-only create/edit (own barangay); routes to DILG for oversight via the
-- existing `reports` table (type='minutes', already defined in P7) — a
-- katitikan links to at most one reports row once the SK submits it there.
--
-- created_by is intentionally NOT foreign-keyed — same rule as events/
-- charters/cbydp/abyip (demo officials ids 1-4 aren't real `users` rows;
-- see 2026-07-21_p12_cbydp_abyip.sql for the incident this rule comes from).
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p13_katitikan.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS katitikan (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    session_no VARCHAR(20) NOT NULL,
    series_year SMALLINT UNSIGNED NOT NULL,
    meeting_date DATE NOT NULL,
    meeting_time TIME NOT NULL,
    venue VARCHAR(150) NOT NULL DEFAULT 'Barangay Session Hall',
    presiding_officer VARCHAR(150) NULL,
    invocation_by VARCHAR(150) NULL,
    roll_call_notes VARCHAR(1000) NULL,
    minutes_reading_notes VARCHAR(1000) NULL,
    committee_reports VARCHAR(1000) NULL,
    announcements VARCHAR(1000) NULL,
    adjournment_time TIME NULL,
    adjourned_by VARCHAR(150) NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    prepared_by_name VARCHAR(150) NULL,
    prepared_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Secretary',
    approved_by_name VARCHAR(150) NULL,
    approved_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Chairperson',
    signed_file_path VARCHAR(255) NULL,
    signed_file_original_name VARCHAR(255) NULL,
    signed_uploaded_at DATETIME NULL,
    report_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_katitikan_session (barangay_id, series_year, session_no),
    CONSTRAINT fk_katitikan_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    CONSTRAINT fk_katitikan_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS katitikan_attendees (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    katitikan_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    designation VARCHAR(60) NOT NULL,
    attendance_status ENUM('present','absent') NOT NULL DEFAULT 'present',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_katitikan_attendee (katitikan_id),
    CONSTRAINT fk_attendee_katitikan FOREIGN KEY (katitikan_id) REFERENCES katitikan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS katitikan_privilege_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    katitikan_id INT UNSIGNED NOT NULL,
    speaker_name VARCHAR(150) NULL,
    proposal VARCHAR(1000) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_katitikan_privilege (katitikan_id),
    CONSTRAINT fk_privilege_katitikan FOREIGN KEY (katitikan_id) REFERENCES katitikan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS katitikan_agenda_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    katitikan_id INT UNSIGNED NOT NULL,
    category ENUM('unfinished','agenda','new') NOT NULL,
    description VARCHAR(500) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_katitikan_agenda (katitikan_id),
    CONSTRAINT fk_agenda_katitikan FOREIGN KEY (katitikan_id) REFERENCES katitikan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS katitikan_id INT UNSIGNED NULL AFTER ref_user_id;

SET @has_reports_katitikan_fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND CONSTRAINT_NAME = 'fk_reports_katitikan');
SET @sql := IF(@has_reports_katitikan_fk = 0,
    'ALTER TABLE reports ADD CONSTRAINT fk_reports_katitikan FOREIGN KEY (katitikan_id) REFERENCES katitikan(id) ON DELETE SET NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
