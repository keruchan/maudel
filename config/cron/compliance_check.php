<?php
/**
 * ============================================================
 * File     : config/cron/compliance_check.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Scheduled job (P7). Once past the 10th of the month, checks
 *            every active SK barangay for last month's monthly report and
 *            adds a compliance strike (+ notifications) for any missing.
 *            Idempotent — safe to run daily; only ever strikes once per
 *            SK per missed month.
 *
 * Schedule (Windows Task Scheduler, daily ~1:00 AM):
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\SKed\config\cron\compliance_check.php"
 *
 * CLI-only guard: refuses to run over HTTP.
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/role_transitions.php';
require_once __DIR__ . '/../../includes/compliance.php';

date_default_timezone_set('Asia/Manila');

$result = sked_run_compliance_check();

printf(
    "[%s] Compliance check complete: %d SK barangays checked, %d strikes added.\n",
    date('Y-m-d H:i:s'),
    $result['checked'],
    $result['strikes_added']
);
