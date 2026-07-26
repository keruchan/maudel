-- ============================================================
-- Migration : 2026-07-21_p19_qr_attendance.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : QR-based event attendance (P19).
--
--   Primary flow  : official scans the YOUTH's personal QR (their KK ID).
--   Fallback flow : youth scans the EVENT's QR, shown/printed by the official.
--
-- Two separate token families on purpose:
--   users.qr_token          -> identifies a youth (acts as their ID card)
--   events.attendance_token -> identifies an event for self-scan
-- events.share_token is NOT reused for attendance: it is the public
-- promo link handed out freely, so anyone holding it could otherwise
-- self-mark attendance. Attendance needs its own, non-public secret.
--
-- created_by-shaped columns (attendance_scans.scanned_by) are intentionally
-- NOT foreign-keyed to users(id) — demo officials (ids 1-4) are a no-DB
-- login shortcut and have no users row. Same rule the events/charters/
-- cbydp migrations already document.
--
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p19_qr_attendance.sql
-- ============================================================

USE sked_db;

-- --- Youth identity: printable KK ID number + scannable token -----------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS kk_id_no VARCHAR(24) NULL AFTER username,
    ADD COLUMN IF NOT EXISTS qr_token CHAR(32) NULL AFTER kk_id_no,
    ADD COLUMN IF NOT EXISTS qr_issued_at DATETIME NULL AFTER qr_token;

-- Unique, but nullable: only youth ever get one, and it is issued lazily.
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_kk_id_no ON users (kk_id_no);
CREATE UNIQUE INDEX IF NOT EXISTS uq_users_qr_token ON users (qr_token);

-- --- Event-side token for the reverse (youth-scans-event) flow ----------
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS attendance_token CHAR(32) NULL AFTER share_token,
    ADD COLUMN IF NOT EXISTS self_scan_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER attendance_token;

CREATE UNIQUE INDEX IF NOT EXISTS uq_events_attendance_token ON events (attendance_token);

-- --- How each attendance was captured ----------------------------------
ALTER TABLE event_participants
    ADD COLUMN IF NOT EXISTS attendance_method ENUM('officer_scan','self_scan','manual') NULL AFTER attended_at;

-- --- Full audit trail of every scan attempt, including rejects ----------
-- Rejected/duplicate attempts are recorded too: an attendance system is
-- only trustworthy if the failures are visible, not silently dropped.
CREATE TABLE IF NOT EXISTS attendance_scans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    method ENUM('officer_scan','self_scan','manual') NOT NULL,
    result ENUM('marked','duplicate','rejected') NOT NULL,
    reason VARCHAR(160) NULL,
    scanned_by INT UNSIGNED NULL,
    scanned_by_role ENUM('dilg','ppsk','sk','youth') NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scan_event (event_id),
    KEY idx_scan_user (user_id),
    KEY idx_scan_time (scanned_at),
    CONSTRAINT fk_scan_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
