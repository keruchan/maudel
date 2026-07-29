<?php
/**
 * Streams announcement images from the private uploads folder.
 * Published announcement images are public; drafts require manager access.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/announcements.php';

$announcementId = (int) ($_GET['id'] ?? 0);
$announcement = sked_get_announcement($announcementId);

$role = (string) ($_SESSION['role'] ?? '');
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : null;
$isPublicVisible = $announcement !== null && $announcement['status'] === 'published';
$canManage = $announcement !== null && $role !== '' && sked_can_manage_announcement($role, $barangayId, $announcement);

if ($announcement === null || empty($announcement['image_file_path']) || (!$isPublicVisible && !$canManage)) {
    http_response_code(404);
    exit;
}

$path = sked_announcement_image_path($announcement);
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
