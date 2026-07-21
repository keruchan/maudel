-- ============================================================
-- Migration : 2026-07-20_p1_auth_accounts.sql
-- Project   : SKed - Youth Profiling System for Event Management
-- Phase     : P1 - Auth & accounts hardening
--
-- Adds `birthdate` to users so registration can enforce the KK
-- eligibility rule (age 15-30, RA 10742) and so the SK can confirm age
-- during verification (P2). Idempotent; safe to re-run.
--
-- Run with:
--   "C:\xampp\mysql\bin\mysql.exe" -u root sked_db < config/migrations/2026-07-20_p1_auth_accounts.sql
-- ============================================================

USE sked_db;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS birthdate DATE NULL AFTER mobile;
