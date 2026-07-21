<?php
/**
 * ============================================================
 * File     : pages/sk/reports.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : SK monthly report submission (P7, spec 4.3/6.4). Due by the
 *            10th of every month; missing it accrues a compliance strike.
 *            Shows compliance status and submission history.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/barangays.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/compliance.php';

require_role('sk');

$role = (string) $_SESSION['role'];
$skUserId = (int) $_SESSION['id'];
$barangayId = isset($_SESSION['barangay_id']) ? (int) $_SESSION['barangay_id'] : 0;
$barangayName = $barangayId > 0 ? sked_barangay_name($barangayId) : '';
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'SK Chairman';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_reports_token'])) {
    $_SESSION['csrf_reports_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_reports_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } elseif ($barangayId <= 0) {
        $flash = ['type' => 'danger', 'msg' => 'Your account is not assigned to a barangay.'];
    } else {
        $creator = ['id' => $skUserId, 'role' => $role, 'name' => $displayName, 'barangay_id' => $barangayId];
        $r = sked_submit_report($creator, 'monthly', (string) ($_POST['title'] ?? ''), (string) ($_POST['content'] ?? ''), (string) ($_POST['period_month'] ?? ''), null, $_FILES['attachment'] ?? null);
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Monthly report submitted to your PPSK.']
            : ['type' => 'danger', 'msg' => implode(' ', array_map('e', $r['errors']))];
    }
}

$history = $barangayId > 0 ? sked_monthly_reports_for_barangay($barangayId) : [];
$strikeCount = sked_strike_count($skUserId);
$strikes = sked_strikes_for_sk($skUserId);

$duePeriod = (new DateTime('first day of last month'))->format('Y-m');
$dueLabel = date('F Y', strtotime($duePeriod . '-01'));
$alreadySubmittedDue = $barangayId > 0 && sked_has_submitted_monthly_report($barangayId, $duePeriod);

// Last 12 months as selectable periods, newest first.
$periodOptions = [];
for ($i = 0; $i < 12; $i++) {
    $d = new DateTime("first day of -$i month");
    $val = $d->format('Y-m');
    $periodOptions[$val] = $d->format('F Y') . ($barangayId > 0 && sked_has_submitted_monthly_report($barangayId, $val) ? ' (submitted)' : '');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Monthly Reports</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../css/dashboard.css?v=1">
</head>
<body>
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="app-shell">
        <?php render_sked_navigation($role, 'reports'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Barangay <?php echo e($barangayName !== '' ? $barangayName : 'Council'); ?> &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Monthly Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Submit your barangay's monthly report to PPSK — due by the 10th.</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <?php render_sked_notification_bell('header'); ?><span class="officer-chip">
                            <span class="avatar-dot"><?php echo e(strtoupper(substr($displayName, 0, 1))); ?></span><?php echo e($displayName); ?>
                        </span>
                        <a class="btn-logout-outline text-decoration-none" href="dashboard.php"><i class="bi bi-arrow-left me-1"></i> Dashboard</a>
                    </div>
                </div>
                <svg class="ridge-divider" viewBox="0 0 1200 20" preserveAspectRatio="none" aria-hidden="true"><path d="M0 14 Q150 2 300 12 T600 10 T900 13 T1200 8" fill="none" stroke="#818cf8" stroke-width="2"/></svg>
            </section>

            <?php if ($flash['msg'] !== ''): ?><div class="alert alert-<?php echo e($flash['type']); ?>" role="alert"><?php echo $flash['msg']; ?></div><?php endif; ?>

            <?php if ($barangayId <= 0): ?>
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> Your SK account isn't linked to a barangay yet.</div>
            <?php else: ?>

            <section class="row g-3 mb-4">
                <div class="col-sm-4">
                    <div class="ledger-card <?php echo $alreadySubmittedDue ? 'accent-teal' : 'accent-amber'; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi <?php echo $alreadySubmittedDue ? 'bi-check-circle' : 'bi-hourglass-split'; ?>"></i></span>
                            <span class="ledger-tag">Due Report</span>
                        </div>
                        <div class="ledger-value" style="font-size:1.15rem;"><?php echo e($dueLabel); ?></div>
                        <div class="ledger-caption"><?php echo $alreadySubmittedDue ? 'Already submitted' : 'Due by the 10th'; ?></div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card <?php echo $strikeCount > 0 ? 'accent-rust' : ''; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-exclamation-octagon"></i></span>
                            <span class="ledger-tag">Strikes</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo $strikeCount; ?> / <?php echo SKED_DISMISSAL_STRIKE_THRESHOLD; ?></div>
                        <div class="ledger-caption">Missed-deadline strikes this term</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div class="ledger-card accent-teal">
                        <div class="d-flex justify-content-between align-items-start">
                            <span class="ledger-icon"><i class="bi bi-file-earmark-text"></i></span>
                            <span class="ledger-tag">Submitted</span>
                        </div>
                        <div class="ledger-value tabular"><?php echo count($history); ?></div>
                        <div class="ledger-caption">Monthly reports on file</div>
                    </div>
                </div>
            </section>

            <?php if ($strikeCount > 0): ?>
                <div class="alert alert-<?php echo $strikeCount >= SKED_DISMISSAL_STRIKE_THRESHOLD ? 'danger' : 'warning'; ?>" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    You have <?php echo $strikeCount; ?> compliance strike<?php echo $strikeCount === 1 ? '' : 's'; ?> for missed monthly report deadlines.
                    <?php echo $strikeCount >= SKED_DISMISSAL_STRIKE_THRESHOLD ? ' Your PPSK may report this to DILG for dismissal review.' : ' Reaching ' . SKED_DISMISSAL_STRIKE_THRESHOLD . ' strikes may lead to dismissal review.'; ?>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Submit Report</h2></div>
                        <form method="post" action="reports.php" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_reports_token']); ?>">
                            <div class="mb-3">
                                <label for="period_month" class="form-label">Reporting month</label>
                                <select class="form-select" id="period_month" name="period_month" required>
                                    <?php foreach ($periodOptions as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo $val === $duePeriod ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="title" class="form-label">Report title</label>
                                <input type="text" class="form-control" id="title" name="title" maxlength="160" placeholder="e.g. Monthly Activity Report" required>
                            </div>
                            <div class="mb-3">
                                <label for="content" class="form-label">Summary</label>
                                <textarea class="form-control" id="content" name="content" rows="5" maxlength="4000" placeholder="Activities, events held, profiling progress, concerns…"></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="attachment" class="form-label">Attachment</label>
                                <input type="file" class="form-control" id="attachment" name="attachment" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
                                <div class="form-text">PDF, Word, Excel, JPG, or PNG. Max 10 MB.</div>
                            </div>
                            <button type="submit" class="btn btn-sked w-100"><i class="bi bi-send-check me-1"></i> Submit to PPSK</button>
                        </form>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="docket-panel mb-4">
                        <div class="section-heading"><h2>Submission History</h2></div>
                        <?php if (empty($history)): ?>
                            <div class="text-center text-secondary py-4"><i class="bi bi-file-earmark fs-1 d-block mb-2"></i>No reports submitted yet.</div>
                        <?php else: ?>
                            <?php foreach ($history as $r): ?>
                                <div class="docket-row">
                                    <div>
                                        <div class="docket-title"><?php echo e(date('F Y', strtotime((string) $r['period_month'] . '-01'))); ?></div>
                                        <div class="docket-sub"><?php echo e((string) $r['title']); ?></div>
                                    </div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                                        <span class="badge <?php echo $r['status'] === 'reviewed' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo e(ucfirst((string) $r['status'])); ?></span>
                                        <div class="action-buttons">
                                            <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                            <?php if (!empty($r['attachment_file_path'])): ?>
                                                <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($strikes)): ?>
                    <div class="docket-panel">
                        <div class="section-heading"><h2>Strike History</h2></div>
                        <?php foreach ($strikes as $s): ?>
                            <div class="docket-row">
                                <div>
                                    <div class="docket-title"><?php echo e(date('F Y', strtotime((string) $s['period_month'] . '-01'))); ?></div>
                                    <div class="docket-sub"><?php echo e((string) $s['reason']); ?></div>
                                </div>
                                <span class="badge text-bg-danger">Strike</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
