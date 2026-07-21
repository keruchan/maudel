<?php
/**
 * ============================================================
 * File     : pages/manage/report_export.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Printable/exportable report view shared by PPSK and DILG.
 *            Includes gated attachment links when the report has a file.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/reports.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$reportId = (int) ($_GET['id'] ?? 0);
$report = sked_get_report($reportId);

if ($report === null || !sked_can_access_report($report, $role, $userId, $barangayId)) {
    header('Location: ../' . $role . '/dashboard.php');
    exit;
}

$typeLabels = [
    'monthly' => 'Monthly Report',
    'interbarangay' => 'Inter-barangay Event Report',
    'minutes' => 'Minutes of Meeting',
    'dismissal_recommendation' => 'Dismissal Recommendation',
    'turnover' => 'Turnover Report',
];
$typeLabel = $typeLabels[(string) $report['type']] ?? ucwords(str_replace('_', ' ', (string) $report['type']));
$barangayName = !empty($report['barangay_id']) ? sked_barangay_name((int) $report['barangay_id']) : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo e($typeLabel); ?> - <?php echo e((string) $report['title']); ?></title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;color:#111827;margin:0;padding:28px;font-size:12pt;line-height:1.45;}
  .print-bar{display:flex;gap:8px;margin-bottom:20px;}
  button,a.button{border:1px solid #111827;background:#111827;color:#fff;border-radius:6px;padding:8px 12px;text-decoration:none;font:inherit;cursor:pointer;}
  a.button.secondary{background:#fff;color:#111827;}
  .doc-header{border-bottom:2px solid #111827;padding-bottom:14px;margin-bottom:18px;}
  .eyebrow{text-transform:uppercase;letter-spacing:.08em;font-size:9pt;color:#4b5563;font-weight:bold;}
  h1{font-size:20pt;margin:6px 0 8px;}
  .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 24px;margin:18px 0;}
  .meta div{border-bottom:1px solid #d1d5db;padding-bottom:5px;}
  .label{display:block;font-size:8.5pt;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;font-weight:bold;}
  .content{white-space:pre-wrap;border:1px solid #d1d5db;padding:16px;min-height:160px;margin-top:12px;}
  .attachment{margin-top:18px;padding:12px;border:1px solid #111827;background:#f9fafb;}
  .status{display:inline-block;border:1px solid #111827;border-radius:999px;padding:2px 9px;font-size:10pt;text-transform:capitalize;}
  @media print{.print-bar{display:none;} body{padding:0;} a{color:#111827;text-decoration:none;} .content{min-height:120px;}}
</style>
</head>
<body>
  <div class="print-bar">
    <button onclick="window.print()">Print / Save as PDF</button>
    <?php if (!empty($report['attachment_file_path'])): ?>
      <a class="button secondary" href="report_file.php?id=<?php echo (int) $report['id']; ?>" target="_blank">Open Attachment</a>
    <?php endif; ?>
  </div>

  <div class="doc-header">
    <div class="eyebrow">SKed Report Export</div>
    <h1><?php echo e((string) $report['title']); ?></h1>
    <div><?php echo e($typeLabel); ?> <span class="status"><?php echo e((string) $report['status']); ?></span></div>
  </div>

  <div class="meta">
    <div><span class="label">Submitted by</span><?php echo e((string) ($report['submitted_by_name'] ?? '')); ?></div>
    <div><span class="label">Submitted on</span><?php echo e(date('F j, Y g:i A', strtotime((string) $report['submitted_at']))); ?></div>
    <div><span class="label">Target office</span><?php echo e(strtoupper((string) $report['target_role'])); ?></div>
    <div><span class="label">Origin barangay</span><?php echo e($barangayName !== '' ? $barangayName : 'Federation / city-wide'); ?></div>
    <?php if (!empty($report['period_month'])): ?>
      <div><span class="label">Reporting month</span><?php echo e(date('F Y', strtotime((string) $report['period_month'] . '-01'))); ?></div>
    <?php endif; ?>
    <?php if (!empty($report['reviewed_at'])): ?>
      <div><span class="label">Reviewed on</span><?php echo e(date('F j, Y g:i A', strtotime((string) $report['reviewed_at']))); ?></div>
    <?php endif; ?>
  </div>

  <h2 style="font-size:14pt;margin-bottom:6px;">Report Content</h2>
  <div class="content"><?php echo e((string) ($report['content'] ?? 'No written content provided.')); ?></div>

  <?php if (!empty($report['attachment_file_path'])): ?>
    <div class="attachment">
      <strong>Attachment:</strong>
      <a href="report_file.php?id=<?php echo (int) $report['id']; ?>" target="_blank"><?php echo e((string) $report['attachment_file_original_name']); ?></a>
      <?php if (!empty($report['attachment_uploaded_at'])): ?>
        <span>uploaded <?php echo e(date('F j, Y', strtotime((string) $report['attachment_uploaded_at']))); ?></span>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($report['katitikan_id'])): ?>
    <p><strong>Full Katitikan:</strong> <a href="katitikan_export.php?id=<?php echo (int) $report['katitikan_id']; ?>" target="_blank">Open structured minutes export</a></p>
  <?php endif; ?>
</body>
</html>
