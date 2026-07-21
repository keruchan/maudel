-- ============================================================
-- Migration : 2026-07-21_p12_cbydp_abyip.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P12 - CBYDP (Comprehensive Barangay Youth Development Plan,
--             a 3-year rolling plan) and ABYIP (Annual Barangay Youth
--             Investment Program), matching the real DILG/NYC Annex
--             templates the user supplied. SK-only create/edit; PPSK/DILG
--             view + export for oversight. An ABYIP is normally derived
--             from a CBYDP's line items for one year of its 3-year window,
--             then adjusted (reference code, MOOE/CO budget split).
--
-- Both plans are grouped into per-"Center of Participation" sections
-- (the same canonical list as sked_interest_categories() / KK Profiling's
-- "Center of Youth Participation"), each holding one or more PPA line
-- items — matching the two-level structure visible in both source forms.
--
-- created_by is intentionally NOT foreign-keyed (same rule as events/
-- charters, config/migrations/2026-07-20_p4_events.sql): demo officials
-- (ids 1-4) aren't real rows in `users`, so a real FK there would break
-- plan creation for every demo SK account.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p12_cbydp_abyip.sql
-- ============================================================

USE sked_db;

CREATE TABLE IF NOT EXISTS cbydp_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    region VARCHAR(60) NOT NULL DEFAULT 'Region IV-A (CALABARZON)',
    province VARCHAR(60) NOT NULL DEFAULT 'Laguna',
    city_municipality VARCHAR(60) NOT NULL DEFAULT 'Siniloan',
    cy_year_start SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    prepared_by_name VARCHAR(150) NULL,
    prepared_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Secretary',
    approved_by_name VARCHAR(150) NULL,
    approved_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Chairperson',
    signed_file_path VARCHAR(255) NULL,
    signed_file_original_name VARCHAR(255) NULL,
    signed_uploaded_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbydp_barangay_cycle (barangay_id, cy_year_start),
    CONSTRAINT fk_cbydp_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cbydp_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id INT UNSIGNED NOT NULL,
    center_of_participation VARCHAR(50) NOT NULL,
    agenda_statement VARCHAR(1000) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cbydp_section (plan_id, center_of_participation),
    CONSTRAINT fk_cbydp_section_plan FOREIGN KEY (plan_id) REFERENCES cbydp_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cbydp_line_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    youth_development_concern VARCHAR(255) NOT NULL,
    objective VARCHAR(255) NULL,
    performance_indicator VARCHAR(255) NULL,
    target_year1 VARCHAR(255) NULL,
    target_year2 VARCHAR(255) NULL,
    target_year3 VARCHAR(255) NULL,
    ppas VARCHAR(500) NULL,
    budget DECIMAL(12,2) NULL,
    person_responsible VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cbydp_line_section (section_id),
    CONSTRAINT fk_cbydp_line_section FOREIGN KEY (section_id) REFERENCES cbydp_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abyip_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    barangay_id INT UNSIGNED NOT NULL,
    cbydp_plan_id INT UNSIGNED NULL,
    region VARCHAR(60) NOT NULL DEFAULT 'Region IV-A (CALABARZON)',
    province VARCHAR(60) NOT NULL DEFAULT 'Laguna',
    city_municipality VARCHAR(60) NOT NULL DEFAULT 'Siniloan',
    calendar_year SMALLINT UNSIGNED NOT NULL,
    status ENUM('draft','finalized') NOT NULL DEFAULT 'draft',
    prepared_by_name VARCHAR(150) NULL,
    prepared_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Secretary',
    approved_by_name VARCHAR(150) NULL,
    approved_by_role VARCHAR(60) NOT NULL DEFAULT 'SK Chairperson',
    signed_file_path VARCHAR(255) NULL,
    signed_file_original_name VARCHAR(255) NULL,
    signed_uploaded_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_abyip_barangay_year (barangay_id, calendar_year),
    CONSTRAINT fk_abyip_barangay FOREIGN KEY (barangay_id) REFERENCES barangays(id),
    CONSTRAINT fk_abyip_cbydp FOREIGN KEY (cbydp_plan_id) REFERENCES cbydp_plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abyip_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    plan_id INT UNSIGNED NOT NULL,
    center_of_participation VARCHAR(50) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_abyip_section (plan_id, center_of_participation),
    CONSTRAINT fk_abyip_section_plan FOREIGN KEY (plan_id) REFERENCES abyip_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS abyip_line_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    section_id INT UNSIGNED NOT NULL,
    source_cbydp_line_item_id INT UNSIGNED NULL,
    reference_code VARCHAR(20) NULL,
    ppa_name VARCHAR(255) NOT NULL,
    description VARCHAR(500) NULL,
    expected_result VARCHAR(500) NULL,
    performance_indicator VARCHAR(255) NULL,
    period_of_implementation VARCHAR(120) NULL,
    budget_mooe DECIMAL(12,2) NOT NULL DEFAULT 0,
    budget_co DECIMAL(12,2) NOT NULL DEFAULT 0,
    budget_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    person_responsible VARCHAR(255) NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_abyip_line_section (section_id),
    CONSTRAINT fk_abyip_line_section FOREIGN KEY (section_id) REFERENCES abyip_sections(id) ON DELETE CASCADE,
    CONSTRAINT fk_abyip_line_source FOREIGN KEY (source_cbydp_line_item_id) REFERENCES cbydp_line_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
