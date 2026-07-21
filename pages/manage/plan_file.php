<?php
/**
 * ============================================================
 * File     : pages/manage/plan_file.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Gated download of a CBYDP/ABYIP signed-copy upload (P12).
 *            Files live under uploads/plans/{type}/ which is itself
 *            denied to direct web access (see includes/plan_uploads.php);
 *            this is the only path back to the actual bytes, and it
 *            re-checks the requester's role/barangay scope first.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/cbydp.php';
require_once __DIR__ . '/../../includes/abyip.php';
require_once __DIR__ . '/../../includes/katitikan.php';
require_once __DIR__ . '/../../includes/plan_uploads.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$type = (string) ($_GET['type'] ?? '');
$planId = (int) ($_GET['id'] ?? 0);

if (!in_array($type, SKED_PLAN_UPLOAD_TYPES, true)) {
    http_response_code(404);
    exit('Not found.');
}

$plan = match ($type) {
    'cbydp' => sked_cbydp_get($planId),
    'abyip' => sked_abyip_get($planId),
    'katitikan' => sked_katitikan_get($planId),
};
if ($plan === null || empty($plan['signed_file_path'])) {
    http_response_code(404);
    exit('Not found.');
}
if ($role === 'sk' && (int) $plan['barangay_id'] !== $sessionBarangayId) {
    http_response_code(403);
    exit('Forbidden.');
}

$path = sked_plan_upload_dir($type) . '/' . basename((string) $plan['signed_file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$downloadName = (string) ($plan['signed_file_original_name'] ?? basename($path));

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
