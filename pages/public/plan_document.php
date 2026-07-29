<?php
/**
 * ============================================================
 * File     : pages/public/plan_document.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Public, no-login streamer for an uploaded plan document
 *            (CBYDP/ABYIP/Annual Budget/Monthly Purchase Request — see
 *            includes/plan_documents.php). Every upload is public by
 *            design (full-disclosure/transparency), so unlike
 *            pages/manage/report_file.php this deliberately has NO
 *            require_roles() gate — anyone with the link, logged in or
 *            not, can view it.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/plan_documents.php';

$id = (int) ($_GET['id'] ?? 0);
$doc = sked_get_plan_document($id);
if ($doc === null) {
    http_response_code(404);
    exit('Not found.');
}

$path = sked_plan_document_upload_dir() . '/' . basename((string) $doc['file_path']);
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
$downloadName = (string) ($doc['file_original_name'] ?? basename($path));

header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $downloadName) . '"');
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
