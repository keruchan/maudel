-- "Strictly apply" toggle for event "For You" targeting (includes/events.php
-- sked_event_targeting_options()). When set, the event is hidden entirely
-- from youth who don't match ANY selected targeting dimension (and from
-- anonymous/non-youth viewers on the public landing feed) — a genuine
-- visibility filter, not just the default "For You" badge + sort boost.

USE sked_db;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS targeting_strict TINYINT(1) NOT NULL DEFAULT 0 AFTER capacity;
