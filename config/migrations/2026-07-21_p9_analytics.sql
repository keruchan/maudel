-- ============================================================
-- Migration : 2026-07-21_p9_analytics.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P9 - Prescriptive analytics
--
-- Adds an optional `category` to `polls`, matching `events.category` (both
-- draw from the same canonical list, sked_interest_categories() in
-- includes/profiling.php). This lets poll engagement feed the same
-- category-ranked recommender as event success rates, without fragile
-- free-text matching against poll questions/options.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-21_p9_analytics.sql
-- ============================================================

USE sked_db;

ALTER TABLE polls
    ADD COLUMN IF NOT EXISTS category VARCHAR(50) NULL AFTER question;
