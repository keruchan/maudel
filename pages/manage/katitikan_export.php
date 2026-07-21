<?php
/**
 * ============================================================
 * File     : pages/manage/katitikan_export.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Print/export view for a Katitikan (P13), matching the real
 *            Brgy. J.P. Rizal minutes sample layout — WITHOUT the
 *            barangay/SK seal logos, ready for physical signatures. Same
 *            access rule as katitikan.php (SK own barangay, or DILG any).
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/katitikan.php';

require_roles(['sk', 'dilg']);

$role = (string) $_SESSION['role'];
$sessionBarangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$id = (int) ($_GET['id'] ?? 0);
$k = sked_katitikan_get($id);
if ($k === null || ($role === 'sk' && (int) $k['barangay_id'] !== $sessionBarangayId)) {
    header('Location: ' . ($role === 'sk' ? 'katitikan.php' : '../' . $role . '/dashboard.php'));
    exit;
}

$present = array_values(array_filter($k['attendees'], static fn($a) => $a['attendance_status'] === 'present'));
$absent = array_values(array_filter($k['attendees'], static fn($a) => $a['attendance_status'] === 'absent'));
$meetingDateLabel = strtoupper(date('F j, Y', strtotime((string) $k['meeting_date'])));
$meetingTimeLabel = strtoupper(sked_format_time_filipino((string) $k['meeting_time']));
$barangayUpper = strtoupper($k['barangay_name']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Minutes No. <?php echo e((string) $k['session_no']); ?>, Series of <?php echo (int) $k['series_year']; ?></title>
<style>
  body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;margin:0;padding:24px;}
  .doc-header{text-align:center;margin-bottom:14px;}
  .doc-title{font-weight:bold;font-size:12pt;text-transform:uppercase;text-align:justify;margin:14px 0;}
  .present-list{width:100%;border-collapse:collapse;margin:10px 0;}
  .present-list td{padding:1px 4px;font-size:11pt;vertical-align:top;}
  .present-list td.name{padding-left:24px;}
  .present-list td.role{text-align:right;}
  .session-title{text-align:center;font-weight:bold;margin:16px 0;text-decoration:underline;}
  h3{font-size:12pt;margin:14px 0 4px;}
  p{margin:4px 0 10px;text-align:justify;}
  ul{margin:4px 0 10px;padding-left:22px;}
  ol{margin:4px 0 10px;padding-left:22px;}
  .cert-title{text-align:center;font-weight:bold;margin-top:22px;}
  .sign-block{margin-top:40px;}
  .sign-col{margin-top:40px;}
  .sign-name{font-weight:bold;text-transform:uppercase;}
  .print-bar{margin-bottom:18px;}
  @media print{ .print-bar{display:none;} body{padding:0;} }
</style>
</head>
<body>
  <div class="print-bar"><button onclick="window.print()">Print / Save as PDF</button></div>

  <div class="doc-header">
    <div>Republic of the Philippines</div>
    <div>Province of Laguna</div>
    <div>Municipality of Siniloan</div>
    <div style="font-weight:bold;">BARANGAY <?php echo e($barangayUpper); ?></div>
    <div>OFFICE OF THE SANGGUNIANG KABATAAN</div>
  </div>

  <div class="doc-title">
    MINUTES OF THE MONTHLY REGULAR MEETING OF THE SANGGUNIANG KABATAAN OF BARANGAY <?php echo e($barangayUpper); ?>, SINILOAN, LAGUNA HELD ON <?php echo e($meetingDateLabel); ?> AT <?php echo e($meetingTimeLabel); ?> HELD AT THE BARANGAY <?php echo e($barangayUpper); ?> <?php echo e(strtoupper((string) $k['venue'])); ?>.
  </div>

  <p><strong>PRESENT:</strong></p>
  <table class="present-list">
    <?php foreach ($present as $a): ?>
      <tr><td class="name"><?php echo e(strtoupper((string) $a['name'])); ?></td><td class="role"><?php echo e(strtoupper((string) $a['designation'])); ?></td></tr>
    <?php endforeach; ?>
  </table>

  <p><strong>ABSENT:</strong></p>
  <table class="present-list">
    <?php foreach ($absent as $a): ?>
      <tr><td class="name"><?php echo e(strtoupper((string) $a['name'])); ?></td><td class="role"><?php echo e(strtoupper((string) $a['designation'])); ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($absent)): ?><tr><td class="name">None.</td><td></td></tr><?php endif; ?>
  </table>

  <div class="session-title">MINUTES OF THE SESSION NO. <?php echo e((string) $k['session_no']); ?> - <?php echo (int) $k['series_year']; ?></div>

  <h3>I. CALL TO ORDER</h3>
  <p>The meeting was called to order by the Presiding Officer at exactly <?php echo e(sked_format_time_filipino((string) $k['meeting_time'])); ?>.</p>

  <h3>II. INVOCATION</h3>
  <p>The Invocation was led by <?php echo e((string) ($k['invocation_by'] ?? '____________________')); ?>.</p>

  <h3>III. ROLL CALL</h3>
  <p><?php echo nl2br(e((string) ($k['roll_call_notes'] ?? 'The presiding officer roll-called the SK Members.'))); ?></p>

  <h3>IV. READING AND APPROVAL OF THE MINUTES</h3>
  <p><?php echo nl2br(e((string) ($k['minutes_reading_notes'] ?? 'The SK Secretary read the minutes from the previous meeting.'))); ?></p>

  <h3>V. PRIVILEGE HOUR</h3>
  <?php if (!empty($k['privilege_items'])): ?>
  <ul>
    <?php foreach ($k['privilege_items'] as $item): ?>
      <li><?php echo !empty($item['speaker_name']) ? e((string) $item['speaker_name']) . ' ' : ''; ?><?php echo e((string) $item['proposal']); ?></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p>None.</p><?php endif; ?>

  <h3>VI. CALENDAR OF BUSINESS</h3>
  <p><strong>A. UNFINISHED BUSINESS</strong></p>
  <?php if (!empty($k['agenda_unfinished'])): ?>
  <ol><?php foreach ($k['agenda_unfinished'] as $item): ?><li><?php echo e((string) $item['description']); ?></li><?php endforeach; ?></ol>
  <?php else: ?><p>None.</p><?php endif; ?>

  <p><strong>B. AGENDA</strong></p>
  <?php if (!empty($k['agenda_agenda'])): ?>
  <ol><?php foreach ($k['agenda_agenda'] as $item): ?><li><?php echo e((string) $item['description']); ?></li><?php endforeach; ?></ol>
  <?php else: ?><p>None.</p><?php endif; ?>

  <p><strong>C. NEW BUSINESS</strong></p>
  <?php if (!empty($k['agenda_new'])): ?>
  <ol><?php foreach ($k['agenda_new'] as $item): ?><li><?php echo e((string) $item['description']); ?></li><?php endforeach; ?></ol>
  <?php else: ?><p>None.</p><?php endif; ?>

  <h3>VII. COMMITTEE REPORTS</h3>
  <p><?php echo nl2br(e((string) ($k['committee_reports'] ?? 'None.'))); ?></p>

  <h3>VIII. ANNOUNCEMENTS</h3>
  <p><?php echo nl2br(e((string) ($k['announcements'] ?? 'None.'))); ?></p>

  <h3>IX. ADJOURNMENT</h3>
  <p>There being no other matter to be discussed, <?php echo e((string) ($k['adjourned_by'] ?? '____________________')); ?> adjourned the Session<?php echo $k['adjournment_time'] !== null ? ' at exactly ' . e(sked_format_time_filipino((string) $k['adjournment_time'])) : ''; ?>.</p>

  <div class="cert-title">SECRETARY'S CERTIFICATION</div>
  <p>I HEREBY CERTIFY to the correctness of the foregoing Minutes of the Regular Session No. <?php echo e((string) $k['session_no']); ?> Series of <?php echo (int) $k['series_year']; ?> of the Sangguniang Kabataan of Barangay <?php echo e($k['barangay_name']); ?>, Siniloan Laguna held on the <?php echo e(date('jS', strtotime((string) $k['meeting_date']))); ?> day of <?php echo e(date('F, Y', strtotime((string) $k['meeting_date']))); ?> at the Barangay <?php echo e($k['barangay_name']); ?> <?php echo e((string) $k['venue']); ?>.</p>

  <div class="sign-block">
    <div class="sign-name"><?php echo e(strtoupper((string) ($k['prepared_by_name'] ?? ''))); ?></div>
    <div><?php echo e((string) $k['prepared_by_role']); ?></div>
  </div>

  <p style="margin-top:26px;"><strong>APPROVED:</strong></p>
  <div class="sign-col">
    <div class="sign-name"><?php echo e(strtoupper((string) ($k['approved_by_name'] ?? ''))); ?></div>
    <div><?php echo e((string) $k['approved_by_role']); ?></div>
  </div>
</body>
</html>
