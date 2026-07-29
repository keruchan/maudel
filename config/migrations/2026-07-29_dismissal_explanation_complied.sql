-- Dismissal review workflow: DILG can request an explanation before deciding
-- (reuses the existing 'rework' status as "awaiting explanation"), or close
-- the case as complied/resolved without dismissing the SK (new 'complied'
-- status). Marking a case complied also clears the SK's compliance strikes
-- (see sked_mark_dismissal_complied() in includes/compliance.php) so they
-- are not immediately re-eligible for escalation on the same old strikes.

USE sked_db;

ALTER TABLE reports
    MODIFY COLUMN status ENUM('submitted','reviewed','rework','rejected','complied') NOT NULL DEFAULT 'submitted';
