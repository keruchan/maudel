<?php
/**
 * Streams event images from the private uploads folder.
 * Published/live event images are public; draft images require manager access.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/events.php';

$eventId = (int) ($_GET['id'] ?? 0);
$token = trim((string) ($_GET['t'] ?? ''));
$event = sked_get_event($eventId);

$role = (string) ($_SESSION['role'] ?? '');
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$isPublicVisible = $event !== null
    && $token !== ''
    && hash_equals((string) $event['share_token'], $token)
    && in_array((string) $event['status'], ['published', 'confirmed', 'ongoing', 'completed', 'evaluation'], true);
$canManage = $event !== null && $role !== '' && sked_can_manage_event($role, $barangayId, $event);

if ($event === null || empty($event['image_file_path']) || (!$isPublicVisible && !$canManage)) {
    http_response_code(404);
    exit;
}

$path = sked_event_image_path($event);
if ($path === null || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
];

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
