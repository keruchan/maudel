-- Report review actions for DILG inbox.
-- Adds explicit rework/rejected outcomes and reviewer comments.

USE sked_db;

ALTER TABLE reports
    MODIFY COLUMN status ENUM('submitted','reviewed','rework','rejected') NOT NULL DEFAULT 'submitted';

ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS review_comments TEXT NULL AFTER reviewed_by;
