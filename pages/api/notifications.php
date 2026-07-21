<?php
/**
 * ============================================================
 * File     : pages/api/notifications.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : JSON endpoint backing the notification bell (P10, spec 4.5:
 *            "functional, real-time or polling-based, per-user notification
 *            center"). Polled by js/notifications.js.
 *
 *   GET                       -> {unread_count, notifications: [...]}
 *   POST action=mark_all      -> marks all read, returns the same shape
 *   POST action=mark_one&id=N -> marks one read (scoped to the caller), same shape
 *
 * Requires an active session (any role) — no CSRF cookie/GET reads happen
 * without one; POST additionally requires the csrf_notif_token embedded in
 * the bell markup by render_sked_notification_bell().
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

header('Content-Type: application/json');

if (empty($_SESSION['id']) || empty($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$userId = (int) $_SESSION['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_notif_token']) || !hash_equals((string) $_SESSION['csrf_notif_token'], $token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Security validation failed.']);
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'mark_all') {
        sked_mark_all_read($userId);
    } elseif ($action === 'mark_one') {
        sked_mark_notification_read($userId, (int) ($_POST['id'] ?? 0));
    }
}

$notifications = array_map(static function (array $n): array {
    $visual = sked_notification_visual((string) $n['type']);
    return [
        'id' => (int) $n['id'],
        'title' => $n['title'],
        'message' => $n['message'],
        'link' => $n['link'],
        'is_read' => (bool) $n['is_read'],
        'when' => sked_relative_time((string) $n['created_at']),
        'icon' => $visual['icon'],
        'accent' => $visual['accent'],
    ];
}, sked_recent_notifications($userId, 15));

echo json_encode([
    'unread_count' => sked_unread_count($userId),
    'notifications' => $notifications,
]);
