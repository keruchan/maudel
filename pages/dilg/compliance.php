<?php
/**
 * ============================================================
 * File     : pages/dilg/compliance.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : DILG dismissal review (P7, spec 6.4 step 3). Reviews PPSK
 *            dismissal recommendations for repeat-offender SKs and
 *            processes the dismissal — reverting the SK to a regular Youth
 *            account with a "Former SK Chairman" badge.
 * ============================================================
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/navigation.php';
require_once __DIR__ . '/../../includes/view.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/compliance.php';

require_role('dilg');

$role = (string) $_SESSION['role'];
$dilgUserId = (int) $_SESSION['id'];
$displayName = !empty($_SESSION['name']) ? (string) $_SESSION['name'] : 'DILG Administrator';
$todayLabel = date('l, F j, Y');

$flash = ['type' => '', 'msg' => ''];

if (empty($_SESSION['csrf_dcompliance_token'])) {
    $_SESSION['csrf_dcompliance_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf_dcompliance_token'], $token)) {
        $flash = ['type' => 'danger', 'msg' => 'Security validation failed. Please try again.'];
    } else {
        $r = sked_process_dismissal($dilgUserId, (int) ($_POST['report_id'] ?? 0));
        $flash = $r['ok']
            ? ['type' => 'success', 'msg' => 'Dismissal processed. The account is now a regular Youth account with a "' . e($r['badge']) . '" badge.']
            : ['type' => 'danger', 'msg' => e($r['error'])];
    }
}

$pending = sked_reports_for_role('dilg', ['type' => 'dismissal_recommendation', 'status' => 'submitted']);
$processed = sked_reports_for_role('dilg', ['type' => 'dismissal_recommendation', 'status' => 'reviewed']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKed | Dismissal Review</title>
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
        <?php render_sked_navigation($role, 'compliance'); ?>
        <main class="main" id="main-content">
            <section class="page-header mb-4">
                <div class="seal-watermark" aria-hidden="true"></div>
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <div class="eyebrow">Oversight Console &middot; <?php echo e($todayLabel); ?></div>
                        <h1 class="page-title">Dismissal Review</h1>
                        <p class="text-secondary meta-copy mb-0">PPSK-escalated compliance cases awaiting DILG action.</p>
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

            <div class="docket-panel mb-4">
                <div class="section-heading">
                    <h2>Pending Dismissal Recommendations</h2>
                    <span class="section-note"><?php echo count($pending); ?> pending</span>
                </div>
                <?php if (empty($pending)): ?>
                    <div class="text-center text-secondary py-4"><i class="bi bi-shield-check fs-1 d-block mb-2"></i>Nothing pending.</div>
                <?php else: ?>
                    <?php foreach ($pending as $r): ?>
                        <div class="docket-row d-block">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="docket-title"><?php echo e((string) $r['title']); ?></div>
                                    <div class="docket-sub">Reported by <?php echo e((string) $r['submitted_by_name']); ?> &middot; <?php echo e(date('M j, Y', strtotime((string) $r['submitted_at']))); ?></div>
                                </div>
                                <span class="badge text-bg-danger">Action needed</span>
                            </div>
                            <?php if (!empty($r['content'])): ?><p class="small text-secondary mt-2 mb-2"><?php echo nl2br(e((string) $r['content'])); ?></p><?php endif; ?>
                            <div class="action-buttons mt-1">
                                <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                <?php if (!empty($r['attachment_file_path'])): ?>
                                    <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                <?php endif; ?>
                                <form method="post" action="compliance.php" onsubmit="return confirm('Process this dismissal? The SK account will immediately revert to a regular Youth account. This cannot be undone.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo e((string) $_SESSION['csrf_dcompliance_token']); ?>">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $r['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-person-dash"></i><span>Process dismissal</span></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($processed)): ?>
            <div class="docket-panel">
                <div class="section-heading"><h2>Processed</h2><span class="section-note"><?php echo count($processed); ?></span></div>
                <?php foreach ($processed as $r): ?>
                    <div class="docket-row">
                        <div>
                            <div class="docket-title"><?php echo e((string) $r['title']); ?></div>
                            <div class="docket-sub">Processed <?php echo e($r['reviewed_at'] ? date('M j, Y', strtotime((string) $r['reviewed_at'])) : ''); ?></div>
                        </div>
                        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                            <span class="badge text-bg-success">Dismissed</span>
                            <div class="action-buttons">
                                <a href="../manage/report_export.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Export report"><i class="bi bi-printer"></i><span>Export</span></a>
                                <?php if (!empty($r['attachment_file_path'])): ?>
                                    <a href="../manage/report_file.php?id=<?php echo (int) $r['id']; ?>" class="btn btn-sm btn-outline-secondary" target="_blank" title="Open attachment"><i class="bi bi-paperclip"></i><span>Attachment</span></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
