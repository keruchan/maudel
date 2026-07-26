<?php
/**
 * ============================================================
 * File     : pages/api/attendance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : JSON endpoint behind both QR attendance scanners (P19).
 *            Called by js/qr-attendance.js as codes are decoded, so the
 *            scanner never has to reload between people in the queue.
 *
 *   POST action=officer_scan  (sk/ppsk/dilg) event_id, payload
 *        -> resolves a youth QR / typed KK ID and marks them present
 *   POST action=self_scan     (youth)        payload
 *        -> resolves an event QR and marks the caller present
 *
 * Requires an active session; POST additionally requires the
 * csrf_attendance_token issued by the page that hosts the scanner.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attendance.php';

header('Content-Type: application/json');

if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Not authenticated.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'POST required.']);
    exit;
}

$token = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_attendance_token']) || !hash_equals((string) $_SESSION['csrf_attendance_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Security validation failed. Reload the page.']);
    exit;
}

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$action = (string) ($_POST['action'] ?? '');
$payload = (string) ($_POST['payload'] ?? '');

if ($payload === '' || mb_strlen($payload) > 300) {
    echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Empty or oversized code.']);
    exit;
}

if ($action === 'officer_scan') {
    if (!in_array($role, ['sk', 'ppsk', 'dilg'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Officials only.']);
        exit;
    }
    $actor = [
        'id' => $userId,
        'role' => $role,
        'barangay_id' => isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null,
    ];
    $result = sked_attendance_officer_scan($actor, (int) ($_POST['event_id'] ?? 0), $payload);
    $result['summary'] = sked_attendance_summary((int) ($_POST['event_id'] ?? 0));
    echo json_encode($result);
    exit;
}

if ($action === 'self_scan') {
    if ($role !== 'youth') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Youth accounts only.']);
        exit;
    }
    if (!sked_is_verified()) {
        echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Your KK membership must be verified before you can check in.']);
        exit;
    }
    echo json_encode(sked_attendance_self_scan([
        'id' => $userId,
        'barangay_id' => isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null,
    ], $payload));
    exit;
}

echo json_encode(['ok' => false, 'result' => 'rejected', 'message' => 'Unknown action.']);
