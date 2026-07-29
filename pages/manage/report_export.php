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
require_once __DIR__ . '/../../includes/compliance.php';

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

$isDismissal = (string) $report['type'] === 'dismissal_recommendation';
$subjectSk = null;
$skStrikes = [];
$reviewerName = '';
if ($isDismissal && !empty($report['ref_user_id'])) {
    $skStmt = sked_db()->prepare('SELECT name, barangay_id, former_role_badge FROM users WHERE id = :id LIMIT 1');
    $skStmt->execute(['id' => (int) $report['ref_user_id']]);
    $subjectSk = $skStmt->fetch() ?: null;
    $skStrikes = sked_strikes_for_sk((int) $report['ref_user_id']);
}
if ($isDismissal && !empty($report['reviewed_by'])) {
    $reviewerStmt = sked_db()->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
    $reviewerStmt->execute(['id' => (int) $report['reviewed_by']]);
    $reviewerName = (string) $reviewerStmt->fetchColumn();
}
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
  .letterhead{text-align:center;margin-bottom:16px;}
  .letterhead div{line-height:1.3;}
  .letterhead .office{font-weight:bold;text-transform:uppercase;margin-top:4px;}
  .doc-title{text-align:center;font-weight:bold;font-size:15pt;text-transform:uppercase;letter-spacing:.03em;margin:16px 0 4px;}
  .doc-subtitle{text-align:center;color:#4b5563;font-size:10pt;margin-bottom:18px;}
  .subject-box{border:1.5px solid #111827;padding:14px 16px;margin:16px 0;}
  .subject-box .label{margin-bottom:2px;}
  .subject-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 24px;}
  .strikes-table{width:100%;border-collapse:collapse;margin:10px 0 4px;font-size:10.5pt;}
  .strikes-table th,.strikes-table td{border:1px solid #d1d5db;padding:6px 8px;text-align:left;}
  .strikes-table th{background:#f3f4f6;text-transform:uppercase;font-size:8.5pt;letter-spacing:.04em;}
  .outcome-box{border:1.5px solid #111827;padding:14px 16px;margin:18px 0;background:#f9fafb;}
  .sign-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 40px;margin-top:56px;}
  .sign-line{border-top:1px solid #111827;padding-top:6px;margin-top:48px;}
  .sign-name{font-weight:bold;text-transform:uppercase;}
  .sign-role{font-size:9.5pt;color:#4b5563;}
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

  <?php if ($isDismissal): ?>
    <div class="letterhead">
      <div>Republic of the Philippines</div>
      <div>Province of Laguna</div>
      <div>Municipality of Siniloan</div>
      <div class="office">Department of the Interior and Local Government</div>
    </div>
    <div class="doc-title">SK Compliance Dismissal Recommendation</div>
    <div class="doc-subtitle">Sangguniang Kabataan Accountability &amp; Compliance Review</div>
  <?php else: ?>
  <div class="doc-header">
    <div class="eyebrow">SKed Report Export</div>
    <h1><?php echo e((string) $report['title']); ?></h1>
    <div><?php echo e($typeLabel); ?> <span class="status"><?php echo e((string) $report['status']); ?></span></div>
  </div>
  <?php endif; ?>

  <?php if ($isDismissal): ?>
    <div class="subject-box">
      <span class="label">Subject Official</span>
      <div class="subject-grid">
        <div><strong><?php echo e($subjectSk !== null ? (string) $subjectSk['name'] : 'Unknown'); ?></strong> &mdash; SK Chairperson</div>
        <div>Barangay <?php echo e($barangayName !== '' ? $barangayName : '—'); ?></div>
      </div>
      <?php if ($subjectSk !== null && !empty($subjectSk['former_role_badge'])): ?>
        <div class="small" style="margin-top:6px;font-style:italic;">Current standing: <?php echo e((string) $subjectSk['former_role_badge']); ?></div>
      <?php endif; ?>
    </div>

    <h2 style="font-size:13pt;margin-bottom:6px;">Grounds: Compliance Strike History</h2>
    <?php if (empty($skStrikes)): ?>
      <p class="small" style="color:#4b5563;">No strikes currently on file for this official (may have been cleared since this report was filed).</p>
    <?php else: ?>
      <table class="strikes-table">
        <thead><tr><th>Period</th><th>Reason</th><th>Recorded</th></tr></thead>
        <tbody>
        <?php foreach ($skStrikes as $s): ?>
          <tr>
            <td><?php echo e(date('F Y', strtotime((string) $s['period_month'] . '-01'))); ?></td>
            <td><?php echo e((string) $s['reason']); ?></td>
            <td><?php echo e(date('M j, Y', strtotime((string) $s['created_at']))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endif; ?>

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

  <h2 style="font-size:14pt;margin-bottom:6px;"><?php echo $isDismissal ? 'Basis for Recommendation' : 'Report Content'; ?></h2>
  <div class="content"><?php echo e((string) ($report['content'] ?? 'No written content provided.')); ?></div>

  <?php if ($isDismissal): ?>
    <div class="outcome-box">
      <span class="label">DILG Action / Outcome</span>
      <div><strong><?php echo e(sked_report_status_label((string) $report['status'], true)); ?></strong>
        <?php if (!empty($report['reviewed_at'])): ?> — <?php echo e(date('F j, Y', strtotime((string) $report['reviewed_at']))); ?><?php endif; ?>
      </div>
      <?php if (!empty($report['review_comments'])): ?>
        <div style="margin-top:8px;white-space:pre-wrap;"><?php echo nl2br(e((string) $report['review_comments'])); ?></div>
      <?php endif; ?>
      <?php if ((string) $report['status'] === 'submitted'): ?>
        <div style="margin-top:8px;color:#4b5563;font-style:italic;">No action taken yet — pending DILG review.</div>
      <?php endif; ?>
    </div>

    <div class="sign-grid">
      <div class="sign-line">
        <div class="sign-name"><?php echo e((string) ($report['submitted_by_name'] ?? '_________________________')); ?></div>
        <div class="sign-role">Reporting PPSK President</div>
      </div>
      <div class="sign-line">
        <div class="sign-name"><?php echo e($reviewerName !== '' ? $reviewerName : '_________________________'); ?></div>
        <div class="sign-role">Reviewing DILG Officer</div>
      </div>
    </div>
  <?php endif; ?>

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
