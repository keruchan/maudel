-- Real Announcements: a lightweight title/content notice board SK/PPSK/DILG
-- can post (no date/capacity/registration — that's what events are for).
-- Mirrors events'/event_barangays' exact scope model and composite-key
-- target-barangay junction table pattern.

CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    content TEXT NOT NULL,
    scope ENUM('barangay','interbarangay','municipal') NOT NULL DEFAULT 'barangay',
    barangay_id INT UNSIGNED NULL,
    image_file_path VARCHAR(255) NULL,
    image_file_original_name VARCHAR(255) NULL,
    image_uploaded_at DATETIME NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('draft','published') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL,
    created_by_role ENUM('dilg','ppsk','sk') NULL,
    created_by_name VARCHAR(150) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ann_scope (scope, barangay_id),
    KEY idx_ann_status (status),
    CONSTRAINT fk_ann_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_barangays (
    announcement_id INT UNSIGNED NOT NULL,
    barangay_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (announcement_id, barangay_id),
    KEY idx_ab_barangay (barangay_id),
    CONSTRAINT fk_ab_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    CONSTRAINT fk_ab_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
