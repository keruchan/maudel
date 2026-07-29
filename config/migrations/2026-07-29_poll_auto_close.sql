-- ============================================================
-- Migration : 2026-07-29_poll_auto_close.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Purpose   : Adds scheduled close date/time for community polls.
--
-- Idempotent; safe to re-run.
-- Run: "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-29_poll_auto_close.sql
-- ============================================================

USE sked_db;

ALTER TABLE polls
    ADD COLUMN IF NOT EXISTS closes_at DATETIME NULL AFTER created_at;

