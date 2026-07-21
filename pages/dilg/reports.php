<?php
/**
 * ============================================================
 * File     : pages/dilg/reports.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG consolidated report review (P7, spec 4.1). Inter-barangay
 *            event reports from PPSK and Katitikan (meeting minutes, P13)
 *            submitted directly by each barangay's SK. Dismissal
 *            recommendations are handled separately on compliance.php.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$dilgUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_dreports_token'])) {
    $_SESSION['csrf_dreports_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_dreports_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $ok = sked_mark_report_reviewed((int) ($_POST['report_id'] ?? 0), $dilgUserId);
        $flash = $ok ? ['type' => 'success', 'msg' => 'Report marked reviewed.'] : ['type' => 'danger', 'msg' => 'Could not update that report.'];
    }
}

$typeFilter = (string) ($_GET['type'] ?? '');
$statusFilter = (string) ($_GET['status'] ?? '');
$allReports = sked_reports_for_role('dilg', ['status' => $statusFilter]);
$reports = array_values(array_filter($allReports, static function ($r) use ($typeFilter) {
    if ($r['type'] === 'dismissal_recommendation') {
        return false; // handled on compliance.php
    }
    return $typeFilter === '' || $r['type'] === $typeFilter;
}));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Reports</title>
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
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Reports</h1>
                        <p class="text-secondary meta-copy mb-0">Inter-barangay event reports from PPSK and barangay Katitikan (meeting minutes) from SK.</p>
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

            <div class="docket-panel">
                <div class="section-heading">
                    <h2>Federation Reports</h2>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-sm <?php echo $typeFilter === '' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="reports.php">All types</a>
                        <a class="btn btn-sm <?php echo $typeFilter === 'interbarangay' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="reports.php?type=interbarangay">Event reports</a>
                        <a class="btn btn-sm <?php echo $typeFilter === 'minutes' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="reports.php?type=minutes">Minutes</a>
                        <a class="btn btn-sm <?php echo $statusFilter === 'submitted' ? 'btn-dark' : 'btn-outline-dark'; ?>" href="reports.php?status=submitted&type=<?php echo e($typeFilter); ?>">Pending only</a>
                    </div>
                </div>
                <?php if (empty($reports)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-inbox fs-1 d-block mb-2"></i>No reports match these filters.</div>
                <?php else: ?>
                    <?php foreach ($reports as $r): ?>
                        <div class="docket-row d-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="docket-title"><?php echo e((string) $r['title']); ?> <span class="badge text-bg-light text-capitalize"><?php echo e((string) $r['type']); ?></span></div>
                                    <div class="docket-sub"><?php echo e((string) $r['submitted_by_name']); ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $r['submitted_at']))); ?></div>
                                </div>
                                <span class="badge <?php echo $r['status'] === 'reviewed' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo e(ucfirst((string) $r['status'])); ?></span>
                            </div>
                            <?php if (!empty($r['content'])): ?><p class="small text-secondary mt-2 mb-2"><?php echo nl2br(e((string) $r['content'])); ?></p><?php endif; ?>
                            <div class="action-buttons mt-1">
                                <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                <?php if (!empty($r['attachment_file_path'])): ?>
                                    <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                <?php endif; ?>
                                <?php if (!empty($r['katitikan_id'])): ?>
                                    <a href="../manage/katitikan.php?id=<?php echo (int) $r['katitikan_id']; ?>" class="btn btn-sm btn-outline-secondary" title="View full minutes"><i class="bi bi-journal-text"></i><span>Full Minutes</span></a>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'submitted'): ?>
                                <form method="post" action="reports.php">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dreports_token']); ?>">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-circle"></i><span>Mark reviewed</span></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
