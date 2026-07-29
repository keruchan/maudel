<?php
/**
 * ============================================================
 * File     : pages/manage/abyip_export.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Print/export view for an ABYIP (P12), matching the real
 *            "Annex 6" DILG/NYC template layout — WITHOUT logos, ready
 *            for physical signatures. Same access rule as abyip_plan.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/abyip.php';

require_roles(['sk', 'ppsk', 'dilg', 'youth']);

$role = (string) $_SESSION['role'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$planId = (int) ($_GET['id'] ?? 0);
$plan = sked_abyip_get($planId);
if ($plan === null
    || (in_array($role, ['sk', 'youth'], true) && (int) $plan['barangay_id'] !== $sessionBarangayId)
    || ($role === 'youth' && (string) $plan['status'] !== 'finalized')) {
    header('Location: ' . ($role === 'sk' ? 'abyip.php' : '../' . $role . '/' . ($role === 'youth' ? 'full_disclosure.php' : 'dashboard.php')));
    exit;
}
$year = (int) $plan['calendar_year'];
$totals = sked_abyip_total_budget($plan);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>ABYIP CY <?php echo $year; ?> &mdash; Brgy. <?php echo e($plan['barangay_name']); ?></title>
<style>
  body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;margin:0;padding:24px;}
  .annex{font-size:10pt;margin-bottom:8px;}
  .meta-row{display:flex;justify-content:space-between;font-size:11pt;margin-bottom:4px;}
  .meta-row u{font-weight:bold;}
  .doc-title{text-align:center;font-weight:bold;font-size:14pt;text-transform:uppercase;margin:10px 0 4px;}
  .doc-subtitle{text-align:center;font-weight:bold;font-size:12pt;margin-bottom:14px;}
  .section-title{font-weight:bold;font-size:11.5pt;margin:18px 0 4px;text-transform:uppercase;}
  table{width:100%;border-collapse:collapse;margin-bottom:6px;}
  th,td{border:1px solid #000;padding:4px 6px;font-size:9pt;vertical-align:top;text-align:left;}
  th{background:#cbbf99;text-align:center;font-weight:bold;}
  .num{text-align:right;white-space:nowrap;}
  .totals-row td{font-weight:bold;background:#f0ede2;}
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

  <div class="annex">Annex 6. Annual Barangay Youth Investment Program (ABYIP)</div>
  <div class="meta-row">
    <span>Region: <u><?php echo e($plan['region']); ?></u></span>
    <span>Province: <u>Laguna</u></span>
    <span>City/Municipality: <u><?php echo e($plan['city_municipality']); ?></u></span>
    <span>Barangay: <u><?php echo e(strtoupper($plan['barangay_name'])); ?></u></span>
  </div>

  <div class="doc-title">Annual Barangay Youth Investment Program (ABYIP)</div>
  <div class="doc-subtitle">Calendar Year <?php echo $year; ?></div>

  <?php foreach ($plan['sections'] as $section): ?>
    <div class="section-title">Center of Participation: <?php echo e($section['center_of_participation']); ?></div>
    <table>
      <thead>
        <tr>
          <th rowspan="2">Reference Code</th>
          <th rowspan="2">PPA's</th>
          <th rowspan="2">Description</th>
          <th rowspan="2">Expected Result</th>
          <th rowspan="2">Performance Indicator</th>
          <th rowspan="2">Period of Implementation</th>
          <th colspan="3">Budget Proposed</th>
          <th rowspan="2">Person Responsible</th>
        </tr>
        <tr><th>MOOE</th><th>CO</th><th>Total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($section['items'] as $item): ?>
        <tr>
          <td><?php echo e((string) ($item['reference_code'] ?? '')); ?></td>
          <td><?php echo e((string) $item['ppa_name']); ?></td>
          <td><?php echo e((string) ($item['description'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['expected_result'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['performance_indicator'] ?? '')); ?></td>
          <td><?php echo e((string) ($item['period_of_implementation'] ?? '')); ?></td>
          <td class="num"><?php echo number_format((float) $item['budget_mooe'], 2); ?></td>
          <td class="num"><?php echo number_format((float) $item['budget_co'], 2); ?></td>
          <td class="num"><?php echo number_format((float) $item['budget_total'], 2); ?></td>
          <td><?php echo e((string) ($item['person_responsible'] ?? '')); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($section['items'])): ?><tr><td colspan="10" style="text-align:center;color:#666;">No entries yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
  <?php if (empty($plan['sections'])): ?><p style="text-align:center;color:#666;">No sections yet.</p><?php endif; ?>

  <table style="max-width:340px;margin-left:auto;">
    <tbody>
      <tr class="totals-row"><td>Total MOOE</td><td class="num"><?php echo number_format($totals['mooe'], 2); ?></td></tr>
      <tr class="totals-row"><td>Total CO</td><td class="num"><?php echo number_format($totals['co'], 2); ?></td></tr>
      <tr class="totals-row"><td>Grand Total</td><td class="num"><?php echo number_format($totals['total'], 2); ?></td></tr>
    </tbody>
  </table>

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
