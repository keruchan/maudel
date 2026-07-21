<?php
/**
 * ============================================================
 * File     : config/config.php
 * Project  : SKed - A Web-Based Youth Profiling System for
 *            Event Management (Sangguniang Kabataan)
 * Purpose  : Central configuration file for the SKED skeleton.
 *            - Starts a hardened PHP session (holds the demo
 *              authentication/role state used by every page under
 *              pages/dilg/, pages/ppsk/, pages/sk/, pages/youth/).
 *            - Opens the shared MySQL (PDO) connection.
 *            - Sets the project timezone.
 *
 * NOTE:
 *   Demo login (usernames 1, 2, 3, 4 mapping to the four roles, no
 *   password) is still a hardcoded, no-DB shortcut — see
 *   includes/auth.php — so the role dashboards stay reachable even if
 *   MySQL is down. Self-registered community accounts, however, are
 *   now persisted in the `sked_db` MySQL database (config/database.php,
 *   config/schema.sql) instead of the earlier JSON-file store.
 *
 * Include this file at the TOP of every page, e.g.:
 *      require_once __DIR__ . '/../../config/config.php';
 * ============================================================
 */

// ------------------------------------------------------------
// 1. SESSION HANDLING
// ------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ------------------------------------------------------------
// 2. ERROR REPORTING
// ------------------------------------------------------------
// Skeleton/dev default: display errors on so layout wiring issues are
// visible during development. Flip to false for any shared deployment.
define('DISPLAY_ERRORS', true);

if (DISPLAY_ERRORS) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}

// ------------------------------------------------------------
// 3. TIMEZONE
// ------------------------------------------------------------
date_default_timezone_set('Asia/Manila');

// ------------------------------------------------------------
// 4. DATABASE
// ------------------------------------------------------------
// Provides sked_db(): PDO for anything that needs MySQL (currently
// includes/auth.php's registration/login queries). Connection is opened
// lazily on first sked_db() call, not here, so pages that never touch the
// database (e.g. the demo-only DILG/PPSK/SK dashboards) don't pay for it.
require_once __DIR__ . '/database.php';
