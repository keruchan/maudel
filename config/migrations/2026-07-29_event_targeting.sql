-- "For You" event targeting (optional). Organizers may tag an event with
-- one or more KK Profiling attributes it's meant for; youth who match get
-- a "For You" badge and priority sort on Browse Events, but nothing is
-- hidden from anyone — see includes/events.php sked_events_for_youth().
-- One generic table covers all 4 targetable dimensions rather than 4
-- near-identical junction tables, mirroring event_barangays's composite-key
-- + cascade-delete pattern.

CREATE TABLE IF NOT EXISTS event_targeting (
    event_id INT UNSIGNED NOT NULL,
    dimension ENUM('classification','specific_need','sex','interest') NOT NULL,
    value VARCHAR(80) NOT NULL,
    PRIMARY KEY (event_id, dimension, value),
    KEY idx_et_lookup (dimension, value),
    CONSTRAINT fk_et_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
