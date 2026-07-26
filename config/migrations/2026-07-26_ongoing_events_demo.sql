-- ============================================================
-- Migration : 2026-07-26_ongoing_events_demo.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Ensure the system has visible ongoing event records for
--             dashboard/event-page verification.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-26_ongoing_events_demo.sql
-- ============================================================

USE sked_db;

INSERT INTO events
    (title, description, category, scope, barangay_id, type, is_team_sport,
     location, event_date, start_time, end_time, registration_deadline,
     min_participants, capacity, share_token, status,
     created_by, created_by_role, created_by_name)
VALUES
    (
        'Ongoing Barangay Youth Help Desk',
        'Same-day SK assistance desk for KK profile follow-ups, event questions, and youth service referrals.',
        'Governance',
        'barangay',
        (SELECT id FROM barangays WHERE province = 'Laguna' AND municipality = 'Siniloan' AND name = 'Acevida' LIMIT 1),
        'interested',
        0,
        'Barangay Acevida Covered Court',
        CURDATE(),
        '08:00:00',
        '17:00:00',
        CURDATE(),
        0,
        NULL,
        '20260726000000000000000000000001',
        'ongoing',
        1002,
        'sk',
        'SK Chairperson Acevida'
    )
ON DUPLICATE KEY UPDATE
    event_date = CURDATE(),
    start_time = VALUES(start_time),
    end_time = VALUES(end_time),
    registration_deadline = CURDATE(),
    status = 'ongoing',
    updated_at = CURRENT_TIMESTAMP;

INSERT INTO events
    (title, description, category, scope, barangay_id, type, is_team_sport,
     location, event_date, start_time, end_time, registration_deadline,
     min_participants, capacity, share_token, status,
     created_by, created_by_role, created_by_name)
VALUES
    (
        'Ongoing Municipal Youth Sports Clinic',
        'Municipality-wide youth sports and wellness clinic open to eligible KK members across Siniloan.',
        'Health',
        'municipal',
        NULL,
        'interested',
        0,
        'Siniloan Municipal Gymnasium',
        CURDATE(),
        '09:00:00',
        '16:00:00',
        CURDATE(),
        0,
        NULL,
        '20260726000000000000000000000002',
        'ongoing',
        1001,
        'ppsk',
        'PPSK President'
    )
ON DUPLICATE KEY UPDATE
    event_date = CURDATE(),
    start_time = VALUES(start_time),
    end_time = VALUES(end_time),
    registration_deadline = CURDATE(),
    status = 'ongoing',
    updated_at = CURRENT_TIMESTAMP;
