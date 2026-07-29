-- Replaces the old structured CBYDP/ABYIP section/line-item data entry with a
-- simple file-upload model. Also covers two new upload categories: Annual
-- Budget and Monthly Itemized List of Purchase Request. Every row is one
-- uploaded document for one barangay, scoped to a date period (a year, or a
-- year+month for purchase_request) — no draft/finalized workflow, every
-- upload is immediately public. The old cbydp_plans/abyip_plans tables (see
-- 2026-07-21_p12_cbydp_abyip.sql) are left in place, untouched.

USE sked_db;

CREATE TABLE IF NOT EXISTS plan_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    doc_type ENUM('cbydp','abyip','annual_budget','purchase_request') NOT NULL,
    period_label VARCHAR(30) NOT NULL,
    period_start DATE NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_original_name VARCHAR(255) NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_by_name VARCHAR(150) NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_plandoc_barangay_type (barangay_id, doc_type),
    KEY idx_plandoc_period (period_start),
    CONSTRAINT fk_plandoc_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
