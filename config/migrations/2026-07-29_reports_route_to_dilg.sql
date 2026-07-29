-- Reports rework: SK and PPSK reports now all route directly to DILG,
-- flattening the old SK -> PPSK -> DILG chain. sked_report_target_role()
-- in includes/reports.php now always resolves to 'dilg' for new
-- submissions; this one-time backfill brings existing monthly reports
-- (previously target_role='ppsk') in line so DILG inherits full history
-- instead of only seeing reports submitted after this change.

UPDATE reports SET target_role = 'dilg' WHERE type = 'monthly' AND target_role = 'ppsk';
