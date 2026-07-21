<?php
/**
 * ============================================================
 * File     : pages/manage/report_file.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Gated download/view for report attachments. Files live under
 *            uploads/reports/ and are streamed only after role/scope checks.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/reports.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$reportId = (int) ($_GET['id'] ?? 0);

$report = sked_get_report($reportId);
if ($report === null || empty($report['attachment_file_path'])) {
    http_response_code(404);
    exit('Not found.');
}
if (!sked_can_access_report($report, $role, $userId, $barangayId)) {
    http_response_code(403);
    exit('Forbidden.');
}

$path = sked_report_upload_dir() . '/' . basename((string) $report['attachment_file_path']);
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
];
$downloadName = (string) ($report['attachment_file_original_name'] ?? basename($path));

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
