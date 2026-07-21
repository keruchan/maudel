<?php
/**
 * ============================================================
 * File     : pages/manage/cbydp_export.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Print/export view for a CBYDP (P12), matching the real DILG/
 *            NYC Annex template layout — WITHOUT the barangay/SK seal
 *            logos (per spec: "exportable... excluding logos"), ready for
 *            physical signatures. Same access rule as cbydp_plan.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/cbydp.php';

require_roles(['sk', 'ppsk', 'dilg']);

$role = (string) $_SESSION['role'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$planId = (int) ($_GET['id'] ?? 0);
$plan = sked_cbydp_get($planId);
if ($plan === null || ($role === 'sk' && (int) $plan['barangay_id'] !== $sessionBarangayId)) {
    header('Location: ' . ($role === 'sk' ? 'cbydp.php' : '../' . $role . '/dashboard.php'));
    exit;
}
$yearStart = (int) $plan['cy_year_start'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>CBYDP CY <?php echo $yearStart; ?> &mdash; Brgy. <?php echo e($plan['barangay_name']); ?></title>
<style>
  body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;margin:0;padding:24px;}
  .doc-header{text-align:center;margin-bottom:14px;}
  .doc-header .country{font-size:12pt;}
  .doc-title{text-align:center;font-weight:bold;font-size:14pt;text-transform:uppercase;margin:10px 0;}
  .meta-row{display:flex;justify-content:space-between;font-size:11pt;margin-bottom:4px;}
  .meta-row u{font-weight:bold;}
  .agenda{font-size:11pt;margin:8px 0 14px;}
  .section-title{font-weight:bold;font-size:12pt;margin:18px 0 4px;text-transform:uppercase;}
  table{width:100%;border-collapse:collapse;margin-bottom:6px;}
  th,td{border:1px solid #000;padding:4px 6px;font-size:9.5pt;vertical-align:top;text-align:left;}
  th{background:#cbbf99;text-align:center;font-weight:bold;}
  .num{text-align:right;white-space:nowrap;}
  .sign-block{display:flex;justify-content:space-between;margin-top:40px;}
  .sign-col{width:45%;}
  .sign-label{margin:0 0 40px;}
  .sign-name{font-weight:bold;text-transform:uppercase;text-align:center;}
  .sign-role{font-size:10pt;text-align:center;}
  .print-bar{margin-bottom:18px;}
  @media print{ .print-bar{display:none;} body{padding:0;} }
</style>
</head>
<body>
  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>

  <div class="doc-header">
    <div class="country">Republic of the Philippines</div>
    <div>Province of Laguna</div>
    <div>Municipality of <?php echo e($plan['city_municipality']); ?></div>
    <div>Barangay <u><?php echo e(strtoupper($plan['barangay_name'])); ?></u></div>
    <div>OFFICE OF SANGGUNIANG KABATAAN</div>
  </div>

  <div class="doc-title">Comprehensive Barangay Youth Development Plan (CBYDP) CY <?php echo $yearStart; ?></div>
  <div class="meta-row">
    <span>Region: <u><?php echo e($plan['region']); ?></u></span>
    <span>Province: <u>Laguna</u> City/Municipality: <u><?php echo e($plan['city_municipality']); ?></u></span>
  </div>

  <?php foreach ($plan['sections'] as $section): ?>
    <div class="section-title">Center of Participation: <?php echo e($section['center_of_participation']); ?></div>
    <?php if (!empty($section['agenda_statement'])): ?>
      <div class="agenda"><strong>Agenda Statement:</strong> <?php echo e((string) $section['agenda_statement']); ?></div>
    <?php endif; ?>
    <table>
      <thead>
        <tr>
          <th rowspan="2">Youth Development Concern</th>
          <th rowspan="2">Objective</th>
          <th rowspan="2">Performance Indicator</th>
          <th colspan="3">Target</th>
          <th rowspan="2">PPAs</th>
          <th rowspan="2">Budget</th>
          <th rowspan="2">Person Responsible</th>
        </tr>
        <tr>
          <th><?php echo $yearStart; ?></th><th><?php echo $yearStart + 1; ?></th><th><?php echo $yearStart + 2; ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($section['items'] as $item): ?>
        <tr>
          <td><?php echo e((string) $item['youth_development_concern']); ?></td>
          <td><?php echo e((string) ($item['objective'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['performance_indicator'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['target_year1'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['target_year2'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['target_year3'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['ppas'] ?? '')); ?></td>
          <td class="num"><?php echo $item['budget'] !== null ? number_format((float) $item['budget'], 2) : ''; ?></td>
          <td><?php echo e((string) ($item['person_responsible'] ?? '')); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($section['items'])): ?><tr><td colspan="9" style="text-align:center;color:#666;">No entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <?php if (empty($plan['sections'])): ?><p style="text-align:center;color:#666;">No sections yet.</p><?php endif; ?>

  <div class="sign-block">
    <div class="sign-col">
      <p class="sign-label">Prepared by:</p>
      <div class="sign-name"><?php echo e(strtoupper((string) ($plan['prepared_by_name'] ?? ''))); ?></div>
      <div class="sign-role"><?php echo e((string) $plan['prepared_by_role']); ?></div>
    </div>
    <div class="sign-col">
      <p class="sign-label">Approved by:</p>
      <div class="sign-name">HON. <?php echo e(strtoupper((string) ($plan['approved_by_name'] ?? ''))); ?></div>
      <div class="sign-role"><?php echo e((string) $plan['approved_by_role']); ?></div>
    </div>
  </div>
</body>
</html>
