<?php
/**
 * ============================================================
 * File     : pages/auth/logout.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : End the current session and return to login.
 *
 * Security notes:
 * - CSRF-protected POST action (not a plain GET link).
 * - Clears session variables, destroys server-side session data,
 *   and expires the browser's session cookie.
 *
 * reason=idle (set by js/idle-timeout.js before it auto-submits the
 * sidebar's logout form after 30 minutes of no interaction) redirects to
 * the login page with ?timeout=1 so it can show an explanatory message.
 * Server-side, require_roles() enforces the same 30-minute timeout
 * independently (see SKED_IDLE_TIMEOUT_SECONDS in includes/auth.php) — this
 * client-triggered path is a faster/friendlier UX on top of that, not a
 * substitute for it.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$submittedToken = (string) ($_POST['csrf_token'] ?? '');
$sessionToken = (string) ($_SESSION['csrf_logout_token'] ?? '');
if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    exit('Security validation failed. Please return to the page you were on and try again.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $cookieParams['path'] ?? '/',
        'domain'   => $cookieParams['domain'] ?? '',
        'secure'   => (bool) ($cookieParams['secure'] ?? false),
        'httponly' => (bool) ($cookieParams['httponly'] ?? true),
        'samesite' => $cookieParams['samesite'] ?? 'Lax',
    ]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

$isIdle = ($_POST['reason'] ?? '') === 'idle';
header('Location: login.php' . ($isIdle ? '?timeout=1' : ''));
exit;
