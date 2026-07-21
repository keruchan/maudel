<?php
/**
 * ============================================================
 * File     : config/cron/event_maintenance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Scheduled job (P4). Runs event housekeeping so it happens on
 *            time regardless of whether an admin is browsing:
 *              1. Auto-cancel published events that passed their
 *                 registration deadline without meeting min_participants,
 *                 and notify anyone who had signed up.
 *              2. Send day-before reminders to participants.
 *
 * Schedule (Windows Task Scheduler, daily ~1:00 AM):
 *   "C:\xampp\php\php.exe" "C:\xampp\htdocs\SKed\config\cron\event_maintenance.php"
 *
 * Or cron on *nix:
 *   0 1 * * * /usr/bin/php /var/www/SKed/config/cron/event_maintenance.php
 *
 * CLI-only guard: refuses to run over HTTP.
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script runs from the command line only.');
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../../includes/events.php';

date_default_timezone_set('Asia/Manila');

$result = sked_run_event_maintenance();

printf(
    "[%s] Event maintenance complete: %d auto-cancelled, %d reminder batches sent.\n",
    date('Y-m-d H:i:s'),
    $result['cancelled'],
    $result['reminded']
);
